<?php

namespace App\Http\Controllers;

use App\Models\SalesforceIntegration;
use App\Models\SalesforceIntegrationLog;
use App\Services\Salesforce\SalesforceService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class SalesforceController extends Controller
{
    public function __construct(private readonly SalesforceService $salesforce) {}

    public function index(Request $request): View
    {
        $user = Auth::user();
        $wsId = $user?->current_workspace_id;
        $integration = $wsId ? SalesforceIntegration::where('workspace_id', $wsId)->latest('id')->first() : null;

        $stats = ['created' => 0, 'updated' => 0, 'failed' => 0, 'last' => null];
        if ($integration) {
            $logs = $integration->logs();
            $stats['created'] = (clone $logs)->where('event_type', 'oauth.connected')->where('status', 'sent')->count();
            $stats['updated'] = (clone $logs)->where('event_type', 'like', '%.updated')->where('status', 'sent')->count();
            $stats['failed'] = (clone $logs)->where('status', 'failed')->count();
            $stats['last'] = (clone $logs)->latest('created_at')->value('created_at');
        }

        return view('user.salesforce.dashboard', [
            'integration' => $integration,
            'appEnabled'  => $this->salesforce->isEnabled() && $this->salesforce->clientId() !== '',
            'recentLogs'  => $integration
                ? $integration->logs()->latest('created_at')->limit(15)->get()
                : collect(),
            'stats'       => $stats,
        ]);
    }

    public function startOAuth(Request $request)
    {
        if (! $this->salesforce->isEnabled() || $this->salesforce->clientId() === '') {
            return back()->with('error', 'Salesforce integration is not configured. Ask your admin to enable it.');
        }
        $state = Str::random(40);
        $pkce = SalesforceService::generatePkce();
        session([
            'salesforce_oauth_state'   => $state,
            'salesforce_pkce_verifier' => $pkce['verifier'],
        ]);

        return redirect()->away($this->salesforce->authorizeUrl($state, $pkce['challenge']));
    }

    public function oauthCallback(Request $request)
    {
        \App\Services\PlanLimitGuard::feature($request->user()?->currentWorkspace, 'integration_salesforce');

        $state = (string) $request->query('state', '');
        if (! $state || $state !== session('salesforce_oauth_state')) {
            return redirect('/salesforce')->with('error', 'OAuth state mismatch.');
        }

        $code = (string) $request->query('code', '');
        $verifier = (string) session('salesforce_pkce_verifier', '');
        $exchange = $this->salesforce->exchangeCode($code, $verifier ?: null);
        if (! ($exchange['success'] ?? false)) {
            return redirect('/salesforce')->with('error', 'Salesforce OAuth failed: ' . ($exchange['error'] ?? 'unknown'));
        }

        $user = Auth::user();
        $wsId = $user?->current_workspace_id;
        if (! $wsId) {
            return redirect('/salesforce')->with('error', 'No workspace selected.');
        }

        $instanceUrl = (string) ($exchange['instance_url'] ?? '');
        $org = $instanceUrl !== ''
            ? $this->salesforce->getOrgInfo((string) $exchange['access_token'], $instanceUrl)
            : [];

        $row = SalesforceIntegration::updateOrCreate(
            ['workspace_id' => $wsId, 'org_id' => $org['org_id'] ?? ''],
            [
                'user_id'                 => $user->id,
                'org_name'                => $org['org_name'] ?? null,
                'org_email'               => $org['org_email'] ?? null,
                'instance_url'            => $instanceUrl,
                'login_host'              => $this->salesforce->loginHost(),
                'access_token'            => $exchange['access_token'],
                'refresh_token'           => $exchange['refresh_token'] ?? '',
                'access_token_expires_at' => now()->addSeconds($exchange['expires_in'] ?? 7200),
                'scopes'                  => $this->salesforce->scopes(),
                'status'                  => 'active',
                'last_verified_at'        => now(),
                'connected_at'            => now(),
            ],
        );

        SalesforceIntegrationLog::create([
            'integration_id' => $row->id,
            'event_type'     => 'oauth.connected',
            'status'         => 'sent',
            'object_id'      => (string) ($org['org_id'] ?? ''),
            'payload'        => ['org_name' => $org['org_name'] ?? null],
            'created_at'     => now(),
        ]);

        session()->forget(['salesforce_oauth_state', 'salesforce_pkce_verifier']);

        return redirect('/salesforce')->with('success', 'Salesforce connected.');
    }

    public function disconnect(int $id)
    {
        $user = Auth::user();
        $integration = $user
            ? SalesforceIntegration::where('workspace_id', $user->current_workspace_id)->find($id)
            : null;
        if (! $integration) {
            abort(404);
        }
        $integration->delete();

        return redirect('/salesforce')->with('success', 'Salesforce disconnected.');
    }
}
