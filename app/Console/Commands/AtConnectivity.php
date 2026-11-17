<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Check this server's path to Africa's Talking hosts.
 *
 * Campaign calls failed with "cURL error 28: Failed to connect to
 * voice.africastalking.com port 443 after 10006 ms" while direct API tests
 * sometimes succeed — i.e. the network path from the server is flaky or
 * blocked. This checks DNS, TCP 443, and a harmless POST to the Voice endpoint
 * (AT answers 400 "missing required form field 'from'" on the correct host),
 * and repeats it so intermittent failures are visible.
 */
class AtConnectivity extends Command
{
    protected $signature = 'telephony:at-connectivity
        {--attempts=3 : How many times to try each host}';

    protected $description = 'Test DNS/TCP/HTTPS reachability of AT Voice and API hosts from this server';

    public function handle(): int
    {
        $attempts = max(1, (int) $this->option('attempts'));

        $hosts = [
            'voice.africastalking.com' => 'https://voice.africastalking.com/call',
            'api.africastalking.com' => 'https://api.africastalking.com/version1/user',
        ];

        $allOk = true;

        foreach ($hosts as $host => $url) {
            $this->line('');
            $this->info($host);

            $ip = gethostbyname($host);
            $this->line('  DNS: '.($ip !== $host ? $ip : 'FAILED'));

            for ($i = 1; $i <= $attempts; $i++) {
                $tcpStart = microtime(true);
                $socket = @fsockopen('tcp://'.$host, 443, $errno, $errstr, 10);
                $tcpMs = (int) round((microtime(true) - $tcpStart) * 1000);
                $tcpOk = $socket !== false;
                if ($socket !== false) {
                    fclose($socket);
                }
                $this->line(sprintf('  TCP 443 attempt %d: %s (%d ms)%s', $i, $tcpOk ? 'OK' : 'FAIL', $tcpMs, $tcpOk ? '' : ' — '.$errstr));

                $httpStart = microtime(true);
                try {
                    $response = Http::withHeaders(['Accept' => 'application/json'])
                        ->connectTimeout(10)
                        ->timeout(20)
                        ->asForm()
                        ->post($url, ['username' => 'connectivity-probe']);
                    $httpMs = (int) round((microtime(true) - $httpStart) * 1000);
                    $this->line(sprintf('  HTTPS attempt %d: HTTP %d (%d ms) %s', $i, $response->status(), $httpMs, mb_substr(str_replace("\n", ' ', $response->body()), 0, 120)));
                } catch (\Throwable $e) {
                    $httpMs = (int) round((microtime(true) - $httpStart) * 1000);
                    $allOk = false;
                    $this->line(sprintf('  HTTPS attempt %d: ERROR (%d ms) %s', $i, $httpMs, $e->getMessage()));
                }
            }
        }

        $this->line('');
        if ($allOk) {
            $this->info('AT hosts reachable from this server. If campaign calls still fail, share the latest "Provider call failed" log line.');

            return self::SUCCESS;
        }

        $this->warn('Intermittent/unreachable AT host. If TCP or HTTPS fails here, the app cannot dial campaigns from this server:');
        $this->line('  1) Ask the hosting provider to allow outbound HTTPS from this server to voice.africastalking.com (and api.africastalking.com).');
        $this->line('  2) Check the server IP is not blocked by AT (share it with AT voice support with the curl error).');
        $this->line('  3) Retry: php artisan telephony:at-test-call +254756112633 --from=+254711082025');

        return self::FAILURE;
    }
}
