<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WizardBootstrapTest extends TestCase
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

    public function test_chatbot_widget_wizard_embeds_json_outside_attributes(): void
    {
        $user = $this->actingWorkspaceAdmin();
        $response = $this->actingAs($user)->get(route('user.chatbot-widgets.create'));
        $response->assertStatus(200);
        $response->assertSee('id="cbw-builder-defaults"', false);
        $response->assertSee('\u0027d like', false);
        $response->assertDontSee("data-defaults='", false);
    }

    public function test_wa_links_wizard_embeds_json_outside_attributes(): void
    {
        $user = $this->actingWorkspaceAdmin();
        $response = $this->actingAs($user)->get(route('user.wa-links.create'));
        $response->assertStatus(200);
        $response->assertSee('id="wcl-builder-defaults"', false);
        $response->assertSee('\u0027d like', false);
        $response->assertDontSee("data-defaults='", false);
    }
}
