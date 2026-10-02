<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Threads (Meta) — connected accounts. One row per Threads account linked to a
 * WaDesk workspace (a workspace can connect several). The long-lived Threads
 * token (~60 days) is stored ENCRYPTED via the model cast. Mirrors how the
 * Instagram channel stores per-workspace accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('threads_accounts')) {
            return;
        }

        Schema::create('threads_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();   // WaDesk workspace
            $table->unsignedBigInteger('user_id')->nullable();     // who connected it

            // Threads Graph identifiers (graph.threads.net).
            $table->string('threads_user_id', 64)->index();
            $table->string('username', 191)->nullable();
            $table->string('name', 191)->nullable();
            $table->string('profile_pic_url', 1024)->nullable();

            // Long-lived token (~60d) — encrypted at rest via the model cast.
            $table->text('access_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('scopes')->nullable();

            $table->string('status', 24)->default('connected');    // connected | expired | error
            $table->string('last_error', 500)->nullable();
            $table->json('meta_json')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'threads_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('threads_accounts');
    }
};
