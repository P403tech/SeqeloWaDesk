<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesforceIntegration extends Model
{
    protected $fillable = [
        'workspace_id', 'user_id',
        'org_id', 'org_name', 'org_email', 'instance_url', 'login_host',
        'access_token', 'refresh_token', 'access_token_expires_at',
        'scopes', 'status', 'metadata',
        'last_verified_at', 'connected_at',
    ];

    protected $casts = [
        'access_token'            => 'encrypted',
        'refresh_token'           => 'encrypted',
        'metadata'                => 'array',
        'access_token_expires_at' => 'datetime',
        'last_verified_at'        => 'datetime',
        'connected_at'            => 'datetime',
    ];

    protected $hidden = ['access_token', 'refresh_token'];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(SalesforceIntegrationLog::class, 'integration_id');
    }

    public function isConnected(): bool
    {
        return $this->status === 'active' && ! empty($this->access_token);
    }
}
