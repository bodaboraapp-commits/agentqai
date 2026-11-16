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

Route::prefix('at-xml')->name('at-xml.')->middleware(['throttle:120,1'])->group(function (): void {
    // The single URL an AT number's "Callback" setting must point at.
    Route::match(['get', 'post'], '/session', [AfricaSTalkingSessionController::class, 'session'])
        ->name('session');

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
