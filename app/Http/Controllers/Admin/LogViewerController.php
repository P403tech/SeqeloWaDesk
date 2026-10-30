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

    /** Set by tailFromBridge() when the HTTP call fails — surfaced in tail(). */
    private ?string $lastBridgeLogError = null;

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
                    ? ($this->lastBridgeLogError ?? __('Could not fetch node.log from the bridge. See Admin → Channel settings → Test connection, then redeploy the node-bridge Railway service from this repo (root folder node).'))
                    : __('Node runs on a separate Railway service. Set Server URL + X-Node-Token under Admin → Channel settings — Live log reads node.log from node-bridge, not this PHP container.'),
            ]);
        }

        return response()->json([
            'ok' => false,
            'info' => self::laravelLogsToStderr(),
            'error' => self::laravelLogsToStderr()
                ? __('This is normal on Railway: LOG_CHANNEL=stderr, so PHP logs go to the SeqeloWaDesk deploy log stream — not storage/logs/laravel.log. In Railway open project discerning-spirit → service SeqeloWaDesk → Deployments → View logs.')
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
        $this->lastBridgeLogError = null;
        $base = wd_node_url();
        $token = node_token();
        if ($base === '' || $token === '') {
            return null;
        }

        $query = [];
        if ($offset !== null && $offset !== '') {
            $query['offset'] = (int) $offset;
        }

        $url = $base.'/api/admin/logs/tail';
        try {
            $res = Http::timeout(12)
                ->withHeaders(['X-Node-Token' => $token, 'Accept' => 'application/json'])
                ->get($url, $query);
        } catch (\Throwable $e) {
            $host = parse_url($base, PHP_URL_HOST) ?: $base;
            $this->lastBridgeLogError = __('Could not connect to :host (:msg). The bridge process may be down or the Server URL is wrong.', [
                'host' => $host,
                'msg' => $e->getMessage(),
            ]);

            return null;
        }

        if (in_array($res->status(), [401, 403], true)) {
            $this->lastBridgeLogError = __('Bridge reachable but rejected X-Node-Token (HTTP :status). Set the same NODE_WEBHOOK_TOKEN on the node-bridge Railway service and in Admin → Channel settings, then Test connection.', [
                'status' => $res->status(),
            ]);

            return null;
        }

        if ($res->status() === 404) {
            $this->lastBridgeLogError = __('Bridge is up but /api/admin/logs/tail is missing (HTTP 404). Redeploy node-bridge from GitHub branch main, root directory node, with the latest commit that added log tail routes.');

            return null;
        }

        if (! $res->successful()) {
            $this->lastBridgeLogError = __('Bridge returned HTTP :status for the log tail endpoint.', ['status' => $res->status()]);

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
