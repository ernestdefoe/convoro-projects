<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * convoro-projects — the project showcase entries themselves.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('projects')) {
            Schema::create('projects', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('user_id')->nullable()->index();
                $t->unsignedInteger('primary_category_id')->nullable();
                $t->string('title', 255);
                $t->string('slug', 255)->unique();
                $t->string('excerpt', 600)->nullable();
                $t->text('content')->nullable();
                $t->string('image_path', 600)->nullable();
                $t->string('status', 20)->default('pending')->index(); // pending | published | rejected
                $t->string('rejection_reason', 500)->nullable();
                $t->unsignedInteger('likes_count')->default(0);
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
