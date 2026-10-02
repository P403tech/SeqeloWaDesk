<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client Support Bot — knowledge sources. PLATFORM-scoped (no workspace_id):
 * the platform admin uploads help docs once and every tenant's users search
 * them. Each row is one source — an uploaded file (md/html/pdf/docx/txt/csv),
 * a fetched URL, or a raw text snippet. The extracted plain text is kept in
 * `content` so a re-index can rebuild chunks without re-fetching/re-parsing.
 * The searchable units live in support_bot_chunks (rebuilt from these rows).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('support_bot_sources', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 16);                    // file|url|text
            $t->string('label', 200);
            $t->string('url', 1024)->nullable();       // for kind=url
            $t->string('source_path', 512)->nullable();// stored upload path for kind=file
            $t->longText('content')->nullable();       // extracted plain text (reindex source)

            // pending → ready (chunked) | error (extraction/fetch failed)
            $t->string('status', 16)->default('pending');
            $t->unsignedInteger('tokens_estimate')->nullable();
            $t->text('error')->nullable();

            $t->unsignedBigInteger('created_by')->nullable(); // admin user id
            $t->timestamps();
            $t->softDeletes();

            $t->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_bot_sources');
    }
};
