<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Diagnose AT SIP registration (is it credentials, account state, or IP?).
 *
 * Sends an OPTIONS then a REGISTER challenge/auth sequence over SIP/TCP and
 * prints the server's own status lines. Run it from the production server as
 * well as any other machine: if every source IP (and even a nonexistent user)
 * gets 401 → 403, the account/SIP registration is blocked at AT's policy layer
 * (typically IP allow-listing or a SIP number not mapped/active), not a wrong
 * password.
 *
 * Nothing is stored; the password is never echoed.
 */
class SipProbe extends Command
{
    protected $signature = 'telephony:sip-probe
        {--domain=ke.sip.africastalking.com : AT SIP domain}
        {--username= : SIP username}
        {--password= : SIP password (prompted when omitted)}
        {--port=5060 : SIP TCP port}';

    protected $description = 'Diagnose AT SIP registration (credentials vs IP allow-list) over SIP/TCP';

    public function handle(): int
    {
        $domain = (string) $this->option('domain');
        $port = (int) $this->option('port');
        $username = trim((string) ($this->option('username') ?? ''));
        if ($username === '') {
            $username = trim((string) $this->ask('SIP username'));
        }
        $password = (string) ($this->option('password') ?? '');
        if ($password === '') {
            $password = (string) $this->secret('SIP password');
        }

        $this->echoPublicIps();

        $this->line('SIP server: '.$domain.':'.$port);

        $options = $this->exchange($domain, $port, $this->optionsMessage($domain, $port));
        $this->line('OPTIONS → '.$this->firstLine($options));

        $probe = $this->exchange($domain, $port, $this->registerMessage($domain, $port, $username, null));
        $this->line('REGISTER (no auth) → '.$this->firstLine($probe));

        $challenge = $this->parseChallenge($probe['body'] ?? '');
        if ($challenge === null) {
            $this->warn('No 401 challenge — the server answered the unauthenticated REGISTER directly.');

            return self::FAILURE;
        }

        $this->line('Challenge: realm='.$challenge['realm'].' qop='.$challenge['qop']);

        // 1) Real credentials. 2) Deliberately wrong password. 3) Unknown user.
        $real = $this->exchange($domain, $port, $this->registerMessage($domain, $port, $username, $challenge, $password));
        $this->line('REGISTER (your credentials) → '.$this->firstLine($real));

        $wrong = $this->exchange($domain, $port, $this->registerMessage($domain, $port, $username, $challenge, 'deliberately-wrong-'.bin2hex(random_bytes(4))));
        $this->line('REGISTER (wrong password) → '.$this->firstLine($wrong));

        $unknown = $this->exchange($domain, $port, $this->registerMessage($domain, $port, 'agentq_no_such_user_'.bin2hex(random_bytes(3)), $challenge, 'x'));
        $this->line('REGISTER (nonexistent user) → '.$this->firstLine($unknown));

        $this->newLine();
        $realOk = str_contains($this->firstLine($real), ' 200 ');
        $wrongSame = str_contains($this->firstLine($wrong), ' 403 ') && str_contains($this->firstLine($real), ' 403 ');
        $unknownSame = str_contains($this->firstLine($unknown), ' 403 ');

        if ($realOk) {
            $this->info('SIP registration SUCCEEDED from this IP. The account and credentials are valid here; if another IP fails, AT has that IP allow-listed/blocked.');

            return self::SUCCESS;
        }

        if ($wrongSame && $unknownSame) {
            $this->warn('VERDICT: registration is being refused by AT policy, not credential validation.');
            $this->line('A wrong password AND a nonexistent username both get the same 403, so the registrar is not checking passwords for this source.');
            $this->line('Most likely: this source IP is not allow-listed for the SIP account, or the SIP number is not mapped/active.');
            $this->line('Ask AT voice support (voice@africastalking.com) to whitelist the source IP shown above and confirm the SIP number is active.');

            return self::FAILURE;
        }

        $this->warn('VERDICT unclear: credential/account error. Send the lines above to AT support.');

        return self::FAILURE;
    }

    private function echoPublicIps(): void
    {
        foreach (['https://api.ipify.org', 'https://ifconfig.me/ip'] as $url) {
            try {
                $ip = trim((string) Http::timeout(8)->get($url)->body());
                if ($ip !== '') {
                    $this->line('This machine exits to the internet as: '.$ip);
                    break;
                }
            } catch (\Throwable) {
                // try the next service
            }
        }
    }

