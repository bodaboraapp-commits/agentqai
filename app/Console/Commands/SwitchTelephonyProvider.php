<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Telephony\SwitchTelephonyProviderAction;
use App\Enums\TelephonyProvider;
use Illuminate\Console\Command;

/**
 * Switch the active telephony provider from the terminal — the same
 * non-destructive switch as Admin → Telephony, including --force for stuck
 * calls. Useful when an admin cannot reach the UI (or wants no browser
 * round-trip).
 */
class SwitchTelephonyProvider extends Command
{
    protected $signature = 'telephony:switch
        {provider : twilio, plivo or africastalking}
        {--force : Cancel calls still in progress and pause running campaigns, then switch}';

    protected $description = 'Switch the active telephony provider without deleting either configuration (--force cancels stuck calls first)';

    public function handle(SwitchTelephonyProviderAction $action): int
    {
        $value = strtolower(trim((string) $this->argument('provider')));
        $provider = TelephonyProvider::tryFrom($value);

        if ($provider === null) {
            $this->error("Unknown provider [{$value}]. Use one of: ".implode(', ', array_column(TelephonyProvider::cases(), 'value')));

            return self::FAILURE;
        }

        try {
            $action->execute($provider, auth()->user(), (bool) $this->option('force'));
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            $this->line('Tip: use --force to cancel calls still in progress and switch anyway.');

            return self::FAILURE;
        }

        $this->info("This installation is now using {$provider->label()}.");

        return self::SUCCESS;
    }
}
