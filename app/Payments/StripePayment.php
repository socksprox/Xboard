<?php

namespace App\Payments;

use App\Contracts\PaymentInterface;
use Stripe\Stripe;

class StripePayment implements PaymentInterface
{
    protected $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function form(): array
    {
        return [
            'publishable_key' => [
                'label' => 'Stripe Publishable Key',
                'description' => 'Your Stripe *Publishable* API Key',
                'type' => 'input',
            ],
            'secret_key' => [
                'label' => 'Stripe Secret Key',
                'description' => 'Your Stripe *Secret* API Key',
                'type' => 'input',
            ],
            'webhook_secret' => [
                'label' => 'Stripe Webhook Secret',
                'description' => 'Secret to verify Stripe webhook signatures',
                'type' => 'input',
            ]
        ];
    }

    public function pay($order): array
    {
        Stripe::setApiKey($this->config['secret_key']);

        $session = \Stripe\Checkout\Session::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => ['name' => 'Order ' . $order['trade_no']],
                    'unit_amount' => $order['total_amount'],
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => $order['return_url'] . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $order['return_url'] . '?cancel=1',
            'metadata' => ['order_id' => $order['trade_no']],
            'client_reference_id' => $order['trade_no'], // Added for compatibility
        ]);

        return [
            'type' => 1,
            'data' => $session->url
        ];
    }

    public function notify($params): array|bool
    {
        if (!isset($params['payload'], $params['signature_header'])) {
            return false;
        }

        try {
            $event = \Stripe\Webhook::constructEvent(
                $params['payload'],
                $params['signature_header'],
                $this->config['webhook_secret']
            );
        } catch (\Exception $e) {
            return false;
        }

        // Handle both immediate and async successful payments
        if (in_array($event->type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'])) {
            $session = $event->data->object;

            // Verify payment was actually successful
            if ($session->payment_status !== 'paid') {
                return false;
            }

            // Get order ID from metadata or client reference
            $orderId = $session->metadata->order_id ?? $session->client_reference_id ?? null;
            if (!$orderId) {
                return false;
            }

            return [
                'trade_no' => $orderId,
                'callback_no' => $session->payment_intent // Use payment intent ID
            ];
        }

        return false;
    }
}
