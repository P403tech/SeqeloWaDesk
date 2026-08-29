<?php

namespace Database\Seeders;

use App\Models\FlowTemplate;
use Illuminate\Database\Seeder;

/**
 * Industry gallery blueprints on /flows ("Start from a template").
 *
 * Copy is Meta-policy-safe: inbound keyword triggers (customer-initiated
 * 24h session), UTILITY-style wording, no unsolicited marketing. A
 * `template` node is included where an outside-window follow-up needs a
 * Meta-approved WhatsApp template the tenant maps in the builder.
 *
 * Run: php artisan db:seed --class=Database\\Seeders\\FlowTemplateSeeder
 */
class FlowTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            $this->supportDesk(),
            $this->faqMenu(),
            $this->optInLeadCapture(),
            $this->shopSelfService(),
            $this->orderStatus(),
            $this->whatsappCatalogShop(),
            $this->shopifyStorefront(),
            $this->wooStorefront(),
            $this->abandonedCart(),
            $this->returnsExchange(),
            $this->codConfirm(),
            $this->productHelp(),
            $this->appointmentBooking(),
            $this->restaurantDesk(),
            $this->courseEnquiry(),
            $this->loanStatus(),
            $this->tripItinerary(),
            $this->saasOnboarding(),
            $this->agencyIntake(),
            $this->propertyViewing(),
            $this->salonBooking(),
            $this->parcelTracking(),
        ];

        foreach ($templates as $i => $tpl) {
            $row = FlowTemplate::query()->where('name', $tpl['name'])->first() ?: new FlowTemplate();
            $row->fill([
                'name'        => $tpl['name'],
                'description' => $tpl['description'],
                'flow_type'   => 'chat',
                'category'    => $tpl['category'],
                'flow_data'   => $tpl['graph'],
                'is_active'   => true,
                'sort_order'  => $i + 1,
            ]);
            $row->save();
            $this->command?->info('  • '.$tpl['name'].' ('.count($tpl['graph']['flowNodes']).' steps)');
        }

        FlowTemplate::query()->where('name', 'Shop self-service (list)')->delete();

        $this->command?->info('[FlowTemplate] '.count($templates).' industry Meta-compliant chat templates ready.');
    }

    private function node(int $i, string $type, string $id, array $data, array $extra = []): array
    {
        return array_merge([
            'id'   => $id,
            'type' => $type,
            'x'    => 80 + $i * 40,
            'y'    => 80,
            'data' => $data,
        ], $extra);
    }

    private function edge(string $from, ?string $handle, string $to): array
    {
        $e = [
            'id'     => 'e_'.$from.'_'.($handle ?: 'out').'_'.$to,
            'source' => $from,
            'target' => $to,
        ];
        if ($handle !== null) {
            $e['sourceHandle'] = $handle;
        }

        return $e;
    }

    private function graph(array $nodes, array $edges): array
    {
        return [
            'flowNodes' => $this->layoutNodes($nodes, $edges),
            'flowEdges' => $edges,
            'vars'      => [],
        ];
    }

    /** p0, p1, … then out, then yes/purchased, then no/abandoned. */
    private function handleSortKey(?string $handle): int
    {
        $h = $handle ?: 'out';
        if (preg_match('/^p(\d+)$/', $h, $m)) {
            return (int) $m[1];
        }
        return match ($h) {
            'out' => 50,
            'yes', 'purchased', 'created', 'booked', 'submitted' => 80,
            'no', 'abandoned', 'error', 'else', 'nomatch', 'timeout', 'no_slots' => 81,
            default => 60,
        };
    }

    /**
     * Place templates like the mockup: trunk on the left, branch hub in the
     * middle, one horizontal lane per menu port so wires do not pile up.
     */
    private function layoutNodes(array $nodes, array $edges): array
    {
        if ($nodes === []) {
            return $nodes;
        }

        $out = [];
        foreach ($edges as $e) {
            $src = (string) ($e['source'] ?? '');
            $tgt = (string) ($e['target'] ?? '');
            if ($src === '' || $tgt === '') {
                continue;
            }
            $out[$src][] = [
                'handle' => (string) ($e['sourceHandle'] ?? 'out'),
                'target' => $tgt,
            ];
        }

        $start = null;
        foreach ($nodes as $n) {
            if (! empty($n['isStart']) || ($n['type'] ?? '') === 'trigger') {
                $start = (string) $n['id'];
                break;
            }
        }
        $start = $start ?: (string) $nodes[0]['id'];

        $hub = $start;
        $guard = 0;
        while ($guard++ < 80) {
            $outs = $out[$hub] ?? [];
            $handles = array_unique(array_column($outs, 'handle'));
            $targets = array_unique(array_column($outs, 'target'));
            if (count($handles) > 1 || count($targets) > 1 || $outs === []) {
                break;
            }
            $next = $outs[0]['target'];
            if ($next === $hub) {
                break;
            }
            $hub = $next;
        }

        $trunk = [];
        $cur = $start;
        $seen = [];
        while ($cur && ! isset($seen[$cur])) {
            $seen[$cur] = true;
            $trunk[] = $cur;
            if ($cur === $hub) {
                break;
            }
            $outs = $out[$cur] ?? [];
            if ($outs === []) {
                break;
            }
            $cur = $outs[0]['target'];
        }

        $dx = 360;
        $dy = 230;
        $pos = [];
        $placed = [];

        $hubOuts = $out[$hub] ?? [];
        usort($hubOuts, fn ($a, $b) => $this->handleSortKey($a['handle']) <=> $this->handleSortKey($b['handle']));

        $hubX = 80 + max(0, count($trunk) - 1) * $dx;
        $cursorY = 80.0;

        foreach ($hubOuts as $o) {
            $target = $o['target'];
            if (isset($placed[$target]) || $target === $hub) {
                continue;
            }
            $before = $placed;
            $this->layoutPlaceTree($target, $hubX + $dx, $cursorY, $placed, $pos, $out, $hub);
            $newMax = $cursorY;
            foreach ($placed as $id => $_) {
                if (! isset($before[$id]) && isset($pos[$id])) {
                    $newMax = max($newMax, (float) $pos[$id]['y']);
                }
            }
            $cursorY = $newMax + $dy;
        }

        $branchYs = array_column($pos, 'y');
        $minY = $branchYs === [] ? 80.0 : (float) min($branchYs);
        $maxY = $branchYs === [] ? 80.0 : (float) max($branchYs);
        $trunkY = $minY + max(0.0, (($maxY - $minY) - 360) / 2);

        foreach ($trunk as $i => $id) {
            if (! isset($placed[$id])) {
                $placed[$id] = true;
            }
            $pos[$id] = ['x' => 80 + $i * $dx, 'y' => $trunkY];
        }

        $col = 0;
        foreach ($nodes as $n) {
            $id = (string) $n['id'];
            if (isset($pos[$id])) {
                continue;
            }
            $pos[$id] = [
                'x' => 80 + ($col % 4) * $dx,
                'y' => $cursorY + intdiv($col, 4) * $dy,
            ];
            $col++;
        }

        foreach ($nodes as &$n) {
            $id = (string) $n['id'];
            if (! isset($pos[$id])) {
                continue;
            }
            $n['x'] = (int) round($pos[$id]['x']);
            $n['y'] = (int) round($pos[$id]['y']);
        }
        unset($n);

        return $nodes;
    }

    private function layoutPlaceTree(
        string $id,
        float $x,
        float $y,
        array &$placed,
        array &$pos,
        array $out,
        string $hubId,
    ): void {
        if (isset($placed[$id]) || $id === $hubId) {
            return;
        }
        $placed[$id] = true;
        $pos[$id] = ['x' => $x, 'y' => $y];

        $kids = [];
        foreach ($out[$id] ?? [] as $o) {
            if ($o['target'] === $hubId) {
                continue;
            }
            if (! in_array($o['target'], $kids, true)) {
                $kids[] = $o['target'];
            }
        }
        $unplaced = [];
        foreach ($kids as $k) {
            if (! isset($placed[$k])) {
                $unplaced[] = $k;
            }
        }
        foreach ($unplaced as $i => $kid) {
            $this->layoutPlaceTree($kid, $x + 360, $y + $i * 230, $placed, $pos, $out, $hubId);
        }
    }

    /** Customer-initiated support — session messages only. */
    private function supportDesk(): array
    {
        $i = 0;
        $n = [];
        $n[] = $this->node($i++, 'trigger', 'trigger', [
            'kind' => 'keyword', 'keywordMode' => 'keywords',
            'keywords' => 'help, support, hi, hello',
            'channel' => 'chat', 'deviceId' => '',
        ], ['isStart' => true]);
        $n[] = $this->node($i++, 'message', 'welcome', [
            'text' => "Hi {{name}} — thanks for messaging us.\nHow can we help you today?",
        ]);
        $n[] = $this->node($i++, 'buttons', 'menu', [
            'prompt'  => 'Choose an option:',
            'options' => ['Order help', 'Talk to a person', 'Something else'],
            'var'     => 'support_topic',
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_order', [
            'prompt' => 'Please reply with your order number so we can look it up.',
            'var'    => 'order_id', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'message', 'order_ack', [
            'text' => 'Thanks. We have order {{order_id}} and a teammate will follow up in this chat.',
        ]);
        $n[] = $this->node($i++, 'assign', 'assign_agent', [
            'team' => '', 'userId' => '', 'message' => 'Inbound support — order {{order_id}}',
        ]);
        $n[] = $this->node($i++, 'message', 'handoff', [
            'text' => 'Connecting you with a teammate now. Please stay in this chat.',
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_detail', [
            'prompt' => 'Please describe what you need help with in one message.',
            'var'    => 'support_detail', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'assign', 'assign_other', [
            'team' => '', 'userId' => '', 'message' => 'Support: {{support_detail}}',
        ]);
        $n[] = $this->node($i++, 'end', 'end', ['label' => 'Done']);

        $e = [
            $this->edge('trigger', 'out', 'welcome'),
            $this->edge('welcome', 'out', 'menu'),
            $this->edge('menu', 'p0', 'ask_order'),
            $this->edge('menu', 'p1', 'handoff'),
            $this->edge('menu', 'p2', 'ask_detail'),
            $this->edge('ask_order', 'out', 'order_ack'),
            $this->edge('order_ack', 'out', 'assign_agent'),
            $this->edge('assign_agent', 'out', 'end'),
            $this->edge('handoff', 'out', 'assign_agent'),
            $this->edge('ask_detail', 'out', 'assign_other'),
            $this->edge('assign_other', 'out', 'end'),
        ];

        return [
            'name'        => 'Support desk (Meta utility)',
            'description' => 'Customer-initiated help menu. Session messages only — map your Meta-approved utility template later for out-of-window follow-up.',
            'category'    => 'support',
            'graph'       => $this->graph($n, $e),
        ];
    }

    /** Order / shipment desk — typical UTILITY category Meta approves. */
    private function orderStatus(): array
    {
        $i = 0;
        $n = [];
        $n[] = $this->node($i++, 'trigger', 'trigger', [
            'kind' => 'keyword', 'keywordMode' => 'keywords',
            'keywords' => 'order, tracking, status, shipped, where is my order, wismo',
            'channel' => 'chat', 'deviceId' => '',
        ], ['isStart' => true]);
        $n[] = $this->node($i++, 'message', 'intro', [
            'text' => "Hi {{name}} — we can look up your order in this chat.\nReply with the order number when asked (for example WD-1042).",
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_order', [
            'prompt' => 'What is your order number?',
            'var'    => 'order_id', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'list', 'next', [
            'prompt'  => 'What do you need for order {{order_id}}?',
            'button'  => 'Order options',
            'var'     => 'order_next',
            'options' => [
                ['title' => 'Where is it?', 'description' => 'Latest status and tracking', 'section' => 'Status'],
                ['title' => 'Change address', 'description' => 'Update delivery details', 'section' => 'Change'],
                ['title' => 'Cancel order', 'description' => 'Request a cancellation', 'section' => 'Change'],
                ['title' => 'Talk to support', 'description' => 'A teammate will join this chat', 'section' => 'Help'],
            ],
        ]);
        $n[] = $this->node($i++, 'message', 'track_msg', [
            'text' => "Thanks. We're checking order {{order_id}} and will reply here with the latest status and tracking link.",
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_address', [
            'prompt' => 'Reply with the full updated delivery address for order {{order_id}} (street, city, postcode).',
            'var'    => 'new_address', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'message', 'address_ack', [
            'text' => 'Got it. A teammate will confirm the new address for {{order_id}} in this chat before we update the shipment.',
        ]);
        $n[] = $this->node($i++, 'buttons', 'cancel_confirm', [
            'prompt'  => 'Cancel order {{order_id}}? If it has already shipped we may not be able to stop it.',
            'options' => ['Yes, cancel it', 'Keep my order'],
            'var'     => 'cancel_yes',
        ]);
        $n[] = $this->node($i++, 'message', 'cancel_ack', [
            'text' => 'Cancellation request received for {{order_id}}. We will confirm here once it is processed.',
        ]);
        $n[] = $this->node($i++, 'message', 'keep_ack', [
            'text' => 'No change — order {{order_id}} stays as is. Anything else we can help with?',
        ]);
        $n[] = $this->node($i++, 'tag', 'tag_cancel', [
            'action' => 'add', 'tagId' => '', 'tag' => 'order-cancel-request',
        ]);
        $n[] = $this->node($i++, 'assign', 'assign', [
            'team' => '', 'userId' => '', 'message' => 'Order desk {{order_id}} · {{order_next}} · {{new_address}}',
        ]);
        $n[] = $this->node($i++, 'template', 'tpl_followup', [
            'tpl'     => '',
            'preview' => 'Attach your Meta-approved UTILITY template (order_shipped / order_update) for messages outside the 24h window.',
        ]);
        $n[] = $this->node($i++, 'end', 'end', ['label' => 'Done']);

        $e = [
            $this->edge('trigger', 'out', 'intro'),
            $this->edge('intro', 'out', 'ask_order'),
            $this->edge('ask_order', 'out', 'next'),
            $this->edge('next', 'p0', 'track_msg'),
            $this->edge('next', 'p1', 'ask_address'),
            $this->edge('next', 'p2', 'cancel_confirm'),
            $this->edge('next', 'p3', 'assign'),
            $this->edge('track_msg', 'out', 'tpl_followup'),
            $this->edge('ask_address', 'out', 'address_ack'),
            $this->edge('address_ack', 'out', 'assign'),
            $this->edge('cancel_confirm', 'p0', 'cancel_ack'),
            $this->edge('cancel_confirm', 'p1', 'keep_ack'),
            $this->edge('cancel_ack', 'out', 'tag_cancel'),
            $this->edge('tag_cancel', 'out', 'assign'),
            $this->edge('keep_ack', 'out', 'end'),
            $this->edge('assign', 'out', 'tpl_followup'),
            $this->edge('tpl_followup', 'out', 'end'),
        ];

        return [
            'name'        => 'Order status (Meta utility)',
            'description' => 'Look up an order, share tracking, change address, or request cancel. Session replies plus a Meta-approved shipping template slot.',
            'category'    => 'ecommerce',
            'graph'       => $this->graph($n, $e),
        ];
    }

    /**
     * Shared shop journey: welcome menu → commerce node (purchased / abandoned)
     * → thank-you + tag, or cart nudge + human handoff.
     * Tenant maps storeId + products on the commerce node after clone.
     */
    private function commerceStorefront(
        string $provider,
        string $name,
        string $description,
        string $keywords,
        string $intro,
        string $headerText,
        string $bodyText,
        string $footerText,
    ): array {
        $i = 0;
        $n = [];
        $n[] = $this->node($i++, 'trigger', 'trigger', [
            'kind' => 'keyword', 'keywordMode' => 'keywords',
            'keywords' => $keywords,
            'channel' => 'chat', 'deviceId' => '',
        ], ['isStart' => true]);
        $n[] = $this->node($i++, 'message', 'intro', [
            'text' => $intro,
        ]);
        $n[] = $this->node($i++, 'buttons', 'menu', [
            'prompt'  => 'How can we help?',
            'options' => ['Shop now', 'Track an order', 'Talk to us'],
            'var'     => 'shop_need',
        ]);
        $n[] = $this->node($i++, $provider, 'shop', [
            'storeId' => '',
            'productItems' => [],
            'headerText' => $headerText,
            'bodyText' => $bodyText,
            'footerText' => $footerText,
            'abandonedWaitMinutes' => 15,
        ]);
        $n[] = $this->node($i++, 'message', 'thanks', [
            'text' => "Thanks for your order, {{name}}.\nWe'll send updates for this purchase in this chat. Reply STOP any time if you no longer want order alerts.",
        ]);
        $n[] = $this->node($i++, 'tag', 'tag_buyer', [
            'action' => 'add', 'tagId' => '', 'tag' => 'purchased',
        ]);
        $n[] = $this->node($i++, 'deal', 'deal', [
            'action' => 'create', 'dealName' => '{{name}} — WhatsApp order',
            'stageId' => '', 'value' => '', 'ownerId' => '', 'saveAs' => 'deal_id',
        ]);
        $n[] = $this->node($i++, 'template', 'tpl_receipt', [
            'tpl'     => '',
            'preview' => 'Attach your Meta-approved UTILITY order_confirmation template for the receipt / shipping update.',
        ]);
        $n[] = $this->node($i++, 'message', 'nudge', [
            'text' => "Still thinking it over? Your cart is saved.\nReply SHOP to see the products again, or tell us what you need and we'll help in this chat.",
        ]);
        $n[] = $this->node($i++, 'tag', 'tag_cart', [
            'action' => 'add', 'tagId' => '', 'tag' => 'abandoned-cart',
        ]);
        $n[] = $this->node($i++, 'cta', 'cta_shop', [
            'actions' => [
                ['type' => 'url', 'label' => 'Complete checkout', 'value' => 'https://example.com/checkout'],
            ],
            'headerText' => 'Checkout',
            'bodyText' => 'Tap below to finish your order. Replace the URL with your store checkout after clone.',
            'footerText' => '',
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_order', [
            'prompt' => 'Reply with your order number and we will look it up.',
            'var'    => 'order_id', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'message', 'track_ack', [
            'text' => "Thanks. We're checking {{order_id}} and will reply here with the status.",
        ]);
        $n[] = $this->node($i++, 'assign', 'assign', [
            'team' => '', 'userId' => '', 'message' => 'Shop {{shop_need}} · order {{order_id}}',
        ]);
        $n[] = $this->node($i++, 'end', 'end', ['label' => 'Done']);

        $e = [
            $this->edge('trigger', 'out', 'intro'),
            $this->edge('intro', 'out', 'menu'),
            $this->edge('menu', 'p0', 'shop'),
            $this->edge('menu', 'p1', 'ask_order'),
            $this->edge('menu', 'p2', 'assign'),
            $this->edge('shop', 'purchased', 'thanks'),
            $this->edge('shop', 'abandoned', 'nudge'),
            $this->edge('thanks', 'out', 'tag_buyer'),
            $this->edge('tag_buyer', 'out', 'deal'),
            $this->edge('deal', 'created', 'tpl_receipt'),
            $this->edge('deal', 'error', 'tpl_receipt'),
            $this->edge('tpl_receipt', 'out', 'end'),
            $this->edge('nudge', 'out', 'tag_cart'),
            $this->edge('tag_cart', 'out', 'cta_shop'),
            $this->edge('cta_shop', 'out', 'assign'),
            $this->edge('ask_order', 'out', 'track_ack'),
            $this->edge('track_ack', 'out', 'assign'),
            $this->edge('assign', 'out', 'end'),
        ];

        return [
            'name'        => $name,
            'description' => $description,
            'category'    => 'ecommerce',
            'graph'       => $this->graph($n, $e),
        ];
    }

    private function whatsappCatalogShop(): array
    {
        return $this->commerceStorefront(
            'whatsapp_shop',
            'WhatsApp catalog shop',
            'Browse catalog, buy in chat, recover abandoned carts. Clone then pick your WhatsApp catalog and products on the Shop node.',
            'shop, catalog, buy, products, store',
            "Hi {{name}} — welcome to our WhatsApp shop.\nTap Shop now to browse products, or track an existing order.",
            'Featured products',
            'Tap a product to see details and checkout.',
            'Secure checkout · reply here if you need help',
        );
    }

    private function shopifyStorefront(): array
    {
        return $this->commerceStorefront(
            'shopify',
            'Shopify shop (WhatsApp)',
            'Send Shopify products in WhatsApp, then branch on purchased vs abandoned. Connect the store on the Shopify node after clone.',
            'shop, shopify, buy, products, store, catalog',
            "Hi {{name}} — shop our collection on WhatsApp.\nWe'll send live Shopify products you can tap to buy.",
            'From our store',
            'Tap a product to see details. Checkout uses your Shopify cart.',
            'Powered by Shopify · updates in this chat',
        );
    }

    private function wooStorefront(): array
    {
        return $this->commerceStorefront(
            'woocommerce',
            'WooCommerce shop (WhatsApp)',
            'Send WooCommerce products in WhatsApp, then thank buyers or nudge abandoned carts. Connect the store on the Woo node after clone.',
            'shop, woo, woocommerce, buy, products, store',
            "Hi {{name}} — thanks for messaging us.\nBrowse products from our store or track an order.",
            'In stock now',
            'Tap a product to see details and complete checkout.',
            'WooCommerce checkout · help in this chat',
        );
    }

    /** Customer came back after leaving a cart — session nudge + shop node. */
    private function abandonedCart(): array
    {
        $i = 0;
        $n = [];
        $n[] = $this->node($i++, 'trigger', 'trigger', [
            'kind' => 'keyword', 'keywordMode' => 'keywords',
            'keywords' => 'cart, checkout, complete order, finish order, still there',
            'channel' => 'chat', 'deviceId' => '',
        ], ['isStart' => true]);
        $n[] = $this->node($i++, 'message', 'intro', [
            'text' => "Hi {{name}} — you still have items waiting.\nWe can reopen checkout in this chat. This is an order reminder, not a promotion.",
        ]);
        $n[] = $this->node($i++, 'buttons', 'menu', [
            'prompt'  => 'What would you like to do?',
            'options' => ['Finish checkout', 'Ask a question', 'Remove my cart'],
            'var'     => 'cart_action',
        ]);
        $n[] = $this->node($i++, 'whatsapp_shop', 'shop', [
            'storeId' => '',
            'productItems' => [],
            'headerText' => 'Your saved items',
            'bodyText' => 'Tap to finish checkout. Stock can change — we check live inventory.',
            'footerText' => 'Need a different size or colour? Reply here.',
            'abandonedWaitMinutes' => 30,
        ]);
        $n[] = $this->node($i++, 'message', 'thanks', [
            'text' => 'Order received. We will send payment and shipping updates in this chat.',
        ]);
        $n[] = $this->node($i++, 'tag', 'tag_buyer', [
            'action' => 'add', 'tagId' => '', 'tag' => 'purchased',
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_q', [
            'prompt' => 'What do you need to know before checkout? (size, shipping, payment)',
            'var'    => 'cart_question', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'message', 'cleared', [
            'text' => 'Cart cleared. Message us any time you want to shop again.',
        ]);
        $n[] = $this->node($i++, 'tag', 'untag_cart', [
            'action' => 'remove', 'tagId' => '', 'tag' => 'abandoned-cart',
        ]);
        $n[] = $this->node($i++, 'assign', 'assign', [
            'team' => '', 'userId' => '', 'message' => 'Cart help: {{cart_question}}',
        ]);
        $n[] = $this->node($i++, 'template', 'tpl_cart', [
            'tpl'     => '',
            'preview' => 'Optional: map a Meta-approved UTILITY abandoned-checkout reminder for out-of-window sends. Do not use a MARKETING template here unless the contact opted in.',
        ]);
        $n[] = $this->node($i++, 'end', 'end', ['label' => 'Done']);

        $e = [
            $this->edge('trigger', 'out', 'intro'),
            $this->edge('intro', 'out', 'menu'),
            $this->edge('menu', 'p0', 'shop'),
            $this->edge('menu', 'p1', 'ask_q'),
            $this->edge('menu', 'p2', 'cleared'),
            $this->edge('shop', 'purchased', 'thanks'),
            $this->edge('shop', 'abandoned', 'tpl_cart'),
            $this->edge('thanks', 'out', 'tag_buyer'),
            $this->edge('tag_buyer', 'out', 'untag_cart'),
            $this->edge('ask_q', 'out', 'assign'),
            $this->edge('cleared', 'out', 'untag_cart'),
            $this->edge('untag_cart', 'out', 'end'),
            $this->edge('assign', 'out', 'end'),
            $this->edge('tpl_cart', 'out', 'end'),
        ];

        return [
            'name'        => 'Abandoned cart (e-commerce)',
            'description' => 'Reopen checkout, answer a pre-purchase question, or clear the cart. Shop node recovers the sale; swap it to Shopify/Woo if that is your store.',
            'category'    => 'ecommerce',
            'graph'       => $this->graph($n, $e),
        ];
    }

    /** Returns / exchange — utility, customer-initiated. */
    private function returnsExchange(): array
    {
        $i = 0;
        $n = [];
        $n[] = $this->node($i++, 'trigger', 'trigger', [
            'kind' => 'keyword', 'keywordMode' => 'keywords',
            'keywords' => 'return, refund, exchange, replace, send back',
            'channel' => 'chat', 'deviceId' => '',
        ], ['isStart' => true]);
        $n[] = $this->node($i++, 'message', 'intro', [
            'text' => "We can start a return or exchange in this chat.\nMost unused items can be returned within 14 days — update this policy in the builder.",
        ]);
        $n[] = $this->node($i++, 'list', 'menu', [
            'prompt'  => 'What do you need?',
            'button'  => 'Returns menu',
            'var'     => 'return_need',
            'options' => [
                ['title' => 'Start a return', 'description' => 'Send an item back', 'section' => 'Request'],
                ['title' => 'Exchange item', 'description' => 'Different size or colour', 'section' => 'Request'],
                ['title' => 'Refund status', 'description' => 'Where is my refund?', 'section' => 'Status'],
                ['title' => 'Talk to support', 'description' => 'A teammate will join', 'section' => 'Help'],
            ],
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_order', [
            'prompt' => 'What is the order number for this return?',
            'var'    => 'order_id', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_item', [
            'prompt' => 'Which item, and why are you returning it? (wrong size, damaged, changed mind)',
            'var'    => 'return_reason', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'condition', 'is_exchange', [
            'conditions' => [['variable' => 'return_need', 'operator' => 'equals', 'value' => 'Exchange item']],
            'operators' => [],
        ]);
        $n[] = $this->node($i++, 'condition', 'is_refund', [
            'conditions' => [['variable' => 'return_need', 'operator' => 'equals', 'value' => 'Refund status']],
            'operators' => [],
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_exchange', [
            'prompt' => 'What size or colour should we send instead?',
            'var'    => 'exchange_for', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'message', 'return_ack', [
            'text' => "Return noted for {{order_id}}.\nReason: {{return_reason}}\nA teammate will send the return address or pickup details in this chat.",
        ]);
        $n[] = $this->node($i++, 'message', 'exchange_ack', [
            'text' => "Exchange noted for {{order_id}} → {{exchange_for}}.\nWe'll confirm stock and shipping in this chat.",
        ]);
        $n[] = $this->node($i++, 'message', 'refund_ack', [
            'text' => "Thanks. We're checking the refund for {{order_id}} and will confirm the status here. Bank timing depends on your payment method.",
        ]);
        $n[] = $this->node($i++, 'tag', 'tag_return', [
            'action' => 'add', 'tagId' => '', 'tag' => 'return-request',
        ]);
        $n[] = $this->node($i++, 'deal', 'deal', [
            'action' => 'create', 'dealName' => 'Return {{order_id}}',
            'stageId' => '', 'value' => '', 'ownerId' => '', 'saveAs' => 'deal_id',
        ]);
        $n[] = $this->node($i++, 'assign', 'assign', [
            'team' => '', 'userId' => '', 'message' => 'Returns {{return_need}} · {{order_id}} · {{return_reason}} · {{exchange_for}}',
        ]);
        $n[] = $this->node($i++, 'template', 'tpl_rma', [
            'tpl'     => '',
            'preview' => 'Attach your Meta-approved UTILITY return_update or refund_update template.',
        ]);
        $n[] = $this->node($i++, 'end', 'end', ['label' => 'Done']);

        $e = [
            $this->edge('trigger', 'out', 'intro'),
            $this->edge('intro', 'out', 'menu'),
            $this->edge('menu', 'p0', 'ask_order'),
            $this->edge('menu', 'p1', 'ask_order'),
            $this->edge('menu', 'p2', 'ask_order'),
            $this->edge('menu', 'p3', 'assign'),
            $this->edge('ask_order', 'out', 'ask_item'),
            $this->edge('ask_item', 'out', 'is_exchange'),
            $this->edge('is_exchange', 'yes', 'ask_exchange'),
            $this->edge('is_exchange', 'no', 'is_refund'),
            $this->edge('is_refund', 'yes', 'refund_ack'),
            $this->edge('is_refund', 'no', 'return_ack'),
            $this->edge('ask_exchange', 'out', 'exchange_ack'),
            $this->edge('return_ack', 'out', 'tag_return'),
            $this->edge('exchange_ack', 'out', 'tag_return'),
            $this->edge('refund_ack', 'out', 'tag_return'),
            $this->edge('tag_return', 'out', 'deal'),
            $this->edge('deal', 'created', 'tpl_rma'),
            $this->edge('deal', 'error', 'assign'),
            $this->edge('tpl_rma', 'out', 'end'),
            $this->edge('assign', 'out', 'end'),
        ];

        return [
            'name'        => 'Returns & exchange (e-commerce)',
            'description' => 'Capture order, item, and reason for returns, exchanges, or refund status, then open a CRM deal and hand off.',
            'category'    => 'ecommerce',
            'graph'       => $this->graph($n, $e),
        ];
    }

    /** Cash-on-delivery confirm / cancel — common WhatsApp commerce pattern. */
    private function codConfirm(): array
    {
        $i = 0;
        $n = [];
        $n[] = $this->node($i++, 'trigger', 'trigger', [
            'kind' => 'keyword', 'keywordMode' => 'keywords',
            'keywords' => 'cod, cash on delivery, confirm order, confirm cod, cancel order',
            'channel' => 'chat', 'deviceId' => '',
        ], ['isStart' => true]);
        $n[] = $this->node($i++, 'message', 'intro', [
            'text' => 'Please confirm your cash-on-delivery order so we can pack and dispatch it.',
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_order', [
            'prompt' => 'What is the order number we should confirm?',
            'var'    => 'order_id', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'buttons', 'confirm', [
            'prompt'  => 'Confirm cash on delivery for order {{order_id}}?',
            'options' => ['Confirm COD', 'Change address', 'Cancel order'],
            'var'     => 'cod_action',
        ]);
        $n[] = $this->node($i++, 'message', 'ok', [
            'text' => "Confirmed. We'll pack order {{order_id}} for cash on delivery. Please keep the exact amount ready for the courier.",
        ]);
        $n[] = $this->node($i++, 'tag', 'tag_cod', [
            'action' => 'add', 'tagId' => '', 'tag' => 'cod-confirmed',
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_address', [
            'prompt' => 'Reply with the full delivery address for {{order_id}}.',
            'var'    => 'cod_address', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'message', 'addr_ack', [
            'text' => 'Address received for {{order_id}}. A teammate will confirm it before dispatch.',
        ]);
        $n[] = $this->node($i++, 'message', 'cancel_ack', [
            'text' => 'Cancellation request received for {{order_id}}. We will stop packing if it has not shipped yet.',
        ]);
        $n[] = $this->node($i++, 'tag', 'tag_cancel', [
            'action' => 'add', 'tagId' => '', 'tag' => 'cod-cancelled',
        ]);
        $n[] = $this->node($i++, 'assign', 'assign', [
            'team' => '', 'userId' => '', 'message' => 'COD {{cod_action}} · {{order_id}} · {{cod_address}}',
        ]);
        $n[] = $this->node($i++, 'template', 'tpl_cod', [
            'tpl'     => '',
            'preview' => 'Attach your Meta-approved UTILITY order_confirmation template for dispatch updates.',
        ]);
        $n[] = $this->node($i++, 'end', 'end', ['label' => 'Done']);

        $e = [
            $this->edge('trigger', 'out', 'intro'),
            $this->edge('intro', 'out', 'ask_order'),
            $this->edge('ask_order', 'out', 'confirm'),
            $this->edge('confirm', 'p0', 'ok'),
            $this->edge('confirm', 'p1', 'ask_address'),
            $this->edge('confirm', 'p2', 'cancel_ack'),
            $this->edge('ok', 'out', 'tag_cod'),
            $this->edge('tag_cod', 'out', 'tpl_cod'),
            $this->edge('ask_address', 'out', 'addr_ack'),
            $this->edge('addr_ack', 'out', 'assign'),
            $this->edge('cancel_ack', 'out', 'tag_cancel'),
            $this->edge('tag_cancel', 'out', 'assign'),
            $this->edge('assign', 'out', 'end'),
            $this->edge('tpl_cod', 'out', 'end'),
        ];

        return [
            'name'        => 'COD confirm (e-commerce)',
            'description' => 'Confirm, change address, or cancel a cash-on-delivery order before dispatch. Tags the contact and slots a utility confirmation template.',
            'category'    => 'ecommerce',
            'graph'       => $this->graph($n, $e),
        ];
    }

    /** Pre-purchase size, shipping, and stock questions. */
    private function productHelp(): array
    {
        $i = 0;
        $n = [];
        $n[] = $this->node($i++, 'trigger', 'trigger', [
            'kind' => 'keyword', 'keywordMode' => 'keywords',
            'keywords' => 'size, sizing, shipping, delivery, stock, available, fit',
            'channel' => 'chat', 'deviceId' => '',
        ], ['isStart' => true]);
        $n[] = $this->node($i++, 'message', 'intro', [
            'text' => 'We can help with sizing, shipping, or stock before you order.',
        ]);
        $n[] = $this->node($i++, 'list', 'menu', [
            'prompt'  => 'What do you need?',
            'button'  => 'Product help',
            'var'     => 'help_topic',
            'options' => [
                ['title' => 'Size & fit', 'description' => 'How to choose a size', 'section' => 'Product'],
                ['title' => 'Shipping', 'description' => 'Times and delivery areas', 'section' => 'Product'],
                ['title' => 'Stock check', 'description' => 'Is this item available?', 'section' => 'Product'],
                ['title' => 'Talk to us', 'description' => 'A teammate will join', 'section' => 'Help'],
            ],
        ]);
        $n[] = $this->node($i++, 'message', 'sizing', [
            'text' => "Size guide (update this in the builder):\n• S — chest 86–91 cm\n• M — chest 96–101 cm\n• L — chest 106–111 cm\nReply with the item name if you want a personal recommendation.",
        ]);
        $n[] = $this->node($i++, 'message', 'shipping', [
            'text' => "Shipping (update this in the builder):\n• Local: 1–3 working days\n• Nationwide: 3–7 working days\nWe send tracking in this chat after dispatch.",
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_sku', [
            'prompt' => 'Reply with the product name or SKU and we will check stock.',
            'var'    => 'sku', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'message', 'stock_ack', [
            'text' => "Thanks. We're checking stock for {{sku}} and will confirm availability here.",
        ]);
        $n[] = $this->node($i++, 'whatsapp_shop', 'shop', [
            'storeId' => '',
            'productItems' => [],
            'headerText' => 'Related products',
            'bodyText' => 'Tap a product if you want to order after we confirm stock.',
            'footerText' => '',
            'abandonedWaitMinutes' => 15,
        ]);
        $n[] = $this->node($i++, 'message', 'bought', [
            'text' => 'Order received for {{sku}}. We will send updates in this chat.',
        ]);
        $n[] = $this->node($i++, 'assign', 'assign', [
            'team' => '', 'userId' => '', 'message' => 'Product help {{help_topic}} · {{sku}}',
        ]);
        $n[] = $this->node($i++, 'end', 'end', ['label' => 'Done']);

        $e = [
            $this->edge('trigger', 'out', 'intro'),
            $this->edge('intro', 'out', 'menu'),
            $this->edge('menu', 'p0', 'sizing'),
            $this->edge('menu', 'p1', 'shipping'),
            $this->edge('menu', 'p2', 'ask_sku'),
            $this->edge('menu', 'p3', 'assign'),
            $this->edge('sizing', 'out', 'end'),
            $this->edge('shipping', 'out', 'end'),
            $this->edge('ask_sku', 'out', 'stock_ack'),
            $this->edge('stock_ack', 'out', 'shop'),
            $this->edge('shop', 'purchased', 'bought'),
            $this->edge('shop', 'abandoned', 'assign'),
            $this->edge('bought', 'out', 'end'),
            $this->edge('assign', 'out', 'end'),
        ];

        return [
            'name'        => 'Product help (e-commerce)',
            'description' => 'Size guide, shipping times, and stock check — then offer catalog products or hand off to a teammate.',
            'category'    => 'ecommerce',
            'graph'       => $this->graph($n, $e),
        ];
    }

    /**
     * Clean 10-lane shop desk (gallery starter). One list hub, one row
     * per option — matches the numbered self-service mockup. Nested
     * Track/COD/return desks stay as separate ecommerce templates.
     */
    private function shopSelfService(): array
    {
        $i = 0;
        $n = [];
        $n[] = $this->node($i++, 'trigger', 'trigger', [
            'kind' => 'keyword', 'keywordMode' => 'keywords',
            'keywords' => 'hi, hello, shop, menu, help, order, return, catalog, store',
            'channel' => 'chat', 'deviceId' => '',
        ], ['isStart' => true]);
        $n[] = $this->node($i++, 'list', 'menu', [
            'prompt' => "Hi *{{name}}*,👋\nWelcome to our WhatsApp shop self service! Type an option number or tap *View menu*.👇\n\n"
                ."1️⃣ Browse *Products*\n"
                ."2️⃣ *Track* my order\n"
                ."3️⃣ Confirm *COD*\n"
                ."4️⃣ *Return* or exchange\n"
                ."5️⃣ Size & *shipping*\n"
                ."6️⃣ *Stock* check\n"
                ."7️⃣ Finish *checkout*\n"
                ."8️⃣ *Payment* help\n"
                ."9️⃣ *Find* our store\n"
                ."🔟 Talk to a *person*",
            'button' => 'View menu',
            'var'    => 'shop_need',
            'options' => [
                ['title' => 'Browse products', 'description' => 'Catalog and checkout', 'section' => ''],
                ['title' => 'Track my order', 'description' => 'Status and tracking', 'section' => ''],
                ['title' => 'Confirm COD', 'description' => 'Cash on delivery', 'section' => ''],
                ['title' => 'Return or exchange', 'description' => 'Send an item back', 'section' => ''],
                ['title' => 'Size & shipping', 'description' => 'Fit and delivery times', 'section' => ''],
                ['title' => 'Stock check', 'description' => 'Is this item available?', 'section' => ''],
                ['title' => 'Finish checkout', 'description' => 'Complete a saved cart', 'section' => ''],
                ['title' => 'Payment help', 'description' => 'Pay or confirm payment', 'section' => ''],
                ['title' => 'Find our store', 'description' => 'Address and map pin', 'section' => ''],
                ['title' => 'Talk to a person', 'description' => 'A teammate joins this chat', 'section' => ''],
            ],
        ]);
        $n[] = $this->node($i++, 'whatsapp_shop', 'shop', [
            'storeId' => '',
            'productItems' => [],
            'headerText' => 'Featured products',
            'bodyText' => 'Tap a product to see details and checkout.',
            'footerText' => 'Secure checkout · help in this chat',
            'abandonedWaitMinutes' => 15,
        ]);
        $n[] = $this->node($i++, 'message', 'thanks', [
            'text' => "Thanks for your order, {{name}}.\nWe'll send updates in this chat. Reply STOP if you no longer want order alerts.",
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_track', [
            'prompt' => 'What is your order number? (for example WD-1042)',
            'var'    => 'order_id', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'message', 'track_ack', [
            'text' => "Thanks. We're checking order {{order_id}} and will reply here with status and tracking.",
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_cod', [
            'prompt' => 'What is the cash-on-delivery order number we should confirm?',
            'var'    => 'order_id', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'message', 'cod_ack', [
            'text' => "Thanks. We'll confirm cash on delivery for {{order_id}} in this chat.",
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_return', [
            'prompt' => 'What is the order number for this return or exchange?',
            'var'    => 'order_id', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'message', 'return_ack', [
            'text' => 'Return noted for {{order_id}}. A teammate will send next steps in this chat.',
        ]);
        $n[] = $this->node($i++, 'message', 'sizing', [
            'text' => "Size & shipping (update this in the builder):\n• S — chest 86–91 cm · M — 96–101 cm · L — 106–111 cm\n• Local: 1–3 working days · Nationwide: 3–7 working days",
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_sku', [
            'prompt' => 'Reply with the product name or SKU and we will check stock.',
            'var'    => 'sku', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'message', 'stock_ack', [
            'text' => "Thanks. We're checking stock for {{sku}} and will confirm availability here.",
        ]);
        $n[] = $this->node($i++, 'cta', 'cta_shop', [
            'actions' => [
                ['type' => 'url', 'label' => 'Complete checkout', 'value' => 'https://example.com/checkout'],
            ],
            'headerText' => 'Checkout',
            'bodyText' => 'Tap below to finish your order. Replace the URL with your store checkout after clone.',
            'footerText' => '',
        ]);
        $n[] = $this->node($i++, 'cta', 'cta_pay', [
            'actions' => [
                ['type' => 'url', 'label' => 'Pay now', 'value' => 'https://example.com/pay'],
            ],
            'headerText' => 'Payment',
            'bodyText' => 'Tap to complete payment. Replace the URL after clone.',
            'footerText' => '',
        ]);
        $n[] = $this->node($i++, 'location', 'store_pin', [
            'lat' => '', 'lng' => '',
            'title' => 'Our store',
            'address' => 'Replace with your shop address after clone.',
        ]);
        $n[] = $this->node($i++, 'message', 'handoff', [
            'text' => 'Connecting you with a teammate now. Please stay in this chat.',
        ]);
        $n[] = $this->node($i++, 'assign', 'assign', [
            'team' => '', 'userId' => '', 'message' => 'Shop desk {{shop_need}} · order {{order_id}} · {{sku}}',
        ]);

        $e = [
            $this->edge('trigger', 'out', 'menu'),
            $this->edge('menu', 'p0', 'shop'),
            $this->edge('menu', 'p1', 'ask_track'),
            $this->edge('menu', 'p2', 'ask_cod'),
            $this->edge('menu', 'p3', 'ask_return'),
            $this->edge('menu', 'p4', 'sizing'),
            $this->edge('menu', 'p5', 'ask_sku'),
            $this->edge('menu', 'p6', 'cta_shop'),
            $this->edge('menu', 'p7', 'cta_pay'),
            $this->edge('menu', 'p8', 'store_pin'),
            $this->edge('menu', 'p9', 'handoff'),
            $this->edge('shop', 'purchased', 'thanks'),
            $this->edge('ask_track', 'out', 'track_ack'),
            $this->edge('ask_cod', 'out', 'cod_ack'),
            $this->edge('ask_return', 'out', 'return_ack'),
            $this->edge('ask_sku', 'out', 'stock_ack'),
            $this->edge('handoff', 'out', 'assign'),
        ];

        return [
            'name'        => 'Shop self-service (e-commerce)',
            'description' => 'Clean 10-lane shop menu: browse, track, COD, returns, size, stock, checkout, pay, store, human. Clone then map catalog, pin, and checkout/pay URLs. Use the dedicated Track / COD / Returns templates for deeper desks.',
            'category'    => 'ecommerce',
            'graph'       => $this->graph($n, $e),
        ];
    }

    /** Appointments — UTILITY reminder/confirm pattern. */
    private function appointmentBooking(): array
    {
        $i = 0;
        $n = [];
        $n[] = $this->node($i++, 'trigger', 'trigger', [
            'kind' => 'keyword', 'keywordMode' => 'keywords',
            'keywords' => 'book, appointment, schedule, booking',
            'channel' => 'chat', 'deviceId' => '',
        ], ['isStart' => true]);
        $n[] = $this->node($i++, 'message', 'intro', [
            'text' => 'We can book or update an appointment in this chat.',
        ]);
        $n[] = $this->node($i++, 'buttons', 'menu', [
            'prompt'  => 'What would you like to do?',
            'options' => ['Book a time', 'Reschedule', 'Cancel'],
            'var'     => 'appt_action',
        ]);
        $n[] = $this->node($i++, 'book_appointment', 'book', [
            'slotCount' => 5,
            'prompt'    => 'Pick a time that works:',
            'confirmation' => 'Your appointment is confirmed for {{slot}}.',
            'calendarOverride' => '',
            'collectEmail' => true,
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_current', [
            'prompt' => 'Reply with your current appointment date and time so we can update it.',
            'var'    => 'current_slot', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'message', 'cancel_ack', [
            'text' => 'Your appointment request has been received. We will confirm the cancellation in this chat.',
        ]);
        $n[] = $this->node($i++, 'template', 'tpl_reminder', [
            'tpl'     => '',
            'preview' => 'Attach your Meta-approved UTILITY appointment_reminder template for the reminder send.',
        ]);
        $n[] = $this->node($i++, 'end', 'end', ['label' => 'Done']);

        $e = [
            $this->edge('trigger', 'out', 'intro'),
            $this->edge('intro', 'out', 'menu'),
            $this->edge('menu', 'p0', 'book'),
            $this->edge('menu', 'p1', 'ask_current'),
            $this->edge('menu', 'p2', 'cancel_ack'),
            $this->edge('book', 'out', 'tpl_reminder'),
            $this->edge('ask_current', 'out', 'book'),
            $this->edge('cancel_ack', 'out', 'end'),
            $this->edge('tpl_reminder', 'out', 'end'),
        ];

        return [
            'name'        => 'Appointments (Meta utility)',
            'description' => 'Book, reschedule, or cancel. Session chat plus a slot for your Meta-approved appointment reminder template.',
            'category'    => 'healthcare',
            'graph'       => $this->graph($n, $e),
        ];
    }

    /** Explicit opt-in capture — required before marketing templates. */
    private function optInLeadCapture(): array
    {
        $i = 0;
        $n = [];
        $n[] = $this->node($i++, 'trigger', 'trigger', [
            'kind' => 'keyword', 'keywordMode' => 'keywords',
            'keywords' => 'info, catalogue, catalog, updates',
            'channel' => 'chat', 'deviceId' => '',
        ], ['isStart' => true]);
        $n[] = $this->node($i++, 'message', 'notice', [
            'text' => "We can send useful updates on WhatsApp about your account and orders.\nReply YES if you agree to receive these messages. Reply STOP any time to opt out.",
        ]);
        $n[] = $this->node($i++, 'buttons', 'consent', [
            'prompt'  => 'Do you want updates in this chat?',
            'options' => ['Yes, keep me updated', 'No thanks'],
            'var'     => 'opt_in',
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_name', [
            'prompt' => 'What name should we use?',
            'var'    => 'lead_name', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_email', [
            'prompt' => 'What email should we use for receipts?',
            'var'    => 'lead_email', 'validate' => 'email', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'tag', 'tag_optin', [
            'action' => 'add', 'tagId' => '', 'tag' => 'whatsapp-opt-in',
        ]);
        $n[] = $this->node($i++, 'message', 'thanks', [
            'text' => 'Thanks {{lead_name}}. You are opted in. Reply STOP any time to unsubscribe.',
        ]);
        $n[] = $this->node($i++, 'message', 'declined', [
            'text' => 'No problem. We will only reply in this chat when you message us.',
        ]);
        $n[] = $this->node($i++, 'end', 'end', ['label' => 'Done']);

        $e = [
            $this->edge('trigger', 'out', 'notice'),
            $this->edge('notice', 'out', 'consent'),
            $this->edge('consent', 'p0', 'ask_name'),
            $this->edge('consent', 'p1', 'declined'),
            $this->edge('ask_name', 'out', 'ask_email'),
            $this->edge('ask_email', 'out', 'tag_optin'),
            $this->edge('tag_optin', 'out', 'thanks'),
            $this->edge('thanks', 'out', 'end'),
            $this->edge('declined', 'out', 'end'),
        ];

        return [
            'name'        => 'WhatsApp opt-in (Meta compliant)',
            'description' => 'Collects explicit YES/STOP consent before any marketing template. Required for Meta-approved MARKETING sends.',
            'category'    => 'lead',
            'graph'       => $this->graph($n, $e),
        ];
    }

    /** FAQ list — session interactive messages Meta allows in-thread. */
    private function faqMenu(): array
    {
        $i = 0;
        $n = [];
        $n[] = $this->node($i++, 'trigger', 'trigger', [
            'kind' => 'keyword', 'keywordMode' => 'keywords',
            'keywords' => 'faq, hours, price, pricing, location',
            'channel' => 'chat', 'deviceId' => '',
        ], ['isStart' => true]);
        $n[] = $this->node($i++, 'list', 'faq', [
            'prompt'  => 'What would you like to know?',
            'button'  => 'Open menu',
            'var'     => 'faq_topic',
            'options' => [
                ['title' => 'Opening hours', 'description' => 'When we are available', 'section' => 'Basics'],
                ['title' => 'Pricing', 'description' => 'How billing works', 'section' => 'Basics'],
                ['title' => 'Our location', 'description' => 'Address and map', 'section' => 'Basics'],
                ['title' => 'Talk to a person', 'description' => 'A teammate will join', 'section' => 'Help'],
            ],
        ]);
        $n[] = $this->node($i++, 'message', 'hours', [
            'text' => 'We reply on WhatsApp every day, 9:00–18:00 (your local timezone).',
        ]);
        $n[] = $this->node($i++, 'message', 'pricing', [
            'text' => 'Pricing depends on your plan. A teammate can confirm the exact amount for your account in this chat.',
        ]);
        $n[] = $this->node($i++, 'location', 'location', [
            'lat' => 25.2048, 'lng' => 55.2708, 'address' => 'Update this pin in the builder', 'title' => 'Our office',
        ]);
        $n[] = $this->node($i++, 'assign', 'assign', [
            'team' => '', 'userId' => '', 'message' => 'FAQ handoff',
        ]);
        $n[] = $this->node($i++, 'end', 'end', ['label' => 'Done']);

        $e = [
            $this->edge('trigger', 'out', 'faq'),
            $this->edge('faq', 'p0', 'hours'),
            $this->edge('faq', 'p1', 'pricing'),
            $this->edge('faq', 'p2', 'location'),
            $this->edge('faq', 'p3', 'assign'),
            $this->edge('hours', 'out', 'end'),
            $this->edge('pricing', 'out', 'end'),
            $this->edge('location', 'out', 'end'),
            $this->edge('assign', 'out', 'end'),
        ];

        return [
            'name'        => 'FAQ menu (session)',
            'description' => 'Hours, pricing, location, and handoff using WhatsApp list messages inside the customer-initiated window.',
            'category'    => 'support',
            'graph'       => $this->graph($n, $e),
        ];
    }

    /** Hospitality — restaurant table / hours. */
    private function restaurantDesk(): array
    {
        $i = 0;
        $n = [];
        $n[] = $this->node($i++, 'trigger', 'trigger', [
            'kind' => 'keyword', 'keywordMode' => 'keywords',
            'keywords' => 'table, reservation, menu, book, restaurant',
            'channel' => 'chat', 'deviceId' => '',
        ], ['isStart' => true]);
        $n[] = $this->node($i++, 'message', 'intro', [
            'text' => "Hi {{name}} — thanks for messaging us.\nWe can reserve a table or share today's hours in this chat.",
        ]);
        $n[] = $this->node($i++, 'buttons', 'menu', [
            'prompt'  => 'How can we help?',
            'options' => ['Reserve a table', 'Opening hours', 'Talk to the host'],
            'var'     => 'resto_need',
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_party', [
            'prompt' => 'How many guests should we seat?',
            'var'    => 'party_size', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'book_appointment', 'book', [
            'slotCount' => 6,
            'prompt'    => 'Pick a seating time:',
            'confirmation' => 'Table reserved for {{party_size}} guests at {{slot}}.',
            'calendarOverride' => '',
            'collectEmail' => false,
        ]);
        $n[] = $this->node($i++, 'message', 'hours', [
            'text' => 'We are open 12:00–15:00 and 18:00–22:30. Kitchen last orders 30 minutes before close. Update these hours in the builder.',
        ]);
        $n[] = $this->node($i++, 'location', 'location', [
            'lat' => 25.2048, 'lng' => 55.2708, 'address' => 'Update this pin in the builder', 'title' => 'Restaurant',
        ]);
        $n[] = $this->node($i++, 'message', 'no_slots', [
            'text' => 'No open tables match that time. A host will confirm the next available seating in this chat.',
        ]);
        $n[] = $this->node($i++, 'assign', 'assign', [
            'team' => '', 'userId' => '', 'message' => 'Restaurant: {{resto_need}} · party {{party_size}}',
        ]);
        $n[] = $this->node($i++, 'template', 'tpl_reminder', [
            'tpl'     => '',
            'preview' => 'Attach your Meta-approved UTILITY reservation_reminder template for the reminder send.',
        ]);
        $n[] = $this->node($i++, 'end', 'end', ['label' => 'Done']);

        $e = [
            $this->edge('trigger', 'out', 'intro'),
            $this->edge('intro', 'out', 'menu'),
            $this->edge('menu', 'p0', 'ask_party'),
            $this->edge('menu', 'p1', 'hours'),
            $this->edge('menu', 'p2', 'assign'),
            $this->edge('ask_party', 'out', 'book'),
            $this->edge('book', 'booked', 'tpl_reminder'),
            $this->edge('book', 'no_slots', 'no_slots'),
            $this->edge('no_slots', 'out', 'assign'),
            $this->edge('hours', 'out', 'location'),
            $this->edge('location', 'out', 'end'),
            $this->edge('assign', 'out', 'end'),
            $this->edge('tpl_reminder', 'out', 'end'),
        ];

        return [
            'name'        => 'Restaurant desk (hospitality)',
            'description' => 'Table reservation, hours, and host handoff. Session chat plus a slot for a Meta-approved reservation reminder template.',
            'category'    => 'hospitality',
            'graph'       => $this->graph($n, $e),
        ];
    }

    /** Education — course / admission enquiry. */
    private function courseEnquiry(): array
    {
        $i = 0;
        $n = [];
        $n[] = $this->node($i++, 'trigger', 'trigger', [
            'kind' => 'keyword', 'keywordMode' => 'keywords',
            'keywords' => 'course, enroll, class, admission, fees',
            'channel' => 'chat', 'deviceId' => '',
        ], ['isStart' => true]);
        $n[] = $this->node($i++, 'message', 'intro', [
            'text' => 'We can share programme details and next steps for enrolment in this chat.',
        ]);
        $n[] = $this->node($i++, 'list', 'menu', [
            'prompt'  => 'What would you like to know?',
            'button'  => 'View options',
            'var'     => 'edu_topic',
            'options' => [
                ['title' => 'Programmes', 'description' => 'What we currently offer', 'section' => 'Study'],
                ['title' => 'Fees & dates', 'description' => 'Tuition and start dates', 'section' => 'Study'],
                ['title' => 'Apply / enroll', 'description' => 'Share your details for a callback', 'section' => 'Admissions'],
                ['title' => 'Talk to admissions', 'description' => 'A teammate will join', 'section' => 'Help'],
            ],
        ]);
        $n[] = $this->node($i++, 'message', 'programmes', [
            'text' => 'Update this list in the builder with your current programmes. A teammate can confirm eligibility in this chat.',
        ]);
        $n[] = $this->node($i++, 'message', 'fees', [
            'text' => 'Fees and start dates depend on the programme. Reply with the course name and we will confirm the current figures here.',
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_name', [
            'prompt' => 'What name should admissions use?',
            'var'    => 'lead_name', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_email', [
            'prompt' => 'What email should we use for enrolment updates?',
            'var'    => 'lead_email', 'validate' => 'email', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'deal', 'deal', [
            'action' => 'create', 'dealName' => '{{lead_name}} — enrolment',
            'stageId' => '', 'value' => '', 'ownerId' => '', 'saveAs' => 'deal_id',
        ]);
        $n[] = $this->node($i++, 'message', 'thanks', [
            'text' => 'Thanks {{lead_name}}. Admissions has your request and will follow up in this chat.',
        ]);
        $n[] = $this->node($i++, 'assign', 'assign', [
            'team' => '', 'userId' => '', 'message' => 'Education enquiry: {{edu_topic}}',
        ]);
        $n[] = $this->node($i++, 'template', 'tpl_class', [
            'tpl'     => '',
            'preview' => 'Attach your Meta-approved UTILITY class_reminder template for session reminders.',
        ]);
        $n[] = $this->node($i++, 'end', 'end', ['label' => 'Done']);

        $e = [
            $this->edge('trigger', 'out', 'intro'),
            $this->edge('intro', 'out', 'menu'),
            $this->edge('menu', 'p0', 'programmes'),
            $this->edge('menu', 'p1', 'fees'),
            $this->edge('menu', 'p2', 'ask_name'),
            $this->edge('menu', 'p3', 'assign'),
            $this->edge('programmes', 'out', 'end'),
            $this->edge('fees', 'out', 'end'),
            $this->edge('ask_name', 'out', 'ask_email'),
            $this->edge('ask_email', 'out', 'deal'),
            $this->edge('deal', 'created', 'thanks'),
            $this->edge('deal', 'error', 'assign'),
            $this->edge('thanks', 'out', 'tpl_class'),
            $this->edge('assign', 'out', 'end'),
            $this->edge('tpl_class', 'out', 'end'),
        ];

        return [
            'name'        => 'Course enquiry (education)',
            'description' => 'Programmes, fees, and enrolment capture. Session replies plus a slot for a Meta-approved class reminder template.',
            'category'    => 'education',
            'graph'       => $this->graph($n, $e),
        ];
    }

    /** Finance — application / account status (utility). */
    private function loanStatus(): array
    {
        $i = 0;
        $n = [];
        $n[] = $this->node($i++, 'trigger', 'trigger', [
            'kind' => 'keyword', 'keywordMode' => 'keywords',
            'keywords' => 'loan, application, account, status, statement',
            'channel' => 'chat', 'deviceId' => '',
        ], ['isStart' => true]);
        $n[] = $this->node($i++, 'message', 'intro', [
            'text' => 'We can share the latest status of your application or account in this chat. We will never ask for your full password or PIN.',
        ]);
        $n[] = $this->node($i++, 'buttons', 'menu', [
            'prompt'  => 'What do you need?',
            'options' => ['Application status', 'Send a document', 'Talk to an advisor'],
            'var'     => 'fin_need',
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_ref', [
            'prompt' => 'Reply with your application or account reference (for example LN-2048).',
            'var'    => 'app_ref', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'message', 'ack', [
            'text' => "Thanks. We're checking {{app_ref}} and will reply here with the current status.",
        ]);
        $n[] = $this->node($i++, 'message', 'docs', [
            'text' => 'Reply with the document type you need to send (ID, proof of address, or income). A teammate will confirm how to share it securely in this chat.',
        ]);
        $n[] = $this->node($i++, 'assign', 'assign', [
            'team' => '', 'userId' => '', 'message' => 'Finance {{fin_need}} · {{app_ref}}',
        ]);
        $n[] = $this->node($i++, 'template', 'tpl_status', [
            'tpl'     => '',
            'preview' => 'Attach your Meta-approved UTILITY loan_status or account_update template for out-of-window updates.',
        ]);
        $n[] = $this->node($i++, 'end', 'end', ['label' => 'Done']);

        $e = [
            $this->edge('trigger', 'out', 'intro'),
            $this->edge('intro', 'out', 'menu'),
            $this->edge('menu', 'p0', 'ask_ref'),
            $this->edge('menu', 'p1', 'docs'),
            $this->edge('menu', 'p2', 'assign'),
            $this->edge('ask_ref', 'out', 'ack'),
            $this->edge('ack', 'out', 'assign'),
            $this->edge('docs', 'out', 'assign'),
            $this->edge('assign', 'out', 'tpl_status'),
            $this->edge('tpl_status', 'out', 'end'),
        ];

        return [
            'name'        => 'Account status (finance)',
            'description' => 'Application and account updates without collecting secrets. Uses session replies and a Meta-approved utility template slot.',
            'category'    => 'finance',
            'graph'       => $this->graph($n, $e),
        ];
    }

    /** Travel — itinerary / booking changes. */
    private function tripItinerary(): array
    {
        $i = 0;
        $n = [];
        $n[] = $this->node($i++, 'trigger', 'trigger', [
            'kind' => 'keyword', 'keywordMode' => 'keywords',
            'keywords' => 'trip, booking, itinerary, flight, hotel, travel',
            'channel' => 'chat', 'deviceId' => '',
        ], ['isStart' => true]);
        $n[] = $this->node($i++, 'message', 'intro', [
            'text' => 'We can share your itinerary or help change dates in this chat.',
        ]);
        $n[] = $this->node($i++, 'buttons', 'menu', [
            'prompt'  => 'What do you need?',
            'options' => ['My itinerary', 'Change dates', 'Talk to travel desk'],
            'var'     => 'travel_need',
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_pnr', [
            'prompt' => 'Reply with your booking reference (for example PNR-8821).',
            'var'    => 'booking_ref', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'message', 'itinerary', [
            'text' => "Thanks. We're pulling booking {{booking_ref}} and will send the itinerary in this chat.",
        ]);
        $n[] = $this->node($i++, 'message', 'change', [
            'text' => 'Reply with the new dates you need for {{booking_ref}}. A teammate will confirm availability here.',
        ]);
        $n[] = $this->node($i++, 'assign', 'assign', [
            'team' => '', 'userId' => '', 'message' => 'Travel {{travel_need}} · {{booking_ref}}',
        ]);
        $n[] = $this->node($i++, 'template', 'tpl_confirm', [
            'tpl'     => '',
            'preview' => 'Attach your Meta-approved UTILITY booking_confirmation or pre_travel_reminder template.',
        ]);
        $n[] = $this->node($i++, 'end', 'end', ['label' => 'Done']);

        $e = [
            $this->edge('trigger', 'out', 'intro'),
            $this->edge('intro', 'out', 'menu'),
            $this->edge('menu', 'p0', 'ask_pnr'),
            $this->edge('menu', 'p1', 'ask_pnr'),
            $this->edge('menu', 'p2', 'assign'),
            $this->edge('ask_pnr', 'out', 'itinerary'),
            $this->edge('itinerary', 'out', 'change'),
            $this->edge('change', 'out', 'assign'),
            $this->edge('assign', 'out', 'tpl_confirm'),
            $this->edge('tpl_confirm', 'out', 'end'),
        ];

        return [
            'name'        => 'Trip itinerary (travel)',
            'description' => 'Booking lookup and date changes. Session chat plus a slot for a Meta-approved travel utility template.',
            'category'    => 'travel',
            'graph'       => $this->graph($n, $e),
        ];
    }

    /** SaaS — setup / billing / success handoff. */
    private function saasOnboarding(): array
    {
        $i = 0;
        $n = [];
        $n[] = $this->node($i++, 'trigger', 'trigger', [
            'kind' => 'keyword', 'keywordMode' => 'keywords',
            'keywords' => 'setup, onboard, trial, login, billing, help',
            'channel' => 'chat', 'deviceId' => '',
        ], ['isStart' => true]);
        $n[] = $this->node($i++, 'message', 'intro', [
            'text' => 'Hi {{name}} — we can help you get set up or look up a billing question in this chat.',
        ]);
        $n[] = $this->node($i++, 'buttons', 'menu', [
            'prompt'  => 'What do you need?',
            'options' => ['Getting started', 'Billing', 'Talk to success'],
            'var'     => 'saas_need',
        ]);
        $n[] = $this->node($i++, 'message', 'start', [
            'text' => "Getting started:\n1. Invite your team\n2. Connect WhatsApp\n3. Publish your first flow\nReply with the step you're on if you need help.",
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_invoice', [
            'prompt' => 'Reply with your workspace name or invoice number so we can look it up.',
            'var'    => 'ws_ref', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'message', 'billing_ack', [
            'text' => 'Thanks. A teammate will confirm the billing details for {{ws_ref}} in this chat.',
        ]);
        $n[] = $this->node($i++, 'assign', 'assign', [
            'team' => '', 'userId' => '', 'message' => 'SaaS {{saas_need}} · {{ws_ref}}',
        ]);
        $n[] = $this->node($i++, 'end', 'end', ['label' => 'Done']);

        $e = [
            $this->edge('trigger', 'out', 'intro'),
            $this->edge('intro', 'out', 'menu'),
            $this->edge('menu', 'p0', 'start'),
            $this->edge('menu', 'p1', 'ask_invoice'),
            $this->edge('menu', 'p2', 'assign'),
            $this->edge('start', 'out', 'end'),
            $this->edge('ask_invoice', 'out', 'billing_ack'),
            $this->edge('billing_ack', 'out', 'assign'),
            $this->edge('assign', 'out', 'end'),
        ];

        return [
            'name'        => 'Product onboarding (SaaS)',
            'description' => 'Setup checklist, billing lookup, and success-team handoff for customer-initiated chats.',
            'category'    => 'saas',
            'graph'       => $this->graph($n, $e),
        ];
    }

    /** Agency — project / quote intake. */
    private function agencyIntake(): array
    {
        $i = 0;
        $n = [];
        $n[] = $this->node($i++, 'trigger', 'trigger', [
            'kind' => 'keyword', 'keywordMode' => 'keywords',
            'keywords' => 'quote, project, brief, work with, proposal',
            'channel' => 'chat', 'deviceId' => '',
        ], ['isStart' => true]);
        $n[] = $this->node($i++, 'message', 'intro', [
            'text' => 'Tell us about the project and we will confirm next steps in this chat.',
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_company', [
            'prompt' => 'What company or brand is this for?',
            'var'    => 'company', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'buttons', 'budget', [
            'prompt'  => 'Rough budget range?',
            'options' => ['Under 2k', '2k–10k', '10k+'],
            'var'     => 'budget',
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_brief', [
            'prompt' => 'In one message, what should we deliver and by when?',
            'var'    => 'brief', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'deal', 'deal', [
            'action' => 'create', 'dealName' => '{{company}} — {{budget}}',
            'stageId' => '', 'value' => '', 'ownerId' => '', 'saveAs' => 'deal_id',
        ]);
        $n[] = $this->node($i++, 'message', 'thanks', [
            'text' => 'Thanks. We have the brief for {{company}} and a teammate will follow up in this chat.',
        ]);
        $n[] = $this->node($i++, 'assign', 'assign', [
            'team' => '', 'userId' => '', 'message' => 'Agency brief · {{company}} · {{budget}} · {{brief}}',
        ]);
        $n[] = $this->node($i++, 'end', 'end', ['label' => 'Done']);

        $e = [
            $this->edge('trigger', 'out', 'intro'),
            $this->edge('intro', 'out', 'ask_company'),
            $this->edge('ask_company', 'out', 'budget'),
            $this->edge('budget', 'p0', 'ask_brief'),
            $this->edge('budget', 'p1', 'ask_brief'),
            $this->edge('budget', 'p2', 'ask_brief'),
            $this->edge('ask_brief', 'out', 'deal'),
            $this->edge('deal', 'created', 'thanks'),
            $this->edge('deal', 'error', 'assign'),
            $this->edge('thanks', 'out', 'assign'),
            $this->edge('assign', 'out', 'end'),
        ];

        return [
            'name'        => 'Project intake (agency)',
            'description' => 'Captures company, budget band, and brief, then opens a CRM deal and hands off to a teammate.',
            'category'    => 'agency',
            'graph'       => $this->graph($n, $e),
        ];
    }

    /** Real estate — viewing request. */
    private function propertyViewing(): array
    {
        $i = 0;
        $n = [];
        $n[] = $this->node($i++, 'trigger', 'trigger', [
            'kind' => 'keyword', 'keywordMode' => 'keywords',
            'keywords' => 'viewing, property, visit, house, apartment, rent, buy',
            'channel' => 'chat', 'deviceId' => '',
        ], ['isStart' => true]);
        $n[] = $this->node($i++, 'message', 'intro', [
            'text' => 'We can arrange a property viewing in this chat.',
        ]);
        $n[] = $this->node($i++, 'list', 'type', [
            'prompt'  => 'What are you looking for?',
            'button'  => 'Property type',
            'var'     => 'property_type',
            'options' => [
                ['title' => 'Apartment', 'description' => 'Flat or condo', 'section' => 'Type'],
                ['title' => 'House', 'description' => 'Villa or townhouse', 'section' => 'Type'],
                ['title' => 'Commercial', 'description' => 'Office or retail', 'section' => 'Type'],
                ['title' => 'Talk to an agent', 'description' => 'A teammate will join', 'section' => 'Help'],
            ],
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_area', [
            'prompt' => 'Which area or listing ID should we use?',
            'var'    => 'area', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'book_appointment', 'book', [
            'slotCount' => 5,
            'prompt'    => 'Pick a viewing time:',
            'confirmation' => 'Viewing confirmed for {{slot}} · {{property_type}} in {{area}}.',
            'calendarOverride' => '',
            'collectEmail' => true,
        ]);
        $n[] = $this->node($i++, 'message', 'no_slots', [
            'text' => 'No viewing slots match that time. An agent will propose alternatives in this chat.',
        ]);
        $n[] = $this->node($i++, 'deal', 'deal', [
            'action' => 'create', 'dealName' => '{{area}} — {{property_type}}',
            'stageId' => '', 'value' => '', 'ownerId' => '', 'saveAs' => 'deal_id',
        ]);
        $n[] = $this->node($i++, 'assign', 'assign', [
            'team' => '', 'userId' => '', 'message' => 'Viewing {{property_type}} · {{area}}',
        ]);
        $n[] = $this->node($i++, 'template', 'tpl_view', [
            'tpl'     => '',
            'preview' => 'Attach your Meta-approved UTILITY appointment_reminder template for the viewing reminder.',
        ]);
        $n[] = $this->node($i++, 'end', 'end', ['label' => 'Done']);

        $e = [
            $this->edge('trigger', 'out', 'intro'),
            $this->edge('intro', 'out', 'type'),
            $this->edge('type', 'p0', 'ask_area'),
            $this->edge('type', 'p1', 'ask_area'),
            $this->edge('type', 'p2', 'ask_area'),
            $this->edge('type', 'p3', 'assign'),
            $this->edge('ask_area', 'out', 'book'),
            $this->edge('book', 'booked', 'deal'),
            $this->edge('book', 'no_slots', 'no_slots'),
            $this->edge('no_slots', 'out', 'assign'),
            $this->edge('deal', 'created', 'tpl_view'),
            $this->edge('deal', 'error', 'assign'),
            $this->edge('assign', 'out', 'end'),
            $this->edge('tpl_view', 'out', 'end'),
        ];

        return [
            'name'        => 'Property viewing (real estate)',
            'description' => 'Property type, area, and viewing slot. Session chat plus a Meta-approved reminder template slot.',
            'category'    => 'realestate',
            'graph'       => $this->graph($n, $e),
        ];
    }

    /** Beauty & wellness — salon booking. */
    private function salonBooking(): array
    {
        $i = 0;
        $n = [];
        $n[] = $this->node($i++, 'trigger', 'trigger', [
            'kind' => 'keyword', 'keywordMode' => 'keywords',
            'keywords' => 'hair, salon, spa, nail, appointment, book',
            'channel' => 'chat', 'deviceId' => '',
        ], ['isStart' => true]);
        $n[] = $this->node($i++, 'message', 'intro', [
            'text' => 'We can book or update a salon appointment in this chat.',
        ]);
        $n[] = $this->node($i++, 'buttons', 'menu', [
            'prompt'  => 'What would you like?',
            'options' => ['Book a service', 'Reschedule', 'Services & prices'],
            'var'     => 'salon_action',
        ]);
        $n[] = $this->node($i++, 'list', 'service', [
            'prompt'  => 'Which service?',
            'button'  => 'Services',
            'var'     => 'service',
            'options' => [
                ['title' => 'Haircut', 'description' => 'Cut and finish', 'section' => 'Hair'],
                ['title' => 'Colour', 'description' => 'Tint or highlights', 'section' => 'Hair'],
                ['title' => 'Spa / nails', 'description' => 'Wellness treatments', 'section' => 'Wellness'],
            ],
        ]);
        $n[] = $this->node($i++, 'book_appointment', 'book', [
            'slotCount' => 6,
            'prompt'    => 'Pick a time:',
            'confirmation' => '{{service}} booked for {{slot}}.',
            'calendarOverride' => '',
            'collectEmail' => false,
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_current', [
            'prompt' => 'Reply with your current appointment date and time so we can move it.',
            'var'    => 'current_slot', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'message', 'prices', [
            'text' => 'Update this message with your current price list. A teammate can confirm the exact amount in this chat.',
        ]);
        $n[] = $this->node($i++, 'message', 'no_slots', [
            'text' => 'That time is full. A teammate will offer the next available slot here.',
        ]);
        $n[] = $this->node($i++, 'assign', 'assign', [
            'team' => '', 'userId' => '', 'message' => 'Salon {{salon_action}} · {{service}}',
        ]);
        $n[] = $this->node($i++, 'template', 'tpl_reminder', [
            'tpl'     => '',
            'preview' => 'Attach your Meta-approved UTILITY appointment_reminder template.',
        ]);
        $n[] = $this->node($i++, 'end', 'end', ['label' => 'Done']);

        $e = [
            $this->edge('trigger', 'out', 'intro'),
            $this->edge('intro', 'out', 'menu'),
            $this->edge('menu', 'p0', 'service'),
            $this->edge('menu', 'p1', 'ask_current'),
            $this->edge('menu', 'p2', 'prices'),
            $this->edge('service', 'p0', 'book'),
            $this->edge('service', 'p1', 'book'),
            $this->edge('service', 'p2', 'book'),
            $this->edge('book', 'booked', 'tpl_reminder'),
            $this->edge('book', 'no_slots', 'no_slots'),
            $this->edge('no_slots', 'out', 'assign'),
            $this->edge('ask_current', 'out', 'book'),
            $this->edge('prices', 'out', 'end'),
            $this->edge('assign', 'out', 'end'),
            $this->edge('tpl_reminder', 'out', 'end'),
        ];

        return [
            'name'        => 'Salon booking (beauty)',
            'description' => 'Service picker, booking, and reschedule. Session chat plus a Meta-approved appointment reminder slot.',
            'category'    => 'beauty',
            'graph'       => $this->graph($n, $e),
        ];
    }

    /** Logistics — parcel / delivery tracking. */
    private function parcelTracking(): array
    {
        $i = 0;
        $n = [];
        $n[] = $this->node($i++, 'trigger', 'trigger', [
            'kind' => 'keyword', 'keywordMode' => 'keywords',
            'keywords' => 'delivery, parcel, tracking, courier, shipment',
            'channel' => 'chat', 'deviceId' => '',
        ], ['isStart' => true]);
        $n[] = $this->node($i++, 'message', 'intro', [
            'text' => 'We can share the latest delivery status in this chat.',
        ]);
        $n[] = $this->node($i++, 'ask', 'ask_awb', [
            'prompt' => 'Reply with your tracking or AWB number.',
            'var'    => 'awb', 'validate' => 'text', 'options' => [],
        ]);
        $n[] = $this->node($i++, 'list', 'next', [
            'prompt'  => 'What do you need for {{awb}}?',
            'button'  => 'View options',
            'var'     => 'log_need',
            'options' => [
                ['title' => 'Where is my parcel', 'description' => 'Latest scan status', 'section' => 'Delivery'],
                ['title' => 'Reschedule delivery', 'description' => 'Pick another day', 'section' => 'Delivery'],
                ['title' => 'Talk to dispatch', 'description' => 'A teammate will join', 'section' => 'Help'],
            ],
        ]);
        $n[] = $this->node($i++, 'message', 'where', [
            'text' => "Thanks. We're checking {{awb}} and will reply here with the latest scan.",
        ]);
        $n[] = $this->node($i++, 'message', 'reschedule', [
            'text' => 'Reply with a preferred delivery day for {{awb}} and we will confirm it in this chat.',
        ]);
        $n[] = $this->node($i++, 'assign', 'assign', [
            'team' => '', 'userId' => '', 'message' => 'Logistics {{log_need}} · {{awb}}',
        ]);
        $n[] = $this->node($i++, 'template', 'tpl_ship', [
            'tpl'     => '',
            'preview' => 'Attach your Meta-approved UTILITY shipment_update template for out-of-window scans.',
        ]);
        $n[] = $this->node($i++, 'end', 'end', ['label' => 'Done']);

        $e = [
            $this->edge('trigger', 'out', 'intro'),
            $this->edge('intro', 'out', 'ask_awb'),
            $this->edge('ask_awb', 'out', 'next'),
            $this->edge('next', 'p0', 'where'),
            $this->edge('next', 'p1', 'reschedule'),
            $this->edge('next', 'p2', 'assign'),
            $this->edge('where', 'out', 'assign'),
            $this->edge('reschedule', 'out', 'assign'),
            $this->edge('assign', 'out', 'tpl_ship'),
            $this->edge('tpl_ship', 'out', 'end'),
        ];

        return [
            'name'        => 'Parcel tracking (logistics)',
            'description' => 'AWB lookup, reschedule, and dispatch handoff. Session replies plus a Meta-approved shipment-update template slot.',
            'category'    => 'logistics',
            'graph'       => $this->graph($n, $e),
        ];
    }
}
