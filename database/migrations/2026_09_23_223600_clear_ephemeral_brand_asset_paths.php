<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Uploaded favicon/logo paths lived in storage/ (ephemeral on Railway).
 * MySQL kept the path after the file vanished. The Seqelo bag is shipped
 * in public/brand/seqelo-mark.png — clear the DB so that file is used.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }

        $keys = [
            'brand.favicon',
            'brand.logo.paper',
            'brand.logo.bright',
            'brand.logo.dark',
            'brand.logo.doodle',
        ];

        DB::table('system_settings')->whereIn('key', $keys)->delete();
        foreach ($keys as $key) {
            Cache::forget('system_setting:' . $key);
        }
    }

    public function down(): void
    {
        //
    }
};
