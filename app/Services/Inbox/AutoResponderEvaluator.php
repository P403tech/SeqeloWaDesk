<?php

namespace App\Services\Inbox;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\InboxMessage;
use App\Models\KeywordReply;
use Illuminate\Support\Carbon;

/**
 * Shared gate logic for the Auto Responder / Welcome Message feature
 * (client Mahmoud Ashraf spec, 2026-07-31).
 *
 * ONE source of truth for the four new behaviours so the Official (WABA/Twilio/
 * Instagram → KeywordReplyDispatcher) and Unofficial (Baileys → Node →
 * AutoReplyController::lookup) paths decide identically:
 *
 *   - resendAllowed()  — re-greet only after the "Resend after" window (Step 2)
 *   - agentPaused()    — pause once a human agent replies; resume after inactivity
 *   - workingHours()   — per-rule schedule + outside-hours action (Step 3)
 *   - isExcluded()     — excluded individual numbers + number groups (Step 1)
 *
 * Every method fails OPEN (returns "allowed"/"in-hours") on any error so a bad
 * rule can never silence the bot. All new columns are nullable, so a classic
 * keyword rule (trigger_type='keyword', all gates empty) sails through unchanged.
 */
class AutoResponderEvaluator
{
    /** Re-greet window when a welcome rule leaves "Resend after" blank (24h). */
    private const DEFAULT_RESEND_SECONDS = 86400;

    /** Resume window when agent-override leaves "Resume after" blank (24h). */
    private const DEFAULT_RESUME_SECONDS = 86400;

    /**
     * "Greet once, never resend" — what an explicit 0 means on a responder rule.
     * A window, not a flag, because `keyword_replies.cooldown` is an UNSIGNED
     * int so a -1 sentinel cannot be stored. 10 years outlives any thread.
     */
    public const NEVER_RESEND_SECONDS = 315360000;

    /** Units the "Resend after" / "Resume after" pickers offer, largest first. */
    public const UNITS = ['days', 'hours', 'minutes', 'seconds'];

    /** Seconds per unit — the single source both directions of the conversion use. */
    public const UNIT_SECONDS = ['days' => 86400, 'hours' => 3600, 'minutes' => 60, 'seconds' => 1];

    /** Convert a value + Minutes/Hours/Days unit into seconds (null when unset). */
    public static function toSeconds($value, ?string $unit): ?int
    {
        // NULL/'' (field left blank) and an explicit 0 are NOT the same thing.
        // Collapsing both to null made "0" mean "unset", which the responder
        // triggers then read as the 24h default — so an operator asking for no
        // window silently got the longest one possible. Blank still means unset;
        // 0 is now carried through as a real, explicit zero.
        if ($value === null || $value === '') {
            return null;
        }
        $value = (int) $value;
        if ($value < 0) {
            return null;
        }
        if ($value === 0) {
            return 0;
        }
        return match (strtolower((string) $unit)) {
            'second', 'seconds', 'sec' => $value,
            'minute', 'minutes', 'min' => $value * 60,
            'day', 'days'              => $value * 86400,
            default                    => $value * 3600, // hours (default)
        };
    }

    /**
     * Seconds → the unit the edit form should redisplay. Picks the LARGEST unit
     * that divides the stored value exactly, preferring the operator's saved
     * unit when it still divides cleanly, so reopening the form never truncates.
     */
    public static function displayUnit($seconds, ?string $savedUnit): string
    {
        if ($seconds === null || $seconds === '') {
            return in_array($savedUnit, self::UNITS, true) ? $savedUnit : 'hours';
        }
        $s = (int) $seconds;
        if ($s <= 0) {
            return in_array($savedUnit, self::UNITS, true) ? $savedUnit : 'hours';
        }
        // Keep the operator's own unit whenever it represents the value exactly.
        $savedDiv = self::UNIT_SECONDS[$savedUnit] ?? null;
        if ($savedDiv !== null && $s % $savedDiv === 0) {
            return $savedUnit;
        }
        foreach (['days', 'hours', 'minutes', 'seconds'] as $u) {
            if ($s % self::UNIT_SECONDS[$u] === 0) {
                return $u;
            }
        }
        return 'seconds';
    }

