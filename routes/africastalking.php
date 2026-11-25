<?php

declare(strict_types=1);

use App\Http\Controllers\AfricaSTalking\AfricaSTalkingSessionController;
use App\Http\Controllers\Admin\TelephonySettingsController;
use App\Http\Controllers\Api\SoftphoneCallStatusController;
use App\Http\Controllers\CallController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Africa's Talking — Voice callbacks and call-control XML
|--------------------------------------------------------------------------
|
| AT's per-number callback URL is fixed once in their dashboard (there is no
| API to set it), so both inbound calls and the answer step of an outbound
| call arrive at /at-xml/session. That controller matches the request to the
| Call row (clientRequestId / sessionId / from+to) and redirects AT to the
| per-call XML endpoint the shared TwimlController already serves.
|
| AT does not sign callbacks: a shared secret and/or IP allow-list is the
| supported verification (config africastalking.webhook). The provider is not
| selectable while config africastalking.enabled is false.
*/

// The browser softphone reports the outcome of an AT WebRTC call (AT does not
// reliably send per-call status callbacks for the client session). Session
// authenticated + CSRF protected like the rest of the web app.
Route::middleware('auth')
    ->post('/api/africastalking/call-completed', [SoftphoneCallStatusController::class, 'completed'])
    ->name('africastalking.call-completed');

// Admin-only, worker-free campaign dial used to diagnose the campaign path
// from the web process (current code, no queue worker): reports the locked
// provider, whether AT credentials resolve here, and the resulting Call row or
// exception. Open in the browser while signed in as admin.
Route::middleware(['auth', 'role:admin'])->get('/admin/campaigns/dial-now', function () {
    $request = request();
    $campaignId = (string) $request->query('campaign', '');
    $rawNumber = (string) $request->query('number', '');

    $campaign = \App\Models\Campaign::query()
        ->where('id', is_numeric($campaignId) ? (int) $campaignId : -1)
        ->orWhere('name', $campaignId)
        ->first();

    if ($campaign === null) {
        return response()->json(['error' => 'campaign not found', 'campaign' => $campaignId], 404);
    }

    $phone = app(\App\Services\PhoneValidationService::class)
        ->sanitize($rawNumber, (string) config('telephony.default_country_code', '+254'));

    if (! $phone['success']) {
        return response()->json(['error' => 'invalid number', 'detail' => $phone['error']], 422);
    }

    $number = (string) $phone['formatted'];

    $atRow = \App\Models\TelephonyProviderConfig::query()->where('name', 'africastalking')->latest('id')->first();

    $contact = \App\Models\CampaignContact::query()->firstOrCreate(
        ['campaign_id' => $campaign->id, 'phone_number' => $number],
        ['status' => 'pending', 'call_attempts' => 0]
    );
    $contact->update(['status' => 'pending', 'call_attempts' => 0]);

    $originalStatus = $campaign->status;
    if ($campaign->status !== 'running') {
        $campaign->update(['status' => 'running']);
    }

    $error = null;
    try {
        \App\Jobs\MakeCampaignCallJob::dispatchSync($campaign, $contact);
    } catch (\Throwable $e) {
        $error = get_class($e).': '.$e->getMessage();
    } finally {
        if ($campaign->status === 'running' && $originalStatus !== 'running' && $originalStatus !== 'draft') {
            $campaign->update(['status' => $originalStatus]);
        }
    }

    $contact->refresh();
    $call = \App\Models\Call::query()
        ->where('campaign_contact_id', $contact->id)
        ->latest('id')
        ->first();

    return response()->json([
        'campaign' => ['id' => $campaign->id, 'name' => $campaign->name, 'from_number' => $campaign->from_number],
        'number' => $number,
        'provider' => app(\App\Services\Telephony\TelephonyManager::class)->provider()->value,
        'at_config' => [
            'row_id' => $atRow?->id,
            'has_username' => (bool) ($atRow?->credential('username') ?? $atRow?->credential('account_id')),
            'has_api_key' => (bool) ($atRow?->credential('api_key') ?? $atRow?->credential('auth_token')),
        ],
        'job_exception' => $error,
        'contact' => ['id' => $contact->id, 'status' => $contact->status],
        'call' => $call === null ? null : [
            'id' => $call->id,
            'status' => $call->status,
            'provider' => $call->provider,
            'to' => $call->to_number,
            'from' => $call->from_number,
            'provider_call_sid' => $call->provider_call_sid,
            'error' => $call->error_message,
        ],
    ]);
})->name('africastalking.admin-campaign-dial-now');


