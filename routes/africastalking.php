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
            'latest_inbound_calls' => \App\Models\Call::query()
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
