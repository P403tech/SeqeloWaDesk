<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WaTemplateSampleLibraryPageTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_sample_library_lists_festival_and_education_cards(): void
    {
        [$user] = $this->createWorkspaceAndUser();

        $response = $this->actingAs($user)->get(route('user.templates.samples'));
        $response->assertStatus(200);
        $response->assertSee('christmas_greetings');
        $response->assertSee('management_course');
        $response->assertSee('Use sample');
        $response->assertSee('Festival');
    }

    public function test_use_sample_fills_the_create_form(): void
    {
        [$user] = $this->createWorkspaceAndUser();

        $response = $this->actingAs($user)->get(route('user.templates.create', ['sample' => 'christmas_greetings']));
        $response->assertStatus(200);
        $response->assertSee('christmas_greetings');
        $response->assertSee('Started from sample');
        $response->assertSee('wishing you a joyful Christmas');
        $response->assertSee('Reply STOP to unsubscribe');
    }

    public function test_empty_your_templates_shows_sample_cards(): void
    {
        [$user] = $this->createWorkspaceAndUser();

        $response = $this->actingAs($user)->get(route('user.templates.index', ['view' => 'yours']));
        $response->assertStatus(200);
        $response->assertSee('Start from a sample');
        $response->assertSee('christmas_greetings');
        $response->assertSee('Use sample');
        $response->assertDontSee('No data found');
    }

    public function test_templates_home_shows_samples_to_customer_accounts(): void
    {
        $user = User::create([
            'name'     => 'Customer Owner',
            'email'    => 'customer_' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
            'role'     => 'user',
        ]);

        $ws = Workspace::create([
            'name'          => 'Customer Workspace',
            'slug'          => 'cust-ws-' . uniqid(),
            'owner_user_id' => $user->id,
            'user_id'       => $user->id,
            'plan'          => 1,
            'status'        => 1,
        ]);
        $ws->members()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);
        $user->forceFill(['current_workspace_id' => $ws->id])->save();

        $response = $this->actingAs($user)->get(route('user.templates.index'));
        $response->assertStatus(200);
        $response->assertSee('christmas_greetings');
        $response->assertSee('management_course');
        $response->assertSee('Use sample');
        $response->assertSee('Template Library');
    }

    public function test_samples_category_filter(): void
    {
        [$user] = $this->createWorkspaceAndUser();

        $response = $this->actingAs($user)->get(route('user.templates.samples', ['category' => 'education']));
        $response->assertStatus(200);
        $response->assertSee('management_course');
        $response->assertDontSee('christmas_greetings');
    }
}
