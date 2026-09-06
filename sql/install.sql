-- ch.ipik.smtprouter — install.sql
-- Creates the SMTP configuration table.

CREATE TABLE IF NOT EXISTS `civicrm_smtp_config` (
  `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `from_email`    VARCHAR(255)    NOT NULL,
  `smtp_host`     VARCHAR(255)    NOT NULL DEFAULT 'mail.infomaniak.com',
  `smtp_port`     SMALLINT        NOT NULL DEFAULT 587,
  `smtp_auth`     TINYINT(1)      NOT NULL DEFAULT 1,
  `smtp_username` VARCHAR(255)        NULL,
  `smtp_password` TEXT                NULL  COMMENT 'Encrypted via Civi crypto.token',
  `smtp_security` ENUM('none','tls','ssl') NOT NULL DEFAULT 'tls',
  `is_active`     TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`    DATETIME            NULL,
  `updated_at`    DATETIME            NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_from_email` (`from_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
