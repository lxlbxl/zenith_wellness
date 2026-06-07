<?php
/**
 * EmailService — SMTP email delivery for password resets, welcome emails, etc.
 * Phase 1.2 / 4.1: Email Infrastructure
 */

class EmailService
{
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $fromEmail;
    private string $fromName;
    private string $secure;
    private string $appUrl;

    public function __construct()
    {
        $this->host = defined('SMTP_HOST') ? SMTP_HOST : getenv('SMTP_HOST') ?: 'smtp.gmail.com';
        $this->port = (int) (defined('SMTP_PORT') ? SMTP_PORT : getenv('SMTP_PORT') ?: 587);
        $this->username = defined('SMTP_USER') ? SMTP_USER : getenv('SMTP_USER') ?: '';
        $this->password = defined('SMTP_PASS') ? SMTP_PASS : getenv('SMTP_PASS') ?: '';
        $this->fromEmail = defined('SMTP_FROM_EMAIL') ? SMTP_FROM_EMAIL : getenv('SMTP_FROM_EMAIL') ?: 'noreply@zenithwellness.com';
        $this->fromName = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : getenv('SMTP_FROM_NAME') ?: 'Zenith Wellness';
        $this->secure = defined('SMTP_SECURE') ? SMTP_SECURE : getenv('SMTP_SECURE') ?: 'tls';
        $this->appUrl = defined('APP_URL') ? APP_URL : getenv('APP_URL') ?: 'http://localhost:5173';
    }

