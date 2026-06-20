<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * convoro-projects — admin-defined link/button slots, each optionally
 * constrained to an allow-list of host suffixes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_buttons')) {
            Schema::create('project_buttons', function (Blueprint $t) {
                $t->id();
                $t->string('label', 100);
                $t->string('key', 100)->unique();
                $t->string('icon', 100)->nullable();
                $t->text('allowed_domains')->nullable();        // JSON array of host suffixes
                $t->boolean('allow_custom_label')->default(true);
                $t->boolean('is_required')->default(false);
                $t->boolean('is_primary')->default(false);
                $t->unsignedInteger('position')->default(0);
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_buttons');
    }
};
