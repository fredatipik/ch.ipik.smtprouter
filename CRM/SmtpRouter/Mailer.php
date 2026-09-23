<?php
/**
 * Helpers for building and testing SMTP connections using PEAR Mail / Net_SMTP.
 *
 * This CiviCRM installation ships PEAR's "Mail" and "Net_SMTP" packages
 * (visible in the runtime include_path: vendor/pear/mail, vendor/pear/net_smtp,
 * vendor/pear/mail_mime, vendor/pear/auth_sasl) — NOT PHPMailer. CiviCRM's own
 * core outbound-mail feature is built on the same PEAR stack, so we mirror
 * that here for maximum compatibility.
 *
 * TLS behaviour (Net_SMTP):
 *   - 'ssl' security  → host is prefixed with 'ssl://' (implicit TLS, port 465).
 *   - 'tls' security  → plain host (port 587); Net_SMTP::auth() negotiates
 *                       STARTTLS automatically by default when authenticating.
 *   - 'none' security → no encryption requested.
 */
class CRM_SmtpRouter_Mailer {

  /**
   * Ensure the PEAR Mail / Net_SMTP / PEAR classes are loaded.
   * They live in CiviCRM's vendor include_path, so a plain include_once
   * (no absolute path) is enough — same mechanism CiviCRM core itself uses.
   */
  private static function _loadPear(): bool {
    if (!class_exists('PEAR')) {
      @include_once 'PEAR.php';
    }
    if (!class_exists('Net_SMTP')) {
      @include_once 'Net/SMTP.php';
    }
    if (!class_exists('Mail')) {
      @include_once 'Mail.php';
    }
    return class_exists('Mail') && class_exists('Net_SMTP');
  }

  /**
   * Test an SMTP connection: connect + authenticate, then disconnect.
   * No email is sent.
   *
   * @param array $config  Row from civicrm_smtp_config (password decrypted).
   * @return array [bool $ok, string $message]
   */
  public static function testConnection(array $config): array {
    if (!self::_loadPear()) {
      // PEAR Mail not available for some reason — fall back to a bare TCP test.
      return self::_rawSocketTest($config);
    }

    $host   = $config['smtp_host'];
    $port   = (int) $config['smtp_port'];
    $useSsl = ($config['smtp_security'] === 'ssl');
    $useTls = ($config['smtp_security'] === 'tls');

    $smtp = new \Net_SMTP($useSsl ? 'ssl://' . $host : $host, $port, NULL, FALSE, 15);

    $connectResult = $smtp->connect(15);
    if (\PEAR::isError($connectResult)) {
      return [FALSE, "Connexion échouée vers $host:$port — " . $connectResult->getMessage()];
    }

    if (!empty($config['smtp_auth'])) {
      $authResult = $smtp->auth($config['smtp_username'] ?? '', $config['smtp_password'] ?? '', NULL, $useTls || $useSsl);
      if (\PEAR::isError($authResult)) {
        $smtp->disconnect();
        return [FALSE, "Connecté à $host:$port, mais authentification échouée — " . $authResult->getMessage()];
      }
    }

    $smtp->disconnect();
    return [TRUE, "Connexion et authentification réussies vers $host:$port."];
  }

  /**
   * Fallback: raw TCP test — verifies that the SMTP host is reachable and returns a banner.
   * Does NOT test authentication. Used only if PEAR Mail is unavailable.
   */
  private static function _rawSocketTest(array $config): array {
    $host    = $config['smtp_host'];
    $port    = (int) $config['smtp_port'];
    $timeout = 10;

    $prefix = ($config['smtp_security'] === 'ssl') ? 'ssl://' : '';
    $errno  = 0;
    $errstr = '';

    $socket = @fsockopen($prefix . $host, $port, $errno, $errstr, $timeout);
    if (!$socket) {
      return [FALSE, "Impossible de se connecter à $host:$port — $errstr ($errno). (PEAR Mail absent : test d'authentification impossible.)"];
    }
    $banner = fgets($socket, 512);
    fclose($socket);
    return [TRUE, "Connexion TCP OK vers $host:$port. Bannière : " . trim($banner) . ". (PEAR Mail absent : authentification non testée.)"];
  }


  /**
   * Build a PEAR Mail_smtp transport from a config row.
   *
   * @return \Mail|\PEAR_Error
   */
  public static function buildTransport(array $config) {
    $useSsl = ($config['smtp_security'] === 'ssl');
    return \Mail::factory('smtp', [
      'host'     => $useSsl ? 'ssl://' . $config['smtp_host'] : $config['smtp_host'],
      'port'     => (int) $config['smtp_port'],
      'auth'     => (bool) $config['smtp_auth'],
      'username' => $config['smtp_username'] ?? '',
      'password' => $config['smtp_password'] ?? '',
      'timeout'  => 15,
    ]);
  }

