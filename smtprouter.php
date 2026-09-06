<?php
/**
 * ch.ipik.smtprouter — Main hooks file.
 *
 * Conventions follow com.ipik.swissQRinvoice:
 *  - require_once class files in hook_civicrm_config
 *  - routes inserted directly into civicrm_menu (hook_civicrm_alterMenu)
 *  - install/uninstall managed entirely in hooks (no managed/*.mgd.php)
 */

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

function smtprouter_civicrm_config(&$config): void {
  static $loaded = FALSE;
  if ($loaded) return;
  $loaded = TRUE;

  $extRoot = __DIR__ . DIRECTORY_SEPARATOR;

  // CRM_Core_Controller (QuickForm dispatch) does an unconditional
  // require_once resolved via PHP's include_path only — it does NOT check
  // class_exists() first, so loading our classes here via absolute paths is
  // not enough. We must also add our extension root to include_path,
  // matching the convention used by com.ipik.swissQRinvoice and
  // com.ipik.booking (visible in Controller.php's error trace, which lists
  // both without a trailing slash — i.e. self-registered, not
  // classloader-managed).
  $currentIncludePath = get_include_path();
  if (strpos($currentIncludePath, $extRoot) === FALSE
    && strpos($currentIncludePath, rtrim($extRoot, DIRECTORY_SEPARATOR)) === FALSE) {
    set_include_path($currentIncludePath . PATH_SEPARATOR . rtrim($extRoot, DIRECTORY_SEPARATOR));
  }

  require_once $extRoot . 'CRM/SmtpRouter/BAO/SmtpConfig.php';
  require_once $extRoot . 'CRM/SmtpRouter/Mailer.php';
  require_once $extRoot . 'CRM/SmtpRouter/Form/Settings.php';
  require_once $extRoot . 'CRM/SmtpRouter/Page/TestConnection.php';

  // Smarty resolves .tpl files via its own template_dir list, entirely
  // separate from PHP's include_path (fixed above). Without this, Smarty
  // throws "Unable to load 'file:CRM/SmtpRouter/Form/Settings.tpl'" even
  // though the include_path fix already lets PHP find our class files.
  $template = CRM_Core_Smarty::singleton();
  $template->addTemplateDir($extRoot . 'templates');
}

// ---------------------------------------------------------------------------
// Install / Uninstall
// ---------------------------------------------------------------------------

function smtprouter_civicrm_install(): void {
  _smtprouter_run_sql('install');
  _smtprouter_install_routes();
  _smtprouter_install_navigation();
}

function smtprouter_civicrm_enable(): void {
  _smtprouter_install_routes();
  _smtprouter_install_navigation();
}

function smtprouter_civicrm_uninstall(): void {
  CRM_Core_DAO::executeQuery(
    "DELETE FROM civicrm_menu WHERE path IN ('civicrm/smtprouter/settings','civicrm/smtprouter/test')"
  );
  CRM_Core_DAO::executeQuery(
    "DELETE FROM civicrm_navigation WHERE url LIKE '%civicrm/smtprouter/settings%'"
  );
  CRM_Core_DAO::executeQuery("DROP TABLE IF EXISTS civicrm_smtp_config");
}

function smtprouter_civicrm_disable(): void {
  // Leave table intact; remove menu entries and navigation so the page disappears.
  CRM_Core_DAO::executeQuery(
    "DELETE FROM civicrm_menu WHERE path IN ('civicrm/smtprouter/settings','civicrm/smtprouter/test')"
  );
  CRM_Core_DAO::executeQuery(
    "DELETE FROM civicrm_navigation WHERE url LIKE '%civicrm/smtprouter/settings%'"
  );
}

// ---------------------------------------------------------------------------
// Menu / Routes
// ---------------------------------------------------------------------------

/**
 * CiviCRM 6 menu hook (replaces the defunct hook_civicrm_xmlMenu).
 *
 * IMPORTANT: $items here holds LIVE, already-unserialized PHP values
 * (CiviCRM reads civicrm_menu from DB and unserializes access_callback /
 * access_arguments before calling this hook). Do NOT serialize() anything
 * here — that was the bug in v0.12 that produced:
 *   "call_user_func_array(): Argument #2 ($args) must be of type array, string given"
 * because a serialized string was passed where an array was expected.
 *
 * access_callback is omitted so CiviCRM falls back to its default
 * CRM_Core_Permission::checkMenu(). access_arguments follows the documented
 * live-array format: [ [permission, ...], 'and'|'or' ].
 */
function smtprouter_civicrm_alterMenu(&$items): void {
  $items['civicrm/smtprouter/settings'] = [
    'title'            => 'SMTP Router',
    'page_callback'    => 'CRM_SmtpRouter_Form_Settings',
    'access_arguments' => [['administer CiviCRM'], 'and'],
    'is_public'        => 0,
  ];
  $items['civicrm/smtprouter/test'] = [
    'title'            => 'SMTP Router — Test',
    'page_callback'    => 'CRM_SmtpRouter_Page_TestConnection',
    'access_arguments' => [['administer CiviCRM'], 'and'],
    'is_public'        => 0,
  ];
}

