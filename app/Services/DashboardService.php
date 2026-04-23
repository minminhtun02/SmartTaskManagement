<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Task;
use App\Models\TaskProgressLog;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(int $userId): array
    {
        $total = $this->countByCondition($userId, '1=1');
        $completed = $this->countByCondition($userId, 'status_id = 3');
        $pending = $this->countByCondition($userId, 'status_id != 3');
        $highPriority = $this->countByCondition($userId, 'priority_id = 1');
        $overdue = Task::overdueCount($userId);
        $dueToday = Task::dueTodayCount($userId);
        $recentProgress = TaskProgressLog::recentForUser($userId);

        return [
            'total_tasks' => $total,
            'completed_tasks' => $completed,
            'pending_tasks' => $pending,
            'high_priority_tasks' => $highPriority,
            'overdue_tasks' => $overdue,
            'tasks_due_today' => $dueToday,
            'recent_progress_updates' => $recentProgress,
        ];
    }

    private function countByCondition(int $userId, string $condition): int
    {
        return (int) DB::table('tasks')
            ->where('user_id', $userId)
            ->whereRaw($condition)
            ->count();
    }
}
