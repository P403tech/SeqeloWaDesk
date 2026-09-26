<?php

namespace App\Services\Ai;

use App\Models\AiAgent;
use App\Models\AiChatAssistant;
use App\Models\Conversation;

/**
 * Detect the customer's writing language and tell the model to match it.
 * Script-based (no billed translation API). Latin languages (en/fr/es/…)
 * are left to the model from the actual transcript.
 */
class CustomerLanguage
{
    /** @var array<string, string> ISO 639-1 => English name */
    public const NAMES = [
        'ar' => 'Arabic',
        'he' => 'Hebrew',
        'fa' => 'Persian',
        'ur' => 'Urdu',
        'ko' => 'Korean',
        'ja' => 'Japanese',
        'zh' => 'Chinese',
        'uk' => 'Ukrainian',
        'bg' => 'Bulgarian',
        'el' => 'Greek',
        'th' => 'Thai',
        'hi' => 'Hindi',
        'bn' => 'Bengali',
        'ta' => 'Tamil',
        'te' => 'Telugu',
        'gu' => 'Gujarati',
        'pa' => 'Punjabi',
        'ml' => 'Malayalam',
        'kn' => 'Kannada',
        'am' => 'Amharic',
        'hy' => 'Armenian',
        'ka' => 'Georgian',
        'en' => 'English',
        'fr' => 'French',
        'es' => 'Spanish',
        'de' => 'German',
        'pt' => 'Portuguese',
        'it' => 'Italian',
        'tr' => 'Turkish',
        'nl' => 'Dutch',
        'id' => 'Indonesian',
        'ms' => 'Malay',
        'vi' => 'Vietnamese',
        'pl' => 'Polish',
        'ro' => 'Romanian',
        'sv' => 'Swedish',
        'da' => 'Danish',
        'fi' => 'Finnish',
        'no' => 'Norwegian',
        'cs' => 'Czech',
        'hu' => 'Hungarian',
    ];

    public static function detectFromText(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $checks = [
            'ar' => '/\p{Arabic}/u',
            'he' => '/\p{Hebrew}/u',
            'ko' => '/\p{Hangul}/u',
            'ja' => '/[\p{Hiragana}\p{Katakana}]/u',
            'zh' => '/\p{Han}/u',
            'el' => '/\p{Greek}/u',
            'ru' => '/\p{Cyrillic}/u',
            'th' => '/\p{Thai}/u',
            'hi' => '/\p{Devanagari}/u',
            'bn' => '/\p{Bengali}/u',
            'ta' => '/\p{Tamil}/u',
            'te' => '/\p{Telugu}/u',
            'gu' => '/\p{Gujarati}/u',
            'pa' => '/\p{Gurmukhi}/u',
            'ml' => '/\p{Malayalam}/u',
            'kn' => '/\p{Kannada}/u',
            'am' => '/\p{Ethiopic}/u',
            'hy' => '/\p{Armenian}/u',
            'ka' => '/\p{Georgian}/u',
        ];

        $letters = preg_replace('/[^\p{L}]+/u', '', $text) ?? '';
        $len = max(1, mb_strlen($letters));

        foreach ($checks as $code => $pattern) {
            if (! preg_match_all($pattern, $text, $m)) {
                continue;
            }
            if ((count($m[0]) / $len) >= 0.25) {
                return $code;
            }
        }

        return null;
    }

    public static function name(string $code): string
    {
        $code = strtolower(trim($code));

        return self::NAMES[$code] ?? $code;
    }

    public static function fallbackForAgent(AiAgent $agent): string
    {
        try {
            $id = (int) ($agent->knowledge_assistant_id ?? 0);
            if ($id > 0) {
                $lang = AiChatAssistant::where('id', $id)->value('language');
                $lang = strtolower(trim((string) $lang));
                if ($lang !== '') {
                    return $lang;
                }
            }
        } catch (\Throwable $e) {
        }

        return 'en';
    }

    /**
     * Pin a confident detection on the conversation (first time, or when
     * the latest message is clearly a different script).
     */
    public static function resolve(Conversation $convo, string $latestInbound): ?string
    {
        $detected = self::detectFromText($latestInbound);
        $pinned = strtolower(trim((string) ($convo->customer_language ?? '')));

        $use = $detected ?: ($pinned !== '' ? $pinned : null);

        if ($detected && $detected !== $pinned) {
            try {
                $convo->forceFill(['customer_language' => $detected])->saveQuietly();
            } catch (\Throwable $e) {
            }
        } elseif ($detected === null && $pinned === '' && $latestInbound !== '') {
            // leave unpinned — model matches Latin languages from the transcript
        }

        return $use;
    }

    public static function promptBlock(?string $detected, string $fallback = 'en'): string
    {
        $fallback = strtolower(trim($fallback)) ?: 'en';
        $out = "\n\nLanguage rule (required): Always reply in the same language the customer used in their latest message. "
            ."If they switch languages, switch immediately. Never default to English unless they wrote in English. "
            ."If the latest message has no readable language (sticker, numbers only), use {$fallback} (".self::name($fallback).').';

        if ($detected) {
            $out .= ' The latest customer message appears to be '.self::name($detected)
                ." (code \"{$detected}\"). Write the entire reply in that language, naturally.";
        }

        return $out;
    }
}
