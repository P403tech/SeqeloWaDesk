<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Support\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeatureToggleVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FeatureRegistry::flushMemo();
    }

    public function test_header_crm_key_follows_crm_dashboard_toggle(): void
    {
        SystemSetting::set(FeatureRegistry::FLAG_PREFIX.'crm-dashboard', false, 'bool');

        $this->assertFalse(FeatureRegistry::visible('crm'));
        $this->assertFalse(FeatureRegistry::visible('crm-dashboard'));
        $this->assertFalse(FeatureRegistry::hrefVisible(url('/crm')));
        $this->assertFalse(FeatureRegistry::hrefVisible(url('/crm/guide')));
    }

    public function test_social_posts_uses_the_real_customer_path(): void
    {
        SystemSetting::set(FeatureRegistry::FLAG_PREFIX.'social-posts', false, 'bool');

        $this->assertFalse(FeatureRegistry::hrefVisible(url('/social/posts')));
        $this->assertContains('/social/posts', FeatureRegistry::hiddenPaths());
    }

    public function test_openai_ads_can_be_closed(): void
    {
        SystemSetting::set(FeatureRegistry::FLAG_PREFIX.'openaiads', false, 'bool');

        $this->assertFalse(FeatureRegistry::visible('openaiads'));
        $this->assertFalse(FeatureRegistry::hrefVisible(url('/openai-ads')));
    }
}
