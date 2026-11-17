<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Campaign;
use App\Models\CampaignContact;
use App\Services\PhoneValidationService;
use Illuminate\Console\Command;

/**
 * Repair campaign contacts whose phone numbers were stored in the wrong
 * international format.
 *
 * Before telephony.default_country_code existed, numbers without an explicit
 * '+' were normalised with a US +1 default: +254756112633 became
 * +1254756112633. Existing rows keep that value, so starting the campaign from
 * the UI keeps dialling the bad number even after the sanitizer was fixed.
 * This command re-sanitises every campaign contact (optionally one campaign)
 * and reports exactly what would change.
 */
class CampaignNormalizeNumbers extends Command
{
    protected $signature = 'campaigns:normalize-numbers
        {campaign? : Campaign id or name (defaults to all campaigns)}
        {--dry-run : Show changes without writing them}';

    protected $description = 'Re-normalise campaign contact phone numbers to the installation country code (e.g. +254…)';

    public function handle(): int
    {
        $identifier = $this->argument('campaign');
        $dryRun = (bool) $this->option('dry-run');

        $query = CampaignContact::query()->orderBy('id');

        if (is_string($identifier) && $identifier !== '') {
            $campaign = Campaign::query()
                ->where('id', is_numeric($identifier) ? (int) $identifier : -1)
                ->orWhere('name', $identifier)
                ->first();

            if ($campaign === null) {
                $this->error('Campaign not found: '.$identifier);

                return self::FAILURE;
            }

            $this->line('Campaign: #'.$campaign->id.' '.$campaign->name);
            $query->where('campaign_id', $campaign->id);
        }

        $validator = app(PhoneValidationService::class);
        $defaultCountry = (string) config('telephony.default_country_code', '+254');

        $this->line('Default country code: '.$defaultCountry.'  (dry-run: '.($dryRun ? 'yes' : 'no').')');

        $rows = [];
        $changed = 0;
        $failed = 0;

        foreach ($query->cursor() as $contact) {
            $result = $validator->sanitize((string) $contact->phone_number, $defaultCountry);

            if (! $result['success']) {
                $failed++;
                $rows[] = [$contact->id, $contact->campaign_id, $contact->phone_number, 'INVALID: '.$result['error']];

                continue;
            }

            if ($result['formatted'] === $contact->phone_number) {
                continue;
            }

            $rows[] = [$contact->id, $contact->campaign_id, $contact->phone_number, $result['formatted']];

            if (! $dryRun) {
                $contact->update(['phone_number' => $result['formatted']]);
            }

            $changed++;
        }

        if ($rows !== []) {
            $this->table(['Contact', 'Campaign', 'Old number', 'New number'], array_slice($rows, 0, 100));
        }

        $this->info(($dryRun ? 'Would fix: ' : 'Fixed: ').$changed.' contact(s)'.($failed > 0 ? ', invalid: '.$failed : '').'.');

        if ($changed === 0) {
            $this->line('No campaign contact numbers needed normalising.');
        } elseif ($dryRun) {
            $this->line('Re-run without --dry-run to apply.');
        }

        return self::SUCCESS;
    }
}
