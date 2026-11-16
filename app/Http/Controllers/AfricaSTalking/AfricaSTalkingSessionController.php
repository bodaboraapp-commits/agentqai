<?php

declare(strict_types=1);

namespace App\Http\Controllers\AfricaSTalking;

use App\Actions\Calls\RecordDtmfAction;
use App\Actions\Calls\UpdateCallStatusAction;
use App\Http\Controllers\CallController;
use App\Http\Controllers\Controller;
use App\Models\Call;
use App\Services\Telephony\TelephonyManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Africa's Talking call session + events callbacks.
 *
 * AT's number-level Callback URL is fixed in their dashboard, so this is the
 * single entry point for both inbound calls and the answer step of an
 * outbound call. It matches the request to a Call row and redirects AT to the
 * per-call XML endpoint the shared TwimlController already serves (AT supports
 * <Redirect>), keeping all campaign/inbound XML logic in one place.
 *
 * Matching order: clientRequestId (sent when placing the call) → the AT
 * session id (a retry/redirect of the same call) → the newest initiated
 * outbound call with this From/To pair (AT does not echo clientRequestId on
 * the call-session callback, only on events).
 */
class AfricaSTalkingSessionController extends Controller
{
    public function __construct(private readonly TelephonyManager $telephony) {}

    public function session(Request $request): Response
    {
        $payload = $this->payload($request);
        $request->merge($payload);

        \Illuminate\Support\Facades\Log::info('AT session callback', [
            'payload' => $request->all(),
            'resolved' => ['from' => $payload['From'] ?? null, 'to' => $payload['To'] ?? null, 'direction' => $payload['Direction'] ?? null],
        ]);

        $sessionId = (string) ($payload['CallSid'] ?? '');
        $direction = strtolower((string) ($payload['Direction'] ?? ''));
        $from = $payload['From'] ?? null;
        $to = $payload['To'] ?? null;

        // AT reports a browser-originated call as direction=Inbound, with the
        // WebRTC client identity as caller (e.g. "AgentQ_AI.user_12") and our
        // AT number as destination — the client dialled a destination, not us.
        // The pending outbound Call row the softphone created a moment ago
        // holds that destination. Routing this to the inbound handler made AT
        // ring the client back into its own number and abort after 1s.
        if (($clientUserId = $this->clientUserIdFromCaller($from)) !== null) {
            $call = Call::query()
                ->where('provider', 'africastalking')
                ->where('direction', 'outbound')
                ->whereIn('status', ['initiated', 'ringing'])
                ->where('user_id', $clientUserId)
                ->where('created_at', '>=', now()->subMinutes(15))
                ->latest('id')
                ->first();

            if ($call !== null) {
                if ($sessionId !== '' && $call->shouldAdoptProviderCallId((string) $call->twilio_call_sid)) {
                    $call->update([
                        'twilio_call_sid' => $sessionId,
                        'provider_call_sid' => $sessionId,
                        'provider' => 'africastalking',
                        'status' => 'ringing',
                    ]);
                }

                return $this->redirectXml(route('at-xml.manual.call', array_filter([
                    'call_id' => $call->id,
                    'From' => $from,
                    'To' => $call->to_number,
                ])));
            }
        }

        if ($direction === 'inbound') {
            return $this->redirectXml(route('at-xml.inbound.call', array_filter([
                'From' => $from,
                'To' => $to,
                'CallSid' => $sessionId,
            ])));
        }

        $call = $this->resolveCall(
            isset($payload['call_id']) ? (string) $payload['call_id'] : null,
            $sessionId,
            $from,
            $to
        );

        if ($call === null) {
            return $this->errorXml('We could not match this call to a campaign. Please try again later.');
        }

        if ($sessionId !== '' && $call->shouldAdoptProviderCallId((string) $call->twilio_call_sid)) {
            $call->update([
                'twilio_call_sid' => $sessionId,
                'provider_call_sid' => $sessionId,
                'provider' => 'africastalking',
                'status' => 'ringing',
            ]);
        }

        if ($call->campaign_id) {
            $campaign = $call->campaign;
            $campaign?->loadMissing(['campaignContacts']);

            if ($campaign?->ai_agent_id) {
                // AT has no bidirectional media streaming: AI-agent calling is
                // hidden for this provider (config/telephony.php).
                return $this->errorXml('This call flow is not available.');
            }

            return $this->redirectXml(route(
                $campaign?->type === 'text_to_speech' ? 'at-xml.campaign.tts' : 'at-xml.campaign.voice',
                array_filter(['call_id' => $call->id, 'From' => $from, 'To' => $to])
            ));
        }

        return $this->redirectXml(route('at-xml.manual.call', array_filter([
            'call_id' => $call->id,
            'From' => $from,
            'To' => $to,
        ])));
    }

