<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per Threads reply the poller has already processed (dedupe). Keeps
 * the auto-responder idempotent.
 */
class ThreadsReplyLog extends Model
{
    protected $table = 'threads_reply_log';

    protected $fillable = [
        'workspace_id', 'threads_account_id', 'reply_id', 'post_media_id',
        'from_username', 'text', 'matched_rule_id', 'action', 'replied_media_id',
    ];
}
