<?php

namespace App\Support;

use App\Models\AiChatAssistant;
use App\Models\Workspace;
use App\Services\AiAgentService;
use App\Services\AiKeyResolver;
use Illuminate\Support\Facades\Schema;

/**
 * Customer-facing snapshot: last provider failures for this workspace
 * plus live agents that cannot resolve a key.
 */
class WorkspaceLlmHealth
{
    /** @return array{errors: list<array>, blocked: list<array>} */
    public static function snapshot(int $workspaceId): array
    {
        $errors = array_values(array_filter(
            AiProviderErrorInbox::recent(AiProviderErrorInbox::MAX),
            fn ($row) => (int) ($row['workspace_id'] ?? 0) === $workspaceId
        ));
        $errors = array_slice($errors, 0, 8);

        $blocked = [];
        if ($workspaceId <= 0 || ! Schema::hasTable('ai_chat_assistants')) {
            return ['errors' => $errors, 'blocked' => $blocked];
        }

        $ws = Workspace::find($workspaceId);
        $q = AiChatAssistant::query()
            ->where('workspace_id', $workspaceId)
            ->where('status', 'active');
        foreach ($q->get(['id', 'name', 'ai_provider', 'ai_model']) as $a) {
            $provider = AiAgentService::providerForModel(
                (string) $a->ai_provider,
                (string) $a->ai_model
            );
            $source = 'none';
            try {
                $source = AiKeyResolver::resolve($ws, $provider)['source'] ?? 'none';
            } catch (\Throwable $e) {
                $source = 'none';
            }
            if ($source !== 'none') {
                continue;
            }
            $blocked[] = [
                'id'       => (int) $a->id,
                'name'     => (string) $a->name,
                'provider' => $provider,
                'model'    => (string) $a->ai_model,
            ];
            if (count($blocked) >= 8) {
                break;
            }
        }

        return ['errors' => $errors, 'blocked' => $blocked];
    }
}
