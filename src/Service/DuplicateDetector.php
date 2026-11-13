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
        // Remove leading articles and conversational fillers
        $normalized = (string) preg_replace('/^(the|a|an|but|to be|it is|its|it\'s|that is|thats|that\'s)\s+/i', '', $normalized);
        // Replace common shorthand digits/abbreviations/homophones
        $normalized = (string) preg_replace('/\b1\b/', 'one', $normalized);
        $normalized = (string) preg_replace('/\b2\b/', 'to', $normalized);
        $normalized = (string) preg_replace('/\btwo\b/', 'to', $normalized);
        $normalized = (string) preg_replace('/\btoo\b/', 'to', $normalized);
        $normalized = (string) preg_replace('/\b4\b/', 'for', $normalized);
        $normalized = (string) preg_replace('/\bfour\b/', 'for', $normalized);
        $normalized = (string) preg_replace('/\b1st\b/', 'first', $normalized);
        $normalized = (string) preg_replace('/\b2nd\b/', 'second', $normalized);
        $normalized = (string) preg_replace('/\b3rd\b/', 'third', $normalized);
        $normalized = (string) preg_replace('/\bu\b/', 'you', $normalized);
        $normalized = str_replace('&', 'and', $normalized);
        // Remove punctuation
        $normalized = (string) preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $normalized);
        // Collapse spaces
        $normalized = (string) preg_replace('/\s+/', ' ', trim($normalized));

        return trim($normalized);
    }

    /**
     * Stem a single word for inflection/root matching
     */
    private function stemWord(string $w): string
    {
        if (in_array($w, ['misunderstood', 'misunderstanding', 'misunderstand'], true)) {
            return 'misunderstand';
        }
        if (in_array($w, ['forgive', 'forgiveness', 'forgiving'], true)) {
            return 'forgive';
        }
        if (in_array($w, ['indian', 'india'], true)) {
            return 'india';
        }

        $len = mb_strlen($w);
        if ($len > 5 && str_ends_with($w, 'ing')) {
            return substr($w, 0, -3);
        }
        if ($len > 4 && str_ends_with($w, 'ed') && !str_ends_with($w, 'eed')) {
            return substr($w, 0, -2);
        }
        if ($len > 4 && str_ends_with($w, 'ies')) {
            return substr($w, 0, -3) . 'y';
        }
        if ($len > 4 && str_ends_with($w, 'es') && !str_ends_with($w, 'ees')) {
            return substr($w, 0, -2);
        }
        if ($len > 3 && str_ends_with($w, 's') && !str_ends_with($w, 'ss')) {
            return substr($w, 0, -1);
        }

        return $w;
    }

    /**
     * Light singularization & stemming for matching plurals, tenses, and optional fillers
     */
    private function singularize(string $normalized): string
    {
        $words = explode(' ', $normalized);
        $filler = ['up', 'food', 'the', 'a', 'an', 'but'];
        $res = [];
        foreach ($words as $w) {
            if (count($words) >= 2 && in_array($w, $filler, true)) {
                continue;
            }
            $res[] = $this->stemWord($w);
        }

        if (empty($res)) {
            return $normalized;
        }

        return implode(' ', $res);
    }

    /**
     * Extract normalized/stemmed variants from primary answer and alternate_answers
     *
     * @param array<string, mixed> $q
     * @return array<int, array{norm: string, sing: string, sl: string, tokens: array<int, string>, sorted: string}>
     */
    private function extractVariants(array $q): array
    {
        $rawList = [(string) ($q['answer'] ?? '')];
        $altsRaw = trim((string) ($q['alternate_answers'] ?? ''));
        if ($altsRaw !== '') {
            if (str_starts_with($altsRaw, '[')) {
                $decoded = json_decode($altsRaw, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $item) {
                        $rawList[] = (string) $item;
                    }
                }
            } else {
                foreach (explode(',', $altsRaw) as $item) {
                    $rawList[] = trim($item);
                }
            }
        }

        $variants = [];
        foreach ($rawList as $r) {
            $norm = $this->normalizeAnswer($r);
            if ($norm === '') {
                continue;
            }
            $sing = $this->singularize($norm);
            $sl = str_replace(' ', '', $sing);
            $tokens = explode(' ', $sing);
            $sortedTokens = $tokens;
            sort($sortedTokens);

            $variants[] = [
                'norm' => $norm,
                'sing' => $sing,
                'sl' => $sl,
                'tokens' => $tokens,
                'sorted' => implode(' ', $sortedTokens),
            ];
        }

        return $variants;
    }

    /**
     * Determine whether two questions' variant sets match as duplicate / near-duplicate
     *
     * @param array<int, array{norm: string, sing: string, sl: string, tokens: array<int, string>, sorted: string}> $vListA
     * @param array<int, array{norm: string, sing: string, sl: string, tokens: array<int, string>, sorted: string}> $vListB
     */
    private function questionsMatch(array $vListA, array $vListB): bool
    {
        $commonStopWords = [
            'between', 'friend', 'around', 'little', 'people', 'something', 'nothing',
            'everything', 'someone', 'anyone', 'second', 'thought', 'broken', 'double',
            'ground', 'center', 'morning', 'evening', 'letter', 'number', 'animal',
            'season', 'square', 'corner', 'family', 'doctor', 'person', 'school',
            'market', 'street', 'water', 'money', 'heart', 'light', 'night', 'world',
            'point', 'right', 'stand', 'house', 'first', 'under', 'over',
        ];

        foreach ($vListA as $idxA => $va) {
            foreach ($vListB as $idxB => $vb) {
                // Avoid matching two short secondary alternates against each other
                if ($idxA > 0 && $idxB > 0 && min(mb_strlen($va['sl']), mb_strlen($vb['sl'])) < 5) {
                    continue;
                }

                // 1. Exact normalized or stemmed/singular match
                if ($va['norm'] === $vb['norm'] || $va['sing'] === $vb['sing']) {
                    return true;
                }

                // 2. Spaceless compound match (e.g. "left over" vs "leftover", "tuna fish" vs "tunafish")
                if ($va['sl'] === $vb['sl'] && mb_strlen($va['sl']) >= 4) {
                    return true;
                }

                // 3. Word-order permutation match (e.g. "Overseas travel" vs "Travel Overseas")
                if (count($va['tokens']) >= 2 && count($va['tokens']) === count($vb['tokens']) && $va['sorted'] === $vb['sorted']) {
                    return true;
                }

                // 4. Primary-to-primary near-duplicate checks
                if ($idxA === 0 && $idxB === 0) {
                    // Typo fuzzy match (Levenshtein)
                    $minLen = min(mb_strlen($va['sing']), mb_strlen($vb['sing']));
                    if ($minLen >= 5) {
                        $dist = levenshtein($va['sing'], $vb['sing']);
                        if ($dist <= 1 || ($dist <= 2 && $minLen >= 10)) {
                            return true;
                        }
                    }

                    $sA = $va['sing'];
                    $sB = $vb['sing'];

                    // Prefix variant (e.g. "Noon tea" vs "Afternoon tea")
                    if ($sA === 'after' . $sB || $sB === 'after' . $sA || $va['sl'] === 'after' . $vb['sl'] || $vb['sl'] === 'after' . $va['sl']) {
                        return true;
                    }

                    // Single distinctive word (>= 6 chars) contained as a whole token inside another answer
                    // (e.g. "Forecast" vs "Weather forecast", "Blanket" vs "Electric blanket", "Teacup" vs "Storm in a teacup", "Dragon" vs "Dragon Warrior", "Infinity" vs "To infinity and beyond")
                    if (count($va['tokens']) === 1 && mb_strlen($sA) >= 6 && !in_array($sA, $commonStopWords, true) && in_array($sA, $vb['tokens'], true)) {
                        return true;
                    }
                    if (count($vb['tokens']) === 1 && mb_strlen($sB) >= 6 && !in_array($sB, $commonStopWords, true) && in_array($sB, $va['tokens'], true)) {
                        return true;
                    }

                    // Shared long distinctive root token (>= 10 chars, e.g. "conversation", "misunderstand")
                    foreach ($va['tokens'] as $tA) {
                        if (mb_strlen($tA) >= 10 && in_array($tA, $vb['tokens'], true)) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
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

        // 3. Precompute variants for each question and group by matching rules
        $qVariants = [];
        foreach ($questions as $q) {
            $qVariants[(int) $q['id']] = $this->extractVariants($q);
        }

        /** @var array<int, array{key: string, display_answer: string, questions: array<int, array<string, mixed>>}> $groups */
        $groups = [];

        foreach ($questions as $q) {
            $qId = (int) $q['id'];
            $variants = $qVariants[$qId] ?? [];
            if (empty($variants)) {
                continue;
            }

            $matchedIndex = null;
            foreach ($groups as $idx => $g) {
                foreach ($g['questions'] as $gq) {
                    $gqId = (int) $gq['id'];
                    if ($this->questionsMatch($variants, $qVariants[$gqId] ?? [])) {
                        $matchedIndex = $idx;
                        break 2;
                    }
                }
            }

            if ($matchedIndex !== null) {
                $groups[$matchedIndex]['questions'][] = $q;
            } else {
                $groups[] = [
                    'key' => $variants[0]['norm'],
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

        $this->gameDatabase->syncMasterJson();
    }

    /**
     * Get count of duplicate groups currently active
     */
    public function getDuplicateGroupCount(): int
    {
        return count($this->findDuplicates());
    }
}

