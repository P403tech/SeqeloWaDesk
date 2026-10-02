<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * One external account = one workspace, platform-wide.
 *
 * Every channel resolves its rows with `where('workspace_id', $wsId)`, which is
 * correct for listing but wrong for CLAIMING: it means a second workspace can
 * connect an account the first one already owns. That is not merely untidy —
 * for several channels the underlying session/credential store is keyed by the
 * account identifier ALONE, so the second workspace attaches to the first
 * workspace's live session instead of establishing its own:
 *
 *   - WhatsApp (unofficial): node/baileys_auth/session_<phone> has no workspace
 *     in the path, so the second workspace connects with NO QR scan — and the
 *     QR scan is the only proof of number ownership the product has.
 *   - Webhook channels (Telegram / LINE / WeChat / Viber): the provider allows
 *     ONE webhook URL per bot. Whoever connects last silently steals delivery,
 *     and the first workspace simply stops receiving messages.
 *   - Meta channels (Facebook / Instagram / WABA): inbound routing resolves the
 *     workspace from the page/account id, so a duplicate makes routing
 *     ambiguous — messages land in whichever row is found first.
 *
 * So the claim has to be checked GLOBALLY, before the row is created.
 *
 * Deliberately says nothing about WHICH workspace holds the account: that would
 * let anyone probe the platform for which numbers, pages and bots exist.
 */
class ChannelClaim
{
    /**
     * The row holding this account in a DIFFERENT workspace, or null if free.
     *
     * @param  class-string<Model>  $model      e.g. \App\Models\TelegramBot::class
     * @param  string               $column     the provider's stable identifier column
     * @param  string               $value      its value (bot id, page id, open id…)
     * @param  int                  $workspaceId the workspace trying to claim it
     */
    public static function heldElsewhere(string $model, string $column, string $value, int $workspaceId): ?Model
    {
        $value = trim($value);
        if ($value === '' || ! class_exists($model)) {
            return null;
        }

        return $model::query()
            ->where($column, $value)
            ->where('workspace_id', '!=', $workspaceId)
            ->first();
    }

    /**
     * Same check for columns that are ENCRYPTED at rest. Ciphertext is
     * non-deterministic, so a WHERE can never match — the comparison has to
     * happen in PHP after decrypting. Only used where the identifier really is
     * encrypted (device phone numbers), because it loads the table.
     *
     * @param  callable(Model): string  $normalise turns a row into its comparable value
     */
    public static function heldElsewhereEncrypted(string $model, callable $normalise, string $value, int $workspaceId): ?Model
    {
        if ($value === '' || ! class_exists($model)) {
            return null;
        }

        return $model::query()->get()
            ->first(fn ($row) => (int) ($row->workspace_id ?? 0) !== $workspaceId
                && $normalise($row) === $value);
    }

    /**
     * The message shown when an account is already claimed. Intentionally does
     * not name the other workspace, its owner, or anything else about it.
     */
    public static function takenMessage(string $what): string
    {
        return __('That :thing is already connected on this platform. Disconnect it there first, or use a different one.', [
            'thing' => $what,
        ]);
    }
}