    private function firstLine(array $result): string
    {
        if (isset($result['error'])) {
            return 'ERROR '.$result['error'];
        }

        return trim(strtok($result['body'] ?? '', "\r\n") ?: '(empty response)');
    }

    /** @return array{body?: string, error?: string} */
    private function exchange(string $domain, int $port, string $message): array
    {
        $socket = @fsockopen('tcp://'.$domain, $port, $errno, $errstr, 8);
        if ($socket === false) {
            return ['error' => 'connect failed: '.$errstr];
        }

        stream_set_timeout($socket, 8);
        fwrite($socket, $message);

        $body = '';
        while (! feof($socket)) {
            $chunk = fread($socket, 4096);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $body .= $chunk;
            if (str_contains($body, "\r\n\r\n")) {
                break;
            }
        }
        fclose($socket);

        return ['body' => $body];
    }

    private function optionsMessage(string $domain, int $port): string
    {
        return 'OPTIONS sip:'.$domain.' SIP/2.0'."\r\n"
            .'Via: SIP/2.0/TCP probe.invalid;branch=z9hG4bK'.bin2hex(random_bytes(4))."\r\n"
            .'Max-Forwards: 70'."\r\n"
            .'To: <sip:'.$domain.'>'."\r\n"
            .'From: <sip:probe@probe.invalid>;tag='.bin2hex(random_bytes(4))."\r\n"
            .'Call-ID: probe-'.uniqid()."\r\n"
            .'CSeq: 1 OPTIONS'."\r\n"
            .'Content-Length: 0'."\r\n\r\n";
    }

    /** @param array{realm: string, nonce: string, qop: ?string, opaque: ?string}|null $auth */
    private function registerMessage(string $domain, int $port, string $username, ?array $auth, string $password = ''): string
    {
        $uri = 'sip:'.$domain.':'.$port;
        $aor = 'sip:'.$username.'@'.$domain;
        $message = 'REGISTER '.$uri.' SIP/2.0'."\r\n"
            .'Via: SIP/2.0/TCP probe.invalid;branch=z9hG4bK'.bin2hex(random_bytes(4)).';rport'."\r\n"
            .'Max-Forwards: 70'."\r\n"
            .'From: <'.$aor.'>;tag='.bin2hex(random_bytes(4))."\r\n"
            .'To: <'.$aor.'>'."\r\n"
            .'Call-ID: probe-'.uniqid().'@'.$domain."\r\n"
            .'CSeq: 1 REGISTER'."\r\n"
            .'Contact: <sip:'.$username.'@probe.invalid:5099;transport=tcp>'."\r\n"
            .'Expires: 60'."\r\n"
            .'User-Agent: agentq-sip-probe'."\r\n";

        if ($auth !== null) {
            $nc = '00000001';
            $cnonce = bin2hex(random_bytes(6));
            $ha1 = md5($username.':'.$auth['realm'].':'.$password);
            $ha2 = md5('REGISTER:'.$uri);
            $response = $auth['qop']
                ? md5($ha1.':'.$auth['nonce'].':'.$nc.':'.$cnonce.':'.$auth['qop'].':'.$ha2)
                : md5($ha1.':'.$auth['nonce'].':'.$ha2);

            $message .= 'Authorization: Digest username="'.$username.'", realm="'.$auth['realm'].'", nonce="'.$auth['nonce']
                .'", uri="'.$uri.'", response="'.$response.'", algorithm=MD5';
            if ($auth['qop']) {
                $message .= ', qop='.$auth['qop'].', nc='.$nc.', cnonce="'.$cnonce.'"';
            }
            if ($auth['opaque']) {
                $message .= ', opaque="'.$auth['opaque'].'"';
            }
            $message .= "\r\n";
        }

        return $message."Content-Length: 0\r\n\r\n";
    }

    /** @return array{realm: string, nonce: string, qop: ?string, opaque: ?string}|null */
    private function parseChallenge(string $response): ?array
    {
        if (! str_contains($response, 'WWW-Authenticate')) {
            return null;
        }

        $pick = function (string $name) use ($response): ?string {
            return preg_match('/'.$name.'="?([^"\r\n,]+)"?/', $response, $m) ? $m[1] : null;
        };

        $realm = $pick('realm');
        $nonce = $pick('nonce');

        if ($realm === null || $nonce === null) {
            return null;
        }

        return ['realm' => $realm, 'nonce' => $nonce, 'qop' => $pick('qop'), 'opaque' => $pick('opaque')];
    }
}
