<?php

namespace App\Payments;

use App\Contracts\PaymentInterface;
use Stripe\Stripe;
use Stripe\Checkout\Session;
use Stripe\Webhook;
use Exception;

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

        $session = Session::create([
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
            'payment_intent_data' => [
                'metadata' => ['order_id' => $order['trade_no']] // Critical for payment_intent events
            ],
        ]);

        return [
            'type' => 1,
            'data' => $session->url
        ];
    }

    public function notify($params): array|bool
    {
        $payload = file_get_contents('php://input');
        $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

        if (empty($payload) || empty($sigHeader)) {
            error_log("Stripe webhook error: Missing payload or signature header.");
            return false;
        }

        try {
            $event = Webhook::constructEvent(
                $payload,
                $sigHeader,
                $this->config['webhook_secret']
            );
        } catch (\UnexpectedValueException $e) {
            error_log("Stripe webhook error (invalid payload): " . $e->getMessage());
            return false;
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            error_log("Stripe webhook error (invalid signature): " . $e->getMessage());
            return false;
        }

        // Handle both session and payment intent events
        if ($event->type === 'checkout.session.completed' || $event->type === 'payment_intent.succeeded') {
            $session = $event->data->object;

            // Extract order_id from session or payment intent metadata
            $orderId = $session->metadata->order_id ?? $session->payment_intent->metadata->order_id ?? null;

            if (!$orderId) {
                error_log("Stripe webhook error: Missing order_id in metadata.");
                return false;
            }

            return [
                'trade_no' => $orderId,
                'callback_no' => $session->payment_intent ?? $session->id
            ];
        }

        return false;
    }
}
