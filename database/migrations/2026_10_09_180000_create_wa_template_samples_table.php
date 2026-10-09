<?php

use App\Models\WaTemplateSample;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-curated WhatsApp sample library. Tenants copy a sample into
 * /templates/create — these are NOT submitted to Meta for the tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_template_samples', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 80)->unique();
            $table->string('title', 160);
            $table->string('category', 32)->index();
            $table->string('meta_category', 32)->default('utility');
            $table->string('language', 12)->default('en_US');
            $table->string('header', 60);
            $table->text('body');
            $table->string('footer', 60)->nullable();
            $table->json('buttons')->nullable();
            $table->string('color_from', 9)->default('#1B4B3D');
            $table->string('color_to', 9)->default('#037D66');
            $table->string('emoji', 16)->default('✦');
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        WaTemplateSample::seedBuiltins();
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_template_samples');
    }
};
