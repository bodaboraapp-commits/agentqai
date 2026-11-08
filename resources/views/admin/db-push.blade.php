<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Database push · {{ config('app.name') }}</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 32px 16px;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #f4f5f7;
            color: #111827;
            line-height: 1.5;
        }
        .wrap { max-width: 900px; margin: 0 auto; }
        .card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            box-shadow: 0 1px 2px rgba(16, 24, 40, .05);
            overflow: hidden;
        }
        .head { padding: 24px 28px 0; }
        h1 { margin: 0 0 4px; font-size: 20px; letter-spacing: -.01em; }
        .sub { margin: 0; color: #6b7280; font-size: 13px; }
        .banner {
            margin: 20px 28px 0;
            padding: 14px 16px;
            border-radius: 8px;
            font-size: 14px;
            border: 1px solid;
        }
        .banner.success { background: #ecfdf5; border-color: #a7f3d0; color: #065f46; }
        .banner.failed,
        .banner.error { background: #fef2f2; border-color: #fecaca; color: #991b1b; }
        .banner.dry { background: #eff6ff; border-color: #bfdbfe; color: #1e40af; }
        .body { padding: 20px 28px 28px; }
        h2 {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: #6b7280;
            margin: 26px 0 8px;
        }
        dl.facts {
            display: grid;
            grid-template-columns: max-content 1fr;
            gap: 6px 18px;
            margin: 0;
            font-size: 14px;
        }
        dl.facts dt { color: #6b7280; }
        dl.facts dd {
            margin: 0;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
            font-size: 13px;
            word-break: break-all;
        }
        ul.migs {
            margin: 0;
            padding-left: 20px;
            font-size: 13px;
            line-height: 1.9;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
            word-break: break-word;
        }
        pre {
            margin: 0;
            padding: 14px 16px;
            background: #0b1220;
            color: #e5e7eb;
            border-radius: 8px;
            overflow-x: auto;
            font-size: 12.5px;
            line-height: 1.6;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .muted { color: #6b7280; font-size: 13px; }
        footer {
            margin-top: 26px;
            padding-top: 18px;
            border-top: 1px solid #e5e7eb;
            font-size: 13px;
            color: #6b7280;
            display: flex;
            gap: 12px;
            justify-content: space-between;
            flex-wrap: wrap;
        }
        a { color: #2563eb; text-decoration: none; }
        a:hover { text-decoration: underline; }
        code {
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
            background: #f3f4f6;
            padding: 1px 5px;
            border-radius: 4px;
            font-size: 12.5px;
        }
    </style>
</head>
<body>
<main class="wrap">
    <div class="card">
        <div class="head">
            <h1>{{ $heading }}</h1>
            <p class="sub">Admin migration tool · {{ config('app.name') }}</p>
        </div>

        <div class="banner {{ $status }}">{{ $message }}</div>

        <div class="body">
            @if ($facts !== [])
                <h2>Target</h2>
                <dl class="facts">
                    @foreach ($facts as $label => $value)
                        <dt>{{ $label }}</dt>
                        <dd>{{ $value }}</dd>
                    @endforeach
                </dl>
            @endif

            @if (count($pending))
                <h2>{{ $dryRun ? 'Pending migrations (would run)' : 'Pending before this run' }}</h2>
                <ul class="migs">
                    @foreach ($pending as $migration)
                        <li>{{ $migration }}</li>
                    @endforeach
                </ul>
            @endif

            @if (count($applied))
                <h2>Applied just now</h2>
                <ul class="migs">
                    @foreach ($applied as $migration)
                        <li>{{ $migration }}</li>
                    @endforeach
                </ul>
            @endif

            @if ($status === 'success' && count($pending) === 0)
                <p class="muted">No migration files were pending — the target database is already up to date.</p>
            @endif

            @if (count($pendingAfter))
                <h2>Still pending</h2>
                <p class="muted">These did not run. Fix the error below, then refresh to retry.</p>
                <ul class="migs">
                    @foreach ($pendingAfter as $migration)
                        <li>{{ $migration }}</li>
                    @endforeach
                </ul>
            @endif

            @if ($output !== '')
                <h2>Artisan output</h2>
                <pre>{{ $output }}</pre>
            @endif

            @if ($error)
                <h2>Error</h2>
                <pre>{{ $error }}</pre>
            @endif

            <footer>
                <span>
                    Migrations are recorded in the target's <code>migrations</code> table, so refreshing
                    only applies what is still pending.
                </span>
                <span>
                    <a href="{{ $refreshUrl }}">Run again</a>
                    · <a href="{{ route('dashboard') }}">Dashboard</a>
                </span>
            </footer>
        </div>
    </div>
</main>
</body>
</html>
