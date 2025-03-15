<?php

namespace App\Payments;

use App\Contracts\PaymentInterface;
use Stripe\Stripe;
use Stripe\Checkout\Session;
use Stripe\Webhook;
use Stripe\PaymentIntent;

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

        $session = Session::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => [
                        'name' => 'Order ' . $order['trade_no'],
                    ],
                    'unit_amount' => $order['total_amount'],
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => $order['return_url'] . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $order['return_url'] . '?cancel=1',
            'metadata' => [
                'order_id' => $order['trade_no']
            ],
            'payment_intent_data' => [
                'metadata' => [
                    'order_id' => $order['trade_no']
                ]
            ],
        ]);

        return [
            'type' => 1,
            'data' => $session->url
        ];
    }

    public function notify($params): array|bool
    {
        // Retrieve the raw payload and signature from the request
        $payload = file_get_contents('php://input');
        $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

        if (empty($payload) || empty($sigHeader)) {
            error_log("Stripe Webhook Error: Missing payload or signature");
            return false;
        }

        try {
            $event = Webhook::constructEvent(
                $payload,
                $sigHeader,
                $this->config['webhook_secret']
            );
        } catch (\Exception $e) {
            error_log("Stripe Webhook Error: " . $e->getMessage());
            return false;
        }

        if ($event->type === 'checkout.session.completed' || $event->type === 'payment_intent.succeeded') {
            $session = $event->data->object;
            $paymentIntentId = $session->payment_intent ?? null;
            $orderId = $session->metadata->order_id ?? null;

            if (!$orderId && $paymentIntentId) {
                try {
                    $paymentIntent = PaymentIntent::retrieve($paymentIntentId);
                    $orderId = $paymentIntent->metadata->order_id ?? null;
                } catch (\Exception $e) {
                    error_log("Stripe PaymentIntent Retrieval Error: " . $e->getMessage());
                    return false;
                }
            }

            if (!$orderId) {
                error_log("Stripe Webhook Error: Order ID not found");
                return false;
            }

            return [
                'trade_no' => $orderId,
                'callback_no' => $paymentIntentId ?: $session->id
            ];
        }

        error_log("Stripe Webhook Error: Unhandled event type " . $event->type);
        return false;
    }
}
