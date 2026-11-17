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
        $starredOnly = (int) $request->request->get('starred_only', 0) ? 1 : 0;

        $stmt = $pdo->prepare('INSERT INTO game_shows (name, subtitle, default_timer_seconds, starred_only, max_teams, status) VALUES (?, ?, ?, ?, 12, "setup")');
        $stmt->execute([$name, $subtitle, $timer, $starredOnly]);
        $showId = (int) $pdo->lastInsertId();

        $this->gameDatabase->getShowRules($showId);
        $this->configureOneHourTournament($showId);

        if ((int) $request->request->get('include_rapid_fire', 0) === 1) {
            $this->ensureRapidFireRound($showId, true);
        }

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
        $starredOnly = (int) $request->request->get('starred_only', 0) ? 1 : 0;

        if ($name === '') {
            $this->addFlash('error', 'Show name cannot be empty.');
            return $this->redirectToRoute('app_show_manage', ['id' => $id]);
        }

        $stmt = $pdo->prepare('UPDATE game_shows SET name = ?, subtitle = ?, default_timer_seconds = ?, starred_only = ? WHERE id = ?');
        $stmt->execute([$name, $subtitle, $timer, $starredOnly, $id]);

        $this->addFlash('success', 'Game show details updated successfully.');

        $referer = (string) $request->headers->get('referer', '');
        if ($referer !== '' && !str_contains($referer, '/manage')) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('app_show_manage', ['id' => $id]);
    }

    #[Route('/shows/{id}/puzzle-pool', name: 'app_show_puzzle_pool', methods: ['POST'])]
    public function updatePuzzlePool(int $id, Request $request): RedirectResponse
    {
        $pdo = $this->gameDatabase->getPdo();
        $show = $this->getShowOrFail($id);

        $starredOnly = $request->request->has('starred_only')
            ? ((int) $request->request->get('starred_only') ? 1 : 0)
            : (((int) ($show['starred_only'] ?? 0) === 1) ? 0 : 1);

        $pdo->prepare('UPDATE game_shows SET starred_only = ? WHERE id = ?')->execute([$starredOnly, $id]);
        $this->populateRoundQuestionsForShow($id);

        if ($starredOnly === 1) {
            $this->addFlash('success', '⭐ Puzzle Pool updated: This game show will now pick ONLY Starred puzzles.');
        } else {
            $this->addFlash('success', '👍 Puzzle Pool updated: This game show will now pick from all Included puzzles.');
        }

        return $this->redirectToRoute('app_show_manage', ['id' => $id]);
    }

    #[Route('/shows/{id}/rapid-fire-toggle', name: 'app_show_rapid_fire_toggle', methods: ['POST'])]
    public function toggleRapidFireRound(int $id, Request $request): RedirectResponse
    {
        $pdo = $this->gameDatabase->getPdo();
        $this->getShowOrFail($id);

        $existingRfCount = (int) $pdo->query("SELECT COUNT(*) FROM show_rounds WHERE show_id = {$id} AND round_type = 'rapid_fire'")->fetchColumn();
        $enable = $request->request->has('enable')
            ? ((int) $request->request->get('enable') === 1)
            : ($existingRfCount === 0);

        $this->ensureRapidFireRound($id, $enable);

        if ($enable) {
            $rfRoundId = (int) $pdo->query("SELECT id FROM show_rounds WHERE show_id = {$id} AND round_type = 'rapid_fire' ORDER BY round_number DESC LIMIT 1")->fetchColumn();
            $rfCount = $rfRoundId > 0
                ? (int) $pdo->query("SELECT COUNT(*) FROM show_round_questions WHERE show_id = {$id} AND round_id = {$rfRoundId}")->fetchColumn()
                : 0;
            $this->addFlash('success', "⚡ Rapid Fire Round enabled! Automatically loaded {$rfCount} remaining Starred & Included puzzle(s) in randomized order.");
        } else {
            $this->addFlash('success', '⚡ Rapid Fire Round removed from this game show.');
        }

        return $this->redirect($this->generateUrl('app_show_manage', ['id' => $id]) . '#tab-rounds');
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
            $pdo->prepare('UPDATE game_shows SET status = "setup", current_round_id = ?, current_question_id = NULL, active_matchup_label = "", active_team_a_id = NULL, active_team_b_id = NULL, question_started_at = NULL, answer_revealed = 0, active_rule_index = -1, active_team_index = -1 WHERE id = ?')
                ->execute([$firstRound ?: null, $id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $this->addFlash('success', '🔄 Game Show Reset! Team scores reset to 0 and show state returned to the Welcome Splash Screen / Round 1 (assigned round questions & order preserved).');

        $referer = (string) $request->headers->get('referer', '');
        if (str_contains($referer, '/play')) {
            return $this->redirectToRoute('app_show_play', ['id' => $id]);
        }
        if ($referer !== '' && !str_contains($referer, '/manage') && !str_contains($referer, '/shows/')) {
            return $this->redirectToRoute('app_home');
        }

        return $this->redirectToRoute('app_show_manage', ['id' => $id]);
    }

    #[Route('/shows/{id}/reset-questions', name: 'app_show_reset_questions', methods: ['POST'])]
    public function resetQuestions(int $id, Request $request): RedirectResponse
    {
        $pdo = $this->gameDatabase->getPdo();
        $this->getShowOrFail($id);

        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM show_used_questions WHERE show_id = ?')->execute([$id]);
            $pdo->prepare('UPDATE game_shows SET current_question_id = NULL, question_started_at = NULL, answer_revealed = 0 WHERE id = ?')
                ->execute([$id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        // Choose new random non-duplicate questions across all rounds while keeping all round settings, team scores, and show state intact
        $this->populateRoundQuestionsForShow($id);

        $this->addFlash('success', '🎲 Questions Reset! Selected a fresh random set of non-repeating puzzles across all rounds (round settings, team scores, and game show state preserved).');

        $referer = (string) $request->headers->get('referer', '');
        if (str_contains($referer, '/play')) {
            return $this->redirectToRoute('app_show_play', ['id' => $id]);
        }
        if ($referer !== '' && !str_contains($referer, '/manage') && !str_contains($referer, '/shows/')) {
            return $this->redirectToRoute('app_home');
        }

        return $this->redirect($this->generateUrl('app_show_manage', ['id' => $id]) . '#tab-rounds');
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
            $pdo->prepare('DELETE FROM show_round_questions WHERE show_id = ?')->execute([$id]);
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
        $teams = $pdo->prepare('SELECT * FROM show_teams WHERE show_id = ? ORDER BY sort_order ASC, id ASC');
        $teams->execute([$id]);
        $teamsList = $teams->fetchAll();

        $assignedTotal = (int) $pdo->query("SELECT COUNT(*) FROM show_round_questions WHERE show_id = {$id}")->fetchColumn();
        $usedCount = (int) $pdo->query("SELECT COUNT(*) FROM show_used_questions WHERE show_id = {$id}")->fetchColumn();
        if ($assignedTotal === 0 && $usedCount === 0) {
            $this->populateRoundQuestionsForShow($id);
        }

        $roundQuestionsMap = $this->getRoundQuestionsMap($id);

        $rounds = $pdo->prepare('SELECT * FROM show_rounds WHERE show_id = ? ORDER BY round_number ASC');
        $rounds->execute([$id]);
        $roundsList = $rounds->fetchAll();
        $hasRapidFire = false;
        foreach ($roundsList as &$r) {
            $r['allocations'] = json_decode($r['category_allocations'] ?: '[]', true);
            $r['questions'] = $roundQuestionsMap[(int) $r['id']] ?? [];
            $r['description'] = $this->buildDynamicRoundDescription($r);
            $r['settings_summary'] = $r['description'];
            if (($r['round_type'] ?? '') === 'rapid_fire') {
                $hasRapidFire = true;
            }
        }
        unset($r);

        $categories = $pdo->query('SELECT * FROM categories ORDER BY sort_order ASC')->fetchAll();

        $poolStats = [
            'included' => (int) $pdo->query("SELECT COUNT(*) FROM questions WHERE is_active = 1 AND review_status = 'included'")->fetchColumn(),
            'starred' => (int) $pdo->query("SELECT COUNT(*) FROM questions WHERE is_favorite = 1 AND is_active = 1 AND review_status = 'included'")->fetchColumn(),
        ];

        $mobileHostUrl = $this->getHostLanUrl($request, "/shows/{$id}/play");

        return $this->render('game/manage.html.twig', [
            'show' => $show,
            'teams' => $teamsList,
            'rounds' => $roundsList,
            'has_rapid_fire' => $hasRapidFire,
            'categories' => $categories,
            'rules' => $this->gameDatabase->getShowRules($id),
            'used_count' => $usedCount,
            'pool_stats' => $poolStats,
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

    #[Route('/shows/{id}/teams/apply-theme', name: 'app_show_teams_apply_theme', methods: ['POST'])]
    public function applyTeamIconTheme(int $id, Request $request): RedirectResponse
    {
        $pdo = $this->gameDatabase->getPdo();
        $this->getShowOrFail($id);
        $theme = (string) $request->request->get('theme', 'liquors');

        $themes = [
            'liquors' => [
                'label' => '🥃 Liquors & Spirits',
                'flights' => ['Single Malt Flight', 'Cocktail Flight'],
                'icons' => [
                    ['emoji' => '🥃', 'color' => '#d97706'],
                    ['emoji' => '🍷', 'color' => '#e11d48'],
                    ['emoji' => '🍸', 'color' => '#38bdf8'],
                    ['emoji' => '🍾', 'color' => '#eab308'],
                    ['emoji' => '🍹', 'color' => '#f97316'],
                    ['emoji' => '🍺', 'color' => '#10b981'],
                    ['emoji' => '🥂', 'color' => '#ec4899'],
                    ['emoji' => '🍶', 'color' => '#8b5cf6'],
                    ['emoji' => '🧉', 'color' => '#b45309'],
                    ['emoji' => '🍋', 'color' => '#14b8a6'],
                    ['emoji' => '🧊', 'color' => '#06b6d4'],
                    ['emoji' => '🍒', 'color' => '#ef4444'],
                ],
            ],
            'ramayana' => [
                'label' => '🏹 Ramayana Legends',
                'flights' => ['Ayodhya Flight', 'Panchavati Flight'],
                'icons' => [
                    ['emoji' => '🏹', 'color' => '#f97316'],
                    ['emoji' => '🪔', 'color' => '#f59e0b'],
                    ['emoji' => '🪷', 'color' => '#ec4899'],
                    ['emoji' => '👑', 'color' => '#eab308'],
                    ['emoji' => '⛰️', 'color' => '#10b981'],
                    ['emoji' => '🦅', 'color' => '#38bdf8'],
                    ['emoji' => '🐚', 'color' => '#a855f7'],
                    ['emoji' => '☀️', 'color' => '#ea580c'],
                    ['emoji' => '🐒', 'color' => '#d97706'],
                    ['emoji' => '🦌', 'color' => '#fbbf24'],
                    ['emoji' => '🌊', 'color' => '#06b6d4'],
                    ['emoji' => '🌺', 'color' => '#ef4444'],
                ],
            ],
            'desi' => [
                'label' => '🪔 Desi Party & Culture',
                'flights' => ['Royal Flight', 'Mastani Flight'],
                'icons' => [
                    ['emoji' => '🪔', 'color' => '#f59e0b'],
                    ['emoji' => '🦚', 'color' => '#06b6d4'],
                    ['emoji' => '🪷', 'color' => '#ec4899'],
                    ['emoji' => '🥭', 'color' => '#eab308'],
                    ['emoji' => '🏏', 'color' => '#3b82f6'],
                    ['emoji' => '🐅', 'color' => '#f97316'],
                    ['emoji' => '🥁', 'color' => '#e11d48'],
                    ['emoji' => '🛺', 'color' => '#10b981'],
                    ['emoji' => '🌶️', 'color' => '#ef4444'],
                    ['emoji' => '🐘', 'color' => '#8b5cf6'],
                    ['emoji' => '🎬', 'color' => '#a855f7'],
                    ['emoji' => '💎', 'color' => '#38bdf8'],
                ],
            ],
            'planets' => [
                'label' => '🪐 Planets & Navagraha',
                'flights' => ['Solar Flight', 'Galactic Flight'],
                'icons' => [
                    ['emoji' => '☀️', 'color' => '#f97316'],
                    ['emoji' => '🪐', 'color' => '#eab308'],
                    ['emoji' => '🌍', 'color' => '#10b981'],
                    ['emoji' => '🌕', 'color' => '#cbd5e1'],
                    ['emoji' => '🔥', 'color' => '#ef4444'],
                    ['emoji' => '🌟', 'color' => '#ec4899'],
                    ['emoji' => '⚡', 'color' => '#8b5cf6'],
                    ['emoji' => '☄️', 'color' => '#06b6d4'],
                    ['emoji' => '🌌', 'color' => '#6366f1'],
                    ['emoji' => '🚀', 'color' => '#38bdf8'],
                    ['emoji' => '🌙', 'color' => '#f59e0b'],
                    ['emoji' => '✨', 'color' => '#fbbf24'],
                ],
            ],
            'fall' => [
                'label' => '🍁 Fall Harvest',
                'flights' => ['Acorn Flight', 'Pumpkin Flight'],
                'icons' => [
                    ['emoji' => '🍁', 'color' => '#f97316'],
                    ['emoji' => '🎃', 'color' => '#eab308'],
                    ['emoji' => '🍎', 'color' => '#10b981'],
                    ['emoji' => '🧣', 'color' => '#3b82f6'],
                    ['emoji' => '🌰', 'color' => '#8b5cf6'],
                    ['emoji' => '🥧', 'color' => '#ec4899'],
                    ['emoji' => '🪵', 'color' => '#ef4444'],
                    ['emoji' => '🚜', 'color' => '#14b8a6'],
                    ['emoji' => '🔥', 'color' => '#f59e0b'],
                    ['emoji' => '🌕', 'color' => '#6366f1'],
                    ['emoji' => '🍂', 'color' => '#d97706'],
                    ['emoji' => '☕', 'color' => '#b45309'],
                ],
            ],
        ];

        $selected = $themes[$theme] ?? $themes['liquors'];
        $teamsStmt = $pdo->prepare('SELECT id FROM show_teams WHERE show_id = ? ORDER BY sort_order ASC, id ASC');
        $teamsStmt->execute([$id]);
        $teamIds = $teamsStmt->fetchAll(PDO::FETCH_COLUMN);

        $updStmt = $pdo->prepare('UPDATE show_teams SET avatar_emoji = ?, avatar_color = ?, flight_group = ? WHERE id = ? AND show_id = ?');
        $half = max(1, (int) ceil(count($teamIds) / 2));
        foreach ($teamIds as $idx => $teamId) {
            $iconSpec = $selected['icons'][$idx % count($selected['icons'])];
            $flightGroup = ($idx < $half) ? $selected['flights'][0] : $selected['flights'][1];
            $updStmt->execute([$iconSpec['emoji'], $iconSpec['color'], $flightGroup, (int) $teamId, $id]);
        }

        $this->addFlash('success', "Applied {$selected['label']} icon theme to all " . count($teamIds) . ' teams!');
        return $this->redirectToRoute('app_show_manage', ['id' => $id]);
    }

    #[Route('/shows/{id}/teams/sort-alphabetically', name: 'app_show_teams_sort_alpha', methods: ['POST'])]
    public function sortTeamsAlphabetically(int $id): RedirectResponse
    {
        $pdo = $this->gameDatabase->getPdo();
        $this->getShowOrFail($id);

        $stmt = $pdo->prepare('SELECT id, name FROM show_teams WHERE show_id = ? ORDER BY name COLLATE NOCASE ASC, id ASC');
        $stmt->execute([$id]);
        $teams = $stmt->fetchAll();

        $updStmt = $pdo->prepare('UPDATE show_teams SET sort_order = ? WHERE id = ? AND show_id = ?');
        foreach ($teams as $idx => $team) {
            $updStmt->execute([$idx + 1, (int) $team['id'], $id]);
        }

        $this->addFlash('success', '🔤 Teams sorted alphabetically (A–Z)!');
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

        $dynDesc = $this->buildDynamicRoundDescription([
            'round_type' => $roundType,
            'timer_seconds' => $timer,
            'points_per_question' => $points,
            'steal_points' => $steal,
            'advancement_type' => $advType,
            'advance_top_x' => $topX,
        ]);

        if ($roundId > 0) {
            if ($name === '') {
                $existingName = $pdo->query("SELECT name FROM show_rounds WHERE id = {$roundId}")->fetchColumn();
                $name = $existingName ?: 'Round';
            }
            $stmt = $pdo->prepare('UPDATE show_rounds SET name = ?, description = ?, round_type = ?, timer_seconds = ?, points_per_question = ?, steal_points = ?, advancement_type = ?, advance_top_x = ?, category_allocations = ? WHERE id = ? AND show_id = ?');
            $stmt->execute([$name, $dynDesc, $roundType, $timer, $points, $steal, $advType, $topX, json_encode($allocations), $roundId, $id]);
            if ($roundType === 'rapid_fire') {
                $this->syncRapidFireRoundsForShow($id);
            } elseif (!empty($allocations)) {
                $this->populateRoundQuestionsForShow($id, $roundId, true);
            } else {
                $this->syncRapidFireRoundsForShow($id);
            }
        } else {
            $roundNum = (int) $pdo->query("SELECT IFNULL(MAX(round_number), 0) + 1 FROM show_rounds WHERE show_id = {$id}")->fetchColumn();
            if ($name === '') {
                $name = $roundType === 'rapid_fire'
                    ? "Round {$roundNum}: ⚡ Rapid Fire (Remaining Starred)"
                    : 'Round ' . $roundNum;
            }
            $stmt = $pdo->prepare('INSERT INTO show_rounds (show_id, round_number, name, description, round_type, timer_seconds, points_per_question, steal_points, advancement_type, advance_top_x, category_allocations, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending")');
            $stmt->execute([$id, $roundNum, $name, $dynDesc, $roundType, $timer, $points, $steal, $advType, $topX, json_encode($allocations)]);
            $newRoundId = (int) $pdo->lastInsertId();

            $currRound = $pdo->query("SELECT current_round_id FROM game_shows WHERE id = {$id}")->fetchColumn();
            if (!$currRound) {
                $pdo->prepare('UPDATE game_shows SET current_round_id = ? WHERE id = ?')->execute([$newRoundId, $id]);
            }
            if ($roundType === 'rapid_fire') {
                $this->syncRapidFireRoundsForShow($id);
            } elseif (!empty($allocations)) {
                $this->populateRoundQuestionsForShow($id, $newRoundId, false);
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
            $pdo->prepare('DELETE FROM show_round_questions WHERE round_id = ? AND show_id = ?')->execute([$roundId, $id]);
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

        $this->syncRapidFireRoundsForShow($id);

        return $this->redirectToRoute('app_show_manage', ['id' => $id]);
    }

    #[Route('/api/shows/{id}/available-questions', name: 'app_api_show_available_questions', methods: ['GET'])]
    public function getAvailableQuestionsForShow(int $id, Request $request): JsonResponse
    {
        $pdo = $this->gameDatabase->getPdo();
        $this->getShowOrFail($id);

        $search = trim((string) $request->query->get('q', ''));
        $catId = (int) $request->query->get('category_id', 0);
        $diff = trim((string) $request->query->get('difficulty', 'Any'));
        $starredFilter = trim((string) $request->query->get('starred', 'all'));
        $hideAssigned = (int) $request->query->get('hide_assigned', 0) === 1;
        $limit = max(20, min(250, (int) $request->query->get('limit', 120)));

        // Gather all questions already in non-rapid-fire rounds or show_used_questions for this show
        $existingStmt = $pdo->prepare("
            SELECT srq.question_id, srq.round_id, r.round_number, r.name as round_name, q.answer
            FROM show_round_questions srq
            JOIN show_rounds r ON r.id = srq.round_id
            JOIN questions q ON q.id = srq.question_id
            WHERE srq.show_id = ? AND r.round_type != 'rapid_fire'
        ");
        $existingStmt->execute([$id]);
        $existingRows = $existingStmt->fetchAll();

        $assignedByQId = [];
        $assignedByNormAnswer = [];
        foreach ($existingRows as $er) {
            $eqId = (int) $er['question_id'];
            $assignedByQId[$eqId] = [
                'round_id' => (int) $er['round_id'],
                'round_number' => (int) $er['round_number'],
                'round_name' => $er['round_name'],
            ];
            $norm = $this->normalizeAnswerForShow((string) $er['answer']);
            if ($norm !== '' && !isset($assignedByNormAnswer[$norm])) {
                $assignedByNormAnswer[$norm] = [
                    'question_id' => $eqId,
                    'round_number' => (int) $er['round_number'],
                    'answer' => $er['answer'],
                ];
            }
        }

        // Also check show_used_questions in case any question was asked outside show_round_questions
        $usedStmt = $pdo->prepare('
            SELECT uq.question_id, uq.round_id, q.answer
            FROM show_used_questions uq
            JOIN questions q ON q.id = uq.question_id
            WHERE uq.show_id = ?
        ');
        $usedStmt->execute([$id]);
        foreach ($usedStmt->fetchAll() as $ur) {
            $uqId = (int) $ur['question_id'];
            if (!isset($assignedByQId[$uqId])) {
                $assignedByQId[$uqId] = [
                    'round_id' => (int) ($ur['round_id'] ?? 0),
                    'round_number' => 1,
                    'round_name' => 'Asked in Show',
                ];
            }
            $norm = $this->normalizeAnswerForShow((string) $ur['answer']);
            if ($norm !== '' && !isset($assignedByNormAnswer[$norm])) {
                $assignedByNormAnswer[$norm] = [
                    'question_id' => $uqId,
                    'round_number' => 1,
                    'answer' => $ur['answer'],
                ];
            }
        }

        $where = ["q.is_active = 1", "q.review_status = 'included'"];
        $params = [];

        if ($catId > 0) {
            $where[] = 'q.category_id = ?';
            $params[] = $catId;
        }
        if ($diff !== '' && $diff !== 'Any') {
            $where[] = '(q.difficulty = ? OR (? = "Simple" AND q.difficulty = "Easy"))';
            $params[] = $diff;
            $params[] = $diff;
        }
        if ($starredFilter === '1' || $starredFilter === 'starred') {
            $where[] = 'q.is_favorite = 1';
        } elseif ($starredFilter === '0' || $starredFilter === 'unstarred') {
            $where[] = 'q.is_favorite = 0';
        }
        if ($search !== '') {
            $cleanId = ltrim($search, '#');
            if (ctype_digit($cleanId)) {
                $where[] = '(q.id = ? OR q.answer LIKE ? OR q.title LIKE ?)';
                $params[] = (int) $cleanId;
                $params[] = '%' . $search . '%';
                $params[] = '%' . $search . '%';
            } else {
                $where[] = '(q.answer LIKE ? OR q.title LIKE ? OR q.alternate_answers LIKE ?)';
                $params[] = '%' . $search . '%';
                $params[] = '%' . $search . '%';
                $params[] = '%' . $search . '%';
            }
        }

        $whereSql = implode(' AND ', $where);
        $sql = "SELECT q.id, q.category_id, q.title, q.answer, q.difficulty, q.media_path, q.is_favorite,
                       c.name as category_name, c.icon as category_icon, c.color as category_color
                FROM questions q
                JOIN categories c ON c.id = q.category_id
                WHERE {$whereSql}
                ORDER BY q.is_favorite DESC, q.id DESC
                LIMIT " . ($limit * 2);

        $qStmt = $pdo->prepare($sql);
        $qStmt->execute($params);
        $rows = $qStmt->fetchAll();

        $results = [];
        foreach ($rows as $row) {
            $qId = (int) $row['id'];
            $norm = $this->normalizeAnswerForShow((string) $row['answer']);
            $canSelect = true;
            $dupReason = null;
            $assignedRoundNum = null;

            if (isset($assignedByQId[$qId])) {
                $canSelect = false;
                $assignedRoundNum = $assignedByQId[$qId]['round_number'];
                $dupReason = 'Already in Round ' . $assignedRoundNum;
            } elseif ($norm !== '' && isset($assignedByNormAnswer[$norm])) {
                $canSelect = false;
                $dupInfo = $assignedByNormAnswer[$norm];
                $assignedRoundNum = $dupInfo['round_number'];
                $dupReason = 'Duplicate answer in Round ' . $dupInfo['round_number'] . ' (#' . $dupInfo['question_id'] . ')';
            }

            if ($hideAssigned && !$canSelect) {
                continue;
            }

            $row['id'] = $qId;
            $row['category_id'] = (int) $row['category_id'];
            $row['is_favorite'] = (int) $row['is_favorite'];
            $row['can_select'] = $canSelect;
            $row['duplicate_reason'] = $dupReason;
            $row['assigned_round_number'] = $assignedRoundNum;
            $results[] = $row;

            if (count($results) >= $limit) {
                break;
            }
        }

        return $this->json([
            'success' => true,
            'questions' => $results,
            'total_in_show' => count($assignedByQId),
        ]);
    }

    #[Route('/shows/{id}/rounds/{roundId}/questions/add', name: 'app_show_round_question_add', methods: ['POST'])]
    public function addQuestionToRound(int $id, int $roundId, Request $request): Response
    {
        $pdo = $this->gameDatabase->getPdo();
        $this->getShowOrFail($id);

        $rStmt = $pdo->prepare('SELECT * FROM show_rounds WHERE id = ? AND show_id = ?');
        $rStmt->execute([$roundId, $id]);
        $round = $rStmt->fetch();
        if (!$round) {
            throw $this->createNotFoundException('Round not found.');
        }

        $isJsonReq = str_contains((string) $request->headers->get('Content-Type', ''), 'application/json')
            || $request->isXmlHttpRequest()
            || str_contains((string) $request->headers->get('Accept', ''), 'application/json');

        if (($round['round_type'] ?? '') === 'rapid_fire') {
            $msg = 'Rapid Fire round automatically includes all remaining Starred puzzles and does not allow manual selection.';
            if ($isJsonReq) {
                return $this->json(['success' => false, 'error' => $msg], Response::HTTP_BAD_REQUEST);
            }
            $this->addFlash('error', $msg);
            return $this->redirect($this->generateUrl('app_show_manage', ['id' => $id]) . '#tab-rounds');
        }

        $payload = json_decode($request->getContent(), true) ?: $request->request->all();
        $rawIds = $payload['question_ids'] ?? (isset($payload['question_id']) ? [$payload['question_id']] : []);
        if (!is_array($rawIds)) {
            $rawIds = [$rawIds];
        }
        $questionIds = array_values(array_unique(array_filter(array_map('intval', $rawIds), fn ($v) => $v > 0)));

        if (empty($questionIds)) {
            if ($isJsonReq) {
                return $this->json(['error' => 'No valid question IDs provided.'], Response::HTTP_BAD_REQUEST);
            }
            $this->addFlash('error', 'No valid puzzles selected.');
            return $this->redirect($this->generateUrl('app_show_manage', ['id' => $id]) . '#tab-rounds');
        }

        // Load existing questions in show (across all non-rapid-fire rounds + used) to block any duplicates
        $existingStmt = $pdo->prepare("
            SELECT srq.question_id, r.round_number, q.answer
            FROM show_round_questions srq
            JOIN show_rounds r ON r.id = srq.round_id
            JOIN questions q ON q.id = srq.question_id
            WHERE srq.show_id = ? AND r.round_type != 'rapid_fire'
        ");
        $existingStmt->execute([$id]);
        $usedIds = [];
        $usedNormAnswers = [];
        foreach ($existingStmt->fetchAll() as $er) {
            $usedIds[(int) $er['question_id']] = (int) $er['round_number'];
            $norm = $this->normalizeAnswerForShow((string) $er['answer']);
            if ($norm !== '') {
                $usedNormAnswers[$norm] = (int) $er['round_number'];
            }
        }

        $usedQStmt = $pdo->prepare('SELECT uq.question_id, q.answer FROM show_used_questions uq JOIN questions q ON q.id = uq.question_id WHERE uq.show_id = ?');
        $usedQStmt->execute([$id]);
        foreach ($usedQStmt->fetchAll() as $ur) {
            $usedIds[(int) $ur['question_id']] = 1;
            $norm = $this->normalizeAnswerForShow((string) $ur['answer']);
            if ($norm !== '') {
                $usedNormAnswers[$norm] = 1;
            }
        }

        $maxSort = (int) $pdo->query("SELECT IFNULL(MAX(sort_order), 0) FROM show_round_questions WHERE show_id = {$id} AND round_id = {$roundId}")->fetchColumn();
        $delFromRfStmt = $pdo->prepare("DELETE FROM show_round_questions WHERE show_id = ? AND question_id = ? AND round_id IN (SELECT id FROM show_rounds WHERE show_id = ? AND round_type = 'rapid_fire')");
        $insStmt = $pdo->prepare('INSERT INTO show_round_questions (show_id, round_id, question_id, sort_order, is_manual) VALUES (?, ?, ?, ?, 1)');
        $qFetchStmt = $pdo->prepare('SELECT * FROM questions WHERE id = ?');

        $addedCount = 0;
        $skippedReasons = [];

        foreach ($questionIds as $qId) {
            $qFetchStmt->execute([$qId]);
            $qRow = $qFetchStmt->fetch();
            if (!$qRow) {
                $skippedReasons[] = "#{$qId}: Puzzle not found";
                continue;
            }
            if ((int) ($qRow['is_active'] ?? 0) !== 1 || ($qRow['review_status'] ?? '') !== 'included') {
                $skippedReasons[] = "#{$qId}: Only Included puzzles can be added to a game show";
                continue;
            }
            if (isset($usedIds[$qId])) {
                $skippedReasons[] = "#{$qId} ({$qRow['answer']}): Already assigned in Round {$usedIds[$qId]}";
                continue;
            }
            $norm = $this->normalizeAnswerForShow((string) $qRow['answer']);
            if ($norm !== '' && isset($usedNormAnswers[$norm])) {
                $skippedReasons[] = "#{$qId} ({$qRow['answer']}): Duplicate answer already in Round {$usedNormAnswers[$norm]}";
                continue;
            }

            $delFromRfStmt->execute([$id, $qId, $id]);
            ++$maxSort;
            $insStmt->execute([$id, $roundId, $qId, $maxSort]);
            $usedIds[$qId] = (int) $round['round_number'];
            if ($norm !== '') {
                $usedNormAnswers[$norm] = (int) $round['round_number'];
            }
            ++$addedCount;
        }

        if ($addedCount > 0) {
            $this->syncRoundAllocationsFromAssigned($id, $roundId);
            $this->syncRapidFireRoundsForShow($id);
        }

        $allRoundQuestions = $this->getRoundQuestionsMap($id);
        $roundQuestions = $allRoundQuestions[$roundId] ?? [];
        $rapidFireRounds = $this->getRapidFireRoundsPayload($id, $allRoundQuestions);

        if ($isJsonReq) {
            if ($addedCount === 0 && !empty($skippedReasons)) {
                return $this->json([
                    'success' => false,
                    'added_count' => 0,
                    'skipped_duplicates' => $skippedReasons,
                    'error' => implode('; ', $skippedReasons),
                    'round_questions' => $roundQuestions,
                    'rapid_fire_rounds' => $rapidFireRounds,
                ], Response::HTTP_CONFLICT);
            }

            return $this->json([
                'success' => true,
                'added_count' => $addedCount,
                'skipped_duplicates' => $skippedReasons,
                'round_questions' => $roundQuestions,
                'rapid_fire_rounds' => $rapidFireRounds,
            ]);
        }

        if ($addedCount > 0) {
            $this->addFlash('success', "Added {$addedCount} puzzle(s) to {$round['name']}.");
        }
        if (!empty($skippedReasons)) {
            $this->addFlash('error', 'Skipped duplicate/ineligible puzzles: ' . implode(' | ', $skippedReasons));
        }

        return $this->redirect($this->generateUrl('app_show_manage', ['id' => $id]) . '#tab-rounds');
    }

    #[Route('/shows/{id}/rounds/{roundId}/questions/{questionId}/remove', name: 'app_show_round_question_remove', methods: ['POST'])]
    public function removeQuestionFromRound(int $id, int $roundId, int $questionId, Request $request): Response
    {
        $pdo = $this->gameDatabase->getPdo();
        $this->getShowOrFail($id);

        $rStmt = $pdo->prepare('SELECT * FROM show_rounds WHERE id = ? AND show_id = ?');
        $rStmt->execute([$roundId, $id]);
        $round = $rStmt->fetch();
        if (!$round) {
            throw $this->createNotFoundException('Round not found.');
        }

        $isJsonReq = str_contains((string) $request->headers->get('Content-Type', ''), 'application/json')
            || $request->isXmlHttpRequest()
            || str_contains((string) $request->headers->get('Accept', ''), 'application/json');

        if (($round['round_type'] ?? '') === 'rapid_fire') {
            $msg = 'Rapid Fire round automatically includes all remaining Starred puzzles and does not allow manual removal.';
            if ($isJsonReq) {
                return $this->json(['success' => false, 'error' => $msg], Response::HTTP_BAD_REQUEST);
            }
            $this->addFlash('error', $msg);
            return $this->redirect($this->generateUrl('app_show_manage', ['id' => $id]) . '#tab-rounds');
        }

        $pdo->prepare('DELETE FROM show_round_questions WHERE show_id = ? AND round_id = ? AND question_id = ?')
            ->execute([$id, $roundId, $questionId]);
        $pdo->prepare('DELETE FROM show_used_questions WHERE show_id = ? AND round_id = ? AND question_id = ?')
            ->execute([$id, $roundId, $questionId]);

        // Re-sequence sort_order
        $remStmt = $pdo->prepare('SELECT id FROM show_round_questions WHERE show_id = ? AND round_id = ? ORDER BY sort_order ASC, id ASC');
        $remStmt->execute([$id, $roundId]);
        $updSort = $pdo->prepare('UPDATE show_round_questions SET sort_order = ? WHERE id = ?');
        foreach ($remStmt->fetchAll(PDO::FETCH_COLUMN) as $idx => $srqId) {
            $updSort->execute([$idx + 1, $srqId]);
        }

        $this->syncRoundAllocationsFromAssigned($id, $roundId);
        $this->syncRapidFireRoundsForShow($id);

        $allRoundQuestions = $this->getRoundQuestionsMap($id);
        $roundQuestions = $allRoundQuestions[$roundId] ?? [];
        $rapidFireRounds = $this->getRapidFireRoundsPayload($id, $allRoundQuestions);
        if ($isJsonReq) {
            return $this->json([
                'success' => true,
                'removed_question_id' => $questionId,
                'round_questions' => $roundQuestions,
                'rapid_fire_rounds' => $rapidFireRounds,
            ]);
        }

        $this->addFlash('success', "Removed puzzle #{$questionId} from round.");
        return $this->redirect($this->generateUrl('app_show_manage', ['id' => $id]) . '#tab-rounds');
    }

    #[Route('/shows/{id}/rounds/{roundId}/questions/{questionId}/swap', name: 'app_show_round_question_swap', methods: ['POST'])]
    public function swapQuestionInRound(int $id, int $roundId, int $questionId, Request $request): Response
    {
        $pdo = $this->gameDatabase->getPdo();
        $show = $this->getShowOrFail($id);

        $rStmt = $pdo->prepare('SELECT * FROM show_rounds WHERE id = ? AND show_id = ?');
        $rStmt->execute([$roundId, $id]);
        $round = $rStmt->fetch();
        if (!$round) {
            throw $this->createNotFoundException('Round not found.');
        }

        $isJsonReq = str_contains((string) $request->headers->get('Content-Type', ''), 'application/json')
            || $request->isXmlHttpRequest()
            || str_contains((string) $request->headers->get('Accept', ''), 'application/json');

        if (($round['round_type'] ?? '') === 'rapid_fire') {
            $msg = 'Rapid Fire round automatically includes all remaining Starred puzzles and does not allow manual swapping.';
            if ($isJsonReq) {
                return $this->json(['success' => false, 'error' => $msg], Response::HTTP_BAD_REQUEST);
            }
            $this->addFlash('error', $msg);
            return $this->redirect($this->generateUrl('app_show_manage', ['id' => $id]) . '#tab-rounds');
        }

        $srqStmt = $pdo->prepare('SELECT srq.*, q.category_id, q.difficulty FROM show_round_questions srq JOIN questions q ON q.id = srq.question_id WHERE srq.show_id = ? AND srq.round_id = ? AND srq.question_id = ?');
        $srqStmt->execute([$id, $roundId, $questionId]);
        $srqRow = $srqStmt->fetch();

        if (!$srqRow) {
            if ($isJsonReq) {
                return $this->json(['error' => 'Question not found in round'], Response::HTTP_NOT_FOUND);
            }
            return $this->redirect($this->generateUrl('app_show_manage', ['id' => $id]) . '#tab-rounds');
        }

        $existingStmt = $pdo->prepare("SELECT srq.question_id, q.answer FROM show_round_questions srq JOIN show_rounds r ON r.id = srq.round_id JOIN questions q ON q.id = srq.question_id WHERE srq.show_id = ? AND r.round_type != 'rapid_fire'");
        $existingStmt->execute([$id]);
        $usedIds = [$questionId => true];
        $usedNormAnswers = [];
        foreach ($existingStmt->fetchAll() as $er) {
            $usedIds[(int) $er['question_id']] = true;
            if ((int) $er['question_id'] !== $questionId) {
                $norm = $this->normalizeAnswerForShow((string) $er['answer']);
                if ($norm !== '') {
                    $usedNormAnswers[$norm] = true;
                }
            }
        }

        $isStarredOnly = ((int) ($show['starred_only'] ?? 0)) === 1;
        $poolCond = $isStarredOnly
            ? "q.is_favorite = 1 AND q.is_active = 1 AND q.review_status = 'included'"
            : "q.is_active = 1 AND q.review_status = 'included'";

        $candStmt = $pdo->prepare("SELECT q.* FROM questions q WHERE {$poolCond} AND q.category_id = ? ORDER BY q.is_favorite DESC, RANDOM()");
        $candStmt->execute([(int) $srqRow['category_id']]);
        $replacement = null;
        foreach ($candStmt->fetchAll() as $cand) {
            $cId = (int) $cand['id'];
            $norm = $this->normalizeAnswerForShow((string) $cand['answer']);
            if (!isset($usedIds[$cId]) && ($norm === '' || !isset($usedNormAnswers[$norm]))) {
                $replacement = $cand;
                break;
            }
        }

        if (!$replacement) {
            // Fallback to any category
            $fbStmt = $pdo->query("SELECT q.* FROM questions q WHERE {$poolCond} ORDER BY q.is_favorite DESC, RANDOM()");
            foreach ($fbStmt->fetchAll() as $cand) {
                $cId = (int) $cand['id'];
                $norm = $this->normalizeAnswerForShow((string) $cand['answer']);
                if (!isset($usedIds[$cId]) && ($norm === '' || !isset($usedNormAnswers[$norm]))) {
                    $replacement = $cand;
                    break;
                }
            }
        }

        if (!$replacement) {
            if ($isJsonReq) {
                return $this->json(['error' => 'No alternative unused puzzles available in pool'], Response::HTTP_BAD_REQUEST);
            }
            $this->addFlash('error', 'No alternative unused puzzles available to swap.');
            return $this->redirect($this->generateUrl('app_show_manage', ['id' => $id]) . '#tab-rounds');
        }

        $pdo->prepare("DELETE FROM show_round_questions WHERE show_id = ? AND question_id = ? AND round_id IN (SELECT id FROM show_rounds WHERE show_id = ? AND round_type = 'rapid_fire')")
            ->execute([$id, (int) $replacement['id'], $id]);
        $pdo->prepare('UPDATE show_round_questions SET question_id = ?, is_manual = 0 WHERE id = ?')
            ->execute([(int) $replacement['id'], (int) $srqRow['id']]);

        $this->syncRoundAllocationsFromAssigned($id, $roundId);
        $this->syncRapidFireRoundsForShow($id);

        $allRoundQuestions = $this->getRoundQuestionsMap($id);
        $roundQuestions = $allRoundQuestions[$roundId] ?? [];
        $rapidFireRounds = $this->getRapidFireRoundsPayload($id, $allRoundQuestions);
        if ($isJsonReq) {
            return $this->json([
                'success' => true,
                'swapped_out_id' => $questionId,
                'new_question_id' => (int) $replacement['id'],
                'round_questions' => $roundQuestions,
                'rapid_fire_rounds' => $rapidFireRounds,
            ]);
        }

        $this->addFlash('success', "Swapped puzzle #{$questionId} with #{$replacement['id']} ({$replacement['answer']}).");
        return $this->redirect($this->generateUrl('app_show_manage', ['id' => $id]) . '#tab-rounds');
    }

    #[Route('/shows/{id}/rounds/{roundId}/questions/randomize', name: 'app_show_round_questions_randomize', methods: ['POST'])]
    public function randomizeRoundQuestions(int $id, int $roundId, Request $request): Response
    {
        $this->getShowOrFail($id);
        $payload = json_decode($request->getContent(), true) ?: $request->request->all();
        $mode = (string) ($payload['mode'] ?? 'replace_all');
        $fillOnly = ($mode === 'fill_quota');

        $this->populateRoundQuestionsForShow($id, $roundId, $fillOnly);

        $isJsonReq = str_contains((string) $request->headers->get('Content-Type', ''), 'application/json')
            || $request->isXmlHttpRequest()
            || str_contains((string) $request->headers->get('Accept', ''), 'application/json');

        $allRoundQuestions = $this->getRoundQuestionsMap($id);
        $roundQuestions = $allRoundQuestions[$roundId] ?? [];
        $rapidFireRounds = $this->getRapidFireRoundsPayload($id, $allRoundQuestions);
        if ($isJsonReq) {
            return $this->json([
                'success' => true,
                'round_questions' => $roundQuestions,
                'rapid_fire_rounds' => $rapidFireRounds,
            ]);
        }

        $this->addFlash('success', $fillOnly ? 'Auto-filled remaining round questions randomly.' : 'Randomly selected a fresh set of puzzles for the round.');
        return $this->redirect($this->generateUrl('app_show_manage', ['id' => $id]) . '#tab-rounds');
    }

    #[Route('/shows/{id}/rounds/{roundId}/questions/reorder', name: 'app_show_round_questions_reorder', methods: ['POST'])]
    public function reorderRoundQuestions(int $id, int $roundId, Request $request): Response
    {
        $pdo = $this->gameDatabase->getPdo();
        $this->getShowOrFail($id);

        $rStmt = $pdo->prepare('SELECT * FROM show_rounds WHERE id = ? AND show_id = ?');
        $rStmt->execute([$roundId, $id]);
        $round = $rStmt->fetch();
        if (!$round) {
            throw $this->createNotFoundException('Round not found.');
        }

        $isJsonReq = str_contains((string) $request->headers->get('Content-Type', ''), 'application/json')
            || $request->isXmlHttpRequest()
            || str_contains((string) $request->headers->get('Accept', ''), 'application/json');

        if (($round['round_type'] ?? '') === 'rapid_fire') {
            $msg = 'Rapid Fire round order is randomized automatically and cannot be manually changed.';
            if ($isJsonReq) {
                return $this->json(['success' => false, 'error' => $msg], Response::HTTP_BAD_REQUEST);
            }
            $this->addFlash('error', $msg);
            return $this->redirect($this->generateUrl('app_show_manage', ['id' => $id]) . '#tab-rounds');
        }

        $payload = json_decode($request->getContent(), true) ?: $request->request->all();

        $curStmt = $pdo->prepare('SELECT question_id FROM show_round_questions WHERE show_id = ? AND round_id = ? ORDER BY sort_order ASC, id ASC');
        $curStmt->execute([$id, $roundId]);
        $currentOrder = array_values(array_map('intval', $curStmt->fetchAll(PDO::FETCH_COLUMN)));

        $newOrder = [];
        if (!empty($payload['question_ids']) && is_array($payload['question_ids'])) {
            $provided = array_values(array_unique(array_map('intval', $payload['question_ids'])));
            $currentSet = array_flip($currentOrder);
            foreach ($provided as $qId) {
                if (isset($currentSet[$qId])) {
                    $newOrder[] = $qId;
                    unset($currentSet[$qId]);
                }
            }
            // Append any remaining questions in the round that weren't in payload
            foreach (array_keys($currentSet) as $remQId) {
                $newOrder[] = (int) $remQId;
            }
        } elseif (!empty($payload['question_id'])) {
            $targetQId = (int) $payload['question_id'];
            $direction = strtolower(trim((string) ($payload['direction'] ?? '')));
            $idx = array_search($targetQId, $currentOrder, true);
            $newOrder = $currentOrder;
            if ($idx !== false) {
                $swapIdx = null;
                if ($direction === 'left' || $direction === 'up') {
                    $swapIdx = $idx - 1;
                } elseif ($direction === 'right' || $direction === 'down') {
                    $swapIdx = $idx + 1;
                } elseif (isset($payload['target_index'])) {
                    $swapIdx = max(0, min(count($newOrder) - 1, (int) $payload['target_index']));
                }

                if ($swapIdx !== null && isset($newOrder[$swapIdx]) && $swapIdx !== $idx) {
                    $item = $newOrder[$idx];
                    array_splice($newOrder, $idx, 1);
                    array_splice($newOrder, $swapIdx, 0, [$item]);
                }
            }
        }

        if (!empty($newOrder)) {
            $pdo->beginTransaction();
            try {
                $upd = $pdo->prepare('UPDATE show_round_questions SET sort_order = ? WHERE show_id = ? AND round_id = ? AND question_id = ?');
                foreach ($newOrder as $pos => $qId) {
                    $upd->execute([$pos + 1, $id, $roundId, $qId]);
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }
        }

        $isJsonReq = str_contains((string) $request->headers->get('Content-Type', ''), 'application/json')
            || $request->isXmlHttpRequest()
            || str_contains((string) $request->headers->get('Accept', ''), 'application/json');

        $roundQuestions = $this->getRoundQuestionsMap($id)[$roundId] ?? [];
        if ($isJsonReq) {
            return $this->json([
                'success' => true,
                'round_questions' => $roundQuestions,
            ]);
        }

        return $this->redirect($this->generateUrl('app_show_manage', ['id' => $id]) . '#tab-rounds');
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

        $teams = $pdo->prepare('SELECT * FROM show_teams WHERE show_id = ? ORDER BY is_eliminated ASC, score DESC, sort_order ASC, id ASC');
        $teams->execute([$id]);
        $teamList = $teams->fetchAll();

        $catRows = $pdo->query('SELECT id, name, icon, color FROM categories')->fetchAll();
        $catsById = [];
        foreach ($catRows as $c) {
            $catsById[(int) $c['id']] = $c;
        }

        $srqCountStmt = $pdo->prepare('SELECT round_id, COUNT(*) as cnt FROM show_round_questions WHERE show_id = ? GROUP BY round_id');
        $srqCountStmt->execute([$id]);
        $srqCountsByRound = $srqCountStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

        $rounds = $pdo->prepare('SELECT * FROM show_rounds WHERE show_id = ? ORDER BY round_number ASC');
        $rounds->execute([$id]);
        $roundList = $rounds->fetchAll();
        foreach ($roundList as &$r) {
            $allocs = json_decode($r['category_allocations'] ?: '[]', true) ?: [];
            $totalQ = 0;
            foreach ($allocs as &$a) {
                $cId = (int) ($a['category_id'] ?? 0);
                $cnt = (int) ($a['count'] ?? 0);
                $totalQ += $cnt;
                $a['category_name'] = $catsById[$cId]['name'] ?? 'General';
                $a['category_icon'] = $catsById[$cId]['icon'] ?? '🧩';
                $a['category_color'] = $catsById[$cId]['color'] ?? '#f97316';
            }
            unset($a);
            $r['category_allocations'] = $allocs;
            $assignedCnt = (int) ($srqCountsByRound[(int) $r['id']] ?? 0);
            $r['total_questions'] = $assignedCnt > 0 ? $assignedCnt : $totalQ;
            $r['description'] = $this->buildDynamicRoundDescription($r);
            $r['settings_summary'] = $r['description'];
        }
        unset($r);

        $currentRound = null;
        $nextRound = null;
        if ($show['current_round_id']) {
            foreach ($roundList as $idx => $r) {
                if ((int) $r['id'] === (int) $show['current_round_id']) {
                    $currentRound = $r;
                    $nextRound = $roundList[$idx + 1] ?? null;
                    break;
                }
            }
        }

        $roundQuota = 0;
        $usedInCurrentRound = 0;
        if ($currentRound) {
            $roundQuota = (int) ($currentRound['total_questions'] ?? 0);
            $rUsedStmt = $pdo->prepare('SELECT COUNT(*) FROM show_used_questions WHERE show_id = ? AND round_id = ?');
            $rUsedStmt->execute([$id, $currentRound['id']]);
            $usedInCurrentRound = (int) $rUsedStmt->fetchColumn();
        }

        $currentQuestion = null;
        if ($show['current_question_id']) {
            $qStmt = $pdo->prepare('SELECT q.*, c.name as category_name, c.color as category_color, c.icon as category_icon 
                FROM questions q JOIN categories c ON q.category_id = c.id WHERE q.id = ?');
            $qStmt->execute([$show['current_question_id']]);
            $currentQuestion = $qStmt->fetch();
        } elseif (($show['status'] ?? '') === 'sample_puzzle') {
            $currentQuestion = [
                'id' => -1,
                'is_sample' => true,
                'title' => 'Sample Tryout Puzzle',
                'prompt_text' => 'Practice Rebus Puzzle — Say What You See!',
                'answer' => 'FALL PARTY',
                'media_path' => '/media/sample_fall_party.jpg',
                'difficulty' => 'Tryout (No Points)',
                'category_name' => 'Sample Tryout Puzzle',
                'category_color' => '#f59e0b',
                'category_icon' => '🎯',
            ];
        }

        $usedCount = (int) $pdo->query("SELECT COUNT(*) FROM show_used_questions WHERE show_id = {$id}")->fetchColumn();

        $mobileHostUrl = $this->getHostLanUrl($request, "/shows/{$id}/play");

        return $this->json([
            'show' => $show,
            'teams' => $teamList,
            'rounds' => $roundList,
            'rules' => $this->gameDatabase->getShowRules($id),
            'current_round' => $currentRound,
            'round_progress' => [
                'used' => $usedInCurrentRound,
                'total' => $roundQuota,
                'is_completed' => $currentRound ? (($currentRound['status'] ?? '') === 'completed') : false,
                'next_round' => $nextRound,
            ],
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

            case 'show_splash':
                $pdo->prepare('UPDATE game_shows SET status = "setup", current_question_id = NULL, active_rule_index = -1, active_team_index = -1, question_started_at = NULL, answer_revealed = 0 WHERE id = ?')
                    ->execute([$id]);
                return $this->json(['success' => true, 'stage' => 'setup', 'active_team_index' => -1]);

            case 'show_teams':
                $teamIdx = (int) ($payload['team_index'] ?? 0);
                $pdo->prepare('UPDATE game_shows SET status = "teams_intro", current_question_id = NULL, active_rule_index = -1, active_team_index = ?, question_started_at = NULL, answer_revealed = 0 WHERE id = ?')
                    ->execute([$teamIdx, $id]);
                return $this->json(['success' => true, 'stage' => 'teams_intro', 'active_team_index' => $teamIdx]);

            case 'prev_team':
                $currTIdx = (int) ($show['active_team_index'] ?? 0);
                $prevTIdx = max(0, $currTIdx - 1);
                $pdo->prepare('UPDATE game_shows SET status = "teams_intro", active_team_index = ?, active_rule_index = -1 WHERE id = ?')
                    ->execute([$prevTIdx, $id]);
                return $this->json(['success' => true, 'stage' => 'teams_intro', 'active_team_index' => $prevTIdx]);

            case 'finish_teams':
                $rules = $this->gameDatabase->getShowRules($id);
                if (!empty($rules)) {
                    $pdo->prepare('UPDATE game_shows SET status = "rules_intro", active_team_index = -1, active_rule_index = 0, current_question_id = NULL WHERE id = ?')
                        ->execute([$id]);
                    return $this->json(['success' => true, 'stage' => 'rules_intro', 'active_rule_index' => 0, 'active_team_index' => -1]);
                }
                $sampleTimer = (int) ($show['default_timer_seconds'] ?? 25) ?: 25;
                $pdo->prepare('UPDATE game_shows SET status = "sample_puzzle", active_team_index = -1, active_rule_index = -1, current_question_id = NULL, question_started_at = ?, timer_duration = ?, answer_revealed = 0 WHERE id = ?')
                    ->execute([time(), $sampleTimer, $id]);
                return $this->json(['success' => true, 'stage' => 'sample_puzzle', 'active_rule_index' => -1, 'active_team_index' => -1, 'sample_puzzle' => true]);

            case 'start_sample_puzzle':
                $sampleTimer = (int) ($show['default_timer_seconds'] ?? 25) ?: 25;
                $pdo->prepare('UPDATE game_shows SET status = "sample_puzzle", active_team_index = -1, active_rule_index = -1, current_question_id = NULL, question_started_at = ?, timer_duration = ?, answer_revealed = 0 WHERE id = ?')
                    ->execute([time(), $sampleTimer, $id]);
                return $this->json(['success' => true, 'stage' => 'sample_puzzle', 'sample_puzzle' => true]);

            case 'show_round_intro':
                $pdo->prepare('UPDATE game_shows SET status = "round_intro", current_question_id = NULL, active_rule_index = -1, active_team_index = -1, question_started_at = NULL, answer_revealed = 0 WHERE id = ?')
                    ->execute([$id]);
                return $this->json(['success' => true, 'stage' => 'round_intro']);

            case 'next_team':
            case 'host_next':
                $showStatus = (string) ($show['status'] ?? 'setup');
                $currQIdCheck = (int) ($show['current_question_id'] ?? 0);
                $currRuleIdx = (int) ($show['active_rule_index'] ?? -1);
                $currTeamIdx = (int) ($show['active_team_index'] ?? -1);

                // Stage 1: Welcome Splash Screen -> Show Team #1 on TV
                if ($action !== 'next_team' && $showStatus === 'setup' && $currQIdCheck === 0 && $currRuleIdx < 0) {
                    $pdo->prepare('UPDATE game_shows SET status = "teams_intro", current_question_id = NULL, active_rule_index = -1, active_team_index = 0 WHERE id = ?')
                        ->execute([$id]);
                    return $this->json(['success' => true, 'stage' => 'teams_intro', 'active_team_index' => 0]);
                }

                // Stage 2: Competing Teams 1-by-1 -> Next Team or Unveil Rules (or Sample Puzzle if no rules)
                if (($showStatus === 'teams_intro' || $action === 'next_team') && $currQIdCheck === 0 && $currRuleIdx < 0) {
                    $teamCount = (int) $pdo->query("SELECT COUNT(*) FROM show_teams WHERE show_id = {$id}")->fetchColumn();
                    $effectiveCurrTeamIdx = max(0, $currTeamIdx);
                    $nextTeamIdx = $effectiveCurrTeamIdx + 1;
                    if ($nextTeamIdx < $teamCount) {
                        $pdo->prepare('UPDATE game_shows SET status = "teams_intro", active_team_index = ?, active_rule_index = -1, current_question_id = NULL WHERE id = ?')
                            ->execute([$nextTeamIdx, $id]);
                        return $this->json(['success' => true, 'stage' => 'teams_intro', 'active_team_index' => $nextTeamIdx]);
                    }

                    $rules = $this->gameDatabase->getShowRules($id);
                    if (!empty($rules)) {
                        $pdo->prepare('UPDATE game_shows SET status = "rules_intro", active_team_index = -1, active_rule_index = 0, current_question_id = NULL WHERE id = ?')
                            ->execute([$id]);
                        return $this->json(['success' => true, 'stage' => 'rules_intro', 'active_rule_index' => 0, 'active_team_index' => -1]);
                    }
                    $sampleTimer = (int) ($show['default_timer_seconds'] ?? 25) ?: 25;
                    $pdo->prepare('UPDATE game_shows SET status = "sample_puzzle", active_team_index = -1, active_rule_index = -1, current_question_id = NULL, question_started_at = ?, timer_duration = ?, answer_revealed = 0 WHERE id = ?')
                        ->execute([time(), $sampleTimer, $id]);
                    return $this->json(['success' => true, 'stage' => 'sample_puzzle', 'active_rule_index' => -1, 'active_team_index' => -1, 'sample_puzzle' => true]);
                }

                // Stage 3: Unveiling Rules 1-by-1 -> Next Rule or Sample Tryout Puzzle
                if ($currRuleIdx >= 0 || ($showStatus === 'rules_intro' && $currQIdCheck === 0)) {
                    $rules = $this->gameDatabase->getShowRules($id);
                    $nextRuleIdx = $currRuleIdx + 1;
                    if ($nextRuleIdx < count($rules)) {
                        $pdo->prepare('UPDATE game_shows SET status = "rules_intro", active_rule_index = ? WHERE id = ?')
                            ->execute([$nextRuleIdx, $id]);
                        return $this->json(['success' => true, 'stage' => 'rules_intro', 'active_rule_index' => $nextRuleIdx]);
                    }
                    $sampleTimer = (int) ($show['default_timer_seconds'] ?? 25) ?: 25;
                    $pdo->prepare('UPDATE game_shows SET status = "sample_puzzle", active_team_index = -1, active_rule_index = -1, current_question_id = NULL, question_started_at = ?, timer_duration = ?, answer_revealed = 0 WHERE id = ?')
                        ->execute([time(), $sampleTimer, $id]);
                    return $this->json(['success' => true, 'stage' => 'sample_puzzle', 'active_rule_index' => -1, 'sample_puzzle' => true]);
                }

                // Stage 3.5: Sample Tryout Puzzle -> Round 1 Briefing & Rules
                if ($showStatus === 'sample_puzzle' && $currQIdCheck === 0) {
                    $pdo->prepare('UPDATE game_shows SET status = "round_intro", active_team_index = -1, active_rule_index = -1, current_question_id = NULL, question_started_at = NULL, answer_revealed = 0 WHERE id = ?')
                        ->execute([$id]);
                    return $this->json(['success' => true, 'stage' => 'round_intro', 'active_rule_index' => -1]);
                }
                // Stage 4+: Round Briefing -> Start Round, or Active Question -> Next Question / End Round / Next Round Briefing
                // Fallthrough to next_question!

            case 'start_round':
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

                // If the current round was already marked completed and the host clicks Next again,
                // transition to the Next Round Intro/Rules screen (status = "pending", current_question_id = NULL)
                if (($round['status'] ?? '') === 'completed' && empty($payload['round_id'])) {
                    $nextRStmt = $pdo->prepare('SELECT * FROM show_rounds WHERE show_id = ? AND round_number > ? ORDER BY round_number ASC LIMIT 1');
                    $nextRStmt->execute([$id, (int) $round['round_number']]);
                    $nextRoundRow = $nextRStmt->fetch();
                    if (!$nextRoundRow) {
                        return $this->json([
                            'success' => true,
                            'round_completed' => true,
                            'show_completed' => true,
                            'round_id' => $roundId,
                            'round_number' => (int) $round['round_number'],
                            'round_name' => $round['name'],
                        ]);
                    }

                    // Apply top_x eliminations if configured on the completed round
                    if (($round['advancement_type'] ?? '') === 'top_x') {
                        $topX = (int) $round['advance_top_x'];
                        $tStmt = $pdo->prepare('SELECT id FROM show_teams WHERE show_id = ? AND is_eliminated = 0 ORDER BY score DESC, id ASC');
                        $tStmt->execute([$id]);
                        $activeTeams = $tStmt->fetchAll(PDO::FETCH_COLUMN);
                        if (count($activeTeams) > $topX) {
                            $toEliminate = array_slice($activeTeams, $topX);
                            $elimStmt = $pdo->prepare('UPDATE show_teams SET is_eliminated = 1, eliminated_in_round = ? WHERE id = ? AND show_id = ?');
                            foreach ($toEliminate as $elimId) {
                                $elimStmt->execute([$round['round_number'], $elimId, $id]);
                            }
                        }
                    }

                    $nextRoundId = (int) $nextRoundRow['id'];
                    $nextTimerSec = (int) ($nextRoundRow['timer_seconds'] ?: 25);
                    $pdo->prepare('UPDATE show_teams SET round_score = 0 WHERE show_id = ?')->execute([$id]);
                    $pdo->prepare('UPDATE show_rounds SET status = "pending" WHERE id = ? AND show_id = ?')->execute([$nextRoundId, $id]);
                    $pdo->prepare('UPDATE game_shows SET status = "round_intro", current_round_id = ?, current_question_id = NULL, question_started_at = NULL, timer_duration = ?, answer_revealed = 0 WHERE id = ?')
                        ->execute([$nextRoundId, $nextTimerSec, $id]);

                    return $this->json([
                        'success' => true,
                        'round_intro' => true,
                        'round_id' => $nextRoundId,
                        'round_number' => (int) $nextRoundRow['round_number'],
                        'round_name' => $nextRoundRow['name'],
                    ]);
                }

                $allocations = json_decode($round['category_allocations'] ?: '[]', true);

                // Check quota fulfillment in current round
                $usedInRoundStmt = $pdo->prepare('SELECT category_id, COUNT(*) as cnt FROM show_used_questions WHERE show_id = ? AND round_id = ? GROUP BY category_id');
                $usedInRoundStmt->execute([$id, $roundId]);
                $usedInRound = $usedInRoundStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

                $assignedCntStmt = $pdo->prepare('SELECT COUNT(*) FROM show_round_questions WHERE show_id = ? AND round_id = ?');
                $assignedCntStmt->execute([$id, $roundId]);
                $assignedCount = (int) $assignedCntStmt->fetchColumn();

                $roundQuota = 0;
                if ($assignedCount > 0) {
                    $roundQuota = $assignedCount;
                } else {
                    foreach ($allocations as $alloc) {
                        $roundQuota += (int) ($alloc['count'] ?? 0);
                    }
                }
                $totalUsedInRound = array_sum(array_map('intval', $usedInRound));

                // Automatically end the round when all allocated questions for this round have been completed!
                if ($roundQuota > 0 && $totalUsedInRound >= $roundQuota) {
                    $pdo->prepare('UPDATE show_rounds SET status = "completed" WHERE id = ? AND show_id = ?')->execute([$roundId, $id]);
                    $pdo->prepare('UPDATE game_shows SET current_round_id = ?, current_question_id = NULL, question_started_at = NULL, answer_revealed = 0 WHERE id = ?')
                        ->execute([$roundId, $id]);

                    return $this->json([
                        'success' => true,
                        'round_completed' => true,
                        'round_id' => $roundId,
                        'round_number' => (int) $round['round_number'],
                        'round_name' => $round['name'],
                        'questions_completed' => $totalUsedInRound,
                    ]);
                }

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
                if (!empty($activeAllocations)) {
                    shuffle($activeAllocations);
                }
                $question = null;

                $isStarredOnly = ((int) ($show['starred_only'] ?? 0)) === 1;
                $poolCondition = $isStarredOnly
                    ? "q.is_favorite = 1 AND q.is_active = 1 AND q.review_status = 'included'"
                    : "q.is_active = 1 AND q.review_status = 'included'";

                // 0. If this round has assigned questions in show_round_questions, pick the next unused assigned question!
                if ($assignedCount > 0) {
                    $srqPickStmt = $pdo->prepare("
                        SELECT q.*
                        FROM show_round_questions srq
                        JOIN questions q ON q.id = srq.question_id
                        WHERE srq.show_id = ? AND srq.round_id = ?
                          AND q.is_active = 1 AND q.review_status = 'included'
                          AND q.id NOT IN (SELECT question_id FROM show_used_questions WHERE show_id = ?)
                        ORDER BY srq.sort_order ASC, srq.id ASC
                        LIMIT 1
                    ");
                    $srqPickStmt->execute([$id, $roundId, $id]);
                    $srqCandidate = $srqPickStmt->fetch();
                    if ($srqCandidate) {
                        $question = $srqCandidate;
                    }
                }

                // 1. Dynamic fallback: Pick a random unused Included question across ALL active allocations in the round,
                // prioritizing Starred (is_favorite = 1) over Unstarred (is_favorite = 0), and avoiding any duplicate answer in the show.
                $noShowDupSql = "q.id NOT IN (SELECT question_id FROM show_used_questions WHERE show_id = ?)
                    AND q.id NOT IN (SELECT question_id FROM show_round_questions WHERE show_id = ?)
                    AND LOWER(TRIM(q.answer)) NOT IN (
                        SELECT LOWER(TRIM(q2.answer)) FROM show_used_questions uq JOIN questions q2 ON q2.id = uq.question_id WHERE uq.show_id = ?
                        UNION
                        SELECT LOWER(TRIM(q3.answer)) FROM show_round_questions srq JOIN questions q3 ON q3.id = srq.question_id WHERE srq.show_id = ?
                    )";

                if (!$question && !empty($activeAllocations)) {
                    $orClauses = [];
                    $params = [$id, $id, $id, $id];
                    foreach ($activeAllocations as $alloc) {
                        $catId = (int) $alloc['category_id'];
                        $diff = $alloc['difficulty'] ?? 'Any';
                        if ($diff !== 'Any') {
                            $orClauses[] = '(q.category_id = ? AND (q.difficulty = ? OR (? = "Simple" AND q.difficulty = "Easy")))';
                            $params[] = $catId;
                            $params[] = $diff;
                            $params[] = $diff;
                        } else {
                            $orClauses[] = '(q.category_id = ?)';
                            $params[] = $catId;
                        }
                    }

                    $orSql = implode(' OR ', $orClauses);
                    $sql = "SELECT q.* FROM questions q WHERE {$poolCondition} AND {$noShowDupSql} AND ({$orSql}) ORDER BY q.is_favorite DESC, RANDOM() LIMIT 1";
                    $qStmt = $pdo->prepare($sql);
                    $qStmt->execute($params);
                    $candidate = $qStmt->fetch();
                    if ($candidate) {
                        $question = $candidate;
                    } else {
                        // 1b. If not found with exact difficulty, pick randomly across active allocation categories at any difficulty (Starred prioritized)
                        $activeCatIds = array_values(array_unique(array_map(fn ($a) => (int) $a['category_id'], $activeAllocations)));
                        $placeholders = implode(',', array_fill(0, count($activeCatIds), '?'));
                        $fbCatStmt = $pdo->prepare("SELECT q.* FROM questions q WHERE {$poolCondition} AND {$noShowDupSql} AND q.category_id IN ({$placeholders}) ORDER BY q.is_favorite DESC, RANDOM() LIMIT 1");
                        $fbCatStmt->execute(array_merge([$id, $id, $id, $id], $activeCatIds));
                        $candidate = $fbCatStmt->fetch();
                        if ($candidate) {
                            $question = $candidate;
                        }
                    }
                }

                // 2. Fallback: Any unused Included question from the configured pool in any allocated category (Starred prioritized)
                if (!$question && !empty($allocations)) {
                    $catIds = array_values(array_unique(array_map(fn ($a) => (int) $a['category_id'], $allocations)));
                    $placeholders = implode(',', array_fill(0, count($catIds), '?'));
                    $params = array_merge([$id, $id, $id, $id], $catIds);
                    $fbAllocStmt = $pdo->prepare("SELECT q.* FROM questions q WHERE {$poolCondition} AND {$noShowDupSql} AND q.category_id IN ({$placeholders}) ORDER BY q.is_favorite DESC, RANDOM() LIMIT 1");
                    $fbAllocStmt->execute($params);
                    $question = $fbAllocStmt->fetch();
                }

                // 3. Fallback: Any unused Included question from the configured pool in entire master bank (Starred prioritized)
                if (!$question) {
                    $fbStmt = $pdo->prepare("SELECT q.* FROM questions q WHERE {$poolCondition} AND {$noShowDupSql} ORDER BY q.is_favorite DESC, RANDOM() LIMIT 1");
                    $fbStmt->execute([$id, $id, $id, $id]);
                    $question = $fbStmt->fetch();
                }

                if (!$question) {
                    $errMsg = $isStarredOnly
                        ? 'All available Starred & Included puzzles have been used for this game show! Star more Included puzzles in the Question Bank or refresh the show.'
                        : 'All available Included questions have been used for this game show!';
                    return $this->json(['error' => $errMsg], Response::HTTP_BAD_REQUEST);
                }

                $timerSec = $round['timer_seconds'] ?: 25;
                $pdo->prepare('UPDATE show_rounds SET status = "active" WHERE id = ? AND show_id = ?')->execute([$roundId, $id]);
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

                $pdo->prepare('UPDATE game_shows SET status = "round_intro", current_round_id = ?, current_question_id = NULL, answer_revealed = 0, question_started_at = NULL WHERE id = ?')->execute([$nextRoundId, $id]);
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
                $nextStatus = empty($show['current_question_id']) ? 'rules_intro' : ($show['status'] ?: 'active');
                $pdo->prepare('UPDATE game_shows SET status = ?, active_team_index = -1, active_rule_index = ? WHERE id = ?')->execute([$nextStatus, $ruleIdx, $id]);
                return $this->json(['success' => true, 'active_rule_index' => $ruleIdx, 'stage' => $nextStatus]);

            case 'next_rule':
                $rules = $this->gameDatabase->getShowRules($id);
                $currIdx = (int) ($show['active_rule_index'] ?? -1);
                $nextIdx = $currIdx + 1;
                if ($nextIdx >= count($rules)) {
                    $nextIdx = -1; // End of rules -> transition to sample_puzzle if no question active
                    if (empty($show['current_question_id'])) {
                        $sampleTimer = (int) ($show['default_timer_seconds'] ?? 25) ?: 25;
                        $pdo->prepare('UPDATE game_shows SET status = "sample_puzzle", active_rule_index = -1, question_started_at = ?, timer_duration = ?, answer_revealed = 0 WHERE id = ?')
                            ->execute([time(), $sampleTimer, $id]);
                        return $this->json(['success' => true, 'active_rule_index' => -1, 'stage' => 'sample_puzzle', 'sample_puzzle' => true]);
                    }
                    $nextStatus = $show['status'] ?: 'active';
                    $pdo->prepare('UPDATE game_shows SET status = ?, active_rule_index = -1 WHERE id = ?')->execute([$nextStatus, $id]);
                    return $this->json(['success' => true, 'active_rule_index' => -1, 'stage' => $nextStatus]);
                }
                $pdo->prepare('UPDATE game_shows SET active_rule_index = ? WHERE id = ?')->execute([$nextIdx, $id]);
                return $this->json(['success' => true, 'active_rule_index' => $nextIdx, 'stage' => 'rules_intro']);

            case 'prev_rule':
                $currIdx = (int) ($show['active_rule_index'] ?? 0);
                $prevIdx = max(0, $currIdx - 1);
                $pdo->prepare('UPDATE game_shows SET active_rule_index = ? WHERE id = ?')->execute([$prevIdx, $id]);
                return $this->json(['success' => true, 'active_rule_index' => $prevIdx, 'stage' => 'rules_intro']);

            case 'close_rules':
                $nextStatus = empty($show['current_question_id']) ? 'round_intro' : ($show['status'] ?: 'active');
                $pdo->prepare('UPDATE game_shows SET status = ?, active_rule_index = -1 WHERE id = ?')->execute([$nextStatus, $id]);
                return $this->json(['success' => true, 'active_rule_index' => -1, 'stage' => $nextStatus]);

            case 'finish_rules':
                if (empty($show['current_question_id'])) {
                    $sampleTimer = (int) ($show['default_timer_seconds'] ?? 25) ?: 25;
                    $pdo->prepare('UPDATE game_shows SET status = "sample_puzzle", active_rule_index = -1, question_started_at = ?, timer_duration = ?, answer_revealed = 0 WHERE id = ?')
                        ->execute([time(), $sampleTimer, $id]);
                    return $this->json(['success' => true, 'active_rule_index' => -1, 'stage' => 'sample_puzzle', 'sample_puzzle' => true]);
                }
                $nextStatus = $show['status'] ?: 'active';
                $pdo->prepare('UPDATE game_shows SET status = ?, active_rule_index = -1 WHERE id = ?')->execute([$nextStatus, $id]);
                return $this->json(['success' => true, 'active_rule_index' => -1, 'stage' => $nextStatus]);

            case 'play_sfx':
                $sfx = (string) ($payload['sfx'] ?? '');
                $allowedSfx = ['drumroll', 'sad_trombone', 'dun_dun_dun', 'wrong_buzz', 'applause', 'ipl_horn', 'cheers_clink', 'record_scratch', 'boxing_bell', 'magic_sparkle', 'kahan_se_aate', 'mogambo_khush_hua', 'control_uday', 'tussi_great_ho', 'nana_laugh', 'ruko_zara'];
                if (!in_array($sfx, $allowedSfx, true)) {
                    return $this->json(['success' => false, 'error' => 'Invalid SFX name'], Response::HTTP_BAD_REQUEST);
                }
                $sfxAt = (int) round(microtime(true) * 1000);
                $pdo->prepare('UPDATE game_shows SET last_sfx_name = ?, last_sfx_at = ? WHERE id = ?')->execute([$sfx, $sfxAt, $id]);
                return $this->json(['success' => true, 'sfx' => $sfx, 'last_sfx_at' => $sfxAt]);

            case 'reset_show':
                $pdo->beginTransaction();
                try {
                    $pdo->prepare('DELETE FROM show_used_questions WHERE show_id = ?')->execute([$id]);
                    $pdo->prepare('UPDATE show_teams SET score = 0, round_score = 0, is_eliminated = 0, eliminated_in_round = NULL WHERE show_id = ?')->execute([$id]);
                    $pdo->prepare('UPDATE show_rounds SET status = "pending" WHERE show_id = ?')->execute([$id]);
                    $firstRound = (int) $pdo->query("SELECT id FROM show_rounds WHERE show_id = {$id} ORDER BY round_number ASC LIMIT 1")->fetchColumn();
                    $pdo->prepare('UPDATE game_shows SET status = "setup", current_round_id = ?, current_question_id = NULL, active_matchup_label = "", active_team_a_id = NULL, active_team_b_id = NULL, question_started_at = NULL, answer_revealed = 0, active_rule_index = -1, active_team_index = -1 WHERE id = ?')
                        ->execute([$firstRound ?: null, $id]);
                    $pdo->commit();
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
                return $this->json(['success' => true, 'reset_type' => 'show']);

            case 'reset_questions':
                $pdo->beginTransaction();
                try {
                    $pdo->prepare('DELETE FROM show_used_questions WHERE show_id = ?')->execute([$id]);
                    $pdo->prepare('UPDATE game_shows SET current_question_id = NULL, question_started_at = NULL, answer_revealed = 0 WHERE id = ?')
                        ->execute([$id]);
                    $pdo->commit();
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
                $this->populateRoundQuestionsForShow($id);
                return $this->json(['success' => true, 'reset_type' => 'questions']);

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

        // Preserve Rapid Fire round if one was already enabled on this show
        $hadRapidFire = (int) $pdo->query("SELECT COUNT(*) FROM show_rounds WHERE show_id = {$showId} AND round_type = 'rapid_fire'")->fetchColumn() > 0;

        // Replace rounds
        $pdo->prepare('DELETE FROM show_round_questions WHERE show_id = ?')->execute([$showId]);
        $pdo->prepare('DELETE FROM show_rounds WHERE show_id = ?')->execute([$showId]);

        $rStmt = $pdo->prepare('INSERT INTO show_rounds (show_id, round_number, name, description, round_type, timer_seconds, points_per_question, steal_points, advancement_type, advance_top_x, category_allocations, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending")');
        foreach ($rounds as $idx => $r) {
            $dynDesc = $this->buildDynamicRoundDescription([
                'round_type' => $r['round_type'],
                'timer_seconds' => $r['timer_seconds'],
                'points_per_question' => $r['points'],
                'steal_points' => $r['steal'],
                'advancement_type' => $r['advancement'],
                'advance_top_x' => $r['top_x'],
            ]);
            $rStmt->execute([
                $showId,
                $idx + 1,
                $r['name'],
                $dynDesc,
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

        if ($hadRapidFire) {
            $this->ensureRapidFireRound($showId, true);
        }

        $this->populateRoundQuestionsForShow($showId);
    }

    public function buildDynamicRoundDescription(array $r): string
    {
        $roundType = (string) ($r['round_type'] ?? 'all_play');
        $typeLabel = match ($roundType) {
            'rapid_fire' => '⚡ Rapid Fire (All Remaining Starred Puzzles)',
            'head_to_head' => 'Head-to-Head Flight Matchup',
            default => 'All Teams Open Play',
        };
        $timer = (int) ($r['timer_seconds'] ?? 25);
        $pts = (int) ($r['points_per_question'] ?? 10);
        $steal = (int) ($r['steal_points'] ?? 0);
        $advType = (string) ($r['advancement_type'] ?? 'all');
        $topX = (int) ($r['advance_top_x'] ?? 5);
        $advLabel = $advType === 'top_x'
            ? "Top {$topX} Teams Advance"
            : 'All Advance';

        return "Type: {$typeLabel} • Timer: {$timer}s • Points: +{$pts} (Steal: +{$steal}) • Advancement: {$advLabel}";
    }

    private function normalizeAnswerForShow(string $answer): string
    {
        $s = strtolower(trim($answer));
        $numMap = [
            '/\bzero\b/' => '0',
            '/\bone\b/' => '1',
            '/\btwo\b/' => '2',
            '/\bthree\b/' => '3',
            '/\bfour\b/' => '4',
            '/\bfive\b/' => '5',
            '/\bsix\b/' => '6',
            '/\bseven\b/' => '7',
            '/\beight\b/' => '8',
            '/\bnine\b/' => '9',
            '/\bten\b/' => '10',
            '/\b1st\b/' => 'first',
            '/\b2nd\b/' => 'second',
            '/\b3rd\b/' => 'third',
            '/\b4th\b/' => 'fourth',
        ];
        $s = preg_replace(array_keys($numMap), array_values($numMap), $s) ?? $s;
        return preg_replace('/[^a-z0-9]+/', '', $s) ?? '';
    }

    private function getRoundQuestionsMap(int $showId): array
    {
        $pdo = $this->gameDatabase->getPdo();
        $usedStmt = $pdo->prepare('SELECT question_id FROM show_used_questions WHERE show_id = ?');
        $usedStmt->execute([$showId]);
        $usedMap = array_fill_keys(array_map('intval', $usedStmt->fetchAll(PDO::FETCH_COLUMN)), true);

        $stmt = $pdo->prepare('
            SELECT srq.id as round_question_id, srq.round_id, srq.question_id as id, srq.sort_order, srq.is_manual,
                   q.category_id, q.title, q.answer, q.difficulty, q.media_path, q.is_favorite, q.review_status,
                   c.name as category_name, c.icon as category_icon, c.color as category_color
            FROM show_round_questions srq
            JOIN questions q ON q.id = srq.question_id
            JOIN categories c ON c.id = q.category_id
            WHERE srq.show_id = ?
            ORDER BY srq.round_id ASC, srq.sort_order ASC, srq.id ASC
        ');
        $stmt->execute([$showId]);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $rId = (int) $row['round_id'];
            $qId = (int) $row['id'];
            $row['id'] = $qId;
            $row['round_id'] = $rId;
            $row['sort_order'] = (int) $row['sort_order'];
            $row['is_manual'] = (int) $row['is_manual'];
            $row['is_favorite'] = (int) $row['is_favorite'];
            $row['is_asked'] = isset($usedMap[$qId]);
            $map[$rId][] = $row;
        }

        return $map;
    }

    public function populateRoundQuestionsForShow(int $showId, ?int $onlyRoundId = null, bool $fillOnly = false): void
    {
        $pdo = $this->gameDatabase->getPdo();
        $show = $this->getShowOrFail($showId);

        if ($onlyRoundId !== null) {
            $targetRStmt = $pdo->prepare('SELECT round_type FROM show_rounds WHERE show_id = ? AND id = ?');
            $targetRStmt->execute([$showId, $onlyRoundId]);
            $targetRoundType = (string) $targetRStmt->fetchColumn();
            if ($targetRoundType === 'rapid_fire') {
                $this->syncRapidFireRoundsForShow($showId);
                return;
            }
        }

        $isStarredOnly = ((int) ($show['starred_only'] ?? 0)) === 1;
        $poolCond = $isStarredOnly
            ? "q.is_favorite = 1 AND q.is_active = 1 AND q.review_status = 'included'"
            : "q.is_active = 1 AND q.review_status = 'included'";

        if (!$fillOnly) {
            if ($onlyRoundId !== null) {
                $pdo->prepare('DELETE FROM show_round_questions WHERE show_id = ? AND round_id = ?')->execute([$showId, $onlyRoundId]);
            } else {
                $pdo->prepare('DELETE FROM show_round_questions WHERE show_id = ?')->execute([$showId]);
            }
        }
        // Clear rapid_fire overflow rows before populating normal rounds so UNIQUE(show_id, question_id) is free; syncRapidFireRoundsForShow repopulates them at the end
        $pdo->prepare("DELETE FROM show_round_questions WHERE show_id = ? AND round_id IN (SELECT id FROM show_rounds WHERE show_id = ? AND round_type = 'rapid_fire')")
            ->execute([$showId, $showId]);

        // Gather all currently assigned/used questions across non-rapid-fire rounds to guarantee 0 duplicates
        $usedIds = [];
        $usedNormAnswers = [];

        $existingStmt = $pdo->prepare("SELECT srq.question_id, srq.round_id, q.category_id, q.answer FROM show_round_questions srq JOIN show_rounds r ON r.id = srq.round_id JOIN questions q ON q.id = srq.question_id WHERE srq.show_id = ? AND r.round_type != 'rapid_fire'");
        $existingStmt->execute([$showId]);
        $existingByRoundCat = [];
        $existingByRoundTotal = [];
        foreach ($existingStmt->fetchAll() as $er) {
            $eqId = (int) $er['question_id'];
            $erId = (int) $er['round_id'];
            $ecId = (int) $er['category_id'];
            $usedIds[$eqId] = true;
            $norm = $this->normalizeAnswerForShow((string) $er['answer']);
            if ($norm !== '') {
                $usedNormAnswers[$norm] = true;
            }
            $existingByRoundCat[$erId][$ecId] = ($existingByRoundCat[$erId][$ecId] ?? 0) + 1;
            $existingByRoundTotal[$erId] = ($existingByRoundTotal[$erId] ?? 0) + 1;
        }

        $usedQStmt = $pdo->prepare('SELECT uq.question_id, q.answer FROM show_used_questions uq JOIN questions q ON q.id = uq.question_id WHERE uq.show_id = ?');
        $usedQStmt->execute([$showId]);
        foreach ($usedQStmt->fetchAll() as $ur) {
            $usedIds[(int) $ur['question_id']] = true;
            $norm = $this->normalizeAnswerForShow((string) $ur['answer']);
            if ($norm !== '') {
                $usedNormAnswers[$norm] = true;
            }
        }

        if ($onlyRoundId !== null) {
            $rStmt = $pdo->prepare('SELECT * FROM show_rounds WHERE show_id = ? AND id = ? ORDER BY round_number ASC');
            $rStmt->execute([$showId, $onlyRoundId]);
        } else {
            $rStmt = $pdo->prepare('SELECT * FROM show_rounds WHERE show_id = ? ORDER BY round_number ASC');
            $rStmt->execute([$showId]);
        }
        $rounds = $rStmt->fetchAll();

        $insStmt = $pdo->prepare('INSERT OR IGNORE INTO show_round_questions (show_id, round_id, question_id, sort_order, is_manual) VALUES (?, ?, ?, ?, 0)');

        foreach ($rounds as $r) {
            if (($r['round_type'] ?? '') === 'rapid_fire') {
                continue;
            }
            $rId = (int) $r['id'];
            $allocs = json_decode($r['category_allocations'] ?: '[]', true) ?: [];
            if (empty($allocs)) {
                continue;
            }

            $pickedForRound = [];
            $totalTargetQuota = 0;

            foreach ($allocs as $alloc) {
                $catId = (int) ($alloc['category_id'] ?? 0);
                $cnt = (int) ($alloc['count'] ?? 0);
                $diff = (string) ($alloc['difficulty'] ?? 'Any');
                if ($cnt <= 0 || $catId <= 0) {
                    continue;
                }
                $totalTargetQuota += $cnt;
                $alreadyInCat = (int) ($existingByRoundCat[$rId][$catId] ?? 0);
                $needed = max(0, $cnt - $alreadyInCat);
                if ($needed <= 0) {
                    continue;
                }

                // 1. Exact category + difficulty match (Starred first, then random)
                if ($diff !== 'Any') {
                    $cStmt = $pdo->prepare("SELECT q.* FROM questions q WHERE {$poolCond} AND q.category_id = ? AND (q.difficulty = ? OR (? = 'Simple' AND q.difficulty = 'Easy')) ORDER BY q.is_favorite DESC, RANDOM()");
                    $cStmt->execute([$catId, $diff, $diff]);
                } else {
                    $cStmt = $pdo->prepare("SELECT q.* FROM questions q WHERE {$poolCond} AND q.category_id = ? ORDER BY q.is_favorite DESC, RANDOM()");
                    $cStmt->execute([$catId]);
                }

                foreach ($cStmt->fetchAll() as $cand) {
                    if ($needed <= 0) {
                        break;
                    }
                    $qId = (int) $cand['id'];
                    $norm = $this->normalizeAnswerForShow((string) $cand['answer']);
                    if (isset($usedIds[$qId]) || ($norm !== '' && isset($usedNormAnswers[$norm]))) {
                        continue;
                    }
                    $usedIds[$qId] = true;
                    if ($norm !== '') {
                        $usedNormAnswers[$norm] = true;
                    }
                    $pickedForRound[] = $cand;
                    --$needed;
                }

                // 2. Fallback: Same category at any difficulty
                if ($needed > 0 && $diff !== 'Any') {
                    $fbCatStmt = $pdo->prepare("SELECT q.* FROM questions q WHERE {$poolCond} AND q.category_id = ? ORDER BY q.is_favorite DESC, RANDOM()");
                    $fbCatStmt->execute([$catId]);
                    foreach ($fbCatStmt->fetchAll() as $cand) {
                        if ($needed <= 0) {
                            break;
                        }
                        $qId = (int) $cand['id'];
                        $norm = $this->normalizeAnswerForShow((string) $cand['answer']);
                        if (isset($usedIds[$qId]) || ($norm !== '' && isset($usedNormAnswers[$norm]))) {
                            continue;
                        }
                        $usedIds[$qId] = true;
                        if ($norm !== '') {
                            $usedNormAnswers[$norm] = true;
                        }
                        $pickedForRound[] = $cand;
                        --$needed;
                    }
                }
            }

            // 3. If still short of total round quota, fill from any category in the pool
            $currentTotalInRound = ($existingByRoundTotal[$rId] ?? 0) + count($pickedForRound);
            $stillNeeded = max(0, $totalTargetQuota - $currentTotalInRound);
            if ($stillNeeded > 0) {
                $fbAnyStmt = $pdo->query("SELECT q.* FROM questions q WHERE {$poolCond} ORDER BY q.is_favorite DESC, RANDOM()");
                foreach ($fbAnyStmt->fetchAll() as $cand) {
                    if ($stillNeeded <= 0) {
                        break;
                    }
                    $qId = (int) $cand['id'];
                    $norm = $this->normalizeAnswerForShow((string) $cand['answer']);
                    if (isset($usedIds[$qId]) || ($norm !== '' && isset($usedNormAnswers[$norm]))) {
                        continue;
                    }
                    $usedIds[$qId] = true;
                    if ($norm !== '') {
                        $usedNormAnswers[$norm] = true;
                    }
                    $pickedForRound[] = $cand;
                    --$stillNeeded;
                }
            }

            if (!empty($pickedForRound)) {
                // Keep Starred questions prioritized first while interleaving categories randomly within each priority tier
                $starredBatch = array_values(array_filter($pickedForRound, fn ($q) => (int) ($q['is_favorite'] ?? 0) === 1));
                $unstarredBatch = array_values(array_filter($pickedForRound, fn ($q) => (int) ($q['is_favorite'] ?? 0) === 0));
                shuffle($starredBatch);
                shuffle($unstarredBatch);
                $orderedBatch = array_merge($starredBatch, $unstarredBatch);

                $sortOrder = (int) $pdo->query("SELECT IFNULL(MAX(sort_order), 0) FROM show_round_questions WHERE show_id = {$showId} AND round_id = {$rId}")->fetchColumn();
                foreach ($orderedBatch as $qItem) {
                    ++$sortOrder;
                    $insStmt->execute([$showId, $rId, (int) $qItem['id'], $sortOrder]);
                }
            }
        }

        $this->syncRapidFireRoundsForShow($showId);
    }

    public function ensureRapidFireRound(int $showId, bool $enable): void
    {
        $pdo = $this->gameDatabase->getPdo();
        $rfStmt = $pdo->prepare("SELECT id FROM show_rounds WHERE show_id = ? AND round_type = 'rapid_fire' ORDER BY round_number ASC");
        $rfStmt->execute([$showId]);
        $rfIds = array_map('intval', $rfStmt->fetchAll(PDO::FETCH_COLUMN));

        if ($enable) {
            if (empty($rfIds)) {
                $nextNum = (int) $pdo->query("SELECT IFNULL(MAX(round_number), 0) + 1 FROM show_rounds WHERE show_id = {$showId}")->fetchColumn();
                $rfDesc = $this->buildDynamicRoundDescription([
                    'round_type' => 'rapid_fire',
                    'timer_seconds' => 15,
                    'points_per_question' => 10,
                    'steal_points' => 5,
                    'advancement_type' => 'all',
                    'advance_top_x' => 12,
                ]);
                $ins = $pdo->prepare('
                    INSERT INTO show_rounds (
                        show_id, round_number, name, description, round_type, timer_seconds,
                        points_per_question, steal_points, advancement_type, advance_top_x,
                        category_allocations, status
                    ) VALUES (?, ?, ?, ?, "rapid_fire", 15, 10, 5, "all", 12, "[]", "pending")
                ');
                $ins->execute([
                    $showId,
                    $nextNum,
                    "Round {$nextNum}: ⚡ Rapid Fire (Remaining Starred)",
                    $rfDesc,
                ]);
                $newRfId = (int) $pdo->lastInsertId();

                $currRound = $pdo->query("SELECT current_round_id FROM game_shows WHERE id = {$showId}")->fetchColumn();
                if (!$currRound) {
                    $pdo->prepare('UPDATE game_shows SET current_round_id = ? WHERE id = ?')->execute([$newRfId, $showId]);
                }
            }
            $this->syncRapidFireRoundsForShow($showId);
        } else {
            if (!empty($rfIds)) {
                $pdo->beginTransaction();
                try {
                    foreach ($rfIds as $rfId) {
                        $pdo->prepare('DELETE FROM show_round_questions WHERE show_id = ? AND round_id = ?')->execute([$showId, $rfId]);
                        $pdo->prepare('DELETE FROM show_rounds WHERE show_id = ? AND id = ?')->execute([$showId, $rfId]);
                    }

                    $show = $this->getShowOrFail($showId);
                    if (in_array((int) ($show['current_round_id'] ?? 0), $rfIds, true)) {
                        $nextRoundId = $pdo->query("SELECT id FROM show_rounds WHERE show_id = {$showId} ORDER BY round_number ASC LIMIT 1")->fetchColumn() ?: null;
                        $pdo->prepare('UPDATE game_shows SET current_round_id = ? WHERE id = ?')->execute([$nextRoundId, $showId]);
                    }

                    $remStmt = $pdo->prepare('SELECT id FROM show_rounds WHERE show_id = ? ORDER BY round_number ASC');
                    $remStmt->execute([$showId]);
                    $remIds = $remStmt->fetchAll(PDO::FETCH_COLUMN);
                    $upd = $pdo->prepare('UPDATE show_rounds SET round_number = ? WHERE id = ?');
                    foreach ($remIds as $idx => $rId) {
                        $upd->execute([$idx + 1, $rId]);
                    }

                    $pdo->commit();
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $e;
                }
            }
        }
    }

    public function syncRapidFireRoundsForShow(int $showId): void
    {
        $pdo = $this->gameDatabase->getPdo();
        $rfStmt = $pdo->prepare("SELECT * FROM show_rounds WHERE show_id = ? AND round_type = 'rapid_fire' ORDER BY round_number ASC");
        $rfStmt->execute([$showId]);
        $rfRounds = $rfStmt->fetchAll();
        if (empty($rfRounds)) {
            return;
        }

        // Collect all questions and normalized answers already taken into other (non-rapid-fire) rounds
        $usedIds = [];
        $usedNormAnswers = [];

        $otherStmt = $pdo->prepare("
            SELECT srq.question_id, q.answer
            FROM show_round_questions srq
            JOIN show_rounds r ON r.id = srq.round_id
            JOIN questions q ON q.id = srq.question_id
            WHERE srq.show_id = ? AND r.round_type != 'rapid_fire'
        ");
        $otherStmt->execute([$showId]);
        foreach ($otherStmt->fetchAll() as $row) {
            $usedIds[(int) $row['question_id']] = true;
            $norm = $this->normalizeAnswerForShow((string) $row['answer']);
            if ($norm !== '') {
                $usedNormAnswers[$norm] = true;
            }
        }

        $usedQStmt = $pdo->prepare("
            SELECT uq.question_id, q.answer
            FROM show_used_questions uq
            LEFT JOIN show_rounds r ON r.id = uq.round_id
            JOIN questions q ON q.id = uq.question_id
            WHERE uq.show_id = ? AND (r.round_type IS NULL OR r.round_type != 'rapid_fire')
        ");
        $usedQStmt->execute([$showId]);
        foreach ($usedQStmt->fetchAll() as $ur) {
            $usedIds[(int) $ur['question_id']] = true;
            $norm = $this->normalizeAnswerForShow((string) $ur['answer']);
            if ($norm !== '') {
                $usedNormAnswers[$norm] = true;
            }
        }

        // Query all remaining Starred & Included puzzles in randomized order
        $starredStmt = $pdo->query("
            SELECT q.*
            FROM questions q
            WHERE q.is_favorite = 1 AND q.is_active = 1 AND q.review_status = 'included'
            ORDER BY RANDOM()
        ");

        $remainingStarred = [];
        foreach ($starredStmt->fetchAll() as $cand) {
            $qId = (int) $cand['id'];
            $norm = $this->normalizeAnswerForShow((string) $cand['answer']);
            if (isset($usedIds[$qId]) || ($norm !== '' && isset($usedNormAnswers[$norm]))) {
                continue;
            }
            $usedIds[$qId] = true;
            if ($norm !== '') {
                $usedNormAnswers[$norm] = true;
            }
            $remainingStarred[] = $cand;
        }

        $delStmt = $pdo->prepare('DELETE FROM show_round_questions WHERE show_id = ? AND round_id = ?');
        $insStmt = $pdo->prepare('INSERT OR IGNORE INTO show_round_questions (show_id, round_id, question_id, sort_order, is_manual) VALUES (?, ?, ?, ?, 0)');

        foreach ($rfRounds as $idx => $rfRound) {
            $rfRoundId = (int) $rfRound['id'];
            $delStmt->execute([$showId, $rfRoundId]);
            // Only populate the first rapid_fire round if multiple exist so there are zero duplicates across rounds
            if ($idx === 0) {
                $sortOrder = 0;
                foreach ($remainingStarred as $qItem) {
                    ++$sortOrder;
                    $insStmt->execute([$showId, $rfRoundId, (int) $qItem['id'], $sortOrder]);
                }
            }
            $this->syncRoundAllocationsFromAssigned($showId, $rfRoundId);
        }
    }

    private function getRapidFireRoundsPayload(int $showId, ?array $allRoundQuestions = null): array
    {
        $pdo = $this->gameDatabase->getPdo();
        $rfStmt = $pdo->prepare("SELECT id, round_number, name FROM show_rounds WHERE show_id = ? AND round_type = 'rapid_fire' ORDER BY round_number ASC");
        $rfStmt->execute([$showId]);
        $rfRows = $rfStmt->fetchAll();
        if (empty($rfRows)) {
            return [];
        }

        $map = $allRoundQuestions ?? $this->getRoundQuestionsMap($showId);
        $out = [];
        foreach ($rfRows as $rf) {
            $rId = (int) $rf['id'];
            $out[] = [
                'round_id' => $rId,
                'round_number' => (int) $rf['round_number'],
                'name' => $rf['name'],
                'questions' => $map[$rId] ?? [],
            ];
        }

        return $out;
    }

    private function syncRoundAllocationsFromAssigned(int $showId, int $roundId): void
    {
        $pdo = $this->gameDatabase->getPdo();
        $rStmt = $pdo->prepare('SELECT category_allocations FROM show_rounds WHERE id = ? AND show_id = ?');
        $rStmt->execute([$roundId, $showId]);
        $rawAllocs = $rStmt->fetchColumn();
        $existingAllocs = json_decode((string) ($rawAllocs ?: '[]'), true) ?: [];

        $diffByCat = [];
        foreach ($existingAllocs as $ea) {
            $diffByCat[(int) ($ea['category_id'] ?? 0)] = $ea['difficulty'] ?? 'Any';
        }

        $cntStmt = $pdo->prepare('
            SELECT q.category_id, COUNT(*) as cnt
            FROM show_round_questions srq
            JOIN questions q ON q.id = srq.question_id
            WHERE srq.show_id = ? AND srq.round_id = ?
            GROUP BY q.category_id
            ORDER BY q.category_id ASC
        ');
        $cntStmt->execute([$showId, $roundId]);
        $catCounts = $cntStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

        $newAllocs = [];
        // Keep original allocation order first
        foreach ($existingAllocs as $ea) {
            $cId = (int) ($ea['category_id'] ?? 0);
            if (isset($catCounts[$cId]) && (int) $catCounts[$cId] > 0) {
                $newAllocs[] = [
                    'category_id' => $cId,
                    'count' => (int) $catCounts[$cId],
                    'difficulty' => $diffByCat[$cId] ?? 'Any',
                ];
                unset($catCounts[$cId]);
            }
        }
        // Add any newly introduced categories
        foreach ($catCounts as $cId => $cnt) {
            if ((int) $cnt > 0) {
                $newAllocs[] = [
                    'category_id' => (int) $cId,
                    'count' => (int) $cnt,
                    'difficulty' => $diffByCat[(int) $cId] ?? 'Any',
                ];
            }
        }

        $pdo->prepare('UPDATE show_rounds SET category_allocations = ? WHERE id = ? AND show_id = ?')
            ->execute([json_encode($newAllocs), $roundId, $showId]);
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

