<?php

namespace App\Services\WhatsAppCatalog;

use App\Models\WaCatalog;
use App\Models\WaProduct;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Catalog concierge — turns a free-text inbound ("got any red shoes under
 * 2000?") into an instant Multi-Product Message of the best matches. The
 * customer browses and adds to cart without ever leaving the chat, and the
 * merchant never lifts a finger.
 *
 * Intent is parsed deterministically (price range + keywords) so it works
 * with zero AI spend and is fully testable offline; when a workspace has an
 * AI key configured this is where an LLM refiner would slot in (the parser
 * returns the same shape either way).
 *
 * Hard-gated for safety:
 *   • OFF by default — only runs when concierge / share-on-keyword /
 *     share-on-hello is explicitly true (WaCatalog.meta_json or
 *     workspaces.catalog_auto for Unofficial API workspaces).
 *   • 30s per-sender cooldown so a chatty customer can't trigger a flood.
 *   • Stays silent on no match unless the merchant opts into a reply.
 *   • Every failure is swallowed — the concierge must never break inbound.
 *
 * Unofficial API: there is no Meta Commerce catalog. We send a native
 * product carousel (or the shop link) from the workspace's main device.
 */
class CatalogConciergeService
{
    /** Words that carry no product meaning — stripped before matching. */
    private const STOPWORDS = [
        'show', 'me', 'want', 'need', 'looking', 'look', 'for', 'do', 'you', 'have', 'has',
        'any', 'the', 'a', 'an', 'is', 'are', 'got', 'get', 'some', 'please', 'pls', 'hi',
        'hello', 'hey', 'and', 'or', 'to', 'with', 'i', 'my', 'we', 'us', 'price', 'priced',
        'cost', 'budget', 'under', 'below', 'above', 'over', 'less', 'more', 'than', 'between',
        'around', 'near', 'about', 'upto', 'rs', 'inr', 'usd', 'send', 'share', 'catalog',
        'product', 'products', 'item', 'items', 'buy', 'purchase', 'order', 'available',
    ];

    public function handleInbound(int $workspaceId, string $phone, string $text): bool
    {
        $phone = preg_replace('/\D+/', '', $phone);
        $text  = trim($text);
        if ($phone === '' || mb_strlen($text) < 2) return false;

        try {
            $meta = $this->settings($workspaceId);
            $shareKeyword = ($meta['share_on_keyword'] ?? false) === true;
            $shareHello   = ($meta['share_on_hello'] ?? false) === true;
            $concierge    = ($meta['concierge_enabled'] ?? false) === true;
            if (!$shareKeyword && !$shareHello && !$concierge) return false;

            if (!Cache::add("catalog_concierge:{$workspaceId}:{$phone}", 1, 30)) {
                return false;
            }

            if ($shareKeyword && $this->looksLikeCatalogRequest($text)) {
                return $this->shareFullCatalog($workspaceId, $phone, $meta);
            }

            if ($shareHello && $this->looksLikeGreeting($text)) {
                if (!Cache::add("catalog_hello:{$workspaceId}:{$phone}", 1, 86400)) {
                    return false;
                }
                return $this->shareFullCatalog($workspaceId, $phone, $meta);
            }

            if (!$concierge) return false;

            $intent = $this->extractIntent($text);
            if (empty($intent['keywords']) && $intent['price_min'] === null && $intent['price_max'] === null) {
                return false;
            }

            $max      = max(1, min(30, (int) ($meta['concierge_max'] ?? 10)));
            $products = $this->search($workspaceId, $intent, $max);

            if ($products->isEmpty()) {
                if (($meta['concierge_reply_on_empty'] ?? false) === true) {
                    $this->sendText($workspaceId, $phone, (string) ($meta['concierge_empty_text']
                        ?? "Sorry, I couldn't find a match for that. Try a different keyword or budget."));
                    return true;
                }
                return false;
            }

            $header = (string) ($meta['concierge_header'] ?? "Here's what I found");
            $body   = $this->bodyLine($products->count(), $text);

            return $this->deliverProducts($workspaceId, $phone, $products, [
                'header' => $header,
                'body'   => $body,
                'footer' => !empty($meta['concierge_footer']) ? (string) $meta['concierge_footer'] : '',
            ]);
        } catch (Throwable $e) {
            Log::warning('[CATALOG-CONCIERGE] handleInbound failed (ws ' . $workspaceId . '): ' . $e->getMessage());
            return false;
        }
    }

    /** Send the workspace catalog (carousel or shop link) to a buyer. */
    public function shareFullCatalog(int $workspaceId, string $phone, ?array $meta = null): bool
    {
        $phone = preg_replace('/\D+/', '', $phone);
        if ($phone === '') return false;
        $meta = $meta ?? $this->settings($workspaceId);
        $max = max(1, min(30, (int) ($meta['concierge_max'] ?? 10)));
        $products = WaProduct::where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->where('in_stock', true)
            ->orderByDesc('updated_at')
            ->limit($max)
            ->get();

        $header = (string) ($meta['share_header'] ?? $meta['concierge_header'] ?? 'Our catalog');
        $body   = (string) ($meta['share_body'] ?? 'Tap a product to learn more');

        return $this->deliverProducts($workspaceId, $phone, $products, [
            'header' => $header,
            'body'   => $body,
            'footer' => '',
        ]);
    }

    private function settings(int $workspaceId): array
    {
        $fromWs = [];
        try {
            $fromWs = \App\Models\Workspace::query()->find($workspaceId)?->catalog_auto ?? [];
        } catch (Throwable $e) {}
        $fromWs = is_array($fromWs) ? $fromWs : [];

        $catalog = WaCatalog::where('workspace_id', $workspaceId)->first();
        $fromCat = is_array($catalog?->meta_json) ? $catalog->meta_json : [];

        return array_merge($fromWs, $fromCat);
    }

    private function looksLikeCatalogRequest(string $text): bool
    {
        $low = Str::lower($text);
        return (bool) preg_match('/\b(catalog|catalogue|menu|price\s*list|pricelist|products?|shop|store)\b/u', $low);
    }

    private function looksLikeGreeting(string $text): bool
    {
        $low = Str::lower(trim($text));
        return (bool) preg_match('/^(hi|hello|hey|salam|assalamu?|hola|ok|good\s+(morning|afternoon|evening))\b/u', $low);
    }

    private function deliverProducts(int $workspaceId, string $phone, $products, array $opts): bool
    {
        $catalog = WaCatalog::where('workspace_id', $workspaceId)->first();
        if ($catalog && $catalog->catalog_id && $products->isNotEmpty()) {
            $retailerIds = $products
                ->map(fn ($p) => $p->meta_retailer_id ?: ($p->sku ?: 'wsn-' . $p->id))
                ->values()->all();
            WhatsAppCatalogFactory::forWorkspace($workspaceId)->sendMPM(
                $phone,
                (string) ($opts['header'] ?? 'Our catalog'),
                (string) ($opts['body'] ?? ''),
                [['title' => 'Products', 'product_retailer_ids' => $retailerIds]],
                $opts['footer'] ?? null,
            );
            return true;
        }

        $device = $this->mainBaileysDevice($workspaceId);
        if (!$device) {
            $this->sendText($workspaceId, $phone, (string) ($opts['body'] ?: 'Browse our products.'));
            return true;
        }

        $svc = BaileysCatalogService::make();
        $shop = \App\Models\WaStorefront::query()
            ->where('workspace_id', $workspaceId)
            ->orderByDesc('enabled')
            ->orderByDesc('id')
            ->first();

        if ($products->isEmpty()) {
            if ($shop) {
                $svc->sendStorefrontLink($device, $phone, $shop->public_url, $opts['body'] ?: 'Browse our shop:');
                return true;
            }
            $this->sendText($workspaceId, $phone, 'We will send our catalog shortly.');
            return true;
        }

        try {
            $svc->sendCarousel($device, $phone, $products, $opts);
        } catch (Throwable $e) {
            if ($shop) {
                $svc->sendStorefrontLink($device, $phone, $shop->public_url, $opts['body'] ?: 'Browse our shop:');
            } else {
                throw $e;
            }
        }
        return true;
    }

    private function mainBaileysDevice(int $workspaceId): ?\App\Models\Device
    {
        $key = '';
        try {
            $key = (string) (\App\Models\Workspace::query()->find($workspaceId)?->catalog_sender ?? '');
        } catch (Throwable $e) {}
        if (str_starts_with($key, 'baileys:')) {
            $id = (int) substr($key, 8);
            $d = \App\Models\Device::query()
                ->forWorkspace($workspaceId)
                ->where('id', $id)
                ->where('status', 'connected')
                ->first();
            if ($d) return $d;
        }
        return \App\Models\Device::query()
            ->forWorkspace($workspaceId)
            ->where('status', 'connected')
            ->orderByDesc('active')
            ->first();
    }

    /**
     * Parse a natural-language product query into keywords + a price band.
     * Currency-agnostic: a bare number after "under/below/over/between" is
     * treated as major units (× 100 to compare against price_minor).
     *
     * @return array{keywords:string[],price_min:?int,price_max:?int}
     */
    public function extractIntent(string $text): array
    {
        $low = Str::lower($text);

        $priceMin = null;
        $priceMax = null;

        // "between 1000 and 2000" / "1000 to 2000" / "1000-2000"
        if (preg_match('/(\d[\d,]*)\s*(?:-|to|and)\s*(\d[\d,]*)/', $low, $m)) {
            $a = (int) str_replace(',', '', $m[1]);
            $b = (int) str_replace(',', '', $m[2]);
            $priceMin = min($a, $b);
            $priceMax = max($a, $b);
        } else {
            if (preg_match('/(?:under|below|less than|upto|up to|max|<=?)\s*(?:rs\.?|₹|\$|inr|usd)?\s*(\d[\d,]*)/', $low, $m)) {
                $priceMax = (int) str_replace(',', '', $m[1]);
            }
            if (preg_match('/(?:above|over|more than|from|min|>=?)\s*(?:rs\.?|₹|\$|inr|usd)?\s*(\d[\d,]*)/', $low, $m)) {
                $priceMin = (int) str_replace(',', '', $m[1]);
            }
        }

        // Keywords: drop punctuation, stopwords, pure numbers, currency tokens.
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $low, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $keywords = [];
        foreach ($tokens as $tok) {
            if (mb_strlen($tok) < 2) continue;
            if (ctype_digit($tok)) continue;
            if (in_array($tok, self::STOPWORDS, true)) continue;
            $keywords[] = $tok;
        }
        $keywords = array_values(array_unique($keywords));

        return ['keywords' => $keywords, 'price_min' => $priceMin, 'price_max' => $priceMax];
    }

    /**
     * Find the best-matching active products for an intent. Matches any
     * keyword across name/description/category/brand, applies the price
     * band, then ranks by how many keywords hit the name/category (strong
     * signal) over the description (weak).
     *
     * @param array{keywords:string[],price_min:?int,price_max:?int} $intent
     */
    public function search(int $workspaceId, array $intent, int $limit = 10): \Illuminate\Support\Collection
    {
        $q = WaProduct::where('workspace_id', $workspaceId)->where('status', 'active');

        if ($intent['price_min'] !== null) $q->where('price_minor', '>=', $intent['price_min'] * 100);
        if ($intent['price_max'] !== null) $q->where('price_minor', '<=', $intent['price_max'] * 100);

        $keywords = $intent['keywords'];
        if (!empty($keywords)) {
            $q->where(function ($outer) use ($keywords) {
                foreach ($keywords as $kw) {
                    $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $kw) . '%';
                    $outer->orWhere('name', 'like', $like)
                          ->orWhere('description', 'like', $like)
                          ->orWhere('category', 'like', $like)
                          ->orWhere('brand', 'like', $like);
                }
            });
        }

        // Pull a generous candidate set, then rank in PHP for relevance.
        $candidates = $q->limit(max($limit * 3, 60))->get();

        if (empty($keywords)) {
            return $candidates->sortByDesc('updated_at')->take($limit)->values();
        }

        return $candidates
            ->map(function ($p) use ($keywords) {
                $strong = Str::lower(($p->name ?? '') . ' ' . ($p->category ?? '') . ' ' . ($p->brand ?? ''));
                $weak   = Str::lower((string) ($p->description ?? ''));
                $score  = 0;
                foreach ($keywords as $kw) {
                    if (str_contains($strong, $kw)) $score += 3;
                    elseif (str_contains($weak, $kw)) $score += 1;
                }
                $p->setAttribute('_score', $score);
                return $p;
            })
            ->sortByDesc('_score')
            ->take($limit)
            ->values();
    }

    private function bodyLine(int $count, string $query): string
    {
        $q = Str::limit(trim($query), 60);
        return $count === 1
            ? "I found 1 match for \"{$q}\":"
            : "I found {$count} matches for \"{$q}\":";
    }

    private function sendText(int $workspaceId, string $phone, string $text): void
    {
        try {
            app(\App\Services\WhatsAppDispatcher::class)->sendRaw(
                ['to_number' => $phone, 'body' => $text, 'workspace_id' => $workspaceId],
                null, 'W',
            );
        } catch (Throwable $e) {
            Log::warning('[CATALOG-CONCIERGE] empty-reply send failed: ' . $e->getMessage());
        }
    }
}
