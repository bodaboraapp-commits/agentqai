<?php

declare(strict_types=1);

namespace App\Services\Twilio;

/**
 * Builds Twilio Voice Dialing Permissions bulk-update payloads.
 *
 * Kept separate from the controller so the delta/chunking logic can be tested
 * without a Twilio account.
 *
 * The Twilio API (and the PHP SDK: `BulkCountryUpdateList::create(string
 * $updateRequest)`) expects ONE string containing a JSON array of update
 * objects, for example:
 *
 *   [{"iso_code":"KE","low_risk_numbers_enabled":"true",
 *     "high_risk_special_numbers_enabled":"false",
 *     "high_risk_tollfraud_numbers_enabled":"false"}]
 *
 * The SDK URL-encodes it as the `UpdateRequest` form field itself.
 */
final class DialingPermissionsUpdater
{
    /** Updates per API call; keeps each request comfortably small. */
    public const CHUNK_SIZE = 50;

    /**
     * Whether a country currently accepts calls (any category enabled).
     */
    public static function isEnabled(object $country): bool
    {
        return (bool) $country->lowRiskNumbersEnabled
            || (bool) $country->highRiskSpecialNumbersEnabled
            || (bool) $country->highRiskTollfraudNumbersEnabled;
    }

    /**
     * Compare the live Twilio country list with the desired set and return
     * only the countries that actually change.
     *
     * Enabling a country turns on low-risk numbers only: the high-risk
     * categories need special approval from Twilio and asking for them would
     * make the whole batch fail for most accounts. Disabling turns everything
     * off, which is the safe direction.
     *
     * @param  iterable<object>  $countries  Twilio CountryInstance objects
     * @param  list<string>  $wantedCountries  ISO-3166 alpha-2 codes to keep enabled
     * @return array{updates: list<array<string, string>>, unknown: list<string>}
     */
    public static function buildUpdates(iterable $countries, array $wantedCountries): array
    {
        $wanted = [];
        foreach ($wantedCountries as $code) {
            $code = strtoupper(trim((string) $code));
            if ($code !== '') {
                $wanted[$code] = true;
            }
        }

        $known = [];
        $updates = [];

        foreach ($countries as $country) {
            $code = strtoupper((string) $country->isoCode);
            $known[$code] = true;

            $low = (bool) $country->lowRiskNumbersEnabled;
            $special = (bool) $country->highRiskSpecialNumbersEnabled;
            $tollfraud = (bool) $country->highRiskTollfraudNumbersEnabled;

            $shouldEnable = isset($wanted[$code]);

            if ($shouldEnable) {
                $wantedState = ['low' => true, 'special' => $special, 'tollfraud' => $tollfraud];
            } else {
                $wantedState = ['low' => false, 'special' => false, 'tollfraud' => false];
            }

            if ($wantedState['low'] === $low
                && $wantedState['special'] === $special
                && $wantedState['tollfraud'] === $tollfraud) {
                continue; // already in the desired state
            }

            $updates[] = [
                'iso_code' => $code,
                'low_risk_numbers_enabled' => $wantedState['low'] ? 'true' : 'false',
                'high_risk_special_numbers_enabled' => $wantedState['special'] ? 'true' : 'false',
                'high_risk_tollfraud_numbers_enabled' => $wantedState['tollfraud'] ? 'true' : 'false',
            ];
        }

        $unknown = array_values(array_diff(array_keys($wanted), array_keys($known)));

        return ['updates' => $updates, 'unknown' => $unknown];
    }

    /**
     * Turn the update list into the JSON strings the SDK accepts.
     *
     * @param  list<array<string, string>>  $updates
     * @return list<string>
     */
    public static function payloads(array $updates, int $chunkSize = self::CHUNK_SIZE): array
    {
        $payloads = [];

        foreach (array_chunk($updates, max(1, $chunkSize)) as $chunk) {
            $payloads[] = json_encode($chunk, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        return $payloads;
    }
}
