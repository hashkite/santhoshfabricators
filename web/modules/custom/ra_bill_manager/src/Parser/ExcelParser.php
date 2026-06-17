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

    // Detect if part number is present in items
    $has_part_number = FALSE;
    foreach ($items as $item) {
      if (!empty($item['part_number'])) {
        $has_part_number = TRUE;
        break;
      }
    }
    $metadata['has_part_number'] = $has_part_number;

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
      $this->scanSheetMetadata($invoice_sheet, $meta);
    }

    // Find Abs sheet
    $abs_sheet = NULL;
    foreach ($spreadsheet->getSheetNames() as $sheet_name) {
      if (stripos($sheet_name, 'Abs') !== FALSE || stripos($sheet_name, 'Abstract') !== FALSE) {
        $abs_sheet = $spreadsheet->getSheetByName($sheet_name);
        break;
      }
    }

    if ($abs_sheet) {
      $this->scanSheetMetadata($abs_sheet, $meta);

      // Fallback for Vendor from Abs sheet A2 if still empty
      if (empty($meta['vendor_name'])) {
        $meta['vendor_name'] = trim($abs_sheet->getCell('A2')->getCalculatedValue() ?? '');
      }
    }

    // Clean up Bill Number if it has prefix character like semicolon or colon
    if (!empty($meta['bill_number'])) {
      $meta['bill_number'] = ltrim($meta['bill_number'], ';: ');
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
   * Scans a sheet for metadata key-value pairs.
   */
  protected function scanSheetMetadata($sheet, &$meta) {
    $highest_row = $sheet->getHighestRow();
    $highest_col = $sheet->getHighestColumn();
    $highest_col_index = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highest_col);

    for ($row = 1; $row <= $highest_row; $row++) {
      for ($col = 1; $col <= $highest_col_index; $col++) {
        $cell_coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row;
        $cell = $sheet->getCell($cell_coord);
        $val = trim($cell->getCalculatedValue() ?? '');
        if (empty($val)) {
          continue;
        }

        // Only scan top rows (up to 20) for general metadata headers
        if ($row <= 20) {
          // 1. Bill Number
          if (empty($meta['bill_number']) && preg_match('/^(bill\s*no|invoice\s*no)\b/i', $val)) {
            $meta['bill_number'] = $this->findNextValue($sheet, $row, $col, $highest_col_index);
          }

          // 2. Bill Date
          if (empty($meta['bill_date']) && preg_match('/^(bill\s*date|invoice\s*date|date\.?)$/i', $val)) {
            $date_val = $this->findNextValue($sheet, $row, $col, $highest_col_index);
            if (!empty($date_val)) {
              if (is_numeric($date_val)) {
                $timestamp = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToTimestamp($date_val);
                $meta['bill_date'] = date('Y-m-d', $timestamp);
              } else {
                $clean_date = str_replace(['/', '.'], '-', $date_val);
                if ($timestamp = strtotime($clean_date)) {
                  $meta['bill_date'] = date('Y-m-d', $timestamp);
                }
              }
            }
          }

          // 3. Project / PO number
          if (empty($meta['project_code']) && preg_match('/^(work\s*order\s*no|po\s*no|po\s*number|project\s*:?)$/i', $val)) {
            $proj_val = $this->findNextValue($sheet, $row, $col, $highest_col_index);
            if (!empty($proj_val)) {
              if (preg_match('/([A-Z0-9\-]+)/i', $proj_val, $matches)) {
                $meta['project_code'] = $matches[1];
              }
            }
          }

          // 4. RA Bill Number
          if (empty($meta['ra_bill_number']) && preg_match('/^(ra\s*invoice\s*no|ra\s*bill\s*no|r\.a\.\s*bill\s*no|r\.a\.\s*no|ra)$/i', $val)) {
            $ra_val = $this->findNextValue($sheet, $row, $col, $highest_col_index);
            if (empty($ra_val)) {
              $ra_val = $val;
            }
            if (preg_match('/(\d+)/', $ra_val, $matches)) {
              $meta['ra_bill_number'] = intval($matches[1]);
            }
          }

          // 5. Client
          if (empty($meta['client_name']) && preg_match('/^(client|bill\s*to)\b/i', $val)) {
            $meta['client_name'] = $this->findNextValue($sheet, $row, $col, $highest_col_index);
          }

          // 6. Vendor
          if (empty($meta['vendor_name']) && preg_match('/^(contractor|vendor)\b/i', $val)) {
            $meta['vendor_name'] = $this->findNextValue($sheet, $row, $col, $highest_col_index);
          }
        }

        // Financial totals (basic, CGST/SGST/IGST, total) can be anywhere in the sheet, usually at the bottom
        // 7. Basic Amount
        if (empty($meta['basic_amount']) && preg_match('/total\s*amount|basic\s*amount/i', $val) && !preg_match('/receivable|payable/i', $val)) {
          $amt_val = $this->findNextValue($sheet, $row, $col, $highest_col_index);
          if (is_numeric($amt_val)) {
            $meta['basic_amount'] = floatval($amt_val);
          }
        }

        // 8. GST (CGST/SGST/IGST)
        if (preg_match('/igst|cgst|sgst/i', $val)) {
          $gst_val = $this->findNextValue($sheet, $row, $col, $highest_col_index);
          if (is_numeric($gst_val)) {
            $gst_val = floatval($gst_val);
            if (preg_match('/igst/i', $val)) {
              $meta['cgst'] = $gst_val / 2.0;
              $meta['sgst'] = $gst_val / 2.0;
            }
            elseif (preg_match('/cgst/i', $val) && empty($meta['cgst'])) {
              $meta['cgst'] = $gst_val;
            }
            elseif (preg_match('/sgst/i', $val) && empty($meta['sgst'])) {
              $meta['sgst'] = $gst_val;
            }
          }
        }

        // 9. Total Amount
        if (empty($meta['total_amount']) && preg_match('/total\s*receivable|grand\s*total|net\s*payable/i', $val)) {
          $tot_val = $this->findNextValue($sheet, $row, $col, $highest_col_index);
          if (is_numeric($tot_val)) {
            $meta['total_amount'] = floatval($tot_val);
          }
        }
      }
    }
  }

  /**
   * Helper to find the value in adjacent columns to a label cell.
   */
  protected function findNextValue($sheet, $row, $col, $highest_col_index) {
    for ($c = $col + 1; $c <= $highest_col_index; $c++) {
      $next_coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . $row;
      $next_val = trim($sheet->getCell($next_coord)->getCalculatedValue() ?? '');
      $clean_val = ltrim($next_val, ' :;');
      if ($clean_val !== '') {
        return $clean_val;
      }
    }
    return '';
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
    $highest_col = $abs_sheet->getHighestColumn();
    $highest_col_idx = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highest_col);

    // 1. Dynamically find the header row by looking for sl.no.
    $header_row = 6;
    for ($r = 1; $r <= 15; $r++) {
      for ($c = 1; $c <= 5; $c++) {
        $coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . $r;
        $val = trim((string)$abs_sheet->getCell($coord)->getValue() ?? '');
        if (preg_match('/^(sl\.?\s*no\.?|s\.?\s*no\.?|sr\.?\s*no\.?|serial\s*no\.?)$/i', $val)) {
          $header_row = $r;
          break 2;
        }
      }
    }

    // 2. Initialize default columns layout map
    $col_map = [
      'sl_no' => 'A',
      'part_number' => NULL,
      'desc' => 'B',
      'uom' => 'C',
      'po_qty' => 'D',
      'rate' => 'E',
      'prev_qty' => 'G',
      'current_qty' => 'H',
      'cumulative_qty' => 'I',
      'amount_claimed' => 'K',
    ];

    // Determine if Part Number column is present in B
    $has_part_number = FALSE;
    $col_b_header = trim((string)$abs_sheet->getCell('B' . $header_row)->getValue() ?? '');
    if (preg_match('/part\s*number|part\s*no/i', $col_b_header)) {
      $has_part_number = TRUE;
      $col_map['part_number'] = 'B';
      $col_map['desc'] = 'C';
      $col_map['uom'] = 'D';
      $col_map['po_qty'] = 'E';
      $col_map['rate'] = 'F';
      $col_map['prev_qty'] = 'H';
      $col_map['current_qty'] = 'I';
      $col_map['cumulative_qty'] = 'J';
      $col_map['amount_claimed'] = 'L';
    }

    // 3. Scan the header row and sub-header row for dynamic column mapping
    for ($c = 1; $c <= $highest_col_idx; $c++) {
      $col_letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
      $cell_val = trim((string)$abs_sheet->getCell($col_letter . $header_row)->getValue() ?? '');
      $sub_cell_val = trim((string)$abs_sheet->getCell($col_letter . ($header_row + 1))->getValue() ?? '');

      if (preg_match('/^(sl\.?\s*no\.?|s\.?\s*no\.?|sr\.?\s*no\.?|serial\s*no\.?)$/i', $cell_val)) {
        $col_map['sl_no'] = $col_letter;
      }
      elseif (preg_match('/part\s*number|part\s*no/i', $cell_val)) {
        $has_part_number = TRUE;
        $col_map['part_number'] = $col_letter;
      }
      elseif (preg_match('/desc/i', $cell_val)) {
        $col_map['desc'] = $col_letter;
      }
      elseif (preg_match('/uom|unit/i', $cell_val)) {
        $col_map['uom'] = $col_letter;
      }
      elseif (preg_match('/po\s*\/qty|po\s*qty|approved\s*qty/i', $cell_val) || (preg_match('/qty/i', $cell_val) && !preg_match('/prev|this|current|cumulative|upto/i', $cell_val) && !preg_match('/prev|this|current|cumulative|upto/i', $sub_cell_val))) {
        $col_map['po_qty'] = $col_letter;
      }
      elseif (preg_match('/rate|price/i', $cell_val)) {
        $col_map['rate'] = $col_letter;
      }
      
      // Match quantities
      if (preg_match('/qty|quantity/i', $cell_val) || preg_match('/qty|quantity/i', $sub_cell_val)) {
        if (preg_match('/prev/i', $cell_val) || preg_match('/prev/i', $sub_cell_val)) {
          $col_map['prev_qty'] = $col_letter;
        }
        elseif (preg_match('/this|current/i', $cell_val) || preg_match('/this|current/i', $sub_cell_val)) {
          $col_map['current_qty'] = $col_letter;
        }
        elseif (preg_match('/cumulative|upto|date/i', $cell_val) || preg_match('/cumulative|upto|date/i', $sub_cell_val)) {
          $col_map['cumulative_qty'] = $col_letter;
        }
      }

      // Match amount claimed (this bill amount)
      if (preg_match('/amount/i', $cell_val) || preg_match('/amount/i', $sub_cell_val)) {
        if (preg_match('/this|current/i', $cell_val) || preg_match('/this|current/i', $sub_cell_val)) {
          $col_map['amount_claimed'] = $col_letter;
        }
      }
    }

    $last_description = '';

    // 4. Scan the rows starting from header_row + 1 (the subheader row will fail is_numeric PO Qty checks)
    for ($row = $header_row + 1; $row <= $highest_row; $row++) {
      $sl_no = trim((string)$abs_sheet->getCell($col_map['sl_no'] . $row)->getValue() ?? '');
      $part_number = $col_map['part_number'] ? trim((string)$abs_sheet->getCell($col_map['part_number'] . $row)->getValue() ?? '') : '';
      $description = trim((string)$abs_sheet->getCell($col_map['desc'] . $row)->getValue() ?? '');
      $uom = trim((string)$abs_sheet->getCell($col_map['uom'] . $row)->getValue() ?? '');
      
      // Skip if description or sl_no is a header string
      if (strcasecmp($sl_no, 'sl.no.') === 0 || strcasecmp($sl_no, 'sl.no') === 0 || strcasecmp($description, 'description') === 0 || strcasecmp($description, 'PART NUMBER') === 0) {
        continue;
      }

      // Load calculated cell values for numeric columns
      $po_qty_val = $abs_sheet->getCell($col_map['po_qty'] . $row)->getCalculatedValue();
      $rate_val = $abs_sheet->getCell($col_map['rate'] . $row)->getCalculatedValue();

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
        
        $prev_qty = floatval($abs_sheet->getCell($col_map['prev_qty'] . $row)->getCalculatedValue() ?? 0.0);
        $current_qty = floatval($abs_sheet->getCell($col_map['current_qty'] . $row)->getCalculatedValue() ?? 0.0);
        $cumulative_qty = floatval($abs_sheet->getCell($col_map['cumulative_qty'] . $row)->getCalculatedValue() ?? 0.0);
        $amount_claimed = floatval($abs_sheet->getCell($col_map['amount_claimed'] . $row)->getCalculatedValue() ?? 0.0);

        $items[] = [
          'item_code' => $sl_no,
          'part_number' => $part_number,
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
