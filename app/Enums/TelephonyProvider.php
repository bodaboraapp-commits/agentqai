<?php

declare(strict_types=1);

namespace App\Enums;

enum TelephonyProvider: string
{
    case TWILIO = 'twilio';
    case PLIVO = 'plivo';
    case AFRICASTALKING = 'africastalking';

    public function label(): string
    {
        return match ($this) {
            self::TWILIO => 'Twilio',
            self::PLIVO => 'Plivo',
            self::AFRICASTALKING => 'Africa\'s Talking',
        };
    }

    /**
     * Get all capabilities for this provider.
     */
    public function capabilities(): array
    {
        return match ($this) {
            self::TWILIO => ['sms', 'mms', 'voice', 'webrtc', 'streaming', 'phone_numbers', 'sip_trunking'],
            self::PLIVO => ['sms', 'mms', 'voice', 'webrtc', 'streaming', 'phone_numbers'],
            // AT: voice (outbound), WebRTC browser client and SMS. No MMS, no
            // media streaming (AI agents), no self-serve number purchase and
            // no SIP-trunk API (their SIP is a PBX trunk provisioned by
            // support, IP-whitelisted, outside this app).
            self::AFRICASTALKING => ['sms', 'voice', 'webrtc'],
        };
    }

    /**
     * Get the credential field mapping for this provider.
     */
    public function credentialFields(): array
    {
        return match ($this) {
            self::TWILIO => [
                'account_id' => 'account_sid',
                'auth_token' => 'auth_token',
            ],
            self::PLIVO => [
                'account_id' => 'auth_id',
                'auth_token' => 'auth_token',
            ],
            // AT authenticates with the app username (?username=) and the
            // API key (apiKey header); the generic credential shape stores
            // them as these two keys.
            self::AFRICASTALKING => [
                'account_id' => 'username',
                'auth_token' => 'api_key',
            ],
        };
    }

    /**
     * Whether this provider may be selected at install / in the provider
     * chooser. Africa's Talking is built in phases and stays hidden until
     * its integration is complete and verified.
     */
    public function isSelectable(): bool
    {
        return match ($this) {
            self::AFRICASTALKING => (bool) config('africastalking.enabled', false),
            default => true,
        };
    }

    /**
     * Providers an admin may choose right now.
     *
     * @return list<self>
     */
    public static function selectable(): array
    {
        return array_values(array_filter(self::cases(), fn (self $provider): bool => $provider->isSelectable()));
    }

    /**
     * Twilio-only features hidden from the UI when locked to this provider.
     */
    public function hiddenFeatures(): array
    {
        return config("telephony.hidden_features.{$this->value}", []);
    }

    /**
     * Whether the given feature is hidden for this provider.
     */
    public function hides(string $feature): bool
    {
        return in_array($feature, $this->hiddenFeatures(), true);
    }
}
