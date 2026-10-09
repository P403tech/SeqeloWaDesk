<?php

namespace Tests\Feature;

use App\Models\AiChatAssistant;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AiAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmartAgentBuilderP0Test extends TestCase
{
    use RefreshDatabase;

    private function actingWorkspaceAdmin(): User
    {
        $user = User::create([
            'name'     => 'Test Admin',
            'email'    => 'test_' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'role'     => 'admin',
        ]);

        $ws = Workspace::create([
            'name'          => 'Test Workspace',
            'slug'          => 'test-ws-' . uniqid(),
            'owner_user_id' => $user->id,
            'user_id'       => $user->id,
            'plan'          => 1,
            'status'        => 1,
        ]);
        $ws->members()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);
        $user->forceFill(['current_workspace_id' => $ws->id])->save();

        return $user;
    }

    public function test_create_wizard_defaults_paused_and_has_one_catalog_tile(): void
    {
        $user = $this->actingWorkspaceAdmin();
        $html = $this->actingAs($user)->get(route('user.ai-training.create'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('id="ait-model-select"', $html);
        $this->assertStringContainsString('value="paused"', $html);
        $this->assertStringNotContainsString('OpenAI (GPT)', $html);
        $this->assertSame(1, substr_count($html, 'data-add-source="catalog"'));
        $this->assertStringContainsString('data-n="6"', $html);
        $this->assertStringContainsString('data-step="6"', $html);
        $this->assertStringContainsString('Same knowledge on every pipe', $html);
        $knowledgeStart = strpos($html, 'data-step="5"');
        $channelsStart = strpos($html, 'data-step="6"');
        $this->assertNotFalse($knowledgeStart);
        $this->assertNotFalse($channelsStart);
        $this->assertGreaterThan($knowledgeStart, $channelsStart);
        $knowledgePane = substr($html, $knowledgeStart, $channelsStart - $knowledgeStart);
        $this->assertStringNotContainsString('data-field="channel_whatsapp"', $knowledgePane);
        $this->assertStringContainsString('data-field="channel_whatsapp"', $html);
    }

    public function test_saving_an_agent_without_status_starts_paused(): void
    {
        $user = $this->actingWorkspaceAdmin();

        $this->actingAs($user)->postJson(route('user.ai-training.api.assistant.save'), [
            'name'        => 'Draft bot',
            'ai_provider' => 'openai',
            'ai_model'    => 'gpt-4o-mini',
        ])->assertOk();

        $this->assertSame('paused', AiChatAssistant::where('name', 'Draft bot')->value('status'));
    }

    public function test_playground_folds_business_brief_into_the_system_prompt(): void
    {
        $user = $this->actingWorkspaceAdmin();
        $seen = '';

        $this->mock(AiAgentService::class, function ($m) use (&$seen) {
            $m->shouldReceive('callProvider')->once()->andReturnUsing(function (...$args) use (&$seen) {
                foreach ($args as $v) {
                    if (is_string($v) && str_contains($v, 'Business information')) {
                        $seen = $v;
                    }
                }
                if ($seen === '') {
                    $seen = (string) json_encode($args);
                }

                return 'We close at 6pm.';
            });
            $m->shouldReceive('lastProviderError')->andReturn(null);
        });

        $this->actingAs($user)->postJson(route('user.ai-training.api.assistant.test'), [
            'message'        => 'What are your hours?',
            'name'           => 'Hours bot',
            'ai_provider'    => 'openai',
            'ai_model'       => 'gpt-4o-mini',
            'business_brief' => 'We sell tulips. Shop closes at 6pm.',
            'system_prompt'  => 'You are a florist.',
        ])->assertOk()->assertJsonPath('ok', true);

        $this->assertStringContainsString('We sell tulips', $seen);
    }
}
