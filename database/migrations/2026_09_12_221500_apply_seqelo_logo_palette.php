<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drop Appearance / theme overrides that still pin the previous Interakt
 * forest/mint or WhatsApp greens, so the Seqelo bag-S logo palette can show.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }

        $stale = [
            '#1b4b3d', '#037d66', '#00a68b', '#eff9f6', '#b7fbd2',
            '#075e54', '#128c7e', '#25d366', '#dcf8c6', '#e7ffdb',
        ];
        $keys = [
            'theme.color.wa-deep',
            'theme.color.wa-teal',
            'theme.color.wa-green',
            'theme.color.wa-mint',
            'theme.color.wa-bubble',
            'user_sidebar_color',
            'user_sidebar_accent_color',
        ];
        $placeholders = implode(',', array_fill(0, count($stale), '?'));

        foreach ($keys as $key) {
            DB::table('system_settings')
                ->where('key', $key)
                ->whereRaw('LOWER(value) in ('.$placeholders.')', $stale)
                ->update(['value' => '']);
            Cache::forget('system_setting:'.$key);
        }
    }

    public function down(): void
    {
        //
    }
};
