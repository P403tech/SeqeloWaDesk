<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Workspace;
use App\Models\ZohoIntegration;
use App\Models\ZohoIntegrationLog;
use App\Services\Zoho\ZohoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZohoIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_zoho_service_defaults_and_datacenters(): void
    {
        $service = app(ZohoService::class);

        $this->assertFalse($service->isEnabled());
        $this->assertSame('ZohoCRM.modules.ALL,ZohoCRM.users.READ,ZohoCRM.org.READ', $service->scopes());

        // Test multi-DC resolution
        $this->assertSame('accounts.zoho.com', $service->accountsServer('com'));
        $this->assertSame('accounts.zoho.eu', $service->accountsServer('eu'));
        $this->assertSame('accounts.zoho.in', $service->accountsServer('in'));
        $this->assertSame('accounts.zoho.com.au', $service->accountsServer('com.au'));
        $this->assertSame('accounts.zoho.jp', $service->accountsServer('jp'));
        $this->assertSame('accounts.zoho.zohocloud.ca', $service->accountsServer('ca'));

        SystemSetting::set('zoho_client_id', 'test_client_id', 'string');
        $url = $service->authorizeUrl('test_state_123', 'in');

        $this->assertStringContainsString('accounts.zoho.in', $url);
        $this->assertStringContainsString('client_id=test_client_id', $url);
        $this->assertStringContainsString('state=test_state_123', $url);
        $this->assertStringContainsString('access_type=offline', $url);
    }

    protected function createWorkspaceAndUser(): array
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

        $user->forceFill(['current_workspace_id' => $ws->id])->save();

        return [$user, $ws];
    }

    public function test_zoho_models_and_encryption(): void
    {
        [$user, $ws] = $this->createWorkspaceAndUser();

        $integration = ZohoIntegration::create([
            'workspace_id'    => $ws->id,
            'user_id'         => $user->id,
            'org_id'          => 'org_12345',
            'org_name'        => 'Acme Global',
            'org_email'       => 'admin@acme.com',
            'api_domain'      => 'https://www.zohoapis.com',
            'accounts_server' => 'accounts.zoho.com',
            'access_token'    => 'secret_access_token_123',
            'refresh_token'   => 'secret_refresh_token_456',
            'status'          => 'active',
        ]);

        $this->assertTrue($integration->isConnected());
        $this->assertSame('Acme Global', $integration->org_name);
        $this->assertSame($ws->id, $integration->workspace->id);

        $log = ZohoIntegrationLog::create([
            'integration_id' => $integration->id,
            'event_type'     => 'contact.upsert',
            'status'         => 'sent',
            'object_id'      => 'contact_999',
            'payload'        => ['First_Name' => 'Alice'],
            'created_at'     => now(),
        ]);

        $this->assertSame($integration->id, $log->integration->id);
        $this->assertSame(1, $integration->logs()->count());
    }

    public function test_zoho_dashboard_route_loads_for_authenticated_user(): void
    {
        [$user, $ws] = $this->createWorkspaceAndUser();

        $response = $this->actingAs($user)->get(route('user.zoho'));
        $response->assertStatus(200);
        $response->assertSee('Zoho CRM');
    }

    public function test_zoho_oauth_callback_guards_state(): void
    {
        [$user, $ws] = $this->createWorkspaceAndUser();

        $response = $this->actingAs($user)->get(route('zoho.oauth.callback', ['state' => 'invalid_state', 'code' => 'test_code']));
        $response->assertRedirect(route('user.zoho'));
        $response->assertSessionHas('error', 'OAuth state mismatch.');
    }

    public function test_automation_guide_loads_for_zapier_and_make(): void
    {
        [$user, $ws] = $this->createWorkspaceAndUser();

        $respZapier = $this->actingAs($user)->get(route('user.integrations.automation', ['platform' => 'zapier']));
        $respZapier->assertStatus(200);
        $respZapier->assertSee('Zapier');
        $respZapier->assertSee('conversation.received');

        $respMake = $this->actingAs($user)->get(route('user.integrations.automation', ['platform' => 'make']));
        $respMake->assertStatus(200);
        $respMake->assertSee('Make (Integromat)');
        $respMake->assertSee('conversation.received');
    }
}
