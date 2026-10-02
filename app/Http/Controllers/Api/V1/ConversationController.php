<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\MessageResource;
use App\Models\Conversation;
use App\Models\InboxMessage;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Conversations — read the workspace inbox: the chat threads and the
 * messages inside each one. Read-only (sending is POST /messages).
 */
class ConversationController extends V1Controller
{
    /** GET /api/v1/conversations — recent chat threads (newest first). */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->input('per_page', 25), 1), 100);

        // `origin` records which surface opened a thread, not what the thread
        // IS — and now that there is one thread per number, whichever surface
        // happened to be first owns that label. Filtering on a single value
        // would return an arbitrary subset of the customer's threads, so use
        // the same scope the inbox uses (chat + inbox + chatbot, excluding
        // campaign rows, which live under /broadcasts).
        $p = Conversation::query()
            ->where('workspace_id', $this->workspaceId())
            ->chatOnly()
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return $this->ok(
            collect($p->items())->map(fn (Conversation $c) => $this->shape($c))->all(),
            ['page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total(), 'last_page' => $p->lastPage()]
        );
    }

    /** GET /api/v1/conversations/{id}/messages — messages in one thread. */
    public function messages(Request $request, int $id): JsonResponse
    {
        $conv = Conversation::query()
            ->where('workspace_id', $this->workspaceId())
            ->whereKey($id)
            ->first();

        if (!$conv) {
            return $this->fail('not_found', 'Conversation not found.', 404);
        }

        $perPage = min(max((int) $request->input('per_page', 50), 1), 200);

        // Non-WhatsApp channels (Facebook / Instagram / Telegram / …) store their
        // bubbles in `inbox_messages`, WhatsApp in `messages`. Read the right
        // table so a thread's history is never empty on a channel.
        if (self::isChannelThread($conv)) {
            $p = InboxMessage::query()
                ->where('conversation_id', $conv->id)
                ->orderByDesc('id')
                ->paginate($perPage);

            return $this->ok(
                collect($p->items())->map(fn (InboxMessage $m) => self::shapeInboxMessage($m))->all(),
                ['conversation_id' => $conv->id, 'channel' => $conv->channel ?: 'whatsapp', 'page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total(), 'last_page' => $p->lastPage()]
            );
        }

        $p = Message::query()
            ->where('conversation_id', $conv->id)
            ->orderByDesc('id')
            ->paginate($perPage);

        return $this->ok(
            collect($p->items())->map(fn (Message $m) => (new MessageResource($m))->resolve())->all(),
            ['conversation_id' => $conv->id, 'channel' => 'whatsapp', 'page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total(), 'last_page' => $p->lastPage()]
        );
    }

    /** A thread carried by a non-WhatsApp channel (bubbles live in inbox_messages). */
    public static function isChannelThread(Conversation $c): bool
    {
        return in_array((string) $c->channel, Conversation::ENGINE_AGNOSTIC_CHANNELS, true);
    }

    /** Public shape of a channel bubble (inbox_messages row). */
    public static function shapeInboxMessage(InboxMessage $m): array
    {
        return [
            'id'         => $m->id,
            'direction'  => $m->direction,          // in | out
            'from'       => $m->from_number,
            'to'         => $m->to_number,
            'type'       => $m->media_type ?: 'text',
            'body'       => $m->body,
            'media_url'  => $m->media_path ? (preg_match('#^https?://#i', (string) $m->media_path) ? $m->media_path : media_url($m->media_path)) : null,
            'status'     => $m->status,
            'created_at' => optional($m->created_at)->toIso8601String(),
        ];
    }

    /** Public shape of a conversation thread. */
    private function shape(Conversation $c): array
    {
        $channel = $c->channel ?: 'whatsapp';

        if (self::isChannelThread($c)) {
            // raw_jid = "fb:pageId:psid" / "ig:igUserId:igsid" / "tg:botId:chatId"
            // — the customer id is the last segment.
            $parts   = explode(':', (string) $c->raw_jid);
            $contact = $parts ? (string) end($parts) : null;
        } else {
            // raw_jid looks like "919812345678@s.whatsapp.net" — expose the number.
            $contact = preg_replace('/\D+/', '', (string) explode('@', (string) $c->raw_jid)[0]) ?: null;
        }

        return [
            'id'              => $c->id,
            'contact'         => $contact,
            'name'            => $c->title,
            'last_message'    => $c->preview,
            'last_message_at' => optional($c->last_message_at)->toIso8601String(),
            'unread'          => (int) ($c->unread_count ?? 0),
            'status'          => $c->status,
            'channel'         => $channel,
        ];
    }
}
