<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shadowfly Security Alert</title>
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
        <div class="header">Shadowfly Security Alert</div>
        <div class="content">
            <p>Dear {{$name}},</p>
            <p>We noticed a login attempt to your Shadowfly account.</p>
            <p><strong>IP Address:</strong> {{$ip}}</p>
            <p><strong>Location:</strong> {{$location}}</p>
            <p><strong>Time:</strong> {{$time}}</p>
            <p>If this was you, no further action is needed. If you do not recognize this login, please secure your account immediately.</p>
            <a href="{{$link}}" class="button">Secure Your Account</a>
        </div>
        <div class="footer">
            Need help? Contact our support team at <a href="mailto:support@shadowfly.com" style="color: #00bcd4;">support@shadowfly.com</a>
        </div>
    </div>
</body>
</html>