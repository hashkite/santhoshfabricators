<?php

/**
 * @file
 * Drush script to import project site, POs, RA Bills, and Invoices with payment tracking
 * from THERMAX_ENVIRO_ 25-26_Jamnagar.xlsx.
 *
 * Usage: ddev drush php:script web/modules/custom/ra_bill_manager/scripts/import_jamnagar_excel.php
 */

use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;
use PhpOffice\PhpSpreadsheet\IOFactory;

echo "=== Jamnagar Excel Data Import Script ===\n\n";

// 1. Locate the Excel file in the container
$app_root = \Drupal::root();
$filepath = $app_root . '/../THERMAX_ENVIRO_ 25-26_Jamnagar.xlsx';

if (!file_exists($filepath)) {
  $filepath = './THERMAX_ENVIRO_ 25-26_Jamnagar.xlsx';
}
if (!file_exists($filepath)) {
  $filepath = '../THERMAX_ENVIRO_ 25-26_Jamnagar.xlsx';
}

if (!file_exists($filepath)) {
  echo "ERROR: Excel file not found. Tried paths in DDEV container.\n";
  return;
}

echo "Loading $filepath...\n";
$spreadsheet = IOFactory::load($filepath);
$sheet = $spreadsheet->getSheetByName('Sheet1');

if (!$sheet) {
  echo "ERROR: Sheet1 not found in Excel file.\n";
  return;
}

// 2. Retrieve existing RIL Jamnagar project or create it
$node_storage = \Drupal::entityTypeManager()->getStorage('node');
$project_nids = $node_storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('type', 'project_sites')
  ->condition('title', 'RIL Jamnagar')
  ->execute();

if (!empty($project_nids)) {
  $project_nid = reset($project_nids);
  $project_node = $node_storage->load($project_nid);
  echo "Found existing Project Site: RIL Jamnagar (nid: $project_nid)\n";
} else {
  $project_node = Node::create([
    'type' => 'project_sites',
    'title' => 'RIL Jamnagar',
    'field_project_code' => 'PRJ-2025-0003',
    'field_client' => 'THERMAX ENVIRO',
    'field_status' => 'active',
    'field_start_date' => '2025-04-01',
    'field_end_date' => '2026-03-31',
    'field_executing_office_name' => 'SANTHOSH FABRICATORS PVT. LTD.',
    'field_executing_office_gst_numbe' => '24AAQCS3102E1ZP',
    'field_executing_office_address' => [
      'country_code' => 'IN',
      'administrative_area' => 'GJ',
      'locality' => 'Sikka Jamnagar',
      'postal_code' => '361141',
      'address_line1' => 'PLOT NO 50/19, TIRUPATI PARK',
      'address_line2' => 'SIKKA',
    ],
  ]);
  $project_node->save();
  $project_nid = $project_node->id();
  echo "Created new Project Site: RIL Jamnagar (nid: $project_nid)\n";
}

// 3. Read spreadsheet rows 4 to 12
$rows_data = [];
for ($row = 4; $row <= 12; $row++) {
  $sr_no = $sheet->getCell('A' . $row)->getCalculatedValue();
  $po_no = trim((string) $sheet->getCell('B' . $row)->getCalculatedValue());
  $invoice_date_raw = $sheet->getCell('C' . $row)->getCalculatedValue();
  $invoice_no = trim((string) $sheet->getCell('D' . $row)->getCalculatedValue());
  $invoice_amount = (float) $sheet->getCell('E' . $row)->getCalculatedValue();
  $basic_amount = (float) $sheet->getCell('F' . $row)->getCalculatedValue();
  $gst_amount = (float) $sheet->getCell('G' . $row)->getCalculatedValue();
  
  $tds_val = $sheet->getCell('H' . $row)->getCalculatedValue();
  $tds = $tds_val !== null ? (float) $tds_val : 0.0;
  
  $retention_val = $sheet->getCell('I' . $row)->getCalculatedValue();
  $retention = $retention_val !== null ? (float) $retention_val : 0.0;
  
  $net_expected = (float) $sheet->getCell('J' . $row)->getCalculatedValue();
  
  $cheque_received_val = $sheet->getCell('K' . $row)->getCalculatedValue();
  $cheque_received = $cheque_received_val !== null ? (float) $cheque_received_val : 0.0;
  
  $received_date_raw = $sheet->getCell('L' . $row)->getCalculatedValue();
  $remarks = trim((string) $sheet->getCell('M' . $row)->getCalculatedValue());
  $description = trim((string) $sheet->getCell('N' . $row)->getCalculatedValue());
  
  // Format Date from d.m.y to Y-m-d
  $invoice_date = null;
  if (!empty($invoice_date_raw)) {
    $parts = explode('.', $invoice_date_raw);
    if (count($parts) === 3) {
      $day = str_pad($parts[0], 2, '0', STR_PAD_LEFT);
      $month = str_pad($parts[1], 2, '0', STR_PAD_LEFT);
      $year_short = $parts[2];
      $year = strlen($year_short) === 2 ? '20' . $year_short : $year_short;
      $invoice_date = "$year-$month-$day";
    }
  }
  
  $rows_data[] = [
    'sr_no' => $sr_no,
    'po_no' => $po_no,
    'invoice_date' => $invoice_date ?: date('Y-m-d'),
    'invoice_no' => $invoice_no,
    'invoice_amount' => $invoice_amount,
    'basic_amount' => $basic_amount,
    'gst_amount' => $gst_amount,
    'tds' => $tds,
    'retention' => $retention,
    'net_expected' => $net_expected,
    'cheque_received' => $cheque_received,
    'received_date_raw' => $received_date_raw,
    'remarks' => $remarks,
    'description' => $description,
  ];
}

