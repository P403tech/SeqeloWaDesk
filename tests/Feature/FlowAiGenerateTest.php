<?php

namespace Tests\Feature;

use App\Models\AdminAiKey;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FlowAiGenerateTest extends TestCase
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

    public function test_generate_requires_a_usable_provider_key(): void
    {
        $user = $this->actingWorkspaceAdmin();

        $this->actingAs($user)->postJson(route('user.flows.api.ai-generate'), [
            'prompt'   => 'Customer support bot',
            'model'    => 'gpt-4o-mini',
            'provider' => 'openai',
        ])->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'no_key');
    }

    public function test_generate_applies_model_json_as_a_flow(): void
    {
        $user = $this->actingWorkspaceAdmin();
        $row = AdminAiKey::query()->firstOrCreate(
            ['provider' => 'openai'],
            ['name' => 'OpenAI', 'is_active' => false]
        );
        $row->api_key = 'sk-test';
        $row->is_active = true;
        $row->default_model = 'gpt-4o-mini';
        $row->save();

        $payload = [
            'flowNodes' => [
                ['id' => 'n_trig01', 'type' => 'trigger', 'x' => 40, 'y' => 200, 'data' => ['kind' => 'keyword', 'keywords' => 'hi']],
                ['id' => 'n_msg001', 'type' => 'message', 'x' => 400, 'y' => 200, 'data' => ['text' => 'Hello']],
                ['id' => 'n_end001', 'type' => 'end', 'x' => 760, 'y' => 200, 'data' => []],
            ],
            'flowEdges' => [
                ['id' => 'e_000001', 'source' => 'n_trig01', 'sourceHandle' => 'out', 'target' => 'n_msg001'],
                ['id' => 'e_000002', 'source' => 'n_msg001', 'sourceHandle' => 'out', 'target' => 'n_end001'],
            ],
        ];

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode($payload)]]],
            ], 200),
        ]);

        $this->actingAs($user)->postJson(route('user.flows.api.ai-generate'), [
            'prompt'    => 'Customer support bot with a greeting',
            'model'     => 'gpt-4o-mini',
            'provider'  => 'openai',
            'flow_type' => 'chat',
        ])->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('flow.flowNodes.0.type', 'trigger')
            ->assertJsonPath('flow.flowNodes.1.type', 'message');
    }
}
