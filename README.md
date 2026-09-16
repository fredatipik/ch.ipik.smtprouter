# SMTP Router (ch.ipik.smtprouter)

A CiviCRM extension that routes each outbound email to the correct SMTP server based on the `From:` address.

## Why

On some shared hosting providers (this extension was built and tested on Infomaniak), every sending email address must authenticate with its own SMTP credentials — you cannot use a single global SMTP server for multiple addresses (`info@association.org`, `therapist1@association.org`, `therapist2@association.org`, etc.). CiviCRM natively supports only one global SMTP server (Administer → System Settings → Outbound Mail).

This extension lets you configure multiple SMTP servers and automatically routes each outgoing email to the right one based on its `From:` address, with a transparent fallback to the global SMTP server when no dedicated configuration matches.

## How it works

1. The administrator configures N SMTP servers under **Administer → System Settings → SMTP Router**, one per `From:` address.
2. On send, `hook_civicrm_alterMailParams` intercepts the email, reads the `From:` address, and looks up a matching configuration.
3. If found: the email is sent via [PEAR Mail](https://pear.php.net/package/Mail) (`Mail_smtp`) using the dedicated credentials, and `abortMailSend` is set so CiviCRM does not also send it through the global SMTP server.
4. If not found: CiviCRM falls back to its usual global SMTP configuration.

## Why PEAR Mail instead of PHPMailer

Depending on the CiviCRM version and distribution, the bundled SMTP mailer may be PEAR Mail (`Mail_smtp` / `Net_SMTP`) rather than PHPMailer. This extension was developed and tested on a CiviCRM 6.15 install (WordPress, Infomaniak hosting) that only ships PEAR Mail — so that is what's used here, with a raw TCP connectivity test as a fallback if PEAR Mail is unavailable.

## Installation

```bash
cv ext:install ch.ipik.smtprouter
```

Then configure senders under **Administer → System Settings → SMTP Router**.

## ⚠️ A sending address needs configuration in three places

Adding an address to SMTP Router alone is **not enough** for it to work fully in CiviCRM. Three separate places need to be set up:

1. **SMTP Router** (this extension) — the dedicated SMTP server credentials.
2. **Mail Accounts** (`civicrm/admin/mailSettings`) — needed for email-to-activity processing and bounce handling. Without a matching entry here, an email can be sent successfully by SMTP Router while CiviCRM fails to log the corresponding Activity.
3. **From Email Addresses** (`civicrm/admin/options/from_email_address`) — needed for the address to appear as a selectable "From" option in CiviCRM's send-email forms.

The extension's settings page includes reminders and direct links to the other two pages.

## Security

- SMTP passwords are encrypted at rest via `Civi::service('crypto.token')`.
- All routes are restricted to the `administer CiviCRM` permission.
- Destructive actions (deleting/toggling a config) are protected against CSRF via `CRM_Core_Key`.
- All SQL queries are parameterized.

## Tested compatibility

- CiviCRM 6.15.4 / WordPress / Infomaniak shared hosting.

## License

AGPL-3.0

## Credits

Developed by Frédéric Hiltbrand ([IPIK](https://ipik.ch)) with the assistance of [Claude.ai](https://claude.ai) (Anthropic).
