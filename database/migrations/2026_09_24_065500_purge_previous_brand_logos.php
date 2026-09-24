<?php

use App\Support\Brand;
use Illuminate\Database\Migrations\Migration;

/**
 * Purge leftover uploaded logos (system_settings, workspace columns,
 * storage/brand files). The shipped Seqelo bag in public/brand stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        Brand::purgePreviousLogos();
    }

    public function down(): void
    {
        //
    }
};
