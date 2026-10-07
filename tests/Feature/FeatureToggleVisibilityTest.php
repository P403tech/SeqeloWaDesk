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

    public function test_customer_preview_lists_closed_apps(): void
    {
        SystemSetting::set(FeatureRegistry::FLAG_PREFIX.'ai-training', false, 'bool');
        FeatureRegistry::flushMemo();

        $preview = FeatureRegistry::customerPreview();
        $hiddenKeys = array_column($preview['hidden'], 'key');
        $this->assertContains('ai-training', $hiddenKeys);
        $this->assertSame('access_ai_chat_assistant', FeatureRegistry::planGate('ai-training'));
        $this->assertSame('access_chatbot_widgets', FeatureRegistry::planGate('chatbot-widgets'));
    }
}
