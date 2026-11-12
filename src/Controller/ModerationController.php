<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AiImageGenerator;
use App\Service\DuplicateDetector;
use App\Service\GameDatabase;
use PDO;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/moderation')]
class ModerationController extends AbstractController
{
    public function __construct(
        private readonly GameDatabase $gameDatabase,
        private readonly AiImageGenerator $aiImageGenerator,
        private readonly DuplicateDetector $duplicateDetector,
    ) {
    }

    #[Route('', name: 'app_moderation_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $pdo = $this->gameDatabase->getPdo();
        $categories = $pdo->query('SELECT * FROM categories ORDER BY sort_order ASC, id ASC')->fetchAll();

        $selectedCat = (int) $request->query->get('category_id', 0);
        $diff = trim((string) $request->query->get('difficulty', ''));
        $status = trim((string) $request->query->get('status', 'all'));
        $search = trim((string) $request->query->get('q', ''));
        $favOnly = (bool) $request->query->get('fav', false);
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 30;
        $offset = ($page - 1) * $limit;

        $where = ['1=1'];
        $params = [];

        if ($status === 'unrated' || $status === 'avoid_selected' || $status === 'unreviewed') {
            $where[] = "(q.review_status = 'unrated' OR (q.review_status != 'included' AND q.review_status != 'excluded' AND q.review_status != 'skipped' AND q.is_active = 1))";
        } elseif ($status === 'skipped') {
            $where[] = "q.review_status = 'skipped'";
        } elseif ($status === 'included' || $status === '1') {
            $where[] = "q.review_status = 'included'";
        } elseif ($status === 'excluded' || $status === '0') {
            $where[] = "(q.review_status = 'excluded' OR q.is_active = 0)";
        }

        if ($selectedCat > 0) {
            $where[] = 'q.category_id = ?';
            $params[] = $selectedCat;
        }
        if ($diff !== '' && $diff !== 'all') {
            $where[] = 'q.difficulty = ?';
            $params[] = $diff;
        }
        if ($favOnly) {
            $where[] = 'q.is_favorite = 1';
        }
        if ($search !== '') {
            $where[] = '(q.answer LIKE ? OR q.title LIKE ? OR q.hint LIKE ?)';
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $whereClause = implode(' AND ', $where);

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM questions q WHERE {$whereClause}");
        $countStmt->execute($params);
        $totalCount = (int) $countStmt->fetchColumn();

        $sql = "SELECT q.*, c.name as category_name, c.color as category_color, c.icon as category_icon 
                FROM questions q JOIN categories c ON q.category_id = c.id 
                WHERE {$whereClause} ORDER BY q.id DESC LIMIT {$limit} OFFSET {$offset}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $questions = $stmt->fetchAll();

        $stats = [
            'total' => (int) $pdo->query('SELECT COUNT(*) FROM questions')->fetchColumn(),
            'unrated' => (int) $pdo->query("SELECT COUNT(*) FROM questions WHERE review_status = 'unrated' OR (review_status NOT IN ('included', 'excluded', 'skipped') AND is_active = 1)")->fetchColumn(),
            'included' => (int) $pdo->query("SELECT COUNT(*) FROM questions WHERE review_status = 'included'")->fetchColumn(),
            'excluded' => (int) $pdo->query("SELECT COUNT(*) FROM questions WHERE review_status = 'excluded' OR is_active = 0")->fetchColumn(),
            'skipped' => (int) $pdo->query("SELECT COUNT(*) FROM questions WHERE review_status = 'skipped'")->fetchColumn(),
            'favorites' => (int) $pdo->query('SELECT COUNT(*) FROM questions WHERE is_favorite = 1')->fetchColumn(),
        ];
        $stats['to_review'] = $stats['unrated'];
        $stats['pending'] = $stats['unrated'];

        return $this->render('moderation/index.html.twig', [
            'questions' => $questions,
            'categories' => $categories,
            'selected_cat' => $selectedCat,
            'difficulty' => $diff,
            'status' => $status,
            'search' => $search,
            'fav_only' => $favOnly,
            'page' => $page,
            'total_pages' => max(1, (int) ceil($totalCount / $limit)),
            'total_count' => $totalCount,
            'stats' => $stats,
        ]);
    }

