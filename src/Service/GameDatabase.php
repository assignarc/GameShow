<?php

declare(strict_types=1);

namespace App\Service;

use PDO;
use Symfony\Component\HttpKernel\KernelInterface;

class GameDatabase
{
    private ?PDO $pdo = null;
    private string $dbPath;
    private string $projectDir;

    public function __construct(KernelInterface $kernel)
    {
        $this->projectDir = $kernel->getProjectDir();
        $this->dbPath = $this->projectDir . '/var/gameshow.db';
    }

    public function getPdo(): PDO
    {
        if ($this->pdo === null) {
            $dir = dirname($this->dbPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            $isNew = !file_exists($this->dbPath) || filesize($this->dbPath) === 0;
            $this->pdo = new PDO('sqlite:' . $this->dbPath);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->pdo->exec('PRAGMA foreign_keys = ON;');
            $this->pdo->exec('PRAGMA journal_mode = WAL;');

            $this->initSchema();
            if ($isNew || $this->getQuestionCount() === 0) {
                $this->seedFromMasterJson();
            }
        }

        return $this->pdo;
    }

    public function initSchema(): void
    {
        $pdo = $this->pdo ?? $this->getPdo();

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    slug TEXT NOT NULL UNIQUE,
    description TEXT NOT NULL DEFAULT '',
    color TEXT NOT NULL DEFAULT '#f59e0b',
    icon TEXT NOT NULL DEFAULT '🧩',
    sort_order INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS questions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    category_id INTEGER NOT NULL REFERENCES categories(id) ON DELETE CASCADE,
    title TEXT NOT NULL DEFAULT '',
    prompt_text TEXT NOT NULL DEFAULT '',
    hint TEXT NOT NULL DEFAULT '',
    answer TEXT NOT NULL,
    alternate_answers TEXT NOT NULL DEFAULT '',
    explanation TEXT NOT NULL DEFAULT '',
    difficulty TEXT NOT NULL DEFAULT 'Medium',
    hilarious_score REAL NOT NULL DEFAULT 9.0,
    media_type TEXT NOT NULL DEFAULT 'image',
    media_path TEXT NOT NULL DEFAULT '',
    original_media_path TEXT NOT NULL DEFAULT '',
    media_config TEXT NOT NULL DEFAULT '{}',
    source TEXT NOT NULL DEFAULT 'Curated',
    is_active INTEGER NOT NULL DEFAULT 1,
    is_favorite INTEGER NOT NULL DEFAULT 0,
    review_status TEXT NOT NULL DEFAULT 'pending',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS duplicate_dismissals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    question_id_a INTEGER NOT NULL,
    question_id_b INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(question_id_a, question_id_b)
);

CREATE TABLE IF NOT EXISTS game_shows (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    subtitle TEXT NOT NULL DEFAULT '',
    description TEXT NOT NULL DEFAULT '',
    default_timer_seconds INTEGER NOT NULL DEFAULT 25,
    max_teams INTEGER NOT NULL DEFAULT 12,
    status TEXT NOT NULL DEFAULT 'setup',
    current_round_id INTEGER DEFAULT NULL,
    current_question_id INTEGER DEFAULT NULL,
    active_matchup_label TEXT NOT NULL DEFAULT '',
    active_team_a_id INTEGER DEFAULT NULL,
    active_team_b_id INTEGER DEFAULT NULL,
    question_started_at INTEGER DEFAULT NULL,
    timer_duration INTEGER NOT NULL DEFAULT 25,
    answer_revealed INTEGER NOT NULL DEFAULT 0,
    starred_only INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS show_teams (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    show_id INTEGER NOT NULL REFERENCES game_shows(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    members TEXT NOT NULL DEFAULT '',
    flight_group TEXT NOT NULL DEFAULT 'Acorn Flight',
    avatar_color TEXT NOT NULL DEFAULT '#f97316',
    avatar_emoji TEXT NOT NULL DEFAULT '🍁',
    score INTEGER NOT NULL DEFAULT 0,
    round_score INTEGER NOT NULL DEFAULT 0,
    is_eliminated INTEGER NOT NULL DEFAULT 0,
    eliminated_in_round INTEGER DEFAULT NULL,
    sort_order INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS show_rounds (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    show_id INTEGER NOT NULL REFERENCES game_shows(id) ON DELETE CASCADE,
    round_number INTEGER NOT NULL DEFAULT 1,
    name TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    round_type TEXT NOT NULL DEFAULT 'all_play',
    timer_seconds INTEGER NOT NULL DEFAULT 25,
    points_per_question INTEGER NOT NULL DEFAULT 10,
    steal_points INTEGER NOT NULL DEFAULT 5,
    advancement_type TEXT NOT NULL DEFAULT 'all',
    advance_top_x INTEGER NOT NULL DEFAULT 5,
    category_allocations TEXT NOT NULL DEFAULT '[]',
    status TEXT NOT NULL DEFAULT 'pending'
);

CREATE TABLE IF NOT EXISTS show_round_questions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    show_id INTEGER NOT NULL REFERENCES game_shows(id) ON DELETE CASCADE,
    round_id INTEGER NOT NULL REFERENCES show_rounds(id) ON DELETE CASCADE,
    question_id INTEGER NOT NULL REFERENCES questions(id) ON DELETE CASCADE,
    sort_order INTEGER NOT NULL DEFAULT 1,
    is_manual INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(show_id, question_id)
);

CREATE TABLE IF NOT EXISTS show_used_questions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    show_id INTEGER NOT NULL REFERENCES game_shows(id) ON DELETE CASCADE,
    round_id INTEGER REFERENCES show_rounds(id) ON DELETE SET NULL,
    question_id INTEGER NOT NULL REFERENCES questions(id) ON DELETE CASCADE,
    category_id INTEGER REFERENCES categories(id) ON DELETE SET NULL,
    awarded_team_id INTEGER REFERENCES show_teams(id) ON DELETE SET NULL,
    points_awarded INTEGER NOT NULL DEFAULT 0,
    asked_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(show_id, question_id)
);

CREATE TABLE IF NOT EXISTS common_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sort_order INTEGER NOT NULL DEFAULT 1,
    title TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    icon TEXT NOT NULL DEFAULT '📜'
);
SQL);

