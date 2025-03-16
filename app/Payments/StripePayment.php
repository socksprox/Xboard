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
                'type' => 'input',
            ],
            'secret_key' => [
                'label' => 'Stripe Secret Key',
                'type' => 'input',
            ],
            'webhook_secret' => [
                'label' => 'Webhook Secret',
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
            'client_reference_id' => $order['trade_no'],
        ]);

        return [
            'type' => 1, // Redirect
            'data' => $session->url
        ];
    }

    public function notify($params): array|bool
    {
        // EPay-style raw input handling
        $payload = file_get_contents('php://input');
        $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $sigHeader,
                $this->config['webhook_secret']
            );
        } catch (\Exception $e) {
            error_log('Stripe webhook error: ' . $e->getMessage());
            return false;
        }

        // Mirror EPay's success handling logic
        if ($event->type === 'checkout.session.completed') {
            $session = $event->data->object;

            if ($session->payment_status !== 'paid') {
                return false;
            }

            return [
                'trade_no' => $session->client_reference_id,
                'callback_no' => $session->payment_intent
            ];
        }

        return false;
    }
}
