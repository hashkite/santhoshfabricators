<?php

namespace Drupal\ra_bill_manager\Parser;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Service to parse RA Bill Excel sheets using PhpSpreadsheet.
 */
class ExcelParser {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs a new ExcelParser.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, LoggerChannelFactoryInterface $logger_factory) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('ra_bill_manager');
  }

  /**
   * Parses the given Excel file.
   *
   * @param string $file_path
   *   The real path to the Excel file.
   *
   * @return array
   *   An array containing 'metadata' and 'items'.
   */
  public function parse($file_path) {
    if (!file_exists($file_path)) {
      $this->logger->error('Excel file not found at path: @path', ['@path' => $file_path]);
      throw new \InvalidArgumentException("Excel file not found.");
    }

    try {
      $spreadsheet = IOFactory::load($file_path);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to load Excel file: @message', ['@message' => $e->getMessage()]);
      throw new \RuntimeException("Failed to load Excel file: " . $e->getMessage());
    }

    $metadata = $this->parseMetadata($spreadsheet);
    $items = $this->parseItems($spreadsheet);

    return [
      'metadata' => $metadata,
      'items' => $items,
    ];
  }

  /**
   * Parses RA Bill metadata (Bill No, Date, Vendor, Client, Totals).
   */
  protected function parseMetadata($spreadsheet) {
    $meta = [
      'bill_number' => '',
      'ra_bill_number' => '',
      'bill_date' => '',
      'basic_amount' => 0.0,
      'cgst' => 0.0,
      'sgst' => 0.0,
      'total_amount' => 0.0,
      'vendor_name' => '',
      'client_name' => '',
      'project_code' => '',
    ];

    // Try parsing from the 'Invoice' sheet first
    $invoice_sheet = $spreadsheet->getSheetByName('Invoice');
    if ($invoice_sheet) {
      $highest_row = $invoice_sheet->getHighestRow();
      $highest_col = $invoice_sheet->getHighestColumn();
      $highest_col_index = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highest_col);

      for ($row = 1; $row <= $highest_row; $row++) {
        for ($col = 1; $col <= $highest_col_index; $col++) {
          $cell_coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row;
          $cell = $invoice_sheet->getCell($cell_coord);
          $val = trim($cell->getCalculatedValue() ?? '');
          if (empty($val)) {
            continue;
          }

          // Check Invoice No / Bill Number
          if (preg_match('/invoice\s*no|bill\s*no/i', $val)) {
            for ($c = $col + 1; $c <= $highest_col_index; $c++) {
              $next_coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . $row;
              $next_val = trim($invoice_sheet->getCell($next_coord)->getCalculatedValue() ?? '');
              $clean_val = ltrim($next_val, ': ');
              if ($clean_val !== '') {
                $meta['bill_number'] = $clean_val;
                break;
              }
            }
          }

          // Check Invoice Date
          if (preg_match('/invoice\s*date|bill\s*date/i', $val)) {
            for ($c = $col + 1; $c <= $highest_col_index; $c++) {
              $next_coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . $row;
              $next_val = trim($invoice_sheet->getCell($next_coord)->getCalculatedValue() ?? '');
              $clean_val = ltrim($next_val, ': ');
              if ($clean_val !== '') {
                $clean_date = str_replace(['/', '.'], '-', $clean_val);
                if ($timestamp = strtotime($clean_date)) {
                  $meta['bill_date'] = date('Y-m-d', $timestamp);
                }
                break;
              }
            }
          }

          // Check Work Order No / PO Number
          if (preg_match('/work\s*order\s*no|po\s*no|po\s*number/i', $val)) {
            for ($c = $col + 1; $c <= $highest_col_index; $c++) {
              $next_coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . $row;
              $next_val = trim($invoice_sheet->getCell($next_coord)->getCalculatedValue() ?? '');
              $clean_val = ltrim($next_val, ': ');
              if ($clean_val !== '') {
                if (preg_match('/([A-Z0-9\-]+)/i', $clean_val, $matches)) {
                  $meta['project_code'] = $matches[1];
                }
                break;
              }
            }
          }

          // Check RA Bill Number
          if (preg_match('/^(ra\s*invoice\s*no|ra\s*bill\s*no|r\.a\.\s*bill\s*no|ra)$/i', $val)) {
            for ($c = $col + 1; $c <= $highest_col_index; $c++) {
              $next_coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . $row;
              $next_val = trim($invoice_sheet->getCell($next_coord)->getCalculatedValue() ?? '');
              $clean_val = ltrim($next_val, ': ');
              if ($clean_val !== '') {
                if (preg_match('/(\d+)/', $clean_val, $matches)) {
                  $meta['ra_bill_number'] = intval($matches[1]);
                }
                break;
              }
            }
          }

          // Check Basic Amount
          if (preg_match('/total\s*amount|basic\s*amount/i', $val) && !preg_match('/receivable|payable/i', $val)) {
            for ($c = $col + 1; $c <= $highest_col_index; $c++) {
              $next_coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . $row;
              $next_val = $invoice_sheet->getCell($next_coord)->getCalculatedValue();
              if (is_numeric($next_val)) {
                $meta['basic_amount'] = floatval($next_val);
                break;
              }
            }
          }

          // Check GST (CGST/SGST/IGST)
          if (preg_match('/igst|cgst|sgst/i', $val)) {
            $gst_val = 0.0;
            for ($c = $col + 1; $c <= $highest_col_index; $c++) {
              $next_coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . $row;
              $next_val = $invoice_sheet->getCell($next_coord)->getCalculatedValue();
              if (is_numeric($next_val)) {
                $gst_val = floatval($next_val);
                break;
              }
            }
            if (preg_match('/igst/i', $val)) {
              $meta['cgst'] = $gst_val / 2.0;
              $meta['sgst'] = $gst_val / 2.0;
            }
            elseif (preg_match('/cgst/i', $val)) {
              $meta['cgst'] = $gst_val;
            }
            elseif (preg_match('/sgst/i', $val)) {
              $meta['sgst'] = $gst_val;
            }
          }

          // Check Total Amount
          if (preg_match('/total\s*receivable|grand\s*total|net\s*payable/i', $val)) {
            for ($c = $col + 1; $c <= $highest_col_index; $c++) {
              $next_coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . $row;
              $next_val = $invoice_sheet->getCell($next_coord)->getCalculatedValue();
              if (is_numeric($next_val)) {
                $meta['total_amount'] = floatval($next_val);
                break;
              }
            }
          }
        }
      }

      // Client name fallback from B3 or B6
      $client_val = $invoice_sheet->getCell('B3')->getCalculatedValue();
      if (empty($client_val) || strcasecmp(trim($client_val), 'Bill To,') === 0) {
        $client_val = $invoice_sheet->getCell('B6')->getCalculatedValue();
      }
      if ($client_val) {
        $meta['client_name'] = trim($client_val);
      }
    }

    // Find Abs sheet for fallbacks
    $abs_sheet = NULL;
    foreach ($spreadsheet->getSheetNames() as $sheet_name) {
      if (stripos($sheet_name, 'Abs') !== FALSE || stripos($sheet_name, 'Abstract') !== FALSE) {
        $abs_sheet = $spreadsheet->getSheetByName($sheet_name);
        break;
      }
    }

    if ($abs_sheet) {
      $highest_row = $abs_sheet->getHighestRow();
      $highest_col = $abs_sheet->getHighestColumn();
      $highest_col_index = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highest_col);

      // Fallback for RA Bill Number from Abs sheet top rows
      if (empty($meta['ra_bill_number'])) {
        for ($row = 1; $row <= 15; $row++) {
          for ($col = 1; $col <= $highest_col_index; $col++) {
            $cell_coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row;
            $val = trim($abs_sheet->getCell($cell_coord)->getCalculatedValue() ?? '');
            if (preg_match('/r\.a\.\s*no\b|ra\s*bill\s*no/i', $val)) {
              if (preg_match('/(\d+)/', $val, $matches)) {
                $meta['ra_bill_number'] = intval($matches[1]);
              }
              else {
                for ($c = $col + 1; $c <= $highest_col_index; $c++) {
                  $next_coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . $row;
                  $next_val = trim($abs_sheet->getCell($next_coord)->getCalculatedValue() ?? '');
                  $clean_val = ltrim($next_val, ': ');
                  if (preg_match('/(\d+)/', $clean_val, $matches)) {
                    $meta['ra_bill_number'] = intval($matches[1]);
                    break;
                  }
                }
              }
            }
          }
        }
      }

      // Fallback for Vendor from Abs sheet A2
      if (empty($meta['vendor_name'])) {
        $meta['vendor_name'] = trim($abs_sheet->getCell('A2')->getCalculatedValue() ?? '');
      }

      // Fallback for Client from Abs sheet B3
      if (empty($meta['client_name'])) {
        $client_val = $abs_sheet->getCell('B3')->getCalculatedValue();
        if ($client_val) {
          $meta['client_name'] = trim($client_val);
        }
      }

      // Fallback for Project Code from Abs sheet B4 or D3
      if (empty($meta['project_code'])) {
        foreach (['B4', 'D3'] as $coord) {
          $proj_val = $abs_sheet->getCell($coord)->getCalculatedValue();
          if ($proj_val) {
            $proj_val = trim(str_replace(':', '', $proj_val));
            if (preg_match('/([A-Z0-9\-]+)/i', $proj_val, $matches)) {
              $meta['project_code'] = $matches[1];
              break;
            }
          }
        }
      }
    }

    // Fallback: If RA Bill number is empty, try to extract it from the Bill Number.
    if (empty($meta['ra_bill_number']) && !empty($meta['bill_number'])) {
      if (preg_match('/\/0*(\d+)$/', $meta['bill_number'], $matches)) {
        $meta['ra_bill_number'] = intval($matches[1]);
      }
    }

    return $meta;
  }

  /**
   * Parses line items from the sheet containing 'Abs' or 'Abstract'.
   */
  protected function parseItems($spreadsheet) {
    $items = [];
    
    // Find the Abs sheet dynamically
    $abs_sheet = NULL;
    foreach ($spreadsheet->getSheetNames() as $sheet_name) {
      if (stripos($sheet_name, 'Abs') !== FALSE || stripos($sheet_name, 'Abstract') !== FALSE) {
        $abs_sheet = $spreadsheet->getSheetByName($sheet_name);
        break;
      }
    }

    if (!$abs_sheet) {
      $this->logger->warning('Abs sheet not found in the uploaded workbook.');
      return $items;
    }

    $highest_row = $abs_sheet->getHighestRow();
    $last_description = '';
    
    // Line items data rows scan starts from row 6 to adapt to different headers
    for ($row = 6; $row <= $highest_row; $row++) {
      $sl_no = trim($abs_sheet->getCell('A' . $row)->getValue() ?? '');
      $description = trim($abs_sheet->getCell('B' . $row)->getValue() ?? '');
      $uom = trim($abs_sheet->getCell('C' . $row)->getValue() ?? '');
      
      // Skip if SL.NO is a header string
      if (strcasecmp($sl_no, 'sl.no.') === 0 || strcasecmp($sl_no, 'sl.no') === 0 || strcasecmp($description, 'description') === 0) {
        continue;
      }

      // Load calculated cell values for numeric columns
      $po_qty_val = $abs_sheet->getCell('D' . $row)->getCalculatedValue();
      $rate_val = $abs_sheet->getCell('E' . $row)->getCalculatedValue();

      if (!empty($description)) {
        $last_description = $description;
      }
      elseif (!empty($last_description)) {
        $description = $last_description;
      }

      // Rule to identify a valid line item row:
      // Must have code/sl_no or description, and both PO Qty and Rate must be valid numbers
      if ((!empty($sl_no) || !empty($description)) && is_numeric($po_qty_val) && is_numeric($rate_val)) {
        $po_qty = floatval($po_qty_val);
        $rate = floatval($rate_val);
        
        $prev_qty = floatval($abs_sheet->getCell('G' . $row)->getCalculatedValue() ?? 0.0);
        $current_qty = floatval($abs_sheet->getCell('H' . $row)->getCalculatedValue() ?? 0.0);
        $cumulative_qty = floatval($abs_sheet->getCell('I' . $row)->getCalculatedValue() ?? 0.0);
        $amount_claimed = floatval($abs_sheet->getCell('K' . $row)->getCalculatedValue() ?? 0.0);

        $items[] = [
          'item_code' => $sl_no,
          'description' => $description,
          'uom' => $uom,
          'po_qty' => $po_qty,
          'rate' => $rate,
          'prev_qty' => $prev_qty,
          'current_qty' => $current_qty,
          'cumulative_qty' => $cumulative_qty,
          'amount_claimed' => $amount_claimed,
        ];
      }
    }

    return $items;
  }

}