        try {
            $pdo->exec("ALTER TABLE questions ADD COLUMN review_status TEXT NOT NULL DEFAULT 'pending'");
        } catch (\Throwable) {
            // Column already exists
        }

        try {
            $pdo->exec("ALTER TABLE questions ADD COLUMN original_media_path TEXT NOT NULL DEFAULT ''");
            $pdo->exec("UPDATE questions SET original_media_path = media_path WHERE original_media_path = '' OR original_media_path IS NULL");
        } catch (\Throwable) {
            // Column already exists
        }

        try {
            $pdo->exec('ALTER TABLE game_shows ADD COLUMN rules TEXT DEFAULT NULL');
        } catch (\Throwable) {
            // Column already exists
        }

        try {
            $pdo->exec('ALTER TABLE game_shows ADD COLUMN active_rule_index INTEGER NOT NULL DEFAULT -1');
        } catch (\Throwable) {
            // Column already exists
        }

        try {
            $pdo->exec('ALTER TABLE game_shows ADD COLUMN active_team_index INTEGER NOT NULL DEFAULT -1');
        } catch (\Throwable) {
            // Column already exists
        }

        try {
            $pdo->exec('ALTER TABLE game_shows ADD COLUMN starred_only INTEGER NOT NULL DEFAULT 0');
        } catch (\Throwable) {
            // Column already exists
        }

