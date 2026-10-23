<?php

namespace App\Http\Controllers;

use App\Models\WaProviderConfig;
use App\Services\Waba\WabaCloudClient;
use App\Services\WorkspaceEngine;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Operator UI for the official WhatsApp Cloud API "advanced" endpoints
 * (WabaCloudClient): read-receipts/typing toggles, business profile,
 * conversational automation (welcome + ice breakers + commands), QR codes,
 * two-step PIN, and messaging analytics. WABA-only.
 */
class WabaToolsController extends Controller
{
    private function wsId(): int { return (int) (Auth::user()?->current_workspace_id ?? 0); }

    private function cloud(): ?WabaCloudClient { return WabaCloudClient::forWorkspace($this->wsId()); }

    /** The connected WABA config for this workspace (for the local toggles). */
    private function config(): ?WaProviderConfig
    {
        return WaProviderConfig::where('workspace_id', $this->wsId())
            ->where('provider', WorkspaceEngine::ENGINE_WABA)
            ->where('status', WaProviderConfig::STATUS_CONNECTED)
            ->orderByDesc('connected_at')->first();
    }

    public function page(): View
    {
        $cfg = $this->config();
        $meta = is_array($cfg?->meta_json) ? $cfg->meta_json : [];
        return view('user.waba-tools.index', [
            'connected'     => (bool) $cfg,
            'readReceipts'  => (bool) ($meta['read_receipts'] ?? false),
            'typing'        => (bool) ($meta['typing_indicator'] ?? false),
        ]);
    }

    private function needCloud(): ?JsonResponse
    {
        if (! $this->cloud()) {
            return response()->json(['ok' => false, 'error' => 'No connected WhatsApp (official/WABA) number for this workspace.'], 422);
        }
        return null;
    }

    // ── Read-receipt / typing toggles (stored on the WABA config meta_json) ──
    public function saveReceipts(Request $request): JsonResponse
    {
        $data = $request->validate([
            'read_receipts'    => ['nullable', 'boolean'],
            'typing_indicator' => ['nullable', 'boolean'],
        ]);
        $cfg = $this->config();
        if (! $cfg) return response()->json(['ok' => false, 'error' => 'No connected WABA number.'], 422);
        $meta = is_array($cfg->meta_json) ? $cfg->meta_json : [];
        $meta['read_receipts']    = (bool) ($data['read_receipts'] ?? false);
        $meta['typing_indicator'] = (bool) ($data['typing_indicator'] ?? false);
        $cfg->forceFill(['meta_json' => $meta])->save();
        return response()->json(['ok' => true]);
    }

    // ── Business profile ──
    public function profileGet(): JsonResponse
    {
        if ($r = $this->needCloud()) return $r;
        $res = $this->cloud()->getBusinessProfile();
        $p = $res['data']['data'][0] ?? [];
        return response()->json(['ok' => (bool) $res['ok'], 'error' => $res['error'] ?? null, 'profile' => $p]);
    }
    public function profileSave(Request $request): JsonResponse
    {
        if ($r = $this->needCloud()) return $r;
        $data = $request->validate([
            'about'       => ['nullable', 'string', 'max:139'],
            'description' => ['nullable', 'string', 'max:512'],
            'address'     => ['nullable', 'string', 'max:256'],
            'email'       => ['nullable', 'email', 'max:128'],
            'vertical'    => ['nullable', 'string', 'max:64'],
            'websites'    => ['nullable', 'array', 'max:2'],
            'websites.*'  => ['nullable', 'url', 'max:256'],
        ]);
        $fields = array_filter($data, fn ($v) => $v !== null && $v !== '' && $v !== []);
        if (isset($fields['websites'])) $fields['websites'] = array_values(array_filter($fields['websites']));
        $res = $this->cloud()->setBusinessProfile($fields);
        return response()->json(['ok' => (bool) $res['ok'], 'error' => $res['error'] ?? null]);
    }

