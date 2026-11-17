<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\MakeCampaignCallJob;
use App\Models\Call;
use App\Models\Campaign;
use App\Models\CampaignContact;
use Illuminate\Console\Command;

/**
 * Run a campaign's pending contacts synchronously with the CURRENT code.
 *
 * Shared hosts often keep a long-running `queue:work` daemon from before a
 * deploy; that process keeps executing old classes (old provider branch,
 * old credential resolution) and no amount of git pull/optimize:clear fixes
 * it. This command drives the exact same MakeCampaignCallJob via
 * dispatchSync(), so it bypasses the stale worker entirely.
 */
class CampaignRunSync extends Command
{
    protected $signature = 'campaigns:run-sync
        {campaign : Campaign id or name}
        {--limit=10 : Maximum contacts to dial in this run}
        {--from= : Override the campaign from-number}
        {--retry-failed : Also retry contacts marked failed (resets them first)}';

    protected $description = 'Dial pending campaign contacts synchronously with current code (bypasses a stale queue worker)';

    public function handle(): int
    {
        $identifier = (string) $this->argument('campaign');
        $limit = max(1, (int) $this->option('limit'));

        $campaign = Campaign::query()
            ->where('id', is_numeric($identifier) ? (int) $identifier : -1)
            ->orWhere('name', $identifier)
            ->first();

        if ($campaign === null) {
            $this->error('Campaign not found: '.$identifier);
            $this->line('Available: '.Campaign::query()->orderBy('id')->get()->map(fn ($c) => $c->id.'='.$c->name)->implode(', '));

            return self::FAILURE;
        }

        if ($this->option('from')) {
            $campaign->from_number = (string) $this->option('from');
        }

        $this->line('Campaign: #'.$campaign->id.' '.$campaign->name.' ('.$campaign->status.') from '.($campaign->from_number ?: '(none)'));

        if (empty($campaign->from_number)) {
            $this->error('Campaign has no from-number. Pass --from=+254...');

            return self::FAILURE;
        }

        $statuses = ['pending', 'queued', 'in_progress'];
        if ($this->option('retry-failed')) {
            $statuses[] = 'failed';
        }

        $contacts = CampaignContact::query()
            ->where('campaign_id', $campaign->id)
            ->whereIn('status', $statuses)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($contacts->isEmpty()) {
            $this->warn('No contacts to dial. Add --retry-failed to retry contacts already marked failed.');

            return self::SUCCESS;
        }

        // Reset retried/failed contacts so the job does not skip them.
        $contacts->each(function (CampaignContact $contact): void {
            if ($contact->status !== 'pending') {
                $contact->update(['status' => 'pending', 'call_attempts' => 0]);
            }
        });

        $originalStatus = $campaign->status;
        $campaign->status = 'running';
        $campaign->save();

        $this->line('Dispatching '.$contacts->count().' call(s) synchronously — real, billable calls...');

        $rows = [];

        foreach ($contacts as $contact) {
            try {
                MakeCampaignCallJob::dispatchSync($campaign, $contact);
            } catch (\Throwable $e) {
                $rows[] = [$contact->id, $contact->phone_number, 'THREW', mb_substr($e->getMessage(), 0, 90)];

                continue;
            }

            $contact->refresh();
            $call = $contact->call_id ? Call::query()->find($contact->call_id) : Call::query()->where('campaign_contact_id', $contact->id)->latest('id')->first();

            $rows[] = [
                $contact->id,
                $contact->phone_number,
                $call?->status ?? 'no-call',
                mb_substr((string) ($call?->error_message ?? ''), 0, 90),
            ];
        }

        $this->table(['Contact', 'Number', 'Call status', 'Error'], $rows);

        $initiated = collect($rows)->where(2, 'initiated')->count() + collect($rows)->where(2, 'ringing')->count();
        $failed = collect($rows)->where(2, 'failed')->count();
        $total = count($rows);
        $this->info("Placed: {$initiated}  Failed: {$failed}  (total {$total})");

        if ($failed > 0) {
            $this->line('The Error column above is also stored on the Call row (Call Logs).');
        }

        return self::SUCCESS;
    }
}