  /**
   * Mailer filter: send through the SMTP account matching the From address.
   *
   * Called by CiviCRM with the message already assembled, so the body passed
   * on is byte-for-byte the one CiviCRM built — multipart, attachments and all.
   *
   * @param \Mail  $mailer      CiviCRM's mailer (unused; we substitute our own).
   * @param mixed  $recipients  Envelope recipients (To + Cc + Bcc).
   * @param array  $headers     Message headers, including From.
   * @param string $body        Fully built MIME body.
   *
   * @return null|true|\PEAR_Error
   *   NULL  → no dedicated account for this sender; CiviCRM sends as usual.
   *   TRUE  → sent by us; CiviCRM must not send again.
   *   Error → let CiviCRM report the failure.
   */
  public static function routeFilter($mailer, &$recipients, &$headers, &$body) {
    $fromEmail = _smtprouter_extract_email((string) ($headers['From'] ?? ''));
    if (!$fromEmail) {
      return NULL;
    }

    static $transports = [];
    if (!array_key_exists($fromEmail, $transports)) {
      $transports[$fromEmail] = NULL;
      $config = CRM_SmtpRouter_BAO_SmtpConfig::getByFromEmail($fromEmail);
      if ($config) {
        if (!self::_loadPear()) {
          \Civi::log()->error('smtprouter: PEAR Mail unavailable; falling back to the global SMTP.');
          return NULL;
        }
        $transport = self::buildTransport($config);
        if (\PEAR::isError($transport)) {
          \Civi::log()->error('smtprouter: cannot build SMTP transport for ' . $fromEmail . ': ' . $transport->getMessage());
          return NULL;
        }
        $transports[$fromEmail] = $transport;
      }
    }
    if (!$transports[$fromEmail]) {
      // No dedicated account for this sender: CiviCRM's global SMTP applies.
      return NULL;
    }

    $result = $transports[$fromEmail]->send($recipients, $headers, $body);
    if (\PEAR::isError($result)) {
      \Civi::log()->error('smtprouter: send failed for ' . $fromEmail . ': ' . $result->getMessage());
      return $result;
    }
    \Civi::log()->info('smtprouter: sent via dedicated SMTP for ' . $fromEmail);
    return TRUE;
  }

  /**
   * Send $params through the SMTP config for $fromEmail, using PEAR Mail_smtp.
   *
   * @deprecated since 0.5.0 — rebuilds the message and loses attachments.
   *   Routing now happens in routeFilter(), on the message CiviCRM built.
   *   Kept only so existing calls do not fatal.
   *
   * $params keys used: from, to, cc, bcc, subject, html, text, headers
   *
   * @throws \RuntimeException on failure.
   */
  public static function sendViaConfig(array $params, array $config): bool {
    if (!self::_loadPear()) {
      throw new \RuntimeException('Le paquet PEAR Mail n\'est pas disponible dans cette installation CiviCRM.');
    }

    $useSsl = ($config['smtp_security'] === 'ssl');

    $mailParams = [
      'host'     => $useSsl ? 'ssl://' . $config['smtp_host'] : $config['smtp_host'],
      'port'     => (int) $config['smtp_port'],
      'auth'     => (bool) $config['smtp_auth'],
      'username' => $config['smtp_username'] ?? '',
      'password' => $config['smtp_password'] ?? '',
      'timeout'  => 15,
    ];

    $mailer = \Mail::factory('smtp', $mailParams);
    if (\PEAR::isError($mailer)) {
      throw new \RuntimeException('Impossible de créer le mailer SMTP : ' . $mailer->getMessage());
    }

    $fromRaw = $params['from'] ?? $config['from_email'];

    // CiviCRM's $params shape varies by context: some contexts provide a
    // ready-made 'to' string/array, but 'singleEmail' (CRM_Utils_Mail::send())
    // provides 'toEmail' + 'toName' separately instead. Normalize both.
    if (!empty($params['to'])) {
      $toList = is_array($params['to']) ? implode(', ', $params['to']) : $params['to'];
    }
    else {
      $toEmail = $params['toEmail'] ?? '';
      $toName  = $params['toName'] ?? '';
      if ($toEmail === '') {
        throw new \RuntimeException('Aucune adresse destinataire trouvée dans les paramètres (ni "to", ni "toEmail").');
      }
      $toList = $toName !== '' ? '"' . $toName . '" <' . $toEmail . '>' : $toEmail;
    }

    $headers = [
      'From'         => $fromRaw,
      'To'           => $toList,
      'Subject'      => $params['subject'] ?? '(sans objet)',
      'MIME-Version' => '1.0',
      'Content-Type' => 'text/html; charset=UTF-8',
    ];

    if (!empty($params['cc'])) {
      $headers['Cc'] = is_array($params['cc']) ? implode(', ', $params['cc']) : $params['cc'];
    }
    foreach ((array) ($params['headers'] ?? []) as $name => $value) {
      $headers[$name] = $value;
    }

    $body = $params['html'] ?? ($params['body'] ?? '');

    // Full envelope recipient list (To + Cc + Bcc) — Bcc must reach the
    // envelope but must NOT appear in the visible headers above.
    $recipients = $toList;
    if (!empty($params['cc'])) {
      $recipients .= ', ' . (is_array($params['cc']) ? implode(', ', $params['cc']) : $params['cc']);
    }
    if (!empty($params['bcc'])) {
      $recipients .= ', ' . (is_array($params['bcc']) ? implode(', ', $params['bcc']) : $params['bcc']);
    }

    $result = $mailer->send($recipients, $headers, $body);

    if (\PEAR::isError($result)) {
      throw new \RuntimeException('Échec de l\'envoi SMTP : ' . $result->getMessage());
    }

    return TRUE;
  }
}
