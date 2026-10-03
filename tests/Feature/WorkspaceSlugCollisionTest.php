<?php

namespace Tests\Feature;

use App\Models\Workspace;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Reproduces the production 500 on workspace creation:
 *
 *   SQLSTATE[23000] 1062 Duplicate entry 'oval-ad-media'
 *   for key 'workspaces_slug_unique'
 *
 * Workspace soft-deletes, but `workspaces_slug_unique` is a plain index that
 * still counts trashed rows. generateSlug() checked through the soft-delete
 * scope, so after a client deleted a workspace and recreated it under the
 * same name it handed back a slug the INSERT was guaranteed to reject.
 */
class WorkspaceSlugCollisionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['notifications', 'workspaces'] as $t) {
            Schema::dropIfExists($t);
        }

        // Workspace uses LogsNotifications, which writes a row on create().
        Schema::create('notifications', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('workspace_id')->nullable();
            $t->text('notification_title')->nullable();
            $t->text('notification_msg')->nullable();
            $t->string('category')->nullable();
            $t->string('severity')->nullable();
            $t->string('icon')->nullable();
            $t->string('source_type')->nullable();
            $t->unsignedBigInteger('source_id')->nullable();
            $t->string('verb')->nullable();
            $t->text('action_url')->nullable();
            $t->boolean('is_urgent')->default(0);
            $t->boolean('status')->default(1);
            $t->timestamps();
        });

        Schema::create('workspaces', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('owner_user_id')->nullable();
            $t->string('name');
            $t->string('slug')->unique();
            $t->string('plan', 32)->default('starter');
            $t->boolean('status')->default(true);
            $t->softDeletes();
            $t->timestamps();
        });
    }

    public function test_generate_slug_skips_a_soft_deleted_slug(): void
    {
        $ws = Workspace::create(['name' => 'Oval Ad Media', 'slug' => 'oval-ad-media']);
        $ws->delete();

        $this->assertSoftDeleted('workspaces', ['id' => $ws->id]);
        $this->assertSame('oval-ad-media-2', Workspace::generateSlug('Oval Ad Media'));
    }

    public function test_recreating_a_deleted_workspace_by_name_no_longer_500s(): void
    {
        Workspace::create(['name' => 'Oval Ad Media', 'slug' => 'oval-ad-media'])->delete();

        // Mirrors WorkspacesController@store.
        $fresh = Workspace::createWithUniqueSlug([
            'name' => 'Oval Ad Media',
            'slug' => Workspace::generateSlug('Oval Ad Media'),
        ]);

        $this->assertTrue($fresh->exists);
        $this->assertSame('oval-ad-media-2', $fresh->slug);
    }

    public function test_create_survives_a_slug_that_collides_at_insert_time(): void
    {
        // A slug that passed the pre-check and then lost the race, i.e. what a
        // double-submit or two concurrent same-named signups produce.
        Workspace::create(['name' => 'Acme', 'slug' => 'acme']);

        $second = Workspace::createWithUniqueSlug(['name' => 'Acme', 'slug' => 'acme']);

        $this->assertTrue($second->exists);
        $this->assertNotSame('acme', $second->slug);
        $this->assertStringStartsWith('acme-', $second->slug);
        $this->assertSame(2, Workspace::count());
    }

    public function test_a_non_slug_unique_violation_is_not_swallowed(): void
    {
        Schema::table('workspaces', fn (Blueprint $t) => $t->unique('name'));

        Workspace::create(['name' => 'Acme', 'slug' => 'acme']);

        // Distinct slug, duplicate name: the retry must not absorb this.
        $this->expectException(UniqueConstraintViolationException::class);
        Workspace::createWithUniqueSlug(['name' => 'Acme', 'slug' => 'acme-other']);
    }
}
