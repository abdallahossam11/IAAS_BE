<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Your Galala IAAS Admin Account</title>
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
    <h2>Welcome to Galala IAAS Admin Panel</h2>

    <p>Hello {{ $admin->name }},</p>

    <p>An admin account has been created for you. Below are your login credentials:</p>

    <table style="border-collapse: collapse; margin: 16px 0;">
        <tr>
            <td style="padding: 6px 12px; font-weight: bold;">Email:</td>
            <td style="padding: 6px 12px;">{{ $admin->email }}</td>
        </tr>
        <tr>
            <td style="padding: 6px 12px; font-weight: bold;">Role:</td>
            <td style="padding: 6px 12px;">{{ $admin->role }}</td>
        </tr>
        <tr>
            <td style="padding: 6px 12px; font-weight: bold;">Initial Password:</td>
            <td style="padding: 6px 12px;">{{ $plainPassword }}</td>
        </tr>
    </table>

    <p><a href="{{ $loginUrl }}" style="color: #1a56db;">Log in to Admin Panel</a></p>

    <hr style="margin: 24px 0; border: none; border-top: 1px solid #ddd;">

    <p style="color: #c0392b; font-weight: bold;">Security Notice:</p>
    <ul>
        <li>Do <strong>not</strong> share this password with anyone.</li>
        <li>Change your password immediately after first login.</li>
        <li>If you did not request this account, please contact support immediately.</li>
    </ul>

    <p style="color: #666; font-size: 12px;">This is an automated message from Galala IAAS. Please do not reply.</p>
</body>
</html>
