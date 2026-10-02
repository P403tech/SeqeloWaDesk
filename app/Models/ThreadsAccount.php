<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A connected Threads (Meta) account. The long-lived token is encrypted at
 * rest. One workspace can connect several accounts. Mirrors InstagramAccount.
 */
class ThreadsAccount extends Model
{
    protected $fillable = [
        'workspace_id', 'user_id',
        'threads_user_id', 'username', 'name', 'profile_pic_url',
        'access_token', 'token_expires_at', 'scopes',
        'status', 'last_error', 'meta_json',
    ];

    protected $casts = [
        'access_token'     => 'encrypted',
        'token_expires_at' => 'datetime',
        'scopes'           => 'array',
        'meta_json'        => 'array',
    ];

    protected $hidden = ['access_token'];

    public function scopeForWorkspace($q, int $workspaceId)
    {
        return $q->where('workspace_id', $workspaceId);
    }

    public function scopeConnected($q)
    {
        return $q->where('status', 'connected');
    }

    public function isLive(): bool
    {
        return $this->status === 'connected'
            && (! $this->token_expires_at || $this->token_expires_at->isFuture());
    }
}
