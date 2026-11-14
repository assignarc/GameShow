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

    protected function tearDown(): void
    {
        parent::tearDown();
        if (static::$booted) {
            $db = static::getContainer()->get(GameDatabase::class);
            $db->syncMasterJson();
        }
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
        $db = static::getContainer()->get(GameDatabase::class);
        $pdo = $db->getPdo();

        $pdo->prepare('INSERT INTO game_shows (name, description, status) VALUES (?, ?, ?)')
            ->execute(['Test Question Cycle Show', 'Cycle test', 'active']);
        $showId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO show_teams (show_id, name, score, avatar_color) VALUES (?, ?, ?, ?)')
            ->execute([$showId, 'Team Test', 0, '#f97316']);
        $teamId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO show_rounds (show_id, round_number, name, round_type, status) VALUES (?, ?, ?, ?, ?)')
            ->execute([$showId, 1, 'Round 1', 'all_play', 'active']);
        $roundId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE game_shows SET current_round_id = ? WHERE id = ?')->execute([$roundId, $showId]);

        try {
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
            $this->assertSame(10, $team1['score']);
        } finally {
            $pdo->prepare('DELETE FROM show_used_questions WHERE show_id = ?')->execute([$showId]);
            $pdo->prepare('DELETE FROM show_rounds WHERE show_id = ?')->execute([$showId]);
            $pdo->prepare('DELETE FROM show_teams WHERE show_id = ?')->execute([$showId]);
            $pdo->prepare('DELETE FROM game_shows WHERE id = ?')->execute([$showId]);
        }
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
            $db->syncMasterJson();
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
            $db->syncMasterJson();
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

    public function testUpdateGameShowDetails(): void
    {
        $client = static::createClient();
        $db = static::getContainer()->get(GameDatabase::class);
        $pdo = $db->getPdo();

        $pdo->prepare('INSERT INTO game_shows (name, subtitle, default_timer_seconds, status) VALUES (?, ?, ?, ?)')
            ->execute(['Original Show Name', 'Original Subtitle', 25, 'setup']);
        $showId = (int) $pdo->lastInsertId();

        try {
            $client->request('POST', '/shows/' . $showId . '/update', [
                'name' => 'Updated Championship Name 2026',
                'subtitle' => 'Grand Finale Deluxe',
                'default_timer_seconds' => 35,
            ]);

            $this->assertResponseRedirects('/shows/' . $showId . '/manage');
            $client->followRedirect();
            $this->assertResponseIsSuccessful();

            $show = $pdo->query("SELECT * FROM game_shows WHERE id = {$showId}")->fetch();
            $this->assertSame('Updated Championship Name 2026', $show['name']);
            $this->assertSame('Grand Finale Deluxe', $show['subtitle']);
            $this->assertSame(35, (int) $show['default_timer_seconds']);
        } finally {
            $pdo->prepare('DELETE FROM game_shows WHERE id = ?')->execute([$showId]);
        }
    }

    public function testBatchDeleteQuestions(): void
    {
        $client = static::createClient();
        $db = static::getContainer()->get(GameDatabase::class);
        $pdo = $db->getPdo();

        $catId = (int) $pdo->query('SELECT id FROM categories LIMIT 1')->fetchColumn();
        $stmt = $pdo->prepare('INSERT INTO questions (category_id, answer, difficulty, review_status, is_active) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$catId, 'BATCH TEST 1', 'Simple', 'unrated', 1]);
        $id1 = (int) $pdo->lastInsertId();
        $stmt->execute([$catId, 'BATCH TEST 2', 'Medium', 'unrated', 1]);
        $id2 = (int) $pdo->lastInsertId();

        try {
            $client->request('POST', '/moderation/questions/delete-batch', [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            ], json_encode(['ids' => [$id1, $id2]]));

            $this->assertResponseIsSuccessful();
            $data = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertTrue($data['success']);
            $this->assertSame(2, $data['deleted_count']);

            $count = (int) $pdo->query("SELECT COUNT(*) FROM questions WHERE id IN ({$id1}, {$id2})")->fetchColumn();
            $this->assertSame(0, $count);
        } finally {
            $pdo->prepare("DELETE FROM questions WHERE id IN ({$id1}, {$id2})")->execute();
            $db->syncMasterJson();
        }
    }

    public function testBatchActionIncludeAndExclude(): void
    {
        $client = static::createClient();
        $db = static::getContainer()->get(GameDatabase::class);
        $pdo = $db->getPdo();

        $catId = (int) $pdo->query('SELECT id FROM categories LIMIT 1')->fetchColumn();
        $stmt = $pdo->prepare('INSERT INTO questions (category_id, answer, difficulty, review_status, is_active) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$catId, 'ACTION TEST 1', 'Simple', 'unrated', 1]);
        $id1 = (int) $pdo->lastInsertId();

        try {
            // Test batch exclude
            $client->request('POST', '/moderation/questions/batch-action', [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            ], json_encode(['ids' => [$id1], 'action' => 'exclude']));

            $this->assertResponseIsSuccessful();
            $q = $pdo->query("SELECT review_status, is_active FROM questions WHERE id = {$id1}")->fetch();
            $this->assertSame('excluded', $q['review_status']);
            $this->assertSame(0, (int) $q['is_active']);

            // Test batch include
            $client->request('POST', '/moderation/questions/batch-action', [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            ], json_encode(['ids' => [$id1], 'action' => 'include']));

            $this->assertResponseIsSuccessful();
            $q = $pdo->query("SELECT review_status, is_active FROM questions WHERE id = {$id1}")->fetch();
            $this->assertSame('included', $q['review_status']);
            $this->assertSame(1, (int) $q['is_active']);
        } finally {
            $pdo->prepare("DELETE FROM questions WHERE id = {$id1}")->execute();
            $db->syncMasterJson();
        }
    }

    public function testMasterCommonRulesManagement(): void
    {
        $client = static::createClient();
        $db = static::getContainer()->get(GameDatabase::class);
        $pdo = $db->getPdo();

        // 1. Check Master Common Rules page loads with seeded default rules
        $client->request('GET', '/rules');
        $this->assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('Master Common Rules', $content);
        $this->assertStringContainsString('Host Has Final Authority', $content);

        // 2. Add a new common rule
        $client->request('POST', '/rules/save', [
            'rule_id' => 0,
            'title' => 'Test Master Rule 999',
            'description' => 'Test rule explanation for master common template',
            'icon' => '🎯',
            'sort_order' => 99,
        ]);
        $this->assertResponseRedirects('/rules');
        $client->followRedirect();

        $savedRule = $pdo->query("SELECT * FROM common_rules WHERE title = 'Test Master Rule 999'")->fetch();
        $this->assertNotEmpty($savedRule);
        $this->assertSame('🎯', $savedRule['icon']);

        // 3. Delete the added rule
        $ruleId = (int) $savedRule['id'];
        $client->request('POST', "/rules/{$ruleId}/delete");
        $this->assertResponseRedirects('/rules');

        $deletedCheck = $pdo->query("SELECT COUNT(*) FROM common_rules WHERE id = {$ruleId}")->fetchColumn();
        $this->assertSame(0, (int) $deletedCheck);
    }

    public function testShowRulesInheritanceAndCustomization(): void
    {
        $client = static::createClient();
        $db = static::getContainer()->get(GameDatabase::class);
        $pdo = $db->getPdo();

        // Create a new show
        $client->request('POST', '/shows/new', [
            'name' => 'Rules Inheritance Test Show',
            'subtitle' => 'Testing Rules',
            'default_timer' => 25,
        ]);
        $this->assertResponseRedirects();
        $show = $pdo->query("SELECT * FROM game_shows WHERE name = 'Rules Inheritance Test Show'")->fetch();
        $this->assertNotEmpty($show);
        $showId = (int) $show['id'];

        try {
            // Check that the show has inherited the master common rules
            $initialRules = $db->getShowRules($showId);
            $this->assertNotEmpty($initialRules);
            $this->assertGreaterThanOrEqual(5, count($initialRules));
            $this->assertSame('Host Has Final Authority', $initialRules[0]['title']);

            // Manage page should display the rules tab
            $client->request('GET', "/shows/{$showId}/manage");
            $this->assertResponseIsSuccessful();
            $manageContent = (string) $client->getResponse()->getContent();
            $this->assertStringContainsString('Show Tournament Rules', $manageContent);
            $this->assertStringContainsString('Host Has Final Authority', $manageContent);

            // Add a custom rule specifically for this show
            $client->request('POST', "/shows/{$showId}/rules/save", [
                'rule_id' => 0,
                'title' => 'Special Custom Show Rule',
                'description' => 'Only for this special gameshow edition',
                'icon' => '⚡',
            ]);
            $this->assertResponseRedirects("/shows/{$showId}/manage#tab-rules");

            $customizedRules = $db->getShowRules($showId);
            $this->assertCount(count($initialRules) + 1, $customizedRules);
            $lastRule = end($customizedRules);
            $this->assertSame('Special Custom Show Rule', $lastRule['title']);

            // Verify master common_rules table is UNTOUCHED
            $commonCheck = $pdo->query("SELECT COUNT(*) FROM common_rules WHERE title = 'Special Custom Show Rule'")->fetchColumn();
            $this->assertSame(0, (int) $commonCheck);

            // Reorder rule up
            $lastRuleId = (int) $lastRule['id'];
            $client->request('POST', "/shows/{$showId}/rules/move", [
                'rule_id' => $lastRuleId,
                'direction' => 'up',
            ]);
            $this->assertResponseRedirects("/shows/{$showId}/manage#tab-rules");

            $reorderedRules = $db->getShowRules($showId);
            $secondToLast = $reorderedRules[count($reorderedRules) - 2];
            $this->assertSame('Special Custom Show Rule', $secondToLast['title']);

            // Reset back to Common Rules
            $client->request('POST', "/shows/{$showId}/rules/reset-common");
            $this->assertResponseRedirects("/shows/{$showId}/manage#tab-rules");

            $resetRules = $db->getShowRules($showId);
            $this->assertCount(count($initialRules), $resetRules);
            $titles = array_column($resetRules, 'title');
            $this->assertNotContains('Special Custom Show Rule', $titles);
        } finally {
            $pdo->prepare('DELETE FROM game_shows WHERE id = ?')->execute([$showId]);
            $pdo->prepare('DELETE FROM show_teams WHERE show_id = ?')->execute([$showId]);
            $pdo->prepare('DELETE FROM show_rounds WHERE show_id = ?')->execute([$showId]);
        }
    }

    public function testRulesPresentationApiActions(): void
    {
        $client = static::createClient();
        $db = static::getContainer()->get(GameDatabase::class);
        $pdo = $db->getPdo();

        // Create temporary show
        $pdo->prepare('INSERT INTO game_shows (name, description, status, active_rule_index) VALUES (?, ?, ?, ?)')
            ->execute(['Rules API Presentation Test Show', 'Testing API', 'setup', -1]);
        $showId = (int) $pdo->lastInsertId();

        try {
            // 1. Initial state should have active_rule_index = -1
            $client->request('GET', "/api/shows/{$showId}/state");
            $this->assertResponseIsSuccessful();
            $data = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertSame(-1, (int) $data['show']['active_rule_index']);
            $this->assertNotEmpty($data['rules']);
            $this->assertNotEmpty($data['mobile_host_url']);
            $this->assertNotEmpty($data['lan_ip']);
            $this->assertStringContainsString('/shows/' . $showId . '/play', $data['mobile_host_url']);

            // 2. Start rules presentation mode
            $client->request('POST', "/api/shows/{$showId}/action", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'action' => 'start_rules',
                'rule_index' => 0,
            ]));
            $this->assertResponseIsSuccessful();
            $startRes = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertTrue($startRes['success']);
            $this->assertSame(0, $startRes['active_rule_index']);

            // Verify in state
            $client->request('GET', "/api/shows/{$showId}/state");
            $data = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertSame(0, (int) $data['show']['active_rule_index']);

            // 3. Next rule
            $client->request('POST', "/api/shows/{$showId}/action", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'action' => 'next_rule',
            ]));
            $this->assertResponseIsSuccessful();
            $nextRes = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertSame(1, $nextRes['active_rule_index']);

            // 4. Previous rule
            $client->request('POST', "/api/shows/{$showId}/action", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'action' => 'prev_rule',
            ]));
            $this->assertResponseIsSuccessful();
            $prevRes = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertSame(0, $prevRes['active_rule_index']);

            // 5. Finish / Close rules
            $client->request('POST', "/api/shows/{$showId}/action", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'action' => 'finish_rules',
            ]));
            $this->assertResponseIsSuccessful();
            $finishRes = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertSame(-1, $finishRes['active_rule_index']);

            $client->request('GET', "/api/shows/{$showId}/state");
            $data = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertSame(-1, (int) $data['show']['active_rule_index']);
        } finally {
            $pdo->prepare('DELETE FROM game_shows WHERE id = ?')->execute([$showId]);
        }
    }

    public function testQuestionNavigationHistory(): void
    {
        $client = static::createClient();
        $db = static::getContainer()->get(GameDatabase::class);
        $pdo = $db->getPdo();

        $client->request('POST', '/shows/new', [
            'name' => 'Question Navigation Test Show',
            'subtitle' => 'Testing Navigation',
            'default_timer' => 25,
        ]);
        $this->assertResponseRedirects();
        $client->followRedirect();

        $show = $pdo->query("SELECT * FROM game_shows WHERE name = 'Question Navigation Test Show' ORDER BY id DESC LIMIT 1")->fetch();
        $this->assertNotEmpty($show);
        $showId = (int) $show['id'];

        try {
            // Start question 1
            $client->request('POST', "/api/shows/{$showId}/action", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'action' => 'start_question',
            ]));
            $this->assertResponseIsSuccessful();
            $q1 = json_decode((string) $client->getResponse()->getContent(), true);
            $q1Id = $q1['question_id'];

            // Start question 2
            $client->request('POST', "/api/shows/{$showId}/action", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'action' => 'start_question',
            ]));
            $this->assertResponseIsSuccessful();
            $q2 = json_decode((string) $client->getResponse()->getContent(), true);
            $q2Id = $q2['question_id'];
            $this->assertNotSame($q1Id, $q2Id);

            // Navigate back to previous question
            $client->request('POST', "/api/shows/{$showId}/action", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'action' => 'prev_question',
            ]));
            $this->assertResponseIsSuccessful();
            $prevRes = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertSame($q1Id, $prevRes['question_id']);

            // Navigate forward with next_question
            $client->request('POST', "/api/shows/{$showId}/action", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'action' => 'next_question',
            ]));
            $this->assertResponseIsSuccessful();
            $nextRes = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertSame($q2Id, $nextRes['question_id']);
        } finally {
            $pdo->prepare('DELETE FROM game_shows WHERE id = ?')->execute([$showId]);
        }
    }

    public function testHomeQrModalAndTvDisplaySanity(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');
        $this->assertResponseIsSuccessful();
        $homeContent = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('home-host-qr-modal', $homeContent);
        $this->assertStringContainsString('iPhone QR', $homeContent);

        // Fetch first show
        $db = static::getContainer()->get(GameDatabase::class);
        $pdo = $db->getPdo();
        $show = $pdo->query('SELECT id FROM game_shows ORDER BY id LIMIT 1')->fetch();
        if ($show) {
            $showId = (int) $show['id'];

            // TV screen must NOT have host QR code or remote keys
            $client->request('GET', "/shows/{$showId}/display");
            $this->assertResponseIsSuccessful();
            $tvContent = (string) $client->getResponse()->getContent();
            $this->assertStringNotContainsString('Host Wireless Remote', $tvContent);
            $this->assertStringNotContainsString('tv-host-qrcode', $tvContent);
            $this->assertStringNotContainsString('qrcode.min.js', $tvContent);

            // Arena must have collapsible top menu and prev/next controls
            $client->request('GET', "/shows/{$showId}/play");
            $this->assertResponseIsSuccessful();
            $arenaContent = (string) $client->getResponse()->getContent();
            $this->assertStringContainsString('arena-dropdown-menu', $arenaContent);
            $this->assertStringContainsString('dock-btn-prev', $arenaContent);
        }
    }

    public function testEditTeamNameAndFixedIcon(): void
    {
        $client = static::createClient();
        $db = static::getContainer()->get(GameDatabase::class);
        $pdo = $db->getPdo();

        $pdo->prepare('INSERT INTO game_shows (name, description, status) VALUES (?, ?, ?)')
            ->execute(['Team Edit Test Show', 'Testing team edit', 'setup']);
        $showId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO show_teams (show_id, name, members, flight_group, avatar_emoji, avatar_color) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$showId, 'Original Team', 'Member 1', 'Acorn Flight', '🍁', '#f97316']);
        $teamId = (int) $pdo->lastInsertId();

        try {
            // Verify Manage Show renders the edit team modal and button
            $client->request('GET', "/shows/{$showId}/manage");
            $this->assertResponseIsSuccessful();
            $manageHtml = (string) $client->getResponse()->getContent();
            $this->assertStringContainsString('edit-team-modal', $manageHtml);
            $this->assertStringContainsString('openEditTeamModal', $manageHtml);
            $this->assertStringContainsString('edit-team-btn', $manageHtml);

            // 1. Update team via HTTP POST form
            $client->request('POST', "/shows/{$showId}/teams/{$teamId}/update", [
                'name' => 'The Golden Pumpkins',
                'members' => 'Alice & Bob',
                'flight_group' => 'Pumpkin Flight',
                'avatar_emoji' => '🎃',
                'avatar_color' => '#eab308',
            ]);
            $this->assertResponseRedirects("/shows/{$showId}/manage");
            $client->followRedirect();
            $this->assertResponseIsSuccessful();

            $team = $pdo->query("SELECT * FROM show_teams WHERE id = {$teamId}")->fetch();
            $this->assertSame('The Golden Pumpkins', $team['name']);
            $this->assertSame('Alice & Bob', $team['members']);
            $this->assertSame('Pumpkin Flight', $team['flight_group']);
            $this->assertSame('🎃', $team['avatar_emoji']);
            $this->assertSame('#eab308', $team['avatar_color']);

            // 2. Update team via API action
            $client->request('POST', "/api/shows/{$showId}/action", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'action' => 'update_team',
                'team_id' => $teamId,
                'name' => 'Apple Pie Champions',
                'members' => 'Charlie & Dana',
                'flight_group' => 'Acorn Flight',
                'avatar_emoji' => '🍎',
                'avatar_color' => '#10b981',
            ]));
            $this->assertResponseIsSuccessful();
            $apiRes = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertTrue($apiRes['success']);

            $teamUpdated = $pdo->query("SELECT * FROM show_teams WHERE id = {$teamId}")->fetch();
            $this->assertSame('Apple Pie Champions', $teamUpdated['name']);
            $this->assertSame('🍎', $teamUpdated['avatar_emoji']);
            $this->assertSame('#10b981', $teamUpdated['avatar_color']);
        } finally {
            $pdo->prepare('DELETE FROM show_teams WHERE show_id = ?')->execute([$showId]);
            $pdo->prepare('DELETE FROM game_shows WHERE id = ?')->execute([$showId]);
        }
    }

    public function testBatchStarUnstarAndFilterQuestionBankByStarred(): void
    {
        $client = static::createClient();
        $db = static::getContainer()->get(GameDatabase::class);
        $pdo = $db->getPdo();

        $catId = (int) $pdo->query('SELECT id FROM categories ORDER BY id ASC LIMIT 1')->fetchColumn();
        $ins = $pdo->prepare("INSERT INTO questions (category_id, title, answer, is_active, is_favorite, review_status) VALUES (?, ?, ?, 1, 0, 'included')");
        $ins->execute([$catId, 'Star Test 1', 'STAR ANSWER ONE']);
        $qId1 = (int) $pdo->lastInsertId();
        $ins->execute([$catId, 'Star Test 2', 'STAR ANSWER TWO']);
        $qId2 = (int) $pdo->lastInsertId();

        try {
            // 1. Batch star both puzzles
            $client->request('POST', '/moderation/questions/batch-action', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'ids' => [$qId1, $qId2],
                'action' => 'star',
            ]));
            $this->assertResponseIsSuccessful();
            $res = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertTrue($res['success']);
            $this->assertSame(2, $res['affected_count']);

            $fav1 = (int) $pdo->query("SELECT is_favorite FROM questions WHERE id = {$qId1}")->fetchColumn();
            $fav2 = (int) $pdo->query("SELECT is_favorite FROM questions WHERE id = {$qId2}")->fetchColumn();
            $this->assertSame(1, $fav1);
            $this->assertSame(1, $fav2);

            // 2. Filter Question Bank by status=starred
            $client->request('GET', '/moderation?status=starred&q=STAR+ANSWER');
            $this->assertResponseIsSuccessful();
            $html = (string) $client->getResponse()->getContent();
            $this->assertStringContainsString('STAR ANSWER ONE', $html);
            $this->assertStringContainsString('STAR ANSWER TWO', $html);

            // 3. Batch unstar one puzzle
            $client->request('POST', '/moderation/questions/batch-action', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'ids' => [$qId1],
                'action' => 'unstar',
            ]));
            $this->assertResponseIsSuccessful();
            $fav1After = (int) $pdo->query("SELECT is_favorite FROM questions WHERE id = {$qId1}")->fetchColumn();
            $this->assertSame(0, $fav1After);

            // 4. Filter by status=starred again: only STAR ANSWER TWO should appear
            $client->request('GET', '/moderation?status=starred&q=STAR+ANSWER');
            $this->assertResponseIsSuccessful();
            $htmlAfter = (string) $client->getResponse()->getContent();
            $this->assertStringNotContainsString('STAR ANSWER ONE', $htmlAfter);
            $this->assertStringContainsString('STAR ANSWER TWO', $htmlAfter);
        } finally {
            $pdo->prepare('DELETE FROM questions WHERE id IN (?, ?)')->execute([$qId1, $qId2]);
        }
    }

    public function testGameShowStarredOnlyPuzzleSelectionMode(): void
    {
        $client = static::createClient();
        $db = static::getContainer()->get(GameDatabase::class);
        $pdo = $db->getPdo();

        // Create temporary category with 1 Starred puzzle and 1 Unstarred Included puzzle
        $pdo->prepare('INSERT INTO categories (name, slug, color, icon) VALUES (?, ?, ?, ?)')
            ->execute(['Starred Test Cat', 'starred-test-cat-' . uniqid(), '#f59e0b', '⭐']);
        $catId = (int) $pdo->lastInsertId();

        $ins = $pdo->prepare("INSERT INTO questions (category_id, title, answer, difficulty, is_active, is_favorite, review_status) VALUES (?, ?, ?, 'Medium', 1, ?, ?)");
        $ins->execute([$catId, 'Unstarred Included Puzzle', 'UNSTARRED INCLUDED', 0, 'included']);
        $unstarredQId = (int) $pdo->lastInsertId();

        $ins->execute([$catId, 'Starred Skipped Puzzle', 'STARRED SKIPPED', 1, 'skipped']);
        $starredSkippedQId = (int) $pdo->lastInsertId();

        $ins->execute([$catId, 'Starred Special Puzzle', 'STARRED SPECIAL', 1, 'included']);
        $starredQId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO game_shows (name, description, starred_only, status) VALUES (?, ?, 0, ?)')
            ->execute(['Starred Mode Show', 'Testing starred_only', 'setup']);
        $showId = (int) $pdo->lastInsertId();

        $allocJson = json_encode([['category_id' => $catId, 'count' => 5, 'difficulty' => 'Any']]);
        $pdo->prepare('INSERT INTO show_rounds (show_id, round_number, name, round_type, category_allocations, status) VALUES (?, 1, ?, ?, ?, ?)')
            ->execute([$showId, 'Round 1', 'all_play', $allocJson, 'active']);
        $roundId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE game_shows SET current_round_id = ? WHERE id = ?')->execute([$roundId, $showId]);

        try {
            // 1. Even with starred_only = 0 (default), Starred & Included ($starredQId) MUST take 1st priority over Unstarred Included ($unstarredQId),
            // and Starred Skipped ($starredSkippedQId) must NEVER be selected!
            $client->request('POST', "/api/shows/{$showId}/action", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'action' => 'start_question',
                'round_id' => $roundId,
            ]));
            $this->assertResponseIsSuccessful();
            $startData = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertTrue($startData['success']);
            $this->assertSame($starredQId, (int) $startData['question_id']);

            // 2. Next question (when all Starred & Included in that category are exhausted and starred_only = 0):
            // falls back to Unstarred & Included ($unstarredQId), and still NEVER picks $starredSkippedQId!
            $client->request('POST', "/api/shows/{$showId}/action", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'action' => 'start_question',
                'round_id' => $roundId,
            ]));
            $this->assertResponseIsSuccessful();
            $startData2 = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertTrue($startData2['success']);
            $this->assertSame($unstarredQId, (int) $startData2['question_id']);
        } finally {
            $pdo->prepare('DELETE FROM show_used_questions WHERE show_id = ?')->execute([$showId]);
            $pdo->prepare('DELETE FROM show_rounds WHERE show_id = ?')->execute([$showId]);
            $pdo->prepare('DELETE FROM game_shows WHERE id = ?')->execute([$showId]);
            $pdo->prepare('DELETE FROM questions WHERE id IN (?, ?, ?)')->execute([$unstarredQId, $starredSkippedQId, $starredQId]);
            $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$catId]);
        }
    }

    public function testRoundEndsAutomaticallyAndShowsNextRoundIntro(): void
    {
        $client = static::createClient();
        $db = static::getContainer()->get(GameDatabase::class);
        $pdo = $db->getPdo();

        $catId = (int) $pdo->query('SELECT id FROM categories ORDER BY id ASC LIMIT 1')->fetchColumn();

        $pdo->prepare('INSERT INTO game_shows (name, description, starred_only, status) VALUES (?, ?, 0, ?)')
            ->execute(['Auto End Round Show', 'Testing auto round end', 'setup']);
        $showId = (int) $pdo->lastInsertId();

        // Round 1 has quota = 2 questions; Round 2 has quota = 2 questions
        $alloc1 = json_encode([['category_id' => $catId, 'count' => 2, 'difficulty' => 'Any']]);
        $alloc2 = json_encode([['category_id' => $catId, 'count' => 2, 'difficulty' => 'Any']]);

        $rStmt = $pdo->prepare('INSERT INTO show_rounds (show_id, round_number, name, round_type, timer_seconds, points_per_question, steal_points, category_allocations, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, "pending")');
        $rStmt->execute([$showId, 1, 'Round 1: Opening', 'all_play', 20, 10, 5, $alloc1]);
        $round1Id = (int) $pdo->lastInsertId();

        $rStmt->execute([$showId, 2, 'Round 2: Showdown', 'head_to_head', 30, 20, 10, $alloc2]);
        $round2Id = (int) $pdo->lastInsertId();

        $pdo->prepare('UPDATE game_shows SET current_round_id = ? WHERE id = ?')->execute([$round1Id, $showId]);

        try {
            // Question 1 of Round 1
            $client->request('POST', "/api/shows/{$showId}/action", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['action' => 'next_question']));
            $res1 = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertTrue($res1['success']);
            $this->assertNotEmpty($res1['question_id']);

            // Question 2 of Round 1
            $client->request('POST', "/api/shows/{$showId}/action", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['action' => 'next_question']));
            $res2 = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertTrue($res2['success']);
            $this->assertNotEmpty($res2['question_id']);

            // Clicking Next after Question 2 (quota = 2 reached) MUST automatically end Round 1
            $client->request('POST', "/api/shows/{$showId}/action", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['action' => 'next_question']));
            $resEnd = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertTrue($resEnd['success']);
            $this->assertTrue($resEnd['round_completed'] ?? false);
            $this->assertSame(1, $resEnd['round_number']);

            // Verify state endpoint reports round_progress.is_completed = true and includes next_round details
            $client->request('GET', "/api/shows/{$showId}/state");
            $state = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertTrue($state['round_progress']['is_completed']);
            $this->assertSame(2, $state['round_progress']['used']);
            $this->assertSame(2, $state['round_progress']['total']);
            $this->assertSame($round2Id, (int) $state['round_progress']['next_round']['id']);

            // Clicking Next from End of Round 1 screen transitions to Round 2 Intro/Rules briefing screen
            $client->request('POST', "/api/shows/{$showId}/action", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['action' => 'next_question']));
            $resIntro = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertTrue($resIntro['success']);
            $this->assertTrue($resIntro['round_intro'] ?? false);
            $this->assertSame($round2Id, $resIntro['round_id']);

            // Clicking Next from Round 2 Intro screen starts Question 1 of Round 2
            $client->request('POST', "/api/shows/{$showId}/action", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['action' => 'next_question']));
            $resR2Q1 = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertTrue($resR2Q1['success']);
            $this->assertNotEmpty($resR2Q1['question_id']);
        } finally {
            $pdo->prepare('DELETE FROM show_used_questions WHERE show_id = ?')->execute([$showId]);
            $pdo->prepare('DELETE FROM show_rounds WHERE show_id = ?')->execute([$showId]);
            $pdo->prepare('DELETE FROM game_shows WHERE id = ?')->execute([$showId]);
        }
    }

    public function testManualRoundQuestionsAddRemoveAndDuplicatePrevention(): void
    {
        $client = static::createClient();
        $db = static::getContainer()->get(GameDatabase::class);
        $pdo = $db->getPdo();

        $catId = (int) $pdo->query('SELECT id FROM categories ORDER BY id ASC LIMIT 1')->fetchColumn();

        $insQ = $pdo->prepare("INSERT INTO questions (category_id, title, answer, difficulty, is_active, is_favorite, review_status) VALUES (?, ?, ?, 'Medium', 1, 1, ?)");
        $insQ->execute([$catId, 'Manual Q1', '4 WHEEL DRIVE UNIQUE TEST', 'included']);
        $q1Id = (int) $pdo->lastInsertId();

        $insQ->execute([$catId, 'Manual Q2 Duplicate Answer', 'FOUR WHEEL DRIVE UNIQUE TEST', 'included']);
        $q2DupAnsId = (int) $pdo->lastInsertId();

        $insQ->execute([$catId, 'Manual Q3 Distinct', 'ONCE IN A BLUE MOON TEST', 'included']);
        $q3Id = (int) $pdo->lastInsertId();

        $insQ->execute([$catId, 'Manual Q4 Skipped', 'SKIPPED PUZZLE TEST', 'skipped']);
        $q4SkippedId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO game_shows (name, description, starred_only, status) VALUES (?, ?, 0, ?)')
            ->execute(['Manual Round Curation Show', 'Testing manual add/remove & no duplicates', 'setup']);
        $showId = (int) $pdo->lastInsertId();

        $rStmt = $pdo->prepare('INSERT INTO show_rounds (show_id, round_number, name, round_type, timer_seconds, points_per_question, steal_points, category_allocations, status) VALUES (?, ?, ?, ?, 25, 10, 5, "[]", "pending")');
        $rStmt->execute([$showId, 1, 'Round 1: Custom', 'all_play']);
        $round1Id = (int) $pdo->lastInsertId();
        $rStmt->execute([$showId, 2, 'Round 2: Custom', 'all_play']);
        $round2Id = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE game_shows SET current_round_id = ? WHERE id = ?')->execute([$round1Id, $showId]);

        try {
            // 1. Add Q1 and Q3 to Round 1
            $client->request('POST', "/shows/{$showId}/rounds/{$round1Id}/questions/add", [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode([
                'question_ids' => [$q1Id, $q3Id],
            ]));
            $this->assertResponseIsSuccessful();
            $addRes = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertTrue($addRes['success']);
            $this->assertSame(2, $addRes['added_count']);
            $this->assertCount(2, $addRes['round_questions']);

            // 2. Attempt to add duplicate question ID ($q1Id) to Round 2 -> MUST be blocked!
            $client->request('POST', "/shows/{$showId}/rounds/{$round2Id}/questions/add", [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode([
                'question_ids' => [$q1Id],
            ]));
            $this->assertSame(409, $client->getResponse()->getStatusCode());

            // 3. Attempt to add different question ID with duplicate normalized answer ($q2DupAnsId: "FOUR WHEEL DRIVE UNIQUE TEST") -> MUST be blocked!
            $client->request('POST', "/shows/{$showId}/rounds/{$round2Id}/questions/add", [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode([
                'question_ids' => [$q2DupAnsId],
            ]));
            $this->assertSame(409, $client->getResponse()->getStatusCode());

            // 4. Attempt to add Skipped puzzle ($q4SkippedId) -> MUST be blocked!
            $client->request('POST', "/shows/{$showId}/rounds/{$round2Id}/questions/add", [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode([
                'question_ids' => [$q4SkippedId],
            ]));
            $this->assertSame(409, $client->getResponse()->getStatusCode());

            // 4b. Reorder Round 1 questions so Q3 moves before Q1
            $client->request('POST', "/shows/{$showId}/rounds/{$round1Id}/questions/reorder", [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode([
                'question_id' => $q3Id,
                'direction' => 'left',
            ]));
            $this->assertResponseIsSuccessful();
            $reordRes = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertTrue($reordRes['success']);
            $this->assertSame($q3Id, (int) $reordRes['round_questions'][0]['id']);
            $this->assertSame($q1Id, (int) $reordRes['round_questions'][1]['id']);

            // 5. Remove Q3 from Round 1 -> Round 1 now has 1 question ($q1Id)
            $client->request('POST', "/shows/{$showId}/rounds/{$round1Id}/questions/{$q3Id}/remove", [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json']);
            $this->assertResponseIsSuccessful();
            $remRes = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertTrue($remRes['success']);
            $this->assertCount(1, $remRes['round_questions']);

            // 6. Playing Round 1 picks $q1Id first, and clicking Next again automatically ends Round 1!
            $client->request('POST', "/api/shows/{$showId}/action", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['action' => 'next_question']));
            $play1 = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertTrue($play1['success']);
            $this->assertSame($q1Id, (int) $play1['question_id']);

            $client->request('POST', "/api/shows/{$showId}/action", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['action' => 'next_question']));
            $playEnd = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertTrue($playEnd['round_completed'] ?? false);
        } finally {
            $pdo->prepare('DELETE FROM show_round_questions WHERE show_id = ?')->execute([$showId]);
            $pdo->prepare('DELETE FROM show_used_questions WHERE show_id = ?')->execute([$showId]);
            $pdo->prepare('DELETE FROM show_rounds WHERE show_id = ?')->execute([$showId]);
            $pdo->prepare('DELETE FROM game_shows WHERE id = ?')->execute([$showId]);
            $pdo->prepare('DELETE FROM questions WHERE id IN (?, ?, ?, ?)')->execute([$q1Id, $q2DupAnsId, $q3Id, $q4SkippedId]);
        }
    }

    public function testRapidFireRoundIncludesAllRemainingStarredAndLocksOrderAndSelection(): void
    {
        $client = static::createClient();
        $db = static::getContainer()->get(GameDatabase::class);
        $pdo = $db->getPdo();

        $catId = (int) $pdo->query('SELECT id FROM categories ORDER BY id ASC LIMIT 1')->fetchColumn();

        $insQ = $pdo->prepare("INSERT INTO questions (category_id, title, answer, difficulty, is_active, is_favorite, review_status) VALUES (?, ?, ?, 'Medium', 1, ?, ?)");
        // Starred & Included puzzle 1 (will be put in Round 1 initially)
        $insQ->execute([$catId, 'RF Starred 1', 'RAPID FIRE STARRED ALPHA TEST', 1, 'included']);
        $qStar1 = (int) $pdo->lastInsertId();

        // Starred & Included puzzle 2 (not in Round 1 -> must automatically be in Rapid Fire!)
        $insQ->execute([$catId, 'RF Starred 2', 'RAPID FIRE STARRED BETA TEST', 1, 'included']);
        $qStar2 = (int) $pdo->lastInsertId();

        // Unstarred & Included puzzle (must NEVER be in Rapid Fire!)
        $insQ->execute([$catId, 'RF Unstarred', 'RAPID FIRE UNSTARRED GAMMA TEST', 0, 'included']);
        $qUnstarred = (int) $pdo->lastInsertId();

        // Starred but Skipped puzzle (must NEVER be in Rapid Fire!)
        $insQ->execute([$catId, 'RF Starred Skipped', 'RAPID FIRE SKIPPED DELTA TEST', 1, 'skipped']);
        $qStarSkipped = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO game_shows (name, description, starred_only, status) VALUES (?, ?, 0, ?)')
            ->execute(['Rapid Fire Test Show', 'Testing Rapid Fire overflow starred round', 'setup']);
        $showId = (int) $pdo->lastInsertId();

        $rStmt = $pdo->prepare('INSERT INTO show_rounds (show_id, round_number, name, round_type, timer_seconds, points_per_question, steal_points, category_allocations, status) VALUES (?, ?, ?, ?, 25, 10, 5, "[]", "pending")');
        $rStmt->execute([$showId, 1, 'Round 1: Main', 'all_play']);
        $round1Id = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE game_shows SET current_round_id = ? WHERE id = ?')->execute([$round1Id, $showId]);

        try {
            // Put $qStar1 into Round 1
            $client->request('POST', "/shows/{$showId}/rounds/{$round1Id}/questions/add", [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode([
                'question_ids' => [$qStar1],
            ]));
            $this->assertResponseIsSuccessful();

            // Enable Rapid Fire Round via checkbox toggle endpoint
            $client->request('POST', "/shows/{$showId}/rapid-fire-toggle", ['enable' => '1']);
            $this->assertResponseRedirects("/shows/{$showId}/manage#tab-rounds");

            $rfRound = $pdo->query("SELECT * FROM show_rounds WHERE show_id = {$showId} AND round_type = 'rapid_fire'")->fetch();
            $this->assertNotEmpty($rfRound, 'Rapid Fire round should be created');
            $rfRoundId = (int) $rfRound['id'];

            // Inspect all questions assigned to Rapid Fire round
            $rfRows = $pdo->query("
                SELECT srq.question_id, q.is_favorite, q.review_status
                FROM show_round_questions srq
                JOIN questions q ON q.id = srq.question_id
                WHERE srq.show_id = {$showId} AND srq.round_id = {$rfRoundId}
            ")->fetchAll();
            $rfQIds = array_map(fn ($r) => (int) $r['question_id'], $rfRows);

            // $qStar2 (not in Round 1) MUST be in Rapid Fire
            $this->assertContains($qStar2, $rfQIds);
            // $qStar1 (already in Round 1) MUST NOT be in Rapid Fire
            $this->assertNotContains($qStar1, $rfQIds);
            // $qUnstarred MUST NOT be in Rapid Fire
            $this->assertNotContains($qUnstarred, $rfQIds);
            // $qStarSkipped MUST NOT be in Rapid Fire
            $this->assertNotContains($qStarSkipped, $rfQIds);

            // Every single puzzle in Rapid Fire must be Starred & Included
            foreach ($rfRows as $rRow) {
                $this->assertSame(1, (int) $rRow['is_favorite']);
                $this->assertSame('included', $rRow['review_status']);
            }

            // Adding $qStar2 to Round 1 must automatically remove $qStar2 from Rapid Fire!
            $client->request('POST', "/shows/{$showId}/rounds/{$round1Id}/questions/add", [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode([
                'question_ids' => [$qStar2],
            ]));
            $this->assertResponseIsSuccessful();
            $rfQIdsAfterAdd = array_map('intval', $pdo->query("SELECT question_id FROM show_round_questions WHERE show_id = {$showId} AND round_id = {$rfRoundId}")->fetchAll(\PDO::FETCH_COLUMN));
            $this->assertNotContains($qStar2, $rfQIdsAfterAdd);

            // Removing $qStar1 from Round 1 must automatically add $qStar1 into Rapid Fire!
            $client->request('POST', "/shows/{$showId}/rounds/{$round1Id}/questions/{$qStar1}/remove", [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json']);
            $this->assertResponseIsSuccessful();
            $rfQIdsAfterRemove = array_map('intval', $pdo->query("SELECT question_id FROM show_round_questions WHERE show_id = {$showId} AND round_id = {$rfRoundId}")->fetchAll(\PDO::FETCH_COLUMN));
            $this->assertContains($qStar1, $rfQIdsAfterRemove);

            // Attempting to manually add, remove, swap, or reorder in Rapid Fire round MUST be rejected (400 Bad Request)
            $client->request('POST', "/shows/{$showId}/rounds/{$rfRoundId}/questions/add", [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode([
                'question_ids' => [$qUnstarred],
            ]));
            $this->assertSame(400, $client->getResponse()->getStatusCode());

            $client->request('POST', "/shows/{$showId}/rounds/{$rfRoundId}/questions/{$qStar1}/remove", [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json']);
            $this->assertSame(400, $client->getResponse()->getStatusCode());

            $client->request('POST', "/shows/{$showId}/rounds/{$rfRoundId}/questions/{$qStar1}/swap", [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json']);
            $this->assertSame(400, $client->getResponse()->getStatusCode());

            $client->request('POST', "/shows/{$showId}/rounds/{$rfRoundId}/questions/reorder", [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], json_encode([
                'question_id' => $qStar1,
                'direction' => 'left',
            ]));
            $this->assertSame(400, $client->getResponse()->getStatusCode());
        } finally {
            $pdo->prepare('DELETE FROM show_round_questions WHERE show_id = ?')->execute([$showId]);
            $pdo->prepare('DELETE FROM show_used_questions WHERE show_id = ?')->execute([$showId]);
            $pdo->prepare('DELETE FROM show_rounds WHERE show_id = ?')->execute([$showId]);
            $pdo->prepare('DELETE FROM game_shows WHERE id = ?')->execute([$showId]);
            $pdo->prepare('DELETE FROM questions WHERE id IN (?, ?, ?, ?)')->execute([$qStar1, $qStar2, $qUnstarred, $qStarSkipped]);
        }
    }

    public function testPreShowSplashTeamsRulesAndExplicitRoundStartFlow(): void
    {
        $client = static::createClient();
        $db = static::getContainer()->get(GameDatabase::class);
        $pdo = $db->getPdo();

        $catId = (int) $pdo->query('SELECT id FROM categories ORDER BY id ASC LIMIT 1')->fetchColumn();
        $insQ = $pdo->prepare("INSERT INTO questions (category_id, title, answer, difficulty, is_active, is_favorite, review_status) VALUES (?, ?, ?, 'Medium', 1, ?, ?)");
        $insQ->execute([$catId, 'SF Q1', 'SPLASH FLOW Q1', 1, 'included']);
        $q1Id = (int) $pdo->lastInsertId();

        $insQ->execute([$catId, 'SF Q2', 'SPLASH FLOW Q2', 1, 'included']);
        $q2Id = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO game_shows (name, description, starred_only, status) VALUES (?, ?, 0, ?)')
            ->execute(['Khapre Party Splash Flow Test', 'Test Flow', 'setup']);
        $showId = (int) $pdo->lastInsertId();

        $allocJson = json_encode([['category_id' => $catId, 'count' => 1]]);
        $pdo->prepare('INSERT INTO show_rounds (show_id, round_number, name, round_type, timer_seconds, points_per_question, steal_points, advancement_type, advance_top_x, category_allocations, status) VALUES (?, 1, ?, ?, 25, 10, 0, ?, 0, ?, ?)')
            ->execute([$showId, 'Round 1: Warm-Up', 'all_teams', 'all', $allocJson, 'pending']);
        $r1Id = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO show_rounds (show_id, round_number, name, round_type, timer_seconds, points_per_question, steal_points, advancement_type, advance_top_x, category_allocations, status) VALUES (?, 2, ?, ?, 20, 15, 5, ?, 4, ?, ?)')
            ->execute([$showId, 'Round 2: Showdown', 'head_to_head', 'top_x', $allocJson, 'pending']);
        $r2Id = (int) $pdo->lastInsertId();

        $pdo->prepare('UPDATE game_shows SET current_round_id = ? WHERE id = ?')->execute([$r1Id, $showId]);
        $pdo->prepare('INSERT INTO show_round_questions (show_id, round_id, question_id, sort_order) VALUES (?, ?, ?, 1)')->execute([$showId, $r1Id, $q1Id]);
        $pdo->prepare('INSERT INTO show_round_questions (show_id, round_id, question_id, sort_order) VALUES (?, ?, ?, 1)')->execute([$showId, $r2Id, $q2Id]);

        try {
            // Verify TV Display contains the splash card with /media/khapre_party_splash.jpg
            $client->request('GET', "/shows/{$showId}/display");
            $this->assertResponseIsSuccessful();
            $displayHtml = (string) $client->getResponse()->getContent();
            $this->assertStringContainsString('/media/khapre_party_splash.jpg', $displayHtml);
            $this->assertStringContainsString('tv-welcome-splash-card', $displayHtml);
            $this->assertStringContainsString('tv-teams-intro-card', $displayHtml);

            // Stage 1: Initial state is 'setup' (Welcome Splash Screen on TV)
            $client->request('GET', "/api/shows/{$showId}/state");
            $state1 = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertSame('setup', $state1['show']['status']);
            $this->assertNull($state1['current_question']);
            $rulesCount = count($state1['rules']);
            $this->assertGreaterThan(0, $rulesCount);

            // Host clicks Next -> Stage 2: 'teams_intro' (Show Competing Teams on TV)
            $client->request('POST', "/api/shows/{$showId}/action", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'action' => 'host_next',
            ]));
            $this->assertResponseIsSuccessful();
            $resTeams = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertSame('teams_intro', $resTeams['stage']);

            // Host clicks Next -> Stage 3: 'rules_intro' (Unveil Rules 1-by-1 starting at Rule 0)
            for ($i = 0; $i < $rulesCount; $i++) {
                $client->request('POST', "/api/shows/{$showId}/action", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                    'action' => 'host_next',
                ]));
                $this->assertResponseIsSuccessful();
                $resRule = json_decode((string) $client->getResponse()->getContent(), true);
                $this->assertSame('rules_intro', $resRule['stage']);
                $this->assertSame($i, $resRule['active_rule_index']);
            }

            // Host clicks Next after last rule -> Stage 4: 'round_intro' (Round 1 Briefing on TV, waiting for explicit start)
            $client->request('POST', "/api/shows/{$showId}/action", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'action' => 'host_next',
            ]));
            $this->assertResponseIsSuccessful();
            $resR1Intro = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertSame('round_intro', $resR1Intro['stage']);
            $this->assertSame(-1, $resR1Intro['active_rule_index']);

            // Host explicitly clicks Start Round 1 ('host_next') -> Starts Round 1 Question 1!
            $client->request('POST', "/api/shows/{$showId}/action", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'action' => 'host_next',
            ]));
            $this->assertResponseIsSuccessful();
            $resR1Start = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertSame($q1Id, (int) $resR1Start['question_id']);

            // Host clicks Next after Round 1 Question 1 -> Automatically ends Round 1!
            $client->request('POST', "/api/shows/{$showId}/action", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'action' => 'host_next',
            ]));
            $this->assertResponseIsSuccessful();
            $resR1End = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertTrue($resR1End['round_completed']);
            $this->assertSame(1, (int) $resR1End['round_number']);

            // Host clicks Next from End of Round 1 -> Shows Round 2 Briefing ('round_intro' for Round 2)!
            $client->request('POST', "/api/shows/{$showId}/action", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'action' => 'host_next',
            ]));
            $this->assertResponseIsSuccessful();
            $resR2Intro = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertTrue($resR2Intro['round_intro']);
            $this->assertSame($r2Id, (int) $resR2Intro['round_id']);

            // Host explicitly clicks Start Round 2 ('host_next') -> Starts Round 2 Question 1!
            $client->request('POST', "/api/shows/{$showId}/action", [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
                'action' => 'host_next',
            ]));
            $this->assertResponseIsSuccessful();
            $resR2Start = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertSame($q2Id, (int) $resR2Start['question_id']);
        } finally {
            $pdo->prepare('DELETE FROM show_round_questions WHERE show_id = ?')->execute([$showId]);
            $pdo->prepare('DELETE FROM show_used_questions WHERE show_id = ?')->execute([$showId]);
            $pdo->prepare('DELETE FROM show_rounds WHERE show_id = ?')->execute([$showId]);
            $pdo->prepare('DELETE FROM game_shows WHERE id = ?')->execute([$showId]);
            $pdo->prepare('DELETE FROM questions WHERE id IN (?, ?)')->execute([$q1Id, $q2Id]);
        }
    }
}
