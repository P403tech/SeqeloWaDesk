<?php

namespace Tests\Feature;

use App\Models\Flow;
use App\Models\FlowTemplate;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminFlowTemplateTest extends TestCase
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

    private function sampleTemplate(): FlowTemplate
    {
        return FlowTemplate::create([
            'name'      => 'Welcome bot',
            'flow_type' => 'chat',
            'category'  => 'support',
            'flow_data' => [
                'flowNodes' => [
                    ['id' => 't1', 'type' => 'trigger', 'data' => ['kind' => 'keyword', 'keywords' => 'hi']],
                    ['id' => 'm1', 'type' => 'message', 'data' => ['text' => 'Hello!']],
                ],
                'flowEdges' => [],
            ],
            'is_active' => true,
        ]);
    }

    public function test_tenant_can_clone_active_flow_template(): void
    {
        $tpl = $this->sampleTemplate();
        $customer = $this->customer();

        $response = $this->actingAs($customer)
            ->post(route('user.flows.templates.clone', $tpl->id));
        $response->assertRedirect();

        $flow = Flow::query()
            ->where('workspace_id', $customer->current_workspace_id)
            ->latest('id')
            ->first();
        $this->assertNotNull($flow);
        $this->assertSame('Welcome bot', $flow->flow_name);
        $this->assertFalse($flow->is_published);
        $this->assertSame(1, (int) $tpl->fresh()->clone_count);
    }

    public function test_push_installs_draft_flow_in_customer_workspace(): void
    {
        $admin = $this->platformAdmin();
        $customer = $this->customer();
        $tpl = $this->sampleTemplate();

        $this->actingAs($admin)
            ->post(route('admin.flow-templates.push', $tpl->id))
            ->assertRedirect(route('admin.flow-templates.index'));

        $flow = Flow::query()
            ->where('workspace_id', $customer->current_workspace_id)
            ->where('source_flow_template_id', $tpl->id)
            ->first();
        $this->assertNotNull($flow);
        $this->assertFalse($flow->is_published);
        $this->assertNotNull($tpl->fresh()->last_pushed_at);
    }

    public function test_push_skips_published_copies(): void
    {
        $admin = $this->platformAdmin();
        $customer = $this->customer();
        $tpl = $this->sampleTemplate();

        $this->actingAs($admin)->post(route('admin.flow-templates.push', $tpl->id));
        $flow = Flow::query()
            ->where('workspace_id', $customer->current_workspace_id)
            ->where('source_flow_template_id', $tpl->id)
            ->firstOrFail();
        $flow->update(['is_published' => true]);

        FlowTemplate::findOrFail($tpl->id)->update([
            'flow_data' => [
                'flowNodes' => [
                    ['id' => 't1', 'type' => 'trigger', 'data' => ['kind' => 'keyword', 'keywords' => 'hello']],
                    ['id' => 'm1', 'type' => 'message', 'data' => ['text' => 'Updated copy']],
                ],
                'flowEdges' => [],
            ],
        ]);

        $this->actingAs($admin)->post(route('admin.flow-templates.push', $tpl->id));

        $nodes = $flow->fresh()->decoded_flow_data['flowNodes'] ?? [];
        $text = (string) ($nodes[1]['data']['text'] ?? '');
        $this->assertStringNotContainsString('Updated copy', $text);
    }

    public function test_customer_cannot_push_flow_templates(): void
    {
        $customer = $this->customer();
        $tpl = $this->sampleTemplate();

        $this->actingAs($customer)
            ->post(route('admin.flow-templates.push', $tpl->id))
            ->assertForbidden();
    }
}
