<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TranscriptionService
{
    protected ?string $openaiApiKey;
    protected ?string $groqApiKey;

    public function __construct()
    {
        $this->openaiApiKey = config('services.openai.api_key', env('OPENAI_API_KEY'));
        $this->groqApiKey = config('services.groq.api_key', env('GROQ_API_KEY'));
    }

    /**
     * Transcribe audio file to French text using Whisper API (OpenAI or Groq).
     */
    public function transcribe(string $audioFilePath): array
    {
        // 1. Try Groq Whisper (ultra-fast transcription) if available
        if (!empty($this->groqApiKey)) {
            try {
                $response = Http::withToken($this->groqApiKey)
                    ->attach('file', file_get_contents($audioFilePath), basename($audioFilePath))
                    ->post('https://api.groq.com/openai/v1/audio/transcriptions', [
                        'model' => 'whisper-large-v3',
                        'language' => 'fr',
                        'prompt' => 'Marché Dantokpa Cotonou Bénin, vente, crédit, bidon d\'huile, sac de riz, carton de savon, francs CFA, FCFA, Maman Chantal, Koffi, restock.',
                        'response_format' => 'verbose_json',
                    ]);

                if ($response->successful()) {
                    $data = $response->json();
                    return [
                        'success' => true,
                        'transcript' => $data['text'] ?? '',
                        'confidence' => 0.95, // Estimated confidence
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning('Groq Whisper failed, fallback to OpenAI: ' . $e->getMessage());
            }
        }

        // 2. Try OpenAI Whisper
        if (!empty($this->openaiApiKey)) {
            try {
                $response = Http::withToken($this->openaiApiKey)
                    ->attach('file', file_get_contents($audioFilePath), basename($audioFilePath))
                    ->post('https://api.openai.com/v1/audio/transcriptions', [
                        'model' => 'whisper-1',
                        'language' => 'fr',
                        'prompt' => 'Vocaux de commerçantes du marché au Bénin, ventes, crédits, dettes, bidons d\'huile, riz, tomates, francs CFA.',
                        'response_format' => 'verbose_json',
                    ]);

                if ($response->successful()) {
                    $data = $response->json();
                    return [
                        'success' => true,
                        'transcript' => $data['text'] ?? '',
                        'confidence' => 0.92,
                    ];
                }
            } catch (\Throwable $e) {
                Log::error('OpenAI Whisper transcription error: ' . $e->getMessage());
            }
        }

        // 3. Fallback / Mock for testing without keys
        return [
            'success' => false,
            'transcript' => null,
            'confidence' => 0.0,
            'error' => 'No valid AI transcription key configured (OPENAI_API_KEY or GROQ_API_KEY)',
        ];
    }
}
