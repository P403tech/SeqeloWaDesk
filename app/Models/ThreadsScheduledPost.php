<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A composed / scheduled Threads post. Published by ThreadsScheduledPostSweeper
 * via the 2-step container → publish flow. Mirrors the Instagram scheduled-post
 * shape so the unified Social Calendar renders it alongside the other channels.
 */
class ThreadsScheduledPost extends Model
{
    protected $fillable = [
        'workspace_id', 'threads_account_id',
        'media_type', 'text', 'image_url', 'video_url', 'carousel_urls', 'link_attachment',
        'scheduled_at', 'status', 'pending', 'creation_id', 'media_id', 'last_error', 'published_at',
        'cross_to_ig',
    ];

    protected $casts = [
        'carousel_urls' => 'array',
        'scheduled_at'  => 'datetime',
        'published_at'  => 'datetime',
        'pending'       => 'boolean',
        'cross_to_ig'   => 'boolean',
    ];

    public function scopeForWorkspace($q, int $workspaceId)
    {
        return $q->where('workspace_id', $workspaceId);
    }

    public function account()
    {
        return $this->belongsTo(ThreadsAccount::class, 'threads_account_id');
    }
}
