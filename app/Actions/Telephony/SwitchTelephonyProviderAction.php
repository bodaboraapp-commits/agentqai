<?php

declare(strict_types=1);

namespace App\Actions\Telephony;

use App\Enums\TelephonyProvider;
use App\Models\Call;
use App\Models\Campaign;
use App\Models\TelephonyProviderConfig;
use App\Models\TwilioCredential;
use App\Models\TwilioGlobalConfig;
use App\Models\User;
use App\Services\Telephony\TelephonyLock;
use App\Services\Telephony\TelephonyManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Move the active telephony provider without destroying anything.
 *
 * This is intentionally NOT ResetTelephonyProviderAction: a reset wipes the
 * current provider's in-app configuration and opens the lock. A switch keeps
 * every provider's credentials/settings in place (each provider has its own
 * telephony_providers row) and just moves which one is locked, so an admin can
 * go Twilio → Africa's Talking → Twilio again without re-entering either set
 * of credentials. Provider-specific data (numbers, calls) is already tagged
 * with a provider column and stays untouched.
 *
 * Guards: the target must be selectable and configured (AT requires a verified
 * account), and no campaigns/calls may be in flight unless forced — the same
 * reason the destructive reset refuses mid-flight work, since queued calls
 * would otherwise be placed through the wrong provider.
 */
final class SwitchTelephonyProviderAction
{
    /** Campaign statuses that mean "this campaign is about to place calls". */
    private const BLOCKING_CAMPAIGN_STATUSES = ['running', 'scheduled'];

    /** Call statuses that mean "this call has not finished". */
    private const BLOCKING_CALL_STATUSES = ['initiated', 'queued', 'ringing', 'in-progress'];

    public function __construct(private readonly TelephonyLock $lock) {}

    public function execute(TelephonyProvider $target, ?User $actor = null, bool $force = false): TelephonyProviderConfig
    {
        $current = $this->lock->current();

        if ($current === null) {
            throw new RuntimeException('No telephony provider is locked yet. Finish the telephony setup first.');
        }

        if ($current === $target) {
            return TelephonyProviderConfig::forProvider($target);
        }

        if (! $target->isSelectable()) {
            throw new RuntimeException($target->label().' is not enabled on this installation.');
        }

        if (! $this->isConfigured($target)) {
            throw new RuntimeException($this->notConfiguredMessage($target));
        }

        if ($force) {
            $this->cancelInFlight();
        } else {
            $this->assertNoWorkInFlight();
        }

        $config = $this->lock->switchTo($target, $actor?->id);

        app(TelephonyManager::class)->flush();

        Log::info('Telephony provider switched', [
            'from' => $current->value,
            'to' => $target->value,
            'by_user_id' => $actor?->id,
            'forced' => $force,
        ]);

        return $config;
    }

    private function isConfigured(TelephonyProvider $provider): bool
    {
        $config = TelephonyProviderConfig::where('name', $provider->value)->first();

        return match ($provider) {
            TelephonyProvider::AFRICASTALKING => $config?->verified_at !== null
                && $config->credential('username') !== null
                && $config->credential('api_key') !== null,
            TelephonyProvider::PLIVO => $config?->credential('auth_id') !== null
                && $config?->credential('auth_token') !== null,
            // Twilio's credentials live in its own tables (per-user credentials
            // and the admin global configuration), not in telephony_providers,
            // and every existing installation was Twilio-locked before this
            // feature existed — refusing the switch back would strand them.
            TelephonyProvider::TWILIO => TwilioGlobalConfig::query()->exists()
                || TwilioCredential::query()->exists()
                || true, // Twilio always allowed: existing installs were Twilio-locked.
        };
    }

    private function notConfiguredMessage(TelephonyProvider $provider): string
    {
        return match ($provider) {
            TelephonyProvider::AFRICASTALKING => 'Africa\'s Talking is not ready yet: save its username and API key, then verify them before switching.',
            TelephonyProvider::PLIVO => 'Plivo is not ready yet: save and verify its credentials before switching.',
            default => "{$provider->label()} is not configured.",
        };
    }

    private function assertNoWorkInFlight(): void
    {
        $campaigns = Campaign::query()
            ->whereIn('status', self::BLOCKING_CAMPAIGN_STATUSES)
            ->count();

        if ($campaigns > 0) {
            throw new RuntimeException(
                "There {$this->plural($campaigns, 'is a campaign', 'are campaigns')} running or scheduled. "
                .'Pause them first, or tick "Force switch" to pause them and switch anyway.'
            );
        }

        $calls = Call::query()->whereIn('status', self::BLOCKING_CALL_STATUSES)->count();

        if ($calls > 0) {
            throw new RuntimeException(
                "There {$this->plural($calls, 'is a call', 'are calls')} still in progress ({$this->statusBreakdown()}), "
                .'usually calls left open because their provider callback never arrived. '
                .'Tick "Force switch" to cancel them locally and switch anyway.'
            );
        }
    }

    /**
     * Force path: nothing that was placed through the old provider may still
     * look like it is running after the switch, or queued jobs could place it
     * through the new account. Calls are cancelled locally (their provider
     * callback may never come), and campaigns that would place more calls are
     * paused/drafted — the same end state ResetTelephonyProviderAction forces.
     */
    private function cancelInFlight(): void
    {
        $campaignsPaused = DB::table('campaigns')
            ->whereIn('status', ['running'])
            ->update(['status' => 'paused', 'updated_at' => now()]);

        $campaignsDrafted = DB::table('campaigns')
            ->whereIn('status', ['scheduled'])
            ->update(['status' => 'draft', 'updated_at' => now()]);

        $callsCancelled = DB::table('calls')
            ->whereIn('status', self::BLOCKING_CALL_STATUSES)
            ->update([
                'status' => 'canceled',
                'ended_at' => now(),
                'updated_at' => now(),
            ]);

        Log::warning('Telephony provider switch forced: in-flight work cancelled.', [
            'campaigns_paused' => $campaignsPaused,
            'campaigns_drafted' => $campaignsDrafted,
            'calls_cancelled' => $callsCancelled,
        ]);
    }

    private function statusBreakdown(): string
    {
        $counts = Call::query()
            ->whereIn('status', self::BLOCKING_CALL_STATUSES)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn (int|string $count, string $status): string => "{$count} {$status}")
            ->values()
            ->all();

        return $counts === [] ? 'none' : implode(', ', $counts);
    }

    private function plural(int $count, string $one, string $many): string
    {
        return $count === 1 ? $one : $many;
    }
}
