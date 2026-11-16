<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Call;
use App\Models\CallQueueEntry;
use Illuminate\Console\Command;

/**
 * Clear work the current provider can no longer finish.
 *
 * Provider callbacks occasionally never arrive (a misconfigured callback URL,
 * a call aborted provider-side, a provider switch), which leaves calls stuck in
 * initiated/ringing/in-progress and queue entries stuck as "waiting". Those
 * rows make the softphone show phantom waiting callers and block provider
 * switching with "calls still in progress". This command marks them ended.
 *
 * Non-destructive to history: rows are kept, only their status is changed.
 */
class CleanupStuckTelephonyWork extends Command
{
    protected $signature = 'telephony:cleanup-stuck
        {--minutes=15 : Only touch calls/queue entries older than this many minutes}';

    protected $description = 'Cancel calls stuck in progress and abandon stale queue entries (keeps history)';

    /** @var list<string> */
    private const STUCK_CALL_STATUSES = ['initiated', 'queued', 'ringing', 'in-progress'];

    public function handle(): int
    {
        $minutes = max(0, (int) $this->option('minutes'));
        $cutoff = now()->subMinutes($minutes);

        $calls = Call::query()
            ->whereIn('status', self::STUCK_CALL_STATUSES)
            ->where('updated_at', '<=', $cutoff)
            ->update([
                'status' => 'canceled',
                'ended_at' => now(),
                'updated_at' => now(),
            ]);

        $queue = CallQueueEntry::query()
            ->whereIn('status', ['waiting', 'connecting'])
            ->where('updated_at', '<=', $cutoff)
            ->update([
                'status' => 'abandoned',
                'updated_at' => now(),
            ]);

        $this->info("Cancelled {$calls} stuck call(s) older than {$minutes} minute(s).");
        $this->info("Abandoned {$queue} stale queue entrie(s).");

        if ($calls === 0 && $queue === 0) {
            $this->line('Nothing was stuck.');
        }

        return self::SUCCESS;
    }
}
