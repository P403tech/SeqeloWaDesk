<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bind a chat widget to a flow.
 *
 * Until now a widget could only reach the AI assistant (`assistant_id`); there
 * was nowhere to record "this widget runs THIS workflow", so the binding the
 * merchant asked for had no home even once the runtime existed.
 *
 * Guarded both ways so a half-applied run can be re-run — the updater replays
 * migrations on installs that may already carry the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chatbot_widgets')) {
            return;
        }
        if (! Schema::hasColumn('chatbot_widgets', 'flow_id')) {
            Schema::table('chatbot_widgets', function (Blueprint $t) {
                // nullOnDelete, NOT cascade: deleting a flow must not delete the
                // widget (and its whole visitor history) with it. The widget
                // falls back to its assistant, and the settings page surfaces
                // that the bound flow is gone.
                $t->foreignId('flow_id')->nullable()->after('assistant_id')
                    ->constrained('flows')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('chatbot_widgets') || ! Schema::hasColumn('chatbot_widgets', 'flow_id')) {
            return;
        }
        Schema::table('chatbot_widgets', function (Blueprint $t) {
            $t->dropConstrainedForeignId('flow_id');
        });
    }
};