    /**
     * Seconds → the number the edit form should redisplay, in the unit chosen by
     * displayUnit(). Null stays null (blank field); an explicit 0 stays 0.
     */
    public static function displayValue($seconds, ?string $savedUnit): ?int
    {
        if ($seconds === null || $seconds === '') {
            return null;
        }
        $s = (int) $seconds;
        if ($s <= 0) {
            return 0;
        }
        return intdiv($s, self::UNIT_SECONDS[self::displayUnit($s, $savedUnit)]);
    }

    /**
     * Effective re-greet / resend window in seconds. Canonical value is the
     * rule's `cooldown` (seconds). A welcome rule with no window defaults to 24h
     * so it can never fire on every inbound; a keyword rule with none stays 0
     * (the classic "fire every time, subject to the 30s hard floor" behaviour).
     */
    public function resendSeconds(KeywordReply $rule): int
    {
        $isResponder = in_array($rule->trigger_type ?? 'keyword', ['welcome', 'out_of_hours', 'away'], true);
        $raw = $rule->cooldown;

        // NULL = never configured → keep the historic defaults.
        if ($raw === null || $raw === '') {
            return $isResponder ? self::DEFAULT_RESEND_SECONDS : 0;
        }

        $c = (int) $raw;
        if ($c > 0) {
            return $c;
        }

        // Explicit 0. On a keyword rule that has always meant "no throttle".
        // On a responder it must NOT mean that: a welcome re-greeting on every
        // inbound would front the thread forever and swallow every keyword the
        // customer sends, because the welcome pass returns before keyword
        // matching runs. So 0 = "greet once, never resend".
        return $isResponder ? self::NEVER_RESEND_SECONDS : 0;
    }

