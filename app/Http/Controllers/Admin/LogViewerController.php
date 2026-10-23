<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Admin > System Health — live Laravel / Node log viewer.
 *
 * tail(): first call (no offset) returns the last ~64 KB; every next poll
 * passes the byte offset it got back and receives only what was appended
 * since. A file that shrank (rotated / cleared) restarts from the top.
 * download(): the complete file.
 *
 * Only the two known files are ever opened — the {which} segment is a fixed
 * key, never a path.
 */
class LogViewerController extends Controller
{
    private const FIRST_BYTES = 65536;   // initial tail
    private const MAX_CHUNK   = 262144;  // max bytes returned per poll

    /** Absolute path of a log, or null when it isn't on this server. */
    public static function path(string $which): ?string
    {
        if ($which === 'laravel') {
            $p = storage_path('logs/laravel.log');
            return is_file($p) ? $p : null;
        }
        if ($which === 'node') {
            // Same resolution as node/log-gate.js: WADESK_NODE_LOG, else node/node.log.
            foreach (array_filter([(string) env('WADESK_NODE_LOG', ''), base_path('node/node.log')]) as $p) {
                if (is_file($p)) return $p;
            }
        }
        return null;
    }

    public function tail(Request $request, string $which): JsonResponse
    {
        $path = self::path($which);
        if ($path === null) {
            return response()->json([
                'ok' => false,
                'error' => $which === 'node'
                    ? __('node.log was not found on this server. It is written by the Node service into node/node.log (or WADESK_NODE_LOG) — make sure Node runs from this folder and has been restarted since the logging update.')
                    : __('storage/logs/laravel.log does not exist yet.'),
            ]);
        }

        clearstatcache(true, $path);
        $size = (int) filesize($path);
        $offset = $request->query('offset');
        $rotated = false;

        if ($offset === null || $offset === '') {
            $start = max(0, $size - self::FIRST_BYTES);
        } else {
            $start = max(0, (int) $offset);
            if ($start > $size) { $start = 0; $rotated = true; }
        }

        $len = min(self::MAX_CHUNK, $size - $start);
        $text = '';
        if ($len > 0) {
            $fh = @fopen($path, 'rb');
            if ($fh) {
                fseek($fh, $start);
                $text = (string) fread($fh, $len);
                fclose($fh);
            }
            // Starting mid-file: drop the partial first line.
            if (($offset === null || $offset === '') && $start > 0) {
                $nl = strpos($text, "\n");
                $text = $nl === false ? '' : substr($text, $nl + 1);
            }
        }

        return response()->json([
            'ok'      => true,
            'file'    => basename($path),
            'size'    => $size,
            'offset'  => $start + $len,
            'behind'  => max(0, $size - ($start + $len)), // > 0 when more is waiting
            'rotated' => $rotated,
            'text'    => mb_convert_encoding($text, 'UTF-8', 'UTF-8'),
        ]);
    }

    public function download(string $which): BinaryFileResponse
    {
        $path = self::path($which);
        abort_if($path === null, 404);

        return response()->download($path, $which . '-' . now()->format('Y-m-d_His') . '.log', [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }
}
