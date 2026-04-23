<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AiRecommendation;
use App\Models\Task;
use App\Models\TimeEstimation;
use App\Services\AIService;
use App\Support\LegacyValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AIController extends Controller
{
    public function __construct(
        private readonly AIService $aiService
    ) {}

    public function priority(Request $request)
    {
        $userId = (int) Auth::id();
        $taskId = (int) ($request->input('task_id', 0));
        $task = Task::findForUser($taskId, $userId);
        if (! $task) {
            return ApiResponse::json(false, 'Task not found.', [], 404);
        }

        $result = $this->aiService->suggestPriority($task);
        $this->storeRecommendation($taskId, 'priority', json_encode($result));

        return ApiResponse::json(true, 'Priority suggestion generated.', $result);
    }

    public function category(Request $request)
    {
        $userId = (int) Auth::id();
        $taskId = (int) ($request->input('task_id', 0));
        $task = Task::findForUser($taskId, $userId);
        if (! $task) {
            return ApiResponse::json(false, 'Task not found.', [], 404);
        }

        $result = $this->aiService->suggestCategory($task);
        $this->storeRecommendation($taskId, 'category', json_encode($result));

        return ApiResponse::json(true, 'Category suggestion generated.', $result);
    }

    public function timeEstimate(Request $request)
    {
        $userId = (int) Auth::id();
        $taskId = (int) ($request->input('task_id', 0));
        $task = Task::findForUser($taskId, $userId);
        if (! $task) {
            return ApiResponse::json(false, 'Task not found.', [], 404);
        }

        $result = $this->aiService->estimateTime($task);
        TimeEstimation::query()->create([
            'task_id' => $taskId,
            'estimated_minutes' => $result['estimated_minutes'],
            'estimation_basis' => $result['estimation_basis'],
            'created_at' => now(),
        ]);
        $this->storeRecommendation($taskId, 'time_estimate', json_encode($result));

        return ApiResponse::json(true, 'Time estimate generated.', $result);
    }

    public function focusTask()
    {
        $userId = (int) Auth::id();
        $tasks = Task::listForUser($userId, ['sort' => 'due_date']);
        $focus = $this->aiService->recommendFocusTask($tasks);

        if (! $focus) {
            return ApiResponse::json(true, 'No open tasks available for focus recommendation.', []);
        }

        $this->storeRecommendation((int) $focus['id'], 'focus_task', 'Recommended focus task selected.');

        return ApiResponse::json(true, 'Focus task recommendation generated.', $focus);
    }

    public function productivityTip()
    {
        $userId = (int) Auth::id();
        $tasks = Task::listForUser($userId, ['sort' => 'created_at']);
        $tip = $this->aiService->generateProductivitySuggestion($tasks);

        return ApiResponse::json(true, 'Productivity suggestion generated.', ['tip' => $tip]);
    }

    public function suggestionsDraft(Request $request)
    {
        $userId = (int) Auth::id();
        $body = $request->all();
        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '') {
            return ApiResponse::json(false, 'Title is required for draft suggestions.', [], 422);
        }

        $dueRaw = $body['due_date'] ?? null;
        $dueDate = null;
        if (is_string($dueRaw) && trim($dueRaw) !== '') {
            $dueDate = str_replace('T', ' ', trim($dueRaw));
            if (strlen($dueDate) === 16) {
                $dueDate .= ':00';
            }
            if (! LegacyValidator::dueDateValid($dueDate)) {
                return ApiResponse::json(false, 'Due date must be today or a future date when provided.', [], 422);
            }
        }

        $priorityId = (int) ($body['priority_id'] ?? 2);
        if (! in_array($priorityId, [1, 2, 3], true)) {
            $priorityId = 2;
        }
        $priorityLabel = [1 => 'High', 2 => 'Medium', 3 => 'Low'][$priorityId];

        $draft = [
            'title' => $title,
            'description' => trim((string) ($body['description'] ?? '')),
            'due_date' => $dueDate,
            'priority_name' => $priorityLabel,
        ];

        $tasks = Task::listForUser($userId, ['sort' => 'due_date']);

        $category = $this->aiService->suggestCategory($draft);
        $priority = $this->aiService->suggestPriority($draft);
        $draftForTime = array_merge($draft, ['priority_name' => $priority['priority']]);
        $time = $this->aiService->estimateTime($draftForTime);
        $productivityTip = $this->aiService->draftProductivityTip($draftForTime, $tasks);

        return ApiResponse::json(true, 'Draft suggestions generated.', [
            'priority' => $priority,
            'category' => $category,
            'time_estimate' => $time,
            'productivity_tip' => $productivityTip,
        ]);
    }

    public function chat(Request $request)
    {
        $userId = (int) Auth::id();
        $message = trim((string) ($request->input('message', '')));
        if ($message === '') {
            return ApiResponse::json(false, 'Message is required.', [], 422);
        }
        if (strlen($message) > 2000) {
            return ApiResponse::json(false, 'Message is too long (max 2000 characters).', [], 422);
        }

        $tasks = Task::listForUser($userId, ['sort' => 'created_at']);
        $reply = $this->aiService->chat($message, $tasks);

        return ApiResponse::json(true, 'Reply generated.', ['reply' => $reply]);
    }

    private function storeRecommendation(int $taskId, string $type, string $text): void
    {
        AiRecommendation::query()->create([
            'task_id' => $taskId,
            'recommendation_type' => $type,
            'recommendation_text' => $text,
            'created_at' => now(),
        ]);
    }
}
