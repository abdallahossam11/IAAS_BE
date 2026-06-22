<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Your Galala IAAS Password Reset Code</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <h2>Galala IAAS — Password Reset</h2>

    <p>We received a request to reset your password. Your one-time reset code is:</p>

    <p style="font-size: 32px; font-weight: bold; letter-spacing: 8px; color: #1a56db;">
        {{ $otpCode }}
    </p>

    <p>This code expires in <strong>10 minutes</strong>. Do not share it with anyone.</p>

    <p>If you did not request a password reset, you can safely ignore this email — your password will not change.</p>

    <p style="color: #666; font-size: 12px;">This is an automated message from Galala IAAS. Please do not reply.</p>
</body>
</html>
