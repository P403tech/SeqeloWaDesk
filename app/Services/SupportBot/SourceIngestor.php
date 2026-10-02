<?php

namespace App\Services\SupportBot;

use App\Models\SupportBotChunk;
use App\Models\SupportBotSource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Turns an admin-supplied support source (uploaded file / URL / raw text) into
 * plain text, then into heading-scoped, size-capped chunks in
 * support_bot_chunks — the corpus the retriever searches.
 *
 * The extraction + SSRF-guarded URL fetch mirror the proven AI-Training path
 * (AiTrainingController), kept self-contained here so the support bot needs no
 * tenant/workspace context. Extracted text is stored on the source so a
 * re-index can rebuild chunks without re-fetching or re-parsing.
 */
class SourceIngestor
{
    private const MAX_CHARS = 1200;   // per chunk
    private const MIN_CHARS = 40;     // drop shorter trailing scraps
    private const DISK       = 'local';
    private const DIR        = 'support-bot';

    public const ALLOWED_EXT = ['txt', 'md', 'markdown', 'text', 'csv', 'log', 'html', 'htm', 'pdf', 'docx'];

    /** Ingest an uploaded file. Returns the persisted source (status ready|error). */
    public function ingestUpload(UploadedFile $file, ?string $label = null, ?int $adminId = null): SupportBotSource
    {
        $ext = strtolower($file->getClientOriginalExtension());
        $name = $label ?: $file->getClientOriginalName();

        $source = SupportBotSource::create([
            'kind'       => 'file',
            'label'      => mb_substr($name, 0, 200),
            'status'     => 'pending',
            'created_by' => $adminId,
        ]);

        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            return $this->fail($source, 'Unsupported file type. Allowed: ' . implode(', ', self::ALLOWED_EXT) . '.');
        }

        try {
            $path = $file->store(self::DIR, self::DISK);
        } catch (\Throwable $e) {
            return $this->fail($source, 'Could not store the upload.');
        }
        $source->source_path = $path;

        [$text, $err] = $this->extractFileText(Storage::disk(self::DISK)->path($path), $ext);
        if ($err) return $this->fail($source, $err);

