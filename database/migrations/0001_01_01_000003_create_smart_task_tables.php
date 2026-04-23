<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('description', 255)->nullable();
        });

        Schema::create('priorities', function (Blueprint $table) {
            $table->id();
            $table->string('name', 30)->unique();
            $table->integer('level');
            $table->string('color_code', 20);
        });

        Schema::create('statuses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40)->unique();
            $table->integer('progress_percent')->default(0);
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories');
            $table->foreignId('priority_id')->constrained('priorities');
            $table->foreignId('status_id')->constrained('statuses');
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->dateTime('due_date')->nullable();
            $table->integer('estimated_minutes')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->string('recommendation_type', 50);
            $table->text('recommendation_text');
            $table->dateTime('created_at');
        });

        Schema::create('task_progress_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->string('old_status', 40);
            $table->string('new_status', 40);
            $table->dateTime('changed_at');
            $table->string('note', 255)->nullable();
        });

        Schema::create('time_estimations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->integer('estimated_minutes');
            $table->string('estimation_basis', 255);
            $table->dateTime('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_estimations');
        Schema::dropIfExists('task_progress_logs');
        Schema::dropIfExists('ai_recommendations');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('statuses');
        Schema::dropIfExists('priorities');
        Schema::dropIfExists('categories');
    }
};
