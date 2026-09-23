<?php
/**
 * Admin form: list all SMTP configs + inline add/edit.
 *
 * Route: civicrm/smtprouter/settings
 */
class CRM_SmtpRouter_Form_Settings extends CRM_Core_Form {

  /** @var int|null  ID being edited, or null for a new entry */
  private ?int $_editId = null;

  public function preProcess(): void {
    parent::preProcess();

    CRM_Utils_System::setTitle(ts('SMTP Router — Configurations'));

    $action = CRM_Utils_Request::retrieve('action', 'String', $this, FALSE, 'list');
    $id     = (int) CRM_Utils_Request::retrieve('id', 'Integer', $this, FALSE, 0);

    // Handle delete action immediately (no form needed).
    // Protected against CSRF via CRM_Core_Key — these are state-changing
    // actions reachable by GET, so a bare permission check is not enough:
    // a crafted link clicked by a logged-in admin could otherwise delete
    // or disable a config without their intent.
    if ($action === 'delete' && $id > 0) {
      $key = CRM_Utils_Request::retrieve('key', 'String', $this, FALSE, '');
      if (!CRM_Core_Key::validate($key, 'CRM_SmtpRouter_Form_Settings')) {
        CRM_Core_Session::setStatus(ts('Lien invalide ou expiré. Merci de réessayer depuis la liste.'), ts('SMTP Router'), 'error');
        CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/smtprouter/settings'));
      }
      CRM_SmtpRouter_BAO_SmtpConfig::deleteById($id);
      CRM_Core_Session::setStatus(ts('Configuration supprimée.'), ts('SMTP Router'), 'success');
      CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/smtprouter/settings'));
    }

    // Handle toggle action immediately. Same CSRF protection as delete.
    if ($action === 'toggle' && $id > 0) {
      $key = CRM_Utils_Request::retrieve('key', 'String', $this, FALSE, '');
      if (!CRM_Core_Key::validate($key, 'CRM_SmtpRouter_Form_Settings')) {
        CRM_Core_Session::setStatus(ts('Lien invalide ou expiré. Merci de réessayer depuis la liste.'), ts('SMTP Router'), 'error');
        CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/smtprouter/settings'));
      }
      CRM_SmtpRouter_BAO_SmtpConfig::toggleActive($id);
      CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/smtprouter/settings'));
    }

    if ($action === 'edit' && $id > 0) {
      $this->_editId = $id;
    }

    // Some core-included subtemplates (e.g. formButtons.tpl) expect a
    // top-level 'id' Smarty variable by convention. Assigning it explicitly
    // avoids a PHP8 "Undefined array key" warning on the list view, where
    // our own 'id' form element doesn't exist.
    $this->assign('id', $this->_editId ?? 0);

    $this->assign('action', $action);
    $this->assign('cancelURL', CRM_Utils_System::url('civicrm/smtprouter/settings'));
  }

  public function buildQuickForm(): void {
    // Always pass the config list to the template.
    $this->assign('configs', CRM_SmtpRouter_BAO_SmtpConfig::getAll());
    $this->assign('testURL', CRM_Utils_System::url('civicrm/smtprouter/test'));
    $this->assign('globalSmtp', self::_getGlobalSmtp());
    $this->assign('globalSmtpEditURL', CRM_Utils_System::url('civicrm/admin/setting/smtp', 'reset=1'));

    // CSRF token for the delete/toggle GET links — see preProcess().
    $this->assign('csrfKey', CRM_Core_Key::get('CRM_SmtpRouter_Form_Settings'));

    $action = $this->get('action') ?? CRM_Utils_Request::retrieve('action', 'String', $this, FALSE, 'list');

    if ($action !== 'list') {
      // Add / Edit form elements.
      $this->addElement('hidden', 'id');
      $this->add('text', 'from_email', ts('Adresse From'), ['class' => 'huge', 'placeholder' => 'info@association.ch'], TRUE);
      $this->add('text', 'smtp_host',  ts('Hôte SMTP'),   ['class' => 'huge', 'placeholder' => 'mail.infomaniak.com'], TRUE);
      $this->add('text', 'smtp_port',  ts('Port'),        ['class' => 'four', 'placeholder' => '587'], TRUE);
      $this->addYesNo('smtp_auth', ts('Authentification'));
      $this->add('text',     'smtp_username', ts('Utilisateur SMTP'), ['class' => 'huge']);
      $this->add('password', 'smtp_password', ts('Mot de passe SMTP'), ['class' => 'huge', 'autocomplete' => 'new-password']);
      $this->add('select', 'smtp_security', ts('Sécurité'), [
        'tls'  => ts('TLS (STARTTLS, port 587)'),
        'ssl'  => ts('SSL (port 465)'),
        'none' => ts('Aucune'),
      ]);
      $this->addYesNo('is_active', ts('Actif'));

      $this->addButtons([
        ['type' => 'submit', 'name' => ts('Enregistrer'), 'isDefault' => TRUE],
      ]);
    }
  }

