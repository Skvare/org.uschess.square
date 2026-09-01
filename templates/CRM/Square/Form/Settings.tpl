<div class="crm-block crm-form-block crm-square-settings-form-block">
  <div class="help">
    {ts}When enabled, verbose Square webhook processing details (which event was received, which record was found/created/updated) are written to the CiviCRM debug log. Leave disabled in normal operation.{/ts}
  </div>
  <table class="form-layout">
    <tr class="crm-square-settings-form-block-debug_logging">
      <td class="label">{$form.square_ipn_debug_logging.label}</td>
      <td>{$form.square_ipn_debug_logging.html}</td>
    </tr>
  </table>
  <div class="crm-submit-buttons">
    {include file="CRM/common/formButtons.tpl" location="bottom"}
  </div>
</div>
