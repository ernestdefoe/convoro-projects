<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * convoro-projects — admin-defined custom parameters (typed) that projects
 * carry, e.g. Genre, Release date, Price.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_fields')) {
            Schema::create('project_fields', function (Blueprint $t) {
                $t->id();
                $t->string('name', 100);
                $t->string('key', 100)->unique();
                $t->string('type', 20)->default('text'); // text|textarea|number|date|url|select|boolean
                $t->text('options')->nullable();          // JSON array (select)
                $t->string('icon', 100)->nullable();
                $t->string('prefix', 30)->nullable();
                $t->string('suffix', 30)->nullable();
                $t->boolean('is_required')->default(false);
                $t->boolean('on_card')->default(true);
                $t->unsignedInteger('position')->default(0);
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_fields');
    }
};
