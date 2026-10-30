<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Support\WorkspaceContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WorkspaceContextIsolationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkspaceContext::clear();

        Schema::dropIfExists('contacts');
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('workspace_id')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        DB::table('contacts')->insert([
            ['id' => 1, 'user_id' => 7, 'workspace_id' => 1, 'name' => 'a', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'user_id' => 8, 'workspace_id' => 2, 'name' => 'b', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'user_id' => 7, 'workspace_id' => null, 'name' => 'legacy', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    protected function tearDown(): void
    {
        WorkspaceContext::clear();
        parent::tearDown();
    }

    public function test_unset_context_does_not_hide_rows(): void
    {
        $this->assertEqualsCanonicalizing([1, 2, 3], Contact::query()->pluck('id')->all());
    }

    public function test_bound_workspace_hides_other_tenants_and_keeps_legacy_rows(): void
    {
        WorkspaceContext::set(1, 7, false);

        $this->assertEqualsCanonicalizing([1, 3], Contact::query()->pluck('id')->all());
    }

    public function test_impersonation_does_not_pull_in_orphan_rows(): void
    {
        WorkspaceContext::set(2, 7, true);

        $this->assertSame([2], Contact::query()->pluck('id')->all());
    }
}
