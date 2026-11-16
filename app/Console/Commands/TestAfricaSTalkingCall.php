<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TelephonyProviderConfig;
use App\Services\Telephony\AfricaSTalking\AfricaSTalkingVoiceProvider;
use Illuminate\Console\Command;

/**
 * Place a real outbound call through the AT Voice API to prove the AT account
 * can dial a PSTN number, independently of the browser WebRTC client.
 *
 * This is a diagnostic: it rings the destination from the AT number and, when
 * answered, AT fetches the number callback. No matching Call row exists, so the
 * caller will hear the short "could not match this call" message and the call
 * ends — that still proves the outbound leg and AT number work. Hang up as soon
 * as the phone rings.
 */
class TestAfricaSTalkingCall extends Command
{
    protected $signature = 'telephony:at-test-call
        {number : Destination in E.164, e.g. +2547XXXXXXXX}
        {--from= : AT number to call from (defaults to the configured voice number)}';

    protected $description = 'Diagnostic: place a real outbound AT Voice call to check that the AT account can dial out';

    public function handle(AfricaSTalkingVoiceProvider $voice): int
    {
        $config = TelephonyProviderConfig::where('name', 'africastalking')->first();

        if ($config === null) {
            $this->error('Africa\'s Talking is not configured. Run telephony:africastalking first.');

            return self::FAILURE;
        }

        $from = trim((string) ($this->option('from') ?? $config->credential('voice_number') ?? ''));
        $from = $from !== '' ? $from : (string) config('africastalking.voice.default_number');

        if ($from === '') {
            $this->error('No AT number to call from. Pass --from=+2547XXXXXXXX.');

            return self::FAILURE;
        }

        $to = trim((string) $this->argument('number'));

        $this->warn("Placing a real AT Voice call from {$from} to {$to}. It costs money; hang up as soon as it rings.");

        $result = $voice->initialize([
            'username' => (string) $config->credential('username'),
            'api_key' => (string) $config->credential('api_key'),
        ])->makeCall($to, $from, '', [
            'clientRequestId' => 'at-test-'.now()->timestamp,
        ]);

        if (! $result['success']) {
            $this->error('AT rejected the outbound call: '.($result['error'] ?? 'unknown error'));
            $this->line('If the error mentions the number or the application, check AT dashboard → Voice that the number is voice-enabled and outbound calling is allowed.');

            return self::FAILURE;
        }

        $this->info('AT accepted the call (status: '.($result['status'] ?? 'queued').').');
        $this->line('If the destination did not ring, AT itself is not dialling out for this account/number — that is an AT-side configuration issue, not the browser client.');
        $this->line('If it DID ring, AT outbound works and the softphone WebRTC leg is the failing part.');

        return self::SUCCESS;
    }
}
