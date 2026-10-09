<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Optional header image on admin WhatsApp samples (IMAGE header + body text). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wa_template_samples', function (Blueprint $table) {
            $table->string('header_type', 16)->default('text')->after('language');
            $table->string('image_path')->nullable()->after('emoji');
        });
    }

    public function down(): void
    {
        Schema::table('wa_template_samples', function (Blueprint $table) {
            $table->dropColumn(['header_type', 'image_path']);
        });
    }
};
