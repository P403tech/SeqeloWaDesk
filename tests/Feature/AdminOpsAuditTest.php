<?php

namespace Tests\Feature;

use App\Models\AdminAiKey;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\AiProviderErrorInbox;
use App\Support\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOpsAuditTest extends TestCase
{
    use RefreshDatabase;

    private function platformAdmin(): User
    {
        return User::create([
            'name'     => 'Platform Admin',
            'email'    => 'padmin_' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'role'     => 'admin',
        ]);
    }

    public function test_feature_toggle_save_writes_audit_log(): void
    {
        $admin = $this->platformAdmin();
        FeatureRegistry::flushMemo();
        $keys = FeatureRegistry::keys();
        $show = array_values(array_filter($keys, fn ($k) => $k !== 'catalog'));

        $this->actingAs($admin)
            ->post(route('admin.settings.features.update'), ['show' => $show])
            ->assertRedirect();

        $row = AuditLog::query()->where('action', 'admin.features.updated')->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('platform', $row->layer);
        $this->assertContains('catalog', $row->payload['turned_off'] ?? []);
    }

    public function test_ai_key_save_and_toggle_are_audited_without_storing_the_secret(): void
    {
        $admin = $this->platformAdmin();
        $row = AdminAiKey::query()->firstOrCreate(
            ['provider' => 'openai'],
            ['name' => 'OpenAI', 'is_active' => false]
        );

        $this->actingAs($admin)
            ->patch(route('admin.api-keys.update', $row->id), [
                'api_key'       => 'sk-test-secret-value',
                'default_model' => 'gpt-4o-mini',
            ])
            ->assertRedirect();

        $updated = AuditLog::query()->where('action', 'admin.ai_key.updated')->latest('id')->first();
        $this->assertNotNull($updated);
        $this->assertSame('openai', $updated->payload['provider'] ?? null);
        $this->assertTrue((bool) ($updated->payload['key_replaced'] ?? false));
        $encoded = json_encode($updated->payload);
        $this->assertStringNotContainsString('sk-test-secret-value', (string) $encoded);

        $this->actingAs($admin)
            ->post(route('admin.api-keys.toggle', $row->id))
            ->assertRedirect();

        $this->assertTrue(
            AuditLog::query()->whereIn('action', ['admin.ai_key.activated', 'admin.ai_key.deactivated'])->exists()
        );
    }

    public function test_audit_csv_export_honours_search_and_layer_filters(): void
    {
        $admin = $this->platformAdmin();
        AuditLog::create([
            'layer'         => 'platform',
            'actor_user_id' => $admin->id,
            'action'        => 'admin.features.updated',
            'payload'       => ['turned_off' => ['catalog']],
            'result'        => 'success',
            'created_at'    => now(),
        ]);
        AuditLog::create([
            'layer'         => 'workspace',
            'actor_user_id' => $admin->id,
            'action'        => 'workspace.created',
            'payload'       => ['name' => 'Other'],
            'result'        => 'success',
            'created_at'    => now(),
        ]);

        $response = $this->actingAs($admin)->get(route('admin.audit-log.export', [
            'layer' => 'platform',
            'q'     => 'features',
        ]));
        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('admin.features.updated', $csv);
        $this->assertStringNotContainsString('workspace.created', $csv);
    }

    public function test_ai_dashboard_shows_recorded_llm_errors(): void
    {
        $admin = $this->platformAdmin();
        AiProviderErrorInbox::record('gemini', 'No gemini API key. Add one under Admin → API keys.', 0, 'gemini-flash-latest');

        $this->actingAs($admin)
            ->get(route('admin.ai-dashboard'))
            ->assertOk()
            ->assertSee('LLM errors')
            ->assertSee('No gemini API key');
    }
}