        $this->seedDefaultCommonRules();
    }

    public function getQuestionCount(): int
    {
        return (int) $this->getPdo()->query('SELECT COUNT(*) FROM questions')->fetchColumn();
    }

    public function seedFromMasterJson(): int
    {
        $pdo = $this->getPdo();

        $seedFile = $this->projectDir . '/data/master_questions.json';
        if (!file_exists($seedFile)) {
            return 0;
        }

        $payload = json_decode((string) file_get_contents($seedFile), true);
        if (!is_array($payload)) {
            return 0;
        }

        $pdo->beginTransaction();
        try {
            $catMap = [];
            foreach ($payload['categories'] ?? [] as $idx => $cat) {
                $stmt = $pdo->prepare('INSERT OR IGNORE INTO categories (name, slug, description, color, icon, sort_order) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $cat['name'],
                    $cat['slug'],
                    $cat['description'] ?? '',
                    $cat['color'] ?? '#f59e0b',
                    $cat['icon'] ?? '🧩',
                    $cat['sort_order'] ?? ($idx + 1),
                ]);
                $idStmt = $pdo->prepare('SELECT id FROM categories WHERE slug = ?');
                $idStmt->execute([$cat['slug']]);
                $catMap[$cat['slug']] = (int) $idStmt->fetchColumn();
            }

            $inserted = 0;
            $qStmt = $pdo->prepare(<<<'SQL'
INSERT INTO questions (
    category_id, title, prompt_text, hint, answer, alternate_answers, explanation,
    difficulty, hilarious_score, media_type, media_path, media_config, source, is_active, is_favorite, review_status
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
SQL);

            foreach ($payload['questions'] ?? [] as $q) {
                $catSlug = $q['category_slug'] ?? 'classic-dingbats';
                $catId = $catMap[$catSlug] ?? reset($catMap);
                if (!$catId) {
                    continue;
                }
                $qStmt->execute([
                    $catId,
                    $q['title'] ?? '',
                    $q['prompt_text'] ?? 'Say what you see!',
                    $q['hint'] ?? '',
                    $q['answer'],
                    $q['alternate_answers'] ?? '',
                    $q['explanation'] ?? '',
                    $q['difficulty'] ?? 'Medium',
                    (float) ($q['hilarious_score'] ?? 9.0),
                    $q['media_type'] ?? 'image',
                    $q['media_path'] ?? '',
                    is_array($q['media_config'] ?? null) ? json_encode($q['media_config']) : ($q['media_config'] ?? '{}'),
                    $q['source'] ?? 'Curated',
                    isset($q['is_active']) ? (int) $q['is_active'] : 1,
                    isset($q['is_favorite']) ? (int) $q['is_favorite'] : 0,
                    $q['review_status'] ?? 'pending',
                ]);
                $inserted++;
            }

            // Create Default Sample Show if none exists
            $showCount = (int) $pdo->query('SELECT COUNT(*) FROM game_shows')->fetchColumn();
            if ($showCount === 0 && !empty($catMap)) {
                $this->createDefaultFallPartyShow($catMap);
            }

            $pdo->commit();
            return $inserted;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function createDefaultFallPartyShow(array $catMap): int
    {
        $pdo = $this->getPdo();
        $stmt = $pdo->prepare('INSERT INTO game_shows (name, subtitle, description, default_timer_seconds, max_teams, status) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            'Fall Party Rebus Championship 2026',
            '10 Couples • Acorn & Pumpkin Flights • Say What You See!',
            'Annual couples fall game night featuring illustrated Rebus puzzles, Alphabet Riddles, Catchphrase visual puns, and Championship brain-busters.',
            25,
            12,
            'setup',
        ]);
        $showId = (int) $pdo->lastInsertId();

        $defaultTeams = [
            ['Team Maple & Oak', 'Couple 1', 'Acorn Flight', '#f97316', '🍁'],
            ['The Spice Lattest', 'Couple 2', 'Acorn Flight', '#eab308', '🎃'],
            ['Cider House Rules', 'Couple 3', 'Acorn Flight', '#10b981', '🍎'],
            ['Sweater Weather', 'Couple 4', 'Acorn Flight', '#3b82f6', '🧣'],
            ['Acorn Identity', 'Couple 5', 'Acorn Flight', '#8b5cf6', '🌰'],
            ['Gourd Vibes Only', 'Couple 6', 'Pumpkin Flight', '#ec4899', '🥧'],
            ['Flannel Fury', 'Couple 7', 'Pumpkin Flight', '#ef4444', '🪵'],
            ['Hayride Hustlers', 'Couple 8', 'Pumpkin Flight', '#14b8a6', '🚜'],
            ['Bonfire Bosses', 'Couple 9', 'Pumpkin Flight', '#f59e0b', '🔥'],
            ['Harvest Mooners', 'Couple 10', 'Pumpkin Flight', '#6366f1', '🌕'],
        ];

        $tStmt = $pdo->prepare('INSERT INTO show_teams (show_id, name, members, flight_group, avatar_color, avatar_emoji, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($defaultTeams as $i => $t) {
            $tStmt->execute([$showId, $t[0], $t[1], $t[2], $t[3], $t[4], $i + 1]);
        }

        $allCatIds = array_values($catMap);
        $rounds = [
            [
                'round_number' => 1,
                'name' => 'Round 1: Warm-Up & Alphabet Wordplay',
                'description' => 'Fast-paced illustrated Alphabet riddles and simple visual dingbats to get all couples buzzing in!',
                'round_type' => 'all_play',
                'timer_seconds' => 20,
                'points_per_question' => 10,
                'steal_points' => 5,
                'advancement_type' => 'all',
                'advance_top_x' => 10,
                'allocations' => [
                    ['category_id' => $catMap['alphabet-wordplay'] ?? $allCatIds[0], 'count' => 5, 'difficulty' => 'Simple'],
                    ['category_id' => $catMap['classic-dingbats'] ?? $allCatIds[0], 'count' => 5, 'difficulty' => 'Simple'],
                ],
            ],
            [
                'round_number' => 2,
                'name' => 'Round 2: Flight Round-Robin (Couples & Pop Culture)',
                'description' => 'Head-to-head matchups across Acorn & Pumpkin flights with hilarious couples puns and picture math.',
                'round_type' => 'head_to_head',
                'timer_seconds' => 25,
                'points_per_question' => 15,
                'steal_points' => 10,
                'advancement_type' => 'top_x',
                'advance_top_x' => 5,
                'allocations' => [
                    ['category_id' => $catMap['couples-pop-culture'] ?? $allCatIds[0], 'count' => 6, 'difficulty' => 'Any'],
                    ['category_id' => $catMap['picture-math'] ?? $allCatIds[0], 'count' => 6, 'difficulty' => 'Any'],
                ],
            ],
            [
                'round_number' => 3,
                'name' => 'Round 3: The Final 5 Gauntlet',
                'description' => 'Top 5 surviving couples face tricky spatial & illustrated Rebus puzzles. Bottom 2 couples are eliminated!',
                'round_type' => 'all_play',
                'timer_seconds' => 30,
                'points_per_question' => 20,
                'steal_points' => 10,
                'advancement_type' => 'top_x',
                'advance_top_x' => 3,
                'allocations' => [
                    ['category_id' => $catMap['visual-riddles'] ?? $allCatIds[0], 'count' => 4, 'difficulty' => 'Medium'],
                    ['category_id' => $catMap['classic-dingbats'] ?? $allCatIds[0], 'count' => 4, 'difficulty' => 'Medium'],
                ],
            ],
            [
                'round_number' => 4,
                'name' => 'Round 4: Podium Battle (Top 3 Championship)',
                'description' => 'High-stakes brain-busters to crown the Fall Party Grand Champions!',
                'round_type' => 'all_play',
                'timer_seconds' => 35,
                'points_per_question' => 30,
                'steal_points' => 15,
                'advancement_type' => 'top_x',
                'advance_top_x' => 1,
                'allocations' => [
                    ['category_id' => $catMap['championship-cryptics'] ?? $allCatIds[0], 'count' => 6, 'difficulty' => 'Hard'],
                ],
            ],
        ];

        $rStmt = $pdo->prepare(<<<'SQL'
INSERT INTO show_rounds (
    show_id, round_number, name, description, round_type, timer_seconds,
    points_per_question, steal_points, advancement_type, advance_top_x, category_allocations, status
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
SQL);

        foreach ($rounds as $r) {
            $rStmt->execute([
                $showId,
                $r['round_number'],
                $r['name'],
                $r['description'],
                $r['round_type'],
                $r['timer_seconds'],
                $r['points_per_question'],
                $r['steal_points'],
                $r['advancement_type'],
                $r['advance_top_x'],
                json_encode($r['allocations']),
            ]);
        }

        // Set first round active
        $firstRoundId = (int) $pdo->query("SELECT id FROM show_rounds WHERE show_id = {$showId} ORDER BY round_number ASC LIMIT 1")->fetchColumn();
        if ($firstRoundId) {
            $pdo->prepare('UPDATE game_shows SET current_round_id = ? WHERE id = ?')->execute([$firstRoundId, $showId]);
        }

        return $showId;
    }

    public function exportDatabaseToJson(): array
    {
        $pdo = $this->getPdo();
        $categories = $pdo->query('SELECT * FROM categories ORDER BY sort_order ASC, id ASC')->fetchAll();
        $catSlugById = [];
        foreach ($categories as $c) {
            $catSlugById[$c['id']] = $c['slug'];
        }

        $questions = $pdo->query('SELECT * FROM questions ORDER BY id ASC')->fetchAll();
        foreach ($questions as &$q) {
            $q['category_slug'] = $catSlugById[$q['category_id']] ?? 'classic-dingbats';
            $q['media_config'] = json_decode($q['media_config'] ?: '{}', true);
        }

        return [
            'exported_at' => date('c'),
            'total_categories' => count($categories),
            'total_questions' => count($questions),
            'categories' => $categories,
            'questions' => $questions,
        ];
    }

    public function syncMasterJson(): void
    {
        $seedFile = $this->projectDir . '/data/master_questions.json';
        $data = $this->exportDatabaseToJson();
        file_put_contents(
            $seedFile,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    public function seedDefaultCommonRules(): void
    {
        $pdo = $this->getPdo();
        $count = (int) $pdo->query('SELECT COUNT(*) FROM common_rules')->fetchColumn();
        if ($count > 0) {
            return;
        }

        $defaultRules = [
            ['sort_order' => 1, 'title' => 'Host Has Final Authority', 'description' => "The Host's ruling on pronunciations, buzzers, and close calls is final, binding, and cannot be appealed to any higher court.", 'icon' => '⚖️'],
            ['sort_order' => 2, 'title' => "Host's Wife is Always Right", 'description' => "If the Host and his Wife disagree on an answer, buzzer, or score, Rule #1 is immediately suspended and the Wife's verdict is 100% indisputable.", 'icon' => '👑'],
            ['sort_order' => 3, 'title' => 'In Case of Any Dispute...', 'description' => 'Please refer back to Rule #1. If you are still unsatisfied, proceed directly to Rule #2.', 'icon' => '🔁'],
            ['sort_order' => 4, 'title' => 'Phones Strictly Prohibited (Creative Cheating Allowed)', 'description' => 'Use of smartphones, Google, or AI is strictly forbidden. However, eavesdropping on other teams, lip-reading, or brazenly stealing their guesses is completely allowed!', 'icon' => '📵'],
            ['sort_order' => 5, 'title' => 'Bribing the Host', 'description' => 'Bribing the host with drinks, wine, appetizers, or desserts is strictly against tournament guidelines... but highly encouraged and evaluated on a case-by-case basis.', 'icon' => '🍷'],
        ];

        $stmt = $pdo->prepare('INSERT INTO common_rules (sort_order, title, description, icon) VALUES (?, ?, ?, ?)');
        foreach ($defaultRules as $r) {
            $stmt->execute([$r['sort_order'], $r['title'], $r['description'], $r['icon']]);
        }
    }

    public function getCommonRules(): array
    {
        $pdo = $this->getPdo();
        $this->seedDefaultCommonRules();
        return $pdo->query('SELECT * FROM common_rules ORDER BY sort_order ASC, id ASC')->fetchAll();
    }

    public function saveCommonRule(?int $id, string $title, string $description, string $icon, ?int $sortOrder = null): int
    {
        $pdo = $this->getPdo();
        if ($id && $id > 0) {
            $stmt = $pdo->prepare('UPDATE common_rules SET title = ?, description = ?, icon = ? WHERE id = ?');
            $stmt->execute([$title, $description, $icon, $id]);
            return $id;
        }

        if ($sortOrder === null) {
            $maxOrder = (int) $pdo->query('SELECT MAX(sort_order) FROM common_rules')->fetchColumn();
            $sortOrder = $maxOrder + 1;
        }

        $stmt = $pdo->prepare('INSERT INTO common_rules (sort_order, title, description, icon) VALUES (?, ?, ?, ?)');
        $stmt->execute([$sortOrder, $title, $description, $icon]);
        return (int) $pdo->lastInsertId();
    }

    public function deleteCommonRule(int $id): void
    {
        $pdo = $this->getPdo();
        $pdo->prepare('DELETE FROM common_rules WHERE id = ?')->execute([$id]);
    }

    public function getShowRules(int $showId): array
    {
        $pdo = $this->getPdo();
        $stmt = $pdo->prepare('SELECT rules FROM game_shows WHERE id = ?');
        $stmt->execute([$showId]);
        $raw = (string) $stmt->fetchColumn();

        if ($raw !== '' && $raw !== 'null') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && !empty($decoded)) {
                return $decoded;
            }
        }

        // Inherit from master common rules
        return $this->resetShowRulesToCommon($showId);
    }

    public function resetShowRulesToCommon(int $showId): array
    {
        $common = $this->getCommonRules();
        $rules = [];
        foreach ($common as $idx => $r) {
            $rules[] = [
                'id' => $idx + 1,
                'title' => (string) $r['title'],
                'description' => (string) $r['description'],
                'icon' => (string) ($r['icon'] ?: '📜'),
            ];
        }

        $this->saveShowRules($showId, $rules);
        return $rules;
    }

    public function saveShowRules(int $showId, array $rules): void
    {
        $pdo = $this->getPdo();
        $cleaned = [];
        foreach (array_values($rules) as $idx => $r) {
            $cleaned[] = [
                'id' => $idx + 1,
                'title' => trim((string) ($r['title'] ?? 'Rule ' . ($idx + 1))),
                'description' => trim((string) ($r['description'] ?? '')),
                'icon' => trim((string) ($r['icon'] ?? '📜')) ?: '📜',
            ];
        }

        $json = json_encode($cleaned, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $stmt = $pdo->prepare('UPDATE game_shows SET rules = ? WHERE id = ?');
        $stmt->execute([$json, $showId]);
    }
}

