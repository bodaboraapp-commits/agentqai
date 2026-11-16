<?php

declare(strict_types=1);

namespace App\Services\Telephony\AfricaSTalking;

use App\Contracts\Telephony\CallXmlInterface;
use DOMDocument;
use DOMElement;
use LogicException;

/**
 * Africa's Talking call-control XML ("AT XML").
 *
 * AT XML looks like TwiML but is its own dialect: <Dial phoneNumbers="…">
 * takes the destination as an attribute (not a nested <Number>), <GetDigits>
 * is the gather element, the hang-up element is <Reject/>, and there is no
 * <Pause> and no <Stream> at all. Like the Twilio/Plivo generators, every
 * public method returns a COMPLETE response document
 * (<?xml … ?><Response>…</Response>), because the shared TwiML controller
 * responds with these strings directly.
 *
 * Reference: Africa's Talking PHP SDK's Voice message builder.
 */
class AfricaSTalkingCallXmlGenerator implements CallXmlInterface
{
    /** Audio URLs played back to callers. */
    private function isAudioUrl(string $value): bool
    {
        return (bool) preg_match('#^https?://#i', $value);
    }

    /**
     * @param  callable(DOMDocument, DOMElement): void  $build
     */
    private function document(callable $build): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;
        $response = $dom->createElement('Response');
        $dom->appendChild($response);
        $build($dom, $response);