/**
 * Direct DB insertion of menu rows (reliable fallback for shared hosting).
 * Called on install and enable.
 */
function _smtprouter_install_routes(): void {
  $routes = [
    [
      'path'             => 'civicrm/smtprouter/settings',
      'title'            => 'SMTP Router',
      'page_callback'    => 'CRM_SmtpRouter_Form_Settings',
      'access_callback'  => serialize('CRM_Core_Permission::checkMenu'),
      'access_arguments' => serialize([['administer CiviCRM'], 'and']),
      'is_public'        => 0,
    ],
    [
      'path'             => 'civicrm/smtprouter/test',
      'title'            => 'SMTP Router — Test SMTP',
      'page_callback'    => 'CRM_SmtpRouter_Page_TestConnection',
      'access_callback'  => serialize('CRM_Core_Permission::checkMenu'),
      'access_arguments' => serialize([['administer CiviCRM'], 'and']),
      'is_public'        => 0,
    ],
  ];

  $domain_id = (int) CRM_Core_Config::domainID();

  foreach ($routes as $route) {
    $existingId = (int) CRM_Core_DAO::singleValueQuery(
      "SELECT id FROM civicrm_menu WHERE path = %1 AND domain_id = %2",
      [1 => [$route['path'], 'String'], 2 => [$domain_id, 'Integer']]
    );

    if ($existingId) {
      // Refresh in place so extension upgrades propagate corrected
      // access_callback / access_arguments without a full uninstall/reinstall.
      CRM_Core_DAO::executeQuery(
        "UPDATE civicrm_menu
         SET title = %1, page_callback = %2, access_callback = %3,
             access_arguments = %4, is_public = %5, is_active = 1
         WHERE id = %6",
        [
          1 => [$route['title'],            'String'],
          2 => [$route['page_callback'],    'String'],
          3 => [$route['access_callback'],  'String'],
          4 => [$route['access_arguments'], 'String'],
          5 => [$route['is_public'],        'Integer'],
          6 => [$existingId,                'Integer'],
        ]
      );
    }
    else {
      CRM_Core_DAO::executeQuery(
        "INSERT INTO civicrm_menu
           (path, domain_id, title, page_callback, access_callback, access_arguments,
            is_active, is_public, weight)
         VALUES (%1, %2, %3, %4, %5, %6, 1, %7, 0)",
        [
          1 => [$route['path'],             'String'],
          2 => [$domain_id,                 'Integer'],
          3 => [$route['title'],            'String'],
          4 => [$route['page_callback'],    'String'],
          5 => [$route['access_callback'],  'String'],
          6 => [$route['access_arguments'], 'String'],
          7 => [$route['is_public'],        'Integer'],
        ]
      );
    }
  }
}

/**
 * Insert a navigation entry under "System Settings" so the page appears in
 * Administer → System Settings → SMTP Router.
 *
 * civicrm_navigation rows:
 *   domain_id, label, name, url, permission, permission_operator,
 *   parent_id, is_active, has_separator, weight
 *
 * We look up the "System Settings" parent by name (stable across versions).
 */
function _smtprouter_install_navigation(): void {
  $domain_id = (int) CRM_Core_Config::domainID();

  // Already present?
  $exists = (int) CRM_Core_DAO::singleValueQuery(
    "SELECT COUNT(*) FROM civicrm_navigation
     WHERE url LIKE '%civicrm/smtprouter/settings%' AND domain_id = %1",
    [1 => [$domain_id, 'Integer']]
  );
  if ($exists) {
    return;
  }

  // Find the "System Settings" parent nav id.
  $parentId = (int) CRM_Core_DAO::singleValueQuery(
    "SELECT id FROM civicrm_navigation
     WHERE name = 'System Settings' AND domain_id = %1
     LIMIT 1",
    [1 => [$domain_id, 'Integer']]
  );

  // Fallback: try label search (some installs use French labels).
  if (!$parentId) {
    $parentId = (int) CRM_Core_DAO::singleValueQuery(
      "SELECT id FROM civicrm_navigation
       WHERE (label LIKE '%System Settings%' OR label LIKE '%Paramètres système%')
         AND domain_id = %1
       LIMIT 1",
      [1 => [$domain_id, 'Integer']]
    );
  }

  // Max weight among siblings so we appear at the bottom of the section.
  $weight = 1;
  if ($parentId) {
    $maxWeight = (int) CRM_Core_DAO::singleValueQuery(
      "SELECT MAX(weight) FROM civicrm_navigation WHERE parent_id = %1",
      [1 => [$parentId, 'Integer']]
    );
    $weight = $maxWeight + 1;
  }

  $parentSql   = $parentId ? '%7' : 'NULL';
  $baseParams  = [
    1 => [$domain_id,                            'Integer'],
    2 => ['SMTP Router',                          'String'],
    3 => ['SMTPRouter',                           'String'],
    4 => ['civicrm/smtprouter/settings?reset=1', 'String'],
    5 => ['administer CiviCRM',                   'String'],
    6 => ['AND',                                   'String'],
    8 => [$weight,                                 'Integer'],
  ];
  if ($parentId) {
    $baseParams[7] = [$parentId, 'Integer'];
  }

  CRM_Core_DAO::executeQuery(
    "INSERT INTO civicrm_navigation
       (domain_id, label, name, url, permission, permission_operator,
        parent_id, is_active, has_separator, weight)
     VALUES (%1, %2, %3, %4, %5, %6, {$parentSql}, 1, 0, %8)",
    $baseParams
  );

  // Flush navigation cache so the entry appears immediately.
  CRM_Core_BAO_Navigation::resetNavigation();
}

