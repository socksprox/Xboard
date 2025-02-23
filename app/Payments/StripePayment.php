<?php

namespace App\Payments;

use App\Contracts\PaymentInterface;

// Make sure you have installed Stripe's PHP library:
// composer require stripe/stripe-php

class StripePayment implements PaymentInterface
{
    protected $config;

    /**
     * StripePayment constructor.
     *
     * @param array $config
     */
    public function __construct($config)
    {
        $this->config = $config;
    }

    /**
     * The form() method returns a configuration form.
     * Adjust fields (labels/descriptions) to your needs.
     *
     * @return array
     */
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

    /**
     * pay() should initiate a payment request.
     *
     * @param array $order [
     *   'trade_no' => string Unique order number in your system,
     *   'total_amount' => int   Amount in *cents*,
     *   'notify_url' => string  Webhook URL Stripe can send events to,
     *   'return_url' => string  URL to which the user will be redirected
     * ]
     * @return array [
     *   'type' => int, // 0 for QR code, 1 for redirect URL
     *   'data' => string // the session url or payment link
     * ]
     */
    public function pay($order): array
    {
        // Convert total_amount (cents) to the currency you use. 
        // For example, if you're using 'usd' with cents:
        $amount = $order['total_amount']; // in cents

        // Set your Stripe Secret Key
        \Stripe\Stripe::setApiKey($this->config['secret_key']);

        // Create a Stripe Checkout Session
        $session = \Stripe\Checkout\Session::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => [
                        // A name for the line item. Could be your product name or "Order #xxx"
                        'name' => 'Order ' . $order['trade_no'],
                    ],
                    'unit_amount' => $amount,
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            // These URLs are crucial for Stripe redirect flow
            'success_url' => $order['return_url'] . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'  => $order['return_url'] . '?cancel=1',
            // The metadata can help identify the order when you handle the webhook
            'metadata' => [
                'order_id' => $order['trade_no']
            ],
        ]);

        // Return the session URL so the user can be redirected to Stripe Checkout
        return [
            'type' => 1, // indicates redirect URL
            'data' => $session->url
        ];
    }

    /**
     * notify() should handle the webhook/notification from Stripe.
     * Verify the signature, decode the payload, and check event type.
     *
     * @param array $params  In many frameworks, you’ll grab the raw body, headers,
     *                       and pass them or parse them separately.
     * @return array|bool [
     *   'trade_no' => string,
     *   'callback_no' => string
     * ]  or false if invalid
     */
    public function notify($params): array|bool
    {
        // Typically you’d get the payload and the signature from:
        //   $payload = file_get_contents('php://input');
        //   $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
        //
        // However, because this method signature just has $params, 
        // you might store them in $params['payload'] and $params['signature_header'] 
        // from your controller or router logic.

        // Example:
        if (!isset($params['payload']) || !isset($params['signature_header'])) {
            return false;
        }

        $payload   = $params['payload'];
        $sigHeader = $params['signature_header'];

        // Validate the Stripe webhook signature
        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $sigHeader,
                $this->config['webhook_secret']
            );
        } catch (\Exception $e) {
            // Invalid signature
            return false;
        }

        // Check the event type and handle accordingly
        // Common events: checkout.session.completed, payment_intent.succeeded
        if ($event->type === 'checkout.session.completed') {
            /** @var \Stripe\Checkout\Session $session */
            $session = $event->data->object;

            // Retrieve the order_id from the metadata
            $orderId = $session->metadata->order_id ?? null;
            if (!$orderId) {
                return false;
            }

            // Return data in the format XBoard/v2board expects
            // 'trade_no' => your internal order number
            // 'callback_no' => a unique ID from the payment gateway (e.g., Stripe session id)
            return [
                'trade_no' => $orderId,
                'callback_no' => $session->id,
            ];
        }

        // You can handle other events here if needed
        return false;
    }
}