    /**
     * True when this contact is EXCLUDED from the rule (individual number or a
     * number group). $contact may be null (unresolved) — group exclusion then
     * simply can't match, which is the safe default.
     */
    public function isExcluded(KeywordReply $rule, string $phoneDigits, ?Contact $contact = null): bool
    {
        $phoneDigits = preg_replace('/\D+/', '', $phoneDigits);

        // Individual numbers — match on digits, tolerating a country-code
        // difference via a suffix match on 8+ digit numbers.
        foreach ((is_array($rule->excluded_numbers) ? $rule->excluded_numbers : []) as $n) {
            $d = preg_replace('/\D+/', '', (string) $n);
            if ($d === '') {
                continue;
            }
            if ($d === $phoneDigits) {
                return true;
            }
            if (strlen($d) >= 8 && strlen($phoneDigits) >= 8
                && (str_ends_with($phoneDigits, $d) || str_ends_with($d, $phoneDigits))) {
                return true;
            }
        }

        // Number groups — contacts.contact_group is an ENCRYPTED id array (cast
        // decrypts it), so the check is a PHP intersect, not SQL.
        $groups = array_map('intval', is_array($rule->excluded_group_ids) ? $rule->excluded_group_ids : []);
        if ($groups && $contact) {
            $cg = array_map('intval', is_array($contact->contact_group) ? $contact->contact_group : []);
            if (array_intersect($groups, $cg)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Per-rule working-hours evaluation.
     * @return array{in: bool, outside_action: string}  outside_action = 'send'|'none'.
     * Not enabled / no config → always in-hours (legacy 24/7 behaviour).
     */
    public function workingHours(KeywordReply $rule, ?Carbon $now = null): array
    {
        $wh = is_array($rule->working_hours) ? $rule->working_hours : null;
        if (! $wh || empty($wh['enabled'])) {
            return ['in' => true, 'outside_action' => 'none'];
        }

        $action = ((string) ($wh['outside_action'] ?? 'none')) === 'send' ? 'send' : 'none';

        try {
            $tz  = (string) ($wh['timezone'] ?? '') ?: (string) config('app.timezone', 'UTC') ?: 'UTC';
            $now = ($now ? $now->copy() : Carbon::now())->setTimezone($tz);
        } catch (\Throwable $e) {
            $now = Carbon::now();
        }

        // Active business days (mon..sun). Empty list = every day.
        $days = array_map('strtolower', is_array($wh['days'] ?? null) ? $wh['days'] : []);
        $today = strtolower($now->format('D')); // "Mon" → "mon"
        if ($days && ! in_array($today, $days, true)) {
            return ['in' => false, 'outside_action' => $action];
        }

        $from = (string) ($wh['from'] ?? '');
        $to   = (string) ($wh['to'] ?? '');
        if ($from === '' || $to === '') {
            return ['in' => true, 'outside_action' => $action];
        }

        $cur = $now->format('H:i');
        // Same-day window (from <= to) vs overnight window (from > to, wraps midnight).
        $in = ($from <= $to)
            ? ($cur >= $from && $cur <= $to)
            : ($cur >= $from || $cur <= $to);

        return ['in' => $in, 'outside_action' => $action];
    }

    /**
     * True when the resend window has elapsed for this contact (or the rule has
     * never fired to them).
     *
     * The per-contact "last fired" time is tracked on the CONVERSATION's
     * routing_meta (one thread per contact = the per-contact context) — NOT on
     * keyword_reply_logs, whose contact_phone is encrypted at rest with a random
     * IV, so a `where('contact_phone', …)` there never matches. A null
     * conversation (a brand-new contact with no thread yet) means "never greeted"
     * → allowed, which is exactly the first-message case.
     */
    public function resendAllowed(KeywordReply $rule, ?Conversation $convo, string $phoneDigits): bool
    {
        $sec = $this->resendSeconds($rule);
        if ($sec <= 0 || ! $convo) {
            return true;
        }
        try {
            $meta = is_array($convo->routing_meta) ? $convo->routing_meta : [];
            $last = data_get($meta, 'ar_fires.' . $rule->id);
            if (! $last) {
                return true;
            }
            return \Illuminate\Support\Carbon::parse($last)->lt(now()->subSeconds($sec));
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * Stamp the per-contact "last fired" time for a rule on the conversation's
     * routing_meta, so resendAllowed() throttles the next inbound. Called by both
     * dispatch paths right after a welcome/auto-responder reply fires.
     */
    public function stampFire(KeywordReply $rule, ?Conversation $convo): void
    {
        if (! $convo) {
            return;
        }
        try {
            $meta = is_array($convo->routing_meta) ? $convo->routing_meta : [];
            $meta['ar_fires'] = is_array($meta['ar_fires'] ?? null) ? $meta['ar_fires'] : [];
            $meta['ar_fires'][(string) $rule->id] = now()->toIso8601String();
            $convo->forceFill(['routing_meta' => $meta])->save();
        } catch (\Throwable $e) {
            // Best-effort — a failed stamp at worst re-greets once, never breaks a send.
        }
    }

    /**
     * True when a human agent has taken over the conversation and the customer
     * has NOT yet been quiet long enough to auto-resume.
     *
     * Paused when ALL hold:
     *   - the rule has stop_on_agent_reply enabled, AND
     *   - a HUMAN agent reply exists that is NEWER than the last auto-reply fire
     *     (a genuine handover, not our own bot message), AND
     *   - the gap since the customer's PREVIOUS inbound is < the resume window
     *     (they're still in the same live conversation the agent is handling).
     */
    public function agentPaused(KeywordReply $rule, ?Conversation $convo, string $phoneDigits): bool
    {
        if (empty($rule->stop_on_agent_reply) || ! $convo) {
            return false;
        }
        try {
            // Most recent HUMAN agent outbound (has user_id, not an automated source).
            $lastAgent = InboxMessage::query()
                ->where('conversation_id', $convo->id)
                ->where('direction', 'out')
                ->whereNotNull('user_id')
                ->orderByDesc('id')->limit(25)->get()
                ->first(function ($m) {
                    $src = (string) data_get($m->meta, 'source', '');
                    return ! in_array($src, ['auto_reply', 'flow', 'keyword'], true);
                });
            if (! $lastAgent) {
                return false;
            }

            // Handover only counts if the agent replied AFTER the last auto-reply
            // fire — otherwise a stale old agent reply would pause forever.
            // Read the last-fire time from the conversation's routing_meta
            // (ar_fires[ruleId]) — the SAME source stampFire()/resendAllowed()
            // write/read. The previous version queried keyword_reply_logs on
            // contact_phone, but that column is ENCRYPTED at rest, so the
            // plaintext WHERE never matched → $lastFire was ALWAYS null and this
            // staleness guard was dead (an old agent reply could pause the
            // responder indefinitely, so it never resumed).
            $lastFireRaw = data_get(
                is_array($convo->routing_meta) ? $convo->routing_meta : [],
                'ar_fires.' . $rule->id
            );
            if ($lastFireRaw && $lastAgent->created_at) {
                try {
                    if ($lastAgent->created_at->lt(Carbon::parse($lastFireRaw))) {
                        return false; // our reply is newer than the agent's → not a live handover
                    }
                } catch (\Throwable $e) { /* unparseable stamp → skip this guard */ }
            }

            $resume = (int) ($rule->resume_after ?? 0);
            if ($resume <= 0) {
                $resume = self::DEFAULT_RESUME_SECONDS;
            }

            // Measure the resume window from the AGENT'S last reply — NOT from the
            // customer's previous inbound. "Resume after" means: resume the responder
            // only once it's been quiet for this long since a HUMAN last spoke. The
            // old code anchored on the customer's prior inbound, so a customer who
            // answered the agent an hour after their own previous message (inbound gap
            // > resume_after) looked "idle" and the responder fired on top of a live,
            // agent-handled chat — the exact "agent replied but it still auto-replies"
            // bug. Anchored on the agent's reply, the window only lapses after the
            // agent goes quiet, and every fresh agent reply re-arms the pause.
            if (! $lastAgent->created_at) {
                return true; // agent replied but timestamp missing → treat as live handover
            }
            $gap = now()->diffInSeconds($lastAgent->created_at, true);
            return $gap < $resume; // agent spoke within the resume window → still paused
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Combined gate for a WELCOME rule on a given inbound. Returns:
     *   ['fire' => bool, 'use_outside' => bool, 'reason' => string]
     * `use_outside` true ⇒ send the rule's outside-hours variant instead.
     */
    public function evaluateWelcome(KeywordReply $rule, ?Conversation $convo, string $phoneDigits, ?Contact $contact = null): array
    {
        if ($this->isExcluded($rule, $phoneDigits, $contact)) {
            return ['fire' => false, 'use_outside' => false, 'reason' => 'excluded'];
        }
        if ($this->agentPaused($rule, $convo, $phoneDigits)) {
            return ['fire' => false, 'use_outside' => false, 'reason' => 'agent_paused'];
        }
        // A Welcome greets someone the FIRST time they message — never an
        // existing team-inbox chat. Without this, the resend window is the only
        // guard, so a welcome re-greets any existing conversation once the window
        // lapses. Gate on the conversation actually being brand new.
        if (! $this->isFirstContact($convo)) {
            return ['fire' => false, 'use_outside' => false, 'reason' => 'not_new_conversation'];
        }
        if (! $this->resendAllowed($rule, $convo, $phoneDigits)) {
            return ['fire' => false, 'use_outside' => false, 'reason' => 'resend_window'];
        }
        $wh = $this->workingHours($rule);
        if (! $wh['in']) {
            if ($wh['outside_action'] === 'send') {
                return ['fire' => true, 'use_outside' => true, 'reason' => 'outside_hours_message'];
            }
            return ['fire' => false, 'use_outside' => false, 'reason' => 'outside_hours_silent'];
        }
        return ['fire' => true, 'use_outside' => false, 'reason' => 'ok'];
    }

    /**
     * True when this conversation is a genuinely NEW contact — the person is
     * messaging for the first time, not an existing team-inbox chat. Defined as:
     * at most ONE inbound bubble (the message we're reacting to) AND no outbound
     * bubble yet (we've never replied). An existing chat has prior inbound and/or
     * outbound history, so it fails this and gets no welcome. Fails OPEN on error
     * so a lookup glitch never silently stops greeting new customers.
     */
    private function isFirstContact(?Conversation $convo): bool
    {
        if (! $convo || ! $convo->exists) {
            return true; // no thread persisted yet ⇒ brand new
        }
        try {
            $inbound  = (int) $convo->inboxMessages()->in()->count();
            $outbound = (int) $convo->inboxMessages()->out()->count();
            return $inbound <= 1 && $outbound === 0;
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * True when the workspace's manual "Away mode" switch (workspaces.inbox_away)
     * is currently ON. Fails CLOSED (not away) on any error so a lookup glitch can
     * never spam away replies.
     */
    public function isWorkspaceAway(?int $workspaceId): bool
    {
        if (! $workspaceId) {
            return false;
        }
        try {
            return (bool) \App\Models\Workspace::whereKey($workspaceId)->value('inbox_away');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Combined gate for an OUT-OF-HOURS rule on a given inbound. Fires on ANY
     * inbound that arrives OUTSIDE the rule's working_hours (the rule's own
     * schedule defines the hours). If working_hours is not enabled the rule can
     * never fire — there is no "outside" to speak of, guarding against an
     * always-on out-of-hours reply. Same shape as evaluateWelcome().
     */
    public function evaluateOutOfHours(KeywordReply $rule, ?Conversation $convo, string $phoneDigits, ?Contact $contact = null): array
    {
        if ($this->isExcluded($rule, $phoneDigits, $contact)) {
            return ['fire' => false, 'use_outside' => false, 'reason' => 'excluded'];
        }
        if ($this->agentPaused($rule, $convo, $phoneDigits)) {
            return ['fire' => false, 'use_outside' => false, 'reason' => 'agent_paused'];
        }
        if (! $this->resendAllowed($rule, $convo, $phoneDigits)) {
            return ['fire' => false, 'use_outside' => false, 'reason' => 'resend_window'];
        }
        $wh = is_array($rule->working_hours) ? $rule->working_hours : null;
        if (! $wh || empty($wh['enabled'])) {
            return ['fire' => false, 'use_outside' => false, 'reason' => 'no_hours_configured'];
        }
        if ($this->workingHours($rule)['in']) {
            return ['fire' => false, 'use_outside' => false, 'reason' => 'within_hours'];
        }
        return ['fire' => true, 'use_outside' => false, 'reason' => 'ok'];
    }

    /**
     * Combined gate for an AWAY rule on a given inbound. Fires on ANY inbound while
     * the workspace's manual Away mode is ON. $away may be pre-resolved by the
     * caller (one lookup per inbound batch) or resolved here from the rule's
     * workspace. Same shape as evaluateWelcome().
     */
    public function evaluateAway(KeywordReply $rule, ?Conversation $convo, string $phoneDigits, ?Contact $contact = null, ?bool $away = null): array
    {
        $isAway = $away ?? $this->isWorkspaceAway((int) $rule->workspace_id);
        if (! $isAway) {
            return ['fire' => false, 'use_outside' => false, 'reason' => 'not_away'];
        }
        if ($this->isExcluded($rule, $phoneDigits, $contact)) {
            return ['fire' => false, 'use_outside' => false, 'reason' => 'excluded'];
        }
        if ($this->agentPaused($rule, $convo, $phoneDigits)) {
            return ['fire' => false, 'use_outside' => false, 'reason' => 'agent_paused'];
        }
        if (! $this->resendAllowed($rule, $convo, $phoneDigits)) {
            return ['fire' => false, 'use_outside' => false, 'reason' => 'resend_window'];
        }
        return ['fire' => true, 'use_outside' => false, 'reason' => 'ok'];
    }
}
