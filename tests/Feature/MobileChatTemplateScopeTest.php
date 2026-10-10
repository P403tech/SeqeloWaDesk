<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\User;
use App\Models\WaTemplate;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileChatTemplateScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_template_rejects_other_workspace_template_id(): void
    {
        $ownerA = User::create([
            'name' => 'A', 'email' => 'a_' . uniqid() . '@example.com',
            'password' => bcrypt('password'), 'role' => 'user',
        ]);
        $wsA = Workspace::create([
            'name' => 'WS A', 'slug' => 'ws-a-' . uniqid(),
            'owner_user_id' => $ownerA->id, 'user_id' => $ownerA->id,
            'plan' => 1, 'status' => 1,
        ]);
        $wsA->members()->attach($ownerA->id, ['role' => 'owner', 'joined_at' => now()]);
        $ownerA->forceFill(['current_workspace_id' => $wsA->id])->save();

        $ownerB = User::create([
            'name' => 'B', 'email' => 'b_' . uniqid() . '@example.com',
            'password' => bcrypt('password'), 'role' => 'user',
        ]);
        $wsB = Workspace::create([
            'name' => 'WS B', 'slug' => 'ws-b-' . uniqid(),
            'owner_user_id' => $ownerB->id, 'user_id' => $ownerB->id,
            'plan' => 1, 'status' => 1,
        ]);
        $wsB->members()->attach($ownerB->id, ['role' => 'owner', 'joined_at' => now()]);

        $foreignTpl = WaTemplate::create([
            'user_id'       => $ownerB->id,
            'workspace_id'  => $wsB->id,
            'template_name' => 'foreign_tpl',
            'template_body' => 'Secret',
            'category'      => 'marketing',
            'channel'       => 'baileys',
            'status'        => 'approved',
            'language'      => 'en_US',
        ]);

        $convo = Conversation::create([
            'workspace_id' => $wsA->id,
            'user_id'      => $ownerA->id,
            'title'        => 'Test chat',
            'raw_jid'      => '15551234567@s.whatsapp.net',
            'preview'      => 'Hi',
        ]);

        Sanctum::actingAs($ownerA);

        $this->postJson("/api/app/chats/{$convo->id}/template", [
            'template_id' => $foreignTpl->id,
        ])->assertJson(['success' => false])
            ->assertStatus(404);
    }
}
