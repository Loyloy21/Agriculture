<?php
declare(strict_types=1);

require_once __DIR__ . '/email_settings.php';

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

final class VerificationEmailException extends RuntimeException
{
    public function __construct(public readonly string $failureCode)
    {
        parent::__construct('Verification email delivery failed.');
    }
}

function build_verification_email(string $farmerName, string $decision, ?string $rejectionReason = null): array
{
    if (!in_array($decision, ['Approved', 'Rejected'], true)) {
        throw new InvalidArgumentException('Unsupported verification decision.');
    }

    $template = load_registration_email_template($decision);
    $replacements = [
        '{farmer_name}' => trim($farmerName) !== '' ? trim($farmerName) : 'Farmer',
        '{rejection_reason}' => trim((string) $rejectionReason),
    ];
    $subject = str_replace(["\r", "\n"], ' ', strtr((string) $template['subject'], $replacements));
    $text = strtr((string) $template['message_body'], $replacements);
    $safeMessage = nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    $heading = $decision === 'Approved' ? 'AgriReach registration approved' : 'AgriReach registration decision';
    $headingColor = $decision === 'Approved' ? '#2f8f5b' : '#8b3d3d';

    return [
        'subject' => $subject,
        'html' => '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#27301f;line-height:1.6">'
            . '<div style="max-width:600px;margin:auto;padding:24px;border:1px solid #dce5d6;border-radius:14px">'
            . '<h1 style="color:' . $headingColor . ';font-size:24px">' . $heading . '</h1>'
            . '<div>' . $safeMessage . '</div>'
            . '<p style="margin-top:24px;color:#718078;font-size:13px">For your security, AgriReach does not include passwords or valid ID information in decision emails.</p>'
            . '</div></body></html>',
        'text' => $text . "\n\nFor your security, AgriReach does not include passwords or valid ID information in decision emails.",
    ];
}

function verification_email_transport_name(): string
{
    return (string) registration_email_configuration()['transport'];
}

function registration_email_delivery_route(array $config, string $recipient, bool $applyDevelopmentRouting): array
{
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        throw new VerificationEmailException('invalid_recipient');
    }

    $deliveryRecipient = strtolower(trim($recipient));
    $routingMode = 'Live';
    if ($applyDevelopmentRouting && $config['app_environment'] !== 'production') {
        $routingMode = 'Development Test';
        if ($config['transport'] === 'smtp' && !filter_var($config['test_recipient'], FILTER_VALIDATE_EMAIL)) {
            throw new VerificationEmailException('test_recipient_required');
        }
        if (filter_var($config['test_recipient'], FILTER_VALIDATE_EMAIL)) {
            $deliveryRecipient = strtolower(trim((string) $config['test_recipient']));
        }
    }

    return [
        'intended_recipient' => strtolower(trim($recipient)),
        'delivery_recipient' => $deliveryRecipient,
        'routing_mode' => $routingMode,
    ];
}

function registration_verification_email_configuration(?string $developmentTestRecipient = null): array
{
    $configuration = registration_email_configuration();
    if ($configuration['app_environment'] !== 'production' && $developmentTestRecipient !== null) {
        $developmentTestRecipient = strtolower(trim($developmentTestRecipient));
        if (!filter_var($developmentTestRecipient, FILTER_VALIDATE_EMAIL)) {
            throw new VerificationEmailException('invalid_test_recipient');
        }
        $configuration['test_recipient'] = $developmentTestRecipient;
    }
    return $configuration;
}

