<?php declare(strict_types=1);

namespace App;

/**
 * Envío de correo vía Mailgun (API HTTP, sin dependencias: usa curl como
 * BettermodeClient). Credenciales por env: MAILGUN_API_KEY, MAILGUN_DOMAIN,
 * MAILGUN_FROM. Pensado para el reset de contraseña del Portal de Success (rw2).
 */
final class Mailer
{
    public static function sendPasswordReset(string $toEmail, string $toName, string $resetUrl): bool
    {
        $apiKey = (string) Config::get('MAILGUN_API_KEY', '');
        $domain = (string) Config::get('MAILGUN_DOMAIN', '');
        $from   = (string) Config::get('MAILGUN_FROM', 'info@5t4d10.com');

        if ($apiKey === '' || $domain === '') {
            error_log('[mailer] MAILGUN_API_KEY o MAILGUN_DOMAIN no configurados');
            return false;
        }

        $name = $toName !== '' ? $toName : $toEmail;
        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safeUrl  = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');

        $text = implode("\n", [
            "Hola {$name},",
            "",
            "Recibimos una solicitud para restablecer tu contraseña del Portal 5T4D10.",
            "",
            "Abre el siguiente link (válido por 1 hora):",
            $resetUrl,
            "",
            "Si no solicitaste esto, ignora este mensaje.",
            "",
            "— 5T4D10",
        ]);
        $html = "
            <div style='font-family:sans-serif;max-width:480px;margin:0 auto;'>
              <p>Hola <strong>{$safeName}</strong>,</p>
              <p>Recibimos una solicitud para restablecer tu contraseña del Portal 5T4D10.</p>
              <p style='margin:28px 0;'>
                <a href='{$safeUrl}' style='background:#FF6687;color:#fff;padding:12px 24px;
                   text-decoration:none;border-radius:6px;font-family:sans-serif;font-weight:bold;'>
                  Restablecer contraseña
                </a>
              </p>
              <p style='color:#999;font-size:12px;'>Link válido por 1 hora.<br>
                 Si no solicitaste esto, ignora este mensaje.</p>
            </div>";

        $post = http_build_query([
            'from'    => "Portal 5T4D10 <{$from}>",
            'to'      => "{$name} <{$toEmail}>",
            'subject' => 'Restablecer contraseña — Portal 5T4D10',
            'text'    => $text,
            'html'    => $html,
        ]);

        $ch = curl_init("https://api.mailgun.net/v3/{$domain}/messages");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => 'api:' . $apiKey,
            CURLOPT_POSTFIELDS     => $post,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $raw    = curl_exec($ch);
        $errNo  = curl_errno($ch);
        $errMsg = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errNo !== 0 || $status >= 300) {
            error_log('[mailer] fallo Mailgun status=' . $status . ' err=' . $errMsg . ' body=' . substr((string) $raw, 0, 200));
            return false;
        }
        return true;
    }
}
