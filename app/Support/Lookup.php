<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Category;
use App\Models\Priority;
use App\Models\Status;

final class Lookup
{
    public static function existsById(string $table, int $id): bool
    {
        return match ($table) {
            'categories' => Category::query()->whereKey($id)->exists(),
            'priorities' => Priority::query()->whereKey($id)->exists(),
            'statuses' => Status::query()->whereKey($id)->exists(),
            default => false,
        };
    }
}
