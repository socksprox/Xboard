<?php

namespace App\Support;

final class PlanSeo
{
    /** Billing periods shown to consumers (excludes traffic reset). */
    public const CONSUMER_PERIOD_LABELS = [
        'month_price' => 'Monthly',
        'quarter_price' => 'Quarterly',
        'half_year_price' => 'Semi-annual',
        'year_price' => 'Yearly',
        'two_year_price' => '2-year',
        'three_year_price' => '3-year',
        'onetime_price' => 'One-time',
    ];

    private const PRELOAD_KEYS = [
        'id',
        'group_id',
        'name',
        'tags',
        'month_price',
        'quarter_price',
        'half_year_price',
        'year_price',
        'two_year_price',
        'three_year_price',
        'onetime_price',
        'reset_price',
        'capacity_limit',
        'transfer_enable',
        'speed_limit',
        'device_limit',
        'show',
        'sell',
        'renew',
        'reset_traffic_method',
        'sort',
    ];

    /**
     * @param array<int, array<string, mixed>> $plans
     * @return array<int, array{name: string, specs: string, prices: string, summary: string}>
     */
    public static function buildSeoSummaries(array $plans, string $currencySymbol): array
    {
        return array_values(array_map(function (array $plan) use ($currencySymbol): array {
            $priceParts = [];

            foreach (self::CONSUMER_PERIOD_LABELS as $key => $label) {
                $cents = $plan[$key] ?? null;
                if ($cents === null || $cents <= 0) {
                    continue;
                }
                $priceParts[] = $label . ' ' . $currencySymbol . number_format($cents / 100, 2);
            }

            $specs = self::formatPlanSpecs($plan);

            return [
                'name' => (string) ($plan['name'] ?? 'Plan'),
                'specs' => $specs,
                'prices' => implode(', ', $priceParts),
                'summary' => $specs . ' — ' . implode(', ', $priceParts),
            ];
        }, $plans));
    }

    /**
     * @param array<string, mixed> $plan
     */
    public static function formatPlanSpecs(array $plan): string
    {
        $parts = [];

        $transferGb = $plan['transfer_enable'] ?? null;
        if ($transferGb !== null && $transferGb > 0) {
            $parts[] = (int) $transferGb . ' GB';
        }

        $speedLimit = $plan['speed_limit'] ?? null;
        $parts[] = $speedLimit === null
            ? 'Unlimited speed'
            : (int) $speedLimit . ' Mbps';

        $deviceLimit = $plan['device_limit'] ?? null;
        if ($deviceLimit === null) {
            $parts[] = 'Unlimited devices';
        } else {
            $count = (int) $deviceLimit;
            $parts[] = $count . ($count === 1 ? ' device' : ' devices');
        }

        return implode(', ', $parts);
    }

    /**
     * @param array<string, mixed> $plan
     * @return array<int, array<string, string>>
     */
    public static function planSpecProperties(array $plan): array
    {
        $properties = [];

        $transferGb = $plan['transfer_enable'] ?? null;
        if ($transferGb !== null && $transferGb > 0) {
            $properties[] = [
                '@type' => 'PropertyValue',
                'name' => 'Traffic',
                'value' => (int) $transferGb . ' GB',
            ];
        }

        $speedLimit = $plan['speed_limit'] ?? null;
        $properties[] = [
            '@type' => 'PropertyValue',
            'name' => 'Speed',
            'value' => $speedLimit === null ? 'Unlimited' : (int) $speedLimit . ' Mbps',
        ];

        $deviceLimit = $plan['device_limit'] ?? null;
        $properties[] = [
            '@type' => 'PropertyValue',
            'name' => 'Devices',
            'value' => $deviceLimit === null
                ? 'Unlimited'
                : (string) (int) $deviceLimit,
        ];

        return $properties;
    }

    /**
     * @param array<int, array<string, mixed>> $plans
     * @return array<int, array<string, mixed>>
     */
    public static function buildPreloadPlans(array $plans): array
    {
        return array_values(array_map(
            static fn (array $plan): array => array_intersect_key($plan, array_flip(self::PRELOAD_KEYS)),
            $plans
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $plans
     */
    public static function buildJsonLd(
        array $plans,
        string $appName,
        string $appUrl,
        string $currency
    ): array {
        $itemListElement = [];
        $pricingUrl = rtrim($appUrl, '/') . '/#/pricing';

        foreach ($plans as $plan) {
            $low = null;
            $high = null;
            $offerCount = 0;

            foreach (self::CONSUMER_PERIOD_LABELS as $key => $_label) {
                $cents = $plan[$key] ?? null;
                if ($cents === null || $cents <= 0) {
                    continue;
                }

                $price = $cents / 100;
                $low = $low === null ? $price : min($low, $price);
                $high = $high === null ? $price : max($high, $price);
                $offerCount++;
            }

            if ($offerCount === 0) {
                continue;
            }

            $itemListElement[] = [
                '@type' => 'ListItem',
                'position' => count($itemListElement) + 1,
                'item' => [
                    '@type' => 'Product',
                    'name' => $plan['name'] ?? 'Plan',
                    'additionalProperty' => self::planSpecProperties($plan),
                    'offers' => [
                        '@type' => 'AggregateOffer',
                        'lowPrice' => number_format($low, 2, '.', ''),
                        'highPrice' => number_format($high, 2, '.', ''),
                        'offerCount' => $offerCount,
                        'priceCurrency' => $currency,
                        'url' => $pricingUrl,
                    ],
                ],
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => $appName . ' Pricing',
            'itemListElement' => $itemListElement,
        ];
    }
}
