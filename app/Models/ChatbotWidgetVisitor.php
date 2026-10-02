<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ChatbotWidgetVisitor extends Model
{
    use HasFactory;

    protected $table = 'chatbot_widget_visitors';

    protected $fillable = [
        'workspace_id', 'widget_id', 'conversation_id',
        'visitor_uuid', 'name', 'email', 'phone',
        'referrer_url', 'user_agent', 'ip',
        'first_seen_at', 'last_seen_at',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at'  => 'datetime',
    ];

    /**
     * A stable, digits-only key identifying this visitor to the auto-reply and
     * flow engines.
     *
     * Those engines key everything — per-rule cooldowns, contact resolution,
     * flow enrolment — on a phone number, and immediately run
     * `preg_replace('/\D+/', '', $phone)`. A widget visitor may have no phone at
     * all (`collect_phone` is optional) and `visitor_uuid` does not survive that
     * strip, so without this every phone-less visitor collapses to the SAME
     * empty key: one visitor's cooldown would silence the rule for everyone.
     *
     * When a phone WAS collected we use it, so the widget visitor and the same
     * person on WhatsApp resolve to one contact. Otherwise we synthesise from
     * the row id behind a `0000` prefix — no real number normalises to leading
     * zeros, so a synthetic key can never collide with a genuine phone.
     */
    public function autoReplyKey(): string
    {
        $digits = preg_replace('/\D+/', '', (string) ($this->phone ?? ''));
        if ($digits !== '' && strlen($digits) >= 6) {
            return $digits;
        }
        return '0000' . $this->id;
    }

    /** True when autoReplyKey() is synthetic rather than a real phone number. */
    public function hasSyntheticKey(): bool
    {
        return str_starts_with($this->autoReplyKey(), '0000');
    }

    public static function freshUuid(): string
    {
        return (string) Str::uuid();
    }

    public function widget(): BelongsTo
    {
        return $this->belongsTo(ChatbotWidget::class, 'widget_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function displayName(): string
    {
        if ($this->name) return $this->name;
        if ($this->email) return $this->email;
        return 'Visitor ' . substr($this->visitor_uuid, 0, 6);
    }
}