// Admin-only web runner for the campaign dry run, for hosts without a
// terminal. Validates the whole campaign outbound path with no billable call.
Route::match(['get', 'post'], '/admin/telephony/campaign-dry-run', function (\Illuminate\Http\Request $request) {
    if ($request->isMethod('post')) {
        $request->validate([
            'campaign' => ['required', 'string', 'max:190'],
            'number' => ['required', 'string', 'max:40'],
        ]);

        $arguments = [
            'campaign' => (string) $request->input('campaign'),
            'number' => (string) $request->input('number'),
        ];

        if ((string) $request->input('from') !== '') {
            $arguments['--from'] = (string) $request->input('from');
        }

        $output = new \Symfony\Component\Console\Output\BufferedOutput();

        try {
            \Illuminate\Support\Facades\Artisan::call('campaigns:dry-run', $arguments, $output);
            $text = $output->fetch();
        } catch (\Throwable $e) {
            $text = 'ERROR: '.$e->getMessage();
        }

        if (trim($text) === '') {
            $text = 'No output. Check storage/logs/laravel.log for details.';
        }

        return response($text, 200)->header('Content-Type', 'text/plain; charset=utf-8');
    }

    $token = csrf_token();

    return response(<<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Campaign dry run</title>
<style>
body{font-family:system-ui,sans-serif;max-width:680px;margin:40px auto;padding:0 16px;background:#111;color:#eee}
input{width:100%;padding:10px;margin:6px 0 14px;border:1px solid #444;border-radius:6px;background:#1c1c1c;color:#eee}
button{padding:10px 18px;border:0;border-radius:6px;background:#22c55e;color:#04220f;font-weight:600;cursor:pointer}
small{color:#aaa}
</style>
</head>
<body>
<h2>Campaign dry run (no billable call)</h2>
<p><small>Runs on this server with the stored production AT credentials. It checks the campaign, destination normalisation, provider, credentials, answer URL, AT Voice API reachability and queue state. No call is placed.</small></p>
<form method="post">
<input type="hidden" name="_token" value="{$token}">
<label>Campaign (id or name)</label>
<input name="campaign" value="Service Outage Notification" required>
<label>Destination number</label>
<input name="number" value="+254756112633" required>
<label>From number (optional, defaults to the campaign from-number)</label>
<input name="from" placeholder="+254711082025">
<button type="submit">Run dry run</button>
</form>
</body>
</html>
HTML)->header('Content-Type', 'text/html; charset=utf-8');
})->name('africastalking.admin-campaign-dry-run');

// Admin-only queue maintenance web runner (no terminal on shared hosts):
// clear stale campaign-calls jobs, restart workers, cleanup stuck calls, or
// reset a campaign's contacts so it can be started cleanly again.
Route::match(['get', 'post'], '/admin/telephony/queue-maintenance', function (\Illuminate\Http\Request $request) {
    $output = [];

    if ($request->isMethod('post')) {
        $action = (string) $request->input('action');

        $run = function (string $command, array $arguments = []) use (&$output): void {
            $buffer = new \Symfony\Component\Console\Output\BufferedOutput();
            try {
                \Illuminate\Support\Facades\Artisan::call($command, $arguments, $buffer);
                $output[] = '$ artisan '.$command.' '.implode(' ', array_map(
                    fn ($key, $value) => is_string($key) ? $key.'='.$value : $value,
                    array_keys($arguments),
                    $arguments
                ));
                $output[] = trim($buffer->fetch()) ?: '(no output)';
            } catch (\Throwable $e) {
                $output[] = 'ERROR running '.$command.': '.$e->getMessage();
            }
            $output[] = '';
        };

        if ($action === 'clear') {
            $run('queue:clear', ['connection' => 'database', '--queue' => 'campaign-calls']);
            $run('queue:restart');
        } elseif ($action === 'cleanup') {
            $run('telephony:cleanup-stuck', ['--minutes' => '0']);
        } elseif ($action === 'reset') {
            $campaignId = (int) $request->input('campaign_id', 0);
            $campaign = \App\Models\Campaign::query()->find($campaignId);
            if ($campaign === null) {
                $output[] = 'ERROR: campaign not found.';
            } else {
                $reset = \App\Models\CampaignContact::query()
                    ->where('campaign_id', $campaign->id)
                    ->whereIn('status', ['in_progress', 'failed', 'queued'])
                    ->update(['status' => 'pending', 'call_attempts' => 0]);
                $campaign->update(['status' => 'paused']);
                $output[] = "Reset {$reset} contact(s) to pending and paused campaign #{$campaign->id}.";
            }
        }

        return response(implode("\n", $output), 200)->header('Content-Type', 'text/plain; charset=utf-8');
    }

    $token = csrf_token();

    return response(<<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Queue maintenance</title>
<style>
body{font-family:system-ui,sans-serif;max-width:680px;margin:40px auto;padding:0 16px;background:#111;color:#eee}
form{margin:18px 0;padding:14px;border:1px solid #333;border-radius:8px}
input{padding:8px;margin:4px 0 10px;border:1px solid #444;border-radius:6px;background:#1c1c1c;color:#eee}
button{padding:9px 16px;border:0;border-radius:6px;background:#22c55e;color:#04220f;font-weight:600;cursor:pointer}
small{color:#aaa}
</style>
</head>
<body>
<h2>Queue + stuck call maintenance</h2>
<p><small>Runs artisan commands on the server. No calls are placed by these actions.</small></p>
<form method="post">
<input type="hidden" name="_token" value="{$token}">
<input type="hidden" name="action" value="clear">
<button type="submit">Clear stale campaign queue + restart workers</button>
</form>
<form method="post">
<input type="hidden" name="_token" value="{$token}">
<input type="hidden" name="action" value="cleanup">
<button type="submit">Cleanup stuck calls and phantom queue entries</button>
</form>
<form method="post">
<input type="hidden" name="_token" value="{$token}">
<input type="hidden" name="action" value="reset">
<label>Campaign id</label><br>
<input name="campaign_id" value="3">
<button type="submit">Reset that campaign's contacts to pending and pause it</button>
</form>
</body>
</html>
HTML)->header('Content-Type', 'text/html; charset=utf-8');
})->name('africastalking.admin-queue-maintenance');

// Admin-only one-click campaign live test: runs campaigns:run-sync for a single
// contact (a real, billable call). Requires the confirmation word.
Route::match(['get', 'post'], '/admin/telephony/campaign-live-test', function (\Illuminate\Http\Request $request) {
    if ($request->isMethod('post')) {
        $request->validate([
            'campaign' => ['required', 'string', 'max:190'],
            'number' => ['required', 'string', 'max:40'],
            'confirm' => ['required', 'string'],
        ]);

        if (strtoupper(trim((string) $request->input('confirm'))) !== 'CALL') {
            return response('Type CALL in the confirmation box to place a real call.', 422)
                ->header('Content-Type', 'text/plain; charset=utf-8');
        }

        $arguments = [
            'campaign' => (string) $request->input('campaign'),
            '--retry-failed' => true,
            '--limit' => '1',
        ];

        if ((string) $request->input('from') !== '') {
            $arguments['--from'] = (string) $request->input('from');
        }

        $buffer = new \Symfony\Component\Console\Output\BufferedOutput();

        try {
            \Illuminate\Support\Facades\Artisan::call('campaigns:run-sync', $arguments, $buffer);
            $text = $buffer->fetch();
        } catch (\Throwable $e) {
            $text = 'ERROR: '.$e->getMessage();
        }

        return response(trim($text) ?: 'No output.', 200)->header('Content-Type', 'text/plain; charset=utf-8');
    }

    $token = csrf_token();

    return response(<<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Campaign live test</title>
<style>
body{font-family:system-ui,sans-serif;max-width:680px;margin:40px auto;padding:0 16px;background:#111;color:#eee}
input{width:100%;padding:10px;margin:6px 0 14px;border:1px solid #444;border-radius:6px;background:#1c1c1c;color:#eee}
button{padding:10px 18px;border:0;border-radius:6px;background:#ef4444;color:#fff;font-weight:600;cursor:pointer}
small{color:#aaa}
</style>
</head>
<body>
<h2>Campaign live test (one real, billable call)</h2>
<p><small>Runs campaigns:run-sync with limit 1. Only do this after the dry run passed and the AT from-number is a production number.</small></p>
<form method="post">
<input type="hidden" name="_token" value="{$token}">
<label>Campaign (id or name)</label>
<input name="campaign" value="Service Outage Notification" required>
<label>Destination number</label>
<input name="number" value="+254756112633" required>
<label>From number (optional, defaults to campaign from-number)</label>
<input name="from" placeholder="+254711082025">
<label>Type CALL to confirm</label>
<input name="confirm" placeholder="CALL" required>
<button type="submit">Place one real call</button>
</form>
</body>
</html>
HTML)->header('Content-Type', 'text/html; charset=utf-8');
})->name('africastalking.admin-campaign-live-test');

// Admin-only web runner for the SIP registration probe, for hosts without a
// terminal (shared cPanel). The probe runs server-side, so its REGISTER comes
// from the server's egress IP - which is what AT needs when checking an IP
// allow-list. Output is plain text with the server's public IP on the first line.
Route::match(['get', 'post'], '/admin/telephony/sip-probe', function (\Illuminate\Http\Request $request) {
    if ($request->isMethod('post')) {
        $request->validate([
            'username' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string', 'max:190'],
        ]);

        $arguments = [
            '--username' => (string) $request->input('username'),
            '--password' => (string) $request->input('password'),
            '--domain' => (string) $request->input('domain', 'ke.sip.africastalking.com'),
            '--port' => (string) $request->input('port', '5060'),
            '--transport' => (string) $request->input('transport', 'auto'),
        ];

        $output = new \Symfony\Component\Console\Output\BufferedOutput();

        try {
            \Illuminate\Support\Facades\Artisan::call('telephony:sip-probe', $arguments, $output);
            $text = $output->fetch();
        } catch (\Throwable $e) {
            $text = 'ERROR: '.$e->getMessage();
        }

        if (trim($text) === '') {
            $text = 'No output. Check storage/logs/laravel.log for details.';
        }

        return response($text, 200)->header('Content-Type', 'text/plain; charset=utf-8');
    }

    $token = csrf_token();

    return response(<<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>SIP probe</title>
<style>
body{font-family:system-ui,sans-serif;max-width:640px;margin:40px auto;padding:0 16px;background:#111;color:#eee}
input{width:100%;padding:10px;margin:6px 0 14px;border:1px solid #444;border-radius:6px;background:#1c1c1c;color:#eee}
button{padding:10px 18px;border:0;border-radius:6px;background:#22c55e;color:#04220f;font-weight:600;cursor:pointer}
small{color:#aaa}
</style>
</head>
<body>
<h2>AT SIP registration probe (runs on this server)</h2>
<p><small>The REGISTER below comes from this server's public IP. Use the renewed SIP password. Do not run it repeatedly: failed auth can block the IP.</small></p>
<form method="post">
<input type="hidden" name="_token" value="{$token}">
<label>Username</label>
<input name="username" value="agent1.AgentQ_AI" required>
<label>Password</label>
<input name="password" type="password" required>
<label>Domain</label>
<input name="domain" value="ke.sip.africastalking.com">
<label>Port</label>
<input name="port" value="5060">
<label>Transport</label>
<select name="transport" style="width:100%;padding:10px;margin:6px 0 14px;border:1px solid #444;border-radius:6px;background:#1c1c1c;color:#eee">
<option value="auto" selected>auto (try TCP, then UDP, then TLS)</option>
<option value="tcp">TCP 5060</option>
<option value="udp">UDP 5060</option>
<option value="tls">TLS 5061</option>
</select>
<button type="submit">Run probe</button>
</form>
</body>
</html>
HTML)->header('Content-Type', 'text/html; charset=utf-8');
})->name('africastalking.admin-sip-probe');

// (git ref + file mtimes + presence of recent methods), because stale OPcache
// on shared hosting can keep executing old classes after `git pull`.
Route::middleware(['auth'])->get('/admin/telephony/deploy-check', function () {
    $head = null;
    foreach (['.git/refs/heads/main', '.git/HEAD'] as $ref) {
        $path = base_path($ref);
        if (is_file($path)) {
            $head = trim((string) file_get_contents($path));
            if (str_starts_with($head, 'ref: ')) {
                $resolved = base_path(substr($head, 5));
                $head = is_file($resolved) ? trim((string) file_get_contents($resolved)) : $head;
            }
            break;
        }
    }

    $files = [
        'session_controller' => app_path('Http/Controllers/AfricaSTalking/AfricaSTalkingSessionController.php'),
        'voice_provider' => app_path('Services/Telephony/AfricaSTalking/AfricaSTalkingVoiceProvider.php'),
        'call_xml_generator' => app_path('Services/Telephony/AfricaSTalking/AfricaSTalkingCallXmlGenerator.php'),
        'config' => config_path('africastalking.php'),
    ];

    $mtimes = [];
    foreach ($files as $key => $path) {
        $mtimes[$key] = is_file($path) ? date('c', filemtime($path)) : 'missing';
    }

    return response()->json([
        'git_head' => $head,
        'file_mtimes' => $mtimes,
        'has_voice_sanitizer' => method_exists(
            \App\Services\Telephony\AfricaSTalking\AfricaSTalkingCallXmlGenerator::class,
            'isAfricaSTalkingVoice'
        ),
        'has_terminal_session_handler' => method_exists(
            \App\Http\Controllers\AfricaSTalking\AfricaSTalkingSessionController::class,
            'applyTerminalSessionState'
        ),
        'voice_api_url' => config('africastalking.api.voice_base_url'),
        'voice_call_path' => config('africastalking.api.voice_call_path'),
        'opcache' => function_exists('opcache_get_status') ? [
            'enabled' => (bool) (opcache_get_status(false)['opcache_enabled'] ?? false),
            'validate_timestamps' => (bool) ini_get('opcache.validate_timestamps'),
            'revalidate_freq' => ini_get('opcache.revalidate_freq'),
        ] : ['enabled' => false],
        'queue' => [
            'connection' => config('queue.default'),
            'cache_store' => config('cache.default'),
            // queue:restart writes this cache timestamp; workers older than it
            // are expected to exit and be respawned.
            'restart_signal' => \Illuminate\Support\Facades\Cache::get('illuminate:queue:restart'),
            'pending_campaign_jobs' => \Illuminate\Support\Facades\DB::table('jobs')->where('queue', 'campaign-calls')->count(),
            'oldest_pending_job' => \Illuminate\Support\Facades\DB::table('jobs')->where('queue', 'campaign-calls')->min('created_at'),
        ],
        'inbound' => [
            'mode' => config('africastalking.voice.inbound.mode'),
            'forward_to' => config('africastalking.voice.inbound.forward_to'),
            'voicemail_max_seconds' => config('africastalking.voice.inbound.voicemail_max_seconds'),
            'client_prefix' => config('africastalking.voice.webrtc_client_prefix'),
            'ivr' => config('africastalking.voice.inbound.ivr'),            'latest_inbound_calls' => \App\Models\Call::query()
                ->where('direction', 'inbound')
                ->latest('id')
                ->take(5)
                ->get(['id', 'created_at', 'from_number', 'to_number', 'status', 'provider_call_sid', 'error_message', 'recording_url', 'transcript_status'])
                ->map(fn ($call) => [
                    'id' => $call->id,
                    'created_at' => optional($call->created_at)->toDateTimeString(),
                    'from' => $call->from_number,
                    'to' => $call->to_number,
                    'status' => $call->status,
                    'provider_call_sid' => $call->provider_call_sid,
                    'error' => $call->error_message,
                    'recording' => $call->recording_url !== null,
                    'transcript_status' => $call->transcript_status,
                ])
                ->all(),
        ],
        'africastalking_config_rows' => \App\Models\TelephonyProviderConfig::query()
            ->whereIn('name', ['africastalking', 'africa_stalking', "africa's talking"])
            ->get()
            ->map(fn ($config) => [
                'id' => $config->id,
                'name' => $config->name,
                'enabled' => $config->enabled,
                'updated_at' => optional($config->updated_at)->toDateTimeString(),
                'credential_keys' => array_keys((array) ($config->credentials ?? [])),
                'has_username' => (bool) ($config->credential('username') ?? $config->credential('account_id')),
                'has_api_key' => (bool) ($config->credential('api_key') ?? $config->credential('auth_token')),
            ])
            ->all(),
        'latest_failed_calls' => \App\Models\Call::query()
            ->where('status', 'failed')
            ->latest('id')
            ->take(5)
            ->get(['id', 'created_at', 'campaign_id', 'from_number', 'to_number', 'provider_call_sid', 'error_message'])
            ->map(fn ($call) => [
                'id' => $call->id,
                'created_at' => optional($call->created_at)->toDateTimeString(),
                'campaign_id' => $call->campaign_id,
                'from' => $call->from_number,
                'to' => $call->to_number,
                'provider_call_sid' => $call->provider_call_sid,
                'error' => $call->error_message,
            ])
            ->all(),
        'public_ip' => rescue(fn () => trim((string) \Illuminate\Support\Facades\Http::timeout(6)->get('https://api.ipify.org')->body()), null, false),
        'softphone' => (function () {
            $user = \App\Models\User::query()->whereHas('roles', fn ($q) => $q->where('name', 'admin'))->first()
                ?? \App\Models\User::query()->first();

            if ($user === null) {
                return ['error' => 'no users'];
            }

            $atRow = \App\Models\TelephonyProviderConfig::query()->where('name', 'africastalking')->latest('id')->first();
            $number = \App\Models\PhoneNumber::query()
                ->where('user_id', $user->id)
                ->where('provider', 'africastalking')
                ->where('status', 'assigned')
                ->orderByDesc('id')
                ->value('number')
                ?? \App\Models\PhoneNumber::query()->where('provider', 'africastalking')->value('number');

            $result = [
                'locked_provider' => app(\App\Services\Telephony\TelephonyManager::class)->provider()->value,
                'is_configured' => app(\App\Actions\Telephony\IssueSoftphoneTokenAction::class)->isConfigured(),
                'user' => $user->email,
                'phone_number' => $number,
            ];

            try {
                $issued = app(\App\Actions\Telephony\IssueSoftphoneTokenAction::class)->execute($user);
                $result['token_issue'] = [
                    'ok' => true,
                    'identity' => $issued['identity'] ?? null,
                    'provider' => $issued['provider'] ?? null,
                    'expires_in' => $issued['expires_in'] ?? null,
                    'token_length' => strlen((string) ($issued['token'] ?? '')),
                ];
            } catch (\Throwable $e) {
                $result['token_issue'] = [
                    'ok' => false,
                    'exception' => class_basename($e),
                    'message' => $e->getMessage(),
                ];
            }

            // Direct AT capability-token probe (status + error text only, never the token).
            try {
                $username = $atRow?->credential('username');
                $apiKey = $atRow?->credential('api_key');
                if ($username && $apiKey && $number) {
                    $probe = \Illuminate\Support\Facades\Http::withHeaders(['apiKey' => $apiKey, 'Accept' => 'application/json'])
                        ->timeout(20)
                        ->asJson()
                        ->post((string) config('africastalking.api.webrtc_token_url'), [
                            'username' => $username,
                            'clientName' => 'user_'.$user->id,
                            'phoneNumber' => $number,
                            'incoming' => true,
                            'outgoing' => true,
                            'expire' => 300,
                        ]);
                    $result['at_token_probe'] = [
                        'status' => $probe->status(),
                        'error' => mb_substr((string) ($probe->json('errorMessage') ?? $probe->json('message') ?? $probe->body()), 0, 200),
                        'has_token' => (bool) $probe->json('token'),
                    ];
                } else {
                    $result['at_token_probe'] = ['error' => 'missing AT credentials or phone number'];
                }
            } catch (\Throwable $e) {
                $result['at_token_probe'] = ['exception' => $e->getMessage()];
            }

            // WSS handshake to AT's WebRTC gateway from the server.
            try {
                $context = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
                $socket = @stream_socket_client('tls://webrtc.africastalking.com:443', $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $context);
                if ($socket === false) {
                    $result['wss_handshake'] = 'connect failed: '.$errstr;
                } else {
                    fwrite($socket, "GET /connect HTTP/1.1\r\nHost: webrtc.africastalking.com\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n");
                    $result['wss_handshake'] = trim((string) fgets($socket));
                    fclose($socket);
                }
            } catch (\Throwable $e) {
                $result['wss_handshake'] = 'error: '.$e->getMessage();
            }

            return $result;
        })(),
        'at_verify' => (function () {
            $config = \App\Models\TelephonyProviderConfig::query()->where('name', 'africastalking')->latest('id')->first();
            $username = trim((string) ($config?->credential('username') ?? $config?->credential('account_id') ?? ''));
            $apiKey = trim((string) ($config?->credential('api_key') ?? $config?->credential('auth_token') ?? ''));

            if ($username === '' || $apiKey === '') {
                return ['error' => 'stored AT username or api key missing'];
            }

            $fingerprint = strlen($apiKey) > 16
                ? substr($apiKey, 0, 10).'…'.substr($apiKey, -4)
                : 'short('.strlen($apiKey).')';

            $result = [
                'stored_username' => $username,
                'stored_api_key_fingerprint' => $fingerprint,
                'stored_api_key_length' => strlen($apiKey),
                'api_base_url' => config('africastalking.api.base_url'),
                'api_version_segment' => config('africastalking.api.version_segment'),
                'enabled' => config('africastalking.enabled'),
            ];

            try {
                $verify = app(\App\Services\Telephony\AfricaSTalking\AfricaSTalkingVoiceProvider::class)
                    ->verifyCredentials(['username' => $username, 'api_key' => $apiKey]);
                $result['verify'] = [
                    'valid' => (bool) ($verify['valid'] ?? false),
                    'account' => $verify['account_name'] ?? null,
                    'error' => $verify['error'] ?? null,
                ];
            } catch (\Throwable $e) {
                $result['verify'] = ['exception' => $e->getMessage()];
            }

            return $result;
        })(),
        'server_time' => now()->toDateTimeString(),
    ]);
})->name('africastalking.deploy-check');


Route::prefix('at-xml')->name('at-xml.')->middleware(['throttle:120,1'])->group(function (): void {
    // The single URL an AT number's "Callback" setting must point at.
    Route::match(['get', 'post'], '/session', [AfricaSTalkingSessionController::class, 'session'])
        ->name('session')->defaults('provider', 'africastalking');

    // Per-call call-control documents (AT redirects here from /session).
    Route::match(['get', 'post'], '/campaign/tts', [\App\Http\Controllers\TwimlController::class, 'campaignCallTts'])
        ->name('campaign.tts')->defaults('provider', 'africastalking');
    Route::match(['get', 'post'], '/campaign/voice', [\App\Http\Controllers\TwimlController::class, 'campaignCallVoice'])
        ->name('campaign.voice')->defaults('provider', 'africastalking');
    Route::match(['get', 'post'], '/inbound-call', [\App\Http\Controllers\TwimlController::class, 'inboundCall'])
        ->name('inbound.call')->defaults('provider', 'africastalking');
    Route::match(['get', 'post'], '/manual-call', [\App\Http\Controllers\TwimlController::class, 'manualCall'])
        ->name('manual.call')->defaults('provider', 'africastalking');
    Route::match(['get', 'post'], '/dial-complete/{call}', function (\App\Models\Call $call) {
        $twiml = '<?xml version="1.0" encoding="UTF-8"?><Response/>';

        return response($twiml, 200)->header('Content-Type', 'text/xml');
    })->name('dial-complete');

    // AT <Record callbackUrl> target for the inbound voicemail fallback:
    // store the recording + Deepgram transcript on the Call row.
    Route::match(['get', 'post'], '/voicemail/{call}', [\App\Http\Controllers\TwimlController::class, 'atVoicemailCallback'])
        ->name('voicemail');

    // AT GetDigits callBackUrl target for the inbound IVR (1 = forward,
    // 2 = voicemail, timeout/invalid fallbacks).
    Route::match(['get', 'post'], '/ivr/{call}', [\App\Http\Controllers\TwimlController::class, 'atIvrCallback'])
        ->name('ivr');
});

// AT's per-number "Events" URL (and the callBackUrl of GetDigits/Record XML).
Route::prefix('webhooks/africastalking')
    ->name('webhooks.africastalking.')
    ->middleware(['throttle:120,1'])
    ->group(function (): void {
        // Single events endpoint for the number-level Events URL; dispatches
        // status / recording / DTMF by payload shape.
        Route::post('/events', [AfricaSTalkingSessionController::class, 'events'])->name('events');

        // Per-call callbacks the generated XML points AT at (GetDigits/Record
        // callBackUrl), mirroring the Twilio/Plivo route names.
        Route::post('/calls/{call_id}/status', [CallController::class, 'webhookStatus'])
            ->name('call.status')->defaults('provider', 'africastalking');
        Route::post('/calls/{call_id}/recording', [CallController::class, 'webhookRecording'])
            ->name('call.recording')->defaults('provider', 'africastalking');
        Route::post('/calls/{call_id}/dtmf', [CallController::class, 'webhookDtmf'])
            ->name('call.dtmf')->defaults('provider', 'africastalking');

        // Generic status callback (no per-call segment in the URL).
        Route::post('/call-status', [CallController::class, 'webhookStatus'])
            ->name('call-status')->defaults('provider', 'africastalking');
    });
