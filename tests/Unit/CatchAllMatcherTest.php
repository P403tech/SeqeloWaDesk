<?php

namespace Tests\Unit;

use App\Services\Inbox\CatchAllMatcher;
use PHPUnit\Framework\TestCase;

class CatchAllMatcherTest extends TestCase
{
    public function test_trigger_only_graph_is_not_runnable(): void
    {
        $this->assertFalse(CatchAllMatcher::graphIsRunnable([
            'flowNodes' => [['id' => 'n1', 'type' => 'trigger', 'isStart' => true]],
            'flowEdges' => [],
        ]));
    }

    public function test_wired_graph_is_runnable(): void
    {
        $this->assertTrue(CatchAllMatcher::graphIsRunnable([
            'flowNodes' => [
                ['id' => 'n1', 'type' => 'trigger', 'isStart' => true],
                ['id' => 'n2', 'type' => 'send'],
            ],
            'flowEdges' => [['source' => 'n1', 'target' => 'n2']],
        ]));
    }

    public function test_empty_older_catch_all_loses_to_newer_runnable(): void
    {
        $chosen = CatchAllMatcher::chooseCatchAll([
            ['id' => 11, 'device_id' => 9, 'published_ts' => 100, 'runnable' => false],
            ['id' => 10, 'device_id' => 9, 'published_ts' => 200, 'runnable' => true],
        ]);
        $this->assertNotNull($chosen);
        $this->assertSame(10, $chosen['id']);
    }

    public function test_newest_published_wins_among_runnable(): void
    {
        $chosen = CatchAllMatcher::chooseCatchAll([
            ['id' => 1, 'device_id' => 9, 'published_ts' => 50, 'runnable' => true],
            ['id' => 2, 'device_id' => 9, 'published_ts' => 80, 'runnable' => true],
        ]);
        $this->assertSame(2, $chosen['id']);
    }

    public function test_device_bound_beats_workspace_wide(): void
    {
        $chosen = CatchAllMatcher::chooseCatchAll([
            ['id' => 5, 'device_id' => null, 'published_ts' => 999, 'runnable' => true],
            ['id' => 6, 'device_id' => 9, 'published_ts' => 10, 'runnable' => true],
        ]);
        $this->assertSame(6, $chosen['id']);
    }

    public function test_all_dead_graphs_yield_nothing(): void
    {
        $this->assertNull(CatchAllMatcher::chooseCatchAll([
            ['id' => 11, 'device_id' => 9, 'published_ts' => 100, 'runnable' => false],
        ]));
    }

    public function test_keyword_token_is_not_treated_as_catch_all_only(): void
    {
        [$real, $catch] = CatchAllMatcher::splitTriggerTokens('hi, any');
        $this->assertSame(['hi'], $real);
        $this->assertTrue($catch);
    }
}
