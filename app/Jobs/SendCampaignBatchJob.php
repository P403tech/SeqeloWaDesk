<?php

namespace App\Jobs;

use App\Http\Controllers\WaCampaignsController;
use App\Support\Scaling;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs a campaign's paced send on a queue WORKER instead of the web request's
 * afterResponse() closure — so it's free of the ~20s PHP-FPM budget and scales
 * across worker servers. Only used when Advanced Scaling is ON (Scaling::enabled);
 * the default path stays afterResponse/inline (see WaCampaignsController::dispatchCampaignNow).
 *
 * ShouldBeUnique(campaignId): the SAME campaign can never be queued twice at once
 * — belt-and-suspenders on top of the per-recipient claim + send-attempts cap
 * that already make the send itself exactly-once.
 */
class SendCampaignBatchJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** No auto-retry: the send has its OWN durable per-recipient retry (send_attempts
     *  + next_attempt_at, swept by CampaignScheduleSweeper). A blind job retry would
     *  re-scan the whole campaign — the claim guards make it safe, but it's wasteful. */
    public $tries = 1;
    public $timeout = 3600; // a big blast can take a while; workers have no FPM cap

    public function __construct(
        public int $campaignId,
        public array $contactIds,
        public string $type,
        public array $payload,
    ) {}

    /** Unique lock lives only until the job starts running (then a re-arm may queue the next batch). */
    public function uniqueId(): string
    {
        return 'campaign-send-' . $this->campaignId;
    }

    public function handle(): void
    {
        Scaling::markWorkerAlive(); // health: proves a worker is processing
        app(WaCampaignsController::class)->runQueuedCampaignSend(
            $this->campaignId, $this->contactIds, $this->type, $this->payload
        );
    }
}
