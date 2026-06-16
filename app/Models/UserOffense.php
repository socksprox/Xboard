<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserOffense extends Model
{
    public const TYPE_TORRENT = 'torrent';

    protected $table = 'v2_user_offense';

    protected $dateFormat = 'U';

    protected $guarded = ['id'];

    protected $casts = [
        'detail' => 'array',
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

    public function restriction(): BelongsTo
    {
        return $this->belongsTo(UserRestriction::class, 'restriction_id');
    }
}
