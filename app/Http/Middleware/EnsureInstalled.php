<?php

namespace App\Http\Middleware;

use App\Support\InstallState;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate every request behind the install wizard until installation
 * completes. Detection is InstallState (APP_INSTALLED in .env, the
 * storage/installed marker, or a live users table) — not the marker
 * file alone, so an update ZIP cannot send a live site back to /install.
 *
 * Behaviour:
 *   - Not installed + non-install URL → redirect to /install
 *   - Installed     + install URL    → redirect to /
 *   - Otherwise pass through
 *
 * While not installed, the same fixed file-session config that the
 * install routes use is applied here too so guests landing on /
 * before installation get a consistent session cookie name across
 * the redirect.
 */
class EnsureInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        $installed     = InstallState::isInstalled();
        $path          = trim($request->getPathInfo(), '/');
        $isInstallRoute = $path === 'install' || str_starts_with($path, 'install/');

        if (! $installed) {
            // Bootstrap a usable runtime on a fresh checkout that
            // shipped with only .env.example. The wizard's controller
            // rewrites .env later — these guarantees just let Laravel
            // boot far enough to render the wizard.
            $this->bootstrapEnvIfMissing();

            // Force file session with the same fixed cookie name the
            // install routes use — APP_NAME may change mid-installation
            // (writeEnvFile) and a name-derived cookie would otherwise
            // invalidate the step-state session.
            config([
                'session.driver'  => 'file',
                'session.encrypt' => false,
                'session.cookie'  => 'wadesk_install_session',
            ]);
        }

        // Railway (and other hosts) probe GET /up. That route must return 200
        // even before the installer has run — a redirect to /install fails the
        // healthcheck and the deploy never goes live.
        $isHealthRoute = $path === 'up';

        if (! $installed && ! $isInstallRoute && ! $isHealthRoute) {
            return redirect('/install');
        }

        if ($installed && $isInstallRoute) {
            // JSON wizard steps must never receive an HTML redirect.
            if ($request->expectsJson()) {
                return $next($request);
            }

            return redirect('/');
        }

        return $next($request);
    }

    /**
     * Make sure .env exists and has a usable APP_KEY before the wizard
     * renders. Otherwise Laravel can't sign CSRF tokens consistently
     * and every form POST would 419.
     *
     * Strategy:
     *   1. If .env is missing but .env.example exists → copy it.
     *   2. If APP_KEY is missing/empty in .env → mint one and write back.
     *
     * Both writes are skipped silently when the file system isn't
     * writable — the wizard's Requirements step will surface that to
     * the operator.
     */
    private function bootstrapEnvIfMissing(): void
    {
        $envPath     = base_path('.env');
        $envExample  = base_path('.env.example');

        try {
            if (! file_exists($envPath) && file_exists($envExample) && is_writable(base_path())) {
                @copy($envExample, $envPath);
            }
            if (file_exists($envPath) && is_writable($envPath)) {
                $content = (string) @file_get_contents($envPath);
                // Never rotate a key that is already set — doing so after an
                // update would invalidate sessions and look like a fresh install.
                $hasKey = (bool) preg_match('/^APP_KEY=(?!base64:\s*$)(.+)$/m', $content);
                if (! $hasKey) {
                    $key = 'base64:' . base64_encode(random_bytes(32));
                    if (preg_match('/^APP_KEY=.*/m', $content)) {
                        $content = preg_replace('/^APP_KEY=.*/m', "APP_KEY={$key}", $content);
                    } else {
                        $content .= "\nAPP_KEY={$key}\n";
                    }
                    @file_put_contents($envPath, $content);
                    config(['app.key' => $key]);
                }
            }
        } catch (\Throwable $e) {
            // Non-fatal — wizard's Requirements check will flag the
            // missing-writable .env condition for the operator.
        }
    }
}
