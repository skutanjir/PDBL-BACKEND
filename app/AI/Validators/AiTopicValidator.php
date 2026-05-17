<?php

namespace App\AI\Validators;

class AiTopicValidator
{
    private const CODING_WORDS_PATTERN = '/\b(code|kode|coding|programming|script|function|class|debug|bug|github|repository|javascript|python|java|html|css|sql|ngoding|koding|program|skrip|fungsi|repo|php)\b/';

    private const PROMPT_INJECTION_PATTERNS = [
        '/\b(ignore|disregard|forget|override|bypass|skip|disable|remove|break)\s+(?:all\s+|the\s+|your\s+|previous\s+|prior\s+|above\s+)*(?:instructions?|rules?|directives?|guidelines?|constraints?|restrictions?|safety|policy|policies|guardrails?|filters?)\b/',
        '/\b(?:stop|do\s+not|don\'t)\s+(?:following|obeying|using)\s+(?:the\s+|your\s+|all\s+)*(?:instructions?|rules?|guidelines?|constraints?|restrictions?|policy|policies|guardrails?)\b/',
        '/\b(?:system|developer|admin|root)\s*(?:override|message|prompt|instruction|mode|command)\b/',
        '/\b(?:you\s+are\s+now|act\s+as|pretend\s+to\s+be|roleplay\s+as|simulate)\s+(?:an?\s+)?(?:unrestricted|uncensored|unfiltered|jailbroken|developer\s+mode|dan|do\s+anything\s+now|root|admin)\b/',
        '/\b(?:dan|do\s+anything\s+now|developer\s+mode|jailbreak\s+mode|god\s+mode|evil\s+confidant|grandmother\s+trick|token\s+smuggling)\b/',
        '/\b(?:show|print|output|repeat|reveal|disclose|expose|leak|dump|extract|share|tell\s+me|summarize|tldr|translate|encode|decode)\s+(?:the\s+|your\s+|all\s+)*(?:system|developer|hidden|internal|initial|original|secret|confidential)?\s*(?:prompt|instructions?|directives?|rules?|policy|policies|configuration|config|env|\.env|secrets?|tokens?|api\s*keys?|keys?)\b/',
        '/\b(?:what\s+(?:is|are)|list|describe)\s+(?:the\s+|your\s+)*(?:system|developer|hidden|internal|initial|original|secret|backend)?\s*(?:prompt|instructions?|directives?|rules?|constraints?|tools?|apis?|functions?|endpoints?|configuration|config|env|\.env|secrets?|tokens?|api\s*keys?|keys?)\b/',
        '/\b(?:what\s+is\s+written|repeat\s+what\s+is\s+above|above\s+instructions?|previous\s+instructions?|hidden\s+instructions?)\b/',
        '/\b(?:send|post|append|include|open|fetch|call)\s+(?:it|them|secrets?|prompt|instructions?|tokens?|keys?)\s+(?:to|in|inside|through)\s+https?:\/\//',
        '/https?:\/\/[^\s]+(?:system_prompt|prompt|secret|token|api[_-]?key|env|password|instruction)/',
        '/\b(?:abaikan|lupakan|langgar|bypass|lewati|nonaktifkan|matikan)\s+(?:semua\s+|instruksi\s+|aturan\s+|batasan\s+|kebijakan\s+)*(?:sebelumnya|sistem|developer|aturan|instruksi|batasan|filter|guardrail)\b/',
        '/\b(?:tampilkan|cetak|print|bocorkan|ungkap|kasih\s+tahu|bagikan|keluarkan)\s+(?:prompt|instruksi|aturan|rahasia|token|api\s*key|kunci\s*api|\.env|konfigurasi|config|link\s+backend|url\s+backend)\b/',
        '/\b(?:ignroe|iggnore|disregad|prevoius|previos|systme|sistem\s+promt|revael|revele|jialbreak)\b/',
        '/<\/?(?:system|developer|instructions?|override|prompt|rules?)\b[^>]*>/i',
        '/^\s*(?:system|developer|admin|root|override|ignore)\s*:/m',
    ];