    /**
     * Send a password reset email.
     */
    public function sendPasswordReset(string $to, string $encodedToken): bool
    {
        $resetUrl = $this->appUrl . '/reset-password?token=' . urlencode($encodedToken);

        $subject = 'Reset Your Zenith Wellness Password';
        $body = $this->buildEmailTemplate('Password Reset Request', '
            <p>You requested a password reset for your Zenith Wellness account.</p>
            <p style="text-align: center; margin: 30px 0;">
                <a href="' . htmlspecialchars($resetUrl) . '" 
                   style="background: #4CAF50; color: white; padding: 14px 32px; text-decoration: none; 
                          border-radius: 8px; font-size: 16px; font-weight: 600;">
                    Reset Password
                </a>
            </p>
            <p>This link expires in <strong>1 hour</strong>.</p>
            <p>If you did not request this reset, please ignore this email.</p>
        ');

        return $this->send($to, $subject, $body);
    }

    /**
     * Send welcome email after registration.
     */
    public function sendWelcome(string $to, string $name): bool
    {
        $loginUrl = $this->appUrl . '/login';
        $cohortUrl = $this->appUrl . '/cohorts';

        $subject = 'Welcome to Zenith Wellness! 🌿';
        $body = $this->buildEmailTemplate('Welcome, ' . htmlspecialchars($name) . '!', '
            <p>Your journey to optimal wellness begins now.</p>
            <p>Here\'s what you can do today:</p>
            <ul>
                <li>🧘 Explore our wellness cohorts and programs</li>
                <li>📊 Track your daily habits, meals, and mood</li>
                <li>🏆 Earn points and unlock achievements</li>
                <li>💬 Chat with your AI Wellness Coach</li>
            </ul>
            <p style="text-align: center; margin: 30px 0;">
                <a href="' . htmlspecialchars($cohortUrl) . '" 
                   style="background: #4CAF50; color: white; padding: 14px 32px; text-decoration: none; 
                          border-radius: 8px; font-size: 16px; font-weight: 600;">
                    Explore Cohorts
                </a>
            </p>
        ');

        return $this->send($to, $subject, $body);
    }

    /**
     * Send payment confirmation.
     */
    public function sendPaymentConfirmation(string $to, array $paymentData): bool
    {
        $subject = 'Payment Confirmed - Zenith Wellness';
        $body = $this->buildEmailTemplate('Payment Confirmed ✅', '
            <p>Your payment of <strong>' . htmlspecialchars($paymentData['amount'] ?? '') . ' ' .
            htmlspecialchars($paymentData['currency'] ?? 'USD') . '</strong> was successful.</p>
            <p>Reference: ' . htmlspecialchars($paymentData['reference'] ?? '') . '</p>
            <p>Thank you for your subscription!</p>
        ');

        return $this->send($to, $subject, $body);
    }

    /**
     * Send cohort enrollment confirmation.
     */
    public function sendCohortEnrollment(string $to, string $cohortName, string $startDate): bool
    {
        $subject = 'You\'re Enrolled: ' . htmlspecialchars($cohortName) . ' 🎉';

        $body = $this->buildEmailTemplate('Enrollment Confirmed', '
            <p>You are now enrolled in <strong>' . htmlspecialchars($cohortName) . '</strong>.</p>
            <p>Start Date: <strong>' . htmlspecialchars($startDate) . '</strong></p>
            <p>We\'re excited to have you on this journey!</p>
        ');

        return $this->send($to, $subject, $body);
    }

    /**
     * Send a referral invitation email.
     */
    public function sendReferralInvite(string $to, string $referrerName, string $referralCode): bool
    {
        $signupUrl = $this->appUrl . '/register?ref=' . urlencode($referralCode);

        $subject = htmlspecialchars($referrerName) . ' invites you to Zenith Wellness!';
        $body = $this->buildEmailTemplate('You\'ve Been Invited! 🎁', '
            <p>' . htmlspecialchars($referrerName) . ' thinks you\'d love Zenith Wellness.</p>
            <p>Use referral code: <strong>' . htmlspecialchars($referralCode) . '</strong></p>
            <p style="text-align: center; margin: 30px 0;">
                <a href="' . htmlspecialchars($signupUrl) . '" 
                   style="background: #4CAF50; color: white; padding: 14px 32px; text-decoration: none; 
                          border-radius: 8px; font-size: 16px; font-weight: 600;">
                    Join Now
                </a>
            </p>
        ');

        return $this->send($to, $subject, $body);
    }

    /**
     * Generic send method using PHP mail() with SMTP headers.
     */
    private function send(string $to, string $subject, string $htmlBody): bool
    {
        // If SMTP credentials are available, attempt SMTP
        if (!empty($this->username) && !empty($this->password)) {
            return $this->sendViaSMTP($to, $subject, $htmlBody);
        }

        // Fallback to PHP mail() for local development
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $this->fromName . ' <' . $this->fromEmail . '>',
            'Reply-To: ' . $this->fromEmail,
            'X-Mailer: PHP/' . phpversion(),
        ];

        $sent = mail($to, $subject, $htmlBody, implode("\r\n", $headers));

        // Log to DB
        $this->logEmail($to, $subject, $sent ? 'sent' : 'failed');

        return $sent;
    }

    /**
     * Send via SMTP using fsockopen (no external libraries required).
     */
    private function sendViaSMTP(string $to, string $subject, string $htmlBody): bool
    {
        try {
            $socket = fsockopen(
                ($this->secure === 'ssl' ? 'ssl://' : '') . $this->host,
                $this->port,
                $errno,
                $errstr,
                30
            );

            if (!$socket) {
                error_log("[EmailService] Connection failed: $errstr ($errno)");
                $this->logEmail($to, $subject, 'failed');
                return false;
            }

            $this->smtpCommand($socket, null); // Read greeting

            // EHLO
            $this->smtpCommand($socket, "EHLO " . gethostname());

            // STARTTLS if using TLS
            if ($this->secure === 'tls') {
                $this->smtpCommand($socket, "STARTTLS");
                stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                $this->smtpCommand($socket, "EHLO " . gethostname());
            }

            // Auth Login
            $this->smtpCommand($socket, "AUTH LOGIN");
            $this->smtpCommand($socket, base64_encode($this->username));
            $this->smtpCommand($socket, base64_encode($this->password));

            // Mail From
            $this->smtpCommand($socket, "MAIL FROM:<" . $this->fromEmail . ">");

            // RCPT TO
            $this->smtpCommand($socket, "RCPT TO:<" . $to . ">");

            // DATA
            $this->smtpCommand($socket, "DATA");

            // Message headers + body
            $message = "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
            $message .= "To: <" . $to . ">\r\n";
            $message .= "From: " . $this->fromName . " <" . $this->fromEmail . ">\r\n";
            $message .= "MIME-Version: 1.0\r\n";
            $message .= "Content-Type: text/html; charset=UTF-8\r\n";
            $message .= "\r\n";
            $message .= $htmlBody;
            $message .= "\r\n.\r\n";

            fwrite($socket, $message);
            $response = fread($socket, 512);

            // QUIT
            $this->smtpCommand($socket, "QUIT");
            fclose($socket);

            $sent = strpos($response, '250') === 0;
            $this->logEmail($to, $subject, $sent ? 'sent' : 'failed');
            return $sent;
        } catch (\Exception $e) {
            error_log("[EmailService] SMTP error: " . $e->getMessage());
            $this->logEmail($to, $subject, 'failed');
            return false;
        }
    }

    private function smtpCommand($socket, ?string $command): string
    {
        if ($command !== null) {
            fwrite($socket, $command . "\r\n");
        }
        $response = fread($socket, 512);
        return $response;
    }

    /**
     * Build a responsive HTML email template.
     */
    private function buildEmailTemplate(string $title, string $content): string
    {
        $appName = 'Zenith Wellness';
        $primaryColor = '#4CAF50';
        $bgColor = '#f7f8fa';
        $cardBg = '#ffffff';
        $textColor = '#333333';
        $footerColor = '#999999';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; background-color: {$bgColor}; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 600px; margin: 0 auto;">
        <!-- Header -->
        <tr>
            <td style="padding: 30px 20px; text-align: center; background: linear-gradient(135deg, {$primaryColor}, #45a049);">
                <h1 style="color: white; margin: 0; font-size: 24px;">🌿 {$appName}</h1>
            </td>
        </tr>
        <!-- Body -->
        <tr>
            <td style="background: {$cardBg}; padding: 40px 30px; border-radius: 0 0 12px 12px;">
                <h2 style="color: {$textColor}; margin-top: 0;">{$title}</h2>
                {$content}
            </td>
        </tr>
        <!-- Footer -->
        <tr>
            <td style="padding: 20px; text-align: center; color: {$footerColor}; font-size: 13px;">
                <p>{$appName} — Your journey to optimal wellness</p>
                <p>© " . date('Y') . " {$appName}. All rights reserved.</p>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
    }

    /**
     * Log email to database for tracking/audit.
     */
    private function logEmail(string $to, string $subject, string $status): void
    {
        try {
            $db = (new \Database())->getConnection();
            if ($db) {
                $stmt = $db->prepare(
                    "INSERT INTO email_logs (id, recipient, subject, status, created_at) 
                     VALUES (?, ?, ?, ?, NOW())"
                );
                $id = bin2hex(random_bytes(16));
                $stmt->execute([$id, $to, $subject, $status]);
            }
        } catch (\Exception $e) {
            error_log("[EmailService] Failed to log email: " . $e->getMessage());
        }
    }
}