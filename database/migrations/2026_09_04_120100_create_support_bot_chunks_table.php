<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client Support Bot — the SEARCH CORPUS. Each source is split into
 * heading-scoped, size-capped chunks so retrieval can score and return the
 * most relevant passage instead of a whole document. A MySQL FULLTEXT index
 * on (heading, content) powers the docs-first tier and gates AI/web
 * escalation; on non-MySQL drivers retrieval falls back to LIKE (no index).
 * Rebuilt idempotently from support_bot_sources on (re)index.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('support_bot_chunks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('source_id')->constrained('support_bot_sources')->cascadeOnDelete();
            $t->string('heading', 512)->nullable();
            $t->longText('content');
            $t->unsignedInteger('position')->default(0);
            $t->timestamps();

            $t->index('source_id');
        });

        // FULLTEXT is MySQL-only; guard so the migration still runs on sqlite
        // (tests) where retrieval uses the LIKE fallback path instead.
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::table('support_bot_chunks', function (Blueprint $t) {
                $t->fullText(['heading', 'content'], 'ftx_support_bot_chunks');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('support_bot_chunks');
    }
};
