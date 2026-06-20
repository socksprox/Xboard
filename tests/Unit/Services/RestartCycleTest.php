<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PlanService;
use App\Services\PurchaseOptionsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class RestartCycleTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseOptionsService $purchaseOptionsService;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forever('admin_settings', [
            'restart_cycle_enable' => 1,
            'restart_cycle_require_depleted' => 1,
            'restart_cycle_depleted_threshold' => 100,
            'renew_order_event_id' => 0,
            'restart_order_event_id' => 0,
            'purchase_suggestion_enable' => 1,
        ]);

        $this->purchaseOptionsService = app(PurchaseOptionsService::class);
    }

    public function test_calculate_expired_at_stacks_from_base_timestamp(): void
    {
        $base = Carbon::now()->addDays(10)->timestamp;
        $expected = Carbon::createFromTimestamp($base)->addMonths(1)->timestamp;

        $this->assertSame($expected, OrderService::calculateExpiredAt(Plan::PERIOD_MONTHLY, $base));
    }

    public function test_set_order_type_restart_when_flag_set(): void
    {
        $plan = $this->createPlan();
        $user = $this->createUser($plan, depleted: true, remainingDays: 20);

        $order = new Order([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'period' => Plan::PERIOD_MONTHLY,
            'total_amount' => 999,
        ]);

        $orderService = new OrderService($order);
        $orderService->restartCycle = true;
        $orderService->setOrderType($user);

        $this->assertSame(Order::TYPE_RESTART_CYCLE, $order->type);
    }

    public function test_set_order_type_renewal_without_restart_flag(): void
    {
        $plan = $this->createPlan();
        $user = $this->createUser($plan, depleted: true, remainingDays: 20);

        $order = new Order([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'period' => Plan::PERIOD_MONTHLY,
            'total_amount' => 999,
        ]);

        $orderService = new OrderService($order);
        $orderService->setOrderType($user);

        $this->assertSame(Order::TYPE_RENEWAL, $order->type);
    }

    public function test_purchase_options_show_extend_and_restart_previews(): void
    {
        $plan = $this->createPlan();
        $user = $this->createUser($plan, depleted: true, remainingDays: 20);

        $options = $this->purchaseOptionsService->buildForUser($user, $plan);

        $this->assertTrue($options['intents']['extend']['available']);
        $this->assertTrue($options['intents']['restart']['available']);
        $this->assertArrayHasKey('month_price', $options['intents']['extend']['periods']);
        $this->assertArrayHasKey('month_price', $options['intents']['restart']['periods']);
        $this->assertSame(20, $options['intents']['restart']['periods']['month_price']['forfeited_days']);
        $this->assertTrue($options['intents']['restart']['periods']['month_price']['traffic_resets']);
        $this->assertFalse($options['intents']['extend']['periods']['month_price']['traffic_resets']);
    }

    public function test_purchase_options_restart_unavailable_when_not_depleted(): void
    {
        $plan = $this->createPlan();
        $user = $this->createUser($plan, depleted: false, remainingDays: 20);

        $options = $this->purchaseOptionsService->buildForUser($user, $plan);

        $this->assertFalse($options['intents']['restart']['available']);
        $this->assertTrue($options['intents']['extend']['available']);
    }

    public function test_reset_traffic_requires_usage_threshold_for_users(): void
    {
        $plan = $this->createPlan();
        $user = $this->createUser($plan, depleted: false, remainingDays: 20);

        $planService = new PlanService($plan);

        $this->expectException(ApiException::class);
        $planService->validatePurchase($user, 'reset_price');
    }

    public function test_reset_traffic_allowed_when_depleted(): void
    {
        $plan = $this->createPlan();
        $user = $this->createUser($plan, depleted: true, remainingDays: 20);

        $planService = new PlanService($plan);
        $planService->validatePurchase($user, 'reset_price');

        $this->addToAssertionCount(1);
    }

    public function test_open_restart_cycle_anchors_next_reset_to_new_expiry(): void
    {
        $plan = $this->createPlan();
        $oldExpiry = Carbon::now()->addDays(20)->startOfDay()->addHours(12);
        $user = $this->createUser($plan, depleted: true, remainingDays: 20, expiredAt: $oldExpiry);

        $order = Order::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'period' => Plan::PERIOD_MONTHLY,
            'type' => Order::TYPE_RESTART_CYCLE,
            'trade_no' => 'test-restart-' . uniqid(),
            'total_amount' => 999,
            'status' => Order::STATUS_PROCESSING,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        (new OrderService($order))->open();

        $user->refresh();
        $newExpiry = Carbon::createFromTimestamp((int) $user->expired_at);
        $nextReset = Carbon::createFromTimestamp((int) $user->next_reset_at);

        $this->assertTrue($newExpiry->greaterThan(Carbon::now()->addWeeks(3)));
        $this->assertNotNull($user->next_reset_at);
        $this->assertLessThan(
            abs($nextReset->diffInDays($oldExpiry)),
            abs($nextReset->diffInDays($newExpiry))
        );
    }

    public function test_get_purchasable_subscription_periods_excludes_reset_and_onetime(): void
    {
        $plan = $this->createPlan();
        $planService = new PlanService($plan);

        $periods = $planService->getPurchasableSubscriptionPeriods();

        $this->assertArrayHasKey(Plan::PERIOD_MONTHLY, $periods);
        $this->assertSame('month_price', $periods[Plan::PERIOD_MONTHLY]);
        $this->assertArrayNotHasKey(Plan::PERIOD_RESET_TRAFFIC, $periods);
    }

    private function createPlan(): Plan
    {
        return Plan::query()->create([
            'group_id' => 1,
            'transfer_enable' => 50,
            'name' => 'Test Plan',
            'speed_limit' => null,
            'show' => true,
            'renew' => true,
            'sell' => true,
            'prices' => [
                Plan::PERIOD_MONTHLY => 9.99,
                Plan::PRICE_TYPE_RESET_TRAFFIC => 9.99,
            ],
            'sort' => 1,
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function createUser(Plan $plan, bool $depleted, int $remainingDays, ?Carbon $expiredAt = null): User
    {
        $capBytes = $plan->transfer_enable * 1073741824;
        $expiredAtTimestamp = ($expiredAt ?? Carbon::now()->addDays($remainingDays))->timestamp;

        return User::query()->create([
            'email' => uniqid('user', true) . '@example.com',
            'password' => password_hash('password', PASSWORD_DEFAULT),
            'uuid' => uniqid('', true),
            'token' => uniqid('', true),
            'plan_id' => $plan->id,
            'group_id' => $plan->group_id,
            'transfer_enable' => $capBytes,
            'u' => $depleted ? $capBytes : 0,
            'd' => 0,
            'expired_at' => $expiredAtTimestamp,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}
