<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\TelephonyProvider;
use App\Models\Call;
use App\Models\Campaign;
use App\Models\CampaignContact;
use App\Models\MessageVariant;
use App\Models\TelephonyProviderConfig;
use App\Services\CreditService;
use App\Services\PhoneValidationService;
use App\Services\Telephony\TelephonyManager;
use App\Services\TwilioService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class MakeCampaignCallJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1; // Retries handled manually

    public int $timeout = 120; // 2 minutes

    public int $maxExceptions = 2;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Campaign $campaign,
        public CampaignContact $contact
    ) {
        // Use dedicated queue for campaign calls
        $this->queue = 'campaign-calls';
    }

    /**
     * Execute the job.
     */
    public function handle(
        TwilioService $twilioService,
        CreditService $creditService,
        PhoneValidationService $phoneValidator,
        TelephonyManager $telephony
    ): void {
        // Check if campaign is still running
        $this->campaign->refresh();

        if ($this->campaign->status !== 'running') {
            Log::info("Campaign {$this->campaign->id} is not running, skipping call");
            $this->contact->update(['status' => 'pending']); // Reset to pending

            return;
        }

        // Check if contact already called
        $this->contact->refresh();
        if ($this->contact->status === 'completed' || $this->contact->status === 'failed') {
            return;
        }

        // Whether the provider actually placed a real call — tracked
        // explicitly rather than inferred from $call->status, because a
        // later, unrelated failure (e.g. a credit-deduction race under
        // concurrent dialling) can leave status sitting at 'initiated'
        // even though the provider already dialled. Only this flag may
        // decide whether the catch block below is allowed to terminalize
        // the Call row.
        $providerCallPlaced = false;

        try {
            // 1. CHECK USER CREDITS FIRST (before any API call)
            $user = $this->campaign->user;
            if (! $creditService->canAfford($user, 1.0)) {
                Log::warning("Insufficient credits for campaign {$this->campaign->id}, pausing campaign");

                // Pause the campaign
                $this->campaign->update([
                    'status' => 'paused',
                    'paused_at' => now(),
                ]);

                // Reset contact to pending
                $this->contact->update(['status' => 'pending']);

                return;
            }

            // 2. VALIDATE AND SANITIZE PHONE NUMBER
            $phoneResult = $phoneValidator->sanitize($this->contact->phone_number, (string) config('telephony.default_country_code', '+254'));

            if (! $phoneResult['success']) {
                // Invalid phone number - skip this contact
                Log::error("Invalid phone number for contact {$this->contact->id}: {$phoneResult['error']}");

                $this->contact->update([
                    'status' => 'failed',
                    'call_attempts' => $this->contact->call_attempts + 1,
                ]);

                // Don't deduct credits for invalid numbers
                return;
            }

            $sanitizedNumber = $phoneResult['formatted'];

            $provider = $telephony->provider();

            // 3. CREATE CALL RECORD
            $call = Call::create([
                'user_id' => $this->campaign->user_id,
                'campaign_id' => $this->campaign->id,
                'campaign_contact_id' => $this->contact->id,
                'message_variant_id' => $this->selectMessageVariant(),
                'to_number' => $sanitizedNumber, // Use sanitized number
                'from_number' => $this->campaign->from_number,
                'status' => 'initiated',
                'direction' => 'outbound',
                'call_type' => 'campaign',
                'enable_recording' => $this->campaign->enable_recording,
                'enable_dtmf' => $this->campaign->enable_dtmf,
                'provider' => $provider->value,
            ]);

            // 4. PREPARE CALL PARAMETERS — answer URL follows the locked
            // provider (twiml.* for Twilio, plivo-xml.* for Plivo).
            $callParams = $this->prepareCallParameters($call->id, $provider);

            // 5. RESOLVE CREDENTIALS FOR THE LOCKED PROVIDER
            //
            // Twilio: unchanged — initializeForUser() still resolves the
            // customer's own credential first, falling back to the
            // admin's global configuration, and throws the same clear
            // error when neither exists. The resolved credential is then
            // read back into the shape TelephonyManager::voice() needs;
            // this still runs on the injected (container-singleton)
            // $twilioService, so a test overriding that singleton's
            // initiateCall() continues to observe the real, unmodified
            // resolution.
            //
            // Plivo: this installation's one configured account,
            // read-only (see resolvePlivoCredentials()).
            if ($provider === TelephonyProvider::PLIVO) {
                $credentials = $this->resolvePlivoCredentials();
                $statusCallbackUrl = route('webhooks.plivo.call.status', ['call_id' => $call->id]);
            } elseif ($provider === TelephonyProvider::AFRICASTALKING) {
                $credentials = $this->resolveAfricaSTalkingCredentials();
                $statusCallbackUrl = route('webhooks.africastalking.call.status', ['call_id' => $call->id]);
            } else {
                $twilioService->initializeForUser($this->campaign->user);
                $credential = $twilioService->getCredential();
                $credentials = [
                    'account_sid' => $credential->account_sid,
                    'auth_token' => $credential->auth_token,
                ];
                $statusCallbackUrl = route('webhooks.twilio.call.status', ['call_id' => $call->id]);
            }

            // 6. MAKE CALL VIA THE LOCKED PROVIDER
            $callResult = $telephony->voice()->initialize($credentials)->makeCall(
                $sanitizedNumber, // Use sanitized number
                $this->campaign->from_number,
                $callParams['twiml_url'],
                [
                    'statusCallback' => $statusCallbackUrl,
                    'record' => $this->campaign->enable_recording,
                    // Africa's Talking echoes this back on its events callback
                    // so the session can be matched to this Call row; other
                    // providers ignore it.
                    'clientRequestId' => $call->id,
                ]
            );

            if (! $callResult['success']) {
                // Check if it's a critical provider error
                $errorMessage = $callResult['error'] ?? 'Unknown error';

                if ($this->isCriticalProviderError($errorMessage)) {
                    // Critical error - pause campaign
                    Log::error("Critical provider error for campaign {$this->campaign->id}: {$errorMessage}");

                    $this->campaign->update([
                        'status' => 'paused',
                        'paused_at' => now(),
                    ]);

                    throw new \Exception($errorMessage);
                }

                // Non-critical error - skip this contact and continue
                Log::warning("Provider call failed for contact {$this->contact->id}: {$errorMessage}");

                $this->contact->update([
                    'status' => 'failed',
                    'call_attempts' => $this->contact->call_attempts + 1,
                ]);

                // Mark call as failed — keep the reason on the row so Call
                // Logs/UI show why (network timeout, AT rejection, credits…)
                // instead of a bare "failed" with no SID.
                $call->update([
                    'status' => 'failed',
                    'error_message' => mb_substr((string) $errorMessage, 0, 500),
                ]);

                // Don't deduct credits for failed calls
                return;
            }

            // The provider has now actually placed the call. From this
            // point on, any exception must not cause the catch block below
            // to terminalize the Call row — only a failure that happened
            // before the provider was ever contacted may do that. True on
            // either provider: it marks "the provider accepted this call
            // attempt", not "the call was answered" — a distinction that
            // was already true of Twilio's synchronous success and is
            // equally true of Plivo's RequestUUID-only success below.
            $providerCallPlaced = true;

            // 7. CALL INITIATED SUCCESSFULLY - DEDUCT CREDITS
            $deductResult = $creditService->deductCredit(
                $user,
                1.0,
                "Campaign call to {$sanitizedNumber}",
                [
                    'service_type' => 'call',
                    'reference_type' => 'Call',
                    'reference_id' => $call->id,
                    'metadata' => [
                        'campaign_id' => $this->campaign->id,
                        'contact_id' => $this->contact->id,
                    ],
                ]
            );

            if (! $deductResult) {
                // This shouldn't happen as we checked credits earlier
                Log::error('Failed to deduct credits after successful call initiation');
            }

            // 8. UPDATE CALL WITH THE PROVIDER'S IDENTIFIER
            //
            // On Plivo, call_id here is only a RequestUUID — the real
            // CallUUID isn't known yet and first arrives on the call's
            // first status webhook (UpdateCallStatusAction). Written
            // anyway, into both columns, so the row is findable and
            // hangupable in the meantime; see
            // PlivoVoiceProvider::makeCall()'s docblock.
            $providerStatus = $callResult['status'];
            $mappedStatus = match ($providerStatus) {
                'queued', 'initiated' => 'initiated',
                'ringing' => 'ringing',
                'in-progress', 'answered' => 'in-progress',
                'completed' => 'completed',
                'busy' => 'busy',
                'no-answer', 'no_answer' => 'no-answer',
                'failed' => 'failed',
                'canceled', 'cancelled' => 'canceled',
                default => 'initiated',
            };

            $call->update([
                'twilio_call_sid' => $callResult['call_id'],
                'provider_call_sid' => $callResult['call_id'],
                // Permanent record of what makeCall() actually returned —
                // null on Twilio, the RequestUUID on Plivo — so
                // Call::shouldAdoptProviderCallId() can tell "still the
                // placeholder" from "confirmed" without looking at status.
                'provider_request_id' => $callResult['request_uuid'],
                'status' => $mappedStatus,
            ]);

            // 9. UPDATE CONTACT STATUS
            $this->contact->update([
                'status' => 'in_progress',
                'call_attempts' => $this->contact->call_attempts + 1,
                'last_call_at' => now(),
            ]);

            Log::info("Call initiated successfully for campaign {$this->campaign->id}, contact {$this->contact->id}, credits deducted");

        } catch (\Exception $e) {
            Log::error("Failed to make call for campaign {$this->campaign->id}, contact {$this->contact->id}: {$e->getMessage()}");

            // The Call record created above (step 3) must not survive as a
            // permanently 'initiated' row when the provider was never
            // contacted at all — e.g. Twilio credential resolution
            // failing. $providerCallPlaced, not $call->status, is what
            // decides that: once the provider has actually placed a call,
            // a later, unrelated failure (a credit-deduction race under
            // concurrent dialling, a lock timeout) can still leave status
            // sitting at 'initiated', and that row must be left exactly as
            // the provider path gave it — it is the sync job reconciling
            // calls still in flight that finds it later, not this catch
            // block. Marking it 'failed' here would hide a real, possibly
            // billed call from every reconciliation path that matters.
            // Status is kept only as a secondary, belt-and-braces check.
            if (isset($call) && ! $providerCallPlaced && $call->status === 'initiated') {
                $call->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);
            }

            // Handle retry logic
            $this->handleCallFailure($e);
        }
    }

    /**
     * Check if the error is critical (should pause campaign). Keyword
     * list is generic enough to plausibly match either provider's error
     * strings; not provider-specific.
     *
     * 'account' was dropped: it is a bare, common English word that
     * shows up in plenty of benign provider messages (a low-balance
     * notice, a per-account feature not being enabled, and similar), and
     * matching it pauses the entire campaign on a false positive.
     * 'suspended'/'disabled' already catch a genuinely bad account's own
     * wording without that risk.
     */
    private function isCriticalProviderError(string $errorMessage): bool
    {
        $criticalErrors = [
            'authenticate',
            'credentials',
            'suspended',
            'disabled',
            'permission',
            'unauthorized',
        ];

        $lowerError = strtolower($errorMessage);

        foreach ($criticalErrors as $keyword) {
            if (str_contains($lowerError, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prepare call parameters based on campaign type.
     *
     * Route name prefix follows the locked provider — 'twiml.' for
     * Twilio (byte-identical to the pre-existing route names), 'plivo-xml.'
     * for Plivo — mirroring the same ternary TwimlController itself
     * already uses to pick between its own two route groups.
     */
    private function prepareCallParameters(int $callId, TelephonyProvider $provider): array
    {
        $prefix = match ($provider) {
            TelephonyProvider::PLIVO => 'plivo-xml.',
            TelephonyProvider::AFRICASTALKING => 'at-xml.',
            default => 'twiml.',
        };

        // Check if campaign has AI agent assigned. AT has no real-time media
        // streaming, so there is no at-xml.campaign.ai-agent route: an
        // AI-agent campaign would throw "Route [at-xml.campaign.ai-agent] not
        // defined" and never dial. Fall back to the campaign's TTS/voice file
        // on AT instead.
        if ($this->campaign->ai_agent_id && $provider !== TelephonyProvider::AFRICASTALKING) {
            // Use AI agent conversation endpoint
            $twimlUrl = route($prefix.'campaign.ai-agent').'?call_id='.$callId;
        } elseif ($this->campaign->ai_agent_id && $provider === TelephonyProvider::AFRICASTALKING) {
            Log::warning("Campaign {$this->campaign->id} has an AI agent assigned but AT does not support AI voice agents; falling back to campaign TTS/voice playback");

            $twimlUrl = $this->campaign->type === 'text_to_speech'
                ? route($prefix.'campaign.tts').'?call_id='.$callId
                : route($prefix.'campaign.voice').'?call_id='.$callId;
        } elseif ($this->campaign->type === 'text_to_speech') {
            // Use TTS endpoint with call_id as query parameter
            $twimlUrl = route($prefix.'campaign.tts').'?call_id='.$callId;
        } else {
            // Use voice file endpoint with call_id as query parameter
            $twimlUrl = route($prefix.'campaign.voice').'?call_id='.$callId;
        }

        return [
            'twiml_url' => $twimlUrl,
        ];
    }

    /**
     * This installation's one configured Plivo account
     * (TelephonyProviderConfig, name 'plivo'), read-only — never
     * forProvider()'s firstOrCreate().
     *
     * @return array<string, string>
     */
    private function resolvePlivoCredentials(): array
    {
        $config = TelephonyProviderConfig::where('name', 'plivo')->first();
        $authId = $config?->credential('auth_id');
        $authToken = $config?->credential('auth_token');

        if (! $authId || ! $authToken) {
            throw new \Exception('Plivo is not configured for this platform. Ask an administrator to configure it in Settings → Telephony.');
        }

        return ['auth_id' => $authId, 'auth_token' => $authToken];
    }

    /**
     * This installation's one configured Africa's Talking account
     * (TelephonyProviderConfig, name 'africastalking'), read-only.
     *
     * @return array<string, string>
     */
    private function resolveAfricaSTalkingCredentials(): array
    {
        // Use the provider record the rest of the app uses, but tolerate both
        // credential shapes ("username"/"api_key" from the admin form and
        // "account_id"/"auth_token" from the enum credentialFields) and skip
        // empty/legacy rows: a stray earlier 'africastalking' row with no
        // credentials made campaign calls throw "Africa's Talking is not
        // configured" while the softphone (reading a different path) worked.
        $configs = TelephonyProviderConfig::query()
            ->where('name', 'africastalking')
            ->orderByDesc('updated_at')
            ->get();

        foreach ($configs as $config) {
            $username = $config->credential('username') ?? $config->credential('account_id');
            $apiKey = $config->credential('api_key') ?? $config->credential('auth_token');

            if ($username && $apiKey) {
                return [
                    'username' => (string) $username,
                    'api_key' => (string) $apiKey,
                ];
            }
        }

        throw new \Exception('Africa\'s Talking is not configured for this platform. Ask an administrator to configure it in Admin → Telephony.');
    }

    /**
     * Handle call failure and retry logic
     */
    private function handleCallFailure(\Exception $exception): void
    {
        $this->contact->refresh();

        if ($this->contact->call_attempts < $this->campaign->retry_attempts) {
            // Schedule retry
            $this->contact->update([
                'status' => 'pending',
                'call_attempts' => $this->contact->call_attempts + 1,
            ]);

            // Dispatch retry job with delay
            MakeCampaignCallJob::dispatch($this->campaign, $this->contact)
                ->delay(now()->addMinutes($this->campaign->retry_delay_minutes));

            Log::info("Scheduled retry for contact {$this->contact->id} in {$this->campaign->retry_delay_minutes} minutes");
        } else {
            // Max retries reached, mark as failed
            $this->contact->update([
                'status' => 'failed',
            ]);

            // Update campaign stats
            $this->campaign->increment('total_failed');

            Log::warning("Contact {$this->contact->id} failed after {$this->contact->call_attempts} attempts");
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("MakeCampaignCallJob failed for contact {$this->contact->id}: {$exception->getMessage()}");

        // Jobs that die without reaching the provider branch (or that run on a
        // stale queue worker before error storage existed) left the Call row
        // with a blank reason. Always stamp the last Call row for this contact
        // so Call Logs shows why the attempt failed.
        $call = Call::query()
            ->where('campaign_contact_id', $this->contact->id)
            ->whereIn('status', ['initiated', 'ringing', 'failed'])
            ->latest('id')
            ->first();

        if ($call !== null && ($call->error_message === null || $call->error_message === '')) {
            $call->update([
                'status' => $call->status === 'failed' ? 'failed' : $call->status,
                'error_message' => mb_substr($exception->getMessage(), 0, 500),
            ]);
        }

        $this->handleCallFailure($exception);
    }

    /**
     * Select a message variant for A/B testing
     * Returns variant ID if variants exist and are active, null otherwise
     */
    private function selectMessageVariant(): ?int
    {
        try {
            // Get all active variants for this campaign
            $activeVariants = MessageVariant::where('campaign_id', $this->campaign->id)
                ->where('is_active', true)
                ->get();

            if ($activeVariants->isEmpty()) {
                return null; // No variants, use default message
            }

            // If there's a winner, use it exclusively
            $winner = $activeVariants->where('is_winner', true)->first();
            if ($winner) {
                $winner->recordUsage();

                return $winner->id;
            }

            // A/B testing: randomly select from active variants
            // Weight selection slightly towards better performers if they have data
            $variantId = $this->weightedRandomSelection($activeVariants);

            // Record usage
            $selectedVariant = $activeVariants->find($variantId);
            if ($selectedVariant) {
                $selectedVariant->recordUsage();
            }

            return $variantId;
        } catch (\Exception $e) {
            Log::error('Failed to select message variant', [
                'campaign_id' => $this->campaign->id,
                'error' => $e->getMessage(),
            ]);

            return null; // Fall back to default message
        }
    }

    /**
     * Weighted random selection based on variant performance
     * Better performers get slightly higher chance (80% equal, 20% weighted)
     */
    private function weightedRandomSelection($variants): int
    {
        // If any variant has < 10 sends, do pure random for fair testing
        $minSends = $variants->min('sent_count');
        if ($minSends < 10) {
            return $variants->random()->id;
        }

        // 80% chance: pure random (ensures fair testing)
        if (rand(1, 100) <= 80) {
            return $variants->random()->id;
        }

        // 20% chance: weighted by effectiveness score
        $totalScore = $variants->sum('effectiveness_score');
        if ($totalScore == 0) {
            return $variants->random()->id;
        }

        $random = rand(1, (int) ($totalScore * 100)) / 100;
        $cumulative = 0;

        foreach ($variants as $variant) {
            $cumulative += $variant->effectiveness_score;
            if ($random <= $cumulative) {
                return $variant->id;
            }
        }

        return $variants->last()->id;
    }
}
