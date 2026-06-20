<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Plan;
use App\Models\User;
use App\Utils\Helper;

final class PurchaseOptionsService
{
    public function __construct(
        private readonly UserService $userService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildForUser(User $user, Plan $plan): array
    {
        $planService = new PlanService($plan);

        $remainingDays = $this->remainingDays($user);
        $resetDay = $this->userService->getResetDay($user);
        $usagePercent = $planService->getUsagePercent($user);
        $isDepleted = $planService->isUserDepleted($user);
        $planCapGb = (int) $plan->transfer_enable;
        $trafficAfterGb = $this->remainingTrafficGb($user);

        $current = [
            'expired_at' => $user->expired_at,
            'remaining_days' => $remainingDays,
            'usage_percent' => $usagePercent,
            'is_depleted' => $isDepleted,
            'reset_day' => $resetDay,
            'next_reset_at' => $user->next_reset_at,
        ];

        [$extendAvailable, $extendReason] = $planService->canExtend($user);
        [$restartAvailable, $restartReason] = $planService->canRestart($user);
        [$resetAvailable, $resetReason] = $planService->canUserResetTraffic($user);

        $extendPeriods = [];
        $restartPeriods = [];

        foreach ($planService->getPurchasableSubscriptionPeriods() as $periodKey => $legacyKey) {
            $priceCents = (int) round($plan->prices[$periodKey] * 100);

            if ($extendAvailable) {
                $extendExpiredAt = OrderService::calculateExpiredAt($periodKey, (int) $user->expired_at);
                $extendPeriods[$legacyKey] = [
                    'price_cents' => $priceCents,
                    'result_expired_at' => $extendExpiredAt,
                    'days_added' => $this->daysBetween((int) $user->expired_at, $extendExpiredAt),
                    'traffic_resets' => false,
                    'traffic_after_gb' => $isDepleted ? 0 : $trafficAfterGb,
                ];
            }

            if ($restartAvailable) {
                try {
                    $planService->validateRestartCyclePurchase($user, $periodKey);
                    $restartExpiredAt = OrderService::calculateExpiredAt($periodKey, time());
                    $resultRemainingDays = $this->remainingDaysFromTimestamp($restartExpiredAt);
                    $forfeitedDays = $remainingDays;

                    $restartPeriods[$legacyKey] = [
                        'price_cents' => $priceCents,
                        'result_expired_at' => $restartExpiredAt,
                        'forfeited_days' => $forfeitedDays,
                        'net_days_vs_remaining' => $resultRemainingDays - $forfeitedDays,
                        'traffic_resets' => true,
                        'traffic_after_gb' => $planCapGb,
                    ];
                } catch (ApiException) {
                    // Period-specific restart rules may apply in the future.
                }
            }
        }

        $resetTraffic = [
            'available' => $resetAvailable,
            'unavailable_reason' => $resetReason,
        ];

        if ($resetAvailable) {
            $resetPrice = $plan->getResetTrafficPrice();
            $resetTraffic += [
                'price_cents' => (int) round($resetPrice * 100),
                'result_expired_at' => $user->expired_at,
                'forfeited_days' => 0,
                'traffic_resets' => true,
                'traffic_after_gb' => $planCapGb,
            ];
        }

        $wait = [
            'available' => $resetDay !== null && $resetDay > 0,
            'free_reset_in_days' => $resetDay,
            'free_reset_at' => $user->next_reset_at,
        ];

        return [
            'plan_id' => $plan->id,
            'current' => $current,
            'intents' => [
                'extend' => [
                    'available' => $extendAvailable && $extendPeriods !== [],
                    'unavailable_reason' => $extendAvailable ? null : $extendReason,
                    'periods' => $extendPeriods,
                ],
                'restart' => [
                    'available' => $restartAvailable && $restartPeriods !== [],
                    'unavailable_reason' => $restartAvailable ? null : $restartReason,
                    'periods' => $restartPeriods,
                ],
                'reset_traffic' => $resetTraffic,
                'wait' => $wait,
            ],
            'suggestion' => $this->suggestIntent(
                $isDepleted,
                $remainingDays,
                $resetDay,
                $extendPeriods,
                $restartPeriods,
                $resetAvailable,
                $wait['available']
            ),
        ];
    }

    /**
     * @return array{
     *     extend_available: bool,
     *     restart_available: bool,
     *     reset_available: bool
     * }
     */
    public function buildPurchaseSummary(User $user, ?Plan $plan): array
    {
        if (!$plan || (int) $user->plan_id !== (int) $plan->id) {
            return [
                'extend_available' => false,
                'restart_available' => false,
                'reset_available' => false,
            ];
        }

        $planService = new PlanService($plan);
        [$extendAvailable] = $planService->canExtend($user);
        [$restartAvailable] = $planService->canRestart($user);
        [$resetAvailable] = $planService->canUserResetTraffic($user);

        return [
            'extend_available' => $extendAvailable,
            'restart_available' => $restartAvailable,
            'reset_available' => $resetAvailable,
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    public function assertRestartAvailable(array $options, string $period): void
    {
        $periodKey = PlanService::getPeriodKey($period);
        $legacyKey = PlanService::getLegacyPeriod($periodKey);
        $restart = $options['intents']['restart'] ?? null;

        if (!$restart || !($restart['available'] ?? false)) {
            throw new ApiException(__('Restart cycle is not available'));
        }

        if (!isset($restart['periods'][$legacyKey]) && !isset($restart['periods'][$period]) && !isset($restart['periods'][$periodKey])) {
            throw new ApiException(__('Restart cycle is not available for this period'));
        }
    }

    /**
     * @param array<string, array<string, mixed>> $extendPeriods
     * @param array<string, array<string, mixed>> $restartPeriods
     *
     * @return array{intent: string, reason: string, confidence: string}|null
     */
    private function suggestIntent(
        bool $isDepleted,
        int $remainingDays,
        ?int $resetDay,
        array $extendPeriods,
        array $restartPeriods,
        bool $resetAvailable,
        bool $waitAvailable,
    ): ?array {
        if (!(int) admin_setting('purchase_suggestion_enable', 1)) {
            return null;
        }

        if (!$isDepleted) {
            if ($extendPeriods !== []) {
                return [
                    'intent' => 'extend',
                    'reason' => 'not_depleted',
                    'confidence' => 'medium',
                ];
            }

            return null;
        }

        $monthlyRestart = $restartPeriods['month_price'] ?? null;
        $monthlyNetDays = $monthlyRestart['net_days_vs_remaining'] ?? null;

        if ($waitAvailable && $resetDay !== null && $resetDay <= 7 && $remainingDays > 31) {
            return [
                'intent' => 'wait',
                'reason' => 'free_reset_soon_and_long_subscription_remaining',
                'confidence' => 'medium',
            ];
        }

        if ($resetAvailable && $remainingDays > 7) {
            if ($monthlyNetDays !== null && $monthlyNetDays > 0) {
                return [
                    'intent' => 'reset_traffic',
                    'reason' => 'restart_same_price_worse_than_reset',
                    'confidence' => 'medium',
                ];
            }

            return [
                'intent' => 'reset_traffic',
                'reason' => 'reset_preserves_subscription_time',
                'confidence' => 'medium',
            ];
        }

        if ($resetAvailable || $monthlyRestart !== null) {
            return [
                'intent' => $resetAvailable ? 'reset_traffic' : 'restart',
                'reason' => 'short_remaining_compare_net_days',
                'confidence' => 'low',
            ];
        }

        return null;
    }

    private function remainingDays(User $user): int
    {
        if ($user->expired_at === null || $user->expired_at <= time()) {
            return 0;
        }

        return $this->remainingDaysFromTimestamp((int) $user->expired_at);
    }

    private function remainingDaysFromTimestamp(int $timestamp): int
    {
        return max(0, (int) ceil(($timestamp - time()) / 86400));
    }

    private function daysBetween(int $fromTimestamp, int $toTimestamp): int
    {
        return max(0, (int) ceil(($toTimestamp - $fromTimestamp) / 86400));
    }

    private function remainingTrafficGb(User $user): float
    {
        $cap = (int) $user->transfer_enable;
        if ($cap <= 0) {
            return 0.0;
        }

        $used = ($user->u ?? 0) + ($user->d ?? 0);

        return max(0, round(Helper::transferToGB($cap - $used), 2));
    }
}
