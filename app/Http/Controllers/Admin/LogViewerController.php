<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin > System Health — live Laravel / Node log viewer.
 *
 * Local files when Node and PHP share a disk (cPanel / Docker-all-in-one).
 * On Railway they do not: SeqeloWaDesk and node-bridge are separate services,
 * so node.log lives only on the bridge. In that case we pull the tail over
 * the existing X-Node-Token HTTP bridge (same as campaigns / QR).
 */
class LogViewerController extends Controller
{
    private const FIRST_BYTES = 65536;
    private const MAX_CHUNK   = 262144;

    /** Absolute path of a log on THIS host, or null when the file is not here. */
    public static function path(string $which): ?string
    {
        if ($which === 'laravel') {
            $p = storage_path('logs/laravel.log');
            return is_file($p) ? $p : null;
        }
        if ($which === 'node') {
            foreach (array_filter([(string) env('WADESK_NODE_LOG', ''), base_path('node/node.log')]) as $p) {
                if (is_file($p)) return $p;
            }
        }
        return null;
    }

    public static function laravelLogsToStderr(): bool
    {
        $ch = strtolower((string) config('logging.default', ''));

        return in_array($ch, ['stderr', 'errorlog', 'syslog', 'null'], true);
    }

    public static function nodeBridgeConfigured(): bool
    {
        return wd_node_url() !== '' && node_token() !== '';
    }

    public function tail(Request $request, string $which): JsonResponse
    {
        $path = self::path($which);
        if ($path !== null) {
            return response()->json($this->readLocal($path, $request->query('offset')));
        }

        if ($which === 'node') {
            $remote = $this->tailFromBridge($request->query('offset'));
            if ($remote !== null) {
                return response()->json($remote);
            }

            return response()->json([
                'ok' => false,
                'error' => self::nodeBridgeConfigured()
                    ? __('Could not reach the Node bridge for logs. Check SERVER_URL / baileys_server_url and that node-bridge is running.')
                    : __('Node runs as a separate service on this host. Set the bridge URL and node token in Admin → Channel settings, then use Live log — it reads node.log from node-bridge, not from this web disk.'),
            ]);
        }

        return response()->json([
            'ok' => false,
            'error' => self::laravelLogsToStderr()
                ? __('This web service logs to stderr (Railway). There is no storage/logs/laravel.log file — open the SeqeloWaDesk service logs in Railway.')
                : __('storage/logs/laravel.log does not exist yet.'),
        ]);
    }

    public function download(string $which): BinaryFileResponse|StreamedResponse
    {
        $path = self::path($which);
        if ($path !== null) {
            return response()->download($path, $which . '-' . now()->format('Y-m-d_His') . '.log', [
                'Content-Type' => 'text/plain; charset=utf-8',
            ]);
        }

        if ($which === 'node' && self::nodeBridgeConfigured()) {
            $base = wd_node_url();
            $res = Http::timeout(20)
                ->withHeaders(['X-Node-Token' => node_token(), 'Accept' => 'text/plain'])
                ->get($base.'/api/admin/logs/download');
            abort_unless($res->successful(), 404);
            $body = $res->body();

            return response()->streamDownload(function () use ($body) {
                echo $body;
            }, 'node-'.now()->format('Y-m-d_His').'.log', [
                'Content-Type' => 'text/plain; charset=utf-8',
            ]);
        }

        abort(404);
    }

    /**
     * @return array{ok:bool,file:string,size:int,offset:int,behind:int,rotated:bool,text:string}
     */
    private function readLocal(string $path, mixed $offset): array
    {
        clearstatcache(true, $path);
        $size = (int) filesize($path);
        $rotated = false;

        if ($offset === null || $offset === '') {
            $start = max(0, $size - self::FIRST_BYTES);
        } else {
            $start = max(0, (int) $offset);
            if ($start > $size) {
                $start = 0;
                $rotated = true;
            }
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
            if (($offset === null || $offset === '') && $start > 0) {
                $nl = strpos($text, "\n");
                $text = $nl === false ? '' : substr($text, $nl + 1);
            }
        }

        return [
            'ok'      => true,
            'file'    => basename($path),
            'size'    => $size,
            'offset'  => $start + $len,
            'behind'  => max(0, $size - ($start + $len)),
            'rotated' => $rotated,
            'text'    => mb_convert_encoding($text, 'UTF-8', 'UTF-8'),
        ];
    }

    /** @return array<string,mixed>|null */
    private function tailFromBridge(mixed $offset): ?array
    {
        $base = wd_node_url();
        $token = node_token();
        if ($base === '' || $token === '') {
            return null;
        }

        $query = [];
        if ($offset !== null && $offset !== '') {
            $query['offset'] = (int) $offset;
        }

        try {
            $res = Http::timeout(12)
                ->withHeaders(['X-Node-Token' => $token, 'Accept' => 'application/json'])
                ->get($base.'/api/admin/logs/tail', $query);
        } catch (\Throwable $e) {
            return null;
        }

        if (! $res->successful()) {
            return null;
        }

        $json = $res->json();
        if (! is_array($json) || empty($json['ok'])) {
            return is_array($json) ? $json : null;
        }

        $json['file'] = $json['file'] ?? 'node.log';

        return $json;
    }
}