function send_prepared_registration_email(array $config, string $recipient, string $recipientName, array $content, bool $applyDevelopmentRouting): string
{
    if (!in_array($config['transport'], ['smtp', 'log'], true)) {
        throw new VerificationEmailException('invalid_transport');
    }

    $autoload = agrireach_root() . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new VerificationEmailException('mailer_not_installed');
    }
    require_once $autoload;

    $route = registration_email_delivery_route($config, $recipient, $applyDevelopmentRouting);
    $deliveryAddress = $route['delivery_recipient'];

    try {
        $mailer = new PHPMailer(true);
        $mailer->CharSet = PHPMailer::CHARSET_UTF8;
        $mailer->setFrom($config['from_address'] ?: 'no-reply@agrireach.test', $config['from_name']);
        $mailer->addAddress($deliveryAddress, $recipientName);
        $mailer->Subject = $content['subject'];
        $mailer->isHTML(true);
        $mailer->Body = $content['html'];
        $mailer->AltBody = $content['text'];

        if ($config['transport'] === 'log') {
            if (!$mailer->preSend()) throw new VerificationEmailException('message_build_failed');
            return 'Simulated';
        }

        if ($config['host'] === '' || $config['port'] < 1 || !filter_var($config['from_address'], FILTER_VALIDATE_EMAIL)) {
            throw new VerificationEmailException('smtp_configuration_missing');
        }
        if ($config['authentication'] && ($config['username'] === '' || $config['password'] === '')) {
            throw new VerificationEmailException('smtp_credentials_missing');
        }
        if (!in_array($config['encryption'], ['tls', 'ssl', 'none'], true)) {
            throw new VerificationEmailException('smtp_encryption_invalid');
        }

        $mailer->isSMTP();
        $mailer->Host = $config['host'];
        $mailer->Port = $config['port'];
        $mailer->SMTPAuth = $config['authentication'];
        $mailer->Username = $config['username'];
        $mailer->Password = $config['password'];
        $mailer->Timeout = $config['timeout'];
        $mailer->SMTPAutoTLS = $config['encryption'] !== 'none';
        $mailer->SMTPSecure = match ($config['encryption']) {
            'tls' => PHPMailer::ENCRYPTION_STARTTLS,
            'ssl' => PHPMailer::ENCRYPTION_SMTPS,
            default => '',
        };
        $mailer->send();
        return 'Sent';
    } catch (VerificationEmailException $exception) {
        throw $exception;
    } catch (PHPMailerException $exception) {
        error_log('Verification email SMTP delivery was not accepted.');
        throw new VerificationEmailException('smtp_not_accepted');
    } catch (Throwable $exception) {
        error_log('Verification email delivery encountered an internal error of type ' . get_class($exception) . '.');
        throw new VerificationEmailException('delivery_error');
    }
}

function send_verification_email(
    string $registeredEmail,
    string $farmerName,
    string $decision,
    ?string $rejectionReason = null,
    ?string $developmentTestRecipient = null
): string
{
    $configuration = registration_verification_email_configuration($developmentTestRecipient);

    return send_prepared_registration_email(
        $configuration,
        $registeredEmail,
        $farmerName,
        build_verification_email($farmerName, $decision, $rejectionReason),
        true
    );
}

/**
 * Signs in to Gmail SMTP with new sender credentials without sending anything, so a sender is only replaced by one
 * that works. Throws VerificationEmailException('smtp_not_accepted') when Gmail refuses them.
 */
function verify_registration_email_sender_login(string $senderEmail, string $appPassword): void
{
    $autoload = agrireach_root() . '/vendor/autoload.php';
    if (!is_file($autoload)) throw new VerificationEmailException('mailer_not_installed');
    require_once $autoload;
    $mailer = new PHPMailer(true);
    try {
        $mailer->isSMTP();
        $mailer->Host = 'smtp.gmail.com';
        $mailer->Port = 587;
        $mailer->SMTPAuth = true;
        $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mailer->Username = $senderEmail;
        $mailer->Password = $appPassword;
        $mailer->Timeout = email_configuration()['timeout'];
        if (!$mailer->smtpConnect()) throw new VerificationEmailException('smtp_not_accepted');
        $mailer->smtpClose();
    } catch (VerificationEmailException $exception) {
        throw $exception;
    } catch (Throwable $exception) {
        error_log('Sender credential check was not accepted (' . get_class($exception) . ').');
        throw new VerificationEmailException('smtp_not_accepted');
    }
}

function send_registration_test_email(string $testRecipient, ?array $configuration = null): string
{
    $config = $configuration ?? registration_email_configuration();
    if ($config['transport'] !== 'smtp') {
        throw new VerificationEmailException('smtp_configuration_missing');
    }

    return send_prepared_registration_email(
        $config,
        trim($testRecipient),
        'AgriReach email test recipient',
        [
            'subject' => 'AgriReach registration email test',
            'html' => '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#27301f;line-height:1.6"><div style="max-width:600px;margin:auto;padding:24px;border:1px solid #dce5d6;border-radius:14px"><h1 style="color:#2f8f5b;font-size:24px">AgriReach email settings test</h1><p>The configured Gmail SMTP sender was accepted for this test message.</p><p style="color:#718078;font-size:13px">This confirms SMTP acceptance only. It does not prove inbox delivery.</p></div></body></html>',
            'text' => "AgriReach email settings test\n\nThe configured Gmail SMTP sender was accepted for this test message. This confirms SMTP acceptance only; it does not prove inbox delivery.",
        ],
        false
    );
}
