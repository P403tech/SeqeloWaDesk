<?php

use App\Models\WaTemplateSample;
use Illuminate\Database\Migrations\Migration;

/** Insert newly shipped sample slugs. Never overwrites admin edits. */
return new class extends Migration
{
    public function up(): void
    {
        WaTemplateSample::seedBuiltins();
    }

    public function down(): void
    {
        // Keep rows — an admin may have edited them after seed.
    }
};
