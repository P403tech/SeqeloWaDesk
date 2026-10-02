<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workspace-defined custom fields for DEALS.
 *
 * Contacts have had `contact_custom_fields` since the inbox was built; deals
 * never did, so anything a workspace tracks beyond title/value/owner had to go
 * in the free-text notes box where nothing could filter or report on it.
 *
 * Deliberately identical in shape to contact_custom_fields so the definition
 * manager, the type coercion and the validation are one implementation rather
 * than two that drift. VALUES live in the existing (and until now unused)
 * `deals.meta` JSON column under a `custom` key — no new column, and no
 * migration of existing rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('deal_custom_fields')) {
            return;   // updater re-runs migrations; never fail on a partial upgrade
        }

        Schema::create('deal_custom_fields', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id')->index();
            $t->string('key', 64);
            $t->string('label', 128);
            $t->string('type', 16)->default('text');
            // text | number | date | select | bool | url | email
            $t->json('options')->nullable();
            // for select type
            $t->boolean('required')->default(false);
            $t->boolean('show_in_panel')->default(true);
            $t->unsignedInteger('sort')->default(0);
            $t->timestamps();

            // One definition per key per workspace — the key IS the storage
            // address inside deals.meta->custom, so a duplicate would make two
            // definitions fight over the same value.
            $t->unique(['workspace_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_custom_fields');
    }
};
