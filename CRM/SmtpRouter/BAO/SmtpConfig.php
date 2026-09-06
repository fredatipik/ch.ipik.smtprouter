<?php
/**
 * BAO for civicrm_smtp_config.
 *
 * Handles CRUD operations and password encryption/decryption.
 */
class CRM_SmtpRouter_BAO_SmtpConfig {

  /**
   * Return all rows (password NOT decrypted — safe for listing).
   */
  public static function getAll(): array {
    $rows = [];
    $result = CRM_Core_DAO::executeQuery(
      "SELECT id, from_email, smtp_host, smtp_port, smtp_auth,
              smtp_username, smtp_security, is_active, created_at, updated_at
       FROM civicrm_smtp_config
       ORDER BY from_email ASC"
    );
    while ($result->fetch()) {
      $rows[] = (array) $result->toArray();
    }
    return $rows;
  }

  /**
   * Return a single row by id, with password decrypted.
   */
  public static function getById(int $id): ?array {
    $result = CRM_Core_DAO::executeQuery(
      "SELECT * FROM civicrm_smtp_config WHERE id = %1",
      [1 => [$id, 'Integer']]
    );
    if ($result->fetch()) {
      $row = (array) $result->toArray();
      if (!empty($row['smtp_password'])) {
        try {
          $row['smtp_password'] = Civi::service('crypto.token')->decrypt($row['smtp_password'], '*');
        }
        catch (\Throwable $e) {
          // If decryption fails (e.g. key rotation), return empty — admin must re-enter.
          $row['smtp_password'] = '';
          \Civi::log()->warning('smtprouter: could not decrypt password for id=' . $id . ': ' . $e->getMessage());
        }
      }
      return $row;
    }
    return null;
  }

  /**
   * Return config for a specific From email, with password decrypted.
   * Returns null if not found or inactive.
   */
  public static function getByFromEmail(string $email): ?array {
    $result = CRM_Core_DAO::executeQuery(
      "SELECT * FROM civicrm_smtp_config
       WHERE from_email = %1 AND is_active = 1",
      [1 => [$email, 'String']]
    );
    if ($result->fetch()) {
      $row = (array) $result->toArray();
      if (!empty($row['smtp_password'])) {
        try {
          $row['smtp_password'] = Civi::service('crypto.token')->decrypt($row['smtp_password'], '*');
        }
        catch (\Throwable $e) {
          \Civi::log()->error('smtprouter: could not decrypt password for ' . $email . ': ' . $e->getMessage());
          return null; // Fail safe: don't send with wrong credentials.
        }
      }
      return $row;
    }
    return null;
  }

  /**
   * Insert or update a config row.
   * $data keys: from_email, smtp_host, smtp_port, smtp_auth, smtp_username,
   *             smtp_password (plain text — will be encrypted here),
   *             smtp_security, is_active
   * If $data['id'] is set → UPDATE, else INSERT.
   * When updating, if smtp_password is empty, keep the existing encrypted value.
   */
  public static function save(array $data): int {
    $now = date('Y-m-d H:i:s');

    // Encrypt password if provided.
    if (!empty($data['smtp_password'])) {
      $data['smtp_password'] = Civi::service('crypto.token')->encrypt($data['smtp_password'], 'CRED');
    }

    $id = (int) ($data['id'] ?? 0);

    if ($id > 0) {
      // Build SET clause dynamically to allow keeping existing password.
      $set  = "from_email = %1, smtp_host = %2, smtp_port = %3, smtp_auth = %4,
               smtp_username = %5, smtp_security = %6, is_active = %7, updated_at = %8";
      $params = [
        1 => [$data['from_email'],  'String'],
        2 => [$data['smtp_host'],   'String'],
        3 => [$data['smtp_port'],   'Integer'],
        4 => [$data['smtp_auth'],   'Integer'],
        5 => [$data['smtp_username'] ?? '', 'String'],
        6 => [$data['smtp_security'], 'String'],
        7 => [$data['is_active'],   'Integer'],
        8 => [$now,                 'String'],
        9 => [$id,                  'Integer'],
      ];
      if (!empty($data['smtp_password'])) {
        $set .= ", smtp_password = %10";
        $params[10] = [$data['smtp_password'], 'String'];
      }
      CRM_Core_DAO::executeQuery("UPDATE civicrm_smtp_config SET $set WHERE id = %9", $params);
      return $id;
    }
    else {
      $params = [
        1  => [$data['from_email'],  'String'],
        2  => [$data['smtp_host'],   'String'],
        3  => [$data['smtp_port'],   'Integer'],
        4  => [$data['smtp_auth'],   'Integer'],
        5  => [$data['smtp_username'] ?? '', 'String'],
        6  => [$data['smtp_password'] ?? '', 'String'],
        7  => [$data['smtp_security'], 'String'],
        8  => [$data['is_active'],   'Integer'],
        9  => [$now,                 'String'],
        10 => [$now,                 'String'],
      ];
      CRM_Core_DAO::executeQuery(
        "INSERT INTO civicrm_smtp_config
         (from_email, smtp_host, smtp_port, smtp_auth, smtp_username,
          smtp_password, smtp_security, is_active, created_at, updated_at)
         VALUES (%1, %2, %3, %4, %5, %6, %7, %8, %9, %10)",
        $params
      );
      return (int) CRM_Core_DAO::singleValueQuery("SELECT LAST_INSERT_ID()");
    }
  }

  /**
   * Delete a config row by id.
   */
  public static function deleteById(int $id): void {
    CRM_Core_DAO::executeQuery(
      "DELETE FROM civicrm_smtp_config WHERE id = %1",
      [1 => [$id, 'Integer']]
    );
  }

  /**
   * Toggle the is_active flag.
   */
  public static function toggleActive(int $id): void {
    CRM_Core_DAO::executeQuery(
      "UPDATE civicrm_smtp_config SET is_active = 1 - is_active WHERE id = %1",
      [1 => [$id, 'Integer']]
    );
  }
}
