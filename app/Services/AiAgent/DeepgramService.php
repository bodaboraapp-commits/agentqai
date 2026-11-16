<?php

namespace App\Services\AiAgent;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DeepgramService
{
    private string $apiKey;
    private string $apiUrl;
    private array $config;

    public function __construct()
    {
        $this->apiKey = (string) config('deepgram.api_key');
        $this->apiUrl = (string) config('deepgram.api_url');
        $this->config = (array) config('deepgram.streaming', []);

        // Deliberately NOT throwing here: this service is injected into
        // CallStateManager, which is injected into the whole AI-agent call
        // controller. Throwing at construction made every read-only endpoint
        // (call log list, active calls, stats) 500 on installations without a
        // Deepgram key — including providers where AI voice agents are not
        // used at all. Only methods that actually talk to Deepgram require it.
    }

    private function ensureConfigured(): void
    {
        if ($this->apiKey === '') {
            throw new Exception('Deepgram API key is not configured');
        }
    }

    /**
     * Get WebSocket URL for streaming transcription
     */
    public function getStreamingUrl(): string
    {
        $this->ensureConfigured();

        $params = http_build_query([
            'model' => $this->config['model'],
            'language' => $this->config['language'],
            'encoding' => $this->config['encoding'],
            'sample_rate' => $this->config['sample_rate'],
            'channels' => $this->config['channels'],
            'interim_results' => $this->config['interim_results'] ? 'true' : 'false',
            'punctuate' => $this->config['punctuate'] ? 'true' : 'false',
            'smart_format' => $this->config['smart_format'] ? 'true' : 'false',
            'vad_events' => $this->config['vad_events'] ? 'true' : 'false',
        ]);

        $wsUrl = str_replace(['https://', 'http://'], 'wss://', $this->apiUrl);
        return "{$wsUrl}/listen?{$params}";
    }

    /**
     * Get authorization header for WebSocket connection
     */
    public function getAuthHeader(): string
    {
        $this->ensureConfigured();

        return "Token {$this->apiKey}";
    }

    /**
     * Transcribe audio file (for testing/batch processing)
     */
    public function transcribeFile(string $audioUrl): ?array
    {
        try {
            $this->ensureConfigured();

            $response = Http::withHeaders([
                'Authorization' => $this->getAuthHeader(),
                'Content-Type' => 'application/json',
            ])->post("{$this->apiUrl}/listen", [
                'url' => $audioUrl,
                'model' => $this->config['model'],
                'punctuate' => $this->config['punctuate'],
                'smart_format' => $this->config['smart_format'],
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'transcript' => $data['results']['channels'][0]['alternatives'][0]['transcript'] ?? '',
                    'confidence' => $data['results']['channels'][0]['alternatives'][0]['confidence'] ?? 0,
                    'words' => $data['results']['channels'][0]['alternatives'][0]['words'] ?? [],
                ];
            }

            Log::error('Deepgram transcription failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        } catch (Exception $e) {
            Log::error('Deepgram transcription error', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Process streaming transcription result
     */
    public function processStreamingResult(array $result): ?array
    {
        try {
            if (!isset($result['channel'])) {
                return null;
            }

            $alternative = $result['channel']['alternatives'][0] ?? null;
            if (!$alternative) {
                return null;
            }

            return [
                'transcript' => $alternative['transcript'] ?? '',
                'confidence' => $alternative['confidence'] ?? 0,
                'is_final' => $result['is_final'] ?? false,
                'speech_final' => $result['speech_final'] ?? false,
                'duration' => $result['duration'] ?? 0,
            ];
        } catch (Exception $e) {
            Log::error('Error processing Deepgram result', [
                'error' => $e->getMessage(),
                'result' => $result,
            ]);
            return null;
        }
    }

    /**
     * Check if Deepgram is configured and accessible
     */
    public function test(): bool
    {
        try {
            $this->ensureConfigured();

            $response = Http::withHeaders([
                'Authorization' => $this->getAuthHeader(),
            ])->get("{$this->apiUrl}/projects");

            return $response->successful();
        } catch (Exception $e) {
            Log::error('Deepgram test failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
