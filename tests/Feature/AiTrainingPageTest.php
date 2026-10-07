<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiTrainingPageTest extends TestCase
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

    public function test_ai_training_index_renders(): void
    {
        $user = $this->actingWorkspaceAdmin();

        $response = $this->actingAs($user)->get(route('user.ai-training.index'));
        $response->assertStatus(200);
        $response->assertSee('AI');
        $response->assertSee('New smart agent');
    }

    public function test_create_wizard_embeds_defaults_outside_html_attributes(): void
    {
        $user = $this->actingWorkspaceAdmin();

        $response = $this->actingAs($user)->get(route('user.ai-training.create'));
        $response->assertStatus(200);
        $response->assertSee('id="ait-builder-defaults"', false);
        $response->assertSee('\u0027s intent', false);
        $response->assertDontSee("data-defaults='", false);
    }

    public function test_can_save_a_new_assistant(): void
    {
        $user = $this->actingWorkspaceAdmin();

        $response = $this->actingAs($user)->postJson(route('user.ai-training.api.assistant.save'), [
            'name' => 'Sadaf',
            'greeting' => 'Hi! How can I help?',
            'ai_provider' => 'openai',
            'ai_model' => 'gemini-2.5-flash-lite',
            'reply_max_tokens' => 400,
            'temperature' => 0.4,
            'status' => 'active',
            'channel_whatsapp' => true,
        ]);
        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $this->assertDatabaseHas('ai_chat_assistants', [
            'name' => 'Sadaf',
            'ai_provider' => 'gemini',
            'ai_model' => 'gemini-2.5-flash-lite',
        ]);
    }
}
