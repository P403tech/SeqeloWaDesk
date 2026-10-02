<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Threads reply auto-responder plan gate. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('packages') && ! Schema::hasColumn('packages', 'threads_replies')) {
            Schema::table('packages', function (Blueprint $table) {
                $table->boolean('threads_replies')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('packages') && Schema::hasColumn('packages', 'threads_replies')) {
            Schema::table('packages', function (Blueprint $table) {
                $table->dropColumn('threads_replies');
            });
        }
    }
};
