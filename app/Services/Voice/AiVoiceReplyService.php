<?php

namespace App\Services\Voice;

use App\Models\AiAgent;
use App\Models\AiVoiceUsageDaily;
use App\Models\Conversation;
use App\Models\InboxMessage;
use App\Services\AiAgentService;
use App\Services\Voice\Dto\TtsResult;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Voice-note AI pipeline. Runs ASR → LLM → TTS → outbound send when an
 * inbound audio message lands on a conversation whose assigned AiAgent
 * has voice_note_enabled.
 *
 * Step-by-step:
 *   1. Hydrate the inbound InboxMessage + Conversation + AiAgent.
 *   2. Guards: agent active? voice_note_enabled? daily quota left?
 *   3. ASR the audio file → cache transcript on the message row so a
 *      retry doesn't re-bill, and the inbox can render the text.
 *   4. Reuse AiAgentService::generateReply() so voice replies follow
 *      the exact same prompt rules as text replies (one source of
 *      truth for tone, knowledge, saved-replies block).
 *   5. TTS the reply text into an OGG/Opus file under
 *      public/uploads/voice-replies/.
 *   6. Dispatch the file to the channel-specific outbound bridge:
 *      Baileys → Node /api/send-voice-note/<from_number>
 *      WABA    → POST /<phone_id>/media then /<phone_id>/messages
 *   7. Write the outbound row to inbox_messages so the operator UI
 *      shows what the AI said + the transcript.
 *   8. Bump daily usage counters for billing.
 *
 * All exceptions bubble out — the queue job decides retry vs fail.
 */
class AiVoiceReplyService
{
    public function __construct(
        private readonly VoiceDriverFactory $factory,
        private readonly AiAgentService $aiAgents,
    ) {}

    /**
     * Public entrypoint. Returns the outbound InboxMessage on success,
     * or null when guards stopped the pipeline (not an error).
     */
    public function process(InboxMessage $inboundMsg): ?InboxMessage
    {
        if ($inboundMsg->ai_processed_at) {
            return null; // idempotency — already handled
        }

        $convo = $inboundMsg->conversation;
        if (!$convo || !$convo->assignee_agent_id) {
            return null;
        }

        $agent = AiAgent::find($convo->assignee_agent_id);
        if (!$this->canVoiceReply($agent, $convo)) {
            return null;
        }

        $audioPath = $this->downloadAudioToTemp($inboundMsg);
        if (!$audioPath) {
            Log::warning('[VOICE-AI] audio file missing', ['message_id' => $inboundMsg->id]);
            return null;
        }

        // 1. ASR — transcribe and cache on the row before anything else
        // so partial failures still leave the operator with readable text.
        // The rest of the pipeline works from the transcript TEXT only, so
        // drop the scratch file the moment ASR is done (success or not).
        try {
            $transcript = $this->factory->asrFor($agent)
                ->transcribe($audioPath, $agent->asr_language ?: $agent->voice_language);
        } finally {
            if (is_file($audioPath)) @unlink($audioPath);
        }

        $inboundMsg->forceFill([
            'voice_transcript'      => $transcript->text,
            'voice_transcript_lang' => $transcript->language,
            // Set body to the transcript so AiAgentService::generateReply
            // — which builds the LLM prompt from message bodies — picks
            // it up without a separate code path. We don't clobber
            // existing body; voice messages typically arrive with body
            // empty or "[voice note]".
            'body' => trim((string) $inboundMsg->body) === '' || $inboundMsg->body === '[voice note]'
                ? $transcript->text
                : $inboundMsg->body,
        ])->save();

        if ($transcript->isEmpty()) {
            // Silent / unintelligible audio — mark processed so we don't
            // retry, and don't bill TTS for nothing.
            $inboundMsg->forceFill(['ai_processed_at' => now()])->save();
            return null;
        }

        // 2. LLM — reuse the same generateReply() the text path uses.
        // The conversation history now includes the transcribed audio
        // as the latest customer turn, so the agent's prompt sees it
        // naturally with no special-casing.
        $replyText = $this->aiAgents->generateReply($agent, $convo->fresh());
        if (!$replyText || trim($replyText) === '') {
            $inboundMsg->forceFill(['ai_processed_at' => now()])->save();
            return null;
        }

        // 3. TTS — synthesise the reply.
        $tts = $this->factory->ttsFor($agent)
            ->synthesize($replyText, $agent->voice_id, $agent->voice_language);

        // 4. Hand off to the channel-specific outbound dispatcher.
        // The dispatcher returns the outbound InboxMessage it created
        // (or null on dispatch failure). This service is intentionally
        // channel-agnostic — it doesn't know whether the conversation
        // belongs to a Baileys or WABA number.
        $outbound = app(\App\Services\Voice\VoiceOutboundDispatcher::class)
            ->send($convo, $agent, $replyText, $tts);

        // 5. Mark inbound as processed + link the reply.
        $inboundMsg->forceFill([
            'ai_processed_at' => now(),
            'ai_reply_id'     => $outbound?->id,
        ])->save();

        // 6. Bookkeeping. Per-day per-agent counters fuel the
        // workspace's wallet/plan dashboards.
        $this->bumpUsage($agent, $transcript->durationSec, $tts->charCount);

        try {
            $convo->refresh();
            if (\App\Services\Ai\ShopManagerRouter::enabled($agent)
                && app(\App\Services\Ai\ShopManagerRouter::class)->consumeHandoffAfterReply($convo)
                && ($agent->handoff_enabled ?? true)) {
                $this->aiAgents->triggerHandoff($agent, $convo, 'shop_manager:human');
            }
        } catch (\Throwable $e) {
            Log::info('[VOICE-AI] shop manager handoff skipped: '.$e->getMessage());
        }

        return $outbound;
    }

