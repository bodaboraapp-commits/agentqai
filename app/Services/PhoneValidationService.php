<?php

declare(strict_types=1);

namespace App\Services;

class PhoneValidationService
{
    /**
     * Sanitize and validate phone number to E.164 format
     * This is a simplified version - for production, use libphonenumber-for-php
     */
    public function sanitize(string $phoneNumber, ?string $defaultCountryCode = null): array
    {
        // Every caller shares the installation default (AT installs are +254).
        // A hardcoded +1 turned +254756112633 into +1254756112633, which AT
        // rejects — the campaign dials showed exactly that.
        $defaultCountryCode ??= (string) config('telephony.default_country_code', '+1');
        try {
            // Remove all non-digit characters except +
            $cleaned = preg_replace('/[^0-9+]/', '', trim($phoneNumber));

            if (empty($cleaned)) {
                return [
                    'success' => false,
                    'formatted' => null,
                    'error' => 'Phone number is empty',
                ];
            }

            // Add default country code if not present
            if (!str_starts_with($cleaned, '+')) {
                // Normalize the country code prefix (e.g. '+1' => '1')
                $cc = ltrim($defaultCountryCode, '+');

                // Already an international number typed without the leading
                // '+': e.g. "254756112633" on a +254 installation. Treating
                // it as a national number used to produce "+1254756112633",
                // which AT rejects (campaign dials failed on exactly that).
                if (str_starts_with($cleaned, $cc) && strlen($cleaned) >= strlen($cc) + 7) {
                    $cleaned = '+' . $cleaned;
                } elseif ($cc !== '1' && str_starts_with($cleaned, '0') && strlen($cleaned) === strlen($cc) + 9) {
                    // National trunk format: 0756112633 -> +254756112633
                    $cleaned = '+' . $cc . substr($cleaned, 1);
                } elseif ($cc === '1') {
                    // NANP (country code '1'): accept 10-digit local numbers and
                    // 11-digit national format (with leading '1'). Never use ltrim()
                    // to strip leading digits — that can wrongly chop off area codes
                    // for other country codes.
                    if (strlen($cleaned) === 11 && str_starts_with($cleaned, '1')) {
                        $cleaned = '+' . $cleaned;
                    } else {
                        $cleaned = '+1' . $cleaned;
                    }
                } else {
                    // Non-NANP countries often have a leading '0' for national dialing.
                    // Strip a single leading 0 (not multiple) before prepending.
                    if (str_starts_with($cleaned, '0')) {
                        $cleaned = substr($cleaned, 1);
                    }
                    $cleaned = '+' . $cc . $cleaned;
                }
            }

            // Basic validation: E.164 format should be 7-15 digits after +
            $digits = ltrim($cleaned, '+');
            $digitCount = strlen($digits);

            if ($digitCount < 7 || $digitCount > 15) {
                return [
                    'success' => false,
                    'formatted' => null,
                    'error' => 'Invalid phone number length',
                ];
            }

            // Ensure it starts with +
            if (!str_starts_with($cleaned, '+')) {
                $cleaned = '+' . $cleaned;
            }

            return [
                'success' => true,
                'formatted' => $cleaned,
                'error' => null,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'formatted' => null,
                'error' => 'Validation error',
            ];
        }
    }

    /**
     * Quick validation check
     */
    public function isValid(string $phoneNumber, ?string $defaultCountryCode = null): bool
    {
        $result = $this->sanitize($phoneNumber, $defaultCountryCode);
        return $result['success'];
    }
}