    #[Route('/review', name: 'app_moderation_review', methods: ['GET'])]
    public function reviewDeck(Request $request): Response
    {
        $pdo = $this->gameDatabase->getPdo();
        $categories = $pdo->query('SELECT * FROM categories ORDER BY sort_order ASC, id ASC')->fetchAll();

        $selectedCat = (int) $request->query->get('category_id', 0);
        $diff = trim((string) $request->query->get('difficulty', ''));
        $status = trim((string) $request->query->get('status', 'unrated'));
        if ($status === '' || $status === 'avoid_selected') {
            $status = 'unrated';
        }
        $search = trim((string) $request->query->get('q', ''));
        $favOnly = (bool) $request->query->get('fav', false);
        $startId = (int) $request->query->get('start_id', 0);

        $where = ['1=1'];
        $params = [];

        if ($status === 'unrated' || $status === 'avoid_selected' || $status === 'unreviewed') {
            // Strictly ONLY unrated questions: avoids included, excluded, and skipped!
            $where[] = "(q.review_status = 'unrated' OR (q.review_status != 'included' AND q.review_status != 'excluded' AND q.review_status != 'skipped' AND q.is_active = 1))";
        } elseif ($status === 'skipped') {
            $where[] = "q.review_status = 'skipped'";
        } elseif ($status === 'included' || $status === '1') {
            $where[] = "q.review_status = 'included'";
        } elseif ($status === 'excluded' || $status === '0') {
            $where[] = "(q.review_status = 'excluded' OR q.is_active = 0)";
        }

        if ($selectedCat > 0) {
            $where[] = 'q.category_id = ?';
            $params[] = $selectedCat;
        }
        if ($diff !== '' && $diff !== 'all') {
            $where[] = 'q.difficulty = ?';
            $params[] = $diff;
        }
        if ($favOnly) {
            $where[] = 'q.is_favorite = 1';
        }
        if ($search !== '') {
            $where[] = '(q.answer LIKE ? OR q.title LIKE ? OR q.hint LIKE ?)';
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $whereClause = implode(' AND ', $where);
        $sql = "SELECT q.*, c.name as category_name, c.color as category_color, c.icon as category_icon 
                FROM questions q JOIN categories c ON q.category_id = c.id 
                WHERE {$whereClause} 
                ORDER BY 
                    CASE 
                        WHEN q.review_status = 'excluded' OR q.is_active = 0 THEN 1 
                        ELSE 0 
                    END ASC, 
                    q.id ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $questions = $stmt->fetchAll();

        $stats = [
            'total' => (int) $pdo->query('SELECT COUNT(*) FROM questions')->fetchColumn(),
            'unrated' => (int) $pdo->query("SELECT COUNT(*) FROM questions WHERE review_status = 'unrated' OR (review_status NOT IN ('included', 'excluded', 'skipped') AND is_active = 1)")->fetchColumn(),
            'included' => (int) $pdo->query("SELECT COUNT(*) FROM questions WHERE review_status = 'included'")->fetchColumn(),
            'excluded' => (int) $pdo->query("SELECT COUNT(*) FROM questions WHERE review_status = 'excluded' OR is_active = 0")->fetchColumn(),
            'skipped' => (int) $pdo->query("SELECT COUNT(*) FROM questions WHERE review_status = 'skipped'")->fetchColumn(),
            'favorites' => (int) $pdo->query('SELECT COUNT(*) FROM questions WHERE is_favorite = 1')->fetchColumn(),
        ];
        $stats['to_review'] = $stats['unrated'];
        $stats['pending'] = $stats['unrated'];

        return $this->render('moderation/review.html.twig', [
            'questions' => $questions,
            'categories' => $categories,
            'selected_cat' => $selectedCat,
            'difficulty' => $diff,
            'status' => $status,
            'search' => $search,
            'fav_only' => $favOnly,
            'start_id' => $startId,
            'stats' => $stats,
        ]);
    }

    #[Route('/question/save', name: 'app_moderation_save', methods: ['POST'])]
    public function saveQuestion(Request $request): RedirectResponse
    {
        $pdo = $this->gameDatabase->getPdo();
        $id = (int) $request->request->get('id', 0);
        $catId = (int) $request->request->get('category_id');
        $title = trim((string) $request->request->get('title', ''));
        $prompt = trim((string) $request->request->get('prompt_text', 'Say what you see!'));
        $hint = trim((string) $request->request->get('hint', ''));
        $answer = trim((string) $request->request->get('answer', ''));
        $alts = trim((string) $request->request->get('alternate_answers', ''));
        $diff = (string) $request->request->get('difficulty', 'Medium');
        $hilarious = (float) $request->request->get('hilarious_score', 9.0);
        $mediaPath = trim((string) $request->request->get('media_path', ''));
        $isFav = (int) $request->request->get('is_favorite', 0);
        $isActive = $request->request->has('is_active') ? (int) $request->request->get('is_active') : 1;

        /** @var UploadedFile|null $uploadedFile */
        $uploadedFile = $request->files->get('media_file');
        if ($uploadedFile && $uploadedFile->isValid()) {
            $uploadsDir = $this->getParameter('kernel.project_dir') . '/public/uploads/custom';
            if (!is_dir($uploadsDir)) {
                mkdir($uploadsDir, 0777, true);
            }
            $newFilename = uniqid('puz_', true) . '.' . $uploadedFile->guessExtension();
            $uploadedFile->move($uploadsDir, $newFilename);
            $mediaPath = '/uploads/custom/' . $newFilename;
        }

        if ($id > 0) {
            $reviewStatus = $isActive === 1 ? 'included' : 'excluded';
            $stmt = $pdo->prepare(<<<'SQL'
UPDATE questions SET 
    category_id = ?, title = ?, prompt_text = ?, hint = ?, answer = ?, 
    alternate_answers = ?, difficulty = ?, hilarious_score = ?, media_path = ?, is_favorite = ?, is_active = ?, review_status = ?
WHERE id = ?
SQL);
            $stmt->execute([$catId, $title, $prompt, $hint, $answer, $alts, $diff, $hilarious, $mediaPath, $isFav, $isActive, $reviewStatus, $id]);
            $this->addFlash('success', 'Puzzle updated successfully!');
        } else {
            $reviewStatus = $isActive === 1 ? 'included' : 'pending';
            $stmt = $pdo->prepare(<<<'SQL'
INSERT INTO questions (
    category_id, title, prompt_text, hint, answer, alternate_answers, 
    difficulty, hilarious_score, media_type, media_path, source, is_favorite, is_active, review_status
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'image', ?, 'Custom Added', ?, ?, ?)
SQL);
            $stmt->execute([$catId, $title, $prompt, $hint, $answer, $alts, $diff, $hilarious, $mediaPath, $isFav, $isActive, $reviewStatus]);
            $this->addFlash('success', 'New puzzle added to question bank!');
        }

        return $this->redirectToRoute('app_moderation_index');
    }

    #[Route('/question/{id}/delete', name: 'app_moderation_delete', methods: ['POST'])]
    public function deleteQuestion(int $id): RedirectResponse
    {
        $pdo = $this->gameDatabase->getPdo();
        $pdo->prepare('UPDATE game_shows SET current_question_id = NULL WHERE current_question_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM duplicate_dismissals WHERE question_id_a = ? OR question_id_b = ?')->execute([$id, $id]);
        $pdo->prepare('DELETE FROM show_used_questions WHERE question_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM questions WHERE id = ?')->execute([$id]);
        $this->addFlash('success', 'Puzzle removed from question bank.');

        return $this->redirectToRoute('app_moderation_index');
    }

    #[Route('/questions/delete-batch', name: 'app_moderation_delete_batch', methods: ['POST'])]
    public function deleteBatch(Request $request): Response
    {
        $pdo = $this->gameDatabase->getPdo();
        $contentType = (string) $request->headers->get('Content-Type');
        if (str_contains($contentType, 'application/json')) {
            $data = json_decode((string) $request->getContent(), true) ?: [];
            $ids = (array) ($data['ids'] ?? []);
        } else {
            $ids = (array) $request->request->all('ids');
        }

        $ids = array_values(array_filter(array_map('intval', $ids), fn($id) => $id > 0));

        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE game_shows SET current_question_id = NULL WHERE current_question_id IN ({$placeholders})")->execute($ids);
            $pdo->prepare("DELETE FROM duplicate_dismissals WHERE question_id_a IN ({$placeholders}) OR question_id_b IN ({$placeholders})")->execute(array_merge($ids, $ids));
            $pdo->prepare("DELETE FROM show_used_questions WHERE question_id IN ({$placeholders})")->execute($ids);

            $stmt = $pdo->prepare("DELETE FROM questions WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
            $deletedCount = $stmt->rowCount();

            if (str_contains($contentType, 'application/json')) {
                return $this->json([
                    'success' => true,
                    'deleted_count' => $deletedCount,
                    'message' => "Successfully deleted {$deletedCount} puzzles.",
                ]);
            }

            $this->addFlash('success', "Successfully deleted {$deletedCount} puzzles from the question bank.");
        } else {
            if (str_contains($contentType, 'application/json')) {
                return $this->json([
                    'success' => false,
                    'message' => 'No questions selected for deletion.',
                ], Response::HTTP_BAD_REQUEST);
            }

            $this->addFlash('warning', 'No questions were selected.');
        }

        return $this->redirectToRoute('app_moderation_index');
    }

    #[Route('/questions/batch-action', name: 'app_moderation_batch_action', methods: ['POST'])]
    public function batchAction(Request $request): Response
    {
        $pdo = $this->gameDatabase->getPdo();
        $contentType = (string) $request->headers->get('Content-Type');
        if (str_contains($contentType, 'application/json')) {
            $data = json_decode((string) $request->getContent(), true) ?: [];
            $ids = (array) ($data['ids'] ?? []);
            $action = (string) ($data['action'] ?? 'delete');
        } else {
            $ids = (array) $request->request->all('ids');
            $action = (string) $request->request->get('action', 'delete');
        }

        $ids = array_values(array_filter(array_map('intval', $ids), fn($id) => $id > 0));
        if (empty($ids)) {
            if (str_contains($contentType, 'application/json')) {
                return $this->json(['success' => false, 'message' => 'No questions selected.'], Response::HTTP_BAD_REQUEST);
            }
            $this->addFlash('warning', 'No questions were selected.');
            return $this->redirectToRoute('app_moderation_index');
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        if ($action === 'delete') {
            $pdo->prepare("UPDATE game_shows SET current_question_id = NULL WHERE current_question_id IN ({$placeholders})")->execute($ids);
            $pdo->prepare("DELETE FROM duplicate_dismissals WHERE question_id_a IN ({$placeholders}) OR question_id_b IN ({$placeholders})")->execute(array_merge($ids, $ids));
            $pdo->prepare("DELETE FROM show_used_questions WHERE question_id IN ({$placeholders})")->execute($ids);

            $stmt = $pdo->prepare("DELETE FROM questions WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
            $count = $stmt->rowCount();
            $msg = "Permanently deleted {$count} puzzles.";
        } elseif ($action === 'include') {
            $stmt = $pdo->prepare("UPDATE questions SET review_status = 'included', is_active = 1 WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
            $count = $stmt->rowCount();
            $msg = "Marked {$count} puzzles as Included (Active).";
        } elseif ($action === 'exclude') {
            $stmt = $pdo->prepare("UPDATE questions SET review_status = 'excluded', is_active = 0 WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
            $count = $stmt->rowCount();
            $msg = "Marked {$count} puzzles as Excluded (Inactive).";
        } else {
            if (str_contains($contentType, 'application/json')) {
                return $this->json(['success' => false, 'message' => 'Invalid action.'], Response::HTTP_BAD_REQUEST);
            }
            $this->addFlash('error', 'Invalid batch action.');
            return $this->redirectToRoute('app_moderation_index');
        }

        if (str_contains($contentType, 'application/json')) {
            return $this->json([
                'success' => true,
                'affected_count' => $count,
                'message' => $msg,
            ]);
        }

        $this->addFlash('success', $msg);
        return $this->redirectToRoute('app_moderation_index');
    }
            $count = $stmt->rowCount();
            $msg = "Marked {$count} puzzles as Included (Active).";
        } elseif ($action === 'exclude') {
            $stmt = $pdo->prepare("UPDATE questions SET review_status = 'excluded', is_active = 0 WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
            $count = $stmt->rowCount();
            $msg = "Marked {$count} puzzles as Excluded.";
        } else {
            return $this->json(['error' => 'Invalid action'], Response::HTTP_BAD_REQUEST);
        }

        if (str_contains($contentType, 'application/json')) {
            return $this->json(['success' => true, 'count' => $count, 'message' => $msg]);
        }

        $this->addFlash('success', $msg);
        return $this->redirectToRoute('app_moderation_index');
    }

    #[Route('/question/{id}/toggle-fav', name: 'app_moderation_toggle_fav', methods: ['POST'])]
    public function toggleFavorite(int $id): JsonResponse
    {
        $pdo = $this->gameDatabase->getPdo();
        $pdo->prepare('UPDATE questions SET is_favorite = CASE WHEN is_favorite = 1 THEN 0 ELSE 1 END WHERE id = ?')->execute([$id]);
        $fav = (int) $pdo->query("SELECT is_favorite FROM questions WHERE id = {$id}")->fetchColumn();

        return $this->json(['success' => true, 'is_favorite' => $fav]);
    }

    #[Route('/question/{id}/vote', name: 'app_moderation_vote', methods: ['POST'])]
    #[Route('/question/{id}/review-vote', name: 'app_moderation_review_vote', methods: ['POST'])]
    public function voteQuestion(int $id, Request $request): JsonResponse
    {
        $pdo = $this->gameDatabase->getPdo();
        $payload = json_decode((string) $request->getContent(), true) ?: $request->request->all();

        $raw = $payload['status'] ?? $payload['is_active'] ?? $payload['vote'] ?? null;
        if ($raw === 'toggle') {
            $curr = (int) $pdo->query("SELECT is_active FROM questions WHERE id = {$id}")->fetchColumn();
            $isActive = $curr === 1 ? 0 : 1;
            $reviewStatus = $isActive === 1 ? 'included' : 'excluded';
        } elseif ($raw === 'down' || $raw === 0 || $raw === '0' || $raw === false || $raw === 'exclude' || $raw === 'excluded' || $raw === 'reject') {
            $isActive = 0;
            $reviewStatus = 'excluded';
        } elseif ($raw === 'skip' || $raw === 'skipped') {
            $isActive = 1;
            $reviewStatus = 'skipped';
        } elseif ($raw === 'unrated' || $raw === 'pending') {
            $isActive = 1;
            $reviewStatus = 'unrated';
        } else {
            $isActive = 1;
            $reviewStatus = 'included';
        }

        // Store the decision persistently in database (survives refreshes and restarts)
        $stmt = $pdo->prepare('UPDATE questions SET is_active = ?, review_status = ? WHERE id = ?');
        $stmt->execute([$isActive, $reviewStatus, $id]);

        $counts = [
            'unrated' => (int) $pdo->query("SELECT COUNT(*) FROM questions WHERE review_status = 'unrated' OR (review_status NOT IN ('included', 'excluded', 'skipped') AND is_active = 1)")->fetchColumn(),
            'included' => (int) $pdo->query("SELECT COUNT(*) FROM questions WHERE review_status = 'included'")->fetchColumn(),
            'excluded' => (int) $pdo->query("SELECT COUNT(*) FROM questions WHERE review_status = 'excluded' OR is_active = 0")->fetchColumn(),
            'skipped' => (int) $pdo->query("SELECT COUNT(*) FROM questions WHERE review_status = 'skipped'")->fetchColumn(),
            'favorites' => (int) $pdo->query('SELECT COUNT(*) FROM questions WHERE is_favorite = 1')->fetchColumn(),
            'total' => (int) $pdo->query('SELECT COUNT(*) FROM questions')->fetchColumn(),
        ];
        $counts['to_review'] = $counts['unrated'];
        $counts['pending'] = $counts['unrated'];

        return $this->json([
            'success' => true,
            'id' => $id,
            'is_active' => $isActive,
            'review_status' => $reviewStatus,
            'counts' => $counts,
        ]);
    }

    #[Route('/question/{id}/ai-prompt', name: 'app_moderation_ai_prompt', methods: ['GET'])]
    public function getAiPrompt(int $id): JsonResponse
    {
        $pdo = $this->gameDatabase->getPdo();
        $stmt = $pdo->prepare('SELECT q.*, c.name as category_name FROM questions q JOIN categories c ON q.category_id = c.id WHERE q.id = ?');
        $stmt->execute([$id]);
        $question = $stmt->fetch();

        if (!$question) {
            return $this->json(['error' => 'Question not found'], Response::HTTP_NOT_FOUND);
        }

        $defaultPrompt = $this->aiImageGenerator->buildDiffusionPrompt($question, 'rebus');
        $concept = $this->aiImageGenerator->buildDefaultPrompt($question, 'rebus');

        return $this->json([
            'success' => true,
            'question_id' => $id,
            'title' => $question['title'],
            'answer' => $question['answer'],
            'hint' => $question['hint'],
            'explanation' => $question['explanation'],
            'default_prompt' => $defaultPrompt,
            'concept' => $concept,
            'existing_image_url' => $question['media_path'],
            'original_image_url' => $question['original_media_path'] ?: $question['media_path'],
        ]);
    }

    #[Route('/question/{id}/ai-generate', name: 'app_moderation_ai_generate', methods: ['POST'])]
    public function generateAiImage(int $id, Request $request): JsonResponse
    {
        $pdo = $this->gameDatabase->getPdo();
        $stmt = $pdo->prepare('SELECT q.*, c.name as category_name FROM questions q JOIN categories c ON q.category_id = c.id WHERE q.id = ?');
        $stmt->execute([$id]);
        $question = $stmt->fetch();

        if (!$question) {
            return $this->json(['error' => 'Question not found'], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode($request->getContent(), true) ?? $request->request->all();
        $style = trim((string) ($payload['style'] ?? 'rebus'));
        $prompt = trim((string) ($payload['prompt'] ?? ''));
        if ($prompt === '') {
            $prompt = $this->aiImageGenerator->buildDefaultPrompt($question, $style);
        }

        $seed = isset($payload['seed']) ? (int) $payload['seed'] : null;

        $width = 1280;
        $height = 800;
        if (!empty($payload['resolution']) && is_string($payload['resolution'])) {
            $parts = explode('x', $payload['resolution']);
            if (count($parts) === 2 && (int) $parts[0] > 0 && (int) $parts[1] > 0) {
                $width = (int) $parts[0];
                $height = (int) $parts[1];
            }
        } elseif (isset($payload['width']) && isset($payload['height'])) {
            $width = (int) $payload['width'];
            $height = (int) $payload['height'];
        }

        $genResult = $this->aiImageGenerator->generate($id, $question, $prompt, $style, $seed, $width, $height);

        return $this->json([
            'success' => $genResult['success'],
            'question_id' => $id,
            'image_url' => $genResult['url'],
            'prompt' => $genResult['prompt'],
            'seed' => $genResult['seed'],
            'format' => $genResult['format'],
            'dimensions' => $genResult['dimensions'] ?? "{$width}x{$height}",
            'existing_image_url' => $question['media_path'],
            'original_image_url' => $question['original_media_path'] ?: $question['media_path'],
        ]);
    }

    #[Route('/question/{id}/upscale-image', name: 'app_moderation_upscale_image', methods: ['POST'])]
    public function upscaleImage(int $id, Request $request): JsonResponse
    {
        $pdo = $this->gameDatabase->getPdo();
        $stmt = $pdo->prepare('SELECT * FROM questions WHERE id = ?');
        $stmt->execute([$id]);
        $question = $stmt->fetch();

        if (!$question) {
            return $this->json(['error' => 'Question not found'], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode((string) $request->getContent(), true) ?: $request->request->all();
        $targetWidth = isset($payload['target_width']) ? (int) $payload['target_width'] : 1280;

        $res = $this->aiImageGenerator->upscaleImage($question['media_path'], $targetWidth);
        if (!$res['success']) {
            return $this->json($res, Response::HTTP_BAD_REQUEST);
        }

        return $this->json([
            'success' => true,
            'question_id' => $id,
            'image_url' => $res['url'],
            'existing_image_url' => $question['media_path'],
            'original_image_url' => $question['original_media_path'] ?: $question['media_path'],
            'original_width' => $res['original_width'],
            'original_height' => $res['original_height'],
            'width' => $res['width'],
            'height' => $res['height'],
            'dimensions' => $res['dimensions'],
            'scale_factor' => $res['scale_factor'],
            'mode' => 'scale',
        ]);
    }

    #[Route('/question/{id}/ai-apply', name: 'app_moderation_ai_apply', methods: ['POST'])]
    public function applyAiImage(int $id, Request $request): JsonResponse
    {
        $pdo = $this->gameDatabase->getPdo();
        $stmt = $pdo->prepare('SELECT * FROM questions WHERE id = ?');
        $stmt->execute([$id]);
        $question = $stmt->fetch();

        if (!$question) {
            return $this->json(['error' => 'Question not found'], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode($request->getContent(), true) ?? $request->request->all();
        $action = $payload['action'] ?? 'apply_ai';
        $imageUrl = trim((string) ($payload['image_url'] ?? ''));

        if ($action === 'restore_original') {
            $targetUrl = $question['original_media_path'] ?: $question['media_path'];
            $upd = $pdo->prepare('UPDATE questions SET media_path = ? WHERE id = ?');
            $upd->execute([$targetUrl, $id]);
            return $this->json([
                'success' => true,
                'question_id' => $id,
                'media_path' => $targetUrl,
                'message' => 'Restored to original image.',
            ]);
        }

        if ($imageUrl === '') {
            return $this->json(['error' => 'No image URL provided'], Response::HTTP_BAD_REQUEST);
        }

        // Keep original if not already recorded
        if (empty($question['original_media_path'])) {
            $origStmt = $pdo->prepare('UPDATE questions SET original_media_path = ? WHERE id = ?');
            $origStmt->execute([$question['media_path'], $id]);
        }

        $upd = $pdo->prepare('UPDATE questions SET media_path = ? WHERE id = ?');
        $upd->execute([$imageUrl, $id]);

        return $this->json([
            'success' => true,
            'question_id' => $id,
            'media_path' => $imageUrl,
            'original_media_path' => $question['original_media_path'] ?: $question['media_path'],
            'message' => 'AI Generated image selected and saved!',
        ]);
    }

    #[Route('/question/{id}/update-answer', name: 'app_moderation_update_answer', methods: ['POST'])]
    public function updateAnswer(int $id, Request $request): JsonResponse
    {
        $pdo = $this->gameDatabase->getPdo();
        $payload = json_decode($request->getContent(), true) ?? $request->request->all();

        $answer = trim((string) ($payload['answer'] ?? ''));
        if ($answer === '') {
            return $this->json(['error' => 'Answer cannot be empty'], Response::HTTP_BAD_REQUEST);
        }

        $alts = trim((string) ($payload['alternate_answers'] ?? ''));
        $hint = trim((string) ($payload['hint'] ?? ''));
        $explanation = trim((string) ($payload['explanation'] ?? ''));

        $stmt = $pdo->prepare('UPDATE questions SET answer = ?, alternate_answers = ?, hint = ?, explanation = ? WHERE id = ?');
        $stmt->execute([$answer, $alts, $hint, $explanation, $id]);

        $qStmt = $pdo->prepare('SELECT q.*, c.name as category_name FROM questions q JOIN categories c ON q.category_id = c.id WHERE q.id = ?');
        $qStmt->execute([$id]);
        $question = $qStmt->fetch();

        $newPrompt = $this->aiImageGenerator->buildDefaultPrompt($question);

        return $this->json([
            'success' => true,
            'id' => $id,
            'answer' => $answer,
            'alternate_answers' => $alts,
            'hint' => $hint,
            'explanation' => $explanation,
            'new_prompt' => $newPrompt,
            'message' => 'Answer and clues updated successfully!'
        ]);
    }

    #[Route('/categories/save', name: 'app_moderation_cat_save', methods: ['POST'])]
    public function saveCategory(Request $request): RedirectResponse
    {
        $pdo = $this->gameDatabase->getPdo();
        $id = (int) $request->request->get('id', 0);
        $name = trim((string) $request->request->get('name'));
        $slug = trim((string) $request->request->get('slug'));
        $desc = trim((string) $request->request->get('description', ''));
        $color = trim((string) $request->request->get('color', '#f59e0b'));
        $icon = trim((string) $request->request->get('icon', '🧩'));

        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE categories SET name = ?, slug = ?, description = ?, color = ?, icon = ? WHERE id = ?');
            $stmt->execute([$name, $slug, $desc, $color, $icon, $id]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO categories (name, slug, description, color, icon) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$name, $slug, $desc, $color, $icon]);
        }

        $this->addFlash('success', 'Category saved.');
        return $this->redirectToRoute('app_moderation_index');
    }

    #[Route('/export', name: 'app_moderation_export', methods: ['GET'])]
    public function exportJson(): Response
    {
        $data = $this->gameDatabase->exportDatabaseToJson();
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new Response($json, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="rebus_master_question_bank_' . date('Y-m-d') . '.json"',
        ]);
    }

    #[Route('/duplicates', name: 'app_moderation_duplicates', methods: ['GET'])]
    public function duplicates(): Response
    {
        $duplicateGroups = $this->duplicateDetector->findDuplicates();

        return $this->render('moderation/duplicates.html.twig', [
            'duplicateGroups' => $duplicateGroups,
            'totalGroups' => count($duplicateGroups),
        ]);
    }

    #[Route('/duplicates/dismiss', name: 'app_moderation_duplicates_dismiss', methods: ['POST'])]
    public function dismissDuplicates(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? $request->request->all();
        $ids = $data['question_ids'] ?? [];

        if (is_array($ids) && count($ids) >= 2) {
            $this->duplicateDetector->dismissGroup($ids);
            return new JsonResponse([
                'success' => true,
                'message' => 'Marked as not duplicate',
                'remainingCount' => $this->duplicateDetector->getDuplicateGroupCount(),
            ]);
        }

        return new JsonResponse(['success' => false, 'error' => 'Invalid question IDs'], 400);
    }

    #[Route('/duplicates/keep', name: 'app_moderation_duplicates_keep', methods: ['POST'])]
    public function keepDuplicate(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? $request->request->all();
        $keepId = (int) ($data['keep_id'] ?? 0);
        $otherIds = $data['other_ids'] ?? [];
        $action = (string) ($data['action'] ?? 'delete');

        if ($keepId > 0 && is_array($otherIds) && !empty($otherIds)) {
            $this->duplicateDetector->resolveKeep($keepId, $otherIds, $action);
            return new JsonResponse([
                'success' => true,
                'message' => $action === 'exclude' ? 'Duplicate excluded' : 'Duplicate deleted',
                'remainingCount' => $this->duplicateDetector->getDuplicateGroupCount(),
            ]);
        }

        return new JsonResponse(['success' => false, 'error' => 'Invalid parameters'], 400);
    }
}

