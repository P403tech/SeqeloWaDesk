<?php

namespace App\Jobs;

use App\Support\Scaling;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Proves a Redis queue worker is alive. Dispatched once a minute while
 * Advanced Scaling is on. Real campaign jobs also call markWorkerAlive().
 */
class ScalingHeartbeatJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function handle(): void
    {
        Scaling::markWorkerAlive();
    }
}
