<?php

namespace App\Http\Controllers\Threads;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\Request;

/**
 * Admin → Settings → Threads. The platform Threads (Meta) app id + secret used
 * for OAuth when a workspace hasn't set its own (ownThreadsApp). The secret is
 * encrypted at rest (SystemSetting::ENCRYPTED_KEYS).
 */
class ThreadsSettingsController extends Controller
{
    public function settings()
    {
        return view('admin.settings.threads', [
            'threads_enabled'    => (bool) SystemSetting::get('threads_enabled', false),
            'threads_app_id'     => (string) SystemSetting::get('threads_app_id', ''),
            'threads_secret_set' => trim((string) SystemSetting::get('threads_app_secret', '')) !== '',
            'redirect_uri'       => url('/threads/callback'),
            'connected_count'    => \App\Models\ThreadsAccount::where('status', 'connected')->count(),
        ]);
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'threads_enabled' => 'sometimes|boolean',
            'threads_app_id'  => 'nullable|string|max:64',
            'threads_app_secret' => 'nullable|string|max:191',
        ]);

        SystemSetting::set('threads_enabled', $request->boolean('threads_enabled'), 'bool', 'Threads channel enabled');
        SystemSetting::set('threads_app_id', trim((string) ($data['threads_app_id'] ?? '')), 'string', 'Threads app id');

        // Blank secret = keep the existing one (so the admin can re-save without retyping).
        $secret = trim((string) ($data['threads_app_secret'] ?? ''));
        if ($secret !== '') {
            SystemSetting::set('threads_app_secret', $secret, 'string', 'Threads app secret');
        }

        return back()->with('status', __('Threads settings saved.'));
    }
}
