<?php

namespace Tests\Unit;

use App\Services\Ai\ShopManagerRouter;
use PHPUnit\Framework\TestCase;

class ShopManagerRouterTest extends TestCase
{
    public function test_greeting_and_menu_digits(): void
    {
        $r = new ShopManagerRouter();
        $this->assertSame('hi', $r->classify('hi', ['sub' => 'manager']));
        $this->assertSame('menu_pick', $r->classify('2', ['sub' => 'manager']));
        $this->assertSame('track', $r->menuPick('2'));
        $this->assertSame('human', $r->menuPick('10'));
    }

    public function test_catalog_order_return_human(): void
    {
        $r = new ShopManagerRouter();
        $this->assertSame('catalog', $r->classify('red A24 cover', ['sub' => 'manager']));
        $this->assertSame('track', $r->classify('WD-1042', ['sub' => 'manager']));
        $this->assertSame('returns', $r->classify('I want a refund', ['sub' => 'orders']));
        $this->assertSame('human', $r->classify('talk to a person', ['sub' => 'returns']));
    }

    public function test_returns_does_not_stick_on_off_topic(): void
    {
        $r = new ShopManagerRouter();
        $this->assertSame('chitchat', $r->classify('do you sell bitcoin', ['sub' => 'returns']));
    }

    public function test_photo_routes_to_catalog(): void
    {
        $r = new ShopManagerRouter();
        $this->assertSame('catalog', $r->classify('what is this', ['sub' => 'manager'], true));
    }
}
