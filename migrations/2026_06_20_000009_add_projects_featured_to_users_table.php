<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * convoro-projects — denormalised "featured projects" snapshot on the user
 * (a JSON array of the user's featured project ids), so a username badge /
 * profile highlight can render without per-request project queries.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'projects_featured')) {
            Schema::table('users', function (Blueprint $t) {
                $t->text('projects_featured')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'projects_featured')) {
            Schema::table('users', function (Blueprint $t) {
                $t->dropColumn('projects_featured');
            });
        }
    }
};