    private const CROSS_USER_LOOKUP_PATTERNS = [
        '/\b(?:check|cek|lihat|tampilkan|show|find|cari|lookup|ambil|read|akses|access|query|summarize|ringkas)\b.*\b(?:database|db|user|pengguna|orang\s+lain|akun\s+lain|email|nama|name|id)\b/',
        '/\b(?:task|tasks|todo|todos|tugas|kerjaan)\b.*\b(?:user|pengguna|orang\s+lain|akun\s+lain|email)\b/',
        '/\b(?:user|pengguna|email)\b.*\b(?:task|tasks|todo|todos|tugas|kerjaan|database|db)\b/',
        '/\b(?:milik|punya|belongs\s+to|owned\s+by|user\s+bernama|pengguna\s+bernama|atas\s+nama)\s+[a-z][a-z\s]{2,}\b.*\b(?:task|tasks|todo|todos|tugas|database|db|deadline|priority|prioritas)\b/',
        '/\b(?:task|tasks|todo|todos|tugas|database|db|deadline|priority|prioritas)\b.*\b(?:milik|punya|belongs\s+to|owned\s+by|user\s+bernama|pengguna\s+bernama|atas\s+nama)\s+[a-z][a-z\s]{2,}\b/',
        '/\b(?:id|user\s*id|task\s*id|todo\s*id)\s*[:#-]?\s*\d{2,}\b.*\b(?:task|tasks|todo|todos|tugas|database|db|email|nama|name|user|pengguna)\b/',
        '/\b[\w.+-]+@[\w-]+(?:\.[\w-]+)+\b.*\b(?:task|tasks|todo|todos|tugas|database|db|deadline|priority|prioritas|jadwal)\b/',
        '/\b(?:task|tasks|todo|todos|tugas|deadline|priority|prioritas|jadwal)\b.*\b[\w.+-]+@[\w-]+(?:\.[\w-]+)+\b/',
    ];

    private const ALLOWED = [
        'task', 'todo', 'deadline', 'due', 'priority', 'prioritize', 'schedule', 'today', 'tomorrow',
        'overdue', 'complete', 'completed', 'unfinished', 'rename', 'delete', 'create', 'edit', 'move',
        'remind', 'productivity', 'plan', 'focus', 'meeting', 'work', 'workflow', 'habit', 'start with',
        'tugas', 'jadwal', 'deadline', 'tenggat', 'prioritas', 'hari ini', 'besok', 'terlambat',
        'lewat deadline', 'selesai', 'belum selesai', 'ubah', 'ganti', 'hapus', 'buat', 'tambahkan',
        'pindahkan', 'ingatkan', 'produktivitas', 'rencana', 'fokus', 'rapat', 'kerja', 'kebiasaan',
        'task ku', 'taskku', 'task saya', 'tugas ku', 'tugasku', 'tugas saya', 'apa aja', 'daftar task', 'daftar tugas',
        'mepet', 'telat', 'kelewat', 'ketinggalan', 'urgent', 'duluan', 'mulai dari mana', 'urutin',
        'urutkan', 'progres', 'progress', 'rekap', 'overview', 'agenda', 'kerjaan', 'beres', 'kelar', 'rampung',
        'edit dong', 'ubah dong', 'hapus dong', 'delete it', 'remove it', 'udah beres', 'sudah beres', 'selesein', 'selesaiin',
        'gantiin', 'ubahin', 'apusin', 'hapusin', 'kelarin', 'tuntasin', 'buatin', 'tambahin',
        'maaf', 'sorry', 'belum bisa', 'nanti dulu', 'lagi sibuk', 'tidak bisa sekarang', 'nggak bisa sekarang',
        'gabisa sekarang', 'ga bisa sekarang', 'ingat', 'remember', 'biasanya', 'prefer', 'lebih suka',
    ];

