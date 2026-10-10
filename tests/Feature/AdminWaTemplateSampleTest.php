<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WaTemplate;
use App\Models\WaTemplateSample;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
        $this->assertGreaterThanOrEqual(40, WaTemplateSample::count());
        $this->assertTrue(WaTemplateSample::where('slug', 'christmas_greetings')->exists());
        $this->assertTrue(WaTemplateSample::where('slug', 'abandoned_cart')->exists());
        $this->assertTrue(WaTemplateSample::where('slug', 'eid_mubarak')->exists());
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

    public function test_admin_can_edit_and_save_a_sample(): void
    {
        $admin = $this->platformAdmin();
        $row = WaTemplateSample::where('slug', 'christmas_greetings')->firstOrFail();

        $this->actingAs($admin)->put(route('admin.template-samples.update', $row->id), [
            'title'         => 'Christmas greetings 2026',
            'slug'          => 'christmas_greetings',
            'category'      => 'festival',
            'meta_category' => 'marketing',
            'header'        => 'Merry Christmas',
            'body'          => 'Hello {{name}}, the 2026 greeting is ready for your list.',
            'footer'        => 'Reply STOP to unsubscribe',
            'emoji'         => '🎄',
            'color_from'    => '#7F1D1D',
            'color_to'      => '#B91C1C',
            'is_active'     => '1',
        ])->assertRedirect(route('admin.template-samples.index'));

        $row->refresh();
        $this->assertSame('Christmas greetings 2026', $row->title);
        $this->assertSame('Hello {{name}}, the 2026 greeting is ready for your list.', $row->body);
    }

    public function test_push_installs_a_template_in_the_customer_workspace(): void
    {
        $admin = $this->platformAdmin();
        $customer = $this->customer();
        $row = WaTemplateSample::where('slug', 'christmas_greetings')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('admin.template-samples.push', $row->id))
            ->assertRedirect(route('admin.template-samples.index'));

        $tpl = WaTemplate::query()
            ->where('workspace_id', $customer->current_workspace_id)
            ->where('source_sample_id', $row->id)
            ->first();
        $this->assertNotNull($tpl);
        $this->assertSame('christmas_greetings', $tpl->template_name);
        $this->assertNotNull($row->fresh()->last_pushed_at);

        $this->actingAs($customer)
            ->get(route('user.templates.index', ['view' => 'yours']))
            ->assertOk()
            ->assertSee('christmas_greetings');
    }

    public function test_save_and_push_updates_unsubmitted_copies_and_skips_meta(): void
    {
        $admin = $this->platformAdmin();
        $openOwner = $this->customer();
        $lockedOwner = $this->customer();
        $row = WaTemplateSample::where('slug', 'christmas_greetings')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.template-samples.push', $row->id));

        $open = WaTemplate::query()
            ->where('workspace_id', $openOwner->current_workspace_id)
            ->where('source_sample_id', $row->id)
            ->firstOrFail();

        $locked = WaTemplate::query()
            ->where('workspace_id', $lockedOwner->current_workspace_id)
            ->where('source_sample_id', $row->id)
            ->firstOrFail();
        $locked->update([
            'meta_template_id' => '999',
            'template_body'    => 'Locked on Meta.',
        ]);

        $this->actingAs($admin)->put(route('admin.template-samples.update', $row->id), [
            'title'              => 'Christmas greetings',
            'slug'               => 'christmas_greetings',
            'category'           => 'festival',
            'meta_category'      => 'marketing',
            'header'             => 'Merry Christmas',
            'body'               => 'Hello {{name}}, this push should replace unsubmitted copies only.',
            'footer'             => 'Reply STOP to unsubscribe',
            'emoji'              => '🎄',
            'color_from'         => '#7F1D1D',
            'color_to'           => '#B91C1C',
            'is_active'          => '1',
            'push_to_customers'  => '1',
        ])->assertRedirect(route('admin.template-samples.index'));

        $this->assertStringContainsString('unsubmitted copies only', (string) $open->fresh()->template_body);
        $this->assertSame('Locked on Meta.', (string) $locked->fresh()->template_body);
    }

    public function test_selective_push_only_targets_chosen_workspaces(): void
    {
        $admin = $this->platformAdmin();
        $target = $this->customer();
        $other = $this->customer();
        $row = WaTemplateSample::where('slug', 'christmas_greetings')->firstOrFail();

        $this->actingAs($admin)->post(route('admin.template-samples.push', $row->id), [
            'scope'         => 'selected',
            'workspace_ids' => [$target->current_workspace_id],
        ])->assertRedirect(route('admin.template-samples.index'));

        $this->assertNotNull(WaTemplate::query()
            ->where('workspace_id', $target->current_workspace_id)
            ->where('source_sample_id', $row->id)
            ->first());
        $this->assertNull(WaTemplate::query()
            ->where('workspace_id', $other->current_workspace_id)
            ->where('source_sample_id', $row->id)
            ->first());
    }

    public function test_admin_push_status_page_loads(): void
    {
        $admin = $this->platformAdmin();
        $this->actingAs($admin)
            ->get(route('admin.template-samples.push-status'))
            ->assertOk()
            ->assertSee('Push status');
    }

    public function test_customer_cannot_push_samples(): void
    {
        $customer = $this->customer();
        $row = WaTemplateSample::where('slug', 'christmas_greetings')->firstOrFail();

        $this->actingAs($customer)
            ->post(route('admin.template-samples.push', $row->id))
            ->assertForbidden();
    }

    public function test_admin_can_open_edit_and_save_a_header_image(): void
    {
        $admin = $this->platformAdmin();
        $customer = $this->customer();
        $row = WaTemplateSample::where('slug', 'christmas_greetings')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('admin.template-samples.create'))
            ->assertOk()
            ->assertSee('Header image')
            ->assertSee('Body text');

        $this->actingAs($admin)
            ->get(route('admin.template-samples.edit', $row->id))
            ->assertOk()
            ->assertSee('christmas_greetings')
            ->assertSee('Header image')
            ->assertSee('Body text');

        $this->actingAs($admin)->put(route('admin.template-samples.update', $row->id), [
            'title'              => 'Christmas greetings',
            'slug'               => 'christmas_greetings',
            'category'           => 'festival',
            'meta_category'      => 'marketing',
            'header_type'        => 'image',
            'header'             => 'Merry Christmas',
            'body'               => 'Hello {{name}}, wishing you a joyful Christmas with a photo header.',
            'footer'             => 'Reply STOP to unsubscribe',
            'emoji'              => '🎄',
            'color_from'         => '#7F1D1D',
            'color_to'           => '#B91C1C',
            'is_active'          => '1',
            'image'              => UploadedFile::fake()->image('banner.jpg', 640, 360),
            'push_to_customers'  => '1',
        ])->assertRedirect(route('admin.template-samples.index'));

        $row->refresh();
        $this->assertSame('image', $row->header_type);
        $this->assertNotEmpty($row->image_path);

        $tpl = WaTemplate::query()
            ->where('workspace_id', $customer->current_workspace_id)
            ->where('source_sample_id', $row->id)
            ->first();
        $this->assertNotNull($tpl);
        $this->assertSame('image', $tpl->attachment_type);
        $this->assertSame($row->image_path, $tpl->attachment_file);
    }
}
