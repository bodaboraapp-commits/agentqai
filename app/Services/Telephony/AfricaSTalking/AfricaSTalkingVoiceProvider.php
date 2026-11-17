<?php

declare(strict_types=1);

namespace App\Services\Telephony\AfricaSTalking;

use App\Contracts\Telephony\VoiceProviderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Africa's Talking voice provider.
 *
 * AT's programmable voice model is different from Twilio's: you place a call
 * with (to, from, clientRequestId) and AT then fetches the call-control XML
 * from the callback URL configured on the AT number. There is no per-call
 * answer URL and no REST call-detail/hangup endpoint, so:
 *
 *  - makeCall() only starts the call; the provider's session id, status,
 *    duration and recordings arrive on callbacks (like Plivo's RequestUUID
 *    flow, the interface already models an id that is not known yet);
 *  - getCallDetails()/hangupCall()/updateCall()/getRecording() report the
 *    absence rather than inventing data; live-call control features are
 *    hidden for this provider (see config/telephony.php hidden_features).
 */
class AfricaSTalkingVoiceProvider implements VoiceProviderInterface
{
    private ?string $username = null;

    private ?string $apiKey = null;

    public function initialize(array $credentials): static
    {
        $this->username = trim((string) ($credentials['username'] ?? $credentials['account_id'] ?? ''));
        $this->apiKey = trim((string) ($credentials['api_key'] ?? $credentials['auth_token'] ?? ''));

        return $this;
    }

    private function baseUrl(): string
    {
        $host = $this->username === 'sandbox'
            ? config('africastalking.api.sandbox_base_url')
            : config('africastalking.api.base_url');

        return rtrim((string) $host, '/').'/'.config('africastalking.api.version_segment', 'version1');
    }

    /**
     * AT's Voice API is a separate host from the user/SMS API: the outbound
     * call endpoint is POST https://voice.africastalking.com/call. The old
     * api…/version1/voice/call path answers nginx 404, so every campaign call
     * placed through the provider failed before AT ever saw it.
     */
    private function voiceEndpoint(): string
    {
        $host = $this->username === 'sandbox'
            ? config('africastalking.api.voice_sandbox_base_url')
            : config('africastalking.api.voice_base_url');

        return rtrim((string) $host, '/').config('africastalking.api.voice_call_path', '/call');
    }

    private function assertConfigured(): void
    {
        if ($this->username === null || $this->username === '' || $this->apiKey === null || $this->apiKey === '') {
            throw new RuntimeException('Africa\'s Talking is not configured: username and API key are required.');
        }
    }

    /**
     * Place an outbound call.
     *
     * AT has no answer-url parameter. The number's callback URL (set once in
     * the AT dashboard) receives the call and asks this app for call-control
     * XML; the session id arrives there. `options['clientRequestId']` is
     * echoed back on AT's events callback and lets the app match the session
     * to its own Call row.
     *
     * @return array{success: bool, call_id: ?string, request_uuid: ?string, status: ?string, error: ?string}
     */
    public function makeCall(string $to, string $from, string $answerUrl, array $options = []): array
    {
        $this->assertConfigured();

        $payload = [
            'username' => $this->username,
            'to' => $to,
            'from' => $from,
        ];

        $clientRequestId = $options['clientRequestId'] ?? null;
        if ($clientRequestId !== null && $clientRequestId !== '') {
            $payload['clientRequestId'] = (string) $clientRequestId;
        }

        try {
            $response = Http::withHeaders(['apiKey' => $this->apiKey, 'Accept' => 'application/json'])
                // voice.africastalking.com intermittently takes >10s to accept a
                // connection from some networks (campaign calls failed with
                // cURL error 28 at exactly 10s). Give the connect and the whole
                // request more room, and retry transient connection failures.
                ->connectTimeout(10)
                ->timeout(max(30, (int) config('africastalking.api.timeout_seconds', 30)))
                ->retry(2, 800, fn ($exception): bool => $exception instanceof \Illuminate\Http\Client\ConnectionException, false)
                ->asForm()
                ->post($this->voiceEndpoint(), $payload);

            if (! $response->successful()) {
                $message = $this->errorMessage($response->json(), $response->body());

                return [
                    'success' => false,
                    'call_id' => null,
                    'request_uuid' => null,
                    'status' => null,
                    'error' => 'Africa\'s Talking rejected the call: '.$message,
                ];
            }

            // AT returns no call/session id here — it arrives with the first
            // callback. "Queued" is the state the provider reports.
            return [
                'success' => true,
                'call_id' => null,
                'request_uuid' => null,
                'status' => 'queued',
                'error' => null,
            ];
        } catch (\Throwable $e) {
            Log::error('Africa\'s Talking voice: call request failed', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'call_id' => null,
                'request_uuid' => null,
                'status' => null,
                'error' => 'Failed to reach Africa\'s Talking: '.$e->getMessage(),
            ];
        }
    }

