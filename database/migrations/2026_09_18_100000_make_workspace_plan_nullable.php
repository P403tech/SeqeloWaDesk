<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * workspaces.plan must allow NULL.
 *
 * The app already treats "no plan yet" as a legitimate state:
 *   - WorkspacesController validates it as `nullable|exists:packages,id`
 *   - the same controller COUNTS workspaces with `whereNull('plan')`
 *     (the "free / unassigned" bucket on the admin list)
 *   - the installer falls back to `optional(Package::first())->id`, which is
 *     null on an install whose packages have not been seeded
 *
 * Only the column disagreed, so creating a workspace without picking a plan
 * died with "Integrity constraint violation: 1048 Column 'plan' cannot be
 * null" — a 500 in the admin UI for an input the form says is optional.
 *
 * Raw SQL rather than ->change(): the column has no Doctrine-mappable default
 * and this avoids pulling the rest of the column definition into the diff.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('workspaces') || ! Schema::hasColumn('workspaces', 'plan')) {
            return;
        }

        try {
            DB::statement('ALTER TABLE `workspaces` MODIFY `plan` BIGINT UNSIGNED NULL');
        } catch (\Throwable $e) {
            // Already nullable (a server fixed by hand), or a driver that does
            // not support MODIFY — neither is a reason to fail the migration.
            \Illuminate\Support\Facades\Log::info(
                '[MIGRATION] workspaces.plan nullable skipped: ' . $e->getMessage()
            );
        }
    }

    public function down(): void
    {
        // Deliberately NOT reverted. Going back to NOT NULL would fail on any
        // install that legitimately has plan-less workspaces, and would
        // re-break admin workspace creation.
    }
};
