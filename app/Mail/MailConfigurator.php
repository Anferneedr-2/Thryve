<?php

namespace App\Mail;

use App\Support\Settings;
use Illuminate\Support\Facades\Mail;

/** Applies the Super Admin's SMTP settings to Laravel's mailer at send time. */
final class MailConfigurator
{
    public static function enabled(): bool
    {
        return (bool) Settings::get('smtp_enabled') && filled(Settings::get('smtp_host')) && filled(Settings::get('smtp_from_address'));
    }

    /** Point the 'smtp' mailer at the saved settings. Nothing here is logged or returned. */
    public static function apply(): void
    {
        config([
            'mail.mailers.smtp.host' => Settings::get('smtp_host'),
            'mail.mailers.smtp.port' => (int) Settings::get('smtp_port'),
            'mail.mailers.smtp.encryption' => Settings::get('smtp_encryption') === 'none' ? null : Settings::get('smtp_encryption'),
            'mail.mailers.smtp.scheme' => Settings::get('smtp_encryption') === 'ssl' ? 'smtps' : 'smtp',
            'mail.mailers.smtp.username' => Settings::get('smtp_username'),
            'mail.mailers.smtp.password' => Settings::get('smtp_password'),
            'mail.mailers.smtp.timeout' => 10,
            'mail.from.address' => Settings::get('smtp_from_address'),
            'mail.from.name' => Settings::get('smtp_from_name') ?: Settings::get('company_name'),
        ]);
        Mail::purge('smtp'); // drop any transport built with older settings
    }

    /** Turn a mail exception into a short, safe category. Raw messages can contain hosts or credentials. */
    public static function category(\Throwable $e): string
    {
        $m = strtolower($e->getMessage());

        return match (true) {
            str_contains($m, 'authenticat') || str_contains($m, 'username and password') || str_contains($m, ' 535') => 'auth_failed',
            str_contains($m, 'connection') || str_contains($m, 'could not') || str_contains($m, 'timed out') || str_contains($m, 'refused') || str_contains($m, 'unable to connect') || str_contains($m, 'getaddrinfo') => 'connection_failed',
            str_contains($m, 'ssl') || str_contains($m, 'tls') || str_contains($m, 'certificate') => 'tls_failed',
            default => 'send_failed',
        };
    }

    public static function describe(string $category): string
    {
        return [
            'auth_failed' => 'The mail server did not accept the username or password.',
            'connection_failed' => 'Could not connect to the mail server. Check the server name and port.',
            'tls_failed' => 'A secure connection could not be set up. Check the encryption setting and port.',
            'send_failed' => 'The mail server did not accept the message.',
        ][$category] ?? 'The email could not be sent.';
    }
}
