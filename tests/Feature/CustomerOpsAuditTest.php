<?php

namespace Tests\Feature;

use App\Models\AiChatAssistant;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AiProviderErrorInbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerOpsAuditTest extends TestCase
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

    public function test_saving_an_agent_writes_an_audit_row(): void
    {
        $user = $this->actingWorkspaceAdmin();

        $this->actingAs($user)->postJson(route('user.ai-training.api.assistant.save'), [
            'name' => 'Sadaf',
            'ai_provider' => 'openai',
            'ai_model' => 'gpt-4o-mini',
            'status' => 'active',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ai.assistant.created',
            'layer'  => 'workspace',
            'workspace_id' => $user->current_workspace_id,
        ]);
    }

    public function test_pausing_an_agent_is_audited(): void
    {
        $user = $this->actingWorkspaceAdmin();
        $this->actingAs($user)->postJson(route('user.ai-training.api.assistant.save'), [
            'name' => 'Sadaf',
            'ai_provider' => 'openai',
            'ai_model' => 'gpt-4o-mini',
            'status' => 'active',
        ])->assertOk();
        $assistant = AiChatAssistant::where('name', 'Sadaf')->firstOrFail();

        $this->actingAs($user)
            ->post(route('user.ai-training.status', $assistant->id), ['status' => 'paused'])
            ->assertRedirect();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ai.assistant.paused',
            'subject_id' => $assistant->id,
        ]);
    }

    public function test_saving_a_byok_key_is_audited_without_the_secret(): void
    {
        $user = $this->actingWorkspaceAdmin();

        $this->actingAs($user)
            ->post(route('user.settings.aikeys.update', 'openai'), [
                'api_key' => 'sk-customer-secret-value',
            ])
            ->assertRedirect();

        $row = AuditLog::query()->where('action', 'ai.key.saved')->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('openai', $row->payload['provider'] ?? null);
        $this->assertStringNotContainsString('sk-customer-secret-value', (string) json_encode($row->payload));
    }

    public function test_activity_log_export_hides_platform_rows_and_honours_search(): void
    {
        $user = $this->actingWorkspaceAdmin();
        AuditLog::create([
            'layer'         => 'workspace',
            'workspace_id'  => $user->current_workspace_id,
            'actor_user_id' => $user->id,
            'action'        => 'ai.assistant.created',
            'payload'       => ['name' => 'Sadaf'],
            'result'        => 'success',
            'created_at'    => now(),
        ]);
        AuditLog::create([
            'layer'         => 'platform',
            'workspace_id'  => $user->current_workspace_id,
            'actor_user_id' => $user->id,
            'action'        => 'admin.features.updated',
            'payload'       => ['turned_off' => ['catalog']],
            'result'        => 'success',
            'created_at'    => now(),
        ]);

        $csv = $this->actingAs($user)->get(route('user.activity-log.export', [
            'scope' => 'workspace',
            'q'     => 'assistant',
        ]))->assertOk()->streamedContent();

        $this->assertStringContainsString('ai.assistant.created', $csv);
        $this->assertStringNotContainsString('admin.features.updated', $csv);
    }

    public function test_ai_usage_and_agent_list_show_last_provider_error(): void
    {
        $user = $this->actingWorkspaceAdmin();
        AiProviderErrorInbox::record(
            'gemini',
            'No gemini API key. Add one under Admin → API keys.',
            (int) $user->current_workspace_id,
            'gemini-flash-latest'
        );

        $this->actingAs($user)->get(route('user.ai-usage'))
            ->assertOk()
            ->assertSee('Last AI errors')
            ->assertSee('No gemini API key');

        $this->actingAs($user)->get(route('user.ai-training.index'))
            ->assertOk()
            ->assertSee('Last AI errors')
            ->assertSee('No gemini API key');
    }
}
