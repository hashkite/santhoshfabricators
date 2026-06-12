<?php

/**
 * @file
 * Drush script to renumber all RA Bills per Purchase Order starting from 1.
 *
 * Usage: ddev drush php:script web/modules/custom/ra_bill_manager/scripts/fix_ra_numbers.php
 */

use Drupal\node\Entity\Node;

echo "=== RA Bill Renumbering Script ===\n";
echo "Renumbering all RA Bills per Purchase Order starting from 1...\n\n";

$node_storage = \Drupal::entityTypeManager()->getStorage('node');

// 1. Get all RA bills grouped by Purchase Order.
$all_bill_ids = $node_storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('type', 'ra_bill')
  ->execute();

if (empty($all_bill_ids)) {
  echo "No RA Bills found.\n";
  return;
}

$all_bills = $node_storage->loadMultiple($all_bill_ids);

// Group bills by PO.
$po_groups = [];
$orphan_bills = [];

foreach ($all_bills as $bill) {
  $po_id = !$bill->get('field_purchase_order')->isEmpty() ? $bill->get('field_purchase_order')->target_id : NULL;
  if ($po_id) {
    $po_groups[$po_id][] = $bill;
  }
  else {
    $orphan_bills[] = $bill;
  }
}

echo "Found " . count($all_bills) . " RA Bills across " . count($po_groups) . " Purchase Orders.\n";
if (!empty($orphan_bills)) {
  echo "  (" . count($orphan_bills) . " bills have no PO and will be skipped)\n";
}
echo "\n";

// 2. For each PO, sort bills by existing RA number (or created date) and renumber from 1.
$total_updated = 0;

foreach ($po_groups as $po_id => $bills) {
  // Sort by existing RA bill number, then by node creation date as tiebreaker.
  usort($bills, function ($a, $b) {
    $num_a = intval($a->get('field_ra_bill_number')->value ?? 0);
    $num_b = intval($b->get('field_ra_bill_number')->value ?? 0);
    if ($num_a === $num_b) {
      return intval($a->getCreatedTime()) <=> intval($b->getCreatedTime());
    }
    return $num_a <=> $num_b;
  });

  $po_node = $node_storage->load($po_id);
  $po_label = $po_node ? $po_node->label() : "PO #$po_id";
  echo "PO: $po_label (nid: $po_id) — " . count($bills) . " bills\n";

  $seq = 1;
  foreach ($bills as $bill) {
    $old_num = intval($bill->get('field_ra_bill_number')->value ?? 0);
    if ($old_num !== $seq) {
      $bill->set('field_ra_bill_number', $seq);
      $bill->save();
      echo "  [UPDATED] Bill nid:{$bill->id()} — RA-{$old_num} → RA-{$seq}\n";
      $total_updated++;
    }
    else {
      echo "  [OK]      Bill nid:{$bill->id()} — RA-{$seq} (no change)\n";
    }
    $seq++;
  }
}

echo "\n=== Done. Updated $total_updated bills. ===\n";
