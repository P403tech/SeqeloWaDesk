<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\DripCampaign;
use App\Models\DripCampaignStep;
use App\Models\DripSubscriber;
use App\Models\WaTemplate;
use App\Services\Drip\DripRunner;
use App\Services\PlanLimitGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\View\View;

/**
 * Drip campaigns — scheduled follow-up sequences, separate from Flows.
 *
 * A Flow is conversational: it reacts to replies. A drip is a schedule: it runs
 * over days whether or not the contact answers, and stops when they do. The
 * clinical follow-up case ("confirm now, remind at 24h, check in at 3 days")
 * is a schedule, not a conversation, which is why it lives here.
 */
class DripCampaignsController extends Controller
{
    public function __construct(private readonly DripRunner $runner)
    {
    }

    private function wsId(): int
    {
        return (int) (Auth::user()?->current_workspace_id ?? 0);
    }

    /** Scope every lookup to the caller's workspace — never trust the id alone. */
    private function find(int $id): DripCampaign
    {
        return DripCampaign::forWorkspace($this->wsId())->findOrFail($id);
    }

    // -----------------------------------------------------------------

    public function index(Request $request): View
    {
        $wsId = $this->wsId();

        // Opportunistic drain. Project policy is no schedule:run dependency —
        // periodic work rides on endpoints operators already hit. Cheap: one
        // indexed query on (status, next_send_at) that usually returns nothing.
        try { $this->runner->drain(50); } catch (\Throwable $e) { /* never block the page */ }

        $campaigns = DripCampaign::forWorkspace($wsId)
            ->withCount([
                'steps',
                'subscribers',
                'subscribers as active_count' => fn ($q) => $q->where('status', DripSubscriber::STATUS_ACTIVE),
                'subscribers as completed_count' => fn ($q) => $q->where('status', DripSubscriber::STATUS_COMPLETED),
            ])
            ->orderByDesc('id')
            ->get();

        return view('user.drip-campaigns.index', [
            'campaigns' => $campaigns,
            'stats'     => [
                'total'     => $campaigns->count(),
                'running'   => $campaigns->where('status', DripCampaign::STATUS_ACTIVE)->count(),
                'active'    => (int) $campaigns->sum('active_count'),
                'completed' => (int) $campaigns->sum('completed_count'),
            ],
        ]);
    }