        return $dom->saveXML() ?: '<?xml version="1.0" encoding="UTF-8"?><Response/>';
    }

    /**
     * @param  array<string, scalar|null>  $attributes
     */
    private function append(DOMDocument $dom, DOMElement $parent, string $name, ?string $text = null, array $attributes = []): DOMElement
    {
        $element = $dom->createElement($name);

        foreach ($attributes as $attribute => $value) {
            if ($value === null) {
                continue;
            }
            $element->setAttribute($attribute, $this->scalar($value));
        }

        if ($text !== null && $text !== '') {
            $element->appendChild($dom->createTextNode($text));
        }

        $parent->appendChild($element);

        return $element;
    }

    private function scalar(bool|int|string $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    /**
     * The <Say> voice attribute: AT's voice field, falling back to config.
     */
    private function voice(array $options): ?string
    {
        $voice = $options['voice'] ?? null;

        if (is_string($voice) && trim($voice) !== '') {
            return $voice;
        }

        $default = (string) config('africastalking.voice.tts.default_voice', '');

        return $default !== '' ? $default : null;
    }

    /**
     * The response body for a spoken message, including the optional sibling
     * DTMF/recording blocks used by campaign TTS calls. Mirrors the shared
     * contract's semantics: the script is said first, DTMF and recording are
     * siblings (never wrapping the main text).
     */
    private function sayInto(DOMDocument $dom, DOMElement $response, string $text, array $options): void
    {
        $sayAttributes = ['voice' => $this->voice($options)];

        if (array_key_exists('playBeep', $options)) {
            $sayAttributes['playBeep'] = $options['playBeep'];
        }

        if (! empty($options['dtmf'])) {
            $gather = $this->append($dom, $response, 'GetDigits', null, [
                'numDigits' => $options['dtmf']['num_digits'] ?? 1,
                'timeout' => $options['dtmf']['timeout'] ?? 5,
                'callBackUrl' => $options['dtmf']['action'] ?? null,
            ]);
            $this->append($dom, $gather, 'Say', $text, $sayAttributes);
        } else {
            $this->append($dom, $response, 'Say', $text, $sayAttributes);
        }

        if (! empty($options['record'])) {
            $this->append($dom, $response, 'Record', null, [
                'maxLength' => $options['record']['max_length'] ?? 30,
                'callBackUrl' => $options['record']['action'] ?? null,
            ]);
        }
    }

    public function say(string $text, array $options = []): string
    {
        return $this->document(function (DOMDocument $dom, DOMElement $response) use ($text, $options): void {
            $this->sayInto($dom, $response, $text, $options);
        });
    }

    public function play(string $audioUrl, array $options = []): string
    {
        return $this->document(function (DOMDocument $dom, DOMElement $response) use ($audioUrl, $options): void {
            $this->append($dom, $response, 'Play', null, ['url' => $audioUrl]);

            if (! empty($options['dtmf'])) {
                $gather = $this->append($dom, $response, 'GetDigits', null, [
                    'numDigits' => $options['dtmf']['num_digits'] ?? 1,
                    'timeout' => $options['dtmf']['timeout'] ?? 5,
                    'callBackUrl' => $options['dtmf']['action'] ?? null,
                ]);
                $this->append($dom, $gather, 'Say', $options['dtmf']['prompt'] ?? 'Press any key to respond.');
            }

            if (! empty($options['record'])) {
                $this->append($dom, $response, 'Record', null, [
                    'maxLength' => $options['record']['max_length'] ?? 30,
                    'callBackUrl' => $options['record']['action'] ?? null,
                ]);
            }
        });
    }

    public function dial(string $number, array $options = []): string
    {
        return $this->document(function (DOMDocument $dom, DOMElement $response) use ($number, $options): void {
            if (! empty($options['message'])) {
                $this->append($dom, $response, 'Say', (string) $options['message']);
            }

            $attributes = [
                'phoneNumbers' => $number,
                'callerId' => $options['callerId'] ?? null,
                'record' => $this->dialRecord($options['record'] ?? null),
                'sequential' => $options['sequential'] ?? null,
                'ringbackTone' => $options['ringbackTone'] ?? null,
                'maxDuration' => $options['maxDuration'] ?? $options['timeout'] ?? null,
            ];

            $this->append($dom, $response, 'Dial', null, $attributes);
        });
    }

    private function dialRecord(mixed $record): ?string
    {
        if ($record === null) {
            return null;
        }

        if (is_string($record) && in_array(strtolower($record), ['do-not-record', 'false', ''], true)) {
            return null;
        }

        return 'true';
    }

    public function dialClient(string $clientIdentity, array $options = []): string
    {
        // AT has no separate client-dial element in its documented XML, but
        // WebRTC clients register under a client name (our token identity,
        // e.g. user_12) and AT routes <Dial phoneNumbers="…"> to registered
        // endpoints for the number. The prefix is configurable so the exact
        // form can be adjusted without a code deploy while validating:
        //   AFRICASTALKING_CLIENT_PREFIX=''          → "user_12"
        //   AFRICASTALKING_CLIENT_PREFIX='client:'   → "client:user_12"
        $target = (string) config('africastalking.voice.webrtc_client_prefix', '').$clientIdentity;

        return $this->document(function (DOMDocument $dom, DOMElement $response) use ($target, $options): void {
            if (! empty($options['message'])) {
                $this->append($dom, $response, 'Say', (string) $options['message']);
            }

            $this->append($dom, $response, 'Dial', null, [
                'phoneNumbers' => $target,
                'callerId' => $options['callerId'] ?? null,
                'record' => $this->dialRecord($options['record'] ?? null),
                'maxDuration' => $options['timeout'] ?? null,
            ]);
        });
    }

    public function conference(string $roomName, array $options = []): string
    {
        return $this->document(function (DOMDocument $dom, DOMElement $response): void {
            // AT's <Conference/> is bare: the room is implied by the call's
            // queue/number, not by a name attribute.
            $this->append($dom, $response, 'Conference');
        });
    }

    public function gather(string $prompt, array $options = []): string
    {
        return $this->document(function (DOMDocument $dom, DOMElement $response) use ($prompt, $options): void {
            $gather = $this->append($dom, $response, 'GetDigits', null, [
                'numDigits' => $options['numDigits'] ?? null,
                'timeout' => $options['timeout'] ?? null,
                'finishOnKey' => $options['finishOnKey'] ?? null,
                'callBackUrl' => $options['action'] ?? null,
            ]);

            // AT's GetDigits only collects DTMF; the interface's optional
            // 'speech' input does not exist here (AI voice agents are hidden
            // for this provider).
            if ($this->isAudioUrl($prompt)) {
                $this->append($dom, $gather, 'Play', null, ['url' => $prompt]);
            } else {
                $this->append($dom, $gather, 'Say', $prompt, ['voice' => $this->voice($options)]);
            }
        });
    }

    public function record(array $options = []): string
    {
        return $this->document(function (DOMDocument $dom, DOMElement $response) use ($options): void {
            $this->append($dom, $response, 'Record', null, [
                'finishOnKey' => $options['finishOnKey'] ?? null,
                'maxLength' => $options['maxLength'] ?? $options['max_length'] ?? null,
                'timeout' => $options['timeout'] ?? null,
                'trimSilence' => $options['trimSilence'] ?? null,
                'playBeep' => $options['playBeep'] ?? $options['play_beep'] ?? null,
                'callBackUrl' => $options['action'] ?? null,
            ]);
        });
    }

    public function pause(int $seconds = 1): string
    {
        // AT XML has no <Pause>. An empty response is the honest no-op.
        return $this->buildResponse([]);
    }

    public function hangup(): string
    {
        return $this->document(function (DOMDocument $dom, DOMElement $response): void {
            $this->append($dom, $response, 'Reject');
        });
    }

    public function redirect(string $url): string
    {
        return $this->document(function (DOMDocument $dom, DOMElement $response) use ($url): void {
            $this->append($dom, $response, 'Redirect', $url);
        });
    }

    public function stream(string $websocketUrl, array $options = []): string
    {
        throw new LogicException('Africa\'s Talking does not support bidirectional media streaming.');
    }

    public function buildResponse(array $instructions): string
    {
        if (count($instructions) === 0) {
            return $this->document(static function (): void {});
        }

        if (count($instructions) === 1) {
            return (string) reset($instructions);
        }

        return $this->document(function (DOMDocument $dom, DOMElement $response) use ($instructions): void {
            foreach ($instructions as $instruction) {
                if (! is_string($instruction) || trim($instruction) === '') {
                    continue;
                }

                $fragment = new DOMDocument('1.0', 'UTF-8');
                if (@$fragment->loadXML($instruction) === false) {
                    $this->append($dom, $response, 'Say', $instruction);

                    continue;
                }

                foreach ($fragment->documentElement?->childNodes ?? [] as $child) {
                    if ($child instanceof DOMElement) {
                        $response->appendChild($dom->importNode($child, true));
                    }
                }
            }
        });
    }

    public function generateManualCallXml(string $phoneNumber, array $options = []): string
    {
        return $this->document(function (DOMDocument $dom, DOMElement $response) use ($phoneNumber, $options): void {
            $this->append($dom, $response, 'Dial', null, [
                'phoneNumbers' => $phoneNumber,
                'callerId' => $options['callerId'] ?? null,
                'record' => $this->dialRecord($options['record'] ?? null),
                'maxDuration' => $options['timeout'] ?? null,
            ]);
        });
    }

    public function generateCampaignTtsXml(string $text, array $options = []): string
    {
        return $this->document(function (DOMDocument $dom, DOMElement $response) use ($text, $options): void {
            $voice = $this->voice($options);

            $this->append($dom, $response, 'Say', $text, ['voice' => $voice]);

            if (! empty($options['dtmf']['enabled'])) {
                $gather = $this->append($dom, $response, 'GetDigits', null, [
                    'numDigits' => $options['dtmf']['num_digits'] ?? 1,
                    'timeout' => $options['dtmf']['timeout'] ?? 5,
                    'callBackUrl' => $options['dtmf']['action'] ?? null,
                ]);
                $this->append($dom, $gather, 'Say', $options['dtmf']['prompt'] ?? 'Press any key to respond.', ['voice' => $voice]);
            }

            if (! empty($options['record']['enabled'])) {
                $this->append($dom, $response, 'Record', null, [
                    'maxLength' => $options['record']['max_length'] ?? 30,
                    'callBackUrl' => $options['record']['action'] ?? null,
                ]);
            }
        });
    }

    public function generateErrorXml(string $message): string
    {
        return $this->document(function (DOMDocument $dom, DOMElement $response) use ($message): void {
            $this->append($dom, $response, 'Say', $message);
            $this->append($dom, $response, 'Reject');
        });
    }

    public function getProviderName(): string
    {
        return 'africastalking';
    }
}
