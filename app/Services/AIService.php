<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;

class AIService
{
    private string $apiKey;

    private string $model;

    private string $endpoint;

    private int $timeoutSeconds;

    public function __construct()
    {
        $this->apiKey = (string) config('ai.api_key', '');
        $this->model = (string) config('ai.model', 'gpt-4o-mini');
        $this->endpoint = (string) config('ai.endpoint', 'https://api.openai.com/v1/chat/completions');
        $this->timeoutSeconds = (int) config('ai.timeout_seconds', 20);
    }

    /**
     * @param  array<string, mixed>  $task
     * @return array<string, mixed>
     */
    public function suggestPriority(array $task): array
    {
        $external = $this->askJson(
            'Return JSON only with keys: priority, priority_id, reason. priority must be one of High/Medium/Low and priority_id must match High=1 Medium=2 Low=3.',
            [
                'task' => $this->compactTask($task),
                'goal' => 'Suggest the most appropriate priority for the task.',
            ]
        );
        if ($external && isset($external['priority'], $external['priority_id'])) {
            return [
                'priority' => in_array($external['priority'], ['High', 'Medium', 'Low'], true) ? $external['priority'] : 'Medium',
                'priority_id' => in_array((int) $external['priority_id'], [1, 2, 3], true) ? (int) $external['priority_id'] : 2,
                'reason' => (string) ($external['reason'] ?? 'AI recommendation generated.'),
            ];
        }

        return $this->suggestPriorityRuleBased($task);
    }

    /**
     * @param  array<string, mixed>  $task
     * @return array<string, mixed>
     */
    public function suggestCategory(array $task): array
    {
        $external = $this->askJson(
            'Return JSON only with keys: category, category_id. category_id mapping: 1=Academic, 2=Work, 3=Personal, 4=Health, 5=Finance.',
            [
                'task' => $this->compactTask($task),
                'goal' => 'Suggest the best category.',
            ]
        );
        if ($external && isset($external['category'], $external['category_id'])) {
            $id = (int) $external['category_id'];
            if (! in_array($id, [1, 2, 3, 4, 5], true)) {
                $id = 3;
            }

            return [
                'category' => (string) $external['category'],
                'category_id' => $id,
            ];
        }

        return $this->suggestCategoryRuleBased($task);
    }

    /**
     * @param  array<string, mixed>  $task
     * @return array<string, mixed>
     */
    public function estimateTime(array $task): array
    {
        $external = $this->askJson(
            'Return JSON only with keys: estimated_minutes, estimation_basis. estimated_minutes must be integer between 15 and 480.',
            [
                'task' => $this->compactTask($task),
                'goal' => 'Estimate realistic time required to complete this task.',
            ]
        );
        if ($external && isset($external['estimated_minutes'])) {
            $minutes = (int) $external['estimated_minutes'];
            $minutes = max(15, min(480, $minutes));

            return [
                'estimated_minutes' => $minutes,
                'estimation_basis' => (string) ($external['estimation_basis'] ?? 'AI estimate based on task complexity and urgency.'),
            ];
        }

        return $this->estimateTimeRuleBased($task);
    }

    /**
     * @param  list<array<string, mixed>>  $userTasks
     * @return array<string, mixed>|null
     */
    public function recommendFocusTask(array $userTasks): ?array
    {
        $openTasks = array_values(array_filter($userTasks, static fn ($task) => (int) $task['status_id'] !== 3));
        if ($openTasks === []) {
            return null;
        }

        $external = $this->askJson(
            'Return JSON only with key focus_task_id (integer). Choose one task id from provided tasks that should be focused now.',
            [
                'tasks' => array_map([$this, 'compactTask'], $openTasks),
                'goal' => 'Recommend one focus task.',
            ]
        );

        if ($external && isset($external['focus_task_id'])) {
            $id = (int) $external['focus_task_id'];
            foreach ($openTasks as $task) {
                if ((int) $task['id'] === $id) {
                    return $task;
                }
            }
        }

        return $this->recommendFocusTaskRuleBased($userTasks);
    }