    public function create(): View
    {
        $tpls = $this->templates();

        return view('user.drip-campaigns.edit', [
            'campaign'    => new DripCampaign(['timezone' => config('app.timezone', 'UTC')]),
            'steps'       => collect(),
            'templates'   => $tpls,
            'paramCounts' => $this->templateParamCounts($tpls),
            'groups'    => ContactGroup::where('workspace_id', $this->wsId())->get(['id', 'user_group']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $wsId = $this->wsId();
        if (! $wsId) {
            return back()->withErrors(['workspace' => __('No active workspace.')]);
        }

        // Plan cap. These keys existed on Package for a long time but nothing
        // ever read them — a plan selling "5 drip campaigns" enforced nothing.
        PlanLimitGuard::check(
            Auth::user()->currentWorkspace,
            'drip_campaigns_limit',
            DripCampaign::forWorkspace($wsId)->count(),
        );

        $data = $this->validated($request);
        $data['workspace_id'] = $wsId;
        $data['user_id']      = Auth::id();
        $data['status']       = DripCampaign::STATUS_DRAFT;

        $campaign = DripCampaign::create($data);
        $this->syncSteps($campaign, $request);

        return redirect()
            ->route('user.drip.edit', $campaign->id)
            ->with('success', __('Drip campaign created. Add your steps, then activate it.'));
    }

    public function edit(int $id): View
    {
        $campaign = $this->find($id);

        $tpls = $this->templates();

        return view('user.drip-campaigns.edit', [
            'campaign'    => $campaign,
            'steps'       => $campaign->steps,
            'templates'   => $tpls,
            'paramCounts' => $this->templateParamCounts($tpls),
            'groups'    => ContactGroup::where('workspace_id', $this->wsId())->get(['id', 'user_group']),
            'stats'     => $this->runner->stats($campaign),
        ]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $campaign = $this->find($id);
        $campaign->update($this->validated($request));
        $this->syncSteps($campaign, $request);

        return back()->with('success', __('Saved.'));
    }

    public function destroy(int $id): RedirectResponse
    {
        $campaign = $this->find($id);

        DB::transaction(function () use ($campaign) {
            $campaign->subscribers()->delete();
            $campaign->steps()->delete();
            $campaign->delete();
        });

        return redirect()
            ->route('user.drip.index')
            ->with('success', __('Drip campaign deleted.'));
    }

    /** Activate / pause. A campaign with no steps can never be activated. */
    public function toggle(int $id): RedirectResponse
    {
        $campaign = $this->find($id);

        if (! $campaign->isRunning() && ! $campaign->isLaunchable()) {
            return back()->withErrors(['steps' => __('Add at least one step before activating.')]);
        }

        $campaign->update([
            'status' => $campaign->isRunning()
                ? DripCampaign::STATUS_PAUSED
                : DripCampaign::STATUS_ACTIVE,
        ]);

        return back()->with('success', $campaign->isRunning()
            ? __('Campaign activated — enrolled contacts will start receiving steps.')
            : __('Campaign paused. Pending steps stay queued and resume when you activate it again.'));
    }

    /** Manual enrolment: a contact group, or an explicit list of contacts. */
    public function enrol(Request $request, int $id): RedirectResponse
    {
        $campaign = $this->find($id);

        $data = $request->validate([
            'group_id'     => 'nullable|integer',
            'contact_ids'  => 'nullable|array',
            'contact_ids.*'=> 'integer',
        ]);

        if (! $campaign->isRunning()) {
            return back()->withErrors(['enrol' => __('Activate the campaign before enrolling contacts.')]);
        }

        $q = Contact::where('workspace_id', $this->wsId());

        if (! empty($data['contact_ids'])) {
            $q->whereIn('id', $data['contact_ids']);
            $contacts = $q->cursor();
        } elseif (! empty($data['group_id'])) {
            // contact_group is an ENCRYPTED json array of group ids, so it
            // cannot be filtered in SQL — the ciphertext differs per row even
            // for identical content. Filter after decryption instead.
            $gid = (int) $data['group_id'];
            $contacts = $q->cursor()->filter(function ($c) use ($gid) {
                $ids = is_array($c->contact_group) ? $c->contact_group : [];

                return in_array($gid, array_map('intval', $ids), true);
            });
        } else {
            return back()->withErrors(['enrol' => __('Pick a group or some contacts first.')]);
        }

        $n = $this->runner->enrolMany($campaign, $contacts);

        return back()->with('success', trans_choice(
            '{0}No new contacts to enrol.|{1}1 contact enrolled.|[2,*]:count contacts enrolled.',
            $n,
            ['count' => $n]
        ));
    }

    /** Subscriber list for one campaign (the detail drawer). */
    public function subscribers(int $id): JsonResponse
    {
        $campaign = $this->find($id);

        $rows = $campaign->subscribers()
            ->with('contact:id,name,mobile,country_code')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn ($s) => [
                'id'        => $s->id,
                'name'      => $s->contact?->name ?: '—',
                'mobile'    => $s->contact?->mobile ?: '—',
                'status'    => $s->status,
                'step'      => $s->current_step,
                'next_at'   => $s->next_send_at?->toIso8601String(),
                'sent'      => $s->sent_count,
                'reason'    => $s->stopped_reason,
            ]);

        return response()->json(['ok' => true, 'subscribers' => $rows]);
    }

    /**
     * POST /drip-campaigns/{id}/test — send one step to a number now.
     *
     * Runs the REAL send path, so a typo, a wrong template variable or a
     * missing approval shows up here instead of on 500 patients' phones.
     */
    public function test(Request $request, int $id): JsonResponse
    {
        $campaign = $this->find($id);

        $data = $request->validate([
            'position' => 'required|integer|min:1',
            'to'       => 'required|string|max:32',
        ]);

        $step = $campaign->steps()->where('position', (int) $data['position'])->first();
        if (! $step) {
            return response()->json(['ok' => false, 'message' => __('Save the campaign first, then test a step.')], 422);
        }

        // Prefer a real contact on that number so merge tags and template
        // parameters render with real data rather than placeholders.
        $contact = null;
        try {
            $hash = Contact::hashPhone(null, preg_replace('/\D+/', '', $data['to']));
            if ($hash) {
                $contact = Contact::where('workspace_id', $this->wsId())
                    ->where('mobile_hash', $hash)
                    ->first();
            }
        } catch (\Throwable $e) {
            // fall back to the stand-in contact
        }

        $res = $this->runner->testSend($step, (string) $data['to'], $contact);

        return response()->json([
            'ok'      => $res['ok'],
            'message' => $res['ok']
                ? __('Test sent. Check that number.')
                : ($res['error'] ?: __('Send failed.')),
        ], $res['ok'] ? 200 : 422);
    }

    /**
     * Drain endpoint. Anything that polls can call this to push due steps out
     * without waiting for someone to open the drip page.
     */
    public function drain(): JsonResponse
    {
        return response()->json(['ok' => true, 'delivered' => $this->runner->drain(100)]);
    }

    // -----------------------------------------------------------------

    private function validated(Request $request): array
    {
        return $request->validate([
            'name'             => 'required|string|max:191',
            'trigger_type'     => 'required|string|in:' . implode(',', array_keys(DripCampaign::TRIGGERS)),
            'trigger_value'    => 'nullable|string|max:191',
            'device_id'        => 'nullable|integer',
            'provider'         => 'nullable|string|max:32',
            'timezone'         => 'required|string|max:64',
            'quiet_start_hour' => 'nullable|integer|min:0|max:23',
            'quiet_end_hour'   => 'nullable|integer|min:0|max:23',
            'stop_on_reply'    => 'boolean',
            'stop_on_deal_won' => 'boolean',
            'goal_type'        => 'nullable|string|in:' . implode(',', array_filter(array_keys(DripCampaign::GOALS))),
            'goal_value'       => 'nullable|string|max:191',
        ]);
    }

    /** Replace the step list wholesale — simplest thing that stays consistent. */
    private function syncSteps(DripCampaign $campaign, Request $request): void
    {
        $steps = $request->input('steps', []);
        if (! is_array($steps)) {
            return;
        }

        // Templates this workspace actually owns — a step may only reference one
        // of these. Guards against a crafted request pinning another tenant's
        // template_id (which TemplateSender would then try to send). Non-owned or
        // unknown ids fall back to a plain-text step.
        $ownTemplateIds = \App\Models\WaTemplate::where('workspace_id', $campaign->workspace_id)
            ->pluck('id')->map(fn ($v) => (int) $v)->all();

        DB::transaction(function () use ($campaign, $steps, $ownTemplateIds) {
            $campaign->steps()->delete();

            $position = 0;
            foreach ($steps as $row) {
                $body = trim((string) ($row['body'] ?? ''));

                // Accept the template only when this workspace owns it.
                $templateId = (int) ($row['template_id'] ?? 0);
                $templateId = ($templateId > 0 && in_array($templateId, $ownTemplateIds, true)) ? $templateId : null;

                if ($body === '' && ! $templateId) {
                    continue;   // an empty step would send an empty message
                }

                $position++;

                // var_map arrives as steps[i][var_map][] — one contact-field
                // name per positional {{1}}, {{2}}… in the chosen template.
                $varMap = $row['var_map'] ?? null;
                $varMap = is_array($varMap) ? array_values(array_map('strval', $varMap)) : null;

                DripCampaignStep::create([
                    'drip_campaign_id' => $campaign->id,
                    'position'         => $position,
                    'delay_amount'     => max(0, (int) ($row['delay_amount'] ?? 0)),
                    'delay_unit'       => in_array($row['delay_unit'] ?? 'hour', ['minute', 'hour', 'day'], true)
                        ? $row['delay_unit'] : 'hour',
                    'message_type'     => $templateId ? 'template' : 'text',
                    'body'             => $body,
                    'template_id'      => $templateId,
                    'var_map'          => $templateId ? $varMap : null,
                    'media_path'       => $row['media_path'] ?? null,
                    'media_type'       => $row['media_type'] ?? null,
                ]);
            }
        });
    }

    private function templates()
    {
        return WaTemplate::query()
            ->where('workspace_id', $this->wsId())
            ->approved()
            ->orderBy('template_name')
            ->get(['id', 'template_name', 'language', 'template_body']);
    }

    /**
     * How many positional {{1}}, {{2}}… slots each template declares.
     *
     * The builder needs this to show exactly that many field pickers — Meta
     * rejects a send whose parameter count differs from the approved template,
     * so guessing here becomes a failed message days later.
     *
     * @return array<int,int>
     */
    private function templateParamCounts($templates): array
    {
        return $templates->mapWithKeys(function ($t) {
            $max = 0;
            if (preg_match_all('/\{\{\s*(\d+)\s*\}\}/', (string) $t->template_body, $m)) {
                foreach ($m[1] as $n) $max = max($max, (int) $n);
            }

            return [$t->id => $max];
        })->toArray();
    }
}
