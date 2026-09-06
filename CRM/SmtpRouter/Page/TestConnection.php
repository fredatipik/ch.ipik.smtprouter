<?php
/**
 * AJAX page: test an SMTP connection by config id.
 *
 * Route: civicrm/smtprouter/test
 * Returns JSON: {"ok": true, "message": "..."} or {"ok": false, "message": "..."}
 */
class CRM_SmtpRouter_Page_TestConnection extends CRM_Core_Page {

  public function run(): void {
    // CSRF / permission check.
    if (!CRM_Core_Permission::check('administer CiviCRM')) {
      CRM_Utils_System::permissionDenied();
      return;
    }

    $id = (int) CRM_Utils_Request::retrieve('id', 'Integer');
    if (!$id) {
      $this->_jsonResponse(FALSE, 'Paramètre id manquant.');
      return;
    }

    $config = CRM_SmtpRouter_BAO_SmtpConfig::getById($id);
    if (!$config) {
      $this->_jsonResponse(FALSE, 'Configuration introuvable.');
      return;
    }

    [$ok, $message] = CRM_SmtpRouter_Mailer::testConnection($config);
    $this->_jsonResponse($ok, $message);
  }

  private function _jsonResponse(bool $ok, string $message): void {
    header('Content-Type: application/json');
    echo json_encode(['ok' => $ok, 'message' => $message]);
    CRM_Utils_System::civiExit();
  }
}
