<?php

namespace App\Services\LeadScoring;

use App\Models\Contact;
use App\Models\LeadScoreEvent;
use App\Models\LeadScoringRule;
use Illuminate\Support\Facades\Log;

/**
 * The lead-scoring engine for the AI SDR.
 *
 * A "signal" (an inbound reply, a booked meeting, a won deal, a keyword hit…)
 * is reported here with a small context array. Every active workspace rule for
 * that signal whose conditions match adds (or subtracts) points; the contact's
 * running lead_score is clamped to 0..100, bucketed into an A/B/C/D grade, and
 * each change is written to lead_score_events so the SDR dashboard can explain
 * it. Never throws into the caller — scoring must never break a message flow.
 */
class LeadScoringService
{
    public const MIN = 0;
    public const MAX = 100;

    /** Canonical signals the SDR emits (for the rule builder + validation). */
    public const SIGNALS = [
        'message_inbound',      // any inbound message from the lead
        'inbound_reply',        // lead replied within an active sequence
        'keyword_match',        // inbound text matched a keyword (context: text)
        'link_clicked',
        'form_submitted',
        'appointment_booked',
        'appointment_completed',
        'deal_stage_changed',   // context: stage, is_won
        'deal_won',
        'deal_lost',
        'campaign_delivered',
        'campaign_read',
        'tag_added',            // context: tag
        'unsubscribed',
    ];

    /** Grade thresholds, highest first. */
    private const GRADES = [75 => 'A', 50 => 'B', 25 => 'C', 0 => 'D'];

    /** Per-request rule cache: [workspaceId][signal] => Collection. */
    private static array $ruleCache = [];

    /**
     * Report a signal for a contact and apply any matching rules.
     *
     * @param  Contact|int|null $contact  the lead (model preferred; id also works)
     * @param  array            $context  fields the rule conditions match against
     * @return int|null the new score, or null when nothing was scored
     */
    public static function record($contact, string $signal, array $context = [], ?int $workspaceId = null): ?int
    {
        try {
            $contact = $contact instanceof Contact
                ? $contact
                : ($contact ? Contact::find((int) $contact) : null);
            if (! $contact) {
                return null;
            }
            $wsId = (int) ($workspaceId ?: $contact->workspace_id);
            if ($wsId <= 0) {
                return null;
            }

            $rules = self::rulesFor($wsId, $signal);
            if ($rules->isEmpty()) {
                return null;
            }

            $delta   = 0;
            $matched = [];
            foreach ($rules as $rule) {
                if (self::conditionsMatch($rule->conditions ?? [], $context)) {
                    $delta    += (int) $rule->points;
                    $matched[] = $rule;
                }
            }
            if (empty($matched)) {
                return null;
            }

            $before = (int) ($contact->lead_score ?? 0);
            $after  = max(self::MIN, min(self::MAX, $before + $delta));

            // Persist the contact score even when clamping left it unchanged —
            // the rule still "fired" and the audit row should record the touch.
            $contact->forceFill([
                'lead_score'            => $after,
                'lead_grade'            => self::grade($after),
                'lead_score_updated_at' => now(),
            ])->save();

            foreach ($matched as $rule) {
                LeadScoreEvent::create([
                    'workspace_id' => $wsId,
                    'contact_id'   => $contact->id,
                    'rule_id'      => $rule->id,
                    'signal'       => $signal,
                    'points'       => (int) $rule->points,
                    'score_after'  => $after,
                    'context'      => $context ?: null,
                ]);
                $rule->forceFill([
                    'fired_count'   => (int) $rule->fired_count + 1,
                    'last_fired_at' => now(),
                ])->saveQuietly();
            }

            // Let the SDR react to the new score (e.g. hand a now-qualified
            // lead to the sales team). Best-effort — never break scoring.
            try {
                \App\Services\Sdr\SdrOrchestrator::onScore($contact);
            } catch (\Throwable $e) {
                Log::warning('[LEAD-SCORE] SDR onScore failed: ' . $e->getMessage());
            }

            return $after;
        } catch (\Throwable $e) {
            Log::warning('[LEAD-SCORE] record failed: ' . $e->getMessage(), ['signal' => $signal]);
            return null;
        }
    }

    /** Public condition matcher — reused by the SDR enrol gate. */
    public static function matches(array $conditions, array $context): bool
    {
        return self::conditionsMatch($conditions, $context);
    }

    /** A/B/C/D bucket for a numeric score. */
    public static function grade(int $score): string
    {
        foreach (self::GRADES as $min => $letter) {
            if ($score >= $min) {
                return $letter;
            }
        }
        return 'D';
    }

    /** Active rules for a (workspace, signal), memoised per request. */
    private static function rulesFor(int $workspaceId, string $signal)
    {
        if (! isset(self::$ruleCache[$workspaceId][$signal])) {
            self::$ruleCache[$workspaceId][$signal] = LeadScoringRule::query()
                ->forWorkspace($workspaceId)
                ->forSignal($signal)
                ->active()
                ->orderBy('sort')
                ->get();
        }
        return self::$ruleCache[$workspaceId][$signal];
    }

    /**
     * AND-evaluate [[field, op, value], …] against the signal context. Empty
     * conditions always match. String compares are case-insensitive.
     */
    private static function conditionsMatch($conditions, array $context): bool
    {
        if (empty($conditions) || ! is_array($conditions)) {
            return true;
        }
        foreach ($conditions as $c) {
            $field = is_array($c) ? ($c[0] ?? ($c['field'] ?? null)) : null;
            $op    = is_array($c) ? ($c[1] ?? ($c['op'] ?? 'eq')) : 'eq';
            $val   = is_array($c) ? ($c[2] ?? ($c['value'] ?? null)) : null;
            if ($field === null) {
                continue;
            }
            $actual = $context[$field] ?? null;
            if (! self::compare($actual, (string) $op, $val)) {
                return false;
            }
        }
        return true;
    }

    private static function compare($actual, string $op, $expected): bool
    {
        $a = is_string($actual) ? mb_strtolower(trim($actual)) : $actual;
        $e = is_string($expected) ? mb_strtolower(trim($expected)) : $expected;

        switch ($op) {
            case 'eq':           return (string) $a === (string) $e;
            case 'neq':          return (string) $a !== (string) $e;
            case 'contains':     return $a !== null && $e !== null && str_contains((string) $a, (string) $e);
            case 'not_contains': return ! ($a !== null && $e !== null && str_contains((string) $a, (string) $e));
            case 'in':           return is_array($expected) && in_array($actual, $expected, false);
            case 'exists':       return $actual !== null && $actual !== '';
            case 'gt':           return is_numeric($actual) && is_numeric($expected) && $actual > $expected;
            case 'lt':           return is_numeric($actual) && is_numeric($expected) && $actual < $expected;
            case 'gte':          return is_numeric($actual) && is_numeric($expected) && $actual >= $expected;
            case 'lte':          return is_numeric($actual) && is_numeric($expected) && $actual <= $expected;
            default:             return (string) $a === (string) $e;
        }
    }

    /** Test hook — clear the per-request rule cache. */
    public static function flushCache(): void
    {
        self::$ruleCache = [];
    }
}
