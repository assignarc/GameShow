<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\GameDatabase;
use PDO;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class GameShowController extends AbstractController
{
    public function __construct(private readonly GameDatabase $gameDatabase)
    {
    }

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $pdo = $this->gameDatabase->getPdo();
        $shows = $pdo->query('SELECT s.*, 
            (SELECT COUNT(*) FROM show_teams t WHERE t.show_id = s.id) as team_count,
            (SELECT COUNT(*) FROM show_rounds r WHERE r.show_id = s.id) as round_count,
            (SELECT COUNT(*) FROM show_used_questions uq WHERE uq.show_id = s.id) as used_q_count
            FROM game_shows s ORDER BY s.id DESC')->fetchAll();

        foreach ($shows as &$s) {
            $s['mobile_host_url'] = $this->getHostLanUrl($request, "/shows/{$s['id']}/play");
        }

        $stats = [
            'total_questions' => (int) $pdo->query('SELECT COUNT(*) FROM questions')->fetchColumn(),
            'total_categories' => (int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn(),
            'total_favorites' => (int) $pdo->query('SELECT COUNT(*) FROM questions WHERE is_favorite = 1')->fetchColumn(),
        ];

        return $this->render('home/index.html.twig', [
            'shows' => $shows,
            'stats' => $stats,
            'lan_ip' => $this->getLanIp(),
        ]);
    }

    #[Route('/review', name: 'app_review_direct', methods: ['GET'])]
    public function reviewRedirect(Request $request): RedirectResponse
    {
        return $this->redirectToRoute('app_moderation_review', $request->query->all());
    }

    #[Route('/shows/new', name: 'app_show_create', methods: ['POST'])]
    public function createShow(Request $request): RedirectResponse
    {
        $pdo = $this->gameDatabase->getPdo();
        $name = trim((string) $request->request->get('name', 'Fall Party Game Show'));
        $subtitle = trim((string) $request->request->get('subtitle', 'Couples Rebus Championship'));
        $timer = max(10, min(120, (int) $request->request->get('default_timer', 25)));

        $stmt = $pdo->prepare('INSERT INTO game_shows (name, subtitle, default_timer_seconds, max_teams, status) VALUES (?, ?, ?, 12, "setup")');
        $stmt->execute([$name, $subtitle, $timer]);
        $showId = (int) $pdo->lastInsertId();

        $this->gameDatabase->getShowRules($showId);
        $this->configureOneHourTournament($showId);

        return $this->redirectToRoute('app_show_manage', ['id' => $showId]);
    }

    #[Route('/shows/{id}/update', name: 'app_show_update', methods: ['POST'])]
    public function updateShow(int $id, Request $request): RedirectResponse
    {
        $pdo = $this->gameDatabase->getPdo();
        $this->getShowOrFail($id);

        $name = trim((string) $request->request->get('name', ''));
        $subtitle = trim((string) $request->request->get('subtitle', ''));
        $timer = max(5, min(300, (int) $request->request->get('default_timer_seconds', 25)));

        if ($name === '') {
            $this->addFlash('error', 'Show name cannot be empty.');
            return $this->redirectToRoute('app_show_manage', ['id' => $id]);
        }

        $stmt = $pdo->prepare('UPDATE game_shows SET name = ?, subtitle = ?, default_timer_seconds = ? WHERE id = ?');
        $stmt->execute([$name, $subtitle, $timer, $id]);

        $this->addFlash('success', 'Game show details updated successfully.');

        $referer = (string) $request->headers->get('referer', '');
        if ($referer !== '' && !str_contains($referer, '/manage')) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('app_show_manage', ['id' => $id]);
    }

    #[Route('/shows/{id}/reset', name: 'app_show_reset', methods: ['POST'])]
    public function resetShow(int $id, Request $request): RedirectResponse
    {
        $pdo = $this->gameDatabase->getPdo();
        $this->getShowOrFail($id);

        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM show_used_questions WHERE show_id = ?')->execute([$id]);
            $pdo->prepare('UPDATE show_teams SET score = 0, round_score = 0, is_eliminated = 0, eliminated_in_round = NULL WHERE show_id = ?')->execute([$id]);
            $pdo->prepare('UPDATE show_rounds SET status = "pending" WHERE show_id = ?')->execute([$id]);
            $firstRound = (int) $pdo->query("SELECT id FROM show_rounds WHERE show_id = {$id} ORDER BY round_number ASC LIMIT 1")->fetchColumn();
            $pdo->prepare('UPDATE game_shows SET status = "setup", current_round_id = ?, current_question_id = NULL, active_matchup_label = "", active_team_a_id = NULL, active_team_b_id = NULL, question_started_at = NULL, answer_revealed = 0, active_rule_index = -1 WHERE id = ?')
                ->execute([$firstRound ?: null, $id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $this->addFlash('success', 'Game show refreshed! All team scores reset to 0, question history cleared, and show reset to Round 1.');

        $referer = (string) $request->headers->get('referer', '');
        if (str_contains($referer, '/play')) {
            return $this->redirectToRoute('app_show_play', ['id' => $id]);
        }

        return $this->redirectToRoute('app_show_manage', ['id' => $id]);
    }

    #[Route('/shows/{id}/apply-1hour-template', name: 'app_show_apply_one_hour_template', methods: ['POST'])]
    public function applyOneHourTemplate(int $id): RedirectResponse
    {
        $this->getShowOrFail($id);
        $this->configureOneHourTournament($id);
        $this->addFlash('success', 'Applied 1-Hour Championship Format (4 rounds, 38 questions).');

        return $this->redirectToRoute('app_show_manage', ['id' => $id]);
    }

    #[Route('/shows/{id}/delete', name: 'app_show_delete', methods: ['POST'])]
    public function deleteShow(int $id): RedirectResponse
    {
        $pdo = $this->gameDatabase->getPdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM show_used_questions WHERE show_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM show_teams WHERE show_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM show_rounds WHERE show_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM game_shows WHERE id = ?')->execute([$id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $this->redirectToRoute('app_home');
    }

    #[Route('/shows/{id}/manage', name: 'app_show_manage', methods: ['GET'])]
    public function manageShow(int $id, Request $request): Response
    {
        $pdo = $this->gameDatabase->getPdo();
        $show = $this->getShowOrFail($id);
        $teams = $pdo->prepare('SELECT * FROM show_teams WHERE show_id = ? ORDER BY score DESC, sort_order ASC');
        $teams->execute([$id]);
        $teamsList = $teams->fetchAll();

        $rounds = $pdo->prepare('SELECT * FROM show_rounds WHERE show_id = ? ORDER BY round_number ASC');
        $rounds->execute([$id]);
        $roundsList = $rounds->fetchAll();
        foreach ($roundsList as &$r) {
            $r['allocations'] = json_decode($r['category_allocations'] ?: '[]', true);
        }

        $categories = $pdo->query('SELECT * FROM categories ORDER BY sort_order ASC')->fetchAll();

        $usedCount = (int) $pdo->prepare('SELECT COUNT(*) FROM show_used_questions WHERE show_id = ?')->execute([$id]) ? $pdo->query("SELECT COUNT(*) FROM show_used_questions WHERE show_id = {$id}")->fetchColumn() : 0;

        $mobileHostUrl = $this->getHostLanUrl($request, "/shows/{$id}/play");

        return $this->render('game/manage.html.twig', [
            'show' => $show,
            'teams' => $teamsList,
            'rounds' => $roundsList,
            'categories' => $categories,
            'rules' => $this->gameDatabase->getShowRules($id),
            'used_count' => $usedCount,
            'mobile_host_url' => $mobileHostUrl,
            'lan_ip' => $this->getLanIp(),
        ]);
    }

    #[Route('/shows/{id}/teams/add', name: 'app_show_team_add', methods: ['POST'])]
    public function addTeam(int $id, Request $request): RedirectResponse
    {
        $pdo = $this->gameDatabase->getPdo();
        $name = trim((string) $request->request->get('name', 'New Couple'));
        $members = trim((string) $request->request->get('members', ''));
        $flight = (string) $request->request->get('flight_group', 'Acorn Flight');
        $emoji = (string) $request->request->get('avatar_emoji', '🍁');
        $color = (string) $request->request->get('avatar_color', '#f97316');

        $count = (int) $pdo->prepare('SELECT COUNT(*) FROM show_teams WHERE show_id = ?')->execute([$id]) ? $pdo->query("SELECT COUNT(*) FROM show_teams WHERE show_id = {$id}")->fetchColumn() : 0;
        if ($count >= 12) {
            $this->addFlash('error', 'Maximum of 12 teams reached.');
            return $this->redirectToRoute('app_show_manage', ['id' => $id]);
        }

        $stmt = $pdo->prepare('INSERT INTO show_teams (show_id, name, members, flight_group, avatar_emoji, avatar_color, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$id, $name, $members, $flight, $emoji, $color, $count + 1]);

        return $this->redirectToRoute('app_show_manage', ['id' => $id]);
    }

    #[Route('/shows/{id}/teams/{teamId}/update', name: 'app_show_team_update', methods: ['POST'])]
    public function updateTeam(int $id, int $teamId, Request $request): RedirectResponse
    {
        $pdo = $this->gameDatabase->getPdo();
        $name = trim((string) $request->request->get('name', ''));
        $members = trim((string) $request->request->get('members', ''));
        $flight = (string) $request->request->get('flight_group', 'Acorn Flight');
        $emoji = (string) $request->request->get('avatar_emoji', '🍁');
        $color = (string) $request->request->get('avatar_color', '#f97316');

        if ($name !== '') {
            $stmt = $pdo->prepare('UPDATE show_teams SET name = ?, members = ?, flight_group = ?, avatar_emoji = ?, avatar_color = ? WHERE id = ? AND show_id = ?');
            $stmt->execute([$name, $members, $flight, $emoji, $color, $teamId, $id]);
            $this->addFlash('success', 'Team updated successfully.');
        }

        return $this->redirectToRoute('app_show_manage', ['id' => $id]);
    }

    #[Route('/shows/{id}/teams/{teamId}/delete', name: 'app_show_team_delete', methods: ['POST'])]
    public function deleteTeam(int $id, int $teamId): RedirectResponse
    {
        $pdo = $this->gameDatabase->getPdo();
        $stmt = $pdo->prepare('DELETE FROM show_teams WHERE id = ? AND show_id = ?');
        $stmt->execute([$teamId, $id]);

        return $this->redirectToRoute('app_show_manage', ['id' => $id]);
    }

    #[Route('/shows/{id}/rounds/save', name: 'app_show_round_save', methods: ['POST'])]
    public function saveRound(int $id, Request $request): RedirectResponse
    {
        $pdo = $this->gameDatabase->getPdo();
        $roundId = (int) $request->request->get('round_id', 0);
        $name = trim((string) $request->request->get('name'));
        $roundType = (string) $request->request->get('round_type', 'all_play');
        $timer = max(10, min(120, (int) $request->request->get('timer_seconds', 25)));
        $points = (int) $request->request->get('points_per_question', 10);
        $steal = (int) $request->request->get('steal_points', 5);
        $advType = (string) $request->request->get('advancement_type', 'all');
        $topX = max(1, min(12, (int) $request->request->get('advance_top_x', 5)));

        // parse category allocations
        $allocations = [];
        $catIds = $request->request->all('cat_ids');
        $catCounts = $request->request->all('cat_counts');
        $catDiffs = $request->request->all('cat_diffs');

        foreach ($catIds as $i => $cId) {
            $count = (int) ($catCounts[$i] ?? 0);
            if ($count > 0) {
                $allocations[] = [
                    'category_id' => (int) $cId,
                    'count' => $count,
                    'difficulty' => $catDiffs[$i] ?? 'Any',
                ];
            }
        }

        if ($roundId > 0) {
            if ($name === '') {
                $existingName = $pdo->query("SELECT name FROM show_rounds WHERE id = {$roundId}")->fetchColumn();
                $name = $existingName ?: 'Round';
            }
            $stmt = $pdo->prepare('UPDATE show_rounds SET name = ?, round_type = ?, timer_seconds = ?, points_per_question = ?, steal_points = ?, advancement_type = ?, advance_top_x = ?, category_allocations = ? WHERE id = ? AND show_id = ?');
            $stmt->execute([$name, $roundType, $timer, $points, $steal, $advType, $topX, json_encode($allocations), $roundId, $id]);
        } else {
            $roundNum = (int) $pdo->query("SELECT IFNULL(MAX(round_number), 0) + 1 FROM show_rounds WHERE show_id = {$id}")->fetchColumn();
            if ($name === '') {
                $name = 'Round ' . $roundNum;
            }
            $stmt = $pdo->prepare('INSERT INTO show_rounds (show_id, round_number, name, round_type, timer_seconds, points_per_question, steal_points, advancement_type, advance_top_x, category_allocations, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending")');
            $stmt->execute([$id, $roundNum, $name, $roundType, $timer, $points, $steal, $advType, $topX, json_encode($allocations)]);
            $newRoundId = (int) $pdo->lastInsertId();

            $currRound = $pdo->query("SELECT current_round_id FROM game_shows WHERE id = {$id}")->fetchColumn();
            if (!$currRound) {
                $pdo->prepare('UPDATE game_shows SET current_round_id = ? WHERE id = ?')->execute([$newRoundId, $id]);
            }
        }

        return $this->redirectToRoute('app_show_manage', ['id' => $id]);
    }

    #[Route('/shows/{id}/rounds/{roundId}/delete', name: 'app_show_round_delete', methods: ['POST'])]
    public function deleteRound(int $id, int $roundId): RedirectResponse
    {
        $pdo = $this->gameDatabase->getPdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('DELETE FROM show_rounds WHERE id = ? AND show_id = ?');
            $stmt->execute([$roundId, $id]);

            $show = $this->getShowOrFail($id);
            if ((int) $show['current_round_id'] === $roundId) {
                $nextRoundId = $pdo->query("SELECT id FROM show_rounds WHERE show_id = {$id} ORDER BY round_number ASC LIMIT 1")->fetchColumn() ?: null;
                $pdo->prepare('UPDATE game_shows SET current_round_id = ? WHERE id = ?')->execute([$nextRoundId, $id]);
            }

            // Re-number remaining rounds sequentially
            $remaining = $pdo->prepare('SELECT id FROM show_rounds WHERE show_id = ? ORDER BY round_number ASC');
            $remaining->execute([$id]);
            $roundIds = $remaining->fetchAll(PDO::FETCH_COLUMN);

            $upd = $pdo->prepare('UPDATE show_rounds SET round_number = ? WHERE id = ?');
            foreach ($roundIds as $idx => $rId) {
                $upd->execute([$idx + 1, $rId]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $this->redirectToRoute('app_show_manage', ['id' => $id]);
    }

    #[Route('/shows/{id}/rounds/{roundId}/move/{direction}', name: 'app_show_round_move', methods: ['POST'], requirements: ['direction' => 'up|down'])]
    public function moveRound(int $id, int $roundId, string $direction): RedirectResponse
    {
        $pdo = $this->gameDatabase->getPdo();
        $stmt = $pdo->prepare('SELECT id, round_number FROM show_rounds WHERE show_id = ? ORDER BY round_number ASC');
        $stmt->execute([$id]);
        $rounds = $stmt->fetchAll();

        $currentIndex = null;
        foreach ($rounds as $idx => $r) {
            if ((int) $r['id'] === $roundId) {
                $currentIndex = $idx;
                break;
            }
        }

        if ($currentIndex !== null) {
            $targetIndex = $direction === 'up' ? $currentIndex - 1 : $currentIndex + 1;
            if (isset($rounds[$targetIndex])) {
                $currentRound = $rounds[$currentIndex];
                $targetRound = $rounds[$targetIndex];

                $pdo->beginTransaction();
                try {
                    $upd = $pdo->prepare('UPDATE show_rounds SET round_number = ? WHERE id = ?');
                    $upd->execute([-999, $currentRound['id']]);
                    $upd->execute([$currentRound['round_number'], $targetRound['id']]);
                    $upd->execute([$targetRound['round_number'], $currentRound['id']]);
                    $pdo->commit();
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
            }
        }

        return $this->redirectToRoute('app_show_manage', ['id' => $id]);
    }

    #[Route('/shows/{id}/play', name: 'app_show_play', methods: ['GET'])]
    public function playArena(int $id, Request $request): Response
    {
        $show = $this->getShowOrFail($id);
        $mobileHostUrl = $this->getHostLanUrl($request, "/shows/{$id}/play");
        return $this->render('game/arena.html.twig', [
            'show' => $show,
            'is_host_control' => true,
            'mobile_host_url' => $mobileHostUrl,
            'lan_ip' => $this->getLanIp(),
        ]);
    }

    #[Route('/shows/{id}/display', name: 'app_show_display', methods: ['GET'])]
    public function projectorDisplay(int $id, Request $request): Response
    {
        $show = $this->getShowOrFail($id);
        $mobileHostUrl = $this->getHostLanUrl($request, "/shows/{$id}/play");
        return $this->render('game/display.html.twig', [
            'show' => $show,
            'is_projector' => true,
            'mobile_host_url' => $mobileHostUrl,
            'lan_ip' => $this->getLanIp(),
        ]);
    }

    #[Route('/api/shows/{id}/state', name: 'app_api_show_state', methods: ['GET'])]
    public function getShowState(int $id, Request $request): JsonResponse
    {
        $pdo = $this->gameDatabase->getPdo();
        $show = $this->getShowOrFail($id);

        $teams = $pdo->prepare('SELECT * FROM show_teams WHERE show_id = ? ORDER BY score DESC, sort_order ASC');
        $teams->execute([$id]);
        $teamList = $teams->fetchAll();

        $rounds = $pdo->prepare('SELECT * FROM show_rounds WHERE show_id = ? ORDER BY round_number ASC');
        $rounds->execute([$id]);
        $roundList = $rounds->fetchAll();
        foreach ($roundList as &$r) {
            $r['category_allocations'] = json_decode($r['category_allocations'] ?: '[]', true);
        }

        $currentRound = null;
        if ($show['current_round_id']) {
            foreach ($roundList as $r) {
                if ($r['id'] === (int) $show['current_round_id']) {
                    $currentRound = $r;
                    break;
                }
            }
        }

        $currentQuestion = null;
        if ($show['current_question_id']) {
            $qStmt = $pdo->prepare('SELECT q.*, c.name as category_name, c.color as category_color, c.icon as category_icon 
                FROM questions q JOIN categories c ON q.category_id = c.id WHERE q.id = ?');
            $qStmt->execute([$show['current_question_id']]);
            $currentQuestion = $qStmt->fetch();
        }

        $usedCount = (int) $pdo->query("SELECT COUNT(*) FROM show_used_questions WHERE show_id = {$id}")->fetchColumn();

        $mobileHostUrl = $this->getHostLanUrl($request, "/shows/{$id}/play");

        return $this->json([
            'show' => $show,
            'teams' => $teamList,
            'rounds' => $roundList,
            'rules' => $this->gameDatabase->getShowRules($id),
            'current_round' => $currentRound,
            'current_question' => $currentQuestion,
            'used_questions_count' => $usedCount,
            'mobile_host_url' => $mobileHostUrl,
            'lan_ip' => $this->getLanIp(),
            'server_time' => time(),
        ]);
    }

    #[Route('/api/shows/{id}/action', name: 'app_api_show_action', methods: ['POST'])]
    public function handleShowAction(int $id, Request $request): JsonResponse
    {
        $pdo = $this->gameDatabase->getPdo();
        $show = $this->getShowOrFail($id);
        $payload = json_decode($request->getContent(), true) ?: $request->request->all();
        $action = $payload['action'] ?? '';

        switch ($action) {
            case 'prev_question':
                $uqStmt = $pdo->prepare('SELECT id, question_id, round_id FROM show_used_questions WHERE show_id = ? ORDER BY id ASC');
                $uqStmt->execute([$id]);
                $used = $uqStmt->fetchAll();
                if (empty($used)) {
                    return $this->json(['error' => 'No previous questions available'], Response::HTTP_BAD_REQUEST);
                }

                $currQId = (int) ($show['current_question_id'] ?? 0);
                $currIdx = -1;
                foreach ($used as $idx => $u) {
                    if ((int) $u['question_id'] === $currQId) {
                        $currIdx = $idx;
                        break;
                    }
                }

                if ($currIdx > 0) {
                    $targetItem = $used[$currIdx - 1];
                } elseif ($currIdx === -1 && count($used) > 0) {
                    $targetItem = end($used);
                } else {
                    return $this->json(['error' => 'Already at earliest question'], Response::HTTP_BAD_REQUEST);
                }

                $pdo->prepare('UPDATE game_shows SET current_question_id = ?, current_round_id = ?, answer_revealed = 1, question_started_at = NULL WHERE id = ?')
                    ->execute([$targetItem['question_id'], $targetItem['round_id'], $id]);

                return $this->json(['success' => true, 'question_id' => $targetItem['question_id']]);

            case 'next_question':
                $uqStmt = $pdo->prepare('SELECT id, question_id, round_id FROM show_used_questions WHERE show_id = ? ORDER BY id ASC');
                $uqStmt->execute([$id]);
                $used = $uqStmt->fetchAll();

                $currQId = (int) ($show['current_question_id'] ?? 0);
                $currIdx = -1;
                foreach ($used as $idx => $u) {
                    if ((int) $u['question_id'] === $currQId) {
                        $currIdx = $idx;
                        break;
                    }
                }

                if ($currIdx >= 0 && $currIdx < count($used) - 1) {
                    $targetItem = $used[$currIdx + 1];
                    $pdo->prepare('UPDATE game_shows SET current_question_id = ?, current_round_id = ?, answer_revealed = 1, question_started_at = NULL WHERE id = ?')
                        ->execute([$targetItem['question_id'], $targetItem['round_id'], $id]);
                    return $this->json(['success' => true, 'question_id' => $targetItem['question_id']]);
                }
                // Fallthrough to start_question when at the end of history

            case 'start_question':
                // pick next non-repeated question according to current round configuration
                $roundId = (int) ($payload['round_id'] ?? $show['current_round_id']);
                $rStmt = $pdo->prepare('SELECT * FROM show_rounds WHERE id = ? AND show_id = ?');
                $rStmt->execute([$roundId, $id]);
                $round = $rStmt->fetch();

                if (!$round) {
                    return $this->json(['error' => 'Round not found'], Response::HTTP_NOT_FOUND);
                }

                $allocations = json_decode($round['category_allocations'] ?: '[]', true);

                // Check quota fulfillment in current round
                $usedInRoundStmt = $pdo->prepare('SELECT category_id, COUNT(*) as cnt FROM show_used_questions WHERE show_id = ? AND round_id = ? GROUP BY category_id');
                $usedInRoundStmt->execute([$id, $roundId]);
                $usedInRound = $usedInRoundStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

                // Filter allocations that still need questions in this round
                $eligibleAllocations = [];
                foreach ($allocations as $alloc) {
                    $cId = (int) $alloc['category_id'];
                    $needed = (int) ($alloc['count'] ?? 0);
                    $used = (int) ($usedInRound[$cId] ?? 0);
                    if ($needed === 0 || $used < $needed) {
                        $eligibleAllocations[] = $alloc;
                    }
                }

                $activeAllocations = !empty($eligibleAllocations) ? $eligibleAllocations : $allocations;
                $question = null;

                // 1. Try picking an 'included' (approved) question matching category & difficulty from active allocations
                foreach ($activeAllocations as $alloc) {
                    $catId = (int) $alloc['category_id'];
                    $diff = $alloc['difficulty'] ?? 'Any';

                    $sql = "SELECT q.* FROM questions q WHERE q.is_active = 1 AND q.review_status = 'included' AND q.category_id = ? AND q.id NOT IN (SELECT question_id FROM show_used_questions WHERE show_id = ?)";
                    $params = [$catId, $id];
                    if ($diff !== 'Any') {
                        $sql .= ' AND (q.difficulty = ? OR (? = "Simple" AND q.difficulty = "Easy"))';
                        $params[] = $diff;
                        $params[] = $diff;
                    }
                    $sql .= ' ORDER BY q.is_favorite DESC, RANDOM() LIMIT 1';

                    $qStmt = $pdo->prepare($sql);
                    $qStmt->execute($params);
                    $candidate = $qStmt->fetch();
                    if ($candidate) {
                        $question = $candidate;
                        break;
                    }

                    // If not found with exact difficulty, try any difficulty in that category
                    if ($diff !== 'Any') {
                        $fbCatStmt = $pdo->prepare("SELECT q.* FROM questions q WHERE q.is_active = 1 AND q.review_status = 'included' AND q.category_id = ? AND q.id NOT IN (SELECT question_id FROM show_used_questions WHERE show_id = ?) ORDER BY q.is_favorite DESC, RANDOM() LIMIT 1");
                        $fbCatStmt->execute([$catId, $id]);
                        $candidate = $fbCatStmt->fetch();
                        if ($candidate) {
                            $question = $candidate;
                            break;
                        }
                    }
                }

                // 2. Fallback: Any unused 'included' question from any allocated category
                if (!$question && !empty($allocations)) {
                    $catIds = array_column($allocations, 'category_id');
                    $placeholders = implode(',', array_fill(0, count($catIds), '?'));
                    $params = array_merge([$id], $catIds);
                    $fbAllocStmt = $pdo->prepare("SELECT q.* FROM questions q WHERE q.is_active = 1 AND q.review_status = 'included' AND q.id NOT IN (SELECT question_id FROM show_used_questions WHERE show_id = ?) AND q.category_id IN ({$placeholders}) ORDER BY q.is_favorite DESC, RANDOM() LIMIT 1");
                    $fbAllocStmt->execute($params);
                    $question = $fbAllocStmt->fetch();
                }

                // 3. Fallback: Any unused 'included' question in entire master bank
                if (!$question) {
                    $fbStmt = $pdo->prepare("SELECT * FROM questions WHERE is_active = 1 AND review_status = 'included' AND id NOT IN (SELECT question_id FROM show_used_questions WHERE show_id = ?) ORDER BY is_favorite DESC, RANDOM() LIMIT 1");
                    $fbStmt->execute([$id]);
                    $question = $fbStmt->fetch();
                }

                // 4. Ultimate Fallback: Any active non-excluded question
                if (!$question) {
                    $fbAnyStmt = $pdo->prepare("SELECT * FROM questions WHERE is_active = 1 AND review_status != 'excluded' AND id NOT IN (SELECT question_id FROM show_used_questions WHERE show_id = ?) ORDER BY is_favorite DESC, RANDOM() LIMIT 1");
                    $fbAnyStmt->execute([$id]);
                    $question = $fbAnyStmt->fetch();
                }

                if (!$question) {
                    return $this->json(['error' => 'All available questions have been used for this game show!'], Response::HTTP_BAD_REQUEST);
                }

                $timerSec = $round['timer_seconds'] ?: 25;
                $upd = $pdo->prepare('UPDATE game_shows SET current_round_id = ?, current_question_id = ?, question_started_at = ?, timer_duration = ?, answer_revealed = 0, status = "active" WHERE id = ?');
                $upd->execute([$roundId, $question['id'], time(), $timerSec, $id]);

                // Record used question
                $uqStmt = $pdo->prepare('INSERT OR IGNORE INTO show_used_questions (show_id, round_id, question_id, category_id) VALUES (?, ?, ?, ?)');
                $uqStmt->execute([$id, $roundId, $question['id'], $question['category_id']]);

                return $this->json(['success' => true, 'question_id' => $question['id']]);

            case 'reveal_answer':
                $pdo->prepare('UPDATE game_shows SET answer_revealed = 1 WHERE id = ?')->execute([$id]);
                return $this->json(['success' => true]);

            case 'award_points':
                $teamId = (int) ($payload['team_id'] ?? 0);
                $pts = (int) ($payload['points'] ?? 10);
                $qId = (int) ($payload['question_id'] ?? $show['current_question_id']);
                $roundId = (int) ($payload['round_id'] ?? $show['current_round_id']);

                if ($teamId > 0) {
                    $pdo->prepare('UPDATE show_teams SET score = score + ?, round_score = round_score + ? WHERE id = ? AND show_id = ?')->execute([$pts, $pts, $teamId, $id]);
                    if ($qId > 0) {
                        $pdo->prepare('UPDATE show_used_questions SET awarded_team_id = ?, points_awarded = ? WHERE show_id = ? AND question_id = ?')->execute([$teamId, $pts, $id, $qId]);
                    }
                }
                return $this->json(['success' => true]);

            case 'update_team':
                $teamId = (int) ($payload['team_id'] ?? 0);
                $name = trim((string) ($payload['name'] ?? ''));
                $members = trim((string) ($payload['members'] ?? ''));
                $flight = (string) ($payload['flight_group'] ?? 'Acorn Flight');
                $emoji = (string) ($payload['avatar_emoji'] ?? '🍁');
                $color = (string) ($payload['avatar_color'] ?? '#f97316');

                if ($teamId > 0 && $name !== '') {
                    $stmt = $pdo->prepare('UPDATE show_teams SET name = ?, members = ?, flight_group = ?, avatar_emoji = ?, avatar_color = ? WHERE id = ? AND show_id = ?');
                    $stmt->execute([$name, $members, $flight, $emoji, $color, $teamId, $id]);
                }
                return $this->json(['success' => true]);

            case 'advance_round':
                $nextRoundId = (int) ($payload['next_round_id'] ?? 0);
                $applyEliminations = (bool) ($payload['apply_eliminations'] ?? false);

                // Handle eliminations if requested
                if ($applyEliminations && $show['current_round_id']) {
                    $rStmt = $pdo->prepare('SELECT * FROM show_rounds WHERE id = ? AND show_id = ?');
                    $rStmt->execute([$show['current_round_id'], $id]);
                    $currR = $rStmt->fetch();

                    if ($currR && $currR['advancement_type'] === 'top_x') {
                        $topX = (int) $currR['advance_top_x'];
                        $tStmt = $pdo->prepare('SELECT id FROM show_teams WHERE show_id = ? AND is_eliminated = 0 ORDER BY score DESC, id ASC');
                        $tStmt->execute([$id]);
                        $activeTeams = $tStmt->fetchAll(PDO::FETCH_COLUMN);

                        if (count($activeTeams) > $topX) {
                            $toEliminate = array_slice($activeTeams, $topX);
                            $elimStmt = $pdo->prepare('UPDATE show_teams SET is_eliminated = 1, eliminated_in_round = ? WHERE id = ? AND show_id = ?');
                            foreach ($toEliminate as $elimId) {
                                $elimStmt->execute([$currR['round_number'], $elimId, $id]);
                            }
                        }
                    }
                }

                // Reset round scores for surviving teams
                $pdo->prepare('UPDATE show_teams SET round_score = 0 WHERE show_id = ?')->execute([$id]);

                $pdo->prepare('UPDATE game_shows SET current_round_id = ?, current_question_id = NULL, answer_revealed = 0, question_started_at = NULL WHERE id = ?')->execute([$nextRoundId, $id]);
                return $this->json(['success' => true]);

            case 'set_matchup':
                $teamA = !empty($payload['team_a']) ? (int) $payload['team_a'] : null;
                $teamB = !empty($payload['team_b']) ? (int) $payload['team_b'] : null;
                $label = trim((string) ($payload['label'] ?? ''));

                $pdo->prepare('UPDATE game_shows SET active_matchup_label = ?, active_team_a_id = ?, active_team_b_id = ? WHERE id = ?')->execute([$label, $teamA, $teamB, $id]);
                return $this->json(['success' => true]);

            case 'start_rules':
            case 'show_rules':
                $ruleIdx = (int) ($payload['rule_index'] ?? 0);
                $pdo->prepare('UPDATE game_shows SET active_rule_index = ? WHERE id = ?')->execute([$ruleIdx, $id]);
                return $this->json(['success' => true, 'active_rule_index' => $ruleIdx]);

            case 'next_rule':
                $rules = $this->gameDatabase->getShowRules($id);
                $currIdx = (int) ($show['active_rule_index'] ?? -1);
                $nextIdx = $currIdx + 1;
                if ($nextIdx >= count($rules)) {
                    $nextIdx = -1; // End of rules
                }
                $pdo->prepare('UPDATE game_shows SET active_rule_index = ? WHERE id = ?')->execute([$nextIdx, $id]);
                return $this->json(['success' => true, 'active_rule_index' => $nextIdx]);

            case 'prev_rule':
                $currIdx = (int) ($show['active_rule_index'] ?? 0);
                $prevIdx = max(0, $currIdx - 1);
                $pdo->prepare('UPDATE game_shows SET active_rule_index = ? WHERE id = ?')->execute([$prevIdx, $id]);
                return $this->json(['success' => true, 'active_rule_index' => $prevIdx]);

            case 'close_rules':
            case 'finish_rules':
                $pdo->prepare('UPDATE game_shows SET active_rule_index = -1 WHERE id = ?')->execute([$id]);
                return $this->json(['success' => true, 'active_rule_index' => -1]);

            case 'reset_show':
                $pdo->beginTransaction();
                try {
                    $pdo->prepare('DELETE FROM show_used_questions WHERE show_id = ?')->execute([$id]);
                    $pdo->prepare('UPDATE show_teams SET score = 0, round_score = 0, is_eliminated = 0, eliminated_in_round = NULL WHERE show_id = ?')->execute([$id]);
                    $pdo->prepare('UPDATE show_rounds SET status = "pending" WHERE show_id = ?')->execute([$id]);
                    $firstRound = (int) $pdo->query("SELECT id FROM show_rounds WHERE show_id = {$id} ORDER BY round_number ASC LIMIT 1")->fetchColumn();
                    $pdo->prepare('UPDATE game_shows SET status = "setup", current_round_id = ?, current_question_id = NULL, active_matchup_label = "", active_team_a_id = NULL, active_team_b_id = NULL, question_started_at = NULL, answer_revealed = 0, active_rule_index = -1 WHERE id = ?')
                        ->execute([$firstRound ?: null, $id]);
                    $pdo->commit();
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
                return $this->json(['success' => true]);

            default:
                return $this->json(['error' => 'Unknown action'], Response::HTTP_BAD_REQUEST);
        }
    }

    public function configureOneHourTournament(int $showId): void
    {
        $pdo = $this->gameDatabase->getPdo();
        $cats = $pdo->query('SELECT id, slug FROM categories')->fetchAll();
        $catMap = array_column($cats, 'id', 'slug');
        $allCatIds = array_values($catMap);
        $firstCatId = $allCatIds[0] ?? 1;

        $rounds = [
            [
                'name' => 'Round 1: Warm-Up & Alphabet Wordplay',
                'round_type' => 'all_play',
                'timer_seconds' => 20,
                'points' => 10,
                'steal' => 5,
                'advancement' => 'all',
                'top_x' => 10,
                'allocations' => [
                    ['category_id' => $catMap['alphabet-wordplay'] ?? $firstCatId, 'count' => 5, 'difficulty' => 'Simple'],
                    ['category_id' => $catMap['classic-dingbats'] ?? $firstCatId, 'count' => 5, 'difficulty' => 'Simple'],
                ],
            ],
            [
                'name' => 'Round 2: Flight Battles (Couples & Pop Culture)',
                'round_type' => 'head_to_head',
                'timer_seconds' => 25,
                'points' => 15,
                'steal' => 5,
                'advancement' => 'top_x',
                'top_x' => 6,
                'allocations' => [
                    ['category_id' => $catMap['couples-pop-culture'] ?? $firstCatId, 'count' => 3, 'difficulty' => 'Any'],
                    ['category_id' => $catMap['picture-math'] ?? $firstCatId, 'count' => 3, 'difficulty' => 'Any'],
                    ['category_id' => $catMap['classic-dingbats'] ?? $firstCatId, 'count' => 4, 'difficulty' => 'Medium'],
                ],
            ],
            [
                'name' => 'Round 3: The Final 6 Gauntlet (Visual Riddles)',
                'round_type' => 'all_play',
                'timer_seconds' => 25,
                'points' => 20,
                'steal' => 10,
                'advancement' => 'top_x',
                'top_x' => 3,
                'allocations' => [
                    ['category_id' => $catMap['visual-riddles'] ?? $firstCatId, 'count' => 6, 'difficulty' => 'Medium'],
                    ['category_id' => $catMap['classic-dingbats'] ?? $firstCatId, 'count' => 4, 'difficulty' => 'Medium'],
                ],
            ],
            [
                'name' => 'Round 4: Grand Championship Podium Battle',
                'round_type' => 'all_play',
                'timer_seconds' => 30,
                'points' => 30,
                'steal' => 15,
                'advancement' => 'top_x',
                'top_x' => 1,
                'allocations' => [
                    ['category_id' => $catMap['championship-cryptics'] ?? $firstCatId, 'count' => 6, 'difficulty' => 'Hard'],
                    ['category_id' => $catMap['visual-riddles'] ?? $firstCatId, 'count' => 2, 'difficulty' => 'Hard'],
                ],
            ],
        ];

        // Replace rounds
        $pdo->prepare('DELETE FROM show_rounds WHERE show_id = ?')->execute([$showId]);

        $rStmt = $pdo->prepare('INSERT INTO show_rounds (show_id, round_number, name, round_type, timer_seconds, points_per_question, steal_points, advancement_type, advance_top_x, category_allocations, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending")');
        foreach ($rounds as $idx => $r) {
            $rStmt->execute([
                $showId,
                $idx + 1,
                $r['name'],
                $r['round_type'],
                $r['timer_seconds'],
                $r['points'],
                $r['steal'],
                $r['advancement'],
                $r['top_x'],
                json_encode($r['allocations']),
            ]);
        }

        $firstRound = (int) $pdo->query("SELECT id FROM show_rounds WHERE show_id = {$showId} ORDER BY round_number ASC LIMIT 1")->fetchColumn();
        $pdo->prepare('UPDATE game_shows SET current_round_id = ?, default_timer_seconds = 25 WHERE id = ?')->execute([$firstRound ?: null, $showId]);
    }

    private function getShowOrFail(int $id): array
    {
        $pdo = $this->gameDatabase->getPdo();
        $stmt = $pdo->prepare('SELECT * FROM game_shows WHERE id = ?');
        $stmt->execute([$id]);
        $show = $stmt->fetch();
        if (!$show) {
            throw $this->createNotFoundException('Game show not found.');
        }
        return $show;
    }

    public function getLanIp(): string
    {
        // 1. Try macOS primary Wi-Fi / Ethernet interface
        $macIp = trim((string) @shell_exec('ipconfig getifaddr en0 2>/dev/null || ipconfig getifaddr en1 2>/dev/null'));
        if ($macIp && filter_var($macIp, FILTER_VALIDATE_IP)) {
            return $macIp;
        }

        // 2. Try ifconfig output
        $output = (string) @shell_exec('ifconfig 2>/dev/null');
        if (preg_match('/inet (192\.168\.\d+\.\d+|10\.\d+\.\d+\.\d+|172\.(1[6-9]|2\d|3[01])\.\d+\.\d+)/', $output, $matches)) {
            return $matches[1];
        }

        // 3. Try hostname resolution
        $host = gethostname();
        $ip = gethostbyname($host);
        if ($ip && !str_starts_with($ip, '127.')) {
            return $ip;
        }

        return '127.0.0.1';
    }

    public function getHostLanUrl(Request $request, string $path): string
    {
        $lanIp = $this->getLanIp();
        $port = $request->getPort() ?: 8000;
        // Use http scheme by default for LAN mobile connections so iOS Safari does not prompt on self-signed certs
        return "http://{$lanIp}:{$port}" . $path;
    }
}

