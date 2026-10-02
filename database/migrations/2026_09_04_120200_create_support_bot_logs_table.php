<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client Support Bot — Q&A log. One row per user question so the admin can see
 * what people ask, which tier answered (docs/ai/web), and where the docs are
 * thin (unmatched questions). `source_id` is a soft pointer (no FK) so purging
 * a source never deletes the historical record of what it once answered.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('support_bot_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->text('question');
            $t->longText('answer')->nullable();
            $t->boolean('matched')->default(false);
            $t->float('score')->nullable();
            $t->string('engine', 8)->nullable();     // docs|ai|web|none
            $t->unsignedBigInteger('source_id')->nullable(); // top source (soft pointer)
            $t->string('session_id', 64)->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamps();

            $t->index('created_at');
            $t->index(['matched', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_bot_logs');
    }
};