    private const BLOCKED = [
        'politic', 'movie', 'game', 'joke', 'song', 'code', 'programming', 'recipe', 'celebrity',
        'weather', 'news', 'sport', 'dating', 'story', 'essay', 'homework',
        'politik', 'film', 'permainan', 'lelucon', 'lagu', 'coding', 'resep', 'artis', 'cuaca',
        'berita', 'olahraga', 'pacaran', 'cerita', 'esai',
    ];

    private const SENSITIVE = [
        'api key', 'apikey', 'api_key', 'gemini key', 'secret', 'token', 'password', '.env', 'env file',
        'backend url', 'backend link', 'base url', 'server url', 'endpoint', 'source code', 'code sendiri',
        'kode kamu', 'kode aplikasi', 'system prompt', 'developer prompt', 'prompt system', 'instruction',
        'ignore previous', 'abaikan instruksi', 'jailbreak', 'bypass', 'leak', 'bocorkan', 'rahasia',
        'kunci api', 'link backend', 'url backend', 'kode sumber', 'instruksi sistem', 'prompt rahasia',
        'code', 'coding', 'programming', 'source', 'script', 'function', 'class', 'debug', 'bug', 'compile',
        'repository', 'github', 'laravel code', 'flutter code', 'dart code', 'php code', 'javascript',
        'python', 'java', 'html', 'css', 'sql', 'ngoding', 'koding', 'program', 'skrip', 'fungsi',
        'kelas', 'debugging', 'perbaiki bug', 'buatkan kode', 'tulis kode', 'repo',
    ];

    private const GREETINGS = [
        'hi', 'hello', 'hey', 'halo', 'hallo', 'helo', 'hai', 'hy', 'yo', 'sup', 'pagi', 'siang', 'sore', 'malam',
        'met pagi', 'met siang', 'met sore', 'met malam', 'morning', 'good morning', 'afternoon', 'good afternoon', 'evening', 'good evening', 'night', 'good night',
        'selamat pagi', 'selamat siang', 'selamat sore', 'selamat malam', 'assalamualaikum', 'assalamu alaikum', 'assalamualaikum wr wb',
    ];

    public function isAllowed(string $message): bool
    {
        $text = $this->normalize($message);
        if ($this->isGreeting($text)) {
            return true;
        }

        if ($this->isCapabilityQuestion($text)) {
            return true;
        }

        if ($this->hasTaskActionShape($text)) {
            return true;
        }

        if ($this->isSensitiveRequest($text)) {
            return false;
        }

        foreach (self::ALLOWED as $word) {
            if (str_contains($text, $word)) {
                return true;
            }
        }

        foreach (self::BLOCKED as $word) {
            if (str_contains($text, $word)) {
                return false;
            }
        }

        return false;
    }

    public function isSensitiveRequest(string $message): bool
    {
        $text = $this->normalize($message);
        $hasSecretIntent = false;
        $hasCodingIntent = false;

        if ($this->hasPromptInjectionIntent($text)) {
            return true;
        }

        if ($this->hasCrossUserLookupIntent($text)) {
            return true;
        }

        foreach (self::SENSITIVE as $word) {
            if ($this->containsPhrase($text, $word)) {
                if (preg_match(self::CODING_WORDS_PATTERN, $word)) {
                    $hasCodingIntent = true;
                } else {
                    $hasSecretIntent = true;
                }
            }
        }

        if ($hasSecretIntent) {
            return true;
        }

        if (!$hasCodingIntent) {
            return false;
        }

        if ($this->isTaskManagementIntent($text) && !$this->isDirectCodingHelp($text)) {
            return false;
        }

        return true;
    }

    private function isTaskManagementIntent(string $text): bool
    {
        return preg_match('/\b(task|todo|create task|add task|buat task|buat tugas|tambahkan task|tambahkan tugas|jadwalkan|deadline|prioritas)\b/', $text) === 1;
    }

    private function isDirectCodingHelp(string $text): bool
    {
        return preg_match('/\b(buatkan code|buatkan kode|tulis code|tulis kode|write code|generate code|debug|debugging|perbaiki bug|fix bug|jelaskan kode|explain code|implementasikan|implement)\b/', $text) === 1;
    }

