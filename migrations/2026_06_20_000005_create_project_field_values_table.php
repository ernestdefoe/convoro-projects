<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * convoro-projects — one custom-field value per (project, field).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_field_values')) {
            Schema::create('project_field_values', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('project_id');
                $t->unsignedInteger('field_id');
                $t->text('value')->nullable();
                $t->unique(['project_id', 'field_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_field_values');
    }
};
