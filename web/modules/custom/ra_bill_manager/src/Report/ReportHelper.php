<?php

namespace Drupal\ra_bill_manager\Report;

use Drupal\Core\Database\Connection;

/**
 * Service to helper with RA Bill reports.
 */
class ReportHelper {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a new ReportHelper.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * Gets vendor-wise billing stats.
   *
   * Includes total billed, approved, and pending approval amounts.
   */
  public function getVendorBillingStats() {
    // Queries can be used programmatically in custom dashboards if desired
    $query = $this->database->select('node_field_data', 'n');
    $query->join('node__field_vendor', 'v', 'n.nid = v.entity_id');
    $query->join('node__field_total_amount', 't', 'n.nid = t.entity_id');
    $query->leftJoin('node__field_workflow_status', 'w', 'n.nid = w.entity_id');
    
    $query->fields('v', ['field_vendor_target_id']);
    $query->addExpression('SUM(CAST(t.field_total_amount_value AS DECIMAL(15,2)))', 'total_billed');
    $query->addExpression('SUM(CASE WHEN w.field_workflow_status_value = \'approved\' THEN CAST(t.field_total_amount_value AS DECIMAL(15,2)) ELSE 0 END)', 'total_approved');
    $query->addExpression('SUM(CASE WHEN w.field_workflow_status_value IN (\'draft\', \'engineer_review\', \'manager_review\', \'finance_review\') THEN CAST(t.field_total_amount_value AS DECIMAL(15,2)) ELSE 0 END)', 'total_pending');
    $query->groupBy('v.field_vendor_target_id');

    return $query->execute()->fetchAll();
  }

  /**
   * Calculates progress percentage for a BOQ item.
   */
  public function calculateProgress($cumulative_qty, $approved_qty) {
    if ($approved_qty <= 0) {
      return 0.0;
    }
    return ($cumulative_qty / $approved_qty) * 100;
  }

}