    /**
     * @param  list<array<string, mixed>>  $userTasks
     */
    public function generateProductivitySuggestion(array $userTasks): string
    {
        $external = $this->askJson(
            'Return JSON only with key tip. Keep tip concise (max 30 words).',
            [
                'tasks' => array_map([$this, 'compactTask'], $userTasks),
                'goal' => 'Generate practical productivity advice.',
            ]
        );
        if ($external && isset($external['tip'])) {
            return (string) $external['tip'];
        }

        return $this->generateProductivitySuggestionRuleBased($userTasks);
    }

    /**
     * @param  array<string, mixed>  $draftTask
     * @param  list<array<string, mixed>>  $userTasks
     */
    public function draftProductivityTip(array $draftTask, array $userTasks): string
    {
        $external = $this->askJson(
            'Return JSON only with key tip. Max 30 words. Consider possible_new_task and existing tasks together.',
            [
                'possible_new_task' => $this->compactTask($draftTask),
                'existing_tasks' => array_map([$this, 'compactTask'], $userTasks),
                'goal' => 'One actionable productivity tip related to adding this task.',
            ]
        );
        if ($external && isset($external['tip'])) {
            return (string) $external['tip'];
        }

        $base = $this->generateProductivitySuggestionRuleBased($userTasks);
        $days = $this->daysUntil(isset($draftTask['due_date']) ? (string) $draftTask['due_date'] : null);
        if ($days !== null && $days <= 1) {
            return $base.' This draft looks time-sensitive; slot it before lower-priority work.';
        }
        if ($days !== null && $days <= 3) {
            return $base.' Schedule focused time for this draft within the next few days.';
        }

        return $base;
    }

