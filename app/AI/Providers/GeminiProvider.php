<?php

namespace App\AI\Providers;

use App\AI\Rotators\GeminiKeyRotator;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiProvider
{
    public function __construct(private GeminiKeyRotator $rotator) {}

    public function generate(string $systemPrompt, string $message): array
    {
        $lastError = null;
        $configuredKeys = config('services.gemini.keys', []);
        $keys = [];
        if (is_array($configuredKeys)) {
            foreach ($configuredKeys as $key) {
                if (is_string($key) && trim($key) !== '') {
                    $keys[] = $key;
                }
            }
        }
        $maxAttempts = count($keys);

        if ($maxAttempts === 0) {
            return ['text' => $this->fallback($message), 'key_hash' => null, 'fallback' => true];
        }

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $key = $this->rotator->next();
            if (!$key) {
                return ['text' => $this->fallback($message), 'key_hash' => null, 'fallback' => true];
            }

            try {
                $model = config('services.gemini.model', 'gemini-1.5-flash');
                $response = Http::timeout(config('services.gemini.timeout', 25))
                    ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}", [
                        'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
                        'contents' => [['role' => 'user', 'parts' => [['text' => $message]]]],
                        'generationConfig' => ['temperature' => 0.35, 'maxOutputTokens' => 700],
                    ]);

                if ($response->successful()) {
                    $text = data_get($response->json(), 'candidates.0.content.parts.0.text');
                    return ['text' => trim($text ?: $this->fallback($message)), 'key_hash' => $this->rotator->hash($key), 'fallback' => false];
                }

                if (in_array($response->status(), [403, 429, 500, 502, 503, 504], true)) {
                    $this->rotator->cooldown($key);
                }
                $lastError = 'Gemini HTTP ' . $response->status();
            } catch (\Throwable $e) {
                $this->rotator->cooldown($key, 90);
                $lastError = $e->getMessage();
            }
        }

        if ($lastError) {
            report(new RuntimeException($lastError));
        }

        return ['text' => $this->fallback($message), 'key_hash' => null, 'fallback' => true];
    }

    private function fallback(string $message): string
    {
        $isIndonesian = preg_match('/\b(aku|kamu|yang|gimana|kenapa|apa|dong|nih|ya|nggak|gak|ga|capek|bingung|tugas|jadwal|cerita|curhat)\b/i', $message) === 1;

        return $isIndonesian
            ? 'Aku lagi susah nyambung ke Gemini sebentar. Coba kirim ulang sekali lagi ya, nanti aku jawab sebagai WUDI dengan lebih natural.'
            : 'WUDI is having trouble reaching Gemini for a moment. Try sending that once more and I’ll answer more naturally.';
    }
}
