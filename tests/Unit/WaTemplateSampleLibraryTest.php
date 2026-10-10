<?php

namespace Tests\Unit;

use App\Support\WaTemplateSampleLibrary;
use PHPUnit\Framework\TestCase;

class WaTemplateSampleLibraryTest extends TestCase
{
    public function test_catalog_covers_wati_style_categories(): void
    {
        $all = WaTemplateSampleLibrary::all();
        $this->assertGreaterThanOrEqual(40, count($all));

        $cats = array_unique(array_column($all, 'category'));
        foreach (['festival', 'ecommerce', 'education', 'healthcare', 'utility'] as $need) {
            $this->assertContains($need, $cats, "missing category {$need}");
        }

        $slugs = array_column($all, 'slug');
        $this->assertContains('christmas_greetings', $slugs);
        $this->assertContains('end_of_season_sale', $slugs);
        $this->assertContains('management_course', $slugs);
        $this->assertContains('abandoned_cart', $slugs);
        $this->assertContains('eid_mubarak', $slugs);
        $this->assertContains('booking_confirmed', $slugs);
    }

    public function test_bodies_are_meta_safe_and_named(): void
    {
        foreach (WaTemplateSampleLibrary::all() as $row) {
            $body = trim((string) $row['body']);
            $this->assertNotSame('', $body, $row['slug']);
            $this->assertFalse(str_starts_with($body, '{{'), $row['slug'].' starts with a variable');
            $this->assertFalse(str_ends_with($body, '}}'), $row['slug'].' ends with a variable');
            $this->assertMatchesRegularExpression('/\{\{[a-z0-9_]+\}\}/', $body, $row['slug'].' should use named tokens');
            $this->assertLessThanOrEqual(1024, mb_strlen($body));
            $this->assertLessThanOrEqual(60, mb_strlen((string) $row['header']));
        }
    }

    public function test_find_and_filter(): void
    {
        $row = WaTemplateSampleLibrary::find('christmas_greetings');
        $this->assertNotNull($row);
        $this->assertSame('festival', $row['category']);
        $this->assertSame('marketing', $row['meta_category']);
        $this->assertNotEmpty($row['buttons']);

        $this->assertNull(WaTemplateSampleLibrary::find('not_a_real_sample'));

        $fest = WaTemplateSampleLibrary::filter(null, 'festival');
        $this->assertNotEmpty($fest);
        foreach ($fest as $s) {
            $this->assertSame('festival', $s['category']);
        }

        $search = WaTemplateSampleLibrary::filter('christmas', 'all');
        $this->assertCount(1, $search);
        $this->assertSame('christmas_greetings', $search[0]['slug']);
    }
}
