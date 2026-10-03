<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `contacts.image` was varchar(255), but a social-capture avatar is an
 * Instagram/Facebook CDN URL (~400+ chars) stored ENCRYPTED (longer still), so
 * every inbound DM logged "SQLSTATE[22001] Data too long for column 'image'" and
 * the contact's photo was silently dropped. Widen it to TEXT.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        if (! Schema::hasColumn('contacts', 'image')) {
            return;
        }
        // Doctrine/DBAL can choke on some legacy column flags, so change the type
        // with raw DDL — TEXT holds any encrypted CDN URL.
        DB::statement('ALTER TABLE `contacts` MODIFY `image` TEXT NULL');
    }

    public function down(): void
    {
        if (! Schema::hasColumn('contacts', 'image')) {
            return;
        }
        DB::statement('ALTER TABLE `contacts` MODIFY `image` VARCHAR(255) NULL');
    }
};
