<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PhoneNumber;
use App\Models\TelephonyProviderConfig;
use App\Services\Telephony\AfricaSTalking\AfricaSTalkingVoiceProvider;
use Illuminate\Console\Command;

/**
 * Store + verify Africa's Talking credentials and print the callback URLs the
 * admin must paste into the AT dashboard.
 *
 * The provider stays unselectable until AFRICASTALKING_ENABLED=true, and this
 * installation must be locked to it (a fresh install, or Telephony → Reset)
 * for calls to actually flow through it.
 */
class ConfigureAfricaSTalking extends Command
{
    protected $signature = 'telephony:africastalking
        {--username= : Africa\'s Talking app username}
        {--api-key= : Africa\'s Talking API key (prompted securely when omitted)}
        {--number= : Voice number in E.164 to register for this installation}';

    protected $description = 'Configure and verify Africa\'s Talking, optionally register a voice number, and print the AT callback URLs';

    public function handle(AfricaSTalkingVoiceProvider $voice): int
    {
        $username = trim((string) ($this->option('username') ?? ''));
        if ($username === '') {
            $username = trim((string) $this->ask('Africa\'s Talking username (app username)'));
        }

        $apiKey = trim((string) ($this->option('api-key') ?? ''));
        if ($apiKey === '') {
            $apiKey = trim((string) $this->secret('Africa\'s Talking API key'));
        }

        if ($username === '' || $apiKey === '') {
            $this->error('Both username and API key are required.');

            return self::FAILURE;
        }

        $verification = $voice->verifyCredentials(['username' => $username, 'api_key' => $apiKey]);

        if (! $verification['valid']) {
            $this->error((string) ($verification['error'] ?? 'Africa\'s Talking rejected the credentials.'));

            return self::FAILURE;
        }

        $config = TelephonyProviderConfig::firstOrCreate(['name' => 'africastalking']);
        $config->fill(['credentials' => ['username' => $username, 'api_key' => $apiKey]]);
        $config->verified_at = now();
        $config->save();

        $this->info('Credentials verified and stored: '.$verification['account_name']);

        $number = trim((string) ($this->option('number') ?? ''));
        if ($number !== '') {
            $digits = preg_replace('/[^0-9+]/', '', $number) ?? '';
            if ($digits !== '' && ! str_starts_with($digits, '+')) {
                $digits = '+'.$digits;
            }

            PhoneNumber::updateOrCreate(
                ['number' => $digits],
                [
                    'formatted_number' => $digits,
                    'friendly_name' => 'Africa\'s Talking '.$digits,
                    'status' => 'available',
                    'provider' => 'africastalking',
                    'source' => 'manual',
                    'capabilities' => ['voice' => true, 'sms' => true, 'mms' => false],
                    'monthly_cost' => 0,
                ],
            );

            $this->info('Registered voice number: '.$digits);
        }

        $this->newLine();
        $this->info('Paste these into the Africa\'s Talking dashboard (Voice → Phone Numbers → your number → Actions):');
        $this->line('  Callback URL: '.route('at-xml.session'));
        $this->line('  Events URL:   '.route('webhooks.africastalking.events'));
        $this->newLine();
        $this->warn('The provider is hidden until AFRICASTALKING_ENABLED=true. This installation must also be locked to Africa\'s Talking (fresh install or Admin → Telephony → Reset) for calls to flow through it.');

        return self::SUCCESS;
    }
}
