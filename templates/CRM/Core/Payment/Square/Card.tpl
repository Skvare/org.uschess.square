{*
 * Billing block template for Square Web Payments.
 *
 * The inline <script> is a fallback for Drupal Webforms where
 * CRM_Core_Resources::addSetting() responses are not always processed
 * before square.js runs. civicrmSquareHandleReload() checks this var first.
 *
 * The #crm-payment-js-billing-form-container wrapper is required by
 * CRM.squarePayment.getBillingForm() to locate the parent <form> element.
 *}
{literal}
<script type="text/javascript">
  CRM.$(function($) {
    if (typeof CRM.vars.orgUschessSquare === 'undefined') {
      CRM.vars.orgUschessSquare = {/literal}{$squareJSVarsJson}{literal};
    }
  });
</script>
{/literal}
{crmScope extensionKey='org.uschess.square'}
<div id="crm-payment-js-billing-form-container" class="square-payment-container">
  <div id="square-card-container" style="display:none;"></div>
  <div id="square-card-errors" role="alert" class="crm-error messages error" style="display:none;"></div>
</div>
{/crmScope}
