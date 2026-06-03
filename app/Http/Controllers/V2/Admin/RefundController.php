<?php

namespace App\Http\Controllers\V2\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Refund;
use App\Services\PaymentService;
use App\Services\RefundService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RefundController extends Controller
{
    public function refund(Request $request)
    {
        $request->validate([
            'trade_no' => 'required|string',
            'amount' => 'nullable|integer|min:1',
            'method' => 'required|in:gateway,balance',
            'note' => 'nullable|string|max:1000',
            'revoke_access' => 'nullable|boolean',
        ]);

        $order = Order::with('payment')->where('trade_no', $request->input('trade_no'))->first();
        if (!$order) {
            return $this->fail([400202, 'Order not found']);
        }

        $refundService = new RefundService();
        $amount = $request->input('amount');
        if ($amount === null) {
            $amount = $refundService->remainingRefundableAmount($order);
            if ($amount < 1) {
                return $this->fail([400, 'Nothing left to refund on this order.']);
            }
        }

        $method = $request->input('method');
        if ($method === Refund::METHOD_GATEWAY && $order->payment_id) {
            $paymentMethod = $order->payment?->payment;
            if ($paymentMethod) {
                $paymentService = new PaymentService($paymentMethod, $order->payment_id);
                if (!$paymentService->canRefund()) {
                    return $this->fail([
                        400,
                        'Gateway refund is not available for this payment method. Use balance credit instead.',
                    ]);
                }
            }
        }

        $adminId = (int) $request->user()->id;
        $revokeAccess = $request->has('revoke_access')
            ? $request->boolean('revoke_access')
            : null;

        try {
            $refund = $refundService->execute(
                $order,
                (int) $amount,
                $method,
                $adminId,
                $request->input('note'),
                $revokeAccess,
            );
        } catch (ApiException $e) {
            return $this->fail([500, $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::error('Refund failed', [
                'trade_no' => $order->trade_no,
                'error' => $e->getMessage(),
            ]);
            return $this->fail([500, 'Refund failed: ' . $e->getMessage()]);
        }

        return $this->success($refund);
    }

    public function fetch(Request $request)
    {
        $current = $request->input('current', 1);
        $pageSize = $request->input('pageSize', 10);

        $refundModel = Refund::query();
        $this->applyFilters($request, $refundModel);

        $paginatedResults = $refundModel
            ->latest('created_at')
            ->paginate(
                perPage: $pageSize,
                page: $current
            );

        return $this->paginate($paginatedResults);
    }

    private function applyFilters(Request $request, Builder $builder): void
    {
        if (!$request->has('filter')) {
            return;
        }

        collect($request->input('filter'))->each(function ($filter) use ($builder) {
            $field = $filter['id'];
            $value = $filter['value'];

            if (is_array($value)) {
                $builder->whereIn($field, $value);
                return;
            }

            if (!is_string($value) || !str_contains($value, ':')) {
                $builder->where($field, 'like', "%{$value}%");
                return;
            }

            [$operator, $filterValue] = explode(':', $value, 2);

            if (is_numeric($filterValue)) {
                $filterValue = strpos($filterValue, '.') !== false
                    ? (float) $filterValue
                    : (int) $filterValue;
            }

            $builder->where($field, match (strtolower($operator)) {
                'eq' => '=',
                'gt' => '>',
                'gte' => '>=',
                'lt' => '<',
                'lte' => '<=',
                'like' => 'like',
                'notlike' => 'not like',
                default => 'like',
            }, match (strtolower($operator)) {
                'like', 'notlike' => "%{$filterValue}%",
                default => $filterValue,
            });
        });
    }
}
