<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * convoro-projects — many-to-many pivot between projects and categories.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_category')) {
            Schema::create('project_category', function (Blueprint $t) {
                $t->unsignedInteger('project_id');
                $t->unsignedInteger('category_id');
                $t->primary(['project_id', 'category_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_category');
    }
};
