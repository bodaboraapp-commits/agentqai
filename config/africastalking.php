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
        // Production Voice/SMS API host. Sandbox uses api.sandbox.â€¦
        'base_url' => env('AFRICASTALKING_API_URL', 'https://api.africastalking.com'),
        'sandbox_base_url' => env('AFRICASTALKING_SANDBOX_API_URL', 'https://api.sandbox.africastalking.com'),
        'version_segment' => 'version1',
        'timeout_seconds' => (int) env('AFRICASTALKING_TIMEOUT', 30),

        // Voice API is a separate host from the user/SMS API: POST /call on
        // voice.africastalking.com (voice.sandbox.â€¦ in sandbox). The old
        // apiâ€¦/version1/voice/call path returns nginx 404.
        'voice_base_url' => env('AFRICASTALKING_VOICE_API_URL', 'https://voice.africastalking.com'),
        'voice_sandbox_base_url' => env('AFRICASTALKING_VOICE_SANDBOX_API_URL', 'https://voice.sandbox.africastalking.com'),
        'voice_call_path' => '/call',

        // WebRTC capability-token service (browser softphone, phase 2).
        'webrtc_token_url' => env('AFRICASTALKING_WEBRTC_TOKEN_URL', 'https://webrtc.africastalking.com/capability-token/request'),

        /*
        |----------------------------------------------------------------------
        | Browser-dial bridge shape
        |----------------------------------------------------------------------
        |
        | The <Dial> we return for a browser-originated call is the main lever
        | for "rings, answers, no audio, AT drops after ~5s". Switchable per
        | environment to isolate without a deploy:
        |
        |   full       â€” callerId + maxDuration=30 + record (current default)
        |   minimal    â€” callerId only (AT defaults decide timeouts/recording)
        |   documented â€” callerId + sequential + record (AT's own softphone
        |                sample: <Dial sequential="true" record="true" â€¦>)
        |
        */
        'bridge_dial_mode' => env('AFRICASTALKING_BRIDGE_DIAL_MODE', 'full'),
        'bridge_ringback_tone' => env('AFRICASTALKING_BRIDGE_RINGBACK_TONE'),
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

        // AT's <Dial phoneNumbers="â€¦"> is used for both PSTN numbers and this
        // installation's registered browser clients. AT does not document a
        // client-dial form, so the prefix is configurable to validate it live:
        //   ''               â†’ "user_12"
        //   'client:'        â†’ "client:user_12"
        //   'AgentQ_AI.'     â†’ "AgentQ_AI.user_12"  (AT's own identity format)
        'webrtc_client_prefix' => env('AFRICASTALKING_CLIENT_PREFIX', ''),

        /*
        |----------------------------------------------------------------------
        | Inbound policy
        |----------------------------------------------------------------------
        |
        | AT Voice XML cannot ring a browser WebRTC client by name (AT answers
        | the bridge with a 0-second "Aborted"); the documented softphone target
        | is an AT SIP phone. Two strategies:
        |
        |   client  â€” try the WebRTC client identity with CLIENT_PREFIX above.
        |             Experimental; AT answers 0s "Aborted" for client names.
        |   forward â€” dial AFRICASTALKING_INBOUND_FORWARD: one E.164 number, or
        |             several comma-separated numbers dialled sequentially
        |             (a ring group). AT-documented and always rings.
        |   voicemail â€” record the caller on AT, store the recording and a
        |             Deepgram transcript on the Call row. Fully automated and
        |             needs no SIP/WebRTC support.
        |   auto    â€” forward when a forward number is set, otherwise voicemail.
        |
        */
        'inbound' => [
            'mode' => env('AFRICASTALKING_INBOUND_MODE', 'auto'),
            'forward_to' => env('AFRICASTALKING_INBOUND_FORWARD'),
            'greeting' => env('AFRICASTALKING_INBOUND_GREETING', 'No one is available to take your call. Please leave a message after the tone.'),
            'voicemail_max_seconds' => (int) env('AFRICASTALKING_INBOUND_VOICEMAIL_MAX', 45),

            // Optional IVR (mode=ivr): "press 1 for an operator, 2 for
            // voicemail" before falling back to forward/voicemail.
            'ivr' => [
                'greeting' => env('AFRICASTALKING_INBOUND_IVR_GREETING', 'Welcome. Press 1 to speak to an operator, or press 2 to leave a voicemail.'),
                'timeout_seconds' => (int) env('AFRICASTALKING_INBOUND_IVR_TIMEOUT', 8),
                // What to do when nothing is pressed.
                'timeout_action' => env('AFRICASTALKING_INBOUND_IVR_TIMEOUT_ACTION', 'voicemail'),
                // What to do after the caller presses an invalid key twice.
                'invalid_action' => env('AFRICASTALKING_INBOUND_IVR_INVALID_ACTION', 'voicemail'),
                'max_retries' => (int) env('AFRICASTALKING_INBOUND_IVR_MAX_RETRIES', 1),
            ],
        ],

        // AT <Say> voice + <GetDigits> defaults. Empty means "let AT use its
        // own default voice" â€” Twilio/Polly voice names must never be sent to
        // AT, which rejects the whole XML document for an unknown voice.
        'tts' => [
            'default_voice' => env('AFRICASTALKING_TTS_VOICE', ''),
            'default_language' => env('AFRICASTALKING_TTS_LANGUAGE', 'en-US'),
        ],

        // Read by AfricaSTalkingVoiceProvider::estimateCallCost(). This is a
        // configured estimate, not a live price â€” AT has no pricing API.
        'pricing' => [
            'api_per_minute' => (float) env('AFRICASTALKING_COST_PER_MINUTE', 0.02),
        ],

        // AT's Number callback URL is configured per number in their
        // dashboard (no API). The app displays the URL the admin must paste.
        'session_callback_path' => '/at-xml/session',
    ],
];
