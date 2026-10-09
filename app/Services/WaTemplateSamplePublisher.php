<?php

namespace App\Services;

use App\Http\Controllers\TemplatesController;
use App\Models\WaTemplate;
use App\Models\WaTemplateSample;
use App\Models\Workspace;

/**
 * Installs an admin WhatsApp sample into every active customer workspace
 * as a real WaTemplate. Unofficial copies are locally approved. Cloud API
 * copies stay pending so the tenant submits them to their own WABA.
 * Copies already on Meta are never overwritten.
 */
class WaTemplateSamplePublisher
{
    /**
     * @return array{created: int, updated: int, skipped: int}
     */
    public function push(WaTemplateSample $sample): array
    {
        $fields = $this->fieldsFromSample($sample);
        $created = 0;
        $updated = 0;
        $skipped = 0;

        Workspace::query()
            ->where('status', true)
            ->orderBy('id')
            ->chunkById(100, function ($workspaces) use ($sample, $fields, &$created, &$updated, &$skipped) {
                foreach ($workspaces as $ws) {
                    $result = $this->installIntoWorkspace($ws, $sample, $fields);
                    if ($result === 'created') {
                        $created++;
                    } elseif ($result === 'updated') {
                        $updated++;
                    } else {
                        $skipped++;
                    }
                }
            });

        $sample->forceFill(['last_pushed_at' => now()])->save();

        return compact('created', 'updated', 'skipped');
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function installIntoWorkspace(Workspace $ws, WaTemplateSample $sample, array $fields): string
    {
        $existing = WaTemplate::query()
            ->where('workspace_id', $ws->id)
            ->where('source_sample_id', $sample->id)
            ->first();

        if ($existing && $existing->meta_template_id) {
            return 'skipped';
        }

        if ($existing) {
            $existing->update($fields);

            return 'updated';
        }

        $engine = WorkspaceEngine::for((int) $ws->id);
        $waba = $engine === WorkspaceEngine::ENGINE_WABA;
        $twilio = $engine === WorkspaceEngine::ENGINE_TWILIO;

        WaTemplate::create($fields + [
            'user_id'           => $ws->owner_user_id,
            'workspace_id'      => $ws->id,
            'source_sample_id'  => $sample->id,
            'channel'           => $waba ? 'waba' : ($twilio ? 'twilio' : 'baileys'),
            'status'            => $waba ? 'pending' : 'approved',
            'meta_status'       => $waba ? null : 'APPROVED',
            'approved_at'       => $waba ? null : now(),
        ]);

        return 'created';
    }

    /**
     * @return array<string, mixed>
     */
    private function fieldsFromSample(WaTemplateSample $sample): array
    {
        $rawHeader = (string) $sample->header;
        $rawBody = (string) $sample->body;
        $rawFooter = (string) $sample->footer;

        return [
            'template_name'    => $sample->slug,
            'category'         => $sample->category,
            'meta_category'    => $sample->meta_category,
            'template_type'    => 'standard',
            'header'           => TemplatesController::normalizePlaceholders($rawHeader),
            'template_body'    => TemplatesController::normalizePlaceholders($rawBody),
            'footer'           => TemplatesController::normalizePlaceholders($rawFooter),
            'buttons'          => $this->buttons($sample->buttons),
            'language'         => $sample->language ?: 'en_US',
            'parameter_format' => 'POSITIONAL',
            'variable_map'     => $this->variableMap($rawHeader, $rawBody),
            'attachment_type'  => 'none',
        ];
    }

    /**
     * @param  mixed  $raw
     * @return list<array<string, mixed>>
     */
    private function buttons(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $btn) {
            if (! is_array($btn)) {
                continue;
            }
            $text = trim((string) ($btn['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $type = (string) ($btn['type'] ?? 'quick_reply');
            $row = ['type' => $type, 'text' => mb_substr($text, 0, 25)];
            if (($btn['value'] ?? '') !== '') {
                $row['value'] = (string) $btn['value'];
            }
            $out[] = $row;
            if (count($out) >= 3) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return array{header?: list<array{num: int, key: string}>, body?: list<array{num: int, key: string}>}|null
     */
    private function variableMap(string $header, string $body): ?array
    {
        $entriesFor = function (string $text): array {
            if ($text === '' || ! preg_match_all('/\{\{\s*([a-zA-Z0-9_][\w.-]*)\s*\}\}/u', $text, $m)) {
                return [];
            }
            $out = [];
            $seen = [];
            $i = 0;
            foreach ($m[1] as $token) {
                if (isset($seen[$token])) {
                    continue;
                }
                $seen[$token] = true;
                $i++;
                $out[] = ['num' => $i, 'key' => (string) $token];
            }

            return $out;
        };

        $map = [];
        if ($h = $entriesFor($header)) {
            $map['header'] = $h;
        }
        if ($b = $entriesFor($body)) {
            $map['body'] = $b;
        }

        return $map ?: null;
    }
}
