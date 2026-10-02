<?php

namespace App\Jobs;

use App\Services\Commerce\CommerceEventNotifier;
use App\Support\Scaling;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends ONE configured store-event template (Shopify/Woo order events) to one
 * recipient — used only for the DELAYED path: a store event with a `delay_seconds`
 * set dispatches this with `->delay(...)` so the template fires N seconds after the
 * event instead of immediately. Requires Advanced Scaling (a queue worker) — with
 * the default sync connection the delay is ignored and it sends right away, which
 * matches the pre-existing "send immediately" behaviour.
 */
class SendCommerceEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public function __construct(
        public int $workspaceId,
        public ?int $userId,
        public string $toNumber,
        public int $templateId,
        public array $ctx,
        public ?string $senderKey = null,
    ) {}

    public function handle(): void
    {
        Scaling::markWorkerAlive();
        $tpl = \App\Models\WaTemplate::find($this->templateId);
        if (! $tpl) return;
        app(CommerceEventNotifier::class)->notify($this->workspaceId, $this->userId, $this->toNumber, $tpl, $this->ctx, $this->senderKey ?: null);
    }
}