    /**
     * All the conditions that must be true for the voice-reply pipeline
     * to fire. Centralised so the inbound webhook can call the SAME
     * checks before dispatching the queue job — keeps the queue out of
     * useless retries when guards would have blocked anyway.
     */
    public function canVoiceReply(?AiAgent $agent, Conversation $convo): bool
    {
        if (!$agent || !$agent->is_active) return false;
        if (!$agent->voice_note_enabled) return false;
        if (!$agent->handlesDevice($convo->device_id ?? null)) return false;

        // Plan gate. Workspaces without the `ai_voice_reply` feature
        // can't run voice replies even if the agent toggle is on.
        $workspace = $convo->workspace;
        if ($workspace && !\App\Services\PlanLimitGuard::hasFeature($workspace, 'ai_voice_reply')) {
            return false;
        }

        // Daily safety cap. Stops a runaway loop on a free workspace
        // from draining provider credits.
        if ($this->usedToday($agent) >= (int) ($agent->max_voice_notes_per_day ?? 200)) {
            return false;
        }

        return true;
    }

    /**
     * Materialise the inbound audio as a LOCAL file Whisper can read.
     *
     * Inbound media may live on the LOCAL disk OR on cloud storage
     * (Cloudflare R2, S3, …). The old version only looked under
     * `storage/app/public`, so on any workspace with cloud storage enabled
     * the file was reported "missing" and the whole voice-reply pipeline
     * silently bailed — no transcript, no voice reply. That was the bug.
     *
     * Read the bytes through the ACTIVE media disk (`media_storage()` — R2
     * when cloud storage is on, local otherwise) into a scratch file, since
     * Whisper needs a real file handle. Whisper also picks the audio format
     * from the filename EXTENSION and only accepts a fixed set, so a mangled
     * mime slug (e.g. "oggcod" from "audio/ogg; codecs=opus") is normalised
     * to a supported extension — the bytes are ogg regardless.
     *
     * Returns a temp path the CALLER MUST unlink, or null when the object is
     * genuinely absent.
     */
    private function downloadAudioToTemp(InboxMessage $msg): ?string
    {
        $path = ltrim((string) ($msg->media_path ?? ''), '/\\');
        if ($path === '') return null;

        try {
            $disk  = media_storage();
            $bytes = null;

            if ($disk->exists($path)) {
                $bytes = $disk->get($path);
            } else {
                // Legacy fallback: some installs wrote straight to the public
                // symlink target without registering it on the media disk.
                $legacy = public_path('storage/' . $path);
                if (is_file($legacy)) {
                    $bytes = @file_get_contents($legacy);
                }
            }
            if ($bytes === null || $bytes === '') return null;

            $rawExt = strtolower(pathinfo($path, PATHINFO_EXTENSION) ?: 'ogg');
            $ext = in_array($rawExt, ['flac', 'm4a', 'mp3', 'mp4', 'mpeg', 'mpga', 'oga', 'ogg', 'wav', 'webm'], true) ? $rawExt : 'ogg';
            $tmp = tempnam(sys_get_temp_dir(), 'voice_asr_') . '.' . $ext;
            file_put_contents($tmp, $bytes);

            return is_file($tmp) ? $tmp : null;
        } catch (\Throwable $e) {
            Log::warning('[VOICE-AI] audio fetch failed: ' . $e->getMessage(), ['message_id' => $msg->id]);
            return null;
        }
    }

    private function usedToday(AiAgent $agent): int
    {
        return AiVoiceUsageDaily::query()
            ->where('ai_agent_id', $agent->id)
            ->where('date', now()->toDateString())
            ->value('voice_notes_processed') ?? 0;
    }

    private function bumpUsage(AiAgent $agent, int $asrSeconds, int $ttsChars): void
    {
        try {
            AiVoiceUsageDaily::query()->updateOrCreate(
                [
                    'workspace_id' => $agent->workspace_id,
                    'ai_agent_id'  => $agent->id,
                    'date'         => now()->toDateString(),
                ],
                [
                    'voice_notes_processed' => \DB::raw('voice_notes_processed + 1'),
                    'asr_seconds'           => \DB::raw('asr_seconds + ' . max(0, $asrSeconds)),
                    'tts_chars'             => \DB::raw('tts_chars + ' . max(0, $ttsChars)),
                ],
            );
        } catch (\Throwable $e) {
            // Bookkeeping failure shouldn't cost the operator the reply.
            Log::warning('[VOICE-AI] usage counter write failed: ' . $e->getMessage());
        }
    }
}
