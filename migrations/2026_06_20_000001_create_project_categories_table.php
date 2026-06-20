<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * convoro-projects — admin-defined categories (icon + accent colour). The
 * "primary" category of a project drives its card badge and the author's
 * username badge.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_categories')) {
            Schema::create('project_categories', function (Blueprint $t) {
                $t->id();
                $t->string('name', 100);
                $t->string('slug', 100)->unique();
                $t->string('icon', 100)->nullable();
                $t->string('color', 20)->nullable();
                $t->string('description', 255)->nullable();
                $t->unsignedInteger('position')->default(0);
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_categories');
    }
};
