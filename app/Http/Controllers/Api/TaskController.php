<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Task;
use App\Models\TaskProgressLog;
use App\Support\LegacyValidator;
use App\Support\Lookup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

class TaskController extends Controller
{
    public function index(Request $request)
    {
        $userId = (int) Auth::id();
        $filters = [
            'search' => $request->query('search'),
            'priority_id' => $request->query('priority_id'),
            'category_id' => $request->query('category_id'),
            'status_id' => $request->query('status_id'),
            'sort' => $request->query('sort', 'created_at'),
        ];
        $tasks = Task::listForUser($userId, $filters);

        return ApiResponse::json(true, 'Tasks fetched successfully.', $tasks);
    }

    public function show(int $id)
    {
        $userId = (int) Auth::id();
        $task = Task::findForUser($id, $userId);
        if (! $task) {
            return ApiResponse::json(false, 'Task not found.', [], 404);
        }

        return ApiResponse::json(true, 'Task fetched successfully.', $task);
    }

    public function store(Request $request)
    {
        $userId = (int) Auth::id();
        $validation = $this->validateTaskPayload($request->all());
        if (! $validation['ok']) {
            return ApiResponse::json(false, $validation['message'], [], 422);
        }

        try {
            $task = Task::query()->create(array_merge($validation['data'], ['user_id' => $userId]));
            $row = Task::findForUser((int) $task->id, $userId);

            return ApiResponse::json(true, 'Task created successfully.', $row, 201);
        } catch (Throwable) {
            return ApiResponse::json(false, 'Failed to create task.', [], 500);
        }
    }

    public function update(Request $request, int $id)
    {
        $userId = (int) Auth::id();
        $existing = Task::findForUser($id, $userId);
        if (! $existing) {
            return ApiResponse::json(false, 'Task not found.', [], 404);
        }

        $validation = $this->validateTaskPayload($request->all());
        if (! $validation['ok']) {
            return ApiResponse::json(false, $validation['message'], [], 422);
        }

        try {
            $updated = Task::query()
                ->whereKey($id)
                ->where('user_id', $userId)
                ->update([
                    'category_id' => $validation['data']['category_id'],
                    'priority_id' => $validation['data']['priority_id'],
                    'status_id' => $validation['data']['status_id'],
                    'title' => $validation['data']['title'],
                    'description' => $validation['data']['description'],
                    'due_date' => $validation['data']['due_date'],
                    'estimated_minutes' => $validation['data']['estimated_minutes'],
                    'updated_at' => now(),
                ]);

            if ((int) $existing['status_id'] !== (int) $validation['data']['status_id']) {
                TaskProgressLog::query()->create([
                    'task_id' => $id,
                    'old_status' => (string) $existing['status_name'],
                    'new_status' => $this->statusNameById((int) $validation['data']['status_id']),
                    'changed_at' => now(),
                    'note' => 'Status updated from task update endpoint.',
                ]);
            }

            $message = $updated ? 'Task updated successfully.' : 'No changes detected.';

            return ApiResponse::json(true, $message, Task::findForUser($id, $userId));
        } catch (Throwable) {
            return ApiResponse::json(false, 'Failed to update task.', [], 500);
        }
    }

    public function destroy(int $id)
    {
        $userId = (int) Auth::id();
        $existing = Task::findForUser($id, $userId);
        if (! $existing) {
            return ApiResponse::json(false, 'Task not found.', [], 404);
        }

        try {
            Task::query()->whereKey($id)->where('user_id', $userId)->delete();

            return ApiResponse::json(true, 'Task deleted successfully.', []);
        } catch (Throwable) {
            return ApiResponse::json(false, 'Failed to delete task.', [], 500);
        }
    }

    public function updateStatus(Request $request, int $id)
    {
        $userId = (int) Auth::id();
        $payload = $request->all();
        $newStatusId = (int) ($payload['status_id'] ?? 0);
        $note = trim((string) ($payload['note'] ?? ''));

        if ($newStatusId <= 0 || ! Lookup::existsById('statuses', $newStatusId)) {
            return ApiResponse::json(false, 'Valid status_id is required.', [], 422);
        }

        $task = Task::findForUser($id, $userId);
        if (! $task) {
            return ApiResponse::json(false, 'Task not found.', [], 404);
        }

        try {
            Task::query()
                ->whereKey($id)
                ->where('user_id', $userId)
                ->update([
                    'status_id' => $newStatusId,
                    'updated_at' => now(),
                ]);

            TaskProgressLog::query()->create([
                'task_id' => $id,
                'old_status' => (string) $task['status_name'],
                'new_status' => $this->statusNameById($newStatusId),
                'changed_at' => now(),
                'note' => $note !== '' ? $note : 'Status changed via PATCH endpoint.',
            ]);

            return ApiResponse::json(true, 'Task status updated successfully.', Task::findForUser($id, $userId));
        } catch (Throwable) {
            return ApiResponse::json(false, 'Failed to update status.', [], 500);
        }
    }

    public function search(Request $request)
    {
        $userId = (int) Auth::id();
        $search = trim((string) $request->query('q', ''));
        if ($search === '') {
            return ApiResponse::json(false, 'Search query "q" is required.', [], 422);
        }

        $tasks = Task::listForUser($userId, [
            'search' => $search,
            'sort' => $request->query('sort', 'created_at'),
        ]);

        return ApiResponse::json(true, 'Search results fetched.', $tasks);
    }

    public function filter(Request $request)
    {
        return $this->index($request);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{ok: true, data: array<string, mixed>}|array{ok: false, message: string}
     */
    private function validateTaskPayload(array $payload): array
    {
        $title = trim((string) ($payload['title'] ?? ''));
        if (! LegacyValidator::required($title)) {
            return ['ok' => false, 'message' => 'Task title is required.'];
        }

        $dueDate = $payload['due_date'] ?? null;
        if (! LegacyValidator::dueDateValid(is_string($dueDate) ? $dueDate : null)) {
            return ['ok' => false, 'message' => 'Due date must be today or future date.'];
        }

        $categoryId = (int) ($payload['category_id'] ?? 0);
        $priorityId = (int) ($payload['priority_id'] ?? 0);
        $statusId = (int) ($payload['status_id'] ?? 0);

        if (! Lookup::existsById('categories', $categoryId)) {
            return ['ok' => false, 'message' => 'Invalid category_id.'];
        }
        if (! Lookup::existsById('priorities', $priorityId)) {
            return ['ok' => false, 'message' => 'Invalid priority_id.'];
        }
        if (! Lookup::existsById('statuses', $statusId)) {
            return ['ok' => false, 'message' => 'Invalid status_id.'];
        }

        return [
            'ok' => true,
            'data' => [
                'title' => $title,
                'description' => trim((string) ($payload['description'] ?? '')),
                'due_date' => $dueDate ?: null,
                'category_id' => $categoryId,
                'priority_id' => $priorityId,
                'status_id' => $statusId,
                'estimated_minutes' => isset($payload['estimated_minutes']) ? (int) $payload['estimated_minutes'] : null,
            ],
        ];
    }

    private function statusNameById(int $statusId): string
    {
        $map = [
            1 => 'To Do',
            2 => 'In Progress',
            3 => 'Completed',
        ];

        return $map[$statusId] ?? 'Unknown';
    }
}
