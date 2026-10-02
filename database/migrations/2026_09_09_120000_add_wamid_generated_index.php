<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ROOT-CAUSE PERF FIX — index the WhatsApp message id.
 *
 * `applyStatus()` looks a delivery status up by `meta->wa_message_id` on the two
 * biggest tables (`messages`, `inbox_messages`) on EVERY Meta status webhook,
 * using `whereJsonContains(...)`. A JSON predicate can't use an index → a FULL
 * TABLE SCAN per lookup. During a large campaign (thousands of sends → thousands
 * of sent/delivered/read/failed webhooks) that's thousands of full scans of the
 * two largest tables → mysqld pinned at ~150% CPU.
 *
 * This adds a STORED generated column that mirrors meta->wa_message_id, plus an
 * index on it, so the lookups become index seeks. No insert code needs to change
 * (the column is derived from `meta` automatically).
 *
 * MySQL/MariaDB only (JSON generated columns). Defensive: skips if the driver
 * doesn't support it, the table is missing, or the column already exists — so a
 * re-run or a non-MySQL install is a safe no-op. NOTE: the ALTER rebuilds the
 * table once — run it during low traffic on large installs.
 */
return new class extends Migration
{
    private array $tables = ['messages', 'inbox_messages'];

    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return; // generated-column JSON extract is MySQL/MariaDB-specific
        }

        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'meta')) continue;
            if (Schema::hasColumn($table, 'wa_message_id_g')) continue;
            try {
                DB::statement(
                    "ALTER TABLE `{$table}` "
                    . "ADD COLUMN `wa_message_id_g` VARCHAR(191) "
                    . "GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(`meta`, '$.wa_message_id'))) STORED"
                );
                DB::statement("ALTER TABLE `{$table}` ADD INDEX `{$table}_wamid_g_idx` (`wa_message_id_g`)");
            } catch (\Throwable $e) {
                // Never let a perf index block the deploy — log and move on. The
                // code falls back to whereJsonContains when the column is absent.
                \Log::warning("[migration] wamid index on {$table} skipped: " . $e->getMessage());
            }
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb'], true)) return;

        foreach ($this->tables as $table) {
            if (! Schema::hasColumn($table, 'wa_message_id_g')) continue;
            try { DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$table}_wamid_g_idx`"); } catch (\Throwable $e) {}
            try { DB::statement("ALTER TABLE `{$table}` DROP COLUMN `wa_message_id_g`"); } catch (\Throwable $e) {}
        }
    }
};
