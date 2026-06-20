<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * convoro-projects — per-user project likes (one row per like).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_likes')) {
            Schema::create('project_likes', function (Blueprint $t) {
                $t->unsignedInteger('project_id');
                $t->unsignedInteger('user_id');
                $t->timestamp('created_at')->nullable();
                $t->primary(['project_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_likes');
    }
};
