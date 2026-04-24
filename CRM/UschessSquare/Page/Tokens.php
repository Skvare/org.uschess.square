<?php

use CRM_UschessSquare_ExtensionUtil as E;

/**
 * Contact-level Square Tokens tab.
 *
 * Displays stored Square customer_id, card_ids, subscription_ids.
 */
class CRM_UschessSquare_Page_Tokens extends CRM_Core_Page {

  protected $contactId;

  public function run() {
    $this->contactId = CRM_Utils_Request::retrieve('contact_id', 'Positive', $this);
    if (!$this->contactId) {
      CRM_Core_Error::fatal('Missing contact ID.');
    }

    // Load contact.
    $contact = \Civi\Api4\Contact::get(FALSE)
      ->addSelect('id', 'display_name', 'email', 'custom.square_customer_id')
      ->addWhere('id', '=', $this->contactId)
      ->setLimit(1)
      ->execute()
      ->first();

    // Load recurring contributions for subscription mapping.
    $recur = \Civi\Api4\ContributionRecur::get(FALSE)
      ->addSelect('id', 'amount', 'currency', 'processor_id', 'contribution_status_id')
      ->addWhere('contact_id', '=', $this->contactId)
      ->execute()
      ->getArrayCopy();

    // Load last card_id if stored in custom field.
    $card = NULL;
    try {
      $card = \Civi\Api4\Contact::get(FALSE)
        ->addSelect('custom.square_card_id')
        ->addWhere('id', '=', $this->contactId)
        ->execute()
        ->first()['custom.square_card_id'] ?? NULL;
    }
    catch (\Exception $e) {
      // Safe fallback.
    }

    // Assign for template.
    $this->assign('contact', $contact);
    $this->assign('recur', $recur);
    $this->assign('card', $card);

    parent::run();
  }
}
