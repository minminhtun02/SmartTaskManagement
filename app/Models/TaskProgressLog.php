<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class TaskProgressLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'task_id',
        'old_status',
        'new_status',
        'changed_at',
        'note',
    ];

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function recentForUser(int $userId, int $limit = 5): array
    {
        $rows = DB::table('task_progress_logs as l')
            ->select(['l.*', 't.title as task_title'])
            ->join('tasks as t', 't.id', '=', 'l.task_id')
            ->where('t.user_id', $userId)
            ->orderByDesc('l.changed_at')
            ->limit($limit)
            ->get();

        return $rows->map(function (\stdClass $row): array {
            $a = (array) $row;
            if (isset($a['changed_at']) && $a['changed_at'] instanceof \DateTimeInterface) {
                $a['changed_at'] = $a['changed_at']->format('Y-m-d H:i:s');
            }

            return $a;
        })->all();
    }
}
