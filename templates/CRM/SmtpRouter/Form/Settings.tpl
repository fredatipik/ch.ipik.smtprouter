{* ch.ipik.smtprouter — Settings.tpl — v0.16 *}

<div class="crm-block crm-form-block crm-smtp-router-settings-block">

  {* ------------------------------------------------------------------ *}
  {* Rappel : deux autres pages CiviCRM à configurer pour chaque adresse *}
  {* ------------------------------------------------------------------ *}
  <div class="messages status" style="margin-bottom:16px;">
    <strong>{ts}Une adresse d'envoi ne fonctionne pleinement que si elle est aussi configurée ici :{/ts}</strong>
    <ul style="margin:6px 0 0 20px;">
      <li>
        <a href="{$mailSettingsURL}">{ts}Comptes courriels ↗{/ts}</a>
        — {ts}nécessaire pour l'email-to-activity et la gestion des rebonds (sans quoi l'email peut partir sans qu'une activité soit créée).{/ts}
      </li>
      <li>
        <a href="{$fromEmailURL}">{ts}Adresses courriels (From) du site ↗{/ts}</a>
        — {ts}nécessaire pour que l'adresse apparaisse comme option "From" dans les formulaires d'envoi CiviCRM.{/ts}
      </li>
    </ul>
  </div>

  {* ------------------------------------------------------------------ *}
  {* Encart : SMTP global CiviCRM (lecture seule) *}
  {* ------------------------------------------------------------------ *}
  <h3>{ts}Courrier sortant global (CiviCRM){/ts}
    <small style="font-weight:normal;font-size:0.8em;margin-left:8px;">
      <a href="{$globalSmtpEditURL}">{ts}Modifier ↗{/ts}</a>
    </small>
  </h3>

  <div class="smtprouter-global-smtp" style="background:#f8f8f8;border:1px solid #ddd;border-radius:4px;padding:12px 16px;margin-bottom:16px;">
    {if $globalSmtp.outBound_option == 1}{* SMTP *}
      <table class="form-layout-compressed" style="width:auto;">
        <tr>
          <td class="label">{ts}Type{/ts}</td>
          <td><strong>{$globalSmtp.outBound_label}</strong></td>
          <td style="width:24px;"></td>
          <td class="label">{ts}Hôte{/ts}</td>
          <td><code>{$globalSmtp.smtpServer|default:'—'}</code></td>
        </tr>
        <tr>
          <td class="label">{ts}Port{/ts}</td>
          <td>{$globalSmtp.smtpPort|default:'—'}</td>
          <td></td>
          <td class="label">{ts}Sécurité{/ts}</td>
          <td>{if $globalSmtp.smtpSsl}SSL/TLS{else}TLS (STARTTLS){/if}</td>
        </tr>
        <tr>
          <td class="label">{ts}Authentification{/ts}</td>
          <td>{if $globalSmtp.smtpAuth}{ts}Oui{/ts}{else}{ts}Non{/ts}{/if}</td>
          <td></td>
          <td class="label">{ts}Utilisateur{/ts}</td>
          <td>{$globalSmtp.smtpUsername|default:'—'}</td>
        </tr>
        <tr>
          <td class="label">{ts}From (défaut){/ts}</td>
          <td colspan="4">
            {if $globalSmtp.fromName}"{$globalSmtp.fromName|escape}" &lt;{$globalSmtp.fromEmailAddress|escape}&gt;
            {else}{$globalSmtp.fromEmailAddress|default:'—'|escape}{/if}
          </td>
        </tr>
      </table>
      <p class="description" style="margin-top:8px;margin-bottom:0;">
        {ts}Ce SMTP est utilisé pour toute adresse From sans configuration dédiée ci-dessous.{/ts}
      </p>
    {else}
      <p style="margin:0;">
        {ts}Type :{/ts} <strong>{$globalSmtp.outBound_label}</strong>
        &nbsp;—&nbsp;
        {ts}Les emails dont l'adresse From ne correspond à aucune config ci-dessous seront envoyés via ce mode.{/ts}
        <a href="{$globalSmtpEditURL}" style="margin-left:8px;">{ts}Configurer le SMTP global ↗{/ts}</a>
      </p>
    {/if}
  </div>

  {* ------------------------------------------------------------------ *}
  {* Config table *}
  {* ------------------------------------------------------------------ *}
  <h3>{ts}Serveurs SMTP par expéditeur{/ts}</h3>

  {if $configs}
  <table class="crm-datatable" id="smtprouter-config-list">
    <thead>
      <tr>
        <th>{ts}Adresse From{/ts}</th>
        <th>{ts}Hôte SMTP{/ts}</th>
        <th>{ts}Port{/ts}</th>
        <th>{ts}Sécurité{/ts}</th>
        <th>{ts}Utilisateur{/ts}</th>
        <th>{ts}Actif{/ts}</th>
        <th>{ts}Actions{/ts}</th>
      </tr>
    </thead>
    <tbody>
    {foreach from=$configs item=cfg}
      <tr class="{if $cfg.is_active}crm-row-ok{else}crm-row-error{/if}">
        <td><strong>{$cfg.from_email|escape}</strong></td>
        <td>{$cfg.smtp_host|escape}</td>
        <td>{$cfg.smtp_port|escape}</td>
        <td>{$cfg.smtp_security|upper}</td>
        <td>{$cfg.smtp_username|escape}</td>
        <td>
          <a href="{crmURL p='civicrm/smtprouter/settings' q="action=toggle&id=`$cfg.id`&key=`$csrfKey`&reset=1"}"
             title="{if $cfg.is_active}{ts}Désactiver{/ts}{else}{ts}Activer{/ts}{/if}">
            {if $cfg.is_active}✅{else}⛔{/if}
          </a>
        </td>
        <td>
          <a href="{crmURL p='civicrm/smtprouter/settings' q="action=edit&id=`$cfg.id`&reset=1"}"
             class="button">{ts}Modifier{/ts}</a>
          &nbsp;
          <a href="#" class="button smtprouter-test-btn" data-id="{$cfg.id}"
             data-email="{$cfg.from_email|escape}">{ts}Tester{/ts}</a>
          &nbsp;
          <a href="{crmURL p='civicrm/smtprouter/settings' q="action=delete&id=`$cfg.id`&key=`$csrfKey`&reset=1"}"
             class="button crm-button-type-cancel"
             onclick="return confirm('{ts escape='js'}Supprimer cette configuration ?{/ts}')">{ts}Supprimer{/ts}</a>
        </td>
      </tr>
    {/foreach}
    </tbody>
  </table>
  {else}
    <div class="messages status">
      {ts}Aucune configuration SMTP. Cliquez sur « Ajouter » pour en créer une.{/ts}
    </div>
  {/if}

  <div id="smtprouter-test-result" class="messages" style="display:none;margin-top:10px;"></div>

  <div class="crm-submit-buttons" style="margin-top:12px;">
    <a href="{crmURL p='civicrm/smtprouter/settings' q='action=add&reset=1'}"
       class="button crm-button-type-submit">{ts}+ Ajouter une configuration{/ts}</a>
  </div>

  {* ------------------------------------------------------------------ *}
  {* Add / Edit form — shown only when action != list *}
  {* ------------------------------------------------------------------ *}
  {if $action != 'list'}
  <hr/>
  <h3>{if $action == 'edit'}{ts}Modifier la configuration{/ts}{else}{ts}Nouvelle configuration SMTP{/ts}{/if}</h3>

  <div class="crm-section">
    <div class="label">{$form.from_email.label}</div>
    <div class="content">{$form.from_email.html}
      <span class="description">{ts}L'adresse exacte utilisée dans le champ From de vos emails CiviCRM.{/ts}</span>
    </div>
    <div class="clear"></div>
  </div>

  <div class="crm-section">
    <div class="label">{$form.smtp_host.label}</div>
    <div class="content">{$form.smtp_host.html}</div>
    <div class="clear"></div>
  </div>

  <div class="crm-section">
    <div class="label">{$form.smtp_port.label}</div>
    <div class="content">{$form.smtp_port.html}</div>
    <div class="clear"></div>
  </div>

  <div class="crm-section">
    <div class="label">{$form.smtp_security.label}</div>
    <div class="content">{$form.smtp_security.html}</div>
    <div class="clear"></div>
  </div>

  <div class="crm-section">
    <div class="label">{$form.smtp_auth.label}</div>
    <div class="content">{$form.smtp_auth.html}</div>
    <div class="clear"></div>
  </div>

  <div class="crm-section">
    <div class="label">{$form.smtp_username.label}</div>
    <div class="content">{$form.smtp_username.html}</div>
    <div class="clear"></div>
  </div>

  <div class="crm-section">
    <div class="label">{$form.smtp_password.label}</div>
    <div class="content">{$form.smtp_password.html}
      {if $action == 'edit'}
        <span class="description">{ts}Laissez vide pour conserver le mot de passe actuel.{/ts}</span>
      {/if}
    </div>
    <div class="clear"></div>
  </div>

  <div class="crm-section">
    <div class="label">{$form.is_active.label}</div>
    <div class="content">{$form.is_active.html}</div>
    <div class="clear"></div>
  </div>

  {if isset($form.id)}{$form.id.html}{/if}

  <div class="crm-submit-buttons">
    {include file="CRM/common/formButtons.tpl" location="bottom"}
    <a href="{$cancelURL}" class="button crm-button-type-cancel">{ts}Annuler{/ts}</a>
  </div>
  {/if}

</div>{* .crm-block *}

{literal}
<script type="text/javascript">
(function($) {
  $(document).ready(function() {
    $('.smtprouter-test-btn').on('click', function(e) {
      e.preventDefault();
      var id    = $(this).data('id');
      var email = $(this).data('email');
      var $res  = $('#smtprouter-test-result');
      $res.removeClass('success error').text('Test en cours pour ' + email + '…').show();

      $.ajax({
        url: CRM.url('civicrm/smtprouter/test', { id: id, reset: 1 }),
        dataType: 'json',
        success: function(data) {
          if (data.ok) {
            $res.addClass('success').text('✅ ' + data.message);
          } else {
            $res.addClass('error').text('❌ ' + data.message);
          }
        },
        error: function() {
          $res.addClass('error').text('❌ Erreur de communication avec le serveur.');
        }
      });
    });
  });
})(CRM.$);
</script>
{/literal}
