<?php

namespace App\Service;

use Illuminate\Support\Facades\Mail;

/**
 * Applies the SMTP settings stored in the "system-setting" cache to the
 * Laravel 9+ mail configuration and purges the resolved mailer.
 */
class MailConfigService
{
    public static function apply(array $sysConfig): void
    {
        $encryption = $sysConfig['encryption'] ?? 'ssl';
        $scheme = $encryption === 'ssl' ? 'smtps' : 'smtp';

        config([
            'mail.default' => $sysConfig['driver'] ?? 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.scheme' => $scheme,
            'mail.mailers.smtp.host' => $sysConfig['host'] ?? '',
            'mail.mailers.smtp.port' => (int) ($sysConfig['port'] ?? 465),
            'mail.mailers.smtp.username' => $sysConfig['username'] ?? '',
            'mail.mailers.smtp.password' => $sysConfig['password'] ?? '',
            'mail.from.address' => $sysConfig['from_address'] ?? '',
            'mail.from.name' => $sysConfig['from_name'] ?? '独角发卡',
        ]);

        Mail::purge('smtp');
    }
}
