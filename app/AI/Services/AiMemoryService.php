<?php

namespace App\AI\Services;

use App\Models\User;
use App\Models\Todo;
use Illuminate\Support\Facades\DB;
use Throwable;

class AiMemoryService
{
    public function remember(User $user, string $message, string $response): void
    {
        try {
            $signals = $this->signals($message);
            $signals = array_merge($signals, $this->databaseSignals($user));

            if (empty($signals)) {
                return;
            }

            $existing = DB::table('ai_memories')->where('user_id', $user->id)->first();
            $signals = array_values(array_unique($signals));
            $summary = $this->compactSummary($existing?->summary, $signals);
            $summary = mb_substr($summary, -1600);

            DB::table('ai_memories')->updateOrInsert(
                ['user_id' => $user->id],
                [
                    'summary' => $summary,
                    'signals' => json_encode($signals),
                    'last_refreshed_at' => now(),
                    'updated_at' => now(),
                    'created_at' => $existing?->created_at ?? now(),
                ],
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function signals(string $message): array
    {
        $signals = [];
        $lower = strtolower($message);

        foreach (['morning', 'afternoon', 'evening', 'deadline', 'overdue', 'high priority', 'meeting', 'besok', 'hari ini', 'pagi', 'siang', 'sore', 'malam', 'selamat pagi', 'selamat siang', 'selamat sore', 'selamat malam', 'prioritas', 'rapat'] as $signal) {
            if (str_contains($lower, $signal)) {
                $signals[] = $signal;
            }
        }


        if (preg_match('/\b(selamat\s+pagi|pagi|morning)\b/i', $message)) {
            $signals[] = 'user greets or may be active in the morning';
        }
        if (preg_match('/\b(selamat\s+siang|siang|afternoon)\b/i', $message)) {
            $signals[] = 'user greets or may be active around midday';
        }
        if (preg_match('/\b(selamat\s+sore|sore|evening)\b/i', $message)) {
            $signals[] = 'user greets or may be active in the late afternoon';
        }
        if (preg_match('/\b(selamat\s+malam|malam|night)\b/i', $message)) {
            $signals[] = 'user greets or may review tasks at night';
        }

        if (preg_match('/\b(capek|lelah|ngantuk|low\s+energy|tired)\b/i', $message)) {
            $signals[] = 'user sometimes needs low-energy task suggestions';
        }

        if (preg_match('/\b(semangat|fokus|high\s+energy|energized)\b/i', $message)) {
            $signals[] = 'user sometimes wants high-energy priority suggestions';
        }

        if (preg_match('/\b(\d+\s*menit|cuma\s+punya|hanya\s+punya|sebentar|only\s+have)\b/i', $message)) {
            $signals[] = 'user may ask for short-time task recommendations';
        }

        if (preg_match('/\b(maaf|sorry).*(belum bisa|tidak bisa|nggak bisa|ga bisa|gabisa|can(?:not|\'t))\b/i', $message)) {
            $signals[] = 'user may be unavailable now; respond gently and offer to reschedule or save as task';
        }

        if (preg_match('/\b(nanti dulu|later|lagi sibuk|busy|ingatkan|remind me|reminder)\b/i', $message)) {
            $signals[] = 'user mentions being busy or needing reminder/reschedule support';
        }

        if (preg_match('/\b(biasanya|prefer|lebih suka|suka(?:nya)?|sering|habit|kebiasaan)\b/i', $message)) {
            $signals[] = 'user shared a preference or habit; adapt future productivity replies';
        }

        return array_values(array_unique($signals));
    }

    private function compactSummary(?string $existingSummary, array $signals): string
    {
        $parts = [];
        if ($existingSummary) {
            foreach (preg_split('/\.\s*/', $existingSummary) ?: [] as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $parts[] = $part;
                }
            }
        }

        $parts[] = 'Recent user context: ' . implode(', ', $signals);

        return implode('. ', array_slice(array_values(array_unique($parts)), -8)) . '.';
    }

    private function databaseSignals(User $user): array
    {
        $tasks = Todo::query()
            ->where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->limit(80)
            ->get(['deadline', 'priority', 'is_completed']);

        if ($tasks->isEmpty()) {
            return [];
        }

        $signals = [];
        $open = $tasks->where('is_completed', false);
        $now = now();
        $thisMonth = $tasks->filter(fn (Todo $todo) => $todo->deadline && $todo->deadline->betweenIncluded($now->copy()->startOfMonth(), $now->copy()->endOfMonth()));

        $signals[] = 'database context: user has ' . $open->count() . ' open tasks and ' . $tasks->where('is_completed', true)->count() . ' completed tasks in recent data';
        $signals[] = 'current month task mix: high ' . $thisMonth->where('priority', 'high')->count() . ', medium ' . $thisMonth->where('priority', 'medium')->count() . ', low ' . $thisMonth->where('priority', 'low')->count();

        $today = $tasks->filter(fn (Todo $todo) => $todo->deadline && $todo->deadline->betweenIncluded($now->copy()->startOfDay(), $now->copy()->endOfDay()));
        if ($today->isNotEmpty()) {
            $signals[] = 'database context: user has ' . $today->where('is_completed', false)->count() . ' open tasks due today';
        }

        $nearest = $open->filter(fn (Todo $todo) => $todo->deadline && $todo->deadline->gte($now))->sortBy('deadline')->first();
        if ($nearest) {
            $signals[] = 'nearest open task priority is ' . ($nearest->priority ?? 'medium') . ' with deadline ' . $nearest->deadline->format('Y-m-d H:i');
        }

        $dominantPriority = collect(['high', 'medium', 'low'])
            ->mapWithKeys(fn (string $priority) => [$priority => $open->where('priority', $priority)->count()])
            ->sortDesc()
            ->keys()
            ->first();
        if ($dominantPriority) {
            $signals[] = "most common open task priority is {$dominantPriority}";
        }

        $overdue = $open->filter(fn (Todo $todo) => $todo->deadline && $todo->deadline->lt($now))->count();
        if ($overdue > 0) {
            $signals[] = "user has {$overdue} overdue open tasks; prioritize overdue cleanup gently";
        }

        return $signals;
    }
}
