<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Diagnose AT SIP registration (credentials, account state, or source policy).
 *
 * Shared cPanel hosts often refuse outbound SIP/TCP 5060 while allowing UDP,
 * so "Connection refused" on TCP says nothing about the credentials. This
 * command can probe TCP, UDP and TLS (and "auto" tries all three) and prints
 * the registrar's own status lines for an unauthenticated and an authenticated
 * REGISTER. The password is never echoed and nothing is stored.
 */
class SipProbe extends Command
{
    protected $signature = 'telephony:sip-probe
        {--domain=ke.sip.africastalking.com : AT SIP domain}
        {--username= : SIP username}
        {--password= : SIP password (prompted when omitted)}
        {--port=5060 : SIP port (TLS defaults to 5061 when set to 5060)}
        {--transport=tcp : tcp, udp, tls, or auto (tries all three)}';

    protected $description = 'Diagnose AT SIP registration over SIP/TCP, UDP or TLS';

    public function handle(): int
    {
        $domain = (string) $this->option('domain');
        $port = (int) $this->option('port');
        $transport = strtolower((string) $this->option('transport'));
        $username = trim((string) ($this->option('username') ?? ''));
        if ($username === '') {
            $username = trim((string) $this->ask('SIP username'));
        }
        $password = (string) ($this->option('password') ?? '');
        if ($password === '') {
            $password = (string) $this->secret('SIP password');
        }

        $this->echoPublicIps();

        $targets = match ($transport) {
            'auto' => [['tcp', $port], ['udp', $port], ['tls', $port === 5060 ? 5061 : $port]],
            'udp' => [['udp', $port]],
            'tls' => [['tls', $port === 5060 ? 5061 : $port]],
            default => [['tcp', $port]],
        };

        foreach ($targets as [$scheme, $targetPort]) {
            $this->newLine();
            $this->line('SIP server: '.$domain.':'.$targetPort.' via '.strtoupper($scheme));

            $options = $this->exchange($scheme, $domain, $targetPort, $this->optionsMessage($domain, $targetPort, $scheme));
            $optionsLine = $this->firstLine($options);
            $this->line('OPTIONS → '.$optionsLine);

            if (isset($options['error'])) {
                $this->warn(strtoupper($scheme).' unreachable from this host: '.$options['error'].' — trying next transport if available.');

                continue;
            }

            $probe = $this->exchange($scheme, $domain, $targetPort, $this->registerMessage($domain, $targetPort, $scheme, $username, null));
            $this->line('REGISTER (no auth) → '.$this->firstLine($probe));

            $challenge = $this->parseChallenge($probe['body'] ?? '');
            if ($challenge === null) {
                $this->warn('No 401 challenge — the server answered the unauthenticated REGISTER directly.');

                continue;
            }

            $this->line('Challenge: realm='.$challenge['realm'].' qop='.$challenge['qop']);

            $real = $this->exchange($scheme, $domain, $targetPort, $this->registerMessage($domain, $targetPort, $scheme, $username, $challenge, $password));
            $this->line('REGISTER (your credentials) → '.$this->firstLine($real));

            $wrong = $this->exchange($scheme, $domain, $targetPort, $this->registerMessage($domain, $targetPort, $scheme, $username, $challenge, 'deliberately-wrong-'.bin2hex(random_bytes(4))));
            $this->line('REGISTER (wrong password) → '.$this->firstLine($wrong));

            $unknown = $this->exchange($scheme, $domain, $targetPort, $this->registerMessage($domain, $targetPort, $scheme, 'agentq_no_such_user_'.bin2hex(random_bytes(3)), $challenge, 'x'));
            $this->line('REGISTER (nonexistent user) → '.$this->firstLine($unknown));

            $this->newLine();
            $this->verdict($this->firstLine($real), $this->firstLine($wrong), $this->firstLine($unknown));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->error('No SIP transport was reachable from this host. The hosting firewall likely blocks outbound SIP.');
        $this->line('Test the credentials from a softphone on a network that allows SIP (mobile hotspot), not from the web host.');

        return self::FAILURE;
    }

    private function verdict(string $real, string $wrong, string $unknown): void
    {
        if (str_contains($real, ' 200 ')) {
            $this->info('SIP registration SUCCEEDED from this IP. Credentials are valid; if another IP fails, AT is blocking that IP.');

            return;
        }

        if (str_contains($wrong, ' 403 ') && str_contains($real, ' 403 ') && str_contains($unknown, ' 403 ')) {
            $this->warn('VERDICT: the registrar refuses this source/user before meaningful password validation.');
            $this->line('Wrong password and nonexistent user get the same 403 as the real account.');

            return;
        }

        if (str_contains($real, ' 401 ')) {
            $this->warn('VERDICT: the digest was rejected as an authentication failure (registering credentials or auth username are wrong).');

            return;
        }

        $this->warn('VERDICT unclear. Send the lines above to AT support.');
    }

    private function echoPublicIps(): void
    {
        foreach (['https://api.ipify.org', 'https://ifconfig.me/ip'] as $url) {
            try {
                $ip = trim((string) Http::timeout(8)->get($url)->body());
                if ($ip !== '') {
                    $this->line('This machine exits to the internet as: '.$ip);

                    return;
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
    private function exchange(string $scheme, string $domain, int $port, string $message): array
    {
        if ($scheme === 'udp') {
            return $this->udpExchange($domain, $port, $message);
        }

        $url = ($scheme === 'tls' ? 'tls://' : 'tcp://').$domain.':'.$port;
        $context = $scheme === 'tls'
            ? stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]])
            : null;

        $socket = @stream_socket_client($url, $errno, $errstr, 8, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            return ['error' => 'connect failed: '.$errstr];
        }

        stream_set_timeout($socket, 8);
        $writeError = $this->safeWrite($socket, $message);
        if ($writeError !== null) {
            fclose($socket);

            return ['error' => 'send failed: '.$writeError];
        }

        $body = '';
        while (! feof($socket)) {
            $chunk = @fread($socket, 4096);
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

    /** @return array{body?: string, error?: string} */
    private function udpExchange(string $domain, int $port, string $message): array
    {
        $socket = @stream_socket_client('udp://'.$domain.':'.$port, $errno, $errstr, 8);
        if ($socket === false) {
            return ['error' => 'connect failed: '.$errstr];
        }

        // On shared cPanel hosts the kernel/firewall can refuse the send with
        // EPERM ("Operation not permitted"). Suppress the warning so Laravel's
        // error handler does not turn it into a fatal page error and report it
        // as an unreachable transport instead.
        $writeError = $this->safeWrite($socket, $message);
        if ($writeError !== null) {
            fclose($socket);

            return ['error' => 'send blocked: '.$writeError];
        }

        $read = [$socket];
        $write = null;
        $except = null;
        $ready = @stream_select($read, $write, $except, 6);

        $body = '';
        if ($ready > 0) {
            $body = (string) @fread($socket, 8192);
        }
        fclose($socket);

        if ($body === '') {
            return ['error' => 'no UDP response (timeout or blocked)'];
        }

        return ['body' => $body];
    }

    /**
     * Write without emitting a PHP warning; returns the error message on failure.
     */
    private function safeWrite($socket, string $message): ?string
    {
        $written = @fwrite($socket, $message);

        if ($written === false) {
            $error = error_get_last();

            return $error['message'] ?? 'unknown write error';
        }

        return null;
    }

    private function optionsMessage(string $domain, int $port, string $scheme): string
    {
        $transport = strtoupper($scheme);

        return 'OPTIONS sip:'.$domain.' SIP/2.0'."\r\n"
            .'Via: SIP/2.0/'.$transport.' probe.invalid;branch=z9hG4bK'.bin2hex(random_bytes(4))."\r\n"
            .'Max-Forwards: 70'."\r\n"
            .'To: <sip:'.$domain.'>'."\r\n"
            .'From: <sip:probe@probe.invalid>;tag='.bin2hex(random_bytes(4))."\r\n"
            .'Call-ID: probe-'.uniqid()."\r\n"
            .'CSeq: 1 OPTIONS'."\r\n"
            .'Contact: <sip:probe@probe.invalid:5062;transport='.strtolower($scheme).'>'."\r\n"
            .'Content-Length: 0'."\r\n\r\n";
    }

    /** @param array{realm: string, nonce: string, qop: ?string, opaque: ?string}|null $auth */
    private function registerMessage(string $domain, int $port, string $scheme, string $username, ?array $auth, string $password = ''): string
    {
        $transport = strtoupper($scheme);
        $uri = 'sip:'.$domain.':'.$port;
        $aor = 'sip:'.$username.'@'.$domain;

        $message = 'REGISTER '.$uri.' SIP/2.0'."\r\n"
            .'Via: SIP/2.0/'.$transport.' probe.invalid;branch=z9hG4bK'.bin2hex(random_bytes(4)).';rport'."\r\n"
            .'Max-Forwards: 70'."\r\n"
            .'From: <'.$aor.'>;tag='.bin2hex(random_bytes(4))."\r\n"
            .'To: <'.$aor.'>'."\r\n"
            .'Call-ID: probe-'.uniqid().'@'.$domain."\r\n"
            .'CSeq: 1 REGISTER'."\r\n"
            .'Contact: <sip:'.$username.'@probe.invalid:5062;transport='.strtolower($scheme).'>'."\r\n"
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
