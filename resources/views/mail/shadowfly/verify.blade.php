<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shadowfly Email Verification</title>
    <style>
        body { font-family: Arial, sans-serif; background-color: #121212; color: #ffffff; margin: 0; padding: 20px; }
        .container { max-width: 600px; margin: auto; background: #1e1e1e; padding: 20px; border-radius: 10px; text-align: center; }
        .header { font-size: 24px; font-weight: bold; color: #00bcd4; }
        .content { margin-top: 20px; font-size: 16px; line-height: 1.5; }
        .code { font-size: 32px; font-weight: bold; color: #00bcd4; margin: 20px 0; }
        .footer { margin-top: 20px; font-size: 12px; color: #bbbbbb; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">Verify Your Email</div>
        <div class="content">
            <p>Dear {{$name}},</p>
            <p>Please use the following code to verify your email address:</p>
            <div class="code">{{$code}}</div>
            <p>This code is valid for 5 minutes. If you did not request this verification, please ignore this email.</p>
        </div>
        <div class="footer">
            Need help? Contact our support team at <a href="mailto:support@shadowfly.com" style="color: #00bcd4;">support@shadowfly.com</a>
        </div>
    </div>
</body>
</html>