// 4. Group data by PO to calculate PO totals and create PO nodes
$po_totals = [];
foreach ($rows_data as $data) {
  $po_no = $data['po_no'];
  if (!isset($po_totals[$po_no])) {
    $po_totals[$po_no] = [
      'basic' => 0.0,
      'total' => 0.0,
    ];
  }
  $po_totals[$po_no]['basic'] += $data['basic_amount'];
  $po_totals[$po_no]['total'] += $data['invoice_amount'];
}

$po_nodes = [];
foreach ($po_totals as $po_no => $totals) {
  $po_nids = $node_storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', 'purchase_order')
    ->condition('field_po_number', $po_no)
    ->execute();
    
  if (!empty($po_nids)) {
    $po_nid = reset($po_nids);
    $po_node = $node_storage->load($po_nid);
    $po_node->set('field_basic_amount', number_format($totals['basic'], 2, '.', ''));
    $po_node->set('field_amount_gst', number_format($totals['total'], 2, '.', ''));
    $po_node->set('field_po_status', 'completed');
    $po_node->set('field_project', $project_nid);
    $po_node->save();
    echo "Updated existing PO: $po_no (nid: $po_nid) with basic: {$totals['basic']}, total: {$totals['total']}\n";
  } else {
    $po_node = Node::create([
      'type' => 'purchase_order',
      'title' => "PO $po_no",
      'field_po_number' => $po_no,
      'field_po_status' => 'completed',
      'field_project' => $project_nid,
      'field_company_name' => 'THERMAX ENVIRO',
      'field_po_date' => '2025-04-01',
      'field_basic_amount' => number_format($totals['basic'], 2, '.', ''),
      'field_amount_gst' => number_format($totals['total'], 2, '.', ''),
    ]);
    $po_node->save();
    $po_nid = $po_node->id();
    echo "Created new PO: $po_no (nid: $po_nid) with basic: {$totals['basic']}, total: {$totals['total']}\n";
  }
  $po_nodes[$po_no] = $po_nid;
}

