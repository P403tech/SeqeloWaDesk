<?php

namespace App\Services\AiChat;

use App\Models\Attribute;
use App\Models\ChatbotWidget;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\InboxMessage;
use App\Services\AiAgentService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Website AI chat → conversational lead capture.
 *
 * After the AI has replied, this runs ONE structured extraction over the
 * conversation to pull the visitor's details — name/email/phone plus the
 * workspace's own custom attributes — and merges anything new onto the Contact
 * linked to the chat. So the AI chat behaves like a form the visitor never has
 * to fill in, and the captured fields are then available to the AI (it sees the
 * transcript) and to templates/flows as {{attributes}}.
 *
 * Only BLANK contact fields are filled — a mis-extraction can never clobber a
 * value the visitor already gave. Debounced per conversation so a chatty thread
 * doesn't fire an extraction on every turn. Runs after the HTTP response
 * (dispatch()->afterResponse()) so it never slows the widget reply.
 */
class WidgetLeadCaptureService
{
    public function __construct(private AiAgentService $provider) {}

    public function capture(ChatbotWidget $widget, Conversation $convo): void
    {
        try {
            $assistant = $widget->assistant;
            if (!$assistant) return;

            $contact = $convo->contact_id ? Contact::find($convo->contact_id) : null;
            if (!$contact) return;

            // Debounce — at most once / 25s per conversation, so rapid turns
            // don't each spend an extraction call.
            if (!Cache::add('widget_capture:' . $convo->id, 1, now()->addSeconds(25))) return;

            $wsId = (int) $widget->workspace_id;

            // Target fields = standard identity + the workspace's custom attributes.
            $fields = [
                'name'  => "the person's full name",
                'email' => "their email address",
                'phone' => "their phone number in digits (with country code if stated)",
            ];
            foreach (Attribute::where('workspace_id', $wsId)->get(['attribute_key', 'attribute_name']) as $a) {
                $key = trim((string) $a->attribute_key);
                if ($key === '' || isset($fields[$key])) continue;
                $fields[$key] = (string) ($a->attribute_name ?: $key);
            }

            // Transcript (last ~20 turns of the chat).
            $lines = [];
            $history = InboxMessage::where('conversation_id', $convo->id)->orderByDesc('id')->limit(20)->get()->reverse();
            foreach ($history as $m) {
                $t = trim((string) $m->body);
                if ($t === '') continue;
                $lines[] = ($m->direction === 'in' ? 'Visitor' : 'Assistant') . ': ' . $t;
            }
            $transcript = implode("\n", $lines);
            if (trim($transcript) === '') return;

            $keyList = implode(', ', array_keys($fields));
            $desc = implode("\n", array_map(fn ($k, $v) => "- {$k}: {$v}", array_keys($fields), array_values($fields)));
            $system = "You extract lead/contact details from a website chat. Respond with ONLY a JSON object "
                . "with EXACTLY these keys: {$keyList}. For each key use the value the VISITOR actually stated, "
                . "or an empty string \"\" if they did not state it. NEVER invent, guess, or infer.\n\nField meanings:\n{$desc}";
            $user = "Conversation:\n{$transcript}\n\nReturn the JSON now.";

            $raw = $this->provider->callProvider(
                provider:     (string) $assistant->ai_provider,
                model:        (string) $assistant->ai_model,
                workspaceId:  $wsId,
                systemPrompt: $system,
                userPrompt:   $user,
                maxTokens:    500,
                temperature:  0.0,
                jsonMode:     true,
            );
            $parsed = json_decode($this->stripFences($raw), true);
            if (!is_array($parsed)) return;

            $custom  = is_array($contact->custom_attributes) ? $contact->custom_attributes : [];
            $touched = false;
            foreach ($fields as $key => $_) {
                $val = trim((string) ($parsed[$key] ?? ''));
                if ($val === '') continue;

                if ($key === 'name') {
                    if (blank($contact->name)) { $contact->name = $val; $touched = true; }
                } elseif ($key === 'email') {
                    if (blank($contact->email) && filter_var($val, FILTER_VALIDATE_EMAIL)) { $contact->email = $val; $touched = true; }
                } elseif ($key === 'phone') {
                    $digits = preg_replace('/\D+/', '', $val);
                    if ($digits !== '' && strlen($digits) >= 8 && blank($contact->mobile)) {
                        $contact->mobile = $digits;
                        $contact->mobile_hash = Contact::hashPhone(null, $digits);
                        $touched = true;
                    }
                } else { // workspace custom attribute — fill only when empty
                    if (blank($custom[$key] ?? null)) { $custom[$key] = $val; $touched = true; }
                }
            }

            if ($touched) {
                $contact->custom_attributes = $custom;
                $contact->save();
                Log::info('[WIDGET-CAPTURE] merged captured fields onto contact', [
                    'workspace_id' => $wsId, 'conversation_id' => $convo->id, 'contact_id' => $contact->id,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('[WIDGET-CAPTURE] failed: ' . $e->getMessage());
        }
    }

    private function stripFences(?string $s): string
    {
        $s = trim((string) $s);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $s, $m)) return trim($m[1]);
        return $s;
    }
}
