<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LookupSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [1, 'Academic', 'Study, assignments, exam, thesis related tasks'],
            [2, 'Work', 'Office or freelance work tasks'],
            [3, 'Personal', 'Personal growth and life management tasks'],
            [4, 'Health', 'Fitness and wellbeing tasks'],
            [5, 'Finance', 'Financial planning and document tasks'],
        ];
        foreach ($categories as [$id, $name, $description]) {
            DB::table('categories')->updateOrInsert(
                ['id' => $id],
                ['name' => $name, 'description' => $description]
            );
        }

        $priorities = [
            [1, 'High', 3, '#DC2626'],
            [2, 'Medium', 2, '#F59E0B'],
            [3, 'Low', 1, '#16A34A'],
        ];
        foreach ($priorities as [$id, $name, $level, $color]) {
            DB::table('priorities')->updateOrInsert(
                ['id' => $id],
                ['name' => $name, 'level' => $level, 'color_code' => $color]
            );
        }

        $statuses = [
            [1, 'To Do', 0],
            [2, 'In Progress', 50],
            [3, 'Completed', 100],
        ];
        foreach ($statuses as [$id, $name, $progress]) {
            DB::table('statuses')->updateOrInsert(
                ['id' => $id],
                ['name' => $name, 'progress_percent' => $progress]
            );
        }
    }
}
