<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether this copy of WaDesk has already finished the install wizard.
 *
 * The original check was only `storage/installed`. Applying an update ZIP
 * (or a full-folder overwrite) can drop that marker while leaving .env and
 * the database intact — the next request then "falls back" to /install.
 *
 * Detection order (any one is enough):
 *   1. APP_INSTALLED=true in .env  (never overwritten by the updater)
 *   2. storage/installed marker
 *   3. A live database that already has at least one user
 *
 * When a live install is detected without the env flag / marker, both are
 * rewritten so a later update cannot send the operator through the wizard.
 */
class InstallState
{
    private static ?bool $memo = null;

    public static function markerPath(): string
    {
        return storage_path('installed');
    }

    public static function isInstalled(): bool
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        if (self::envFlag() || self::hasMarker()) {
            self::$memo = true;

            return true;
        }

        if (self::hasLiveDatabase()) {
            // Honor an explicit APP_INSTALLED=false (e.g. Railway) so a
            // half-finished wizard — admin user already in MySQL — can still
            // complete. Auto-heal only when the flag is absent, which is the
            // update-ZIP case.
            if (self::envExplicitlyFalse() || self::isWizardRequest()) {
                return self::$memo = false;
            }

            self::$memo = true;
            self::persistQuietly();

            return true;
        }

        return self::$memo = false;
    }

    /**
     * Write (or restore) the durable install flags. Safe to call after the
     * wizard, after an update apply, and after finalize.
     *
     * @param  array<string,mixed>|null  $extra  Merged into the marker file.
     */
    public static function markInstalled(?array $extra = null): void
    {
        if (! self::envFlag()) {
            EnvWriter::set('APP_INSTALLED', 'true');
            putenv('APP_INSTALLED=true');
            $_ENV['APP_INSTALLED'] = 'true';
            $_SERVER['APP_INSTALLED'] = 'true';
            try {
                config(['app.installed' => true]);
            } catch (\Throwable $e) {
            }
        }

        $path = self::markerPath();
        $shouldWrite = $extra !== null || ! is_file($path);
        if ($shouldWrite) {
            $existing = [];
            if (is_file($path)) {
                $existing = json_decode((string) @file_get_contents($path), true) ?: [];
            }
            $payload = array_merge([
                'installed_at' => $existing['installed_at'] ?? now()->toIso8601String(),
                'version'      => (string) config('version.version', '1.0.0'),
            ], is_array($existing) ? $existing : [], $extra ?? []);
            try {
                @file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } catch (\Throwable $e) {
            }
        }
    }

    public static function forgetMemo(): void
    {
        self::$memo = null;
    }

    private static function isWizardRequest(): bool
    {
        try {
            $path = trim(request()->getPathInfo(), '/');

            return $path === 'install' || str_starts_with($path, 'install/');
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function persistQuietly(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            self::markInstalled();
        } catch (\Throwable $e) {
        }
    }

    private static function hasMarker(): bool
    {
        return is_file(self::markerPath());
    }

    private static function envFlag(): bool
    {
        foreach ([
            $_ENV['APP_INSTALLED'] ?? null,
            $_SERVER['APP_INSTALLED'] ?? null,
            getenv('APP_INSTALLED') ?: null,
        ] as $raw) {
            if (self::truthy($raw)) {
                return true;
            }
        }

        try {
            if (self::truthy(config('app.installed', false))) {
                return true;
            }
        } catch (\Throwable $e) {
        }

        return false;
    }

    private static function envExplicitlyFalse(): bool
    {
        foreach ([
            $_ENV['APP_INSTALLED'] ?? null,
            $_SERVER['APP_INSTALLED'] ?? null,
            getenv('APP_INSTALLED') ?: null,
        ] as $raw) {
            if ($raw === false || $raw === 0 || $raw === '0') {
                return true;
            }
            if (is_string($raw) && $raw !== '' && ! self::truthy($raw)) {
                return true;
            }
        }

        return false;
    }

    private static function truthy(mixed $value): bool
    {
        if ($value === true || $value === 1 || $value === '1') {
            return true;
        }
        if (! is_string($value) || $value === '') {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Last-resort: this is already a working product (users exist), so the
     * wizard must not run even if both markers were deleted by an update.
     */
    private static function hasLiveDatabase(): bool
    {
        try {
            if (! Schema::hasTable('users')) {
                return false;
            }

            return DB::table('users')->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
