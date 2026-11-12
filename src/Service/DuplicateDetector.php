<?php

declare(strict_types=1);

namespace App\Service;

use PDO;

class DuplicateDetector
{
    public function __construct(
        private readonly GameDatabase $gameDatabase
    ) {
    }

    /**
     * Normalize an answer string for fuzzy duplicate matching
     */
    public function normalizeAnswer(string $answer): string
    {
        $normalized = mb_strtolower(trim($answer));
        // Remove leading articles
        $normalized = (string) preg_replace('/^(the|a|an)\s+/i', '', $normalized);
        // Replace common shorthand digits/abbreviations
        $normalized = (string) preg_replace('/\b2\b/', 'to', $normalized);
        $normalized = (string) preg_replace('/\b4\b/', 'for', $normalized);
        $normalized = (string) preg_replace('/\bu\b/', 'you', $normalized);
        $normalized = str_replace('&', 'and', $normalized);
        // Remove punctuation
        $normalized = (string) preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $normalized);
        // Collapse spaces
        $normalized = (string) preg_replace('/\s+/', ' ', trim($normalized));

        return trim($normalized);
    }

    /**
     * Light singularization for matching plurals (e.g. eggs -> egg, leftovers -> leftover)
     */
    private function singularize(string $normalized): string
    {
        $words = explode(' ', $normalized);
        $res = [];
        foreach ($words as $w) {
            $len = mb_strlen($w);
            if ($len > 4 && str_ends_with($w, 'ies')) {
                $res[] = substr($w, 0, -3) . 'y';
            } elseif ($len > 4 && str_ends_with($w, 'es') && !str_ends_with($w, 'ees')) {
                $res[] = substr($w, 0, -2);
            } elseif ($len > 3 && str_ends_with($w, 's') && !str_ends_with($w, 'ss')) {
                $res[] = substr($w, 0, -1);
            } else {
                $res[] = $w;
            }
        }

        return implode(' ', $res);
    }

    /**
     * Find all duplicate question groups, excluding those dismissed by the user
     *
     * @return array<int, array{
     *     key: string,
     *     display_answer: string,
     *     count: int,
     *     questions: array<int, array<string, mixed>>
     * }>
     */
    public function findDuplicates(): array
    {
        $pdo = $this->gameDatabase->getPdo();

        // 1. Load dismissed pairs
        $dismissedPairs = [];
        $stmt = $pdo->query('SELECT question_id_a, question_id_b FROM duplicate_dismissals');
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $a = (int) $row['question_id_a'];
            $b = (int) $row['question_id_b'];
            $key = min($a, $b) . '-' . max($a, $b);
            $dismissedPairs[$key] = true;
        }

        // 2. Fetch all active questions with category info
        $sql = <<<'SQL'
            SELECT q.*, c.name AS category_name, c.color AS category_color, c.icon AS category_icon
            FROM questions q
            LEFT JOIN categories c ON q.category_id = c.id
            WHERE q.is_active = 1
            ORDER BY q.id ASC
        SQL;
        $questions = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        // 3. Group questions by normalized answer + fuzzy matching
        /** @var array<int, array{key: string, sing: string, sl: string, display_answer: string, questions: array<int, array<string, mixed>>}> $groups */
        $groups = [];

        foreach ($questions as $q) {
            $norm = $this->normalizeAnswer((string) ($q['answer'] ?? ''));
            if ($norm === '') {
                continue;
            }

            $sing = $this->singularize($norm);
            $sl = str_replace(' ', '', $sing);

            $matchedIndex = null;
            foreach ($groups as $idx => $g) {
                // Exact normalized or singular match
                if ($norm === $g['key'] || $sing === $g['sing']) {
                    $matchedIndex = $idx;
                    break;
                }
                // Spaceless match (e.g. "tuna fish" vs "tunafish", "robin hood" vs "robinhood")
                if ($sl === $g['sl']) {
                    $matchedIndex = $idx;
                    break;
                }
                // Fuzzy match for typos (e.g. "scrambled egg" vs "scambled egg", "itself" vs "iteself")
                $minLen = min(mb_strlen($sing), mb_strlen($g['sing']));
                if ($minLen >= 5) {
                    $dist = levenshtein($sing, $g['sing']);
                    if ($dist <= 1 || ($dist <= 2 && $minLen >= 10)) {
                        $matchedIndex = $idx;
                        break;
                    }
                }
            }

            if ($matchedIndex !== null) {
                $groups[$matchedIndex]['questions'][] = $q;
            } else {
                $groups[] = [
                    'key' => $norm,
                    'sing' => $sing,
                    'sl' => $sl,
                    'display_answer' => (string) $q['answer'],
                    'questions' => [$q],
                ];
            }
        }

        // 4. Filter groups having >= 2 questions and not all dismissed
        $result = [];
        foreach ($groups as $group) {
            $count = count($group['questions']);
            if ($count < 2) {
                continue;
            }

            // Check if there is at least one undismissed pair in this group
            $hasUndismissedPair = false;
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $idA = (int) $group['questions'][$i]['id'];
                    $idB = (int) $group['questions'][$j]['id'];
                    $pairKey = min($idA, $idB) . '-' . max($idA, $idB);
                    if (!isset($dismissedPairs[$pairKey])) {
                        $hasUndismissedPair = true;
                        break 2;
                    }
                }
            }

            if ($hasUndismissedPair) {
                $result[] = [
                    'key' => $group['key'],
                    'display_answer' => $group['display_answer'],
                    'count' => $count,
                    'questions' => $group['questions'],
                ];
            }
        }

        // Sort by group size descending, then by key
        usort($result, function ($a, $b) {
            if ($b['count'] !== $a['count']) {
                return $b['count'] <=> $a['count'];
            }
            return strcmp($a['key'], $b['key']);
        });

        return $result;
    }

    /**
     * Mark a set of question IDs as not duplicates of each other
     *
     * @param array<int> $questionIds
     */
    public function dismissGroup(array $questionIds): void
    {
        $questionIds = array_unique(array_map('intval', $questionIds));
        if (count($questionIds) < 2) {
            return;
        }

        $pdo = $this->gameDatabase->getPdo();
        $stmt = $pdo->prepare('INSERT OR IGNORE INTO duplicate_dismissals (question_id_a, question_id_b) VALUES (?, ?)');

        $n = count($questionIds);
        $ids = array_values($questionIds);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = min($ids[$i], $ids[$j]);
                $b = max($ids[$i], $ids[$j]);
                $stmt->execute([$a, $b]);
            }
        }
    }

    /**
     * Keep one question and delete or exclude the duplicate ones
     *
     * @param int $keepId
     * @param array<int> $otherIds
     * @param string $action 'delete' or 'exclude'
     */
    public function resolveKeep(int $keepId, array $otherIds, string $action = 'delete'): void
    {
        $pdo = $this->gameDatabase->getPdo();
        $otherIds = array_diff(array_unique(array_map('intval', $otherIds)), [$keepId]);

        if (empty($otherIds)) {
            return;
        }

        // Ensure keepId is active
        $stmt = $pdo->prepare('UPDATE questions SET is_active = 1 WHERE id = ?');
        $stmt->execute([$keepId]);

        $placeholders = implode(',', array_fill(0, count($otherIds), '?'));

        if ($action === 'exclude') {
            $stmt = $pdo->prepare("UPDATE questions SET review_status = 'excluded', is_active = 0 WHERE id IN ($placeholders)");
            $stmt->execute($otherIds);
        } else {
            // Delete duplicates
            $stmt = $pdo->prepare("DELETE FROM questions WHERE id IN ($placeholders)");
            $stmt->execute($otherIds);
        }

        // Clean up any dismissals involving deleted IDs if deleted
        if ($action === 'delete') {
            $delStmt = $pdo->prepare("DELETE FROM duplicate_dismissals WHERE question_id_a IN ($placeholders) OR question_id_b IN ($placeholders)");
            $delStmt->execute(array_merge($otherIds, $otherIds));
        }
    }

    /**
     * Get count of duplicate groups currently active
     */
    public function getDuplicateGroupCount(): int
    {
        return count($this->findDuplicates());
    }
}

