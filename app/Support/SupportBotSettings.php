<?php

namespace App\Support;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Crypt;

/**
 * Platform Client Support Bot configuration — everything is admin-set on
 * /admin/settings/support-bot and stored in system_settings (provider keys
 * encrypted at rest). Single source of truth for the whole feature.
 *
 * Answer ladder (see the plan): DOCS first (no AI) → AI-over-docs (rare, only
 * on weak matches, admin opt-in) → WEB (last resort, only when not in docs,
 * admin opt-in) → contact fallback. The two escalation tiers are OFF by
 * default so the bot is cheap and doc-grounded until the admin turns them on.
 */
class SupportBotSettings
{
    public const DEFAULT_DOCS_THRESHOLD = 0.22; // below this ⇒ "not in docs"
    public const DEFAULT_AI_MIN         = 0.50; // [docs_threshold, ai_min) ⇒ escalate to AI

    /** Master switch — the widget renders (and endpoints answer) only when true. */
    public static function enabled(): bool
    {
        return (bool) SystemSetting::get('support_bot_enabled', false);
    }

    /**
     * AI tier available: toggle ON + the chosen provider has a key configured in
     * Admin → AI Keys. The support bot reuses those platform keys — it never
     * stores its own.
     */
    public static function aiEnabled(): bool
    {
        if (! (bool) SystemSetting::get('support_bot_ai_enabled', false)) return false;
        return self::providerHasKey((string) SystemSetting::get('support_bot_provider', 'openai'));
    }

    /**
     * "Answer beyond the docs" tier available. Reuses the SAME AI provider + its
     * admin key. Pick a web-grounded model (e.g. Perplexity) as the AI provider
     * for live web results; any other model answers from its general knowledge.
     */
    public static function webEnabled(): bool
    {
        if (! (bool) SystemSetting::get('support_bot_web_enabled', false)) return false;
        return self::providerHasKey((string) SystemSetting::get('support_bot_provider', 'openai'));
    }

    /** True when Admin → AI Keys has a usable key for this provider. */
    public static function providerHasKey(string $provider): bool
    {
        try {
            return trim((string) \App\Services\AiKeyResolver::keyFor(null, $provider)) !== '';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Full config, provider keys decrypted. Server-side only — never sent raw to the browser. */
    public static function raw(): array
    {
        return [
            'enabled'        => self::enabled(),
            'ai_enabled'     => (bool) SystemSetting::get('support_bot_ai_enabled', false),
            'ai_provider'    => (string) SystemSetting::get('support_bot_provider', 'openai'),
            'ai_model'       => (string) SystemSetting::get('support_bot_model', ''),
            'web_enabled'    => (bool) SystemSetting::get('support_bot_web_enabled', false),
            'docs_threshold' => (float) SystemSetting::get('support_bot_docs_threshold', self::DEFAULT_DOCS_THRESHOLD),
            'ai_min'         => (float) SystemSetting::get('support_bot_ai_min', self::DEFAULT_AI_MIN),
            'title'          => (string) SystemSetting::get('support_bot_title', 'Help & Support'),
            'greeting'       => (string) SystemSetting::get('support_bot_greeting', 'Hi! Ask me anything — I search the help docs first.'),
            'no_answer'      => (string) SystemSetting::get('support_bot_no_answer', "I couldn't find that in our help docs. Please contact support and we'll help you out."),
            'theme_color'    => (string) SystemSetting::get('support_bot_theme_color', '#128C7E'),
            'position'       => (string) SystemSetting::get('support_bot_position', 'right'),
            'support_url'    => (string) SystemSetting::get('support_bot_support_url', ''),
            'support_email'  => (string) SystemSetting::get('support_bot_support_email', ''),
        ];
    }

    /** Only what is safe to hand the widget (never any provider key). */
    public static function publicConfig(): array
    {
        if (! self::enabled()) return ['enabled' => false];
        $c = self::raw();
        return [
            'enabled'       => true,
            'title'         => $c['title'],
            'greeting'      => $c['greeting'],
            'theme_color'   => $c['theme_color'],
            'position'      => $c['position'],
            'support_url'   => $c['support_url'],
            'support_email' => $c['support_email'],
        ];
    }

    /** Persist from the admin form. Keys only overwritten when a new value is provided. */
    public static function save(array $data): void
    {
        SystemSetting::set('support_bot_enabled', !empty($data['support_bot_enabled']) ? 1 : 0, 'int');
        SystemSetting::set('support_bot_ai_enabled', !empty($data['support_bot_ai_enabled']) ? 1 : 0, 'int');
        SystemSetting::set('support_bot_web_enabled', !empty($data['support_bot_web_enabled']) ? 1 : 0, 'int');

        // Provider + model are chosen from Admin → AI Keys; the bot reuses those
        // keys, so no key is stored here.
        SystemSetting::set('support_bot_provider', (string) ($data['support_bot_provider'] ?? 'openai'), 'string');
        SystemSetting::set('support_bot_model', (string) ($data['support_bot_model'] ?? ''), 'string');

        SystemSetting::set('support_bot_docs_threshold', (float) ($data['support_bot_docs_threshold'] ?? self::DEFAULT_DOCS_THRESHOLD), 'string');

        SystemSetting::set('support_bot_title', (string) ($data['support_bot_title'] ?? 'Help & Support'), 'string');
        SystemSetting::set('support_bot_greeting', (string) ($data['support_bot_greeting'] ?? ''), 'string');
        SystemSetting::set('support_bot_no_answer', (string) ($data['support_bot_no_answer'] ?? ''), 'string');
        SystemSetting::set('support_bot_theme_color', (string) ($data['support_bot_theme_color'] ?? '#128C7E'), 'string');
        SystemSetting::set('support_bot_position', (string) ($data['support_bot_position'] ?? 'right'), 'string');
        SystemSetting::set('support_bot_support_url', (string) ($data['support_bot_support_url'] ?? ''), 'string');
        SystemSetting::set('support_bot_support_email', (string) ($data['support_bot_support_email'] ?? ''), 'string');
    }
}
