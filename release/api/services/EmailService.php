<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../controllers/SettingsController.php'; // For decryption helper if needed, or reproduce decryption logic

class EmailService
{
    private $db;
    private $settingsController;

    public function __construct($db)
    {
        $this->db = $db;
        $this->settingsController = new SettingsController($db);
    }

    public function send($to, $subject, $body, $isHtml = true)
    {
        $mail = new PHPMailer(true);

        try {
            // Server settings
            $mail->isSMTP();

            // Fetch settings
            $host = $this->settingsController->getSetting('smtp_host');
            $user = $this->settingsController->getSetting('smtp_user');
            $pass = $this->settingsController->getSetting('smtp_pass');
            $port = $this->settingsController->getSetting('smtp_port') ?? 587;
            $secure = $this->settingsController->getSetting('smtp_secure') ?? PHPMailer::ENCRYPTION_STARTTLS;
            $fromName = $this->settingsController->getSetting('site_name') ?? 'Zenith Wellness';
            $fromEmail = $this->settingsController->getSetting('smtp_from_email') ?? $user;

            if (empty($host) || empty($user) || empty($pass)) {
                throw new Exception("SMTP Settings not configured");
            }

            $mail->Host = $host;
            $mail->SMTPAuth = true;
            $mail->Username = $user;
            $mail->Password = $pass;
            $mail->SMTPSecure = $secure; // tls or ssl
            $mail->Port = $port;

            // Recipients
            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($to);

            // Content
            $mail->isHTML($isHtml);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->AltBody = strip_tags($body);

            $mail->send();
            $this->logEmail($to, $subject, 'sent');
            return ['status' => 'success'];
        } catch (Exception $e) {
            $error = "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
            $this->logEmail($to, $subject, 'failed', $mail->ErrorInfo);
            return ['status' => 'error', 'message' => $error];
        }
    }

    public function sendTemplate($to, $templateName, $data)
    {
        $templatePath = __DIR__ . "/../templates/emails/$templateName.html";
        if (!file_exists($templatePath)) {
            // Fallback to basic text if template missing
            return $this->send($to, "Notification", "Template $templateName not found.");
        }

        $content = file_get_contents($templatePath);

        // Replace variables {{ key }}
        foreach ($data as $key => $value) {
            $content = str_replace("{{ $key }}", $value, $content);
            $content = str_replace("{{" . $key . "}}", $value, $content); // handle no spaces
        }

        // Basic Subject mapping based on template
        $subject = "Notification from Zenith Wellness";
        if ($templateName === 'welcome')
            $subject = "Welcome to Zenith Wellness!";
        if ($templateName === 'reset_password')
            $subject = "Password Reset Request";
        if ($templateName === 'receipt')
            $subject = "Payment Receipt";
        if ($templateName === 'cohort_access')
            $subject = "Your Cohort Access Details";
        if ($templateName === 'expiry_reminder')
            $subject = "⏰ Your Access Expires Soon";
        if ($templateName === 'cohort_completion')
            $subject = "🎉 Congratulations! You've Completed Your Cohort";

        return $this->send($to, $subject, $content, true);
    }

    private function logEmail($to, $subject, $status, $error = null)
    {
        // Need email_logs table? 
        // Let's create it dynamically if we want, or just check if it exists.
        // Assuming database migration handles it or we do check here.
        // For performance, better to have it in setup_db.php.
        // I will add a method to verify table existence or just try insert.

        try {
            $stmt = $this->db->prepare("INSERT INTO email_logs (recipient, subject, status, error_message, sent_at) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)");
            $stmt->execute([$to, $subject, $status, $error]);
        } catch (Exception $e) {
            // Ignore logging errors to not break flow, or log to file
            error_log("Failed to log email: " . $e->getMessage());
        }
    }
}
