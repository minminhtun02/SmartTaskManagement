<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimeEstimation extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'task_id',
        'estimated_minutes',
        'estimation_basis',
        'created_at',
    ];

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
