<?php

namespace App\AI\Rotators;

use Illuminate\Support\Facades\Cache;

class GeminiKeyRotator
{
    public function next(): ?string
    {
        $configuredKeys = config('services.gemini.keys', []);
        $keys = [];
        if (is_array($configuredKeys)) {
            foreach ($configuredKeys as $key) {
                if (is_string($key) && trim($key) !== '') {
                    $keys[] = $key;
                }
            }
        }
        if (empty($keys)) {
            return null;
        }

        $count = count($keys);
        $pointer = (int) Cache::increment('gemini:key:pointer') - 1;
        $start = (($pointer % $count) + $count) % $count;

        for ($i = 0; $i < $count; $i++) {
            $key = $keys[($start + $i) % $count];
            if (!Cache::has($this->cooldownKey($key))) {
                return $key;
            }
        }

        return $keys[$start % $count];
    }

    public function cooldown(string $key, ?int $seconds = null): void
    {
        Cache::put($this->cooldownKey($key), true, now()->addSeconds($seconds ?? config('services.gemini.cooldown_seconds', 300)));
    }

    public function hash(string $key): string
    {
        return substr(hash('sha256', $key), 0, 16);
    }

    private function cooldownKey(string $key): string
    {
        return 'gemini:key:cooldown:' . $this->hash($key);
    }
}
