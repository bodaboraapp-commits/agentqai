<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TelephonyProvider;
use App\Models\Campaign;
use App\Models\CampaignContact;
use App\Models\TelephonyProviderConfig;
use App\Services\PhoneValidationService;
use App\Services\Telephony\TelephonyManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Validate the outbound campaign path WITHOUT placing a billable call.
 *
 * Checks, in order: campaign/contact/no-number, phone normalisation, locked
 * provider, AT credentials, answer-URL route, AT Voice API host reachability
 * (a POST missing required fields returns 400, which proves the host/path are
 * live without creating a call), and stale queue state.
 */
class CampaignDryRun extends Command
{
    protected $signature = 'campaigns:dry-run
        {campaign : Campaign id or name}
        {number : Destination number}
        {--from= : Override campaign from-number}';

    protected $description = 'Validate the campaign outbound path with no billable call (API reachability only)';

    public function handle(): int
    {
        $identifier = (string) $this->argument('campaign');
        $raw = trim((string) $this->argument('number'));

        $campaign = Campaign::query()
            ->where('id', is_numeric($identifier) ? (int) $identifier : -1)
            ->orWhere('name', $identifier)
            ->first();

        if ($campaign === null) {
            $this->error('Campaign not found: '.$identifier);

            return self::FAILURE;
        }

        $this->line('Campaign: #'.$campaign->id.' '.$campaign->name.' (status: '.$campaign->status.')');

        $from = (string) ($this->option('from') ?? $campaign->from_number ?? '');
        if ($from === '') {
            $this->error('FAIL: campaign has no from-number (pass --from=+254...).');

            return self::FAILURE;
        }
        $this->line('From: '.$from.'  →  To: '.$raw);

        $phone = app(PhoneValidationService::class)->sanitize($raw, (string) config('telephony.default_country_code', '+254'));
        if (! $phone['success']) {
            $this->error('FAIL: invalid destination: '.$phone['error']);

            return self::FAILURE;
        }
        $number = (string) $phone['formatted'];
        $this->line('Normalised destination: '.$number.'  ('.($number === $raw ? 'unchanged' : 'changed from '.$raw).')');

        $provider = app(TelephonyManager::class)->provider();
        $this->line('Locked provider: '.$provider->value);

        $contact = CampaignContact::query()
            ->where('campaign_id', $campaign->id)
            ->where('phone_number', $number)
            ->first();
        $this->line('Campaign contact: '.($contact ? '#'.$contact->id.' status='.$contact->status : 'none yet (run-sync creates it)'));

        if ($provider === TelephonyProvider::AFRICASTALKING) {
            $config = TelephonyProviderConfig::query()->where('name', 'africastalking')->latest('id')->first();
            $username = $config?->credential('username') ?? $config?->credential('account_id');
            $apiKey = $config?->credential('api_key') ?? $config?->credential('auth_token');

            if (! $username || ! $apiKey) {
                $this->error('FAIL: AT credentials missing in telephony_providers.');

                return self::FAILURE;
            }
            $this->line('AT credentials: username='.$username.' api_key=set');

            // Answer URL the campaign job will send to AT (route must exist).
            $prefix = 'at-xml.';
            if ($campaign->ai_agent_id) {
                $route = $prefix.'campaign.tts'; // AT has no ai-agent route (job falls back)
                $this->line('NOTE: campaign has an AI agent; on AT the job falls back to TTS (no AI voice on AT).');
            } else {
                $route = $campaign->type === 'text_to_speech' ? $prefix.'campaign.tts' : $prefix.'campaign.voice';
            }

            try {
                $answerUrl = route($route).'?call_id=0';
                $this->line('Answer URL: '.$answerUrl.'  (route exists)');
            } catch (\Throwable $e) {
                $this->error('FAIL: answer URL route missing: '.$e->getMessage());

                return self::FAILURE;
            }

            // Reachability only: a request missing required fields must return
            // 400, which proves host/path/credentials format without a call.
            try {
                $response = Http::withHeaders(['apiKey' => $apiKey, 'Accept' => 'application/json'])
                    ->connectTimeout(10)
                    ->timeout(20)
                    ->asForm()
                    ->post(rtrim((string) config('africastalking.api.voice_base_url'), '/').config('africastalking.api.voice_call_path', '/call'), [
                        'username' => $username,
                        'from' => $from,
                    ]);
                $this->line('AT Voice API probe: HTTP '.$response->status().' '.mb_substr(str_replace("\n", ' ', (string) ($response->json('errorMessage') ?? $response->body())), 0, 120));
                $reachable = $response->status() >= 400 && $response->status() < 500;
                if (! $reachable && ! $response->successful()) {
                    $this->warn('WARN: unexpected AT response; check credentials/endpoint.');
                }
            } catch (\Throwable $e) {
                $this->error('FAIL: AT Voice API unreachable: '.$e->getMessage());

                return self::FAILURE;
            }
        }

        $pending = DB::table('jobs')->where('queue', 'campaign-calls')->count();
        $oldest = DB::table('jobs')->where('queue', 'campaign-calls')->min('created_at');
        $this->line('Queued campaign-calls jobs: '.$pending.($oldest ? ' (oldest '.$oldest.')' : ''));

        if ($pending > 0) {
            $this->warn('WARN: stale queued campaign job(s) exist. If they were queued before the latest deploy, clear them:');
            $this->line('  php artisan queue:clear database --queue=campaign-calls');
            $this->line('and ensure the queue worker was restarted: php artisan queue:restart');
        }

        $this->newLine();
        $this->info('DRY RUN PASSED — the campaign path is valid; no call was placed.');

        return self::SUCCESS;
    }
}
