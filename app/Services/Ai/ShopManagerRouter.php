<?php

namespace App\Services\Ai;

use App\Models\AiAgent;
use App\Models\Conversation;

/**
 * One Shop Manager on the outside; catalog / orders / COD / returns as jobs.
 * Classifies the latest inbound, stores state on routing_meta, and injects
 * a specialist prompt so the same AiAgent stays one voice across text + voice.
 */
class ShopManagerRouter
{
    public const META_KEY = 'shop_manager';

    public static function enabled(AiAgent $agent): bool
    {
        if (!empty($agent->shop_router)) {
            return true;
        }
        $prompt = (string) ($agent->system_prompt ?? '');

        return str_contains(mb_strtolower($prompt), '[shop-manager]');
    }

    /**
     * @return array{intent: string, sub: string, specialist: string, canned: ?string, handoff_after_reply: bool}
     */
    public function apply(Conversation $convo, string $text, bool $hasPhoto = false): array
    {
        $state = $this->stateFrom($convo);
        $intent = $this->classify($text, $state, $hasPhoto);
        if ($intent === 'menu_pick') {
            $intent = $this->menuPick($text);
        }

        $sub = $this->subFor($intent, $state['sub']);
        $orderId = $this->looksOrderId($text) ? $this->extractOrderId($text) : ($state['order_id'] ?? '');

        $next = [
            'sub' => $sub,
            'intent' => $intent,
            'order_id' => $orderId,
            'turns' => (int) ($state['turns'] ?? 0) + 1,
            'handed_off' => $intent === 'human' ? true : (bool) ($state['handed_off'] ?? false),
            'handoff_after_reply' => $intent === 'human',
        ];
        $this->persist($convo, $next);

        $canned = $intent === 'human'
            ? 'Pulling a teammate in. Stay in this chat — they’ll see everything we just said.'
            : null;

        return [
            'intent' => $intent,
            'sub' => $sub,
            'specialist' => $this->specialistPrompt($intent, $orderId, $hasPhoto),
            'canned' => $canned,
            'handoff_after_reply' => $intent === 'human',
        ];
    }

    public function consumeHandoffAfterReply(Conversation $convo): bool
    {
        $meta = is_array($convo->routing_meta) ? $convo->routing_meta : [];
        $shop = is_array($meta[self::META_KEY] ?? null) ? $meta[self::META_KEY] : [];
        if (empty($shop['handoff_after_reply'])) {
            return false;
        }
        $shop['handoff_after_reply'] = false;
        $meta[self::META_KEY] = $shop;
        $convo->forceFill(['routing_meta' => $meta])->save();

        return true;
    }

    public function classify(string $text, array $st, bool $hasPhoto = false): string
    {
        $t = mb_strtolower(trim($text));
        if (!empty($st['handed_off'])) {
            return 'human';
        }
        if (preg_match('/\b(human|agent|person|manager|complaint|lawyer|stupid bot)\b/u', $t)) {
            return 'human';
        }
        if (preg_match('/^[1-9]$|^10$|^🔟$/u', $t) || preg_match('/^[1-9][\).]$/u', $t)) {
            return 'menu_pick';
        }
        if (preg_match('/\b(menu|options|help)\b/u', $t) && mb_strlen($t) < 24) {
            return 'menu';
        }
        if (preg_match('/\b(hi|hello|hey|salam|asalam|aoa)\b/u', $t) && mb_strlen($t) < 40) {
            return 'hi';
        }
        if (preg_match('/^(\?+|ok+|h+m+|lol+|haha+|u+|😂+|👍+)$/iu', $t)) {
            return 'filler';
        }
        if (preg_match('/\b(track|where.*order|wismo|status|shipped)\b/u', $t)
            || ($this->looksOrderId($t) && ($st['sub'] ?? '') === 'orders')) {
            return 'track';
        }
        if (preg_match('/\b(cod|cash on delivery)\b/u', $t)) {
            return 'cod';
        }
        if (preg_match('/\b(return|refund|exchange|wrong size)\b/u', $t)) {
            return 'returns';
        }
        if (preg_match('/\b(size|shipping|delivery time|chest)\b/u', $t)) {
            return 'size';
        }
        if (preg_match('/\b(stock|available|in stock)\b/u', $t)) {
            return 'stock';
        }
        if (preg_match('/\b(pay|payment|jazz|easypaisa|card)\b/u', $t)) {
            return 'pay';
        }
        if (preg_match('/\b(store|address|map|location|find us)\b/u', $t)) {
            return 'store';
        }
        if (preg_match('/\b(checkout|cart|buy now)\b/u', $t)) {
            return 'checkout';
        }
        if ($hasPhoto || preg_match('/\b(price|how much|cover|product|catalog|sku)\b/u', $t)) {
            return 'catalog';
        }
        if ($this->looksOrderId($t)) {
            return ($st['sub'] ?? '') === 'returns' ? 'returns' : 'track';
        }
        if (($st['sub'] ?? '') === 'orders' && ($this->looksOrderId($t) || preg_match('/\b(address|cancel|courier|packed)\b/u', $t))) {
            return 'track';
        }
        if (($st['sub'] ?? '') === 'returns' && preg_match('/\b(wrong|damaged|size|exchange|refund|pickup|reason|changed mind)\b/u', $t)) {
            return 'returns';
        }
        if (($st['sub'] ?? '') === 'cod' && preg_match('/\b(confirm|address|cancel|cash)\b/u', $t)) {
            return 'cod';
        }

        return 'chitchat';
    }

