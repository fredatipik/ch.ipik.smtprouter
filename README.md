# SMTP Router (ch.ipik.smtprouter)

Extension CiviCRM qui route chaque email sortant vers le bon serveur SMTP en fonction de l'adresse `From:`.

## Pourquoi

Sur un hébergement mutualisé comme Infomaniak, chaque adresse email qui envoie doit s'authentifier avec ses propres identifiants SMTP — impossible d'utiliser un seul serveur SMTP global pour plusieurs adresses (`info@association.ch`, `therapist1@association.ch`, etc.). CiviCRM ne gère nativement qu'un seul serveur SMTP global (Administer → System Settings → Outbound Mail).

Cette extension permet de configurer plusieurs serveurs SMTP et de router automatiquement chaque email vers le bon serveur selon l'adresse `From:`, avec repli transparent sur le SMTP global si aucune configuration dédiée ne correspond.

## Fonctionnement

1. L'administrateur configure N serveurs SMTP dans **Administer → System Settings → SMTP Router**, un par adresse `From:`.
2. À l'envoi, `hook_civicrm_alterMailParams` intercepte l'email, lit l'adresse `From:`, et cherche une configuration correspondante.
3. Si trouvée : envoi via [PEAR Mail](https://pear.php.net/package/Mail) (`Mail_smtp`) avec les identifiants dédiés, puis `abortMailSend` est positionné pour empêcher CiviCRM de renvoyer via son SMTP global.
4. Si non trouvée : CiviCRM utilise son SMTP global comme d'habitude.

## Pourquoi PEAR Mail et pas PHPMailer

Selon la version et la distribution de CiviCRM, le mailer SMTP embarqué peut être PEAR Mail (`Mail_smtp` / `Net_SMTP`) plutôt que PHPMailer. Cette extension a été développée et testée sur une installation CiviCRM 6.15 (WordPress, hébergement Infomaniak) qui n'embarque que PEAR Mail — c'est donc ce paquet qui est utilisé, avec repli sur un test TCP brut si PEAR Mail est indisponible.

## Installation

```bash
cv ext:install ch.ipik.smtprouter
```

Puis configurer via **Administer → System Settings → SMTP Router**.

## ⚠️ Configuration complète d'une adresse d'envoi

Ajouter une adresse dans SMTP Router **ne suffit pas** pour qu'elle fonctionne pleinement dans CiviCRM. Trois endroits doivent être renseignés :

1. **SMTP Router** (cette extension) — les identifiants du serveur SMTP dédié.
2. **Comptes courriels** (`civicrm/admin/mailSettings`) — nécessaire pour l'email-to-activity et la gestion des rebonds ; sans cette entrée, l'email peut partir correctement mais l'activité associée peut ne pas être créée.
3. **Adresses courriels (From) du site** (`civicrm/admin/options/from_email_address`) — pour que l'adresse apparaisse comme option "From" sélectionnable dans les formulaires d'envoi CiviCRM.

## Sécurité

- Mots de passe SMTP chiffrés en base via `Civi::service('crypto.token')`.
- Toutes les routes verrouillées sur la permission `administer CiviCRM`.
- Actions destructives (suppression/activation d'une config) protégées contre le CSRF via `CRM_Core_Key`.
- Aucune requête SQL non paramétrée.

## Compatibilité testée

- CiviCRM 6.15.4 / WordPress / hébergement mutualisé Infomaniak.

## Licence

AGPL-3.0

## Crédits

Développé par Frédéric Hiltbrand ([IPIK](https://ipik.ch)) avec l'assistance de [Claude.ai](https://claude.ai) (Anthropic).
