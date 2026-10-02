<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Threads insights dashboard plan gate. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('packages') && ! Schema::hasColumn('packages', 'threads_insights')) {
            Schema::table('packages', function (Blueprint $table) {
                $table->boolean('threads_insights')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('packages') && Schema::hasColumn('packages', 'threads_insights')) {
            Schema::table('packages', function (Blueprint $table) {
                $table->dropColumn('threads_insights');
            });
        }
    }
};
