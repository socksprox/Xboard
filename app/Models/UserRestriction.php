<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserRestriction extends Model
{
    public const SCOPE_NODE = 'node';
    public const SCOPE_STRICT_POOL = 'strict_pool';
    public const SCOPE_GLOBAL = 'global';

    protected $table = 'v2_user_restriction';

    protected $guarded = ['id'];

    protected $casts = [
        'expires_at' => 'timestamp',
        'revoked_at' => 'timestamp',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function offense(): BelongsTo
    {
        return $this->belongsTo(UserOffense::class, 'offense_id');
    }

    public function isActive(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }
        if ($this->expires_at === null) {
            return true;
        }

        return $this->expires_at > time();
    }
}
