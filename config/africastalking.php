<?php

declare(strict_types=1);

/**
 * Africa's Talking (Voice / SMS / WebRTC) settings.
 *
 * The installation is locked to a single telephony provider (see
 * config/telephony.php). Africa's Talking is added as a third provider
 * behind the same contracts, so nothing here is read unless this
 * installation is actually locked to 'africastalking'.
 *
 * Credentials themselves are NOT read from .env: they live encrypted in
 * telephony_providers.credentials (username, api_key, sender_id,
 * voice_number, client_name, webhook_secret) and are entered by an admin.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Feature gate
    |--------------------------------------------------------------------------
    |
    | The provider is built in phases (voice first, then softphone/SMS).
    | While false it is not offered by the installer or the provider
    | chooser, so no buyer can lock themselves to a half-finished provider.
    | Flip to true once the integration is verified end to end.
    |
    */
    'enabled' => (bool) env('AFRICASTALKING_ENABLED', false),

    'api' => [
        // Production Voice/SMS API host. Sandbox uses api.sandbox.…
        'base_url' => env('AFRICASTALKING_API_URL', 'https://api.africastalking.com'),
        'sandbox_base_url' => env('AFRICASTALKING_SANDBOX_API_URL', 'https://api.sandbox.africastalking.com'),
        'version_segment' => 'version1',
        'timeout_seconds' => (int) env('AFRICASTALKING_TIMEOUT', 20),

        // WebRTC capability-token service (browser softphone, phase 2).
        'webrtc_token_url' => env('AFRICASTALKING_WEBRTC_TOKEN_URL', 'https://webrtc.africastalking.com/capability-token/request'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook verification
    |--------------------------------------------------------------------------
    |
    | Africa's Talking does not sign callbacks. A shared secret (appended to
    | the callback URLs the app displays for the admin, or sent as
    | X-AfricaSTalking-Secret) is the supported verification; when it is
    | empty callbacks are accepted (matching Twilio's local/testing escape
    | hatch) and a warning is logged.
    |
    */
    'webhook' => [
        'secret' => env('AFRICASTALKING_WEBHOOK_SECRET'),
        // Africa's Talking recommends IP allow-listing at the provider/edge.
        // Optional comma-separated list, checked when set.
        'allowed_ips' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('AFRICASTALKING_WEBHOOK_IPS', ''))
        ))),
    ],

    'voice' => [
        // Numbers are provisioned in the AT dashboard (KYC). Calls are only
        // placed FROM a number on the account; this is the default fallback
        // when a call row has no explicit from-number.
        'default_number' => env('AFRICASTALKING_VOICE_NUMBER'),

        // AT <Say> voice + <GetDigits> defaults.
        'tts' => [
            'default_voice' => env('AFRICASTALKING_TTS_VOICE', 'en-US-Standard-A'),
            'default_language' => env('AFRICASTALKING_TTS_LANGUAGE', 'en-US'),
        ],

        // Read by AfricaSTalkingVoiceProvider::estimateCallCost(). This is a
        // configured estimate, not a live price — AT has no pricing API.
        'pricing' => [
            'api_per_minute' => (float) env('AFRICASTALKING_COST_PER_MINUTE', 0.02),
        ],

        // AT's Number callback URL is configured per number in their
        // dashboard (no API). The app displays the URL the admin must paste.
        'session_callback_path' => '/at-xml/session',
    ],
];
