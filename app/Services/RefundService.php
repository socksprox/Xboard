<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Refund;
use App\Models\User;
use App\Services\TrafficResetService;
use App\Services\Plugin\HookManager;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RefundService
{
    private const PERIOD_MONTHS = [
        Plan::PERIOD_MONTHLY => 1,
        Plan::PERIOD_QUARTERLY => 3,
        Plan::PERIOD_HALF_YEARLY => 6,
        Plan::PERIOD_YEARLY => 12,
        Plan::PERIOD_TWO_YEARLY => 24,
        Plan::PERIOD_THREE_YEARLY => 36,
    ];

    public function execute(
        Order $order,
        int $amount,
        string $method,
        int $adminId,
        ?string $note = null,
        ?bool $revokeAccess = null,
    ): Refund {
        if ($order->status !== Order::STATUS_COMPLETED) {
            throw new ApiException('Only completed orders can be refunded.');
        }

        $chargeable = $this->chargeableAmount($order);
        $priorRefunded = $this->priorRefundedAmount($order);

        if ($amount < 1) {
            throw new ApiException('Refund amount must be at least 1.');
        }

        if ($priorRefunded + $amount > $chargeable) {
            throw new ApiException(sprintf(
                'Refund amount exceeds remaining refundable total (%d).',
                max(0, $chargeable - $priorRefunded)
            ));
        }

        $payment = $order->payment_id ? Payment::find($order->payment_id) : null;
        $paymentMethod = $payment?->payment;
        $paymentPlugin = $paymentMethod;

        HookManager::call('order.refund.before', [
            'order' => $order,
            'amount' => $amount,
            'method' => $method,
            'admin_id' => $adminId,
        ]);

        $gatewayRefundId = null;
        $refundStatus = Refund::STATUS_SUCCEEDED;

        if ($method === Refund::METHOD_GATEWAY) {
            if (!$payment || !$paymentMethod) {
                throw new ApiException('Order has no payment method for gateway refund.');
            }

            $paymentService = new PaymentService($paymentMethod, $order->payment_id);
            if (!$paymentService->canRefund()) {
                throw new ApiException(
                    'This payment method does not support gateway refunds. Use balance credit instead.'
                );
            }

            $result = $paymentService->refund($order, $amount);
            if (($result['code'] ?? -1) !== 0) {
                throw new ApiException($result['msg'] ?? 'Gateway refund failed.');
            }

            $gatewayRefundId = $result['refund_id'] ?? null;
        } elseif ($method !== Refund::METHOD_BALANCE) {
            throw new ApiException('Invalid refund method.');
        }

        $shouldRevoke = $revokeAccess ?? (bool) admin_setting('refund_revoke_access_default', 0);

        return DB::transaction(function () use (
            $order,
            $amount,
            $method,
            $paymentPlugin,
            $adminId,
            $note,
            $gatewayRefundId,
            $refundStatus,
            $shouldRevoke,
            $priorRefunded,
            $chargeable,
        ) {
            if ($method === Refund::METHOD_BALANCE) {
                $userService = new UserService();
                if (!$userService->addBalance($order->user_id, $amount)) {
                    throw new ApiException('Failed to credit user balance.');
                }
            }
            $refund = Refund::create([
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'trade_no' => $order->trade_no,
                'amount' => $amount,
                'method' => $method,
                'payment_plugin' => $paymentPlugin,
                'payment_id' => $order->payment_id,
                'gateway_refund_id' => $gatewayRefundId,
                'status' => $refundStatus,
                'revoked_access' => false,
                'admin_id' => $adminId,
                'note' => $note,
            ]);

            $totalRefunded = $priorRefunded + $amount;
            $order->refund_status = $totalRefunded >= $chargeable
                ? Order::REFUND_STATUS_FULL
                : Order::REFUND_STATUS_PARTIAL;
            $order->save();

            if ($shouldRevoke) {
                try {
                    $this->revokeAccess($order);
                    $refund->revoked_access = true;
                    $refund->save();
                } catch (\Throwable $e) {
                    Log::warning('Refund access revocation failed', [
                        'order_id' => $order->id,
                        'trade_no' => $order->trade_no,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            HookManager::call('order.refund.after', $refund);

            return $refund;
        });
    }

    public function remainingRefundableAmount(Order $order): int
    {
        return max(0, $this->chargeableAmount($order) - $this->priorRefundedAmount($order));
    }

    public function chargeableAmount(Order $order): int
    {
        return (int) $order->total_amount + (int) ($order->handling_amount ?? 0);
    }

    public function priorRefundedAmount(Order $order): int
    {
        return (int) Refund::query()
            ->where('order_id', $order->id)
            ->where('status', Refund::STATUS_SUCCEEDED)
            ->sum('amount');
    }

    protected function revokeAccess(Order $order): void
    {
        $user = User::lockForUpdate()->find($order->user_id);
        if (!$user) {
            throw new ApiException('User not found.');
        }

        $periodKey = PlanService::getPeriodKey((string) $order->period);

        if ($periodKey === Plan::PERIOD_ONETIME) {
            if ((int) $user->plan_id === (int) $order->plan_id) {
                $user->plan_id = null;
                $user->group_id = null;
                $user->expired_at = null;
                $user->transfer_enable = 0;
            }
            $user->save();
            return;
        }

        if ($periodKey === Plan::PERIOD_RESET_TRAFFIC) {
            return;
        }

        $months = self::PERIOD_MONTHS[$periodKey] ?? null;
        if ($months === null) {
            Log::warning('Refund revoke: unknown period, manual review may be needed', [
                'order_id' => $order->id,
                'period' => $order->period,
            ]);
            return;
        }

        if ($user->expired_at === null) {
            return;
        }

        $base = max((int) $user->expired_at, time());
        $newExpiry = Carbon::createFromTimestamp($base)->subMonths($months)->timestamp;
        $user->expired_at = max(time(), $newExpiry);

        if ((int) $order->type === Order::TYPE_NEW_PURCHASE && $user->expired_at <= time()) {
            if ((int) $user->plan_id === (int) $order->plan_id) {
                $user->plan_id = null;
                $user->group_id = null;
            }
        }

        if ((int) $order->type === Order::TYPE_RESTART_CYCLE && $user->expired_at <= time()) {
            if ((int) $user->plan_id === (int) $order->plan_id) {
                $user->plan_id = null;
                $user->group_id = null;
            }
        }

        if ($user->plan_id !== null && $user->expired_at !== null && $user->expired_at > time()) {
            $user->loadMissing('plan');
            $nextReset = app(TrafficResetService::class)->calculateNextResetTime($user);
            $user->next_reset_at = $nextReset?->timestamp;
        }

        $user->save();
    }
}
