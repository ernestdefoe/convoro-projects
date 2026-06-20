<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * convoro-projects — a filled-in link on a project, optionally tied to a
 * button slot.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_links')) {
            Schema::create('project_links', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('project_id')->index();
                $t->unsignedInteger('button_id')->nullable();
                $t->string('url', 600);
                $t->string('label', 100)->nullable();
                $t->unsignedInteger('position')->default(0);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_links');
    }
};
