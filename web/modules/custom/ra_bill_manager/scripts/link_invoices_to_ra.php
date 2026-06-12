<?php

/**
 * @file
 * Drush script to link existing invoices to their source RA Bills.
 *
 * Usage: ddev drush php:script web/modules/custom/ra_bill_manager/scripts/link_invoices_to_ra.php
 */

use Drupal\node\Entity\Node;

echo "=== Link Invoices to RA Bills ===\n\n";

$node_storage = \Drupal::entityTypeManager()->getStorage('node');

// Get all invoices.
$invoice_ids = $node_storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('type', 'invoice')
  ->execute();

if (empty($invoice_ids)) {
  echo "No invoices found.\n";
  return;
}

$invoices = $node_storage->loadMultiple($invoice_ids);
echo "Found " . count($invoices) . " invoices.\n\n";

$linked = 0;
$already_linked = 0;
$not_found = 0;

foreach ($invoices as $invoice) {
  // Skip if already linked.
  if ($invoice->hasField('field_ra_bill') && !$invoice->get('field_ra_bill')->isEmpty()) {
    $ra_bill = $invoice->get('field_ra_bill')->entity;
    echo "[SKIP]  Invoice nid:{$invoice->id()} '{$invoice->label()}' — already linked to RA Bill nid:{$ra_bill->id()}\n";
    $already_linked++;
    continue;
  }

  $po_id = $invoice->hasField('field_purchase_order') && !$invoice->get('field_purchase_order')->isEmpty()
    ? $invoice->get('field_purchase_order')->target_id : NULL;

  if (!$po_id) {
    echo "[SKIP]  Invoice nid:{$invoice->id()} '{$invoice->label()}' — no PO reference\n";
    $not_found++;
    continue;
  }

  // Try to match RA Bill by parsing the invoice title for RA number.
  $title = $invoice->label();
  $ra_num = NULL;

  if (preg_match('/RA-(\d+)/', $title, $matches)) {
    $ra_num = intval($matches[1]);
  } elseif (preg_match('/RA Bill\s*#?(\d+)/', $title, $matches)) {
    $ra_num = intval($matches[1]);
  }

  if ($ra_num !== NULL) {
    // Find RA Bill with this number for this PO.
    $ra_bill_ids = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'ra_bill')
      ->condition('field_purchase_order', $po_id)
      ->condition('field_ra_bill_number', $ra_num)
      ->execute();

    if (!empty($ra_bill_ids)) {
      $ra_bill_id = reset($ra_bill_ids);
      $invoice->set('field_ra_bill', $ra_bill_id);
      $invoice->save();
      echo "[LINKED] Invoice nid:{$invoice->id()} '{$invoice->label()}' → RA Bill nid:{$ra_bill_id} (RA-{$ra_num})\n";
      $linked++;
      continue;
    }
  }

  // Fallback: try to match by amount comparison.
  $inv_basic = floatval($invoice->get('field_basic_value')->value ?? 0);
  $ra_bill_ids = $node_storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', 'ra_bill')
    ->condition('field_purchase_order', $po_id)
    ->execute();

  if (!empty($ra_bill_ids)) {
    $ra_bills = $node_storage->loadMultiple($ra_bill_ids);
    foreach ($ra_bills as $ra_bill) {
      $ra_basic = floatval($ra_bill->get('field_basic_amount')->value ?? 0);
      if (abs($ra_basic - $inv_basic) < 0.01 && $inv_basic > 0) {
        $invoice->set('field_ra_bill', $ra_bill->id());
        $invoice->save();
        $ra_no = $ra_bill->get('field_ra_bill_number')->value;
        echo "[LINKED] Invoice nid:{$invoice->id()} '{$invoice->label()}' → RA Bill nid:{$ra_bill->id()} (RA-{$ra_no}) [matched by amount]\n";
        $linked++;
        continue 2;
      }
    }
  }

  echo "[SKIP]  Invoice nid:{$invoice->id()} '{$invoice->label()}' — no matching RA Bill found\n";
  $not_found++;
}

echo "\n=== Done. Linked: {$linked}, Already linked: {$already_linked}, Not found: {$not_found} ===\n";
