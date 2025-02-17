<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shadowfly Subscription Expiry Reminder</title>
    <style>
        body { font-family: Arial, sans-serif; background-color: #121212; color: #ffffff; margin: 0; padding: 20px; }
        .container { max-width: 600px; margin: auto; background: #1e1e1e; padding: 20px; border-radius: 10px; text-align: center; }
        .header { font-size: 24px; font-weight: bold; color: #00bcd4; }
        .content { margin-top: 20px; font-size: 16px; line-height: 1.5; }
        .button { display: inline-block; padding: 10px 20px; text-decoration: none; color: #ffffff; background-color: #00bcd4; border-radius: 5px; font-size: 18px; }
        .footer { margin-top: 20px; font-size: 12px; color: #bbbbbb; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">Subscription Expiry Reminder</div>
        <div class="content">
            <p>Dear {{$name}},</p>
            <p>Your subscription to Shadowfly will expire in <strong>{{$days_left}}</strong> days.</p>
            <p>To avoid service interruption, please renew your subscription before the expiry date.</p>
            <a href="{{$renew_url}}" class="button">Renew Now</a>
        </div>
        <div class="footer">
            Need help? Contact our support team at <a href="mailto:support@shadowfly.com" style="color: #00bcd4;">support@shadowfly.com</a>
        </div>
    </div>
</body>
</html>