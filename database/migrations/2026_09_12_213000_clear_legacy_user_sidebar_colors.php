<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Appearance colour picker always posted a hex, so production stored the
 * old WaDesk dark-green rail as if it were a custom choice. Seqelo's default
 * is mint (#EFF9F6); drop those legacy values so the new rail can show.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }

        $legacyLower = [
            '#0b1f1c', '#0a0f0e', '#0b211d', '#070d0c', '#13312d',
            '#075e54', '#128c7e', '#25d366', '#111827', '#0f172a',
            '#0a1628', '#15281f',
        ];
        $placeholders = implode(',', array_fill(0, count($legacyLower), '?'));

        DB::table('system_settings')
            ->where('key', 'user_sidebar_color')
            ->whereRaw('LOWER(value) in ('.$placeholders.')', $legacyLower)
            ->update(['value' => '']);

        Cache::forget('system_setting:user_sidebar_color');
        Cache::forget('system_setting:user_sidebar_text_color');
        Cache::forget('system_setting:user_sidebar_accent_color');
    }

    public function down(): void
    {
        // Intentionally empty — the stored hexes were accidental defaults.
    }
};