    /**
     * @param  list<array<string, mixed>>  $userTasks
     */
    public function chat(string $userMessage, array $userTasks): string
    {
        $message = trim($userMessage);
        if ($message === '') {
            return 'Please type a question.';
        }

        if ($this->canUseExternalAI()) {
            $system = 'You are TaskAI, a concise productivity coach for a task app. '
                .'Answer in clear plain text (no JSON). Max 150 words. '
                .'Use only the provided task list as facts; do not invent tasks. '
                .'If the question is unrelated, answer briefly and politely.';
            $slice = array_slice($userTasks, 0, 40);
            $ctx = json_encode(['tasks' => array_map([$this, 'compactTask'], $slice)], JSON_UNESCAPED_SLASHES);
            $user = "Question:\n{$message}\n\nTask data (JSON):\n{$ctx}";
            $reply = $this->chatCompletion($system, $user);
            if (is_string($reply) && $reply !== '') {
                return $reply;
            }
        }

        return $this->chatFallback($message, $userTasks);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function askJson(string $instruction, array $payload): ?array
    {
        if (! $this->canUseExternalAI()) {
            return null;
        }

        $systemPrompt = 'You are an AI assistant for a task management platform. Provide safe, practical recommendations. '.$instruction;
        $userPrompt = json_encode($payload, JSON_UNESCAPED_SLASHES);

        $requestBody = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt ?: '{}'],
            ],
            'temperature' => 0.2,
        ];

        $response = Http::timeout($this->timeoutSeconds)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer '.$this->apiKey,
            ])
            ->post($this->endpoint, $requestBody);

        if (! $response->successful()) {
            return null;
        }

        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || trim($content) === '') {
            return null;
        }

        $content = trim($content);
        $content = preg_replace('/^```json\s*/i', '', $content) ?? $content;
        $content = preg_replace('/^```\s*/', '', $content) ?? $content;
        $content = preg_replace('/\s*```$/', '', $content) ?? $content;

        $json = json_decode($content, true);

        return is_array($json) ? $json : null;
    }

    private function chatCompletion(string $systemPrompt, string $userPrompt): ?string
    {
        if (! $this->canUseExternalAI()) {
            return null;
        }

        $requestBody = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'temperature' => 0.4,
            'max_tokens' => 400,
        ];

        $response = Http::timeout($this->timeoutSeconds)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer '.$this->apiKey,
            ])
            ->post($this->endpoint, $requestBody);

        if (! $response->successful()) {
            return null;
        }

        $content = $response->json('choices.0.message.content');
        if (! is_string($content) || trim($content) === '') {
            return null;
        }

        return trim($content);
    }

    /**
     * @param  list<array<string, mixed>>  $userTasks
     */
    private function chatFallback(string $message, array $userTasks): string
    {
        $m = strtolower($message);
        $open = array_values(array_filter($userTasks, static fn ($t) => (int) $t['status_id'] !== 3));

        if (str_contains($m, 'how many') && str_contains($m, 'task')) {
            return 'You currently have '.count($userTasks).' task(s), with '.count($open).' not completed.';
        }

        if (str_contains($m, 'focus') || str_contains($m, 'first') || str_contains($m, 'start')) {
            if ($open === []) {
                return 'You have no open tasks. Create a new task or reopen a completed one to get a focus suggestion.';
            }
            usort($open, function ($a, $b) {
                if ((int) $a['priority_level'] !== (int) $b['priority_level']) {
                    return (int) $b['priority_level'] <=> (int) $a['priority_level'];
                }

                return strtotime((string) ($a['due_date'] ?? '2999-12-31')) <=> strtotime((string) ($b['due_date'] ?? '2999-12-31'));
            });
            $t = $open[0];

            return 'Without the chat API, a simple rule is to start with: "'.$t['title'].'" (higher priority / sooner due date). '
                .'Configure OPENAI_API_KEY in .env for richer answers.';
        }

        if (str_contains($m, 'overdue')) {
            $n = 0;
            foreach ($userTasks as $t) {
                if (! empty($t['due_date']) && strtotime((string) $t['due_date']) < time() && (int) $t['status_id'] !== 3) {
                    $n++;
                }
            }

            return $n > 0
                ? "You appear to have {$n} overdue incomplete task(s). Tackle the smallest one first for momentum."
                : 'No overdue incomplete tasks detected from your list.';
        }

        return 'I can help with focus, priorities, and workload. Try: "What should I focus on?" '
            .'For full natural-language answers, add your OpenAI API key to the .env file.';
    }

    private function canUseExternalAI(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * @param  array<string, mixed>  $task
     * @return array<string, mixed>
     */
    private function compactTask(array $task): array
    {
        return [
            'id' => $task['id'] ?? null,
            'title' => $task['title'] ?? '',
            'description' => $task['description'] ?? '',
            'due_date' => $task['due_date'] ?? null,
            'priority' => $task['priority_name'] ?? $task['priority'] ?? null,
            'priority_level' => $task['priority_level'] ?? null,
            'category' => $task['category_name'] ?? null,
            'status' => $task['status_name'] ?? null,
            'status_id' => $task['status_id'] ?? null,
            'estimated_minutes' => $task['estimated_minutes'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $task
     * @return array<string, mixed>
     */
    private function suggestPriorityRuleBased(array $task): array
    {
        $dueDate = $task['due_date'] ?? null;
        $title = strtolower((string) ($task['title'] ?? ''));
        $description = strtolower((string) ($task['description'] ?? ''));
        $daysLeft = $this->daysUntil($dueDate !== null ? (string) $dueDate : null);

        $score = 0;
        if ($daysLeft !== null && $daysLeft <= 1) {
            $score += 3;
        } elseif ($daysLeft !== null && $daysLeft <= 3) {
            $score += 2;
        } elseif ($daysLeft !== null && $daysLeft <= 7) {
            $score += 1;
        }

        if (str_contains($title.' '.$description, 'exam') || str_contains($title.' '.$description, 'deadline')) {
            $score += 2;
        }

        if ($score >= 4) {
            return ['priority' => 'High', 'priority_id' => 1, 'reason' => 'Due soon and high impact keywords detected.'];
        }
        if ($score >= 2) {
            return ['priority' => 'Medium', 'priority_id' => 2, 'reason' => 'Moderate urgency based on due date and context.'];
        }

        return ['priority' => 'Low', 'priority_id' => 3, 'reason' => 'No immediate urgency detected.'];
    }

    /**
     * @param  array<string, mixed>  $task
     * @return array<string, mixed>
     */
    private function suggestCategoryRuleBased(array $task): array
    {
        $text = strtolower(((string) ($task['title'] ?? '')).' '.((string) ($task['description'] ?? '')));

        if (preg_match('/(thesis|assignment|study|research|exam|lecture)/', $text)) {
            return ['category' => 'Academic', 'category_id' => 1];
        }
        if (preg_match('/(client|meeting|api|review|project|deploy)/', $text)) {
            return ['category' => 'Work', 'category_id' => 2];
        }
        if (preg_match('/(gym|workout|run|health|exercise)/', $text)) {
            return ['category' => 'Health', 'category_id' => 4];
        }
        if (preg_match('/(tax|budget|invoice|finance|payment)/', $text)) {
            return ['category' => 'Finance', 'category_id' => 5];
        }

        return ['category' => 'Personal', 'category_id' => 3];
    }

    /**
     * @param  array<string, mixed>  $task
     * @return array<string, mixed>
     */
    private function estimateTimeRuleBased(array $task): array
    {
        $textLength = strlen(((string) ($task['title'] ?? '')).' '.((string) ($task['description'] ?? '')));
        $priority = strtolower((string) ($task['priority_name'] ?? $task['priority'] ?? 'medium'));
        $base = 60;

        if ($textLength > 120) {
            $base += 45;
        }
        if ($textLength > 250) {
            $base += 30;
        }
        if ($priority === 'high') {
            $base += 45;
        } elseif ($priority === 'medium') {
            $base += 20;
        }

        return [
            'estimated_minutes' => $base,
            'estimation_basis' => 'Rule-based estimation using task complexity text length and priority.',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $userTasks
     * @return array<string, mixed>|null
     */
    private function recommendFocusTaskRuleBased(array $userTasks): ?array
    {
        $openTasks = array_values(array_filter($userTasks, static fn ($task) => (int) $task['status_id'] !== 3));
        if ($openTasks === []) {
            return null;
        }

        usort($openTasks, function ($a, $b) {
            if ((int) $a['priority_level'] !== (int) $b['priority_level']) {
                return (int) $b['priority_level'] <=> (int) $a['priority_level'];
            }

            return strtotime((string) ($a['due_date'] ?? '2999-12-31')) <=> strtotime((string) ($b['due_date'] ?? '2999-12-31'));
        });

        return $openTasks[0];
    }

    /**
     * @param  list<array<string, mixed>>  $userTasks
     */
    private function generateProductivitySuggestionRuleBased(array $userTasks): string
    {
        $highOpen = 0;
        $overdue = 0;
        foreach ($userTasks as $task) {
            if ((int) $task['status_id'] !== 3 && (int) $task['priority_level'] >= 3) {
                $highOpen++;
            }
            if (! empty($task['due_date']) && strtotime((string) $task['due_date']) < time() && (int) $task['status_id'] !== 3) {
                $overdue++;
            }
        }

        if ($overdue > 0) {
            return 'You have overdue tasks. Complete one overdue task first to reduce pressure.';
        }
        if ($highOpen >= 3) {
            return 'You have multiple high-priority tasks. Use 90-minute focus blocks and avoid context switching.';
        }
        if (count($userTasks) === 0) {
            return 'Start by adding your first task with a clear due date and category.';
        }

        return 'Plan tomorrow today: pick one focus task, one quick win, and one maintenance task.';
    }

    private function daysUntil(?string $dueDate): ?int
    {
        if (empty($dueDate)) {
            return null;
        }
        $due = strtotime($dueDate);
        if ($due === false) {
            return null;
        }
        $today = strtotime(date('Y-m-d'));

        return (int) floor(($due - $today) / 86400);
    }
}
