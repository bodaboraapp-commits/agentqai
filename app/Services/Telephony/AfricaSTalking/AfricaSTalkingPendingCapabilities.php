<?php

declare(strict_types=1);

namespace App\Services\Telephony\AfricaSTalking;

use App\Contracts\Telephony\AudioStreamProviderInterface;
use App\Contracts\Telephony\PhoneNumberProviderInterface;
use App\Contracts\Telephony\PricingProviderInterface;
use App\Contracts\Telephony\SmsProviderInterface;
use App\Contracts\Telephony\VerificationProviderInterface;
use App\Contracts\Telephony\WebRTCTokenProviderInterface;
use App\Models\UserKycVerification;
use LogicException;

/**
 * Capabilities Africa's Talking is not wired for yet.
 *
 * The provider is added in phases: the voice core (call XML, outbound calls,
 * callbacks) is phase 1; SMS, browser WebRTC, KYC OTP, pricing sync and number
 * provisioning are phase 2. This single class keeps the registry complete —
 * every capability the shared controllers can ask for exists for this
 * provider — while every unimplemented call fails loudly instead of silently
 * falling through to a Twilio implementation.
 *
 * Each method throws LogicException with the phase-2 reason. The provider is
 * not selectable while config('africastalking.enabled') is false, so no
 * installation can reach these paths yet.
 */
class AfricaSTalkingPendingCapabilities implements
    SmsProviderInterface,
    WebRTCTokenProviderInterface,
    VerificationProviderInterface,
    PricingProviderInterface,
    PhoneNumberProviderInterface,
    AudioStreamProviderInterface
{
    public function initialize(array $credentials): static
    {
        return $this;
    }

    private function unimplemented(string $capability): never
    {
        throw new LogicException("Africa's Talking {$capability} support is not implemented yet (phase 2).");
    }

    public function getName(): string
    {
        return 'africastalking';
    }

    // ── SMS ────────────────────────────────────────────────────────────────

    public function send(string $from, string $to, string $message, array $options = []): array
    {
        $this->unimplemented('SMS sending');
    }

    public function sendBatch(string $from, array $recipients, array $options = []): array
    {
        $this->unimplemented('SMS sending');
    }

    public function getStatus(string $messageId): array
    {
        $this->unimplemented('SMS status');
    }

    public function validateWebhook(array $payload, string $signature): bool
    {
        return true;
    }

    public function parseIncoming(array $payload): array
    {
        $this->unimplemented('incoming SMS parsing');
    }

    public function supportsMMS(): bool
    {
        return false;
    }

    // ── Browser WebRTC ─────────────────────────────────────────────────────

    public function generateToken(string $identity, array $options = []): array
    {
        $this->unimplemented('browser WebRTC tokens');
    }

    // ── KYC phone verification ─────────────────────────────────────────────

    public function start(UserKycVerification $kyc): void
    {
        $this->unimplemented('KYC phone verification');
    }

    public function check(UserKycVerification $kyc, string $code): array
    {
        $this->unimplemented('KYC phone verification');
    }

    // ── Pricing ────────────────────────────────────────────────────────────

    public function fetchPricing(string $serviceType, ?string $page = null): array
    {
        $this->unimplemented('price sync (AT has no pricing API)');
    }

    // ── Phone numbers ──────────────────────────────────────────────────────

    public function searchAvailableNumbers(string $countryCode, array $options = []): array
    {
        $this->unimplemented('number search (AT numbers are provisioned via the AT dashboard)');
    }

    public function purchaseNumber(string $phoneNumber, array $options = []): array
    {
        $this->unimplemented('number purchase (AT numbers are provisioned via the AT dashboard)');
    }

    public function releaseNumber(string $numberSid): bool
    {
        $this->unimplemented('number release');
    }

    public function listNumbers(): array
    {
        $this->unimplemented('number listing');
    }

    public function getOwnedNumber(string $number): ?array
    {
        $this->unimplemented('number lookup');
    }

    public function updateNumberConfig(string $numberSid, array $config): bool
    {
        $this->unimplemented('number configuration (set the callback URL in the AT dashboard)');
    }

    // ── Bidirectional media streaming (AI voice agents) ────────────────────

    public function parseMessage(string $rawMessage): array
    {
        $this->unimplemented('media streaming');
    }

    public function buildAudioMessage(string $audioPayload, string $streamId): string
    {
        $this->unimplemented('media streaming');
    }

    public function buildClearMessage(string $streamId): string
    {
        $this->unimplemented('media streaming');
    }

    public function getAudioFormat(): array
    {
        $this->unimplemented('media streaming');
    }
}
