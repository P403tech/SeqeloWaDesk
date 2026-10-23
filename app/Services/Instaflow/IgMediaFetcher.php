<?php

namespace App\Services\Instaflow;

use App\Services\Waba\WabaMediaFetcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Downloads an inbound Instagram DM media attachment to our own disk.
 *
 * Instagram delivers media (photos, videos, voice notes, shared reels/posts,
 * story mentions, story-reply thumbnails) as a PUBLIC but SHORT-LIVED CDN url
 * (lookaside.fbsbx.com / scontent…). Those links expire within hours, so the
 * raw url stored on the message rots and the inbox bubble renders a broken /
 * empty "Attachment". Copying the bytes to media_disk() at receive-time makes
 * the media permanent — exactly what ManyChat does — so a reel/photo/story the
 * customer sent yesterday still shows inline today.
 *
 * Unlike WABA (a 2-step authenticated fetch) the IG url is directly fetchable
 * with no token. Returns the stored relative path, or null on any failure — the
 * caller then falls back to the (possibly-expiring) CDN url kept in meta.
 */
class IgMediaFetcher
{
    /** Hard cap so a huge video can never exhaust memory/disk. */
    private const MAX_BYTES = 25 * 1024 * 1024;

    public function downloadToDisk(string $url, string $typeHint = ''): ?string
    {
        $url = trim($url);
        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            return null;
        }

        try {
            // connectTimeout fails a dead connect fast; retry rides through the
            // cold-connection blip Meta's CDN occasionally returns. throw:false
            // so a final failed RESPONSE falls through to the successful() guard.
            $res = Http::connectTimeout(8)->timeout(30)->retry(2, 600, null, false)->get($url);
            if (! $res->successful()) {
                return null;
            }
            $bin = $res->body();
            if ($bin === '' || strlen($bin) > self::MAX_BYTES) {
                return null;
            }

            $mime = trim(explode(';', (string) $res->header('Content-Type'))[0]);
            $ext = $this->resolveExt($mime, $url, $typeHint);
            $path = 'chat-media/ig_'.Str::random(22).'.'.$ext;
            media_storage()->put($path, $bin);

            return $path;
        } catch (\Throwable $e) {
            Log::warning('[IG-MEDIA] download failed: '.$e->getMessage(), ['url' => mb_substr($url, 0, 140)]);

            return null;
        }
    }

    /**
     * Prefer the Content-Type (reuses WABA's mime→ext map), then the url's own
     * extension, then the attachment type hint — so a reel saves as .mp4, a
     * voice note as .ogg and a photo as .jpg even when the CDN omits a clean
     * Content-Type.
     */
    private function resolveExt(string $mime, string $url, string $typeHint): string
    {
        $ext = (new WabaMediaFetcher())->extFor($mime);
        if ($ext !== 'bin') {
            return $ext;
        }
        if (preg_match('/\.([a-z0-9]{2,5})(?:\?|#|$)/i', $url, $mm)) {
            return strtolower($mm[1]);
        }

        return match ($typeHint) {
            'video', 'ig_reel', 'reel', 'story_mention' => 'mp4',
            'audio', 'voice' => 'ogg',
            default => 'jpg',
        };
    }
}
