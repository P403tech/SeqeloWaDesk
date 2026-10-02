<?php

namespace App\Services\Waba;

use App\Models\SystemSetting;
use App\Models\WaProviderConfig;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Downloads an inbound WABA Cloud media object to local/cloud disk.
 *
 * Meta media is NOT pushed to the webhook — only a `media_id`. Fetching is a
 * 2-step authenticated call:
 *   1) GET /<media_id>  → { url, mime_type, file_size }
 *   2) GET <url>        → the binary (same bearer token)
 * The download URL is short-lived (~5 min) but the media_id itself is valid for
 * ~30 days, so this can also power an operator "retry download" on a media that
 * failed to fetch at receive-time (e.g. voice notes before the audio fix, or a
 * transient error). Returns the stored relative path, or null on any failure.
 *
 * Shared by WaWebhookController (inbound, at receive-time) and
 * TeamInboxController::retryMedia (operator-initiated re-fetch).
 */
class WabaMediaFetcher
{
    public function downloadToDisk(int $workspaceId, string $mediaId, string $mimeHint = '', string $filename = ''): ?string
    {
        if ($workspaceId <= 0 || $mediaId === '') {
            return null;
        }

        try {
            $cfg = WaProviderConfig::query()->primaryForWorkspace($workspaceId)->first()
                ?? WaProviderConfig::query()->where('workspace_id', $workspaceId)
                    ->where('provider', 'waba')->orderByDesc('connected_at')->first();
            if (!$cfg) {
                return null;
            }

            $token = (string) ($cfg->creds()['access_token'] ?? '');
            if ($token === '') {
                return null;
            }
            $version = (string) SystemSetting::get('waba_graph_api_version', 'v23.0');

            // 1) media id -> { url, mime_type, file_size }
            // Bounded retry/backoff: the first (cold) connection to
            // graph.facebook.com intermittently drops or times out. Without a
            // retry that transient blip left inbound media undownloaded
            // (media_path stayed null) so the operator had to press "Retry" to
            // get the image/voice note. connectTimeout fails a dead connect fast;
            // retry rides through the blip so the media lands at ingest. Same
            // mitigation the WABA send path already uses. throw:false → on a
            // final failed RESPONSE we fall through to the successful() guard.
            $metaRes = Http::withToken($token)->acceptJson()
                ->connectTimeout(8)->timeout(15)->retry(2, 600, null, false)
                ->get("https://graph.facebook.com/{$version}/{$mediaId}");
            if (!$metaRes->successful()) {
                return null;
            }
            $url  = (string) $metaRes->json('url');
            $mime = (string) ($metaRes->json('mime_type') ?: $mimeHint);
            if ($url === '') {
                return null;
            }
            if ((int) $metaRes->json('file_size') > 16 * 1024 * 1024) {
                return null;
            }

            // 2) authenticated GET of the short-lived CDN url for the bytes.
            // Same bounded retry as the metadata call — a transient CDN fetch
            // failure here was the other way inbound media ended up needing a
            // manual "Retry". The media_id stays valid ~30 days, so re-fetching
            // is safe.
            $binRes = Http::withToken($token)
                ->connectTimeout(8)->timeout(30)->retry(2, 600, null, false)
                ->get($url);
            if (!$binRes->successful()) {
                return null;
            }
            $bin = $binRes->body();
            if ($bin === '' || strlen($bin) > 16 * 1024 * 1024) {
                return null;
            }

            $path = $this->buildMediaPath($filename, $this->extFor($mime));
            media_storage()->put($path, $bin);

            return $path;
        } catch (\Throwable $e) {
            Log::warning('[WABA-MEDIA] download failed: ' . $e->getMessage(), ['media_id' => $mediaId]);
            return null;
        }
    }

    /**
     * Map a MIME (with any "; codecs=opus" param stripped) to a real file
     * extension. Defaulting everything to 'jpg' saved voice notes as images so
     * the inbox audio player couldn't read them → "Voice message unavailable".
     */
    public function extFor(string $mime): string
    {
        $base = trim(explode(';', strtolower(trim($mime)))[0]);

        return match ($base) {
            'image/png'               => 'png',
            'image/webp'              => 'webp',
            'image/gif'               => 'gif',
            'image/jpeg', 'image/jpg' => 'jpg',
            'audio/ogg', 'audio/opus' => 'ogg',   // WhatsApp voice notes
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/mp4', 'audio/aac', 'audio/x-m4a' => 'm4a',
            'audio/amr'               => 'amr',
            'video/mp4'               => 'mp4',
            'video/3gpp'              => '3gp',
            'application/pdf'         => 'pdf',
            // Documents — previously ALL fell to the 'bin' default, so an
            // inbound Excel/CSV/Word file was saved as a .bin the recipient
            // couldn't open. Map the common office/text/archive types.
            'text/plain'              => 'txt',
            'text/csv', 'application/csv' => 'csv',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/msword'      => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-powerpoint' => 'ppt',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'application/zip', 'application/x-zip-compressed' => 'zip',
            'application/json'        => 'json',
            'application/xml', 'text/xml' => 'xml',
            default => (
                str_starts_with($base, 'image/') ? 'jpg'
                : (str_starts_with($base, 'audio/') ? 'ogg'
                : (str_starts_with($base, 'video/') ? 'mp4' : 'bin'))
            ),
        };
    }

    /**
     * Where to store the downloaded media. When WhatsApp gave us the sender's
     * ORIGINAL filename (documents carry one), keep it — sanitised and prefixed
     * with a short random token to avoid collisions — so the inbox shows
     * "invoice.xlsx" and the file opens, instead of a random ".bin". Falls back
     * to a random name + the mime-derived extension when there is no filename.
     */
    private function buildMediaPath(string $filename, string $ext): string
    {
        $filename = trim($filename);
        if ($filename !== '') {
            $clean = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($filename));
            $clean = trim((string) $clean, '._') ?: 'file';
            // Trust an extension the original name already has; else add ours.
            if (! preg_match('/\.[A-Za-z0-9]{1,8}$/', $clean)) {
                $clean .= '.' . $ext;
            }
            return 'chat-media/' . Str::random(12) . '__' . mb_substr($clean, 0, 120);
        }

        return 'chat-media/' . Str::random(24) . '.' . $ext;
    }
}
