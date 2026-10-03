<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\ZohoIntegration;
use App\Models\ZohoIntegrationLog;
use App\Services\PlanLimitGuard;
use App\Services\Zoho\ZohoService;
use App\Services\Zoho\ZohoSyncService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ZohoController extends Controller
{
    public function __construct(
        private readonly ZohoService $zoho,
        private readonly ZohoSyncService $syncService
    ) {}

    /**
     * GET /zoho — connect screen or connected dashboard.
     */
    public function index(Request $request): View
    {
        $user = Auth::user();
        $wsId = $user?->current_workspace_id;
        $integration = $wsId ? ZohoIntegration::where('workspace_id', $wsId)->latest('id')->first() : null;

        $stats = ['created' => 0, 'updated' => 0, 'failed' => 0, 'last' => null];
        if ($integration) {
            $logs = $integration->logs();
            $stats['created'] = (clone $logs)->whereIn('event_type', ['contact.upsert', 'deal.created', 'oauth.connected'])->where('status', 'sent')->count();
            $stats['updated'] = (clone $logs)->where('event_type', 'like', '%.upsert')->where('status', 'sent')->count();
            $stats['failed']  = (clone $logs)->where('status', 'failed')->count();
            $stats['last']    = (clone $logs)->latest('created_at')->value('created_at');
        }

        return view('user.zoho.dashboard', [
            'integration' => $integration,
            'appEnabled'  => $this->zoho->isEnabled() && $this->zoho->clientId() !== '',
            'recentLogs'  => $integration
                ? $integration->logs()->latest('created_at')->limit(15)->get()
                : collect(),
            'stats'       => $stats,
            'dataCenters' => ZohoService::DATA_CENTERS,
            'currentDc'   => $this->zoho->dataCenter(),
        ]);
    }

    /**
     * POST /zoho/connect — redirect to Zoho OAuth.
     */
    public function startOAuth(Request $request): RedirectResponse
    {
        if (! $this->zoho->isEnabled() || $this->zoho->clientId() === '') {
            return back()->with('error', 'Zoho CRM integration is not configured. Ask your platform administrator to enable it.');
        }

        $state = Str::random(40);
        $dc = (string) $request->input('data_center', $this->zoho->dataCenter());

        session([
            'zoho_oauth_state' => $state,
            'zoho_oauth_dc'    => $dc,
        ]);

        return redirect()->away($this->zoho->authorizeUrl($state, $dc));
    }

    /**
     * GET /zoho/oauth/callback — exchange code and store credentials.
     */
    public function oauthCallback(Request $request): RedirectResponse
    {
        PlanLimitGuard::feature($request->user()?->currentWorkspace, 'integration_zoho');

        $state = (string) $request->query('state', '');
        if (! $state || $state !== session('zoho_oauth_state')) {
            return redirect('/zoho')->with('error', 'OAuth state mismatch.');
        }

        $code = (string) $request->query('code', '');
        if (! $code) {
            return redirect('/zoho')->with('error', 'Missing authorization code from Zoho.');
        }

        $dc = (string) session('zoho_oauth_dc', '');
        $exchange = $this->zoho->exchangeCode($code, $dc ?: null);

        if (! ($exchange['success'] ?? false)) {
            return redirect('/zoho')->with('error', 'Zoho OAuth failed: ' . ($exchange['error'] ?? 'unknown error'));
        }

        $user = Auth::user();
        $wsId = $user?->current_workspace_id;
        if (! $wsId) {
            return redirect('/zoho')->with('error', 'No workspace selected.');
        }

        $apiDomain = (string) ($exchange['api_domain'] ?? '');
        $org = $apiDomain !== ''
            ? $this->zoho->getOrgInfo((string) $exchange['access_token'], $apiDomain)
            : [];

        $row = ZohoIntegration::updateOrCreate(
            ['workspace_id' => $wsId, 'org_id' => $org['org_id'] ?? ''],
            [
                'user_id'                 => $user->id,
                'org_name'                => $org['org_name'] ?? null,
                'org_email'               => $org['org_email'] ?? null,
                'api_domain'              => $apiDomain,
                'accounts_server'         => $exchange['accounts_server'] ?? 'accounts.zoho.com',
                'access_token'            => $exchange['access_token'],
                'refresh_token'           => $exchange['refresh_token'] ?? '',
                'access_token_expires_at' => now()->addSeconds($exchange['expires_in'] ?? 3600),
                'scopes'                  => $this->zoho->scopes(),
                'status'                  => 'active',
                'last_verified_at'        => now(),
                'connected_at'            => now(),
            ]
        );

        ZohoIntegrationLog::create([
            'integration_id' => $row->id,
            'event_type'     => 'oauth.connected',
            'status'         => 'sent',
            'object_id'      => (string) ($org['org_id'] ?? ''),
            'payload'        => [
                'org_name'   => $org['org_name'] ?? null,
                'api_domain' => $apiDomain,
            ],
            'created_at'     => now(),
        ]);

        session()->forget(['zoho_oauth_state', 'zoho_oauth_dc']);

        return redirect('/zoho')->with('success', 'Zoho CRM connected successfully.');
    }

    /**
     * POST /zoho/{id}/test — test API communication.
     */
    public function test(int $id): RedirectResponse
    {
        $user = Auth::user();
        $integration = $user
            ? ZohoIntegration::where('workspace_id', $user->current_workspace_id)->find($id)
            : null;

        if (! $integration) {
            abort(404);
        }

        $result = $this->zoho->testConnection($integration);
        if ($result['success'] ?? false) {
            return back()->with('success', $result['message']);
        }

        return back()->with('error', $result['message'] ?? 'Connection test failed.');
    }

    /**
     * POST /zoho/{id}/sync — manually sync contacts to Zoho.
     */
    public function sync(Request $request, int $id): RedirectResponse
    {
        $user = Auth::user();
        $integration = $user
            ? ZohoIntegration::where('workspace_id', $user->current_workspace_id)->find($id)
            : null;

        if (! $integration) {
            abort(404);
        }

        $contacts = Contact::where('workspace_id', $user->current_workspace_id)
            ->latest('id')
            ->limit(20)
            ->get();

        $synced = 0;
        foreach ($contacts as $contact) {
            $res = $this->syncService->syncContact($integration, $contact);
            if ($res && ($res['success'] ?? false)) {
                $synced++;
            }
        }

        return back()->with('success', "Synced {$synced} contact(s) to Zoho CRM.");
    }

    /**
     * POST /zoho/{id}/disconnect — remove integration.
     */
    public function disconnect(int $id): RedirectResponse
    {
        $user = Auth::user();
        $integration = $user
            ? ZohoIntegration::where('workspace_id', $user->current_workspace_id)->find($id)
            : null;

        if (! $integration) {
            abort(404);
        }

        $integration->delete();

        return redirect('/zoho')->with('success', 'Zoho CRM disconnected.');
    }
}