    private function errorMessage(mixed $json, string $body): string
    {
        if (is_array($json)) {
            foreach (['errorMessage', 'description', 'message'] as $key) {
                if (isset($json[$key]) && is_string($json[$key]) && $json[$key] !== '') {
                    return $json[$key];
                }
            }
        }

        return $body !== '' ? mb_substr($body, 0, 200) : 'Unknown error';
    }

    /**
     * AT exposes no call-detail endpoint; the call row is maintained from
     * callbacks.
     */
    public function getCallDetails(string $callId): ?array
    {
        return null;
    }

    /**
     * AT exposes no live call-control endpoint (no TwiML "hangup" REST call).
     * Ending a call is done from call-control XML (<Reject/>).
     */
    public function updateCall(string $callId, array $params): bool
    {
        Log::info('Africa\'s Talking voice: updateCall is not supported', ['call_id' => $callId]);

        return false;
    }

    public function hangupCall(string $callId): bool
    {
        Log::info('Africa\'s Talking voice: hangupCall is not supported', ['call_id' => $callId]);

        return false;
    }

    /**
     * Recordings arrive as recording callbacks (URL + session id); AT has no
     * fetch/delete recording API.
     */
    public function getRecording(string $recordingId): ?array
    {
        return null;
    }

    public function deleteRecording(string $recordingId): bool
    {
        return false;
    }

    /**
     * Verify the username/API key against AT's user endpoint.
     *
     * @return array{valid: bool, account_name: ?string, error: ?string}
     */
    public function verifyCredentials(array $credentials): array
    {
        $username = trim((string) ($credentials['username'] ?? $credentials['account_id'] ?? ''));
        $apiKey = trim((string) ($credentials['api_key'] ?? $credentials['auth_token'] ?? ''));

        if ($username === '' || $apiKey === '') {
            return ['valid' => false, 'account_name' => null, 'error' => 'Username and API key are required.'];
        }

        $this->initialize(['username' => $username, 'api_key' => $apiKey]);

        try {
            $response = Http::withHeaders(['apiKey' => $apiKey, 'Accept' => 'application/json'])
                ->timeout((int) config('africastalking.api.timeout_seconds', 20))
                ->get($this->baseUrl().'/user', ['username' => $username]);

            if ($response->successful()) {
                $data = $response->json();
                $balance = $data['UserData']['balance'] ?? null;

                return [
                    'valid' => true,
                    'account_name' => 'Africa\'s Talking — '.$username.($balance !== null ? ' (balance: '.$balance.')' : ''),
                    'error' => null,
                ];
            }

            $message = $this->errorMessage($response->json(), $response->body());

            return [
                'valid' => false,
                'account_name' => null,
                'error' => $response->status() === 401
                    ? 'Africa\'s Talking rejected the credentials (401). Check the username and API key in the AT dashboard.'
                    : 'Africa\'s Talking verification failed: '.$message,
            ];
        } catch (\Throwable $e) {
            return ['valid' => false, 'account_name' => null, 'error' => 'Could not reach Africa\'s Talking: '.$e->getMessage()];
        }
    }

    /**
     * AT does not sign webhooks. A shared secret (shown in the admin
     * callback URLs / X-AfricaSTalking-Secret header) is the supported check.
     */
    public function validateWebhook(array $payload, string $signature, string $url): bool
    {
        $secret = (string) config('africastalking.webhook.secret', '');

        if ($secret === '') {
            return true;
        }

        $provided = $signature !== '' ? $signature : (string) ($payload['secret'] ?? '');

        return $provided !== '' && hash_equals($secret, $provided);
    }

    public function estimateCallCost(string $to, int $durationSeconds): float
    {
        $perMinute = (float) config('africastalking.voice.pricing.api_per_minute', 0.02);

        return round(($durationSeconds / 60) * $perMinute, 4);
    }

    public function getName(): string
    {
        return 'africastalking';
    }
}
