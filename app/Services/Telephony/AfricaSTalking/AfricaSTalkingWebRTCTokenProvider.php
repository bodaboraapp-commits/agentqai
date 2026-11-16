<?php

declare(strict_types=1);

namespace App\Services\Telephony\AfricaSTalking;

use App\Contracts\Telephony\WebRTCTokenProviderInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * AT browser-softphone capability tokens.
 *
 * AT's client SDK registers with a short-lived capability token, minted by
 * POSTing the app credentials to webrtc.africastalking.com. The token is
 * scoped to one clientName and one AT phone number, so the browser never sees
 * the API key.
 *
 * Endpoint/response documented by the africastalking-client SDK README:
 *   POST https://webrtc.africastalking.com/capability-token/request
 *   header: apiKey
 *   form: username, clientName, phoneNumber, incoming, outgoing, expire
 *   → { token, clientName, incoming, outgoing, lifeTimeSec }
 */
class AfricaSTalkingWebRTCTokenProvider implements WebRTCTokenProviderInterface
{
    private ?string $username = null;

    private ?string $apiKey = null;

    /** @var array<string, mixed> */
    private array $credentials = [];

    public function initialize(array $credentials): static
    {
        $this->credentials = $credentials;
        $this->username = trim((string) ($credentials['username'] ?? $credentials['account_id'] ?? ''));
        $this->apiKey = trim((string) ($credentials['api_key'] ?? $credentials['auth_token'] ?? ''));

        return $this;
    }

    /**
     * @param  array{phone_number?: string, expires_in?: int}  $options
     * @return array{token: string, identity: string, provider: string, expires_in: int}
     */
    public function generateToken(string $identity, array $options = []): array
    {
        if ($this->username === null || $this->username === '' || $this->apiKey === null || $this->apiKey === '') {
            throw new RuntimeException('Africa\'s Talking is not configured: username and API key are required.');
        }

        // Token generation requires the AT number the client calls from.
        $phoneNumber = $options['phone_number']
            ?? $this->credentials['voice_number']
            ?? config('africastalking.voice.default_number');

        if (! is_string($phoneNumber) || trim($phoneNumber) === '') {
            throw new RuntimeException('No Africa\'s Talking number is available for the softphone. Assign one to the user first.');
        }

        $expiresIn = (int) ($options['expires_in'] ?? 3600);

        // AT clientName has no spaces and is the identity calls show up as.
        $clientName = preg_replace('/\s+/', '_', $identity) ?: $identity;

        try {
            // AT's capability-token endpoint accepts ONLY application/json
            // (form-encoded bodies get a 415), with boolean incoming/outgoing
            // and a numeric expire. Sending form data was why the browser
            // softphone showed "Request failed with status code 503".
            $response = Http::withHeaders(['apiKey' => $this->apiKey, 'Accept' => 'application/json'])
                ->timeout((int) config('africastalking.api.timeout_seconds', 20))
                ->asJson()
                ->post((string) config('africastalking.api.webrtc_token_url'), [
                    'username' => $this->username,
                    'clientName' => $clientName,
                    'phoneNumber' => trim($phoneNumber),
                    'incoming' => true,
                    'outgoing' => true,
                    'expire' => $expiresIn,
                ]);
        } catch (\Throwable $e) {
            throw new RuntimeException('Could not reach Africa\'s Talking to create a softphone token: '.$e->getMessage());
        }

        if (! $response->successful()) {
            $message = $response->json('errorMessage')
                ?? $response->json('message')
                ?? mb_substr($response->body(), 0, 200);

            throw new RuntimeException('Africa\'s Talking rejected the softphone token request: '.$message);
        }

        $token = (string) ($response->json('token') ?? '');

        if ($token === '') {
            throw new RuntimeException('Africa\'s Talking returned no softphone token.');
        }

        return [
            'token' => $token,
            'identity' => (string) ($response->json('clientName') ?? $clientName),
            'provider' => 'africastalking',
            'expires_in' => (int) ($response->json('lifeTimeSec') ?? $expiresIn),
        ];
    }

    public function getName(): string
    {
        return 'africastalking';
    }
}
