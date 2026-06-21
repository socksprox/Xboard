<?php

namespace App\Support;

final class PlanSeo
{
    public const PERIOD_LABELS = [
        'month_price' => 'Monthly',
        'quarter_price' => 'Quarterly',
        'half_year_price' => 'Semi-annual',
        'year_price' => 'Yearly',
        'two_year_price' => '2-year',
        'three_year_price' => '3-year',
        'onetime_price' => 'One-time',
        'reset_price' => 'Traffic reset',
    ];

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
        $position = 1;

        foreach ($plans as $plan) {
            $planName = $plan['name'] ?? 'Plan';

            foreach (self::PERIOD_LABELS as $key => $label) {
                $cents = $plan[$key] ?? null;
                if ($cents === null || $cents <= 0) {
                    continue;
                }

                $itemListElement[] = [
                    '@type' => 'ListItem',
                    'position' => $position++,
                    'item' => [
                        '@type' => 'Offer',
                        'name' => $planName . ' - ' . $label,
                        'price' => number_format($cents / 100, 2, '.', ''),
                        'priceCurrency' => $currency,
                        'url' => rtrim($appUrl, '/') . '/#/pricing',
                        'availability' => 'https://schema.org/InStock',
                    ],
                ];
            }
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => $appName . ' Pricing',
            'itemListElement' => $itemListElement,
        ];
    }
}