  public function setDefaultValues(): array {
    $defaults = [
      'smtp_host'     => 'mail.infomaniak.com',
      'smtp_port'     => 587,
      'smtp_auth'     => 1,
      'smtp_security' => 'tls',
      'is_active'     => 1,
    ];

    if ($this->_editId) {
      $row = CRM_SmtpRouter_BAO_SmtpConfig::getById($this->_editId);
      if ($row) {
        $defaults = array_merge($defaults, $row);
        // Show a placeholder for the password rather than the decrypted value
        // to avoid it being submitted back inadvertently.
        $defaults['smtp_password'] = '';
      }
    }

    return $defaults;
  }

  /**
   * Read the global CiviCRM SMTP settings directly from civicrm_setting
   * (avoids Civi::settings() reliability issues on shared hosting).
   *
   * @return array  Keys: outBound_option, smtpServer, smtpPort, smtpAuth,
   *                      smtpUsername, smtpSsl, fromName, fromEmailAddress
   */
  private static function _getGlobalSmtp(): array {
    $keys = [
      'outBound_option', 'smtpServer', 'smtpPort', 'smtpAuth',
      'smtpUsername', 'smtpSsl', 'fromName', 'fromEmailAddress',
    ];
    $result = [];
    foreach ($keys as $key) {
      $val = CRM_Core_DAO::singleValueQuery(
        "SELECT value FROM civicrm_setting
         WHERE name = %1 AND domain_id = %2
         ORDER BY id DESC LIMIT 1",
        [
          1 => [$key, 'String'],
          2 => [(int) CRM_Core_Config::domainID(), 'Integer'],
        ]
      );
      $result[$key] = $val !== NULL ? unserialize($val) : NULL;
    }

    // Human-readable outbound type.
    $types = [
      0 => 'Sendmail', 1 => 'SMTP', 2 => 'Disabled',
      3 => 'Redirect to log', 4 => 'SendGrid', 5 => 'Amazon SES',
    ];
    $result['outBound_label'] = $types[$result['outBound_option'] ?? -1] ?? 'Inconnu';

    return $result;
  }

  public function postProcess(): void {
    $values = $this->exportValues();

    $data = [
      'id'            => (int) ($values['id'] ?? 0),
      'from_email'    => trim($values['from_email']),
      'smtp_host'     => trim($values['smtp_host']),
      'smtp_port'     => (int) $values['smtp_port'],
      'smtp_auth'     => (int) $values['smtp_auth'],
      'smtp_username' => trim($values['smtp_username'] ?? ''),
      'smtp_password' => $values['smtp_password'] ?? '',
      'smtp_security' => $values['smtp_security'],
      'is_active'     => (int) $values['is_active'],
    ];

    CRM_SmtpRouter_BAO_SmtpConfig::save($data);

    CRM_Core_Session::setStatus(ts('Configuration SMTP enregistrée.'), ts('SMTP Router'), 'success');
    CRM_Utils_System::redirect(CRM_Utils_System::url('civicrm/smtprouter/settings'));
  }
}