// ---------------------------------------------------------------------------
// Core hook: intercept outbound mail
// ---------------------------------------------------------------------------

/**
 * hook_civicrm_alterMailParams
 *
 * Called before CiviCRM sends any email. We inspect the From address, look up
 * a matching SMTP config, and if found we send the email ourselves via PHPMailer
 * then set $params['abortMailSend'] = TRUE so CiviCRM doesn't double-send.
 *
 * If no config is found → do nothing → CiviCRM uses its global SMTP.
 *
 * @param array  $params      Mail parameters (from, to, subject, html, …)
 * @param string $context     'civimail', 'transactional', 'activity', etc.
 */
function smtprouter_civicrm_alterMailParams(array &$params, string $context = ''): void {
  // Extract the bare email address from the From field.
  $fromRaw = $params['from'] ?? '';
  $fromEmail = _smtprouter_extract_email($fromRaw);

  if (!$fromEmail) {
    return;
  }

  $config = CRM_SmtpRouter_BAO_SmtpConfig::getByFromEmail($fromEmail);
  if (!$config) {
    // No dedicated SMTP for this sender → let CiviCRM handle it.
    return;
  }

  try {
    CRM_SmtpRouter_Mailer::sendViaConfig($params, $config);
    // Tell CiviCRM not to send again.
    $params['abortMailSend'] = TRUE;
    \Civi::log()->info('smtprouter: sent via dedicated SMTP for ' . $fromEmail);
  }
  catch (\Throwable $e) {
    // Log and let the error propagate so CiviCRM's mail error handling triggers.
    \Civi::log()->error('smtprouter: send failed for ' . $fromEmail . ': ' . $e->getMessage());
    // Do NOT set abortMailSend — this allows CiviCRM's error handling to run.
    // The email will likely fail via the global SMTP too if credentials are wrong,
    // but at least it won't silently disappear.
  }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Extract bare email from "Name" <email> or plain email string.
 */
function _smtprouter_extract_email(string $raw): string {
  $raw = trim($raw);
  if (preg_match('/<([^>]+)>/', $raw, $m)) {
    return strtolower(trim($m[1]));
  }
  return strtolower($raw);
}

/**
 * Run an SQL file bundled with the extension.
 * Uses a quote-aware statement splitter (avoids breaking on semicolons inside strings).
 */
function _smtprouter_run_sql(string $basename): void {
  $file = __DIR__ . '/sql/' . $basename . '.sql';
  if (!file_exists($file)) return;

  $sql = file_get_contents($file);

  // Strip line comments (-- ...).
  $sql = preg_replace('/--[^\n]*\n/', "\n", $sql);

  // Split on semicolons that are not inside single-quoted strings.
  $statements = _smtprouter_split_sql($sql);

  foreach ($statements as $stmt) {
    $stmt = trim($stmt);
    if ($stmt !== '') {
      CRM_Core_DAO::executeQuery($stmt);
    }
  }
}

/**
 * Quote-aware SQL splitter: splits on ';' outside of single-quoted strings.
 * Handles escaped quotes ('') inside strings.
 *
 * @return string[]
 */
function _smtprouter_split_sql(string $sql): array {
  $statements = [];
  $current    = '';
  $inString   = FALSE;
  $len        = strlen($sql);

  for ($i = 0; $i < $len; $i++) {
    $ch = $sql[$i];

    if ($inString) {
      $current .= $ch;
      if ($ch === "'") {
        // Check for escaped quote: '' inside a string.
        if (isset($sql[$i + 1]) && $sql[$i + 1] === "'") {
          $current .= "'";
          $i++;
        }
        else {
          $inString = FALSE;
        }
      }
    }
    else {
      if ($ch === "'") {
        $inString = TRUE;
        $current .= $ch;
      }
      elseif ($ch === ';') {
        $statements[] = $current;
        $current = '';
      }
      else {
        $current .= $ch;
      }
    }
  }

  if (trim($current) !== '') {
    $statements[] = $current;
  }

  return $statements;
}
