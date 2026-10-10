<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flow_templates', function (Blueprint $table) {
            $table->timestamp('last_pushed_at')->nullable()->after('clone_count');
        });

        Schema::table('flows', function (Blueprint $table) {
            $table->unsignedBigInteger('source_flow_template_id')->nullable()->after('workspace_id');
            $table->index(['workspace_id', 'source_flow_template_id'], 'flows_ws_source_flow_tpl_idx');
        });
    }

    public function down(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            $table->dropIndex('flows_ws_source_flow_tpl_idx');
            $table->dropColumn('source_flow_template_id');
        });

        Schema::table('flow_templates', function (Blueprint $table) {
            $table->dropColumn('last_pushed_at');
        });
    }
};