// 5. Loop over rows to create RA Bills and Invoices
foreach ($rows_data as $data) {
  $po_no = $data['po_no'];
  $po_nid = $po_nodes[$po_no];
  $invoice_no = $data['invoice_no'];
  
  // Extract RA number
  $ra_num = 1;
  if (preg_match('/RA\s*0?(\d+)/i', $data['description'], $matches)) {
    $ra_num = intval($matches[1]);
  }
  
  // A. Create/update RA Bill Node
  $ra_bill_nids = $node_storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', 'ra_bill')
    ->condition('field_purchase_order', $po_nid)
    ->condition('field_ra_bill_number', $ra_num)
    ->execute();
    
  if (!empty($ra_bill_nids)) {
    $ra_bill_nid = reset($ra_bill_nids);
    $ra_bill_node = $node_storage->load($ra_bill_nid);
    $ra_bill_node->set('field_basic_amount', number_format($data['basic_amount'], 2, '.', ''));
    $ra_bill_node->set('field_total_amount', number_format($data['invoice_amount'], 2, '.', ''));
    $ra_bill_node->set('field_validation_status', 'verified');
    $ra_bill_node->save();
    echo "  Updated RA Bill: RA-$ra_num for PO $po_no (nid: $ra_bill_nid)\n";
  } else {
    $ra_bill_node = Node::create([
      'type' => 'ra_bill',
      'title' => "RA Bill $ra_num - RIL Jamnagar - PO $po_no",
      'field_purchase_order' => $po_nid,
      'field_project' => $project_nid,
      'field_ra_bill_number' => $ra_num,
      'field_basic_amount' => number_format($data['basic_amount'], 2, '.', ''),
      'field_total_amount' => number_format($data['invoice_amount'], 2, '.', ''),
      'field_validation_status' => 'verified',
    ]);
    $ra_bill_node->save();
    $ra_bill_nid = $ra_bill_node->id();
    echo "  Created RA Bill: RA-$ra_num for PO $po_no (nid: $ra_bill_nid)\n";
  }
  
  // B. Determine Paid amount and Payment History
  // We distribute the combined 522000 payment of Row 5/6:
  $actual_paid = 0.0;
  $payment_date = '';
  
  if ($invoice_no === 'GJ/2025-26/28') {
    $actual_paid = 1094344.0; // Row 4 (net expected)
    $payment_date = '2025-10-29';
  } elseif ($invoice_no === 'GJ/2025-26/29') {
    $actual_paid = 191400.0;  // Row 5 (basic 165000 + GST 29700 - 3300 TDS = 191400)
    $payment_date = '2025-10-01';
  } elseif ($invoice_no === 'GJ/2025-26/30') {
    $actual_paid = 330600.0;  // Row 6 (basic 285000 + GST 51300 - 5700 TDS = 330600)
    $payment_date = '2025-10-01';
  } elseif ($invoice_no === 'GJ/2025-26/39') {
    $actual_paid = 625612.0;  // Row 7 (net expected)
    $payment_date = '2025-11-25';
  } elseif ($invoice_no === 'GJ/2025-26/40') {
    $actual_paid = 399113.92; // Row 8 (net expected - GST held)
    $payment_date = '2025-12-22';
  } elseif ($invoice_no === 'GJ/2025-26/41') {
    $actual_paid = 135700.44; // Row 9 (net expected)
    $payment_date = '2025-11-25';
  } elseif ($invoice_no === 'GJ/2025-26/50') {
    $actual_paid = 0.0;       // Row 10 (unpaid)
  } elseif ($invoice_no === 'GJ/2025-26/51') {
    $actual_paid = 0.0;       // Row 11 (unpaid)
  } elseif ($invoice_no === 'GJ/2025-26/52') {
    $actual_paid = 124229.93; // Row 12 (net expected - GST held)
    $payment_date = '2025-12-22';
  }
  
  $net_receivable_with_gst = $data['invoice_amount'] - $data['tds'] - $data['retention'];
  $remaining_due = max(0.0, $net_receivable_with_gst - $actual_paid);
  
  // Status
  $status = 'pending';
  if ($actual_paid <= 0.01) {
    $status = 'pending';
  } elseif ($remaining_due > 0.01) {
    $status = 'partial';
  } else {
    $status = 'paid';
  }
  
  // Payment logs paragraphs
  $paragraph_entities = [];
  if ($actual_paid > 0) {
    $p = Paragraph::create([
      'type' => 'payment_history',
      'field_date' => $payment_date,
      'field_amount' => number_format($actual_paid, 2, '.', ''),
      'field_description' => 'Imported from Jamnagar Excel sheet. Total cheque amount received: ' . ($invoice_no === 'GJ/2025-26/29' || $invoice_no === 'GJ/2025-26/30' ? '₹522,000.00 (combined)' : '₹' . number_format($data['cheque_received'], 2)) . '. Remarks: ' . $data['remarks'],
      'field_recorded_by' => 'System',
    ]);
    $p->save();
    $paragraph_entities[] = [
      'target_id' => $p->id(),
      'target_revision_id' => $p->getRevisionId(),
    ];
  }
  
  // C. Create/update Invoice Node
  $invoice_nids = $node_storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', 'invoice')
    ->condition('field_invoice_no', $invoice_no)
    ->execute();
    
  if (!empty($invoice_nids)) {
    $invoice_nid = reset($invoice_nids);
    $invoice_node = $node_storage->load($invoice_nid);
    echo "  Updating existing Invoice: $invoice_no (nid: $invoice_nid)\n";
    // Clean up existing payment history paragraphs to avoid orphans
    if (!$invoice_node->get('field_payment_history')->isEmpty()) {
      foreach ($invoice_node->get('field_payment_history')->referencedEntities() as $old_p) {
        $old_p->delete();
      }
    }
  } else {
    $invoice_node = Node::create([
      'type' => 'invoice',
      'field_invoice_no' => $invoice_no,
    ]);
    echo "  Creating new Invoice: $invoice_no\n";
  }
  
  $invoice_node->setTitle("Invoice for RA Bill RA-$ra_num");
  $invoice_node->set('field_invoice_date', $data['invoice_date']);
  $invoice_node->set('field_purchase_order', $po_nid);
  $invoice_node->set('field_project', $project_nid);
  $invoice_node->set('field_basic_value', number_format($data['basic_amount'], 2, '.', ''));
  $invoice_node->set('field_basic_value_gst', number_format($data['invoice_amount'], 2, '.', ''));
  $invoice_node->set('field_tds', number_format($data['tds'], 2, '.', ''));
  $invoice_node->set('field_retention', number_format($data['retention'], 2, '.', ''));
  $invoice_node->set('field_remarks', $data['remarks']);
  $invoice_node->set('field_ra_bill', $ra_bill_nid);
  $invoice_node->set('field_paid_amount', number_format($actual_paid, 2, '.', ''));
  $invoice_node->set('field_payment_status', $status);
  $invoice_node->set('field_payment_history', $paragraph_entities);
  
  // Set 30 days due date as default
  $due_time = strtotime($data['invoice_date'] . ' +30 days');
  $invoice_node->set('field_payment_due_date', date('Y-m-d', $due_time));
  
  $invoice_node->save();
  echo "    Saved Invoice nid: {$invoice_node->id()} with status: $status, paid: $actual_paid, remaining: $remaining_due\n";
}

echo "\n=== Import Completed Successfully! ===\n";
