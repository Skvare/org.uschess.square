<?php

use Civi\Api4\CustomField;
use Civi\Api4\CustomGroup;
use Civi\Api4\PaymentProcessor;

/**
 * Collection of upgrade steps for org.uschess.square.
 */
class CRM_Square_Upgrader extends CRM_Extension_Upgrader_Base {

  /**
   * Runs on fresh installs only.
   */
  public function install(): void {
    $this->createSquareCustomerMapTable();
  }

  /**
   * Runs the next time an already-installed copy of this extension checks
   * for upgrades. Creates square_customer_map for sites that had the
   * extension installed before this table existed, backfills it from the
   * legacy square_data.square_customer_id custom field, then removes that
   * now-superseded custom field group.
   */
  public function upgrade_1000(): bool {
    $this->createSquareCustomerMapTable();
    $this->backfillSquareCustomerMapFromCustomField();
    return TRUE;
  }

  /**
   * Runs on uninstall. Drops the table this extension owns.
   */
  public function uninstall(): void {
    CRM_Core_DAO::executeQuery('DROP TABLE IF EXISTS `square_customer_map`');
  }

  /**
   * Create the (contact_id, payment_processor_id) -> square_customer_id
   * mapping table, if it doesn't already exist.
   */
  protected function createSquareCustomerMapTable(): void {
    CRM_Core_DAO::executeQuery(<<<SQL
      CREATE TABLE IF NOT EXISTS `square_customer_map` (
        `id` int unsigned NOT NULL AUTO_INCREMENT,
        `contact_id` int unsigned NOT NULL,
        `payment_processor_id` int unsigned NOT NULL,
        `square_customer_id` varchar(255) NOT NULL,
        `created_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `UI_contact_processor` (`contact_id`, `payment_processor_id`),
        KEY `IDX_square_customer_id` (`square_customer_id`),
        CONSTRAINT `FK_square_customer_map_contact_id` FOREIGN KEY (`contact_id`)
          REFERENCES `civicrm_contact` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
        CONSTRAINT `FK_square_customer_map_payment_processor_id` FOREIGN KEY (`payment_processor_id`)
          REFERENCES `civicrm_payment_processor` (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
      SQL);
  }

  /**
   * One-time migration: copy any existing square_data.square_customer_id
   * custom-field values into square_customer_map (scoped to the live Square
   * payment processor — sandbox customer IDs aren't recoverable from that
   * single, processor-agnostic field), then remove the now-unused
   * square_data custom field group entirely.
   */
  protected function backfillSquareCustomerMapFromCustomField(): void {
    $field = CustomField::get(FALSE)
      ->addWhere('custom_group_id:name', '=', 'square_data')
      ->addWhere('name', '=', 'square_customer_id')
      ->addSelect('id', 'column_name', 'custom_group_id.table_name')
      ->execute()
      ->first();

    if (empty($field)) {
      // Nothing to migrate (fresh install, or already migrated).
      return;
    }

    $table = $field['custom_group_id.table_name'];
    $column = $field['column_name'];

    $liveProcessorId = PaymentProcessor::get(FALSE)
      ->addWhere('payment_processor_type_id:name', '=', 'Square')
      ->addWhere('is_test', '=', FALSE)
      ->addSelect('id')
      ->execute()
      ->first()['id'] ?? NULL;

    if (!empty($liveProcessorId)) {
      CRM_Core_DAO::executeQuery(
        "INSERT IGNORE INTO square_customer_map (contact_id, payment_processor_id, square_customer_id)
         SELECT entity_id, %1, `{$column}`
         FROM `{$table}`
         WHERE `{$column}` IS NOT NULL AND `{$column}` != ''",
        [1 => [$liveProcessorId, 'Integer']]
      );
    }

    // The square_data group (and any other fields on it) is now fully
    // superseded by square_customer_map — remove it.
    $group = CustomGroup::get(FALSE)
      ->addWhere('name', '=', 'square_data')
      ->addSelect('id')
      ->execute()
      ->first();
    if (!empty($group['id'])) {
      CustomField::delete(FALSE)
        ->addWhere('custom_group_id', '=', $group['id'])
        ->execute();
      CustomGroup::delete(FALSE)
        ->addWhere('id', '=', $group['id'])
        ->execute();
    }
  }

}