    /**
     * AT's single number-level Events URL. The same URL receives call status,
     * recording and DTMF callbacks (the generated XML points GetDigits/Record
     * at the per-call routes; this one catches the number-level stream).
     */
    public function events(Request $request): Response
    {
        $payload = $this->payload($request);
        $request->merge($payload);

        \Illuminate\Support\Facades\Log::info('AT events callback', ['payload' => $request->all()]);

        $call = $this->resolveCall(
            isset($payload['call_id']) ? (string) $payload['call_id'] : null,
            (string) ($payload['CallSid'] ?? ''),
            $payload['From'] ?? null,
            $payload['To'] ?? null
        );

        if ($call !== null) {
            $request->merge(['call_id' => $call->id]);
        }

        if ($request->input('recordingUrl') !== null || $request->input('RecordingUrl') !== null) {
            return app(CallController::class)->webhookRecording($request, $this->telephony);
        }

        $digits = $request->input('digits') ?? $request->input('Digits') ?? $request->input('dtmfDigits');
        if ($digits !== null && $digits !== '') {
            $request->merge(['Digits' => $digits]);

            return app(CallController::class)->webhookDtmf($request, app(RecordDtmfAction::class));
        }

        return app(CallController::class)->webhookStatus(
            $request,
            app(UpdateCallStatusAction::class),
            $this->telephony
        );
    }

    /**
     * @return array<string, string>
     */
    private function payload(Request $request): array
    {
        $value = function (string ...$keys) use ($request): ?string {
            foreach ($keys as $key) {
                $candidate = $request->input($key);
                if ($candidate !== null && $candidate !== '') {
                    return (string) $candidate;
                }
            }

            return null;
        };

        $direction = strtolower((string) ($value('Direction', 'direction') ?? ''));

        return array_filter([
            'From' => $value('From', 'callerNumber', 'CallerNumber'),
            'To' => $value('To', 'destinationNumber', 'DestinationNumber'),
            'Direction' => $direction !== '' ? $direction : null,
            'CallSid' => $value('CallSid', 'sessionId', 'callSessionId', 'callSessionID'),
            'Digits' => $value('Digits', 'digits', 'dtmfDigits'),
            'call_id' => $value('clientRequestId'),
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }

    /**
     * The user id behind an AT WebRTC client identity. AT reports the caller
     * as "{appUsername}.{clientName}" (e.g. "AgentQ_AI.user_12"); token
     * identities are "user_{id}". Real phone numbers never match.
     */
    private function clientUserIdFromCaller(?string $from): ?int
    {
        if ($from === null || $from === '') {
            return null;
        }

        if (preg_match('/(?:^|[.:])user_(\d+)$/', $from, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    private function resolveCall(?string $clientRequestId, string $sessionId, ?string $from, ?string $to): ?Call
    {
        if ($clientRequestId !== null && ctype_digit($clientRequestId)) {
            $call = Call::query()->whereKey((int) $clientRequestId)->where('provider', 'africastalking')->first();
            if ($call !== null) {
                return $call;
            }
        }

        if ($sessionId !== '') {
            $call = Call::query()
                ->where('provider', 'africastalking')
                ->where(function ($query) use ($sessionId): void {
                    $query->where('provider_call_sid', $sessionId)
                        ->orWhere('twilio_call_sid', $sessionId);
                })
                ->first();
            if ($call !== null) {
                return $call;
            }
        }

        if ($to === null) {
            return null;
        }

        $query = Call::query()
            ->where('provider', 'africastalking')
            ->where('direction', 'outbound')
            // A retried/duplicated callback arrives after the first one already
            // adopted the session id and moved the row to 'ringing'; both are
            // in-flight states for a dial that has not been answered yet.
            ->whereIn('status', ['initiated', 'ringing'])
            ->where('to_number', $to)
            ->where('created_at', '>=', now()->subMinutes(15));

        // Exact two-way match first: API-placed calls, and browsers whose
        // callback reports the AT number as callerNumber.
        if ($from !== null) {
            $exact = (clone $query)->where('from_number', $from)->latest('id')->first();
            if ($exact !== null) {
                return $exact;
            }

            // AT may report the WebRTC client identity (user_12) rather than a
            // phone number on a browser-originated call. Map it back to the
            // user and match the call that user's softphone just created.
            if (preg_match('/^(?:client:)?user_(\d+)$/', $from, $matches)) {
                $byUser = (clone $query)->where('user_id', (int) $matches[1])->latest('id')->first();
                if ($byUser !== null) {
                    return $byUser;
                }
            }
        }

        // Last resort for a manual dial: the destination plus recency is
        // unique enough among this installation's own AT-number calls.
        return $query->latest('id')->first();
    }

    private function redirectXml(string $url): Response
    {
        return $this->xml('<Response><Redirect>'.e($url).'</Redirect></Response>');
    }

    private function errorXml(string $message): Response
    {
        return $this->xml('<Response><Say>'.e($message).'</Say><Reject/></Response>');
    }

    private function xml(string $body): Response
    {
        return response('<?xml version="1.0" encoding="UTF-8"?>'.$body, 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
