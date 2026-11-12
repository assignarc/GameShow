<?php

declare(strict_types=1);

namespace App\Tests;

use App\Service\GameDatabase;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class GameShowTest extends WebTestCase
{
    private function getFirstShowId(): int
    {
        $db = static::getContainer()->get(GameDatabase::class);
        $pdo = $db->getPdo();
        $id = (int) $pdo->query('SELECT id FROM game_shows ORDER BY id ASC LIMIT 1')->fetchColumn();
        if ($id === 0) {
            $db->seedFromMasterJson();
            $id = (int) $pdo->query('SELECT id FROM game_shows ORDER BY id ASC LIMIT 1')->fetchColumn();
        }
        return $id;
    }

    public function testHomePageLoads(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Fall Party Rebus Arena');
    }

    public function testManageShowPageLoads(): void
    {
        $client = static::createClient();
        $showId = $this->getFirstShowId();
        $client->request('GET', '/shows/' . $showId . '/manage');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Fall Party Rebus Championship 2026');
    }

    public function testProjectorDisplayPageLoads(): void
    {
        $client = static::createClient();
        $showId = $this->getFirstShowId();
        $client->request('GET', '/shows/' . $showId . '/display');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('.tv-scoreboard');
        $this->assertSelectorTextContains('.scoreboard-title', 'Live Scores');
        $this->assertSelectorExists('#tv-teams');
        $this->assertSelectorExists('.tv-stage');
    }

    public function testModerationBankLoadsWithQuestions(): void
    {
        $client = static::createClient();
        $client->request('GET', '/moderation');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Master Rebus Question Bank');
    }

    public function testShowStateApi(): void
    {
        $client = static::createClient();
        $showId = $this->getFirstShowId();
        $client->request('GET', '/api/shows/' . $showId . '/state');

        $this->assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('show', $data);
        $this->assertArrayHasKey('teams', $data);
        $this->assertArrayHasKey('rounds', $data);
        $this->assertCount(10, $data['teams']);
    }

    public function testStartQuestionAndRevealCycle(): void
    {
        $client = static::createClient();
        $showId = $this->getFirstShowId();

        // 1. Start question
        $client->request('POST', '/api/shows/' . $showId . '/action', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'action' => 'start_question'
        ]));
        $this->assertResponseIsSuccessful();
        $startData = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertTrue($startData['success']);
        $this->assertNotNull($startData['question_id']);

        // 2. Reveal answer
        $client->request('POST', '/api/shows/' . $showId . '/action', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'action' => 'reveal_answer'
        ]));
        $this->assertResponseIsSuccessful();

        // 3. Award points to first team
        $db = static::getContainer()->get(GameDatabase::class);
        $pdo = $db->getPdo();
        $teamId = (int) $pdo->query("SELECT id FROM show_teams WHERE show_id = {$showId} ORDER BY id ASC LIMIT 1")->fetchColumn();

        $client->request('POST', '/api/shows/' . $showId . '/action', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'action' => 'award_points',
            'team_id' => $teamId,
            'points' => 10
        ]));
        $this->assertResponseIsSuccessful();

        // Verify updated score
        $client->request('GET', '/api/shows/' . $showId . '/state');
        $state = json_decode((string) $client->getResponse()->getContent(), true);
        $team1 = null;
        foreach ($state['teams'] as $t) {
            if ($t['id'] === $teamId) {
                $team1 = $t;
                break;
            }
        }
        $this->assertNotNull($team1);
        $this->assertGreaterThanOrEqual(10, $team1['score']);
    }

    public function testReviewDeckLoadsAndFilters(): void
    {
        $client = static::createClient();

        // 1. Direct review URL
        $client->request('GET', '/review');
        $this->assertResponseRedirects('/moderation/review');

        // 2. Main review page (defaults to unrated)
        $crawler = $client->request('GET', '/moderation/review');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Question Review & Curation');
        $this->assertSelectorExists('#btn-thumbs-up');
        $this->assertSelectorExists('#btn-thumbs-down');
        $this->assertSelectorExists('option[value="unrated"]');
        $this->assertSelectorExists('#enlarged-image-modal');
        $this->assertSelectorExists('#ai-res-select');

        // 3. Filtered review page
        $client->request('GET', '/moderation/review?status=included&difficulty=Medium');
        $this->assertResponseIsSuccessful();

        // 4. Only unrated filter (strictly avoids selected, skipped, and rejected)
        $client->request('GET', '/moderation/review?status=unrated');
        $this->assertResponseIsSuccessful();
    }

    public function testQuestionReviewVoteThumbsUpDownAndPersistence(): void
    {
        $client = static::createClient();
        $db = static::getContainer()->get(GameDatabase::class);
        $pdo = $db->getPdo();

        // Create an isolated temporary test question so tests never overwrite user's curated questions
        $pdo->prepare("INSERT INTO questions (category_id, title, prompt_text, answer, review_status, is_active, media_path) VALUES (14, 'Automated Test Puzzle', 'Test prompt', 'Test Answer', 'unrated', 1, '/uploads/puzzles/rd/graphic_1.jpg')")->execute();
        $qId = (int) $pdo->lastInsertId();
        $this->assertGreaterThan(0, $qId);

        try {
            // 1. Vote Thumbs Down (exclude from game show)
            $client->request('POST', '/moderation/question/' . $qId . '/vote', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'vote' => 'down'
            ]));
            $this->assertResponseIsSuccessful();
            $downData = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertTrue($downData['success']);
            $this->assertSame(0, $downData['is_active']);
            $this->assertSame('excluded', $downData['review_status']);
            $this->assertArrayHasKey('counts', $downData);

            // Verify in SQLite database (persists across reloads/restarts)
            $dbRow = $pdo->query("SELECT is_active, review_status FROM questions WHERE id = {$qId}")->fetch();
            $this->assertSame(0, (int) $dbRow['is_active']);
            $this->assertSame('excluded', $dbRow['review_status']);

            // 2. Vote Thumbs Up (include in game show)
            $client->request('POST', '/moderation/question/' . $qId . '/review-vote', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'vote' => 'up'
            ]));
            $this->assertResponseIsSuccessful();
            $upData = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertTrue($upData['success']);
            $this->assertSame(1, $upData['is_active']);
            $this->assertSame('included', $upData['review_status']);

            // Verify in SQLite database
            $dbRow = $pdo->query("SELECT is_active, review_status FROM questions WHERE id = {$qId}")->fetch();
            $this->assertSame(1, (int) $dbRow['is_active']);
            $this->assertSame('included', $dbRow['review_status']);

            // 3. Test "Only Unrated" filter strictly avoids selected, skipped, and rejected questions
            // When filtered by unrated, the included question should NOT appear
            $client->request('GET', '/moderation/review?status=unrated');
            $this->assertResponseIsSuccessful();
            $content = (string) $client->getResponse()->getContent();
            if (preg_match('/const PUZZLES = (\[.*?\]);/s', $content, $matches)) {
                $puzzles = json_decode($matches[1], true);
                $includedIds = array_column($puzzles, 'id');
                $this->assertNotContains($qId, $includedIds, 'Unrated filter must not contain already included questions');
            }

            // 3b. Test skip: voting skip sets review_status to skipped and is avoided by unrated filter
            $client->request('POST', '/moderation/question/' . $qId . '/review-vote', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'vote' => 'skip',
                'status' => 'skipped'
            ]));
            $this->assertResponseIsSuccessful();
            $skipData = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertSame('skipped', $skipData['review_status']);

            // Verify skipped question is avoided in Unrated queue
            $client->request('GET', '/moderation/review?status=unrated');
            $content = (string) $client->getResponse()->getContent();
            if (preg_match('/const PUZZLES = (\[.*?\]);/s', $content, $matches)) {
                $puzzles = json_decode($matches[1], true);
                $includedIds = array_column($puzzles, 'id');
                $this->assertNotContains($qId, $includedIds, 'Unrated filter must strictly avoid skipped questions');
            }

            // Verify skipped question appears when requested via ?status=skipped
            $client->request('GET', '/moderation/review?status=skipped');
            $this->assertResponseIsSuccessful();
            $content = (string) $client->getResponse()->getContent();
            if (preg_match('/const PUZZLES = (\[.*?\]);/s', $content, $matches)) {
                $puzzles = json_decode($matches[1], true);
                $includedIds = array_column($puzzles, 'id');
                $this->assertContains($qId, $includedIds, 'Skipped filter must return skipped questions');
            }

            // 4. Test "already selected questions" filter
            // Vote up again to test status=included
            $client->request('POST', '/moderation/question/' . $qId . '/review-vote', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'vote' => 'up'
            ]));
            $client->request('GET', '/moderation/review?status=included');
            $this->assertResponseIsSuccessful();
            $content = (string) $client->getResponse()->getContent();
            if (preg_match('/const PUZZLES = (\[.*?\]);/s', $content, $matches)) {
                $puzzles = json_decode($matches[1], true);
                $includedIds = array_column($puzzles, 'id');
                $this->assertContains($qId, $includedIds, 'Included filter must return already included questions when requested');
            }
        } finally {
            $pdo->prepare('DELETE FROM questions WHERE id = ?')->execute([$qId]);
        }
    }

    public function testAiImageGenerationAndSelection(): void
    {
        $client = static::createClient();
        $db = static::getContainer()->get(GameDatabase::class);
        $pdo = $db->getPdo();

        // Create an isolated temporary test question
        $pdo->prepare("INSERT INTO questions (category_id, title, prompt_text, answer, review_status, is_active, media_path) VALUES (14, 'AI Test Puzzle', 'Test prompt', 'Test Answer', 'unrated', 1, '/uploads/puzzles/rd/graphic_1.jpg')")->execute();
        $qId = (int) $pdo->lastInsertId();
        $originalPath = '/uploads/puzzles/rd/graphic_1.jpg';

        try {
            // 1. Fetch AI Prompt suggestion
            $client->request('GET', '/moderation/question/' . $qId . '/ai-prompt');
            $this->assertResponseIsSuccessful();
            $promptData = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertTrue($promptData['success']);
            $this->assertNotEmpty($promptData['default_prompt']);
            $this->assertArrayHasKey('existing_image_url', $promptData);

            // 2. Generate AI Image (High Resolution)
            $client->request('POST', '/moderation/question/' . $qId . '/ai-generate', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'prompt' => 'A rebus illustration for testing',
                'style' => 'vector_rebus',
                'resolution' => '1280x800',
                'seed' => 12345
            ]));
            $this->assertResponseIsSuccessful();
            $genData = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertTrue($genData['success']);
            $this->assertNotEmpty($genData['image_url']);
            $this->assertSame('1280x800', $genData['dimensions']);
            $generatedUrl = $genData['image_url'];

            // 3. Select & Apply the AI generated image
            $client->request('POST', '/moderation/question/' . $qId . '/ai-apply', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'action' => 'apply_ai',
                'image_url' => $generatedUrl
            ]));
            $this->assertResponseIsSuccessful();
            $applyData = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertTrue($applyData['success']);
            $this->assertSame($generatedUrl, $applyData['media_path']);

            // Verify DB was updated
            $currentDbPath = (string) $pdo->query("SELECT media_path FROM questions WHERE id = {$qId}")->fetchColumn();
            $this->assertSame($generatedUrl, $currentDbPath);

            // 4. Restore original image
            $client->request('POST', '/moderation/question/' . $qId . '/ai-apply', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'action' => 'restore_original'
            ]));
            $this->assertResponseIsSuccessful();
            $restoreData = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertTrue($restoreData['success']);
            $this->assertSame($originalPath, $restoreData['media_path']);

            // Verify DB is restored
            $restoredDbPath = (string) $pdo->query("SELECT media_path FROM questions WHERE id = {$qId}")->fetchColumn();
            $this->assertSame($originalPath, $restoredDbPath);
        } finally {
            $pdo->prepare('DELETE FROM questions WHERE id = ?')->execute([$qId]);
        }
    }

    public function testUpdateQuestionAnswer(): void
    {
        $client = static::createClient();
        $db = static::getContainer()->get(GameDatabase::class);
        $pdo = $db->getPdo();

        $pdo->prepare("INSERT INTO questions (category_id, title, prompt_text, answer, review_status, is_active, media_path) VALUES (14, 'Update Ans Puzzle', 'Test prompt', 'Original Test Answer', 'unrated', 1, '/uploads/puzzles/rd/graphic_1.jpg')")->execute();
        $qId = (int) $pdo->lastInsertId();

        try {
            $updatedAnswer = 'Updated Test Rebus Answer';
            $client->request('POST', '/moderation/question/' . $qId . '/update-answer', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'answer' => $updatedAnswer,
                'alternate_answers' => 'Alt 1, Alt 2',
                'hint' => 'A test hint',
                'explanation' => 'Test explanation breakdown'
            ]));

            $this->assertResponseIsSuccessful();
            $data = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertTrue($data['success']);
            $this->assertSame($updatedAnswer, $data['answer']);
            $this->assertNotEmpty($data['new_prompt']);

            // Verify in DB
            $dbAns = (string) $pdo->query("SELECT answer FROM questions WHERE id = {$qId}")->fetchColumn();
            $this->assertSame($updatedAnswer, $dbAns);
        } finally {
            $pdo->prepare('DELETE FROM questions WHERE id = ?')->execute([$qId]);
        }
    }

    public function testUpgradeImageResolutionAndRejectOption(): void
    {
        $client = static::createClient();
        $db = static::getContainer()->get(GameDatabase::class);
        $pdo = $db->getPdo();

        $pdo->prepare("INSERT INTO questions (category_id, title, prompt_text, answer, review_status, is_active, media_path) VALUES (14, 'Upscale Test Puzzle', 'Test prompt', 'Original Test Answer', 'unrated', 1, '/uploads/puzzles/rd/graphic_1.jpg')")->execute();
        $qId = (int) $pdo->lastInsertId();
        $existingPath = '/uploads/puzzles/rd/graphic_1.jpg';

        try {
            // 1. Request upscale preview (4x super-resolution)
            $client->request('POST', '/moderation/question/' . $qId . '/upscale-image', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'target_width' => 1280
            ]));
            $this->assertResponseIsSuccessful();
            $upscaleData = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertTrue($upscaleData['success']);
            $this->assertStringStartsWith('/uploads/upscaled/', $upscaleData['image_url']);
            $this->assertGreaterThanOrEqual(1200, $upscaleData['width']);
            $this->assertArrayHasKey('scale_factor', $upscaleData);

            // 1b. Fast Vector Rebus regeneration preview
            $client->request('POST', '/moderation/question/' . $qId . '/ai-generate', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'style' => 'vector_rebus',
                'resolution' => '1280x800'
            ]));
            $this->assertResponseIsSuccessful();
            $regenData = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertTrue($regenData['success']);
            $this->assertSame('svg', $regenData['format']);
            $this->assertStringStartsWith('/uploads/ai/', $regenData['image_url']);

            // 2. Verify rejecting option: DB was NOT changed yet (user can reject and keep existing)
            $dbPathAfterPreview = (string) $pdo->query("SELECT media_path FROM questions WHERE id = {$qId}")->fetchColumn();
            $this->assertSame($existingPath, $dbPathAfterPreview, 'Database must not be updated before user accepts the upgrade');

            // 3. User chooses to accept the upgrade
            $client->request('POST', '/moderation/question/' . $qId . '/ai-apply', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'action' => 'apply_ai',
                'image_url' => $upscaleData['image_url']
            ]));
            $this->assertResponseIsSuccessful();
            $acceptedDbPath = (string) $pdo->query("SELECT media_path FROM questions WHERE id = {$qId}")->fetchColumn();
            $this->assertSame($upscaleData['image_url'], $acceptedDbPath);

            // 4. Restore original image
            $client->request('POST', '/moderation/question/' . $qId . '/ai-apply', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'action' => 'restore_original'
            ]));
            $this->assertResponseIsSuccessful();
            $restoredDbPath = (string) $pdo->query("SELECT media_path FROM questions WHERE id = {$qId}")->fetchColumn();
            $this->assertSame($existingPath, $restoredDbPath);
        } finally {
            $pdo->prepare('DELETE FROM questions WHERE id = ?')->execute([$qId]);
        }
    }

    public function testDuplicatesPageLoads(): void
    {
        $client = static::createClient();
        $client->request('GET', '/moderation/duplicates');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Duplicate Puzzles Manager');
    }

    public function testDuplicateDismissAndKeepActions(): void
    {
        $client = static::createClient();
        $db = static::getContainer()->get(GameDatabase::class);
        $pdo = $db->getPdo();

        $catId = (int) $pdo->query('SELECT id FROM categories LIMIT 1')->fetchColumn();
        if ($catId === 0) {
            $db->seedFromMasterJson();
            $catId = (int) $pdo->query('SELECT id FROM categories LIMIT 1')->fetchColumn();
        }

        // Insert two test duplicate questions
        $pdo->prepare('INSERT INTO questions (category_id, title, prompt_text, answer, review_status, is_active) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$catId, 'Dup A', 'Prompt A', 'Test Unique Answer For Dup', 'unrated', 1]);
        $idA = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO questions (category_id, title, prompt_text, answer, review_status, is_active) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$catId, 'Dup B', 'Prompt B', 'Test Unique Answer For Dup', 'unrated', 1]);
        $idB = (int) $pdo->lastInsertId();

        try {
            // Check duplicates page contains our duplicate
            $client->request('GET', '/moderation/duplicates');
            $this->assertResponseIsSuccessful();
            $this->assertStringContainsString('Test Unique Answer For Dup', (string) $client->getResponse()->getContent());

            // 1. Test Dismiss ("Not Duplicate")
            $client->request('POST', '/moderation/duplicates/dismiss', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'question_ids' => [$idA, $idB]
            ]));
            $this->assertResponseIsSuccessful();
            $res = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertTrue($res['success']);

            // Verify they are no longer reported as duplicates
            $client->request('GET', '/moderation/duplicates');
            $this->assertStringNotContainsString('Test Unique Answer For Dup', (string) $client->getResponse()->getContent());

            // Remove dismissal to test keep
            $pdo->prepare('DELETE FROM duplicate_dismissals WHERE question_id_a = ? OR question_id_b = ?')->execute([min($idA, $idB), max($idA, $idB)]);

            // 2. Test Keep A and Exclude B
            $client->request('POST', '/moderation/duplicates/keep', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'keep_id' => $idA,
                'other_ids' => [$idB],
                'action' => 'exclude'
            ]));
            $this->assertResponseIsSuccessful();
            $resKeep = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertTrue($resKeep['success']);

            $statusB = $pdo->query("SELECT review_status FROM questions WHERE id = {$idB}")->fetchColumn();
            $this->assertSame('excluded', $statusB);
        } finally {
            $pdo->prepare('DELETE FROM questions WHERE id IN (?, ?)')->execute([$idA, $idB]);
            $pdo->prepare('DELETE FROM duplicate_dismissals WHERE question_id_a IN (?, ?) OR question_id_b IN (?, ?)')->execute([$idA, $idB, $idA, $idB]);
        }
    }

    public function testDeleteGameShow(): void
    {
        $client = static::createClient();
        $db = static::getContainer()->get(GameDatabase::class);
        $pdo = $db->getPdo();

        // Create temporary show with a team and a round
        $pdo->prepare('INSERT INTO game_shows (name, description, status) VALUES (?, ?, ?)')
            ->execute(['Show To Delete', 'Temporary test show', 'setup']);
        $showId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO show_teams (show_id, name, score, avatar_color) VALUES (?, ?, ?, ?)')
            ->execute([$showId, 'Team Deletable', 100, '#ff0000']);
        $pdo->prepare('INSERT INTO show_rounds (show_id, round_number, name, round_type, points_per_question) VALUES (?, ?, ?, ?, ?)')
            ->execute([$showId, 1, 'Round Deletable', 'classic', 10]);

        $this->assertGreaterThan(0, $showId);

        // Send delete request
        $client->request('POST', '/shows/' . $showId . '/delete');

        $this->assertResponseRedirects('/');
        $client->followRedirect();
        $this->assertResponseIsSuccessful();

        // Verify show and associated records are deleted
        $showExists = (int) $pdo->query('SELECT COUNT(*) FROM game_shows WHERE id = ' . $showId)->fetchColumn();
        $this->assertSame(0, $showExists);

        $teamsExist = (int) $pdo->query('SELECT COUNT(*) FROM show_teams WHERE show_id = ' . $showId)->fetchColumn();
        $this->assertSame(0, $teamsExist);

        $roundsExist = (int) $pdo->query('SELECT COUNT(*) FROM show_rounds WHERE show_id = ' . $showId)->fetchColumn();
        $this->assertSame(0, $roundsExist);
    }

    public function testEditAndManageRounds(): void
    {
        $client = static::createClient();
        $db = static::getContainer()->get(GameDatabase::class);
        $pdo = $db->getPdo();

        // Create temporary show with 2 rounds
        $pdo->prepare('INSERT INTO game_shows (name, description, status) VALUES (?, ?, ?)')
            ->execute(['Show For Round Edit', 'Test show for editing rounds', 'setup']);
        $showId = (int) $pdo->lastInsertId();

        $catId = (int) $pdo->query('SELECT id FROM categories LIMIT 1')->fetchColumn();

        $pdo->prepare('INSERT INTO show_rounds (show_id, round_number, name, round_type, timer_seconds, points_per_question, steal_points) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$showId, 1, 'Initial Round 1', 'all_play', 20, 10, 5]);
        $round1Id = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO show_rounds (show_id, round_number, name, round_type, timer_seconds, points_per_question, steal_points) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$showId, 2, 'Initial Round 2', 'all_play', 25, 15, 5]);
        $round2Id = (int) $pdo->lastInsertId();

        try {
            // 1. Edit Round 1
            $client->request('POST', '/shows/' . $showId . '/rounds/save', [
                'round_id' => $round1Id,
                'name' => 'Updated Custom Round',
                'round_type' => 'head_to_head',
                'timer_seconds' => 45,
                'points_per_question' => 30,
                'steal_points' => 15,
                'advancement_type' => 'top_x',
                'advance_top_x' => 4,
                'cat_ids' => [$catId],
                'cat_counts' => [7],
                'cat_diffs' => ['Hard'],
            ]);

            $this->assertResponseRedirects('/shows/' . $showId . '/manage');
            $client->followRedirect();
            $this->assertResponseIsSuccessful();
            $this->assertStringContainsString('Updated Custom Round', (string) $client->getResponse()->getContent());

            // Check database values
            $r1 = $pdo->query("SELECT * FROM show_rounds WHERE id = {$round1Id}")->fetch();
            $this->assertSame('Updated Custom Round', $r1['name']);
            $this->assertSame('head_to_head', $r1['round_type']);
            $this->assertSame(45, (int) $r1['timer_seconds']);
            $this->assertSame(30, (int) $r1['points_per_question']);
            $this->assertSame(15, (int) $r1['steal_points']);
            $this->assertSame('top_x', $r1['advancement_type']);
            $this->assertSame(4, (int) $r1['advance_top_x']);

            // 2. Move Round 1 Down
            $client->request('POST', '/shows/' . $showId . '/rounds/' . $round1Id . '/move/down');
            $this->assertResponseRedirects('/shows/' . $showId . '/manage');

            $r1Num = (int) $pdo->query("SELECT round_number FROM show_rounds WHERE id = {$round1Id}")->fetchColumn();
            $r2Num = (int) $pdo->query("SELECT round_number FROM show_rounds WHERE id = {$round2Id}")->fetchColumn();
            $this->assertSame(2, $r1Num);
            $this->assertSame(1, $r2Num);

            // 3. Move Round 1 Up (back to 1)
            $client->request('POST', '/shows/' . $showId . '/rounds/' . $round1Id . '/move/up');
            $this->assertResponseRedirects('/shows/' . $showId . '/manage');

            $r1NumAfter = (int) $pdo->query("SELECT round_number FROM show_rounds WHERE id = {$round1Id}")->fetchColumn();
            $this->assertSame(1, $r1NumAfter);

            // 4. Delete Round 2
            $client->request('POST', '/shows/' . $showId . '/rounds/' . $round2Id . '/delete');
            $this->assertResponseRedirects('/shows/' . $showId . '/manage');

            $r2Exists = (int) $pdo->query("SELECT COUNT(*) FROM show_rounds WHERE id = {$round2Id}")->fetchColumn();
            $this->assertSame(0, $r2Exists);

            $remainingCount = (int) $pdo->query("SELECT COUNT(*) FROM show_rounds WHERE show_id = {$showId}")->fetchColumn();
            $this->assertSame(1, $remainingCount);
        } finally {
            $pdo->prepare('DELETE FROM show_rounds WHERE show_id = ?')->execute([$showId]);
            $pdo->prepare('DELETE FROM game_shows WHERE id = ?')->execute([$showId]);
        }
    }

    public function testResetGameShow(): void
    {
        $client = static::createClient();
        $db = static::getContainer()->get(GameDatabase::class);
        $pdo = $db->getPdo();

        // Create temporary show with a team and used question
        $pdo->prepare('INSERT INTO game_shows (name, description, status) VALUES (?, ?, ?)')
            ->execute(['Show To Reset', 'Temporary test show', 'active']);
        $showId = (int) $pdo->lastInsertId();

        try {
            $pdo->prepare('INSERT INTO show_teams (show_id, name, score, avatar_color, is_eliminated) VALUES (?, ?, ?, ?, ?)')
                ->execute([$showId, 'Team Alpha', 150, '#ff0000', 1]);

            $pdo->prepare('INSERT INTO show_rounds (show_id, round_number, name, round_type, status) VALUES (?, ?, ?, ?, ?)')
                ->execute([$showId, 1, 'Round 1', 'all_play', 'active']);
            $roundId = (int) $pdo->lastInsertId();

            $qRow = $pdo->query('SELECT id, category_id FROM questions LIMIT 1')->fetch();
            $qId = (int) $qRow['id'];
            $catId = (int) $qRow['category_id'];
            $pdo->prepare('INSERT INTO show_used_questions (show_id, round_id, question_id, category_id) VALUES (?, ?, ?, ?)')
                ->execute([$showId, $roundId, $qId, $catId]);
            // Trigger reset
            $client->request('POST', '/shows/' . $showId . '/reset');
            $this->assertResponseRedirects('/shows/' . $showId . '/manage');
            $client->followRedirect();
            $this->assertResponseIsSuccessful();

            // Verify team score and elimination reset
            $team = $pdo->query("SELECT score, is_eliminated FROM show_teams WHERE show_id = {$showId}")->fetch();
            $this->assertSame(0, (int) $team['score']);
            $this->assertSame(0, (int) $team['is_eliminated']);

            // Verify used questions cleared
            $usedCount = (int) $pdo->query("SELECT COUNT(*) FROM show_used_questions WHERE show_id = {$showId}")->fetchColumn();
            $this->assertSame(0, $usedCount);

            // Verify round status pending
            $roundStatus = $pdo->query("SELECT status FROM show_rounds WHERE id = {$roundId}")->fetchColumn();
            $this->assertSame('pending', $roundStatus);

            // Verify show status setup
            $showStatus = $pdo->query("SELECT status FROM game_shows WHERE id = {$showId}")->fetchColumn();
            $this->assertSame('setup', $showStatus);
        } finally {
            $pdo->prepare('DELETE FROM show_used_questions WHERE show_id = ?')->execute([$showId]);
            $pdo->prepare('DELETE FROM show_teams WHERE show_id = ?')->execute([$showId]);
            $pdo->prepare('DELETE FROM show_rounds WHERE show_id = ?')->execute([$showId]);
            $pdo->prepare('DELETE FROM game_shows WHERE id = ?')->execute([$showId]);
        }
    }

    public function testApplyOneHourTemplate(): void
    {
        $client = static::createClient();
        $db = static::getContainer()->get(GameDatabase::class);
        $pdo = $db->getPdo();

        $pdo->prepare('INSERT INTO game_shows (name, description, status) VALUES (?, ?, ?)')
            ->execute(['Show For Template', 'Test show for template', 'setup']);
        $showId = (int) $pdo->lastInsertId();

        try {
            $client->request('POST', '/shows/' . $showId . '/apply-1hour-template');
            $this->assertResponseRedirects('/shows/' . $showId . '/manage');

            // Verify 4 rounds exist
            $rounds = $pdo->query("SELECT * FROM show_rounds WHERE show_id = {$showId} ORDER BY round_number ASC")->fetchAll();
            $this->assertCount(4, $rounds);

            $this->assertStringContainsString('Round 1', $rounds[0]['name']);
            $this->assertStringContainsString('Round 2', $rounds[1]['name']);
            $this->assertStringContainsString('Round 3', $rounds[2]['name']);
            $this->assertStringContainsString('Round 4', $rounds[3]['name']);

            // Check total allocated questions across all 4 rounds = 38
            $totalAllocated = 0;
            foreach ($rounds as $r) {
                $allocs = json_decode($r['category_allocations'], true);
                foreach ($allocs as $a) {
                    $totalAllocated += (int) $a['count'];
                }
            }
            $this->assertSame(38, $totalAllocated);
        } finally {
            $pdo->prepare('DELETE FROM show_rounds WHERE show_id = ?')->execute([$showId]);
            $pdo->prepare('DELETE FROM game_shows WHERE id = ?')->execute([$showId]);
        }
    }

    public function testResetShowApiAction(): void
    {
        $client = static::createClient();
        $db = static::getContainer()->get(GameDatabase::class);
        $pdo = $db->getPdo();

        $pdo->prepare('INSERT INTO game_shows (name, description, status) VALUES (?, ?, ?)')
            ->execute(['Show For Api Reset', 'API reset test', 'active']);
        $showId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO show_teams (show_id, name, score) VALUES (?, ?, ?)')
            ->execute([$showId, 'Team Api', 200]);

        try {
            $client->request('POST', '/api/shows/' . $showId . '/action', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'action' => 'reset_show'
            ]));

            $this->assertResponseIsSuccessful();
            $data = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertTrue($data['success']);

            $score = (int) $pdo->query("SELECT score FROM show_teams WHERE show_id = {$showId}")->fetchColumn();
            $this->assertSame(0, $score);
        } finally {
            $pdo->prepare('DELETE FROM show_teams WHERE show_id = ?')->execute([$showId]);
            $pdo->prepare('DELETE FROM game_shows WHERE id = ?')->execute([$showId]);
        }
    }
}
