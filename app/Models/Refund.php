<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $order_id
 * @property int $user_id
 * @property string $trade_no
 * @property int $amount
 * @property string $method
 * @property string|null $payment_plugin
 * @property int|null $payment_id
 * @property string|null $gateway_refund_id
 * @property string $status
 * @property bool $revoked_access
 * @property int|null $admin_id
 * @property string|null $note
 * @property int $created_at
 * @property int $updated_at
 */
class Refund extends Model
{
    protected $table = 'v2_order_refunds';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'revoked_access' => 'boolean',
    ];

    const METHOD_GATEWAY = 'gateway';
    const METHOD_BALANCE = 'balance';

    const STATUS_SUCCEEDED = 'succeeded';
    const STATUS_FAILED = 'failed';
    const STATUS_PENDING = 'pending';

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id', 'id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id')->withTrashed();
    }
}
