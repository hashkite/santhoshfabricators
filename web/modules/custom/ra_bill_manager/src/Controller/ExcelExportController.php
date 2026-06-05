<?php

namespace Drupal\ra_bill_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\Entity\Node;
use Symfony\Component\HttpFoundation\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * Controller to handle exporting RA Bills to styled Excel sheets.
 */
class ExcelExportController extends ControllerBase {

  /**
   * Generates and downloads the Excel file for all RA Bills (Bulk Report).
   */
  public function export() {
    $query = \Drupal::entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'ra_bill')
      ->sort('created', 'DESC');
    $nids = $query->execute();

    $bills = [];
    if (!empty($nids)) {
      $bills = Node::loadMultiple($nids);
    }

    return $this->generateBulkWorkbook($bills, 'RA_Bills_Detailed_Export_' . date('Ymd_His') . '.xlsx');
  }

  /**
   * Generates and downloads the Excel file for a single RA Bill (matching input format).
   */
  public function exportSingle($node) {
    if (is_numeric($node)) {
      $node = Node::load($node);
    }
    if (!$node || $node->bundle() !== 'ra_bill') {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }
    $ra_no = $node->get('field_ra_bill_number')->value ?? $node->id();
    $filename = 'RA_Bill_' . $ra_no . '_Export_' . date('Ymd_His') . '.xlsx';
    return $this->generateSingleWorkbook($node, $filename);
  }

  /**
   * Shared bulk workbook generator.
   */
  protected function generateBulkWorkbook(array $bills, $filename) {
    $spreadsheet = new Spreadsheet();

    // --- SHEET 1: SUMMARY ---
    $sheet1 = $spreadsheet->getActiveSheet();
    $sheet1->setTitle('RA Bills Summary');
    $sheet1->setShowGridlines(TRUE);

    // Style Definitions
    $headerStyle = [
      'font' => [
        'bold' => TRUE,
        'color' => ['rgb' => 'FFFFFF'],
        'size' => 11,
        'name' => 'Calibri',
      ],
      'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '1E293B'], // Slate Blue
      ],
      'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
      ],
      'borders' => [
        'allBorders' => [
          'borderStyle' => Border::BORDER_THIN,
          'color' => ['rgb' => 'CCCCCC'],
        ],
      ],
    ];

    $dataStyle = [
      'font' => [
        'size' => 10,
        'name' => 'Calibri',
      ],
      'borders' => [
        'allBorders' => [
          'borderStyle' => Border::BORDER_THIN,
          'color' => ['rgb' => 'E2E8F0'],
        ],
      ],
    ];

    $totalStyle = [
      'font' => [
        'bold' => TRUE,
        'size' => 11,
        'name' => 'Calibri',
      ],
      'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'F1F5F9'], // Light Slate Gray
      ],
      'borders' => [
        'top' => [
          'borderStyle' => Border::BORDER_THIN,
          'color' => ['rgb' => '94A3B8'],
        ],
        'bottom' => [
          'borderStyle' => Border::BORDER_DOUBLE,
          'color' => ['rgb' => '94A3B8'],
        ],
      ],
    ];

    // Sheet 1 Headers
    $headers1 = [
      'RA Bill No.',
      'Bill Number',
      'Vendor',
      'Project',
      'Bill Date',
      'Basic Amount',
      'CGST (9%)',
      'SGST (9%)',
      'Total Amount',
      'Validation Status',
      'Workflow Status',
    ];

    foreach ($headers1 as $colIdx => $headerText) {
      $colLetter = Coordinate::stringFromColumnIndex($colIdx + 1);
      $sheet1->setCellValue($colLetter . '1', $headerText);
    }
    $sheet1->getStyle('A1:K1')->applyFromArray($headerStyle);
    $sheet1->getRowDimension('1')->setRowHeight(28);

    // Populate Sheet 1 data
    $row = 2;
    $totalBasic = 0.0;
    $totalCgst = 0.0;
    $totalSgst = 0.0;
    $totalAmount = 0.0;

    foreach ($bills as $bill) {
      $project_node = !$bill->get('field_project')->isEmpty() ? $bill->get('field_project')->entity : NULL;
      $vendor_node = !$bill->get('field_vendor')->isEmpty() ? $bill->get('field_vendor')->entity : NULL;

      $ra_no = $bill->get('field_ra_bill_number')->value ?? '';
      $bill_no = $bill->get('field_bill_number')->value ?? '';
      $vendor = $vendor_node ? $vendor_node->label() : '';
      $project = $project_node ? $project_node->label() : '';
      
      $date_val = $bill->get('field_bill_date')->value;
      $date = $date_val ? date('d-M-Y', strtotime($date_val)) : '';

      $basic = floatval($bill->get('field_basic_amount')->value ?? 0.0);
      $cgst = floatval($bill->get('field_cgst')->value ?? 0.0);
      $sgst = floatval($bill->get('field_sgst')->value ?? 0.0);
      $total = floatval($bill->get('field_total_amount')->value ?? 0.0);

      $val_status_val = $bill->get('field_validation_status')->value;
      $val_status = $val_status_val === 'verified' ? 'Verified' : ($val_status_val === 'need_review' ? 'Need Review' : $val_status_val);

      $wf_state = $bill->get('moderation_state')->value ?? 'draft';
      $workflow = ucfirst(str_replace('_', ' ', $wf_state));

      $sheet1->setCellValue('A' . $row, $ra_no);
      $sheet1->setCellValue('B' . $row, $bill_no);
      $sheet1->setCellValue('C' . $row, $vendor);
      $sheet1->setCellValue('D' . $row, $project);
      $sheet1->setCellValue('E' . $row, $date);
      $sheet1->setCellValue('F' . $row, $basic);
      $sheet1->setCellValue('G' . $row, $cgst);
      $sheet1->setCellValue('H' . $row, $sgst);
      $sheet1->setCellValue('I' . $row, $total);
      $sheet1->setCellValue('J' . $row, $val_status);
      $sheet1->setCellValue('K' . $row, $workflow);

      // Formatting
      $sheet1->getStyle("A$row:K$row")->applyFromArray($dataStyle);
      $sheet1->getStyle("F$row:I$row")->getNumberFormat()->setFormatCode('"₹"#,##0.00');
      $sheet1->getStyle("A$row:B$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
      $sheet1->getStyle("E$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
      $sheet1->getStyle("J$row:K$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

      // Add to totals
      $totalBasic += $basic;
      $totalCgst += $cgst;
      $totalSgst += $sgst;
      $totalAmount += $total;

      $row++;
    }

    // Sheet 1 Totals Row
    $sheet1->setCellValue('A' . $row, 'Total');
    $sheet1->mergeCells("A$row:E$row");
    $sheet1->getStyle("A$row:E$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    
    $sheet1->setCellValue('F' . $row, $totalBasic);
    $sheet1->setCellValue('G' . $row, $totalCgst);
    $sheet1->setCellValue('H' . $row, $totalSgst);
    $sheet1->setCellValue('I' . $row, $totalAmount);

    $sheet1->getStyle("A$row:K$row")->applyFromArray($totalStyle);
    $sheet1->getStyle("F$row:I$row")->getNumberFormat()->setFormatCode('"₹"#,##0.00');

    // Auto-fit Columns for Sheet 1
    for ($col = 1; $col <= 11; $col++) {
      $colLetter = Coordinate::stringFromColumnIndex($col);
      $sheet1->getColumnDimension($colLetter)->setAutoSize(TRUE);
    }

    // --- SHEET 2: DETAILED ITEMS ---
    $sheet2 = $spreadsheet->createSheet();
    $sheet2->setTitle('Line Items Details');
    $sheet2->setShowGridlines(TRUE);

    $headerStyle2 = $headerStyle;
    $headerStyle2['fill']['startColor']['rgb'] = '0F766E'; // Deep Teal

    // Sheet 2 Headers
    $headers2 = [
      'RA Bill No.',
      'Bill Number',
      'Project',
      'Item Code',
      'Description',
      'UOM',
      'Rate',
      'Approved Qty',
      'Previous Qty',
      'Current Qty',
      'Cumulative Qty',
      'Amount Claimed',
      'Validation Status',
    ];

    foreach ($headers2 as $colIdx => $headerText) {
      $colLetter = Coordinate::stringFromColumnIndex($colIdx + 1);
      $sheet2->setCellValue($colLetter . '1', $headerText);
    }
    $sheet2->getStyle('A1:M1')->applyFromArray($headerStyle2);
    $sheet2->getRowDimension('1')->setRowHeight(28);

    // Populate Sheet 2 data
    $row2 = 2;
    $grandClaimed = 0.0;

    foreach ($bills as $bill) {
      $project_node = !$bill->get('field_project')->isEmpty() ? $bill->get('field_project')->entity : NULL;
      $project = $project_node ? $project_node->label() : '';
      $ra_no = $bill->get('field_ra_bill_number')->value ?? '';
      $bill_no = $bill->get('field_bill_number')->value ?? '';

      if ($bill->hasField('field_ra_bill_items') && !$bill->get('field_ra_bill_items')->isEmpty()) {
        $paragraphs = $bill->get('field_ra_bill_items')->referencedEntities();
        foreach ($paragraphs as $item) {
          if ($item->bundle() !== 'ra_bill_item') {
            continue;
          }

          $boq_item = !$item->get('field_boq_item')->isEmpty() ? $item->get('field_boq_item')->entity : NULL;
          $approved_qty = $boq_item ? floatval($boq_item->get('field_approved_quantity')->value ?? 0.0) : 0.0;
          $unit_rate = $boq_item ? floatval($boq_item->get('field_unit_rate')->value ?? 0.0) : 0.0;

          $item_code = $item->get('field_item_code')->value ?? '';
          $description = $item->get('field_item_description')->value ?? '';
          $uom = $boq_item && !$boq_item->get('field_unit')->isEmpty() ? $boq_item->get('field_unit')->value : 'Nos';

          $prev_qty = floatval($item->get('field_previous_qty')->value ?? 0.0);
          $current_qty = floatval($item->get('field_current_qty')->value ?? 0.0);
          $cumulative_qty = floatval($item->get('field_cumulative_qty')->value ?? 0.0);
          $claimed = floatval($item->get('field_amount_claimed')->value ?? 0.0);

          $val_state_val = $item->get('field_validation_status')->value;
          $val_state = $val_state_val === 'valid' ? 'Valid' : ($val_state_val === 'over_claimed' ? 'Over Claimed' : $val_state_val);

          $sheet2->setCellValue('A' . $row2, $ra_no);
          $sheet2->setCellValue('B' . $row2, $bill_no);
          $sheet2->setCellValue('C' . $row2, $project);
          $sheet2->setCellValue('D' . $row2, $item_code);
          $sheet2->setCellValue('E' . $row2, $description);
          $sheet2->setCellValue('F' . $row2, $uom);
          $sheet2->setCellValue('G' . $row2, $unit_rate);
          $sheet2->setCellValue('H' . $row2, $approved_qty);
          $sheet2->setCellValue('I' . $row2, $prev_qty);
          $sheet2->setCellValue('J' . $row2, $current_qty);
          $sheet2->setCellValue('K' . $row2, $cumulative_qty);
          $sheet2->setCellValue('L' . $row2, $claimed);
          $sheet2->setCellValue('M' . $row2, $val_state);

          // Formatting
          $sheet2->getStyle("A$row2:M$row2")->applyFromArray($dataStyle);
          $sheet2->getStyle("G$row2")->getNumberFormat()->setFormatCode('"₹"#,##0.00');
          $sheet2->getStyle("L$row2")->getNumberFormat()->setFormatCode('"₹"#,##0.00');
          $sheet2->getStyle("H$row2:K$row2")->getNumberFormat()->setFormatCode('#,##0.00');
          
          $sheet2->getStyle("A$row2:B$row2")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
          $sheet2->getStyle("D$row2")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
          $sheet2->getStyle("F$row2")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
          $sheet2->getStyle("M$row2")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

          $grandClaimed += $claimed;
          $row2++;
        }
      }
    }

    // Sheet 2 Totals Row
    $sheet2->setCellValue('A' . $row2, 'Total Claimed');
    $sheet2->mergeCells("A$row2:K$row2");
    $sheet2->getStyle("A$row2:K$row2")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    
    $sheet2->setCellValue('L' . $row2, $grandClaimed);

    $sheet2->getStyle("A$row2:M$row2")->applyFromArray($totalStyle);
    $sheet2->getStyle("L$row2")->getNumberFormat()->setFormatCode('"₹"#,##0.00');

    // Auto-fit Columns for Sheet 2
    for ($col = 1; $col <= 13; $col++) {
      $colLetter = Coordinate::stringFromColumnIndex($col);
      $sheet2->getColumnDimension($colLetter)->setAutoSize(TRUE);
    }

    // Output as downloadable file response
    $writer = new Xlsx($spreadsheet);
    
    $response = new Response();
    $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
    $response->headers->set('Cache-Control', 'max-age=0');

    ob_start();
    $writer->save('php://output');
    $content = ob_get_clean();
    $response->setContent($content);

    return $response;
  }

  /**
   * Generates and downloads the Excel file for a single RA Bill
   * matching the exact Abstract/Invoice format from the standard template.
   */
  protected function generateSingleWorkbook($bill, $filename) {
    $spreadsheet = new Spreadsheet();

    // ── Style Definitions ──
    $vendorBannerStyle = [
      'font' => [
        'bold' => TRUE,
        'size' => 14,
        'name' => 'Calibri',
        'color' => ['rgb' => '1A1A2E'],
      ],
      'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
      ],
    ];

    $headerStyle = [
      'font' => [
        'bold' => TRUE,
        'color' => ['rgb' => 'FFFFFF'],
        'size' => 10,
        'name' => 'Calibri',
      ],
      'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '1A1A2E'],
      ],
      'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
        'wrapText' => TRUE,
      ],
      'borders' => [
        'allBorders' => [
          'borderStyle' => Border::BORDER_THIN,
          'color' => ['rgb' => '000000'],
        ],
      ],
    ];

    $dataStyle = [
      'font' => [
        'size' => 10,
        'name' => 'Calibri',
      ],
      'borders' => [
        'allBorders' => [
          'borderStyle' => Border::BORDER_THIN,
          'color' => ['rgb' => 'CCCCCC'],
        ],
      ],
    ];

    $highlightStyle = [
      'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'FFFF00'],
      ],
      'font' => [
        'bold' => TRUE,
        'size' => 10,
        'name' => 'Calibri',
      ],
    ];

    $totalStyle = [
      'font' => [
        'bold' => TRUE,
        'size' => 10,
        'name' => 'Calibri',
      ],
      'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'F1F5F9'],
      ],
      'borders' => [
        'top' => [
          'borderStyle' => Border::BORDER_MEDIUM,
          'color' => ['rgb' => '000000'],
        ],
        'bottom' => [
          'borderStyle' => Border::BORDER_DOUBLE,
          'color' => ['rgb' => '000000'],
        ],
        'left' => [
          'borderStyle' => Border::BORDER_THIN,
          'color' => ['rgb' => 'CCCCCC'],
        ],
        'right' => [
          'borderStyle' => Border::BORDER_THIN,
          'color' => ['rgb' => 'CCCCCC'],
        ],
      ],
    ];

    $infoLabelStyle = [
      'font' => [
        'bold' => TRUE,
        'size' => 10,
        'name' => 'Calibri',
      ],
    ];

    // ── Load references ──
    $project_node = !$bill->get('field_project')->isEmpty() ? $bill->get('field_project')->entity : NULL;
    $vendor_node = !$bill->get('field_vendor')->isEmpty() ? $bill->get('field_vendor')->entity : NULL;
    $po_node = !$bill->get('field_purchase_order')->isEmpty() ? $bill->get('field_purchase_order')->entity : NULL;

    $project_name = $project_node ? $project_node->label() : '';
    $client_name = $project_node && $project_node->hasField('field_client') && !$project_node->get('field_client')->isEmpty()
      ? $project_node->get('field_client')->value
      : 'Aquatech Systems (Asia) Pvt. Ltd.';

    $vendor_name = $vendor_node ? $vendor_node->label() : 'SANTHOSH FABRICATORS PVT. LTD.';
    $vendor_gst = $vendor_node && $vendor_node->hasField('field_gst_number') && !$vendor_node->get('field_gst_number')->isEmpty()
      ? $vendor_node->get('field_gst_number')->value
      : '24AABCA1850C2ZE';

    $po_no = '';
    if ($po_node && $po_node->hasField('field_po_number') && !$po_node->get('field_po_number')->isEmpty()) {
      $po_no = $po_node->get('field_po_number')->value;
    }
    elseif ($po_node) {
      $po_no = $po_node->label();
    }

    $po_date = '';
    if ($po_node && $po_node->hasField('field_start_date') && !$po_node->get('field_start_date')->isEmpty()) {
      $po_date = date('d F, Y', strtotime($po_node->get('field_start_date')->value));
    }
    elseif ($po_node && $po_node->hasField('field_po_date') && !$po_node->get('field_po_date')->isEmpty()) {
      $po_date = date('d F, Y', strtotime($po_node->get('field_po_date')->value));
    }

    $bill_no = $bill->get('field_bill_number')->value ?? '';
    $ra_no = $bill->get('field_ra_bill_number')->value ?? '';
    $bill_date_val = $bill->get('field_bill_date')->value;
    $bill_date = $bill_date_val ? date('d.m.Y', strtotime($bill_date_val)) : '';
    $basic = floatval($bill->get('field_basic_amount')->value ?? 0.0);

    // Calculate Financial Year & Invoice Number
    $fy = '';
    if ($bill_date_val) {
      $time = strtotime($bill_date_val);
      $year = intval(date('Y', $time));
      $month = intval(date('n', $time));
      if ($month >= 4) {
        $fy = $year . '-' . substr(strval($year + 1), 2);
      } else {
        $fy = ($year - 1) . '-' . substr(strval($year), 2);
      }
    }
    $ra_num_str = $ra_no ? sprintf('%02d', intval($ra_no)) : '';
    $temp_invoice_no = !empty($fy) && !empty($ra_num_str) ? 'GJ/' . $fy . '/' . $ra_num_str : '';

    // ════════════════════════════════════════════════════════════
    // SHEET 1: Invoice
    // ════════════════════════════════════════════════════════════
    $sheet1 = $spreadsheet->getActiveSheet();
    $sheet1->setTitle('Invoice');
    $sheet1->setShowGridlines(TRUE);

    $sheet1->setCellValue('A2', $vendor_name);
    $sheet1->getStyle('A2')->applyFromArray($vendorBannerStyle);

    $sheet1->setCellValue('B5', 'To,');
    $sheet1->setCellValue('B6', $client_name);

    $sheet1->setCellValue('D5', 'BILL No.');
    $sheet1->setCellValue('F5', ': ' . $bill_no);

    $sheet1->setCellValue('D6', 'BILL DATE');
    $sheet1->setCellValue('F6', ': ' . $bill_date);

    $sheet1->setCellValue('D7', 'Work Order No.');
    $sheet1->setCellValue('F7', ': ' . $po_no);

    $sheet1->setCellValue('D8', 'Work Order Date');
    $sheet1->setCellValue('F8', ': ' . $po_date);

    $sheet1->setCellValue('D9', 'GSTN NO.');
    $sheet1->setCellValue('F9', ': 24AAQCS3102E1ZP');

    $sheet1->setCellValue('B10', 'GSTIN NO : ' . $vendor_gst);
    $sheet1->setCellValue('D10', 'SAC NO.');
    $sheet1->setCellValue('F10', ': 995442');

    $sheet1->setCellValue('D11', 'PAN NO.');
    $sheet1->setCellValue('F11', ': AAQCS3102E');

    $sheet1->setCellValue('D12', 'RA');
    $sheet1->setCellValue('F12', ': ' . sprintf('%02d', $ra_no));

    foreach (['B5', 'B6', 'D5', 'D6', 'D7', 'D8', 'D9', 'B10', 'D10', 'D11', 'D12'] as $coord) {
      $sheet1->getStyle($coord)->getFont()->setBold(TRUE);
    }

    $headersInvoice = ['SR.NO', 'DESCRIPTION', 'UOM', 'QTY', 'RATE', 'AMOUNT'];
    foreach ($headersInvoice as $idx => $text) {
      $colLetter = Coordinate::stringFromColumnIndex($idx + 2);
      $sheet1->setCellValue($colLetter . '13', $text);
    }
    $sheet1->getStyle('B13:G13')->applyFromArray($headerStyle);
    $sheet1->getRowDimension('13')->setRowHeight(24);

    $sheet1->setCellValue('B14', '1');
    $sheet1->setCellValue('C14', 'Installation Cost (' . $project_name . ')');
    $sheet1->setCellValue('D14', 'PU');
    $sheet1->setCellValue('E14', 1);
    $sheet1->setCellValue('F14', $basic);
    // Will be overwritten with dynamic formula after Abs sheet is built
    $sheet1->setCellValue('G14', $basic);

    $sheet1->getStyle('B14:G14')->applyFromArray($dataStyle);
    $sheet1->getStyle('F14:G14')->getNumberFormat()->setFormatCode('"₹"#,##0.00');
    $sheet1->getStyle('B14')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet1->getStyle('D14:E14')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $sheet1->setCellValue('D26', 'TOTAL AMOUNT');
    $sheet1->setCellValue('G26', '=G14');

    $sheet1->setCellValue('D27', 'CGST 9%');
    $sheet1->setCellValue('G27', '=G26*9%');

    $sheet1->setCellValue('D28', 'SGST 9%');
    $sheet1->setCellValue('G28', '=G26*9%');

    $sheet1->setCellValue('D29', 'TOTAL RECEIVABLE');
    $sheet1->setCellValue('G29', '=SUM(G26:G28)');

    foreach ([26, 27, 28, 29] as $r) {
      $sheet1->getStyle('D' . $r)->getFont()->setBold(TRUE);
      $sheet1->getStyle('G' . $r)->getFont()->setBold(TRUE);
      $sheet1->getStyle('G' . $r)->getNumberFormat()->setFormatCode('"₹"#,##0.00');
      $sheet1->getStyle('D' . $r . ':G' . $r)->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);
    }
    $sheet1->getStyle('D29:G29')->applyFromArray($totalStyle);

    for ($col = 1; $col <= 8; $col++) {
      $colLetter = Coordinate::stringFromColumnIndex($col);
      $sheet1->getColumnDimension($colLetter)->setAutoSize(TRUE);
    }

    // ════════════════════════════════════════════════════════════
    // SHEET 2: Abs (Abstract) — matching exact screenshot format
    // ════════════════════════════════════════════════════════════
    $sheet2 = $spreadsheet->createSheet();
    $sheet2->setTitle('Abs');
    $sheet2->setShowGridlines(TRUE);

    // ── Row 1: Vendor Name Banner (centered, spanning A-L) ──
    $sheet2->setCellValue('A1', $vendor_name);
    $sheet2->mergeCells('A1:L1');
    $sheet2->getStyle('A1')->applyFromArray($vendorBannerStyle);
    $sheet2->getRowDimension('1')->setRowHeight(22);
    $sheet2->getStyle('A1:L1')->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);

    // ── Row 2: CLIENT | PO.NO | P.O DATE | INVOICE NO ──
    $sheet2->setCellValue('A2', 'CLIENT:');
    $sheet2->setCellValue('B2', $client_name);
    $sheet2->mergeCells('B2:C2');
    $sheet2->setCellValue('D2', 'PO.NO.' . $po_no);
    $sheet2->mergeCells('D2:F2');
    $sheet2->setCellValue('G2', 'P.O DATE: ' . $po_date);
    $sheet2->mergeCells('G2:I2');
    $sheet2->setCellValue('J2', 'INVOICE NO:');
    $sheet2->setCellValue('K2', $temp_invoice_no);
    $sheet2->mergeCells('K2:L2');

    foreach (['A2', 'D2', 'G2', 'J2'] as $c) {
      $sheet2->getStyle($c)->applyFromArray($infoLabelStyle);
    }
    $sheet2->getStyle('A2:L2')->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);

    // ── Row 3: Project | R.A. No | INVOICE Date ──
    $sheet2->setCellValue('A3', 'Project :');
    $sheet2->setCellValue('B3', $project_name);
    $sheet2->mergeCells('B3:F3');
    $sheet2->setCellValue('G3', 'R.A. No. ' . sprintf('%02d', $ra_no));
    $sheet2->mergeCells('G3:I3');
    $sheet2->setCellValue('J3', 'INVOICE Date :');
    $sheet2->setCellValue('K3', $bill_date);
    $sheet2->mergeCells('K3:L3');

    foreach (['A3', 'G3', 'J3'] as $c) {
      $sheet2->getStyle($c)->applyFromArray($infoLabelStyle);
    }
    $sheet2->getStyle('A3:L3')->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);

    // ── Row 4: ABSTRAC label ──
    $sheet2->setCellValue('A4', 'ABSTRAC');
    $sheet2->mergeCells('A4:L4');
    $sheet2->getStyle('A4')->getFont()->setBold(TRUE)->setSize(11);
    $sheet2->getStyle('A4')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet2->getStyle('A4:L4')->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);

    // ── Row 5-6: Two-level headers ──
    // Row 5: Main headers
    $sheet2->setCellValue('A5', 'SL.NO.');
    $sheet2->setCellValue('B5', 'DESCRIPTION');
    $sheet2->setCellValue('C5', 'UOM');
    $sheet2->setCellValue('D5', 'QTY');
    $sheet2->setCellValue('E5', 'RATE');
    $sheet2->setCellValue('F5', 'AMOUNT');
    $sheet2->setCellValue('G5', 'QUANTITY');
    $sheet2->mergeCells('G5:I5');
    $sheet2->setCellValue('J5', 'AMOUNT');
    $sheet2->mergeCells('J5:L5');

    // Row 6: Sub-headers
    $sheet2->setCellValue('G6', 'UPTO PREV');
    $sheet2->setCellValue('H6', 'IN THIS BILL');
    $sheet2->setCellValue('I6', 'UPTO DATE');
    $sheet2->setCellValue('J6', 'UPTO PREV');
    $sheet2->setCellValue('K6', 'THIS BILL');
    $sheet2->setCellValue('L6', 'UPTO DATE');

    // Merge vertically for non-split headers
    $sheet2->mergeCells('A5:A6');
    $sheet2->mergeCells('B5:B6');
    $sheet2->mergeCells('C5:C6');
    $sheet2->mergeCells('D5:D6');
    $sheet2->mergeCells('E5:E6');
    $sheet2->mergeCells('F5:F6');

    $sheet2->getStyle('A5:L6')->applyFromArray($headerStyle);
    $sheet2->getRowDimension('5')->setRowHeight(20);
    $sheet2->getRowDimension('6')->setRowHeight(20);

    // Yellow highlight for "IN THIS BILL" and "THIS BILL" header cells
    $yellowHeaderStyle = [
      'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => 'C6A700'],
      ],
      'font' => [
        'bold' => TRUE,
        'color' => ['rgb' => 'FFFFFF'],
        'size' => 10,
        'name' => 'Calibri',
      ],
      'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
        'wrapText' => TRUE,
      ],
      'borders' => [
        'allBorders' => [
          'borderStyle' => Border::BORDER_THIN,
          'color' => ['rgb' => '000000'],
        ],
      ],
    ];
    $sheet2->getStyle('H6')->applyFromArray($yellowHeaderStyle);
    $sheet2->getStyle('K6')->applyFromArray($yellowHeaderStyle);

    // ── Populate Data Items starting at Row 7 ──
    $dataRow = 7;
    $slNo = 1;
    if ($bill->hasField('field_ra_bill_items') && !$bill->get('field_ra_bill_items')->isEmpty()) {
      $paragraphs = $bill->get('field_ra_bill_items')->referencedEntities();
      foreach ($paragraphs as $item) {
        if ($item->bundle() !== 'ra_bill_item') {
          continue;
        }

        $boq_item = !$item->get('field_boq_item')->isEmpty() ? $item->get('field_boq_item')->entity : NULL;
        $approved_qty = $boq_item ? floatval($boq_item->get('field_approved_quantity')->value ?? 0.0) : 0.0;
        $unit_rate = $boq_item ? floatval($boq_item->get('field_unit_rate')->value ?? 0.0) : 0.0;
        $uom = $boq_item && !$boq_item->get('field_unit')->isEmpty() ? $boq_item->get('field_unit')->value : 'Nos';

        $description = $item->get('field_item_description')->value ?? '';
        $prev_qty = floatval($item->get('field_previous_qty')->value ?? 0.0);
        $current_qty = floatval($item->get('field_current_qty')->value ?? 0.0);

        $sheet2->setCellValue('A' . $dataRow, $slNo);
        $sheet2->setCellValue('B' . $dataRow, $description);
        $sheet2->setCellValue('C' . $dataRow, $uom);
        $sheet2->setCellValue('D' . $dataRow, $approved_qty);
        $sheet2->setCellValue('E' . $dataRow, $unit_rate);
        // AMOUNT = QTY × RATE
        $sheet2->setCellValue('F' . $dataRow, '=D' . $dataRow . '*E' . $dataRow);
        // QUANTITY columns
        $sheet2->setCellValue('G' . $dataRow, $prev_qty);
        $sheet2->setCellValue('H' . $dataRow, $current_qty);
        $sheet2->setCellValue('I' . $dataRow, '=G' . $dataRow . '+H' . $dataRow);
        // AMOUNT columns
        $sheet2->setCellValue('J' . $dataRow, '=G' . $dataRow . '*E' . $dataRow);
        $sheet2->setCellValue('K' . $dataRow, '=H' . $dataRow . '*E' . $dataRow);
        $sheet2->setCellValue('L' . $dataRow, '=I' . $dataRow . '*E' . $dataRow);

        // Apply data styles
        $sheet2->getStyle("A$dataRow:L$dataRow")->applyFromArray($dataStyle);
        $sheet2->getStyle("E$dataRow:F$dataRow")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet2->getStyle("J$dataRow:L$dataRow")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet2->getStyle("D$dataRow")->getNumberFormat()->setFormatCode('#,##0');
        $sheet2->getStyle("G$dataRow:I$dataRow")->getNumberFormat()->setFormatCode('#,##0');

        $sheet2->getStyle("A$dataRow")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet2->getStyle("C$dataRow")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Yellow highlight for "IN THIS BILL" (H) and "THIS BILL" (K) data cells
        $sheet2->getStyle("H$dataRow")->applyFromArray($highlightStyle);
        $sheet2->getStyle("H$dataRow")->getNumberFormat()->setFormatCode('#,##0');
        $sheet2->getStyle("K$dataRow")->applyFromArray($highlightStyle);
        $sheet2->getStyle("K$dataRow")->getNumberFormat()->setFormatCode('#,##0.00');

        $slNo++;
        $dataRow++;
      }
    }

    // ── Totals Row ──
    $firstDataRow = 7;
    $lastDataRow = $dataRow - 1;

    $sheet2->setCellValue('A' . $dataRow, 'Total');
    $sheet2->mergeCells("A$dataRow:E$dataRow");
    $sheet2->getStyle("A$dataRow:E$dataRow")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    $sheet2->setCellValue('F' . $dataRow, '=SUM(F' . $firstDataRow . ':F' . $lastDataRow . ')');
    $sheet2->setCellValue('J' . $dataRow, '=SUM(J' . $firstDataRow . ':J' . $lastDataRow . ')');
    $sheet2->setCellValue('K' . $dataRow, '=SUM(K' . $firstDataRow . ':K' . $lastDataRow . ')');
    $sheet2->setCellValue('L' . $dataRow, '=SUM(L' . $firstDataRow . ':L' . $lastDataRow . ')');

    $sheet2->getStyle("A$dataRow:L$dataRow")->applyFromArray($totalStyle);
    $sheet2->getStyle("F$dataRow")->getNumberFormat()->setFormatCode('#,##0.00');
    $sheet2->getStyle("J$dataRow:L$dataRow")->getNumberFormat()->setFormatCode('#,##0.00');

    // ── Link Invoice sheet G14 to Abs totals "THIS BILL" (Col K) ──
    $sheet1->setCellValue('G14', '=Abs!K' . $dataRow);

    // ── Auto-fit columns ──
    $sheet2->getColumnDimension('A')->setWidth(8);
    $sheet2->getColumnDimension('B')->setAutoSize(TRUE);
    for ($col = 3; $col <= 12; $col++) {
      $colLetter = Coordinate::stringFromColumnIndex($col);
      $sheet2->getColumnDimension($colLetter)->setAutoSize(TRUE);
    }

    // ── Output as downloadable file ──
    $writer = new Xlsx($spreadsheet);

    $response = new Response();
    $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
    $response->headers->set('Cache-Control', 'max-age=0');

    ob_start();
    $writer->save('php://output');
    $content = ob_get_clean();
    $response->setContent($content);

    return $response;
  }

}
