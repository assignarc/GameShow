<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\GameDatabase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class RulesController extends AbstractController
{
    public function __construct(
        private readonly GameDatabase $gameDatabase,
    ) {
    }

    #[Route('/rules', name: 'app_common_rules', methods: ['GET'])]
    public function commonRules(): Response
    {
        $rules = $this->gameDatabase->getCommonRules();

        return $this->render('rules/index.html.twig', [
            'rules' => $rules,
        ]);
    }

    #[Route('/rules/save', name: 'app_common_rules_save', methods: ['POST'])]
    public function saveCommonRule(Request $request): RedirectResponse
    {
        $id = (int) $request->request->get('id', 0);
        $title = trim((string) $request->request->get('title', ''));
        $description = trim((string) $request->request->get('description', ''));
        $icon = trim((string) $request->request->get('icon', '📜')) ?: '📜';

        if ($title === '') {
            $this->addFlash('error', 'Rule title cannot be empty.');
            return $this->redirectToRoute('app_common_rules');
        }

        $this->gameDatabase->saveCommonRule($id > 0 ? $id : null, $title, $description, $icon);
        $this->addFlash('success', 'Master common rule saved successfully.');

        return $this->redirectToRoute('app_common_rules');
    }

    #[Route('/rules/{id}/delete', name: 'app_common_rules_delete', methods: ['POST'])]
    public function deleteCommonRule(int $id): RedirectResponse
    {
        $this->gameDatabase->deleteCommonRule($id);
        $this->addFlash('success', 'Common rule removed.');

        return $this->redirectToRoute('app_common_rules');
    }

    #[Route('/shows/{id}/rules/save', name: 'app_show_rules_save', methods: ['POST'])]
    public function saveShowRule(int $id, Request $request): RedirectResponse
    {
        $pdo = $this->gameDatabase->getPdo();
        $showExists = (int) $pdo->query("SELECT COUNT(*) FROM game_shows WHERE id = {$id}")->fetchColumn();
        if ($showExists === 0) {
            throw $this->createNotFoundException('Game show not found');
        }

        $ruleId = (int) $request->request->get('rule_id', 0);
        $title = trim((string) $request->request->get('title', ''));
        $description = trim((string) $request->request->get('description', ''));
        $icon = trim((string) $request->request->get('icon', '📜')) ?: '📜';

        if ($title === '') {
            $this->addFlash('error', 'Rule title cannot be empty.');
            return $this->redirectToRoute('app_show_manage', ['id' => $id, '_fragment' => 'tab-rules']);
        }

        $rules = $this->gameDatabase->getShowRules($id);

        if ($ruleId > 0) {
            // Edit existing rule
            $found = false;
            foreach ($rules as &$r) {
                if ((int) $r['id'] === $ruleId) {
                    $r['title'] = $title;
                    $r['description'] = $description;
                    $r['icon'] = $icon;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $rules[] = [
                    'id' => count($rules) + 1,
                    'title' => $title,
                    'description' => $description,
                    'icon' => $icon,
                ];
            }
            $this->addFlash('success', 'Show rule updated.');
        } else {
            // Add new rule
            $rules[] = [
                'id' => count($rules) + 1,
                'title' => $title,
                'description' => $description,
                'icon' => $icon,
            ];
            $this->addFlash('success', 'New rule added to game show.');
        }

        $this->gameDatabase->saveShowRules($id, $rules);

        return $this->redirectToRoute('app_show_manage', ['id' => $id, '_fragment' => 'tab-rules']);
    }

    #[Route('/shows/{id}/rules/delete', name: 'app_show_rules_delete', methods: ['POST'])]
    public function deleteShowRule(int $id, Request $request): RedirectResponse
    {
        $ruleId = (int) $request->request->get('rule_id', 0);
        $rules = $this->gameDatabase->getShowRules($id);

        $filtered = array_values(array_filter($rules, fn($r) => (int) $r['id'] !== $ruleId));
        $this->gameDatabase->saveShowRules($id, $filtered);
        $this->addFlash('success', 'Rule deleted from game show.');

        return $this->redirectToRoute('app_show_manage', ['id' => $id, '_fragment' => 'tab-rules']);
    }

    #[Route('/shows/{id}/rules/move', name: 'app_show_rules_move', methods: ['POST'])]
    public function moveShowRule(int $id, Request $request): RedirectResponse
    {
        $ruleId = (int) $request->request->get('rule_id', 0);
        $direction = (string) $request->request->get('direction', 'up');

        $rules = $this->gameDatabase->getShowRules($id);
        $targetIndex = null;
        foreach ($rules as $idx => $r) {
            if ((int) $r['id'] === $ruleId) {
                $targetIndex = $idx;
                break;
            }
        }

        if ($targetIndex !== null) {
            $swapIndex = $direction === 'up' ? $targetIndex - 1 : $targetIndex + 1;
            if ($swapIndex >= 0 && $swapIndex < count($rules)) {
                $temp = $rules[$targetIndex];
                $rules[$targetIndex] = $rules[$swapIndex];
                $rules[$swapIndex] = $temp;
                $this->gameDatabase->saveShowRules($id, $rules);
                $this->addFlash('success', 'Rules reordered.');
            }
        }

        return $this->redirectToRoute('app_show_manage', ['id' => $id, '_fragment' => 'tab-rules']);
    }

    #[Route('/shows/{id}/rules/reset-common', name: 'app_show_rules_reset_common', methods: ['POST'])]
    public function resetShowRulesToCommon(int $id): RedirectResponse
    {
        $this->gameDatabase->resetShowRulesToCommon($id);
        $this->addFlash('success', 'Game show rules re-inherited from the Master Common Rules template.');

        return $this->redirectToRoute('app_show_manage', ['id' => $id, '_fragment' => 'tab-rules']);
    }
}

