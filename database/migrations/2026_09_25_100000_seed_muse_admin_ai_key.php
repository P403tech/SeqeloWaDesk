<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pre-seed Muse (Meta Muse Spark) on admin_ai_keys so Admin → AI Keys
 * lists it with OpenAI / Anthropic / Gemini. Inactive until a key is pasted.
 */
return new class extends Migration {
    public function up(): void
    {
        if (DB::table('admin_ai_keys')->where('provider', 'muse')->exists()) {
            return;
        }

        $maxSort = (int) DB::table('admin_ai_keys')->max('sort_order');
        DB::table('admin_ai_keys')->insert([
            'provider'      => 'muse',
            'name'          => 'Muse (Meta)',
            'api_key'       => '',
            'default_model' => 'muse-spark-1.3',
            'extra_config'  => json_encode([]),
            'is_active'     => false,
            'sort_order'    => $maxSort + 1,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('admin_ai_keys')
            ->where('provider', 'muse')
            ->where('api_key', '')
            ->delete();
    }
};
