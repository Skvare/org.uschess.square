<?php

use CRM_Square_ExtensionUtil as E;

/**
 * Class CRM_Square_Form_Settings
 *
 * Administer > System Settings > Square Settings.
 *
 * Lets an admin toggle verbose Square webhook debug logging (event
 * dispatch, record lookups, created/updated record IDs) without needing
 * shell/API access. See CRM_Core_Payment_SquareDebugLogger.
 */
class CRM_Square_Form_Settings extends CRM_Core_Form {

  const SETTING_NAME = 'square_ipn_debug_logging';

  public function buildQuickForm() {
    CRM_Utils_System::setTitle(E::ts('Square Settings'));
    $this->addYesNo(self::SETTING_NAME, E::ts('Enable Square IPN Debug Logging'));
    $this->addButtons([
      [
        'type' => 'submit',
        'name' => E::ts('Save'),
        'isDefault' => TRUE,
      ],
    ]);
    parent::buildQuickForm();
  }

  public function setDefaultValues() {
    $defaults = parent::setDefaultValues();
    $defaults[self::SETTING_NAME] = (bool) Civi::settings()->get(self::SETTING_NAME);
    return $defaults;
  }

  public function postProcess() {
    $values = $this->exportValues();
    Civi::settings()->set(self::SETTING_NAME, !empty($values[self::SETTING_NAME]));
    CRM_Core_Session::setStatus(E::ts('Square settings saved.'), E::ts('Saved'), 'success');
    parent::postProcess();
  }

}
