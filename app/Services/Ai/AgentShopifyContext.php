<?php

namespace App\Services\Ai;

use App\Models\ShopifyIntegration;
use App\Services\Shopify\ShopifyService;
use Illuminate\Support\Facades\Log;

/**
 * Live Shopify facts for the smart agent. Shop Manager is a prompt router;
 * this is the actual store snapshot so the model does not invent SKUs.
 */
class AgentShopifyContext
{
    public static function promptBlock(int $workspaceId): string
    {
        if ($workspaceId <= 0) {
            return '';
        }

        try {
            $shop = ShopifyIntegration::query()
                ->where('workspace_id', $workspaceId)
                ->orderByDesc('id')
                ->first();
            if (! $shop) {
                return '';
            }
            if (method_exists($shop, 'isConnected') && ! $shop->isConnected()) {
                return '';
            }

            $svc = app(ShopifyService::class);
            $products = $svc->getProducts($shop, 8);
            $orders = $svc->getOrders($shop, 5);

            $lines = [];
            $lines[] = 'Connected Shopify store: '.trim((string) ($shop->store_name ?: $shop->store_url)).' ('.trim((string) $shop->store_url).').';
            $lines[] = 'Only confirm price, stock, or order status from this list. If it is not here, say you will check — never invent.';

            if ($products) {
                $bits = [];
                foreach (array_slice($products, 0, 8) as $p) {
                    $title = trim((string) ($p['title'] ?? ''));
                    if ($title === '') {
                        continue;
                    }
                    $price = '';
                    $v = $p['variants'][0] ?? [];
                    if (! empty($v['price'])) {
                        $price = ' · '.$v['price'].($shop->shop_currency ? ' '.$shop->shop_currency : '');
                    }
                    $bits[] = $title.$price;
                }
                if ($bits) {
                    $lines[] = 'Products: '.implode('; ', $bits).'.';
                }
            }

            if ($orders) {
                $bits = [];
                foreach (array_slice($orders, 0, 5) as $o) {
                    $name = trim((string) ($o['name'] ?? ('#'.($o['order_number'] ?? ''))));
                    $st = trim((string) ($o['financial_status'] ?? ''));
                    $ff = trim((string) ($o['fulfillment_status'] ?? 'unfulfilled'));
                    $bits[] = trim($name.' '.$st.'/'.$ff);
                }
                if ($bits) {
                    $lines[] = 'Recent orders (id / payment / fulfillment): '.implode('; ', $bits).'.';
                }
            }

            return implode("\n", $lines);
        } catch (\Throwable $e) {
            Log::info('[AI-SHOPIFY] context skipped: '.$e->getMessage());

            return '';
        }
    }
}
