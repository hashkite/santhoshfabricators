<?php

namespace Drupal\ra_bill_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for Vendor Billing Report.
 */
class VendorBillingController extends ControllerBase {

  /**
   * Renders the Vendor Billing Report.
   */
  public function report() {
    $vendor_storage = $this->entityTypeManager()->getStorage('node');
    $bill_storage = $this->entityTypeManager()->getStorage('node');

    $vendor_ids = $vendor_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'vendor')
      ->execute();

    $vendors = $vendor_storage->loadMultiple($vendor_ids);

    $rows = [];
    foreach ($vendors as $vendor) {
      $bill_query = $bill_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'ra_bill')
        ->condition('field_vendor', $vendor->id());
      
      $bill_ids = $bill_query->execute();
      
      $total_billed = 0.0;
      $total_approved_amount = 0.0;
      $approved_count = 0;
      $total_pending_amount = 0.0;
      $pending_count = 0;

      if (!empty($bill_ids)) {
        $bills = $bill_storage->loadMultiple($bill_ids);
        foreach ($bills as $bill) {
          $amt = floatval($bill->get('field_total_amount')->value ?? 0.0);
          
          $state = 'draft';
          if ($bill->hasField('moderation_state') && !$bill->get('moderation_state')->isEmpty()) {
            $state = $bill->get('moderation_state')->value;
          }

          $total_billed += $amt;

          if ($state === 'published' || $state === 'paid') {
            $total_approved_amount += $amt;
            $approved_count++;
          }
          else {
            $total_pending_amount += $amt;
            $pending_count++;
          }
        }
      }

      $rows[] = [
        'vendor' => $vendor->toLink()->toString(),
        'total_billed' => '₹' . number_format($total_billed, 2),
        'approved_bills' => $approved_count . ' (₹' . number_format($total_approved_amount, 2) . ')',
        'pending_bills' => $pending_count . ' (₹' . number_format($total_pending_amount, 2) . ')',
      ];
    }

    $header = [
      'vendor' => $this->t('Vendor Name'),
      'total_billed' => $this->t('Total Billed Amount'),
      'approved_bills' => $this->t('Approved Bills (Amount)'),
      'pending_bills' => $this->t('Pending Approvals (Amount)'),
    ];

    $build = [];

    // Add CSS library styling if needed
    $build['report_title'] = [
      '#markup' => '<h2>' . $this->t('Vendor Billing Stats') . '</h2>',
    ];

    $build['report_table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No vendor data found.'),
    ];

    return $build;
  }

}