    // ── Conversational automation (welcome + ice breakers + commands) ──
    public function automationGet(): JsonResponse
    {
        if ($r = $this->needCloud()) return $r;
        $res = $this->cloud()->getConversationalAutomation();
        $ca = $res['data']['conversational_automation'] ?? [];
        return response()->json([
            'ok'       => (bool) $res['ok'],
            'error'    => $res['error'] ?? null,
            'welcome'  => (bool) ($ca['enable_welcome_message'] ?? false),
            'prompts'  => array_values($ca['prompts'] ?? []),
            'commands' => array_values($ca['commands'] ?? []),
        ]);
    }
    public function automationSave(Request $request): JsonResponse
    {
        if ($r = $this->needCloud()) return $r;
        $data = $request->validate([
            'welcome'               => ['nullable', 'boolean'],
            'prompts'               => ['nullable', 'array', 'max:4'],
            'prompts.*'             => ['nullable', 'string', 'max:80'],
            'commands'              => ['nullable', 'array', 'max:30'],
            'commands.*.command_name'        => ['required_with:commands', 'string', 'max:32'],
            'commands.*.command_description' => ['nullable', 'string', 'max:256'],
        ]);
        $res = $this->cloud()->setConversationalAutomation(
            (bool) ($data['welcome'] ?? false),
            array_values(array_filter($data['prompts'] ?? [])),
            array_values($data['commands'] ?? []),
        );
        return response()->json(['ok' => (bool) $res['ok'], 'error' => $res['error'] ?? null]);
    }

    // ── QR codes / short links ──
    public function qrList(): JsonResponse
    {
        if ($r = $this->needCloud()) return $r;
        $res = $this->cloud()->listQrCodes();
        return response()->json(['ok' => (bool) $res['ok'], 'error' => $res['error'] ?? null, 'codes' => $res['data']['data'] ?? []]);
    }
    public function qrCreate(Request $request): JsonResponse
    {
        if ($r = $this->needCloud()) return $r;
        $data = $request->validate([
            'prefilled_message' => ['required', 'string', 'max:1000'],
            'format'            => ['nullable', 'in:PNG,SVG'],
        ]);
        $res = $this->cloud()->createQrCode($data['prefilled_message'], $data['format'] ?? 'PNG');
        return response()->json(['ok' => (bool) $res['ok'], 'error' => $res['error'] ?? null, 'code' => $res['data'] ?? null]);
    }
    public function qrDelete(Request $request): JsonResponse
    {
        if ($r = $this->needCloud()) return $r;
        $data = $request->validate(['code' => ['required', 'string', 'max:128']]);
        $res = $this->cloud()->deleteQrCode($data['code']);
        return response()->json(['ok' => (bool) $res['ok'], 'error' => $res['error'] ?? null]);
    }

    // ── Two-step verification PIN ──
    public function pinSave(Request $request): JsonResponse
    {
        if ($r = $this->needCloud()) return $r;
        $data = $request->validate(['pin' => ['required', 'digits:6']]);
        $res = $this->cloud()->setTwoStepPin($data['pin']);
        return response()->json(['ok' => (bool) $res['ok'], 'error' => $res['error'] ?? null]);
    }

    // ── Messaging analytics ──
    public function analytics(Request $request): JsonResponse
    {
        if ($r = $this->needCloud()) return $r;
        $days  = min(90, max(1, (int) $request->input('days', 30)));
        $end   = now()->timestamp;
        $start = now()->subDays($days)->timestamp;
        $res   = $this->cloud()->getMessagingAnalytics($start, $end, 'DAY');
        $points = $res['data']['analytics']['data_points'] ?? [];
        return response()->json([
            'ok'     => (bool) $res['ok'],
            'error'  => $res['error'] ?? null,
            'sent'      => array_sum(array_map(fn ($p) => (int) ($p['sent'] ?? 0), $points)),
            'delivered' => array_sum(array_map(fn ($p) => (int) ($p['delivered'] ?? 0), $points)),
            'points'    => $points,
        ]);
    }
}
