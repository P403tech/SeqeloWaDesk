<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Services\GoogleCalendar\GoogleCalendarService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Workspace-scoped Google account connection. The same OAuth token
 * powers the BookAppointment node, the GoogleMeet node, and the
 * Team inbox "Send Meet link" composer button — so this page is the
 * single canonical surface to manage that connection.
 *
 * Auth: members of the current workspace. OAuth start/disconnect
 * happen on the AppointmentGoogleOAuthController routes already
 * registered — we just link to them so connect-flow logic lives in
 * one place.
 */
class GoogleAccountController extends Controller
{
    public function __construct(private GoogleCalendarService $gcal) {}

    /** GET /google-account */
    public function index(): View
    {
        $user = Auth::user();
        $wsId = (int) ($user?->current_workspace_id ?? 0);
        $workspace = $wsId ? Workspace::find($wsId) : null;

        $oauth = $workspace?->appointment_settings['google_oauth'] ?? [];
        $isConnected = !empty($oauth['access_token'] ?? null);
        // Calendar list is only useful when connected — saves an
        // unnecessary network call on the empty-state render.
        $calendars = $isConnected ? $this->gcal->listCalendars($workspace) : [];

        // Ready to connect when EITHER the admin configured a global Google app
        // OR this workspace brought its OWN app (self-serve, no admin needed).
        $appReady = $this->gcal->isEnabled($workspace) && $this->gcal->clientId($workspace) !== '';

        // Own-app (BYO) surface — owner-only, gated by the admin toggle
        // `google_allow_manual_app` so members don't see the keys and the
        // whole self-serve option can be switched off platform-wide.
        $ownApp        = $workspace?->ownGoogleApp();
        $isOwner       = $workspace && (int) $workspace->owner_user_id === (int) ($user?->id ?? 0);
        $manualAllowed = (bool) \App\Models\SystemSetting::get('google_allow_manual_app', false);

        return view('user.google-account.index', [
            'workspace'    => $workspace,
            'isConnected'  => $isConnected,
            'oauth'        => $oauth,
            'calendars'    => $calendars,
            'appReady'     => $appReady,
            'ownApp'       => $ownApp,
            'isOwner'      => $isOwner,
            'manualAllowed'=> $manualAllowed,
            'googleRedirectUri' => $this->gcal->redirectUri(),
        ]);
    }

    /**
     * POST /google-account/own-app — save this workspace's OWN Google OAuth
     * app (client_id + client_secret) so it connects Google through its own
     * app, no admin config or approval. Owner-only. Blank both = clear (back
     * to the platform/admin app). Blank secret with an id present keeps the
     * stored secret (so the owner can edit the id without re-typing the secret).
     */
    public function saveOwnApp(Request $request): RedirectResponse
    {
        $user = Auth::user();
        $ws   = $user?->currentWorkspace;
        if (! $ws) abort(403);
        if (! (bool) \App\Models\SystemSetting::get('google_allow_manual_app', false)) {
            abort(403, __('Connecting Google with your own app is not enabled.'));
        }
        if ((int) $ws->owner_user_id !== (int) $user->id) {
            abort(403, __('Only the workspace owner can change this.'));
        }

        $data = $request->validate([
            'google_client_id'     => ['nullable', 'string', 'max:191'],
            'google_client_secret' => ['nullable', 'string', 'max:191'],
        ]);

        $ws->google_client_id = trim((string) ($data['google_client_id'] ?? '')) ?: null;
        $secret = trim((string) ($data['google_client_secret'] ?? ''));
        if ($secret !== '') {
            $ws->google_client_secret = $secret;         // encrypted cast
        } elseif ($ws->google_client_id === null) {
            $ws->google_client_secret = null;            // fully cleared → back to admin app
        }
        $ws->save();

        return back()->with('status', $ws->fresh()->ownGoogleApp()
            ? __('Your Google app is saved. Now click Connect Google to authorise it.')
            : __('Your Google app keys were cleared. Google will use the platform app if the admin has configured one.'));
    }
}