        return $this->finish($source, $text);
    }

    /** Ingest a public http(s) URL (SSRF-guarded). */
    public function ingestUrl(string $url, ?string $label = null, ?int $adminId = null): SupportBotSource
    {
        $source = SupportBotSource::create([
            'kind'       => 'url',
            'label'      => mb_substr($label ?: $url, 0, 200),
            'url'        => mb_substr($url, 0, 1024),
            'status'     => 'pending',
            'created_by' => $adminId,
        ]);

        [$ok, $text, $err] = $this->fetchUrlAsText($url);
        if (!$ok) return $this->fail($source, $err);

        return $this->finish($source, (string) $text);
    }

    /**
     * Ingest a ZIP of docs (chatdoc's "folder of pages" format). Extracts the
     * archive, ingests every supported file inside as its OWN source/article
     * (skipping assets/images/css/js), and returns the created sources. Zip-slip
     * safe (rejects entries that escape the extract dir); capped at 500 files.
     *
     * @return array<int, SupportBotSource>
     */
    public function ingestZip(UploadedFile $file, ?int $adminId = null): array
    {
        if (!class_exists(\ZipArchive::class)) return [];

        $tmp = storage_path('app/' . self::DIR . '-zip-' . uniqid());
        @mkdir($tmp, 0775, true);

        $zip = new \ZipArchive();
        if ($zip->open($file->getRealPath()) !== true) {
            $this->rrmdir($tmp);
            return [];
        }
        // Zip-slip guard: refuse any entry whose resolved path escapes $tmp.
        $realTmp = realpath($tmp) ?: $tmp;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $dest = $tmp . DIRECTORY_SEPARATOR . $name;
            $parent = dirname($dest);
            if (!is_dir($parent)) @mkdir($parent, 0775, true);
            $resolvedParent = realpath($parent);
            if ($resolvedParent === false || strncmp($resolvedParent, $realTmp, strlen($realTmp)) !== 0) {
                continue; // path traversal attempt — skip this entry
            }
        }
        $zip->extractTo($tmp);
        $zip->close();

        $created = [];
        $count = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tmp, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($count >= 500) break;
            if (!$f->isFile()) continue;
            $ext = strtolower($f->getExtension());
            if (!in_array($ext, self::ALLOWED_EXT, true)) continue; // skip assets (png/css/js…)

            [$text, $err] = $this->extractFileText($f->getRealPath(), $ext);
            if ($err || trim((string) $text) === '') continue;

            $label = $this->labelFromFile($f->getRealPath(), $ext);
            $src = SupportBotSource::create([
                'kind'       => 'file',
                'label'      => mb_substr($label, 0, 200),
                'status'     => 'pending',
                'created_by' => $adminId,
            ]);
            $created[] = $this->finish($src, $text);
            $count++;
        }

        $this->rrmdir($tmp);
        return $created;
    }

    /** A human label for a file inside a zip: HTML <title>/<h1>, else the filename. */
    private function labelFromFile(string $path, string $ext): string
    {
        if (in_array($ext, ['html', 'htm'], true)) {
            $raw = (string) @file_get_contents($path);
            if (preg_match('#<title[^>]*>(.*?)</title>#is', $raw, $m) && trim($m[1]) !== '') {
                return trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
            if (preg_match('#<h1[^>]*>(.*?)</h1>#is', $raw, $m) && trim(strip_tags($m[1])) !== '') {
                return trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
        }
        $base = pathinfo($path, PATHINFO_FILENAME);
        return ucfirst(trim(str_replace(['-', '_'], ' ', $base)));
    }

    /** Recursively remove a temp directory. */
    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getRealPath()) : @unlink($item->getRealPath());
        }
        @rmdir($dir);
    }

    /** Ingest a raw text/markdown snippet pasted by the admin. */
    public function ingestText(string $text, string $label, ?int $adminId = null): SupportBotSource
    {
        $source = SupportBotSource::create([
            'kind'       => 'text',
            'label'      => mb_substr($label, 0, 200),
            'status'     => 'pending',
            'created_by' => $adminId,
        ]);

        return $this->finish($source, $text);
    }

    /**
     * Rebuild a source's chunks from its stored extracted text (idempotent).
     * URL sources are re-fetched so an edited page is picked up; file/text
     * sources re-chunk the stored content.
     */
    public function reindex(SupportBotSource $source): SupportBotSource
    {
        if ($source->kind === 'url' && $source->url) {
            [$ok, $text, $err] = $this->fetchUrlAsText($source->url);
            if (!$ok) return $this->fail($source, $err);
            return $this->finish($source, (string) $text);
        }
        return $this->finish($source, (string) ($source->content ?? ''));
    }

    /** Rebuild every source. Returns the count reindexed. */
    public function reindexAll(): int
    {
        $n = 0;
        SupportBotSource::query()->orderBy('id')->each(function (SupportBotSource $s) use (&$n) {
            $this->reindex($s);
            $n++;
        });
        return $n;
    }

    // ---------------------------------------------------------------------

    /** Store extracted text + rebuild chunks in one transaction. */
    private function finish(SupportBotSource $source, string $text): SupportBotSource
    {
        $text = trim($text);
        if ($text === '') {
            return $this->fail($source, 'No readable text was found in this source.');
        }

        $chunks = $this->chunkText($text, $source->label);

        DB::transaction(function () use ($source, $text, $chunks) {
            $source->chunks()->delete();
            $source->fill([
                'content'         => $text,
                'status'          => 'ready',
                'error'           => null,
                'tokens_estimate' => (int) ceil(mb_strlen($text) / 4),
            ])->save();

            $rows = [];
            foreach ($chunks as $i => $c) {
                $rows[] = [
                    'source_id'  => $source->id,
                    'heading'    => $c['heading'] !== null ? mb_substr($c['heading'], 0, 512) : null,
                    'content'    => $c['content'],
                    'position'   => $i,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                if (count($rows) >= 200) { SupportBotChunk::insert($rows); $rows = []; }
            }
            if ($rows) SupportBotChunk::insert($rows);
        });

        return $source->refresh();
    }

    private function fail(SupportBotSource $source, ?string $error): SupportBotSource
    {
        $source->chunks()->delete();
        $source->fill(['status' => 'error', 'error' => $error ?: 'Ingestion failed.'])->save();
        return $source;
    }

    /**
     * Split plain text into heading-scoped, size-capped chunks. Markdown
     * headings (# .. ######) start a new section and become the chunk heading;
     * within a section, text accumulates up to MAX_CHARS and oversized blocks
     * are hard-split. The source label is the fallback heading.
     *
     * @return array<int, array{heading: ?string, content: string}>
     */
    private function chunkText(string $text, string $fallbackHeading): array
    {
        $text  = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = explode("\n", $text);

        $chunks   = [];
        $heading  = $fallbackHeading;
        $buf      = '';

        $flush = function () use (&$chunks, &$buf, &$heading) {
            $content = trim($buf);
            $buf = '';
            if ($content === '') return;
            foreach ($this->hardSplit($content) as $piece) {
                if (mb_strlen(trim($piece)) === 0) continue;
                $chunks[] = ['heading' => $heading, 'content' => trim($piece)];
            }
        };

        foreach ($lines as $line) {
            $trim = trim($line);
            if (preg_match('/^#{1,6}\s+(.+)$/u', $trim, $m)) {
                $flush();
                $heading = trim($m[1]);
                continue;
            }
            if ($buf !== '' && mb_strlen($buf) + mb_strlen($line) + 1 > self::MAX_CHARS) {
                $flush();
            }
            $buf .= $line . "\n";
        }
        $flush();

        // Drop tiny trailing scraps unless they're all we have.
        $meaningful = array_values(array_filter($chunks, fn ($c) => mb_strlen($c['content']) >= self::MIN_CHARS));
        return $meaningful ?: $chunks;
    }

    /** Hard-split a block that exceeds MAX_CHARS, preferring sentence/space breaks. */
    private function hardSplit(string $content): array
    {
        if (mb_strlen($content) <= self::MAX_CHARS) return [$content];
        $out = [];
        while (mb_strlen($content) > self::MAX_CHARS) {
            $slice = mb_substr($content, 0, self::MAX_CHARS);
            $cut   = max((int) mb_strrpos($slice, "\n"), (int) mb_strrpos($slice, '. '), (int) mb_strrpos($slice, ' '));
            if ($cut < (int) (self::MAX_CHARS * 0.5)) $cut = self::MAX_CHARS; // no good break — hard cut
            $out[] = mb_substr($content, 0, $cut);
            $content = ltrim(mb_substr($content, $cut));
        }
        if (trim($content) !== '') $out[] = $content;
        return $out;
    }

    /* --------------------------- file extraction -------------------------- */

    private function extractFileText(string $path, string $ext): array
    {
        try {
            switch ($ext) {
                case 'pdf':
                    if (!class_exists(\Smalot\PdfParser\Parser::class)) {
                        return ['', 'PDF support is not installed on this server. Paste the text into a Text source instead.'];
                    }
                    $parser = new \Smalot\PdfParser\Parser();
                    return [(string) $parser->parseFile($path)->getText(), null];

                case 'docx':
                    return [$this->docxToText($path), null];

                case 'html':
                case 'htm':
                    $raw = (string) file_get_contents($path);
                    $raw = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $raw) ?? $raw;
                    return [trim(html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8')), null];

                default: // txt, md, markdown, text, csv, log
                    return [(string) file_get_contents($path), null];
            }
        } catch (\Throwable $e) {
            Log::warning('[SUPPORT-BOT] file extract failed (' . $ext . '): ' . $e->getMessage());
            return ['', 'Could not read that file — it may be corrupt or password-protected. Try another file or paste the text.'];
        }
    }

    private function docxToText(string $path): string
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('zip extension unavailable');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('not a valid docx (zip open failed)');
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false || $xml === '') return '';

        $xml = preg_replace('#</w:p>#', "\n", $xml) ?? $xml;
        $xml = preg_replace('#<w:tab[^>]*/?>#', "\t", $xml) ?? $xml;
        $xml = preg_replace('#<w:br[^>]*/?>#', "\n", $xml) ?? $xml;
        $text = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        return trim($text);
    }

    /* ----------------------------- URL fetch ----------------------------- */

    private function fetchUrlAsText(string $url): array
    {
        try {
            $current      = $url;
            $maxRedirects = 5;
            $res          = null;
            for ($hop = 0; $hop <= $maxRedirects; $hop++) {
                $ssrfErr = $this->guardSsrf($current);
                if ($ssrfErr) return [false, null, $ssrfErr];

                $res = Http::timeout(20)
                    ->withOptions(['allow_redirects' => false])
                    ->withHeaders(['User-Agent' => 'WaDeskSupportBot/1.0'])
                    ->get($current);

                if ($res->redirect()) {
                    $loc = (string) $res->header('Location');
                    if ($loc === '') break;
                    if ($hop === $maxRedirects) return [false, null, 'too many redirects'];
                    $current = $this->resolveRedirectUrl($current, $loc);
                    continue;
                }
                break;
            }
            if (!$res || !$res->ok()) {
                return [false, null, 'fetch failed: HTTP ' . ($res ? $res->status() : 0)];
            }
            $html = (string) $res->body();
            $html = preg_replace('#<script\b[^>]*>(.*?)</script>#is', ' ', $html) ?? $html;
            $html = preg_replace('#<style\b[^>]*>(.*?)</style>#is',  ' ', $html) ?? $html;
            $text = html_entity_decode(strip_tags($html));
            $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
            $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
            $text = trim($text);
            if ($text === '') return [false, null, 'fetched page contained no text'];
            if (mb_strlen($text) > 200000) $text = mb_substr($text, 0, 200000);
            return [true, $text, null];
        } catch (\Throwable $e) {
            return [false, null, 'fetch exception: ' . $e->getMessage()];
        }
    }

    private function resolveRedirectUrl(string $base, string $location): string
    {
        $location = trim($location);
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $location)) return $location;
        $b = parse_url($base);
        if (!$b || empty($b['scheme']) || empty($b['host'])) return $location;
        $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if ($location === '') return $origin;
        if ($location[0] === '/') {
            if (isset($location[1]) && $location[1] === '/') return $b['scheme'] . ':' . $location;
            return $origin . $location;
        }
        $path = $b['path'] ?? '/';
        $dir  = substr($path, 0, strrpos($path, '/') + 1) ?: '/';
        return $origin . $dir . $location;
    }

    private function guardSsrf(string $url): ?string
    {
        $p = parse_url($url);
        if (!$p || empty($p['scheme']) || empty($p['host'])) return 'invalid URL';
        $scheme = strtolower($p['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') return "scheme {$scheme} not allowed (use http or https)";
        $host = strtolower($p['host']);
        if (str_contains($host, 'metadata.') || str_ends_with($host, '.internal')) return 'metadata host not allowed';

        $ips = @gethostbynamel($host) ?: [];
        if (filter_var($host, FILTER_VALIDATE_IP)) $ips = [$host];
        if (empty($ips)) {
            $aaaa = @dns_get_record($host, DNS_AAAA);
            foreach ((array) $aaaa as $rec) {
                if (!empty($rec['ipv6'])) $ips[] = $rec['ipv6'];
            }
        }
        if (empty($ips)) return 'hostname did not resolve to a public IP';
        foreach ($ips as $ip) {
            $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            if ($public === false) return "host resolves to private/reserved IP ({$ip}) — refusing to fetch";
        }
        return null;
    }
}
