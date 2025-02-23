<?php

namespace App\Payments;

use App\Contracts\PaymentInterface;

/**
 * StripePayment
 *
 * Implements a server-side Stripe Checkout Session and webhook verification
 * without requiring an external library.
 */
class StripePayment implements PaymentInterface
{
    protected $config;

    /**
     * Constructor
     *
     * @param array $config [
     *   'secret_key'    => 'sk_live_XXX',      // Your Stripe Secret Key
     *   'publishable_key' (optional) => 'pk_live_XXX', // May be used client-side if needed
     *   'webhook_secret' => 'whsec_XXX'        // Your Stripe Webhook Secret
     * ]
     */
    public function __construct($config)
    {
        $this->config = $config;
    }

    /**
     * Returns the configuration form fields
     * for display in your admin panel.
     */
    public function form(): array
    {
        return [
            'secret_key' => [
                'label'       => 'Secret Key',
                'description' => 'Your Stripe secret key (starts with sk_...)',
                'type'        => 'input',
            ],
            'publishable_key' => [
                'label'       => 'Publishable Key',
                'description' => 'Optional: Your Stripe publishable key (starts with pk_...)',
                'type'        => 'input',
            ],
            'webhook_secret' => [
                'label'       => 'Webhook Secret',
                'description' => 'For verifying webhook signatures (starts with whsec_...)',
                'type'        => 'input',
            ],
        ];
    }

    /**
     * Create a Stripe Checkout Session and return a
     * redirect URL for the user to pay.
     *
     * @param array $order [
     *   'trade_no'    => (string) internal order ID
     *   'total_amount'=> (int)    total amount in cents
     *   'notify_url'  => (string) webhook callback URL
     *   'return_url'  => (string) URL user will be sent to after successful payment
     *   ... additional fields if needed ...
     * ]
     *
     * @return array [
     *   'type' => 1,     // 1 => redirect URL
     *   'data' => 'https://checkout.stripe.com/pay/...' // The Stripe Checkout URL
     * ]
     */
    public function pay($order): array
    {
        // Prepare the request fields for Stripe Checkout Session
        $fields = [
            // For an array-based submission via cURL, we place repeated params with bracket syntax:
            'payment_method_types[0]'                   => 'card',
            'mode'                                      => 'payment',
            'success_url'                               => $order['return_url'], // Where to go after success
            'cancel_url'                                => $order['return_url'], // Or use a separate cancel_url if desired
            'line_items[0][quantity]'                   => 1,
            // We create a Price inline with 'price_data'
            'line_items[0][price_data][currency]'       => 'usd',
            // Stripe expects an integer for amount in cents
            'line_items[0][price_data][unit_amount]'    => $order['total_amount'],
            'line_items[0][price_data][product_data][name]' => 'Order #' . $order['trade_no'],
            // Attach metadata with your internal order ID
            'metadata[out_trade_no]'                    => $order['trade_no'],
        ];

        // Initialize cURL
        $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');

        // Convert $fields into an HTTP query string
        $postData = http_build_query($fields);

        // Basic cURL setup
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        // Provide Stripe API key via basic auth: "Authorization: Bearer {secret_key}"
        curl_setopt($ch, CURLOPT_USERPWD, $this->config['secret_key'] . ':');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

        // Execute
        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            // Handle cURL error as appropriate
            // Possibly throw exception or return an error response
            return [
                'type' => 1,
                'data' => 'Error creating Stripe Checkout Session: ' . $error
            ];
        }

        // Decode the JSON response
        $json = json_decode($response, true);

        if (empty($json['id']) || empty($json['url'])) {
            // Error from Stripe or missing data
            // Return the error message from Stripe if available
            $errMsg = isset($json['error']['message'])
                ? $json['error']['message']
                : 'Unknown Stripe error.';
            return [
                'type' => 1,
                'data' => 'Error creating Stripe Checkout Session: ' . $errMsg
            ];
        }

        // Return the session URL for redirection
        return [
            'type' => 1,            // 1 => a redirect URL
            'data' => $json['url'], // Provide the user with the session's checkout URL
        ];
    }

    /**
     * Handle Stripe's webhook notification.
     *
     * @param array $params The request context. Adjust if your framework passes differently.
     *                      You need:
     *                       - RAW POST body (JSON)
     *                       - 'headers' => [ 'Stripe-Signature' => '...' ]
     *
     * @return array|bool [
     *   'trade_no'    => (string) your order ID
     *   'callback_no' => (string) Stripe payment ID
     * ] or false if invalid signature or incomplete event.
     */
    public function notify($params): array|bool
    {
        // 1) Retrieve raw body & signature header
        $rawBody  = $params['raw_body'] ?? '';    // e.g. file_get_contents('php://input')
        $sigHeader= $params['headers']['Stripe-Signature'] ?? '';

        if (empty($rawBody) || empty($sigHeader)) {
            return false;
        }

        // 2) Verify the signature if you have a webhook_secret
        if (! empty($this->config['webhook_secret'])) {
            if (! $this->verifySignature($rawBody, $sigHeader, $this->config['webhook_secret'])) {
                return false; // Invalid signature
            }
        }

        // 3) Parse the event
        $event = json_decode($rawBody, true);
        if (empty($event['type']) || empty($event['data']['object'])) {
            return false;
        }

        // 4) Handle the event you care about
        //    "checkout.session.completed" indicates a successful payment
        if ($event['type'] === 'checkout.session.completed') {
            $session = $event['data']['object'];

            // Make sure it's paid
            // (Stripe docs: session.payment_status == 'paid' for a successful payment)
            if (isset($session['payment_status']) && $session['payment_status'] === 'paid') {
                // Retrieve your internal order ID from metadata
                $outTradeNo = $session['metadata']['out_trade_no'] ?? null;

                if (! $outTradeNo) {
                    return false; // cannot find original order
                }

                // callback_no => the Stripe Checkout Session id or the PaymentIntent
                $callbackNo = $session['payment_intent'] ?? $session['id'];

                return [
                    'trade_no'    => $outTradeNo,
                    'callback_no' => $callbackNo,
                ];
            }
        }

        // You may handle other event types if needed, or just ignore them
        // If not recognized or not completed => false
        return false;
    }

    /**
     * Verify Stripe webhook signature manually (no external library).
     *
     * @param string $payload      The raw JSON string from Stripe
     * @param string $sigHeader    The 'Stripe-Signature' header value
     * @param string $secret       Your endpoint's webhook signing secret
     * @param int    $toleranceSec Number of seconds the timestamp can differ (default 300)
     *
     * @return bool
     */
    protected function verifySignature(
        string $payload,
        string $sigHeader,
        string $secret,
        int $toleranceSec = 300
    ): bool {
        // Stripe sends something like:
        // t=162...,v1=...,v0=... in the signature header
        // We want the 't=' and the 'v1=' part
        $parts = [];
        foreach (explode(',', $sigHeader) as $item) {
            $kv = explode('=', $item, 2);
            if (count($kv) === 2) {
                $parts[$kv[0]] = $kv[1];
            }
        }

        if (empty($parts['t']) || empty($parts['v1'])) {
            return false;
        }

        $timestamp = (int) $parts['t'];
        $expectedSig = $parts['v1'];

        // Check timestamp tolerance
        if (abs(time() - $timestamp) > $toleranceSec) {
            return false;
        }

        // Compute our own signature
        $signedPayload = $timestamp . '.' . $payload;
        $signature = hash_hmac('sha256', $signedPayload, $secret);

        // Compare
        return hash_equals($signature, $expectedSig);
    }
}
