<?php

namespace Database\Seeders;

use App\Models\WaTemplateSample;
use Illuminate\Database\Seeder;

class WaTemplateSampleSeeder extends Seeder
{
    public function run(): void
    {
        WaTemplateSample::seedBuiltins();
    }
}
