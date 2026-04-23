<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class Task extends Model
{
    protected $fillable = [
        'user_id',
        'category_id',
        'priority_id',
        'status_id',
        'title',
        'description',
        'due_date',
        'estimated_minutes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_date' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<Priority, $this>
     */
    public function priority(): BelongsTo
    {
        return $this->belongsTo(Priority::class);
    }

    /**
     * @return BelongsTo<Status, $this>
     */
    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class);
    }

    /**
     * Flat array shape matching the legacy PDO API rows.
     *
     * @return array<string, mixed>
     */
    public function toLegacyRow(): array
    {
        $this->loadMissing(['category', 'priority', 'status']);

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'category_id' => $this->category_id,
            'priority_id' => $this->priority_id,
            'status_id' => $this->status_id,
            'title' => $this->title,
            'description' => $this->description,
            'due_date' => $this->due_date?->format('Y-m-d H:i:s'),
            'estimated_minutes' => $this->estimated_minutes,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
            'category_name' => $this->category->name,
            'priority_name' => $this->priority->name,
            'priority_level' => $this->priority->level,
            'color_code' => $this->priority->color_code,
            'status_name' => $this->status->name,
            'progress_percent' => $this->status->progress_percent,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public static function listForUser(int $userId, array $filters = []): array
    {
        $query = DB::table('tasks as t')
            ->select([
                't.id',
                't.user_id',
                't.category_id',
                't.priority_id',
                't.status_id',
                't.title',
                't.description',
                't.due_date',
                't.estimated_minutes',
                't.created_at',
                't.updated_at',
                'c.name as category_name',
                'p.name as priority_name',
                'p.level as priority_level',
                'p.color_code',
                's.name as status_name',
                's.progress_percent',
            ])
            ->join('categories as c', 'c.id', '=', 't.category_id')
            ->join('priorities as p', 'p.id', '=', 't.priority_id')
            ->join('statuses as s', 's.id', '=', 't.status_id')
            ->where('t.user_id', $userId);

        if (! empty($filters['priority_id'])) {
            $query->where('t.priority_id', (int) $filters['priority_id']);
        }
        if (! empty($filters['category_id'])) {
            $query->where('t.category_id', (int) $filters['category_id']);
        }
        if (! empty($filters['status_id'])) {
            $query->where('t.status_id', (int) $filters['status_id']);
        }
        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(function (QueryBuilder $q) use ($term): void {
                $q->where('t.title', 'like', $term)
                    ->orWhere('t.description', 'like', $term);
            });
        }

        $sort = $filters['sort'] ?? 'created_at';
        $sortMap = [
            'priority' => 'p.level DESC',
            'due_date' => 't.due_date ASC',
            'category' => 'c.name ASC',
            'status' => 's.name ASC',
            'created_at' => 't.created_at DESC',
        ];
        $orderSql = $sortMap[$sort] ?? $sortMap['created_at'];
        $query->orderByRaw($orderSql);

        return array_map(
            static fn (\stdClass $row) => static::normalizeListRow($row),
            $query->get()->all()
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function findForUser(int $taskId, int $userId): ?array
    {
        $row = DB::table('tasks as t')
            ->select([
                't.id',
                't.user_id',
                't.category_id',
                't.priority_id',
                't.status_id',
                't.title',
                't.description',
                't.due_date',
                't.estimated_minutes',
                't.created_at',
                't.updated_at',
                'c.name as category_name',
                'p.name as priority_name',
                'p.level as priority_level',
                'p.color_code',
                's.name as status_name',
                's.progress_percent',
            ])
            ->join('categories as c', 'c.id', '=', 't.category_id')
            ->join('priorities as p', 'p.id', '=', 't.priority_id')
            ->join('statuses as s', 's.id', '=', 't.status_id')
            ->where('t.id', $taskId)
            ->where('t.user_id', $userId)
            ->first();

        return $row ? static::normalizeListRow($row) : null;
    }

    public static function overdueCount(int $userId): int
    {
        return (int) static::query()
            ->where('user_id', $userId)
            ->whereNotNull('due_date')
            ->where('due_date', '<', now())
            ->where('status_id', '!=', 3)
            ->count();
    }

    public static function dueTodayCount(int $userId): int
    {
        return (int) static::query()
            ->where('user_id', $userId)
            ->whereDate('due_date', now()->toDateString())
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeListRow(\stdClass $row): array
    {
        $a = (array) $row;
        foreach (['due_date', 'created_at', 'updated_at'] as $k) {
            if (isset($a[$k]) && $a[$k] instanceof \DateTimeInterface) {
                $a[$k] = $a[$k]->format('Y-m-d H:i:s');
            }
        }

        return $a;
    }
}