    private function hasPromptInjectionIntent(string $text): bool
    {
        foreach (self::PROMPT_INJECTION_PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    private function hasCrossUserLookupIntent(string $text): bool
    {
        foreach (self::CROSS_USER_LOOKUP_PATTERNS as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    private function containsPhrase(string $text, string $phrase): bool
    {
        $phrase = preg_quote($phrase, '/');

        return preg_match('/(?<![a-z0-9_])' . $phrase . '(?![a-z0-9_])/i', $text) === 1;
    }

    private function normalize(string $message): string
    {
        $text = strtolower($message);
        $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{2060}]/u', '', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }

    public function isGreeting(string $message): bool
    {
        $text = trim(strtolower(preg_replace('/[^a-zA-Z\s]/', '', $message)));
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return in_array($text, self::GREETINGS, true)
            || preg_match('/^(hi|hello|hey|halo|hallo|helo|hai|hy|yo|sup|pagi|siang|sore|malam|met pagi|met siang|met sore|met malam|morning|good morning|afternoon|good afternoon|evening|good evening|night|good night|selamat pagi|selamat siang|selamat sore|selamat malam|assalamualaikum|assalamu alaikum)\b/', $text) === 1;
    }

    public function isCapabilityQuestion(string $message): bool
    {
        $text = $this->normalize($message);

        return preg_match('/\b(?:apa\s+saja|apa\s+aja|bisa\s+apa|(?:kamu|kau|wudi|ai(?:nya)?)\s+(?:bisa\s+apa|punya\s+fitur\s+apa|fitur(?:nya)?\s+apa)|fitur(?:nya)?\s+(?:apa|apa\s+aja|apa\s+saja)|what\s+can\s+you\s+do|what\s+do\s+you\s+do|how\s+can\s+you\s+help|capabilities)\b/', $text) === 1;
    }

    private function hasTaskActionShape(string $text): bool
    {
        $hasTaskWord = preg_match('/\b(task|todo|tugas|jadwal|reminder|pengingat)\b/', $text) === 1;
        $hasTaskAttribute = preg_match('/\b(deadline|priorit|priority|judul|title|nama|deskripsi|description|desc|selesai|done|beres|hapus|delete|remove|edit|ubah|ganti|update)\b/', $text) === 1;
        $hasActionishWord = preg_match('/\b(bu\w*|bi\w*|ta\w*|cre\w*|add\w*|mak\w*|ed\w*|up\w*|ub\w*|ga\w*|cha\w*|ren\w*|res\w*|ha\w*|ap\w*|del\w*|rem\w*|com\w*|fin\w*|don\w*|sel\w*|kel\w*|tun\w*)\b/', $text) === 1;

        return $hasActionishWord && ($hasTaskWord || $hasTaskAttribute);
    }

    public function greetingResponse(string $message): string
    {
        $text = $this->normalize($message);
        $period = $this->greetingPeriod($text);

        if (!$this->isMostlyEnglish($message)) {
            return match ($period) {
                'morning' => 'Selamat pagi 👋 Mau aku bantu cek agenda pagi ini, deadline mepet, atau prioritas hari ini?',
                'afternoon' => 'Selamat siang 👋 Mau aku bantu lihat progress hari ini atau urutin task yang paling penting?',
                'evening' => 'Selamat sore 👋 Mau review sisa task hari ini atau cek deadline yang perlu dikejar?',
                'night' => 'Selamat malam 👋 Mau aku bantu rangkum task hari ini atau siapin prioritas buat besok?',
                default => 'Haii 👋 mau mulai dari mana nih, cek deadline yang mepet atau urutin prioritas hari ini?',
            };
        }

        return match ($period) {
            'morning' => 'Good morning 👋 Want me to check today’s agenda, urgent deadlines, or top priorities?',
            'afternoon' => 'Good afternoon 👋 Want a quick progress check or priority sort for today?',
            'evening' => 'Good evening 👋 Want to review remaining tasks or check what deadline needs attention?',
            'night' => 'Good night 👋 Want me to summarize today or prep tomorrow’s priorities?',
            default => 'Hey 👋 want me to check what’s urgent, overdue, or worth doing first today?',
        };
    }

    private function greetingPeriod(string $text): ?string
    {
        if (preg_match('/\b(?:pagi|morning)\b/', $text)) {
            return 'morning';
        }
        if (preg_match('/\b(?:siang|afternoon)\b/', $text)) {
            return 'afternoon';
        }
        if (preg_match('/\b(?:sore|evening)\b/', $text)) {
            return 'evening';
        }
        if (preg_match('/\b(?:malam|night)\b/', $text)) {
            return 'night';
        }

        $hour = (int) now()->format('H');
        if ($hour >= 4 && $hour < 11) {
            return 'morning';
        }
        if ($hour >= 11 && $hour < 15) {
            return 'afternoon';
        }
        if ($hour >= 15 && $hour < 18) {
            return 'evening';
        }

        return 'night';
    }

    public function rejection(string $message = ''): string
    {
        if ($this->isMostlyEnglish($message)) {
            return 'I’m down to keep it chill, but I can only help around tasks, deadlines, schedules, and priorities. Want me to sort what’s urgent today? ✨';
        }

        return 'Bisa santai kok, cuma aku tetap fokusnya ke task, deadline, jadwal, sama prioritas ya. Mau aku bantu cari yang paling urgent dulu? ✨';
    }

    public function sensitiveRejection(string $message): string
    {
        $text = $this->normalize($message);
        if (preg_match(self::CODING_WORDS_PATTERN, $text) && !$this->hasPromptInjectionIntent($text)) {
            if ($this->isMostlyEnglish($message)) {
                return 'I can’t do the coding part, but I can help turn it into a clean task plan, milestones, or priority list 🧩';
            }

            return 'Kalau bagian coding-nya aku nggak bisa bantu langsung, tapi bisa banget aku bantu jadiin task, pecah jadi step kecil, atau susun prioritasnya 🧩';
        }

        if ($this->hasCrossUserLookupIntent($text)) {
            if ($this->isMostlyEnglish($message)) {
                return 'I can only access your own tasks. I can’t check another user’s database, email, name, or task ID, but I can summarize your tasks instead 🔒';
            }

            return 'Aku cuma bisa akses task milik akun kamu sendiri. Aku nggak bisa cek database, email, nama, atau ID task milik user lain ya 🔒';
        }

        if (preg_match('/\b(api|key|backend|kode|rahasia|bocor|env|prompt|instruksi)\b/', $text)) {
            if ($this->isMostlyEnglish($message)) {
                return 'Can’t share private stuff like keys, backend links, prompts, or internal config. But I can still help with your tasks, deadlines, and priorities 🔒';
            }

            return 'Yang private kayak API key, link backend, prompt, atau config internal nggak bisa aku bagi ya. Tapi buat cek deadline, rangkum task, atau susun prioritas, gas 🔒';
        }

        if ($this->isMostlyEnglish($message)) {
            return 'Can’t help with bypasses, hidden instructions, or secrets. If you want, I can help organize the task side of it instead 🔒';
        }

        return 'Kalau bypass, instruksi tersembunyi, atau rahasia internal aku nggak bisa bantu. Tapi kalau mau dirapihin jadi task atau prioritas, bisa banget 🔒';
    }

    private function isMostlyEnglish(string $message): bool
    {
        $text = strtolower($message);

        return preg_match('/\b(what|which|when|how|why|please|tell|show|summarize|deadline|task|tasks|priority|prioritize|schedule|today|tomorrow|overdue|create|delete|complete|movie|joke|game|recipe|story|essay|weather|news|sport|good\s+morning|good\s+afternoon|good\s+evening|good\s+night|morning|afternoon|evening|night)\b/', $text) === 1
            && preg_match('/\b(apa|yang|kapan|gimana|tolong|tampilkan|ringkas|tugas|prioritas|jadwal|hari ini|besok|terlambat|buat|hapus|selesai)\b/', $text) !== 1;
    }
}
