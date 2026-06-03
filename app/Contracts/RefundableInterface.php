<?php

namespace App\Contracts;

use App\Models\Order;

interface RefundableInterface
{
    /**
     * Refund a payment through the gateway.
     *
     * @return array{code: int, refund_id?: string|null, amount?: int|string|null, msg?: string}
     */
    public function refund(Order $order, int $amount): array;
}
