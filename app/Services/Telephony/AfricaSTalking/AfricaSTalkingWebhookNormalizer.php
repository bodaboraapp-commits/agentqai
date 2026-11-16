<?php

declare(strict_types=1);

namespace App\Services\Telephony\AfricaSTalking;

use App\Contracts\Telephony\WebhookNormalizerInterface;
use Illuminate\Support\Str;

/**
 * Normalises Africa's Talking callbacks into the application's provider-neutral
 * shapes.
 *
 * AT posts application/x-www-form-urlencoded callbacks. The events callback
 * looks like:
 *   callSessionState=Active&direction=Outbound&callerCountryCode=254
 *   &callerNumber=+254…&destinationNumber=+254…
 *   &sessionId=ATVId_…&callStartTime=None&isActive=1
 * and hang-up events add hangupCause / durationInSeconds / currencyCode /
 * amount. Field names are normalised case-insensitively because AT has
 * changed casing between callback types.
 */
class AfricaSTalkingWebhookNormalizer implements WebhookNormalizerInterface
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function lowerKeys(array $payload): array
    {
        $lower = [];
        foreach ($payload as $key => $value) {
            $lower[strtolower((string) $key)] = $value;
        }

        return $lower;
    }

    private function value(array $payload, string ...$keys): mixed
    {
        $lower = $this->lowerKeys($payload);

        foreach ($keys as $key) {
            if (array_key_exists($key, $lower) && $lower[$key] !== '') {
                return $lower[$key];
            }
        }

        return null;
    }

    public function normalizeCallEvent(array $payload): array
    {
        return [
            'call_id' => $this->extractCallId($payload) ?? '',
            'from' => (string) ($this->value($payload, 'callernumber', 'from') ?? ''),
            'to' => (string) ($this->value($payload, 'destinationnumber', 'to') ?? ''),
            'direction' => $this->direction($payload),
            'status' => $this->status($payload),
            'provider' => 'africastalking',
            'raw' => $payload,
        ];
    }

    public function normalizeStatusCallback(array $payload): array
    {
        $duration = $this->value($payload, 'durationinseconds', 'duration');
        $amount = $this->value($payload, 'amount', 'cost');
        $hangupCause = $this->value($payload, 'hangupcause');

        return [
            'call_id' => $this->extractCallId($payload) ?? '',
            'status' => $this->status($payload),
            'duration' => is_numeric($duration) ? (int) $duration : null,
            'price' => is_numeric($amount) ? (float) $amount : null,
            // AT has no answering-machine detection, so answered_by is never
            // populated (answered-derived metrics are hidden for this provider).
            'answered_by' => null,
            'provider' => 'africastalking',
            'hangup_cause' => is_string($hangupCause) ? $hangupCause : null,
            'raw' => $payload,
        ];
    }

    public function normalizeRecordingCallback(array $payload): array
    {
        $duration = $this->value($payload, 'durationinseconds', 'duration');

        return [
            'call_id' => $this->extractCallId($payload) ?? '',
            'recording_id' => (string) ($this->extractCallId($payload) ?? ''),
            'recording_url' => $this->value($payload, 'recordingurl', 'url'),
            'duration' => is_numeric($duration) ? (int) $duration : null,
            'status' => 'completed',
            'provider' => 'africastalking',
            'raw' => $payload,
        ];
    }

    public function normalizeIncomingSms(array $payload): array
    {
        $media = $this->value($payload, 'mediaurls', 'media');

        return [
            'message_id' => (string) ($this->value($payload, 'id', 'messageid') ?? ''),
            'from' => (string) ($this->value($payload, 'from', 'sender') ?? ''),
            'to' => (string) ($this->value($payload, 'to', 'linkid') ?? ''),
            'body' => (string) ($this->value($payload, 'text', 'body') ?? ''),
            'media_urls' => is_string($media) && $media !== '' ? [$media] : [],
            'provider' => 'africastalking',
            'raw' => $payload,
        ];
    }

    public function extractCallId(array $payload): ?string
    {
        $id = $this->value($payload, 'sessionid', 'callsessionid', 'callid');

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function isInboundCall(array $payload): bool
    {
        return $this->direction($payload) === 'inbound';
    }

    private function direction(array $payload): string
    {
        $direction = strtolower((string) ($this->value($payload, 'direction') ?? ''));

        return str_contains($direction, 'out') ? 'outbound' : 'inbound';
    }

    /**
     * Map AT's callSessionState (and hang-up causes) onto the app's statuses.
     */
    private function status(array $payload): string
    {
        $state = strtolower((string) ($this->value($payload, 'callsessionstate', 'status') ?? ''));

        if ($state !== '') {
            return match (true) {
                // "noanswer" must be tested before the generic "answer" match,
                // or a no-answer callback would read as in-progress.
                str_contains($state, 'noanswer'), str_contains($state, 'no-answer'), str_contains($state, 'no answer') => 'no-answer',
                str_contains($state, 'complete'), str_contains($state, 'hangup') => 'completed',
                str_contains($state, 'busy') => 'busy',
                str_contains($state, 'fail'), str_contains($state, 'reject') => 'failed',
                str_contains($state, 'ring') => 'ringing',
                str_contains($state, 'queue'), str_contains($state, 'initiat') => 'queued',
                str_contains($state, 'active'), str_contains($state, 'answer') => 'in-progress',
                default => Str::snake($state),
            };
        }

        $cause = strtolower((string) ($this->value($payload, 'hangupcause') ?? ''));

        return match (true) {
            str_contains($cause, 'normal'), $cause === '0' => 'completed',
            str_contains($cause, 'busy') => 'busy',
            str_contains($cause, 'noanswer'), str_contains($cause, 'no answer') => 'no-answer',
            $cause !== '' => 'failed',
            default => 'unknown',
        };
    }

    public function getProviderName(): string
    {
        return 'africastalking';
    }
}
