<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\MakeCampaignCallJob;
use App\Models\Call;
use App\Models\Campaign;
use App\Models\CampaignContact;
use App\Models\PhoneNumber;
use Illuminate\Console\Command;

/**
 * Place one real campaign call through the campaign pipeline.
 *
 * This uses exactly the same job, Call row fields, provider credentials and
 * answer URL as a running campaign (MakeCampaignCallJob), so it proves the
 * campaign module dials end-to-end without starting a whole campaign queue.
 *
 * The campaign is temporarily set to "running" for the single dispatch and its
 * original status is restored afterwards; the contact row is reused when the
 * number is already in the campaign, otherwise created for this single dial.
 */
class CampaignTestCall extends Command
{
    protected $signature = 'campaigns:test-call
        {campaign : Campaign id or name}
        {number : Destination in E.164, e.g. +254756112633}
        {--from= : Override the campaign from-number (defaults to the campaign\'s)}
        {--keep-running : Leave the campaign status as running afterwards}';

    protected $description = 'Dial one real campaign call (same pipeline as the campaign runner)';

    public function handle(): int
    {
        $identifier = (string) $this->argument('campaign');
        $number = trim((string) $this->argument('number'));

        // Normalize before storing: campaign destinations entered without the
        // international prefix used to become +1 numbers (Call Logs showed
        // +1254756112633 for +254756112633), which AT rejects.
        $phone = app(\App\Services\PhoneValidationService::class)
            ->sanitize($number, (string) config('telephony.default_country_code', '+254'));

        if (! $phone['success']) {
            $this->error('Invalid destination number: '.$phone['error']);

            return self::FAILURE;
        }

        $number = (string) $phone['formatted'];

        $campaign = Campaign::query()
            ->where('id', is_numeric($identifier) ? (int) $identifier : -1)
            ->orWhere('name', $identifier)
            ->first();

        if ($campaign === null) {
            $this->error('Campaign not found: '.$identifier);
            $this->line('Available campaigns: '.Campaign::query()->orderBy('id')->pluck('name', 'id')->map(fn ($n, $id) => $id.'='.$n)->implode(', '));

            return self::FAILURE;
        }

        $from = (string) ($this->option('from') ?? $campaign->from_number ?? '');
        if ($from !== '') {
            $campaign->from_number = $from;
        }

        $this->line('Campaign: #'.$campaign->id.' '.$campaign->name.' (status: '.$campaign->status.')');
        $this->line('From: '.($campaign->from_number ?: '(none)').'  →  To: '.$number);

        if (empty($campaign->from_number)) {
            $this->error('This campaign has no from-number. Assign an AT voice number to it first (or pass --from=+254...).');

            return self::FAILURE;
        }

        $this->line('Provider: '.(string) config('telephony.default', 'africastalking'));

        $contact = CampaignContact::query()
            ->where('campaign_id', $campaign->id)
            ->where('phone_number', $number)
            ->first();

        if ($contact === null) {
            $contact = CampaignContact::query()->create([
                'campaign_id' => $campaign->id,
                'phone_number' => $number,
                'status' => 'pending',
                'call_attempts' => 0,
            ]);
            $this->line('Created campaign contact #'.$contact->id.' for '.$number);
        } else {
            $this->line('Reusing campaign contact #'.$contact->id.' (status: '.$contact->status.')');
            $contact->update(['status' => 'pending']);
        }

        $originalStatus = $campaign->status;
        $campaign->status = 'running';
        $campaign->save();

        $this->line('Dispatching MakeCampaignCallJob synchronously — this places a REAL, billable call...');

        try {
            MakeCampaignCallJob::dispatchSync($campaign, $contact);
        } finally {
            if (! $this->option('keep-running') && $originalStatus !== 'running') {
                $campaign->status = $originalStatus;
                $campaign->save();
                $this->line('Campaign status restored to '.$originalStatus.'.');
            }
        }

        $contact->refresh();
        $call = $contact->call_id ? Call::query()->find($contact->call_id) : null;

        $this->newLine();

        if ($call === null) {
            $this->warn('No Call row was created. Contact status: '.$contact->status);

            return self::FAILURE;
        }

        $this->table(
            ['Field', 'Value'],
            [
                ['Call id', $call->id],
                ['Provider', $call->provider],
                ['From', $call->from_number],
                ['To', $call->to_number],
                ['Status', $call->status],
                ['Provider call id', $call->twilio_call_sid ?? $call->provider_call_sid ?? '(none yet)'],
                ['Error', $call->error_message ?? '(none)'],
                ['Contact status', $contact->status],
            ]
        );

        if ($call->status === 'initiated' || $call->status === 'ringing') {
            $this->info('Call placed — the destination should ring now. Answer it to hear the campaign TTS.');
            $this->line('Follow-ups: php artisan telephony:cleanup-stuck --minutes=0 (clears stale rows); check storage/logs/laravel.log for "AT session callback".');
        } else {
            $this->warn('Call did not reach "initiated" — see Error/status above and storage/logs/laravel.log.');
        }

        return self::SUCCESS;
    }
}