    public function menuPick(string $text): string
    {
        $d = preg_replace('/\D+/', '', $text) ?? '';
        $n = (int) $d;
        $map = [
            1 => 'catalog', 2 => 'track', 3 => 'cod', 4 => 'returns', 5 => 'size',
            6 => 'stock', 7 => 'checkout', 8 => 'pay', 9 => 'store', 10 => 'human',
        ];

        return $map[$n] ?? 'menu';
    }

    private function subFor(string $intent, string $current): string
    {
        return match ($intent) {
            'catalog', 'size', 'stock', 'checkout' => 'catalog',
            'track', 'pay' => 'orders',
            'cod' => 'cod',
            'returns' => 'returns',
            'human' => 'human',
            'hi', 'menu', 'filler', 'chitchat', 'store' => 'manager',
            default => $current ?: 'manager',
        };
    }

    private function specialistPrompt(string $intent, string $orderId, bool $hasPhoto): string
    {
        $oid = $orderId !== '' ? " Known order number: {$orderId}." : '';
        $photo = $hasPhoto ? ' The customer sent a photo — talk about what it likely is, or ask for SKU/name if unsure.' : '';

        $jobs = [
            'hi' => 'Manager. Short WhatsApp greeting in their language. Offer the 1–10 shop menu (or “View menu”) once, not every ping. Do not dump policy.',
            'menu' => 'Manager. Send the numbered 1–10 shop menu (browse, track, COD, return, size, stock, checkout, payment, store, person).',
            'filler' => 'Manager. One short line. Ask product, order number, or menu. Do not restart a full script.',
            'catalog' => 'Catalog specialist. Answer from trained catalog/knowledge only. Never invent stock or prices. If they want to buy, send them to checkout/catalog, not a long chat.'.$photo,
            'track' => 'Order desk. Ask for an order number if missing.'.$oid.' Do not invent tracking numbers. Offer status / address change / cancel. Handoff lost parcels.',
            'cod' => 'COD specialist. Confirm, change address, or cancel.'.$oid.' WhatsApp allows max 3 buttons if you offer taps.',
            'returns' => 'Returns specialist. Capture order number, item, reason. Do not process refunds. A human completes refunds.'.$oid,
            'size' => 'Catalog specialist for size and shipping times. Use knowledge; if missing, say you will check with the team.',
            'stock' => 'Catalog specialist for availability. Use knowledge only.'.$photo,
            'checkout' => 'Checkout closer. Help finish a saved cart. Utility tone, not ads.',
            'pay' => 'Order desk for payment matching.'.$oid.' Team sends pay links; you do not claim a payment cleared unless knowledge says so.',
            'store' => 'Manager. Share store address/hours from knowledge. Do not invent a pin.',
            'human' => 'You already decided a teammate must join. One short line, then stop.',
            'chitchat' => 'Manager. One human beat for off-topic or silly questions, then a useful shop question. Never say you are AI.',
        ];

        $job = $jobs[$intent] ?? $jobs['chitchat'];

        return "Shop Manager router — the customer still talks to ONE shop person. Current job: {$job}\n"
            ."Voice: 1–3 WhatsApp lines. Match their language. Never say you are AI, a bot, or a language model.\n"
            .'If they typed, reply in text. If this turn is a voice note, keep the reply short enough to speak (~12 seconds).';
    }

    private function stateFrom(Conversation $convo): array
    {
        $meta = is_array($convo->routing_meta) ? $convo->routing_meta : [];
        $shop = is_array($meta[self::META_KEY] ?? null) ? $meta[self::META_KEY] : [];

        return [
            'sub' => (string) ($shop['sub'] ?? 'manager'),
            'order_id' => (string) ($shop['order_id'] ?? ''),
            'turns' => (int) ($shop['turns'] ?? 0),
            'handed_off' => (bool) ($shop['handed_off'] ?? false),
        ];
    }

    private function persist(Conversation $convo, array $shop): void
    {
        $meta = is_array($convo->routing_meta) ? $convo->routing_meta : [];
        $meta[self::META_KEY] = $shop;
        $convo->forceFill(['routing_meta' => $meta])->save();
    }

    private function looksOrderId(string $t): bool
    {
        return (bool) preg_match('/\b(WD-?\d{3,}|#?\d{4,8})\b/i', $t);
    }

    private function extractOrderId(string $t): string
    {
        if (!preg_match('/\b(WD-?\d{3,}|\d{4,8})\b/i', $t, $m)) {
            return '';
        }
        $raw = strtoupper($m[1]);
        if (str_starts_with($raw, 'WD')) {
            return (string) preg_replace('/^WD-?/', 'WD-', $raw);
        }

        return 'WD-'.$raw;
    }
}
