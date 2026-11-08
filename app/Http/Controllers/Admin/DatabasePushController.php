<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Admin-only "push the database" endpoint.
 *
 *   GET /admin/db-push
 *       Apply this checkout's pending migrations to the database the app is
 *       configured against. On the live server that is the live database, so
 *       this is the URL to open after uploading a deployment.
 *
 *   GET /admin/db-push?database=live      (run on a development machine)
 *       Apply the same migration files to the live MySQL database configured
 *       through LIVE_DB_* in .env (see config/database.php). Requires that
 *       the live MySQL accepts remote connections from this machine.
 *
 *   GET /admin/db-push?dry=1              List what would run, change nothing.
 *   GET /admin/db-push?format=json        Machine-readable result.
 *
 * It is deliberately a GET so it can be triggered by refreshing the URL from
 * a browser, without a form, a CSRF token or terminal access. Access is
 * limited to authenticated admins; set DB_PUSH_KEY to also require a shared
 * secret in the URL (protects against another site triggering the push from
 * a logged-in admin's browser).
 *
 * The push is idempotent: Laravel records what it ran in the target
 * database's "migrations" table, so refreshing again only applies what is
 * still pending.
 */
class DatabasePushController extends Controller
{
    /**
     * Upper bound for the request, in seconds. Shared hosting often enforces
     * its own hard limit; a big migration set may need to be split up.
     */
    private const MAX_RUNTIME_SECONDS = 300;

    public function push(Request $request): Response
    {
        if (! $this->keyAllows($request)) {
            Log::warning('Database push rejected: invalid DB_PUSH_KEY', [
                'user_id' => Auth::id(),
                'ip' => $request->ip(),
            ]);

            return $this->fail($request, 'Forbidden', 'The DB_PUSH_KEY query parameter is missing or incorrect.', 403);
        }

        $originalDefault = (string) config('database.default');
        $target = trim((string) ($request->query('database') ?: $originalDefault));
        $connection = config("database.connections.{$target}");

        if (! is_array($connection) || empty($connection['driver'])) {
            return $this->fail(
                $request,
                'Unknown database connection',
                "No database connection named [{$target}] is configured.",
                400,
            );
        }

        if (! in_array($target, array_unique([$originalDefault, 'live']), true)) {
            return $this->fail(
                $request,
                'Connection not allowed',
                "This endpoint may only target the default connection [{$originalDefault}] or the [live] connection.",
                400,
                $this->facts($target, $connection),
            );
        }

        if ($target === 'live' && ($problem = $this->liveConnectionProblem($connection)) !== null) {
            return $this->fail($request, 'Live connection not usable', $problem, 400, $this->facts($target, $connection));
        }

        $facts = $this->facts($target, $connection);
        $facts['Command'] = 'php artisan migrate --database='.$target.' --force';

        try {
            [$files, $ranBefore] = $this->migrationState($target, $originalDefault);
        } catch (Throwable $e) {
            return $this->fail(
                $request,
                'Cannot reach the database',
                "The [{$target}] connection failed: {$e->getMessage()}",
                500,
                $facts,
            );
        }

        $pending = array_values(array_diff(array_map('strval', array_keys($files)), $ranBefore));
        sort($pending);

        if ($request->boolean('dry')) {
            $facts['Pending'] = count($pending).' migration(s)';

            return $this->respond(
                $request,
                [
                    'status' => 'dry',
                    'heading' => 'Database push (dry run)',
                    'message' => $pending === []
                        ? 'Dry run: nothing is pending — the database is already up to date.'
                        : 'Dry run: '.count($pending).' migration(s) would be applied. Nothing was changed.',
                    'facts' => $facts,
                    'pending' => $pending,
                    'applied' => [],
                    'pendingAfter' => $pending,
                    'output' => '',
                    'error' => null,
                    'dryRun' => true,
                    'refreshUrl' => $request->fullUrl(),
                ],
                [
                    'ok' => true,
                    'status' => 'dry_run',
                    'environment' => app()->environment(),
                    'target' => $target,
                    'connection' => $this->connectionSummary($connection),
                    'pending' => $pending,
                ],
                200,
            );
        }

        $startedAt = microtime(true);
        $exitCode = 0;
        $output = '';
        $error = null;

        if ($pending !== []) {
            // Let a migration that has started finish even if the browser
            // disconnects mid-request; a half-run migration would be worse.
            @ignore_user_abort(true);
            @set_time_limit(self::MAX_RUNTIME_SECONDS);

            try {
                $exitCode = Artisan::call('migrate', [
                    '--database' => $target,
                    '--force' => true,
                    '--no-interaction' => true,
                ]);
                $output = trim((string) Artisan::output());
            } catch (Throwable $e) {
                $exitCode = 1;
                $error = $e->getMessage();
                $output = trim((string) Artisan::output());
            } finally {
                // The migrate command's usingConnection() restores the
                // previous default connection on success, but not when a
                // migration throws. Put it back either way: this request
                // still has to save its session to the application database.
                app('migrator')->setConnection($originalDefault);
            }
        }

        $elapsed = round(microtime(true) - $startedAt, 2);

        try {
            [, $ranAfter] = $this->migrationState($target, $originalDefault);
        } catch (Throwable $e) {
            $ranAfter = $ranBefore;
            $error ??= $e->getMessage();
            if ($exitCode === 0) {
                $exitCode = 1;
            }
        }

        $applied = array_values(array_diff($ranAfter, $ranBefore));
        $pendingAfter = array_values(array_diff(array_map('strval', array_keys($files)), $ranAfter));
        sort($applied);
        sort($pendingAfter);

        $succeeded = $exitCode === 0;

        $message = match (true) {
            ! $succeeded => 'The migrate command failed (exit code '.$exitCode.'). Fix the error below, then refresh to retry.',
            $applied === [] => 'Nothing to migrate — '.$this->label($target, $originalDefault).' is already up to date.',
            default => 'Applied '.count($applied).' migration(s) to '.$this->label($target, $originalDefault).' in '.$elapsed.'s.',
        };

        $facts['Exit code'] = (string) $exitCode;
        $facts['Duration'] = $elapsed.'s';
        $facts['Pending'] = count($pending).' before, '.count($pendingAfter).' after';

        $logContext = [
            'user_id' => Auth::id(),
            'user_email' => Auth::user()?->getAttribute('email'),
            'ip' => $request->ip(),
            'target' => $target,
            'driver' => $connection['driver'] ?? null,
            'database' => $connection['database'] ?? null,
            'pending_before' => count($pending),
            'applied' => count($applied),
            'pending_after' => count($pendingAfter),
            'exit_code' => $exitCode,
            'duration_seconds' => $elapsed,
            'error' => $error,
        ];

        if ($succeeded) {
            Log::info('Database push finished', $logContext);
        } else {
            Log::error('Database push failed', $logContext);
        }

        return $this->respond(
            $request,
            [
                'status' => $succeeded ? 'success' : 'failed',
                'heading' => 'Database push',
                'message' => $message,
                'facts' => $facts,
                'pending' => $pending,
                'applied' => $applied,
                'pendingAfter' => $pendingAfter,
                'output' => $output,
                'error' => $error,
                'dryRun' => false,
                'refreshUrl' => $request->fullUrl(),
            ],
            [
                'ok' => $succeeded,
                'status' => $succeeded ? 'success' : 'failed',
                'environment' => app()->environment(),
                'target' => $target,
                'connection' => $this->connectionSummary($connection),
                'pending_before' => $pending,
                'applied' => $applied,
                'pending_after' => $pendingAfter,
                'exit_code' => $exitCode,
                'duration_seconds' => $elapsed,
                'output' => $output,
                'error' => $error,
            ],
            $succeeded ? 200 : 500,
        );
    }

    /**
     * Pending state of the target connection, read through a migrator whose
     * connection is restored afterwards: setConnection() also rewrites the
     * application's default connection name, and this request still has to
     * save its session to the normal database afterwards.
     *
     * @param  string  $connection  Connection to inspect.
     * @param  string  $restoreTo  Default connection to restore afterwards.
     * @return array{0: array<string, string>, 1: array<int, string>}
     */
    private function migrationState(string $connection, string $restoreTo): array
    {
        $migrator = app('migrator');

        try {
            $migrator->setConnection($connection);

            $files = $migrator->getMigrationFiles([database_path('migrations')]);
            $repository = $migrator->getRepository();
            $ran = $repository->repositoryExists() ? $repository->getRan() : [];

            return [$files, array_values($ran)];
        } finally {
            $migrator->setConnection($restoreTo);
        }
    }

    /**
     * Reasons the [live] connection cannot be used from this machine, or null
     * when it looks usable.
     */
    private function liveConnectionProblem(array $connection): ?string
    {
        if (blank($connection['database'] ?? null)) {
            return 'The [live] connection is not configured. Set LIVE_DB_HOST, LIVE_DB_DATABASE, LIVE_DB_USERNAME and '
                .'LIVE_DB_PASSWORD in .env, then clear the config cache. On the live server itself, open the URL without '
                .'?database=live.';
        }

        $host = (string) ($connection['host'] ?? '');

        if (app()->environment('local', 'development', 'testing') && $this->isLoopback($host)) {
            return 'LIVE_DB_HOST is "'.$host.'", which resolves to this development machine, not the live server. Use the '
                .'server\'s remote MySQL hostname (and whitelist this machine in cPanel → Remote MySQL), or open the URL '
                .'on the live server without ?database=live.';
        }

        return null;
    }

    private function keyAllows(Request $request): bool
    {
        $expected = (string) config('db_push.key', '');

        return $expected === '' || hash_equals($expected, (string) $request->query('key', ''));
    }

    private function isLoopback(string $host): bool
    {
        $host = strtolower(trim($host));

        return $host === 'localhost'
            || $host === '::1'
            || $host === '[::1]'
            || str_starts_with($host, '127.');
    }

    /**
     * @return array<string, string>
     */
    private function facts(string $target, array $connection): array
    {
        $driver = (string) ($connection['driver'] ?? '');
        $isFile = $driver === 'sqlite';

        return [
            'Environment' => (string) app()->environment(),
            'Connection' => $this->label($target, (string) config('database.default')),
            'Driver' => $driver,
            'Host' => $isFile ? 'local file' : (string) ($connection['host'] ?? ''),
            'Database' => (string) ($connection['database'] ?? ''),
            'Username' => $isFile ? '—' : (string) ($connection['username'] ?? ''),
        ];
    }

    /**
     * Connection details safe to show/serialize — never the password.
     *
     * @return array<string, mixed>
     */
    private function connectionSummary(array $connection): array
    {
        return [
            'driver' => $connection['driver'] ?? null,
            'host' => $connection['host'] ?? null,
            'port' => $connection['port'] ?? null,
            'database' => $connection['database'] ?? null,
            'username' => $connection['username'] ?? null,
        ];
    }

    private function label(string $target, string $default): string
    {
        return '['.$target.']'.($target === $default ? ' (default)' : '');
    }

    private function fail(Request $request, string $heading, string $message, int $statusCode, array $facts = []): Response
    {
        return $this->respond(
            $request,
            [
                'status' => 'error',
                'heading' => $heading,
                'message' => $message,
                'facts' => $facts,
                'pending' => [],
                'applied' => [],
                'pendingAfter' => [],
                'output' => '',
                'error' => null,
                'dryRun' => false,
                'refreshUrl' => $request->fullUrl(),
            ],
            [
                'ok' => false,
                'status' => 'error',
                'error' => $message,
            ],
            $statusCode,
        );
    }

    /**
     * @param  array<string, mixed>  $view
     * @param  array<string, mixed>  $json
     */
    private function respond(Request $request, array $view, array $json, int $statusCode): Response
    {
        if ($request->wantsJson() || $request->query('format') === 'json') {
            return response()->json($json, $statusCode)
                ->header('Cache-Control', 'no-store, max-age=0');
        }

        return response()->view('admin.db-push', $view, $statusCode)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }
}
