<?php

namespace App\Console\Commands;

use App\Support\Brand;
use Illuminate\Console\Command;

class SeqeloUseShippedBrandCommand extends Command
{
    protected $signature = 'seqelo:use-shipped-brand';

    protected $description = 'Clear DB brand upload paths so the git-tracked Seqelo bag logo is used after deploys.';

    public function handle(): int
    {
        $n = Brand::purgePreviousLogos();
        $this->info($n > 0
            ? "Removed {$n} previous logo record(s)/file(s). Using public/brand/seqelo-mark.png only."
            : 'No previous logos left. Using the shipped Seqelo mark.');

        return self::SUCCESS;
    }
}
