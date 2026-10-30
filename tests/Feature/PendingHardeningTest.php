<?php

namespace Tests\Feature;

use App\Models\IncomingWebhook;
use App\Models\Pipeline;
use App\Models\WaTemplate;
use App\Support\WorkspaceContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PendingHardeningTest extends TestCase
{
    protected function tearDown(): void
    {
        WorkspaceContext::clear();
        parent::tearDown();
    }

    public function test_template_globals_stay_visible_inside_a_workspace(): void
    {
        Event::fake();
        WorkspaceContext::clear();
        Schema::dropIfExists('wa_templates');
        Schema::create('wa_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('workspace_id')->nullable();
            $table->string('template_name')->nullable();
            $table->timestamps();
        });
        DB::table('wa_templates')->insert([
            ['user_id' => 7, 'workspace_id' => 1, 'template_name' => 'mine', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => 8, 'workspace_id' => 2, 'template_name' => 'theirs', 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => null, 'workspace_id' => null, 'template_name' => 'global', 'created_at' => now(), 'updated_at' => now()],
        ]);

        WorkspaceContext::set(1, 7, false);

        $this->assertEqualsCanonicalizing(
            ['mine', 'global'],
            WaTemplate::query()->toBase()->pluck('template_name')->all()
        );
    }

    public function test_pipeline_scope_matches_workspace_only(): void
    {
        WorkspaceContext::clear();
        Schema::dropIfExists('pipelines');
        Schema::create('pipelines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
        });
        DB::table('pipelines')->insert([
            ['workspace_id' => 1, 'name' => 'a', 'created_at' => now(), 'updated_at' => now()],
            ['workspace_id' => 2, 'name' => 'b', 'created_at' => now(), 'updated_at' => now()],
        ]);

        WorkspaceContext::set(1, 7, false);

        $this->assertSame(['a'], Pipeline::query()->pluck('name')->all());
    }

    public function test_template_send_requires_the_header_secret(): void
    {
        $hook = new IncomingWebhook();
        $hook->template_config = [
            'enabled' => true,
            'template_id' => 1,
            'sender' => 'waba:1',
            'secret_hash' => hash('sha256', 'hook-secret'),
        ];

        $this->assertTrue($hook->templateSendEnabled());
        $this->assertTrue($hook->templateSecretOk('hook-secret'));
        $this->assertFalse($hook->templateSecretOk('wrong'));
        $this->assertFalse($hook->templateSecretOk(null));

        $legacy = new IncomingWebhook();
        $legacy->template_config = ['enabled' => true, 'template_id' => 1, 'sender' => 'waba:1'];
        $this->assertFalse($legacy->templateSecretOk('hook-secret'));
    }

    public function test_incoming_hook_is_post_only(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === 'hooks/in/{token}');

        $this->assertNotNull($route);
        $this->assertContains('POST', $route->methods());
        $this->assertNotContains('GET', $route->methods());
    }

    public function test_quiet_template_sweep_is_scheduled(): void
    {
        $names = collect(app(Schedule::class)->events())
            ->map(fn ($event) => $event->description)
            ->all();

        $this->assertContains('waba-template-sweep', $names);
    }
}
