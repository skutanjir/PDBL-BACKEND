<?php

namespace App\AI\Services;

use App\Models\Todo;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class AiTaskManagerService
{
    private const CREATE_WORDS = ['create', 'add', 'make', 'bikin', 'buat', 'buatkan', 'buatin', 'tambahkan', 'tambah', 'tambahin'];
    private const EDIT_WORDS = ['edit', 'update', 'ubah', 'rubah', 'ganti', 'change', 'rename', 'reschedule', 'revisi', 'benerin', 'perbaiki', 'perbarui', 'majuin', 'mundurin'];
    private const DELETE_WORDS = ['delete', 'remove', 'hapus', 'apus', 'buang', 'ilangin', 'hilangin'];
    private const COMPLETE_WORDS = ['complete', 'finish', 'done', 'selesai', 'selese', 'beres', 'kelar', 'rampung', 'tuntas', 'udah', 'sudah'];
    private const TASK_WORDS = ['task', 'todo', 'tugas', 'jadwal', 'reminder', 'pengingat'];

    public function apply(User $user, string $message): array
    {
        $text = $this->normalizeCasualText(trim($message));
        $lower = strtolower($text);

        $draftKey = "ai:task:draft:{$user->id}";
        $pendingKey = "ai:task:pending:{$user->id}";
        $draft = Cache::get($draftKey);
        $pending = Cache::get($pendingKey);

        if ($this->isRecommendationOnly($lower)) {
            Cache::forget($pendingKey);
            return ['action' => null];
        }

        if ($pending && preg_match('/(?:^|\b)(?:create|add|make|bikin|bkin|buat|baut|buatkan|bautkan|buatin|tambahkan|tambah|tambahin|tmbh)\s+(?:a\s+)?(?:task|todo|tugas)\s+(.+)/i', $text)) {
            Cache::forget($pendingKey);
            $pending = null;
        }

        if ($pending && preg_match('/\b(cancel|batal|batalkan|stop)\b/i', $text)) {
            Cache::forget($pendingKey);
            return [
                'action' => 'cancelled_task_action',
                'note' => 'Oke, aku batalin dulu ya. Kalau mau lanjut, bilang lagi aja tugasnya mau diapain 👌',
            ];
        }

        if ($pending && ($pending['action'] ?? null) === 'delete_confirm') {
            return $this->continueDeleteConfirmation($user, $pendingKey, $pending, $text);
        }

        if ($pending && ($pending['action'] ?? null) !== 'delete' && $this->isDeleteIntent($lower)) {
            Cache::forget($pendingKey);
            return $this->handleTaskAction($user, $pendingKey, 'delete', $text);
        }

        if ($pending && ($pending['action'] ?? null) !== 'complete' && $this->isCompleteIntent($lower)) {
            Cache::forget($pendingKey);
            return $this->handleTaskAction($user, $pendingKey, 'complete', $text);
        }

        if ($pending) {
            if ($this->isLastTaskReference($text) && !empty($pending['task_id'])) {
                $pending['candidates'] = [$pending['task_id']];
            }

            return $this->continuePendingAction($user, $pendingKey, $pending, $text);
        }

        if ($draft && preg_match('/\b(cancel|batal|batalkan|stop)\b/i', $text)) {
            Cache::forget($draftKey);
            return [
                'action' => 'cancelled_task_draft',
                'note' => 'Oke, draft-nya aku batalin ya. Kalau mau bikin lagi, tinggal sebut judul sama deadlinenya 👌',
            ];
        }

        if ($draft && preg_match('/\b(save|simpan|confirm|konfirmasi|buat sekarang|create now)\b/i', $text)) {
            return $this->createFromDraft($user, $draftKey, $draft);
        }

        if ($draft) {
            $draft = $this->mergeDraft($draft, $text);
            Cache::put($draftKey, $draft, now()->addMinutes(15));

            return [
                'action' => 'draft_task',
                'draft' => $draft,
                'note' => $this->draftPrompt($draft),
            ];
        }

        if (preg_match('/(?:^|\b)(?:create|add|make|bikin|bkin|buat|baut|buatkan|bautkan|buatin|tambahkan|tambah|tambahin|tmbh)\s+(?:a\s+)?(?:task|todo|tugas)(?:\s+pribadi)?\s+(.+)/i', $text, $match)) {
            $rawTitle = trim($match[1]);
            $draft = $this->mergeDraft([
                'title' => $this->extractTitle($rawTitle),
                'description' => null,
                'deadline' => $this->extractDeadline($rawTitle)?->toDateTimeString(),
                'priority' => $this->extractPriority($lower),
            ], $text);

            if (!empty($draft['title']) && !empty($draft['deadline'])) {
                Cache::put($draftKey, $draft, now()->addMinutes(15));
                return $this->createFromDraft($user, $draftKey, $draft);
            }

            Cache::put($draftKey, $draft, now()->addMinutes(15));

            return [
                'action' => 'draft_task',
                'draft' => $draft,
                'note' => $this->draftPrompt($draft),
            ];
        }

        if ($this->hasStructuredTaskFields($text)) {
            $draft = $this->mergeDraft([
                'title' => null,
                'description' => null,
                'deadline' => null,
                'priority' => null,
            ], $text);

            if (!empty($draft['title']) && !empty($draft['deadline'])) {
                Cache::put($draftKey, $draft, now()->addMinutes(15));
                return $this->createFromDraft($user, $draftKey, $draft);
            }

            Cache::put($draftKey, $draft, now()->addMinutes(15));

            return [
                'action' => 'draft_task',
                'draft' => $draft,
                'note' => $this->draftPrompt($draft),
            ];
        }

        if ($this->looksLikeCreateIntent($lower)) {
            $hasTitleField = preg_match('/\b(?:nama(?:nya)?|judul|title)\b/i', $text) === 1;
            $rawTitle = $hasTitleField
                ? $text
                : $this->removeIntentWords($text, array_merge(self::CREATE_WORDS, self::TASK_WORDS, ['pribadi']));
            $draft = $this->mergeDraft([
                'title' => $this->extractTitle($rawTitle),
                'description' => null,
                'deadline' => $this->extractDeadline($text)?->toDateTimeString(),
                'priority' => $this->extractPriority($lower),
            ], $text);

            if (!empty($draft['title']) && !empty($draft['deadline'])) {
                Cache::put($draftKey, $draft, now()->addMinutes(15));
                return $this->createFromDraft($user, $draftKey, $draft);
            }

            Cache::put($draftKey, $draft, now()->addMinutes(15));

            return [
                'action' => 'draft_task',
                'draft' => $draft,
                'note' => $this->draftPrompt($draft),
            ];
        }

        if ($this->isCreateIntent($lower)) {
            $draft = [
                'title' => null,
                'description' => null,
                'deadline' => $this->extractDeadline($text)?->toDateTimeString(),
                'priority' => $this->extractPriority($lower),
            ];
            Cache::put($draftKey, $draft, now()->addMinutes(15));

            return [
                'action' => 'draft_task',
                'draft' => $draft,
                'note' => $this->draftPrompt($draft),
            ];
        }

        if ($this->isCompleteIntent($lower)) {
            return $this->handleTaskAction($user, $pendingKey, 'complete', $text);
        }

        if ($this->isDeleteIntent($lower)) {
            return $this->handleTaskAction($user, $pendingKey, 'delete', $text);
        }

        if ($this->isEditIntent($lower)) {
            return $this->handleTaskAction($user, $pendingKey, 'edit', $text);
        }

        if ($this->isLastTaskReference($text) && ($lastTask = $this->lastReferencedTask($user))) {
            $updates = $this->extractUpdates($text);
            if (!empty($updates)) {
                return $this->updateTask($user, $pendingKey, $lastTask, $updates);
            }
        }

        return ['action' => null];
    }

    private function continuePendingAction(User $user, string $pendingKey, array $pending, string $text): array
    {
        if ($this->isLastTaskReference($text) && !empty($pending['task_id'])) {
            $pending['candidates'] = [$pending['task_id']];
        }

        $todo = $this->selectPendingTask($user, $pending, $text);
        if (!$todo) {
            return $this->askWhichTask($user, $pendingKey, $pending['action'], $pending['candidates'] ?? [], 'Aku belum nangkep yang mana. Pilih nomor task-nya aja ya, atau bilang “batal”.');
        }

        if (($pending['action'] ?? null) === 'edit') {
            $updates = array_merge($pending['updates'] ?? [], $this->extractUpdates($text));
            if (empty($updates)) {
                $pending['task_id'] = $todo->id;
                Cache::put($pendingKey, $pending, now()->addMinutes(10));
                return [
                    'action' => 'clarify_task_edit',
                    'kind' => 'task_action_clarify',
                    'task' => $this->taskPayload($todo),
                    'note' => "Oke, task “{$todo->judul}”. Mau diubah apa nih? Bisa judul, deadline, priority, atau deskripsi 🙂",
                ];
            }

            return $this->updateTask($user, $pendingKey, $todo, $updates);
        }

        if (($pending['action'] ?? null) === 'delete') {
            return $this->confirmDeleteTask($user, $pendingKey, $todo);
        }

        if (($pending['action'] ?? null) === 'complete') {
            return $this->completeTask($user, $pendingKey, $todo);
        }

        Cache::forget($pendingKey);
        return ['action' => null];
    }

    private function handleTaskAction(User $user, string $pendingKey, string $action, string $text): array
    {
        $needle = $this->extractTaskNeedle($text, $action);
        $updates = $action === 'edit' ? $this->extractUpdates($text) : [];
        if ($this->isLastTaskReference($text) && ($lastTask = $this->lastReferencedTask($user))) {
            if ($action === 'edit' && !empty($updates)) {
                return $this->updateTask($user, $pendingKey, $lastTask, $updates);
            }

            $candidates = collect([$lastTask]);
        } else {
            $candidates = $needle ? $this->findUserTasks($user, $needle, 5) : $this->activeTasks($user, 5);
        }

        if ($candidates->isEmpty() && $needle !== '') {
            $candidates = $this->findUserTasksByWords($user, $needle, 5);
        }

        if ($this->isLastTaskReference($text) && ($lastTask = $this->lastReferencedTask($user))) {
            if ($action === 'edit') {
                if (empty($updates)) {
                    Cache::put($pendingKey, ['action' => 'edit', 'task_id' => $lastTask->id, 'candidates' => [$lastTask->id], 'updates' => []], now()->addMinutes(10));
                    return [
                        'action' => 'clarify_task_edit',
                        'kind' => 'task_action_clarify',
                        'task' => $this->taskPayload($lastTask),
                        'note' => "Oke, task “{$lastTask->judul}”. Mau diubah apa nih? Bisa judul, deadline, priority, atau deskripsi 🙂",
                    ];
                }

                return $this->updateTask($user, $pendingKey, $lastTask, $updates);
            }

            return $action === 'delete'
                ? $this->confirmDeleteTask($user, $pendingKey, $lastTask)
                : $this->completeTask($user, $pendingKey, $lastTask);
        }

        if ($needle === '') {
            return $this->askWhichTask($user, $pendingKey, $action, $candidates->pluck('id')->all(), $this->clarifyActionText($action, $candidates->isEmpty()));
        }

        if ($candidates->count() === 1) {
            $todo = $candidates->first();
            $this->rememberTaskReference($user, $todo);
            if ($action === 'edit') {
                if (empty($updates)) {
                    Cache::put($pendingKey, ['action' => 'edit', 'task_id' => $todo->id, 'candidates' => [$todo->id], 'updates' => []], now()->addMinutes(10));
                    return [
                        'action' => 'clarify_task_edit',
                        'kind' => 'task_action_clarify',
                        'task' => $this->taskPayload($todo),
                        'note' => "Ketemu “{$todo->judul}”. Mau edit bagian apa? Judul, deadline, priority, atau deskripsi?",
                    ];
                }

                return $this->updateTask($user, $pendingKey, $todo, $updates);
            }

            return $action === 'delete'
                ? $this->confirmDeleteTask($user, $pendingKey, $todo)
                : $this->completeTask($user, $pendingKey, $todo);
        }

        return $this->askWhichTask($user, $pendingKey, $action, $candidates->pluck('id')->all(), $this->clarifyActionText($action, $candidates->isEmpty()));
    }

    private function askWhichTask(User $user, string $pendingKey, string $action, array $candidateIds, string $note): array
    {
        $tasks = empty($candidateIds)
            ? $this->activeTasks($user, 5)
            : Todo::query()->where('user_id', $user->id)->whereIn('id', $candidateIds)->orderBy('deadline')->get();
        $ids = $tasks->pluck('id')->all();
        Cache::put($pendingKey, ['action' => $action, 'candidates' => $ids, 'updates' => []], now()->addMinutes(10));

        return [
            'action' => 'clarify_task_action',
            'kind' => 'task_candidates',
            'tasks' => $tasks->map(fn (Todo $todo) => $this->taskPayload($todo))->values()->all(),
            'note' => $note . ($tasks->isEmpty() ? '' : "\n\nPilih nomor/nama task-nya aja. Kalau nggak jadi, bilang “batal”."),
        ];
    }

    private function clarifyActionText(string $action, bool $empty): string
    {
        if ($empty) {
            return 'Aku belum nemu task yang cocok. Coba sebut nama task-nya agak lengkap ya.';
        }

        return match ($action) {
            'edit' => 'Yang mau diedit yang mana nih?',
            'delete' => 'Yang mau dihapus yang mana nih?',
            'complete' => 'Yang sudah selesai yang mana nih?',
            default => 'Yang mana nih?',
        };
    }

    private function selectPendingTask(User $user, array $pending, string $text): ?Todo
    {
        $candidateIds = $pending['candidates'] ?? [];
        if (!empty($pending['task_id'])) {
            return Todo::query()->where('user_id', $user->id)->where('id', $pending['task_id'])->first();
        }

        if (count($candidateIds) === 1) {
            return Todo::query()->where('user_id', $user->id)->where('id', $candidateIds[0])->first();
        }

        if (preg_match('/\b(?:nomor|no\.?|number)?\s*(\d{1,2})\b/i', $text, $match)) {
            $index = (int) $match[1] - 1;
            $id = $candidateIds[$index] ?? null;
            if ($id) {
                return Todo::query()->where('user_id', $user->id)->where('id', $id)->first();
            }
        }

        return $this->findUserTask($user, $text);
    }

    private function updateTask(User $user, string $pendingKey, Todo $todo, array $updates): array
    {
        $todo->update($updates);
        Cache::forget($pendingKey);
        Cache::forget("ai:context:{$user->id}");
        $this->rememberTaskReference($user, $todo);

        return [
            'action' => 'updated_task',
            'kind' => 'task_updated',
            'task' => $this->taskPayload($todo->fresh()),
            'note' => "Sip, “{$todo->judul}” sudah aku update ✅",
        ];
    }

    private function deleteTask(User $user, string $pendingKey, Todo $todo): array
    {
        $title = $todo->judul;
        Cache::forget("ai:task:last:{$user->id}");
        $todo->delete();
        Cache::forget($pendingKey);
        Cache::forget("ai:context:{$user->id}");

        return ['action' => 'deleted_task', 'kind' => 'task_deleted', 'note' => "Oke, task “{$title}” sudah aku hapus."];
    }

    private function confirmDeleteTask(User $user, string $pendingKey, Todo $todo): array
    {
        $this->rememberTaskReference($user, $todo);
        Cache::put($pendingKey, [
            'action' => 'delete_confirm',
            'task_id' => $todo->id,
            'candidates' => [$todo->id],
            'updates' => [],
        ], now()->addMinutes(10));

        return [
            'action' => 'confirm_task_delete',
            'kind' => 'task_delete_confirm',
            'task' => $this->taskPayload($todo),
            'note' => "Aku ketemu “{$todo->judul}”. Yakin mau hapus? Balas “ya hapus” untuk konfirmasi, atau “batal” kalau nggak jadi.",
        ];
    }

    private function continueDeleteConfirmation(User $user, string $pendingKey, array $pending, string $text): array
    {
        $todo = $this->selectPendingTask($user, $pending, $text);
        if (!$todo) {
            Cache::forget($pendingKey);
            return ['action' => null];
        }

        if (preg_match('/\b(?:ya\s+hapus|hapus\s+ya|confirm\s+delete|delete\s+it|yes\s+delete|konfirmasi\s+hapus|lanjut\s+hapus)\b/i', $text)) {
            return $this->deleteTask($user, $pendingKey, $todo);
        }

        $this->rememberTaskReference($user, $todo);
        Cache::put($pendingKey, $pending, now()->addMinutes(10));

        return [
            'action' => 'confirm_task_delete',
            'kind' => 'task_delete_confirm',
            'task' => $this->taskPayload($todo),
            'note' => "Biar aman, aku belum hapus “{$todo->judul}”. Kalau yakin, balas “ya hapus”. Kalau nggak jadi, bilang “batal”.",
        ];
    }

    private function completeTask(User $user, string $pendingKey, Todo $todo): array
    {
        $todo->update(['is_completed' => true]);
        Cache::forget($pendingKey);
        Cache::forget("ai:context:{$user->id}");
        $this->rememberTaskReference($user, $todo);

        return ['action' => 'completed_task', 'kind' => 'task_completed', 'task' => $this->taskPayload($todo->fresh()), 'note' => "Sip, “{$todo->judul}” aku tandai selesai ✅"];
    }

    private function normalizeCasualText(string $text): string
    {
        $replacements = [
            '/\bgw\b/i' => 'aku',
            '/\bgue\b/i' => 'aku',
            '/\bgua\b/i' => 'aku',
            '/\byg\b/i' => 'yang',
            '/\bdlu\b/i' => 'dulu',
            '/\bskrg\b/i' => 'sekarang',
            '/\bbsk\b/i' => 'besok',
            '/\bntar\b/i' => 'nanti',
            '/\btdi\b/i' => 'tadi',
            '/\bgmn\b/i' => 'gimana',
            '/\bprioritasin\b/i' => 'prioritaskan',
            '/\bjadwalin\b/i' => 'jadwalkan',
        ];

        return trim(preg_replace(array_keys($replacements), array_values($replacements), $text) ?? $text);
    }

    private function isLastTaskReference(string $text): bool
    {
        return preg_match('/\b(?:ini|itu|tadi|task\s+itu|tugas\s+itu|yang\s+tadi|hapus\s+aja|udah\s+beres|sudah\s+beres|ubah\s+jam|ganti\s+jam)\b/i', $text) === 1;
    }

    private function rememberTaskReference(User $user, Todo $todo): void
    {
        Cache::put("ai:task:last:{$user->id}", $todo->id, now()->addMinutes(30));
    }

    private function lastReferencedTask(User $user): ?Todo
    {
        $id = Cache::get("ai:task:last:{$user->id}");
        if (!$id) {
            return null;
        }

        return Todo::query()->where('user_id', $user->id)->where('id', $id)->first();
    }

    private function isEditIntent(string $lower): bool
    {
        return $this->hasFuzzyWord($lower, self::EDIT_WORDS)
            && preg_match('/\b(code|kode|coding|programming|script|bug|debug)\b/', $lower) !== 1;
    }

    private function isRecommendationOnly(string $lower): bool
    {
        return preg_match('/\b(rekomendasi(?:kan)?|recommend|suggest|saran(?:in)?|konsultasi|enak\s+(?:gimana|mana|apa|dikerjain|dimulai))\b/', $lower) === 1
            && preg_match('/\b(create|add|make|bikin|buat|buatkan|hapus|delete|remove|edit|ubah|update|complete|done|selesai)\b/', $lower) !== 1;
    }

    private function isCreateIntent(string $lower): bool
    {
        return $this->looksLikeCreateIntent($lower);
    }

    private function isDeleteIntent(string $lower): bool
    {
        return $this->hasFuzzyWord($lower, self::DELETE_WORDS)
            && preg_match('/\b(code|kode|coding|programming|script)\b/', $lower) !== 1;
    }

    private function isCompleteIntent(string $lower): bool
    {
        return ($this->hasFuzzyWord($lower, self::COMPLETE_WORDS) || preg_match('/\b(mark\s+(?:as\s+)?done|tandai\s+selesai)\b/', $lower) === 1)
            && preg_match('/\b(code|kode|coding|programming|script)\b/', $lower) !== 1;
    }

    private function looksLikeCreateIntent(string $lower): bool
    {
        return $this->hasFuzzyWord($lower, self::CREATE_WORDS)
            && $this->hasFuzzyWord($lower, self::TASK_WORDS);
    }

    private function extractTaskNeedle(string $text, string $action): string
    {
        if ($action === 'edit' && preg_match('/\b(?:deadline|tanggal|date|jam|pukul|at|priority|prioritas|deskripsi|description|desc|judul|title|nama|namanya)\b/i', $text, $field, PREG_OFFSET_CAPTURE)) {
            $prefix = trim(substr($text, 0, $field[0][1]));
            $prefix = preg_replace('/\b(edit|editt|update|ubah|ubahin|rubah|ganti|gantiin|gnti|change|rename|renam|renamein|reschedule|reskedul|editin|revisi|benerin|bnerin|perbaiki|perbaikin|perbarui|majuin|mundurin|task|todo|tugas|yang|ini|itu|the|my|aku|saya|ku|dong|ya|kak|please|tolong|aja|nih|deh|plis|pls)\b/i', ' ', $prefix) ?? $prefix;
            $prefix = trim(preg_replace('/\s+/', ' ', $prefix) ?? $prefix);
            if ($prefix !== '') {
                return $prefix;
            }
        }

        $clean = preg_replace('/\b(edit|editt|update|ubah|ubahin|rubah|ganti|gantiin|gnti|change|rename|renamein|reschedule|reskedul|editin|revisi|benerin|bnerin|perbaiki|perbaikin|perbarui|jadwalin ulang|jadwalkan ulang|majuin|mundurin|delete|delet|delte|remove|hapus|hpus|apus|apusin|hapusin|buang|ilangin|hilangin|removein|deletein|complete|finish|done|donee|selesai|slesai|selsai|selese|selesein|selesaiin|beres|beress|kelar|kelarin|rampung|tuntas|tuntasin|tandai selesai|mark done|mark as done|task|todo|tugas|yang|ini|itu|the|my|aku|saya|ku|dong|ya|kak|please|tolong|aja|nih|deh|plis|pls)\b/i', ' ', $text) ?? $text;
        $clean = preg_replace('/\b(deadline|tanggal|date|jam|pukul|at|priority|prioritas|high|medium|low|tinggi|sedang|rendah|deskripsi|description|desc|judul|title|nama|namanya|besok|tomorrow|tomorow|tommorow|today|hari ini|jadi|to|ke)\b/i', ' ', $clean) ?? $clean;
        $clean = preg_replace('/\b\d{1,2}(?:[.:]\d{2})?\b/', ' ', $clean) ?? $clean;

        return trim(preg_replace('/\s+/', ' ', $clean) ?? $clean);
    }

    private function hasFuzzyWord(string $text, array $targets): bool
    {
        foreach ($this->words($text) as $word) {
            foreach ($targets as $target) {
                if ($this->isCloseWord($word, $target)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function removeIntentWords(string $text, array $targets): string
    {
        $words = preg_split('/\s+/', $text) ?: [];
        $kept = [];

        foreach ($words as $word) {
            $clean = trim(strtolower($word), " \t\n\r\0\x0B,.;:!?\"'“”");
            $isIntent = false;
            foreach ($targets as $target) {
                if ($this->isCloseWord($clean, $target)) {
                    $isIntent = true;
                    break;
                }
            }

            if (!$isIntent) {
                $kept[] = $word;
            }
        }

        return trim(implode(' ', $kept));
    }

    private function isCloseWord(string $word, string $target): bool
    {
        $word = $this->normalizeWord($word);
        $target = $this->normalizeWord($target);

        if ($word === '' || $target === '') {
            return false;
        }

        if ($word === $target || str_starts_with($word, $target) || str_starts_with($target, $word)) {
            return abs(mb_strlen($word) - mb_strlen($target)) <= 3;
        }

        $maxDistance = mb_strlen($target) <= 4 ? 0 : (mb_strlen($target) <= 5 ? 1 : 2);

        return levenshtein($word, $target) <= $maxDistance;
    }

    private function normalizeWord(string $word): string
    {
        $word = strtolower($word);
        $word = preg_replace('/[^a-z0-9]/', '', $word) ?? $word;
        $word = preg_replace('/(.)\1{2,}/', '$1$1', $word) ?? $word;

        return trim($word);
    }

    private function words(string $text): array
    {
        return array_values(array_filter(
            preg_split('/\s+/', strtolower($text)) ?: [],
            fn (string $word) => mb_strlen($this->normalizeWord($word)) >= 2,
        ));
    }

    private function extractUpdates(string $text): array
    {
        $updates = [];
        $deadline = $this->extractDeadline($text);
        if ($deadline) {
            $updates['deadline'] = $deadline->toDateTimeString();
        }

        $priority = $this->extractPriority(strtolower($text));
        if ($priority) {
            $updates['priority'] = $priority;
        }

        $description = $this->extractField($text, ['deskripsi', 'description', 'desc']);
        if ($description !== null) {
            $updates['deskripsi'] = $description;
        }

        $title = $this->extractField($text, ['judul', 'title', 'nama', 'namanya', 'rename to', 'ganti nama', 'ubah judul']);
        if ($title === null && preg_match('/\b(?:judul|title|nama|namanya|rename\s+to|renam\s+to|ganti\s+nama|ubah\s+judul)\s*(?::|=|jadi|to)?\s*(.+)$/i', $text, $match)) {
            $title = trim($match[1]);
        }

        if ($title !== null) {
            $updates['judul'] = $this->extractTitle($title);
        }

        return $updates;
    }

    private function findUserTask(User $user, string $needle): ?Todo
    {
        return $this->findUserTasks($user, $needle, 1)->first();
    }

    private function findUserTasks(User $user, string $needle, int $limit = 5)
    {
        $needle = trim($needle);
        if ($needle === '') {
            return $this->activeTasks($user, $limit);
        }

        if ($this->isLastTaskReference($needle) && ($lastTask = $this->lastReferencedTask($user))) {
            return collect([$lastTask]);
        }

        return Todo::query()
            ->where('user_id', $user->id)
            ->where('judul', 'ilike', '%' . $needle . '%')
            ->orderBy('is_completed')
            ->orderBy('deadline')
            ->limit($limit)
            ->get();
    }

    private function findUserTasksByWords(User $user, string $needle, int $limit = 5)
    {
        $words = array_values(array_filter(
            preg_split('/\s+/', strtolower($needle)) ?: [],
            fn (string $word) => mb_strlen($word) >= 3 && !in_array($word, ['tomorrow', 'tomorow', 'tommorow', 'today', 'besok', 'pagi', 'siang', 'sore', 'malam', 'nanti', 'lusa'], true),
        ));

        if (empty($words)) {
            return collect();
        }

        return Todo::query()
            ->where('user_id', $user->id)
            ->orderBy('is_completed')
            ->orderBy('deadline')
            ->limit(30)
            ->get()
            ->map(function (Todo $todo) use ($words) {
                $title = strtolower($todo->judul);
                $score = 0;
                foreach ($words as $word) {
                    if (str_contains($title, $word)) {
                        $score++;
                    }
                }

                return ['todo' => $todo, 'score' => $score];
            })
            ->filter(fn (array $item) => $item['score'] >= max(1, count($words) - 1))
            ->sortByDesc('score')
            ->take($limit)
            ->map(fn (array $item) => $item['todo'])
            ->values();
    }

    private function activeTasks(User $user, int $limit = 5)
    {
        return Todo::query()
            ->where('user_id', $user->id)
            ->where('is_completed', false)
            ->orderByRaw('deadline is null')
            ->orderBy('deadline')
            ->limit($limit)
            ->get();
    }

    private function taskPayload(Todo $todo): array
    {
        return [
            'id' => $todo->id,
            'title' => $todo->judul,
            'description' => $todo->deskripsi,
            'deadline' => $todo->deadline?->toDateTimeString(),
            'priority' => $todo->priority,
            'completed' => (bool) $todo->is_completed,
        ];
    }

    private function mergeDraft(array $draft, string $text): array
    {
        $lower = strtolower($text);
        $deadline = $this->extractDeadline($text);
        if ($deadline) {
            $draft['deadline'] = $deadline->toDateTimeString();
        }

        $priority = $this->extractPriority($lower);
        if ($priority) {
            $draft['priority'] = $priority;
        }

        $description = $this->extractField($text, ['deskripsi', 'description', 'desc']);
        if ($description !== null) {
            $draft['description'] = $description;
        }

        $title = $this->extractField($text, ['nama', 'namanya', 'judul', 'title']);
        if ($title !== null) {
            $draft['title'] = $this->extractTitle($title);
        }

        return $draft;
    }

    private function createFromDraft(User $user, string $draftKey, array $draft): array
    {
        if (empty($draft['title'])) {
            return ['action' => 'draft_task', 'draft' => $draft, 'note' => 'Boleh. Judul task-nya apa dulu? Contoh: “judul Coding PHP”. Kalau batal, bilang “batal” ya 🙂'];
        }

        $todo = Todo::create([
            'judul' => $draft['title'],
            'deskripsi' => $draft['description'] ?? null,
            'is_completed' => false,
            'deadline' => $draft['deadline'] ?? null,
            'priority' => $draft['priority'] ?? 'medium',
            'user_id' => $user->id,
            'device_id' => null,
        ]);
        Cache::forget($draftKey);
        Cache::forget("ai:context:{$user->id}");
        $this->rememberTaskReference($user, $todo);

        return [
            'action' => 'created_task',
            'task' => $todo->fresh(),
            'note' => "Siap, “{$todo->judul}” sudah aku buat ✅ Priority: {$todo->priority}" . ($todo->deadline ? ", deadline {$todo->deadline->format('Y-m-d H:i')}" : '') . '.',
        ];
    }

    private function draftPrompt(array $draft): string
    {
        $summary = 'Sip, draft-nya aku siapin:'
            . "\n- Judul: " . ($draft['title'] ?: 'belum ada')
            . "\n- Deskripsi: " . ($draft['description'] ?: 'kosong')
            . "\n- Deadline: " . ($draft['deadline'] ?: 'belum ada')
            . "\n- Priority: " . ($draft['priority'] ?: 'medium');

        $missing = [];
        if (empty($draft['title'])) {
            $missing[] = 'judul';
        }
        if (empty($draft['deadline'])) {
            $missing[] = 'deadline atau tanggal/jam';
        }

        if ($missing) {
            return $summary . "\n\nYang kurang: " . implode(', ', $missing) . '. Lengkapi aja, atau bilang “simpan” kalau ini sudah cukup. Kalau batal, bilang “batal” 🙂';
        }

        return $summary . "\n\nMau langsung aku simpan? Balas “simpan”. Kalau mau ubah, kirim detailnya aja 👌";
    }

    private function extractTitle(string $text): string
    {
        if (preg_match('/["“”\']([^"“”\']+)["“”\']/', $text, $quoted)) {
            return trim($quoted[1]);
        }

        if (preg_match('/\b(?:nama(?:nya)?|judul|title)\s*(?::|=|adalah|is|jadi|dengan)?\s*(.+?)(?=\s*[,;\n]\s*(?:deskripsi|description|desc|deadline|due|tenggat|priority|prioritas)\b|$)/i', $text, $match)) {
            return trim($match[1], " \t\n\r\0\x0B\"'“”");
        }

        $fieldTitle = $this->extractField($text, ['nama', 'namanya', 'judul', 'title']);
        if ($fieldTitle !== null) {
            return trim($fieldTitle);
        }

        $title = trim(preg_replace('/\b(untuk|for|besok|tomorrow|today|hari ini|nanti|malam|pagi|siang|sore|lusa|minggu depan|akhir bulan|deadline|jam|pukul|at|high|medium|low|tinggi|sedang|rendah)\b.*$/i', '', $text));

        return trim($title) ?: trim($text);
    }

    private function hasStructuredTaskFields(string $text): bool
    {
        return $this->extractField($text, ['judul', 'title', 'nama', 'namanya']) !== null
            && ($this->extractField($text, ['deadline', 'due', 'tenggat']) !== null || $this->extractDeadline($text) !== null);
    }

    private function extractField(string $text, array $labels): ?string
    {
        $labelPattern = implode('|', array_map(fn (string $label) => preg_quote($label, '/'), $labels));
        $stopPattern = 'judul|title|nama|namanya|deskripsi|description|desc|deadline|due|tenggat|priority|prioritas';

        if (!preg_match('/(?:^|[,;\n])\s*(?:' . $labelPattern . ')\s*(?::|=|adalah|is|jadi|dengan)?\s*(.+?)(?=\s*[,;\n]\s*(?:' . $stopPattern . ')\s*(?::|=|adalah|is|jadi|dengan)?|$)/i', $text, $match)) {
            return null;
        }

        $value = trim($match[1], " \t\n\r\0\x0B\"'“”");

        return $value === '' ? null : $value;
    }

    private function extractPriority(string $lower): ?string
    {
        if (str_contains($lower, 'high') || str_contains($lower, 'tinggi')) {
            return 'high';
        }
        if (str_contains($lower, 'low') || str_contains($lower, 'rendah')) {
            return 'low';
        }
        if (str_contains($lower, 'medium') || str_contains($lower, 'sedang')) {
            return 'medium';
        }

        return null;
    }

    private function extractDeadline(string $text): ?Carbon
    {
        try {
            if (preg_match('/\b(?:(?:jam|pukul|at)\s*)?(\d{1,2})[.:](\d{2})\b/i', $text, $time)) {
                $base = $this->dateBase($text);

                return $base->setTime((int) $time[1], (int) $time[2]);
            }

            if (preg_match('/\b(?:jam|pukul)\s*(\d{1,2})(?::(\d{2}))?\s*(pagi|siang|sore|malam)?\b/i', $text, $time)) {
                $base = $this->dateBase($text);
                $hour = (int) $time[1];
                $period = strtolower($time[3] ?? '');
                if (in_array($period, ['sore', 'malam'], true) && $hour < 12) {
                    $hour += 12;
                }

                return $base->setTime($hour, (int) ($time[2] ?? 0));
            }
            if (preg_match('/\b(\d{1,2})\s*(am|pm)\b/i', $text, $time)) {
                $base = $this->dateBase($text);
                $hour = (int) $time[1] + (strtolower($time[2]) === 'pm' && (int) $time[1] < 12 ? 12 : 0);
                return $base->setTime($hour, 0);
            }

            $base = $this->dateBase($text);
            if (preg_match('/\b(?:nanti\s+malam|malam\s+ini)\b/i', $text)) {
                return $base->setTime(20, 0);
            }
            if (preg_match('/\b(?:besok\s+pagi|tomorrow\s+morning)\b/i', $text)) {
                return $base->setTime(8, 0);
            }
            if (preg_match('/\b(?:besok\s+siang)\b/i', $text)) {
                return $base->setTime(13, 0);
            }
            if (preg_match('/\b(?:besok\s+sore)\b/i', $text)) {
                return $base->setTime(16, 0);
            }
            if (preg_match('/\b(?:besok\s+malam)\b/i', $text)) {
                return $base->setTime(20, 0);
            }
            if (preg_match('/\b(tomorrow|tomorow|tommorow|besok)\b/i', $text)) {
                return $base->setTime(20, 0);
            }
            if (preg_match('/\b(lusa)\b/i', $text)) {
                return $base->setTime(20, 0);
            }
            if (preg_match('/\b(today|hari ini)\b/i', $text)) {
                return $base->setTime(20, 0);
            }
            if (preg_match('/\b(?:minggu\s+depan|next\s+week)\b/i', $text)) {
                return $base->setTime(20, 0);
            }
            if (preg_match('/\b(?:akhir\s+bulan|end\s+of\s+month)\b/i', $text)) {
                return $base->setTime(20, 0);
            }
            if (preg_match('/\b(senin|selasa|rabu|kamis|jumat|jum\'at|sabtu|minggu)\s+depan\b/i', $text)) {
                return $base->setTime(20, 0);
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function dateBase(string $text): Carbon
    {
        $now = now();
        if (preg_match('/\b(tomorrow|tomorow|tommorow|besok)\b/i', $text)) {
            return $now->copy()->addDay();
        }
        if (preg_match('/\blusa\b/i', $text)) {
            return $now->copy()->addDays(2);
        }
        if (preg_match('/\b(?:minggu\s+depan|next\s+week)\b/i', $text)) {
            return $now->copy()->addWeek();
        }
        if (preg_match('/\b(?:akhir\s+bulan|end\s+of\s+month)\b/i', $text)) {
            return $now->copy()->endOfMonth();
        }
        if (preg_match('/\b(senin|selasa|rabu|kamis|jumat|jum\'at|sabtu|minggu)\s+depan\b/i', $text, $match)) {
            $days = [
                'senin' => Carbon::MONDAY,
                'selasa' => Carbon::TUESDAY,
                'rabu' => Carbon::WEDNESDAY,
                'kamis' => Carbon::THURSDAY,
                'jumat' => Carbon::FRIDAY,
                'jum\'at' => Carbon::FRIDAY,
                'sabtu' => Carbon::SATURDAY,
                'minggu' => Carbon::SUNDAY,
            ];
            return $now->copy()->next($days[strtolower($match[1])] ?? Carbon::MONDAY);
        }

        return $now->copy();
    }
}
