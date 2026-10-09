<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WaTemplateSample;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminWaTemplateSampleTest extends TestCase
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

    private function customer(): User
    {
        $user = User::create([
            'name'     => 'Customer Owner',
            'email'    => 'cust_' . uniqid() . '@example.com',
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

        return $user;
    }

    public function test_migration_seeds_the_shipped_catalog(): void
    {
        $this->assertGreaterThanOrEqual(12, WaTemplateSample::count());
        $this->assertTrue(WaTemplateSample::where('slug', 'christmas_greetings')->exists());
    }

    public function test_admin_can_create_a_sample_and_tenants_see_it(): void
    {
        $admin = $this->platformAdmin();

        $this->actingAs($admin)->post(route('admin.template-samples.store'), [
            'title'         => 'Ramadan greeting',
            'slug'          => 'ramadan_greeting',
            'category'      => 'festival',
            'meta_category' => 'marketing',
            'header'        => 'Ramadan Mubarak',
            'body'          => 'Hello {{name}}, Ramadan Mubarak. May this month bring peace.',
            'emoji'         => '🌙',
            'color_from'    => '#0F172A',
            'color_to'      => '#22C55E',
            'is_active'     => '1',
            'buttons'       => [
                ['type' => 'quick_reply', 'text' => 'Thank you', 'value' => ''],
            ],
        ])->assertRedirect(route('admin.template-samples.index'));

        $this->assertDatabaseHas('wa_template_samples', [
            'slug'   => 'ramadan_greeting',
            'header' => 'Ramadan Mubarak',
        ]);

        $customer = $this->customer();
        $this->actingAs($customer)
            ->get(route('user.templates.samples'))
            ->assertOk()
            ->assertSee('ramadan_greeting')
            ->assertSee('Ramadan Mubarak');
    }

    public function test_hidden_sample_is_not_on_the_tenant_gallery(): void
    {
        $admin = $this->platformAdmin();
        $row = WaTemplateSample::where('slug', 'christmas_greetings')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.template-samples.toggle', $row->id))
            ->assertRedirect();

        $this->assertFalse($row->fresh()->is_active);

        $customer = $this->customer();
        $this->actingAs($customer)
            ->get(route('user.templates.samples'))
            ->assertOk()
            ->assertDontSee('christmas_greetings')
            ->assertSee('management_course');
    }

    public function test_use_sample_still_fills_create_from_the_library(): void
    {
        $customer = $this->customer();
        $this->actingAs($customer)
            ->get(route('user.templates.create', ['sample' => 'christmas_greetings']))
            ->assertOk()
            ->assertSee('Started from sample')
            ->assertSee('wishing you a joyful Christmas');
    }
}
