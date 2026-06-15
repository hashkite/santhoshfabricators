<?php

namespace Drupal\power_alpha_helper\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\Entity\Node;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for the administrative dashboard overview page.
 */
class DashboardController extends ControllerBase {

  /**
   * Builds the administrative overview dashboard page.
   *
   * Computes KPI metrics from dynamic entity data and passes them
   * to the theme template for rendering.
   *
   * @return array
   *   A render array for the dashboard page.
   */
  public function overview() {
    $data = [];

    // ── 1. ACTIVE PROJECTS COUNT ──
    $active_query = \Drupal::entityQuery('node')
      ->condition('type', 'project_sites')
      ->condition('status', 1)
      ->accessCheck(FALSE);
    $active_nids = $active_query->execute();
    $data['active_projects'] = count($active_nids);

    // ── 2. TOTAL MONTHLY SPEND (expenses in current month) ──
    $now = new \DateTime();
    $month_start = $now->format('Y-m-01');
    $month_end = $now->format('Y-m-t');
    $expense_query = \Drupal::entityQuery('node')
      ->condition('type', 'expenses')
      ->condition('status', 1)
      ->condition('created', strtotime($month_start), '>=')
      ->condition('created', strtotime($month_end) + 86400, '<')
      ->accessCheck(FALSE);
    $expense_nids = $expense_query->execute();
    $monthly_spend = 0;
    if (!empty($expense_nids)) {
      foreach (Node::loadMultiple($expense_nids) as $expense) {
        $monthly_spend += (float) $expense->get('field_amount')->value;
      }
    }
    $data['monthly_spend'] = $monthly_spend;
    $data['monthly_spend_formatted'] = $this->formatCurrency($monthly_spend);

    // ── 3. PENDING PURCHASE ORDERS ──
    $po_query = \Drupal::entityQuery('node')
      ->condition('type', 'purchase_order')
      ->condition('status', 1)
      ->accessCheck(FALSE);
    $po_nids = $po_query->execute();
    $data['pending_pos'] = count($po_nids);

    // ── 4. TOTAL EXPENSES (all time) ──
    $total_exp_query = \Drupal::entityQuery('node')
      ->condition('type', 'expenses')
      ->condition('status', 1)
      ->accessCheck(FALSE);
    $total_exp_nids = $total_exp_query->execute();
    $data['total_expenses'] = count($total_exp_nids);

    // ── 5. MAJOR SITE STATUS (top projects with budget utilization) ──
    $projects_data = [];
    if (!empty($active_nids)) {
      $projects = Node::loadMultiple($active_nids);
      foreach ($projects as $project) {
        $allocated_raw = $project->get('field_allocated_budget')->value;
        $allocated = (float) $allocated_raw;

        // Calculate spent based on Purchase Orders
        $proj_po_query = \Drupal::entityQuery('node')
          ->condition('type', 'purchase_order')
          ->condition('field_project', $project->id())
          ->condition('status', 1)
          ->accessCheck(FALSE);
        $proj_po_nids = $proj_po_query->execute();
        $spent = 0;
        if (!empty($proj_po_nids)) {
          foreach (Node::loadMultiple($proj_po_nids) as $po) {
            $po_val = $po->get('field_amount_gst')->value;
            if (empty($po_val)) {
              $po_val = $po->get('field_basic_amount')->value;
            }
            $spent += (float) $po_val;
          }
        }

        $percent = $allocated > 0 ? round(($spent / $allocated) * 100) : 0;
        $status = $project->get('field_status')->value ?? 'active';
        $code = $project->get('field_project_code')->value ?? '';

        $projects_data[] = [
          'id' => $project->id(),
          'title' => $project->label(),
          'code' => $code,
          'status' => $status,
          'allocated' => $allocated,
          'spent' => $spent,
          'percent' => min($percent, 100),
          'url' => $project->toUrl()->toString(),
        ];
      }

      // Sort by utilization descending, take top 5
      usort($projects_data, function ($a, $b) {
        return $b['percent'] - $a['percent'];
      });
      $projects_data = array_slice($projects_data, 0, 5);
    }
    $data['projects'] = $projects_data;

    // ── 6. TOTAL BUDGET UTILIZATION ──
    $total_allocated = 0;
    $total_spent = 0;
    if (!empty($active_nids)) {
      foreach (Node::loadMultiple($active_nids) as $project) {
        $total_allocated += (float) $project->get('field_allocated_budget')->value;
      }
    }
    // Sum all purchase orders
    $total_po_query = \Drupal::entityQuery('node')
      ->condition('type', 'purchase_order')
      ->condition('status', 1)
      ->accessCheck(FALSE);
    $total_po_nids = $total_po_query->execute();
    if (!empty($total_po_nids)) {
      foreach (Node::loadMultiple($total_po_nids) as $po) {
        $po_val = $po->get('field_amount_gst')->value;
        if (empty($po_val)) {
          $po_val = $po->get('field_basic_amount')->value;
        }
        $total_spent += (float) $po_val;
      }
    }
    $data['total_allocated'] = $total_allocated;
    $data['total_spent'] = $total_spent;
    $data['total_utilization'] = $total_allocated > 0 ? round(($total_spent / $total_allocated) * 100) : 0;

    // ── 7. RECENT FINANCIAL ACTIVITY (last 10 entries) ──
    $recent_activity = [];

    // Fetch recent expenses
    $recent_exp_query = \Drupal::entityQuery('node')
      ->condition('type', 'expenses')
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->range(0, 5)
      ->accessCheck(FALSE);
    $recent_exp_nids = $recent_exp_query->execute();
    if (!empty($recent_exp_nids)) {
      foreach (Node::loadMultiple($recent_exp_nids) as $exp) {
        $recent_activity[] = [
          'title' => $exp->label(),
          'date' => \Drupal::service('date.formatter')->format($exp->getCreatedTime(), 'custom', 'M d, Y'),
          'type' => 'Expense',
          'type_class' => 'expense',
          'amount' => '₹' . number_format((float) $exp->get('field_amount')->value, 0),
          'url' => $exp->toUrl()->toString(),
          'timestamp' => $exp->getCreatedTime(),
        ];
      }
    }

    // Fetch recent POs
    $recent_po_query = \Drupal::entityQuery('node')
      ->condition('type', 'purchase_order')
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->range(0, 5)
      ->accessCheck(FALSE);
    $recent_po_nids = $recent_po_query->execute();
    if (!empty($recent_po_nids)) {
      foreach (Node::loadMultiple($recent_po_nids) as $po) {
        $amount = 0;
        if ($po->hasField('field_amount_gst') && !$po->get('field_amount_gst')->isEmpty()) {
          $amount = (float) $po->get('field_amount_gst')->value;
        } elseif ($po->hasField('field_basic_amount') && !$po->get('field_basic_amount')->isEmpty()) {
          $amount = (float) $po->get('field_basic_amount')->value;
        }
        $recent_activity[] = [
          'title' => $po->label(),
          'date' => \Drupal::service('date.formatter')->format($po->getCreatedTime(), 'custom', 'M d, Y'),
          'type' => 'Purchase Order',
          'type_class' => 'po',
          'amount' => '₹' . number_format($amount, 0),
          'url' => $po->toUrl()->toString(),
          'timestamp' => $po->getCreatedTime(),
        ];
      }
    }

    // Sort by timestamp descending
    usort($recent_activity, function ($a, $b) {
      return $b['timestamp'] - $a['timestamp'];
    });
    $recent_activity = array_slice($recent_activity, 0, 8);
    $data['recent_activity'] = $recent_activity;

    // Current month name
    $data['current_month'] = $now->format('F Y');

    // Auth status for CRUD link visibility
    $data['logged_in'] = \Drupal::currentUser()->isAuthenticated();

    return [
      '#theme' => 'dashboard_overview',
      '#data' => $data,
      '#cache' => [
        'max-age' => 300,
        'tags' => ['node_list:project_sites', 'node_list:expenses', 'node_list:purchase_order'],
        'contexts' => ['user.roles'],
      ],
    ];
  }

  /**
   * Format currency in Indian notation.
   */
  private function formatCurrency($amount) {
    if ($amount >= 10000000) {
      return '₹' . number_format($amount / 10000000, 1) . 'Cr';
    } elseif ($amount >= 100000) {
      return '₹' . number_format($amount / 100000, 1) . 'L';
    } elseif ($amount >= 1000) {
      return '₹' . number_format($amount / 1000, 1) . 'K';
    }
    return '₹' . number_format($amount, 0);
  }

  /**
   * Generates the Invoice Payment Tracking report.
   */
  public function trackingReport() {
    $request = \Drupal::request();
    $status_filter = $request->query->get('status', '');
    $due_filter = $request->query->get('due_date', '');
    $search = $request->query->get('search', '');
    $project_filter = $request->query->get('project', '');
    $invoice_no_filter = $request->query->get('invoice_no', '');
    $po_no_filter = $request->query->get('po_no', '');
    $month_filter = $request->query->get('month', '');
    $retention_filter = $request->query->get('retention', '');

    // Fetch all active project sites for selection dropdown
    $project_query = \Drupal::entityQuery('node')
      ->condition('type', 'project_sites')
      ->condition('status', 1)
      ->sort('title', 'ASC')
      ->accessCheck(FALSE);
    $project_nids = $project_query->execute();
    $projects_list = [];
    if (!empty($project_nids)) {
      $project_nodes = Node::loadMultiple($project_nids);
      foreach ($project_nodes as $p_node) {
        $projects_list[$p_node->id()] = $p_node->label();
      }
    }

    // Fetch all invoices
    $query = \Drupal::entityQuery('node')
      ->condition('type', 'invoice')
      ->accessCheck(FALSE);
    
    if (!empty($status_filter) && $status_filter !== 'overdue') {
      $query->condition('field_payment_status', $status_filter);
    }
    if (!empty($project_filter)) {
      $query->condition('field_project', $project_filter);
    }
    
    $nids = $query->execute();
    
    $invoices = [];
    if (!empty($nids)) {
      $invoices = Node::loadMultiple($nids);
    }

    // Extract month-year options dynamically from all matching/unfiltered invoices
    $all_months_query = \Drupal::entityQuery('node')
      ->condition('type', 'invoice')
      ->accessCheck(FALSE);
    $all_months_nids = $all_months_query->execute();
    $month_options = [];
    if (!empty($all_months_nids)) {
      $all_invoices = Node::loadMultiple($all_months_nids);
      foreach ($all_invoices as $inv) {
        $date_raw = $inv->get('field_invoice_date')->value;
        if ($date_raw) {
          $time = strtotime($date_raw);
          $key = date('Y-m', $time);
          $label = date('F Y', $time);
          $month_options[$key] = [
            'value' => $key,
            'label' => $label,
            'timestamp' => $time,
          ];
        }
      }
    }
    uasort($month_options, function ($a, $b) {
      return $b['timestamp'] <=> $a['timestamp'];
    });

    $total_invoiced = 0.0;
    $total_collected = 0.0;
    $total_outstanding = 0.0;
    $total_retention = 0.0;
    $overdue_count = 0;

    $rows = [];
    foreach ($invoices as $inv) {
      $inv_no = $inv->get('field_invoice_no')->value ?: '-';
      $fy = $inv->get('field_financial_year')->value ?: '-';
      $date_raw = $inv->get('field_invoice_date')->value;
      $date = $date_raw ? date('d.m.Y', strtotime($date_raw)) : '-';
      $timestamp = $date_raw ? strtotime($date_raw) : 0;
      
      $basic = (float) ($inv->get('field_basic_value')->value ?? 0.0);
      $total = (float) ($inv->get('field_basic_value_gst')->value ?? 0.0);
      $gst = $total - $basic;
      $tds = (float) ($inv->hasField('field_tds') ? ($inv->get('field_tds')->value ?? 0.0) : 0.0);
      $tds_paid = $inv->hasField('field_tds_paid') && !$inv->get('field_tds_paid')->isEmpty() ? (bool) $inv->get('field_tds_paid')->value : FALSE;
      $retention = (float) ($inv->hasField('field_retention') ? ($inv->get('field_retention')->value ?? 0.0) : 0.0);
      $paid = (float) ($inv->get('field_paid_amount')->value ?? 0.0);
      
      // Expected payment: Total (with GST) - TDS - Retention
      $expected = $total - $tds - $retention;
      $balance = max(0.0, $expected - $paid);
      
      $due_date_raw = $inv->get('field_payment_due_date')->value;
      $due_time = $due_date_raw ? strtotime($due_date_raw) : 0;
      $due_date = $due_date_raw ? date('d.m.Y', $due_time) : '-';
      
      $status = $inv->get('field_payment_status')->value ?: 'pending';
      
      // Auto-detect overdue status
      if ($due_time && $due_time < time() && $status !== 'paid') {
        $status = 'overdue';
      }

      // Load client/project info
      $project_name = '';
      $project_id = NULL;
      $project_node = !$inv->get('field_project')->isEmpty() ? $inv->get('field_project')->entity : NULL;
      if ($project_node) {
        $project_name = $project_node->label();
        $project_id = $project_node->id();
      }
      
      $client_name = '';
      if ($project_node && $project_node->hasField('field_client') && !$project_node->get('field_client')->isEmpty()) {
        $client_name = $project_node->get('field_client')->value;
      } else {
        $client_name = 'Aquatech Systems (Asia) Pvt. Ltd.'; // Default client
      }

      // Load PO info
      $po_no = '-';
      $po_id = NULL;
      $po_node = !$inv->get('field_purchase_order')->isEmpty() ? $inv->get('field_purchase_order')->entity : NULL;
      if ($po_node) {
        $po_no = $po_node->get('field_po_number')->value ?: $po_node->label();
        $po_id = $po_node->id();
      }

      // Load RA Bill number info
      $ra_num = '-';
      $ra_node = !$inv->get('field_ra_bill')->isEmpty() ? $inv->get('field_ra_bill')->entity : NULL;
      if ($ra_node) {
        $ra_bill_num = $ra_node->get('field_ra_bill_number')->value;
        if (!empty($ra_bill_num)) {
          $ra_num = 'RA-' . intval($ra_bill_num);
        }
      }
      if ($ra_num === '-') {
        // Fallback: parse from title
        $node_title = $inv->label();
        if (preg_match('/RA-(\d+)/', $node_title, $matches)) {
          $ra_num = 'RA-' . intval($matches[1]);
        } elseif (preg_match('/RA Bill\s*#?(\d+)/', $node_title, $matches)) {
          $ra_num = 'RA-' . intval($matches[1]);
        }
      }

      // Apply Search Filter (Search by invoice number, client/project name, or PO number)
      if (!empty($search)) {
        $search_lower = mb_strtolower($search);
        $matches_inv = (strpos(mb_strtolower($inv_no), $search_lower) !== FALSE);
        $matches_client = (strpos(mb_strtolower($client_name), $search_lower) !== FALSE);
        $matches_project = (strpos(mb_strtolower($project_name), $search_lower) !== FALSE);
        $matches_po = (strpos(mb_strtolower($po_no), $search_lower) !== FALSE);
        if (!$matches_inv && !$matches_client && !$matches_project && !$matches_po) {
          continue;
        }
      }

      // Apply Invoice No Filter
      if (!empty($invoice_no_filter)) {
        $inv_no_lower = mb_strtolower($inv_no);
        $filter_lower = mb_strtolower($invoice_no_filter);
        if (strpos($inv_no_lower, $filter_lower) === FALSE) {
          continue;
        }
      }

      // Apply PO Number Filter
      if (!empty($po_no_filter)) {
        $po_no_lower = mb_strtolower($po_no);
        $filter_lower = mb_strtolower($po_no_filter);
        if (strpos($po_no_lower, $filter_lower) === FALSE) {
          continue;
        }
      }

      // Apply Month Filter
      if (!empty($month_filter)) {
        if (!$date_raw || date('Y-m', strtotime($date_raw)) !== $month_filter) {
          continue;
        }
      }

      // Apply Status Filter for Overdue
      if ($status_filter === 'overdue' && $status !== 'overdue') {
        continue;
      }

      // Apply Due Date Filter
      if (!empty($due_filter)) {
        if ($due_filter === 'overdue') {
          if ($status !== 'overdue') {
            continue;
          }
        } elseif ($due_filter === 'this_week') {
          $one_week_later = strtotime('+7 days');
          if (!$due_time || $due_time < time() || $due_time > $one_week_later) {
            continue;
          }
        } elseif ($due_filter === 'this_month') {
          $one_month_later = strtotime('+30 days');
          if (!$due_time || $due_time < time() || $due_time > $one_month_later) {
            continue;
          }
        }
      }

      // Apply Retention Filter
      if (!empty($retention_filter)) {
        if ($retention_filter === 'yes') {
          if ($retention <= 0.0) {
            continue;
          }
        } elseif ($retention_filter === 'no') {
          if ($retention > 0.0) {
            continue;
          }
        }
      }

      // Compute general KPI metrics on all matching search/filters
      $total_invoiced += $total;
      $total_collected += $paid;
      $total_outstanding += $balance;
      $total_retention += $retention;
      if ($status === 'overdue') {
        $overdue_count++;
      }

      $rows[] = [
        'id' => $inv->id(),
        'invoice_no' => $inv_no,
        'project' => $project_name,
        'project_id' => $project_id,
        'client' => $client_name,
        'po_no' => $po_no,
        'po_id' => $po_id,
        'ra_num' => $ra_num,
        'fy' => $fy,
        'date' => $date,
        'timestamp' => $timestamp,
        'basic' => '₹' . $this->formatIndianCurrency($basic),
        'basic_raw' => $basic,
        'gst' => '₹' . $this->formatIndianCurrency($gst),
        'gst_raw' => $gst,
        'tds' => '₹' . $this->formatIndianCurrency($tds),
        'tds_raw' => $tds,
        'tds_paid' => $tds_paid,
        'retention' => '₹' . $this->formatIndianCurrency($retention),
        'retention_raw' => $retention,
        'total' => '₹' . $this->formatIndianCurrency($total),
        'total_raw' => $total,
        'expected' => '₹' . $this->formatIndianCurrency($expected),
        'expected_raw' => $expected,
        'paid' => '₹' . $this->formatIndianCurrency($paid),
        'paid_raw' => $paid,
        'balance' => '₹' . $this->formatIndianCurrency($balance),
        'balance_raw' => $balance,
        'due_date' => $due_date,
        'status' => $status,
        'status_label' => ucfirst($status),
        'is_overdue' => ($status === 'overdue'),
      ];
    }

    // Sort matching invoices by Date descending, then ID descending
    usort($rows, function ($a, $b) {
      $time_a = $a['timestamp'];
      $time_b = $b['timestamp'];
      if ($time_a === $time_b) {
        return $b['id'] <=> $a['id'];
      }
      return $time_b <=> $time_a;
    });

    // Handle Excel Export Request
    $export = $request->query->get('export', '');
    if ($export === 'excel') {
      $spreadsheet = new Spreadsheet();
      $sheet = $spreadsheet->getActiveSheet();
      $sheet->setTitle('Invoices Tracking');
      $sheet->setShowGridlines(TRUE);

      // Styles
      $titleStyle = [
        'font' => [
          'bold' => TRUE,
          'size' => 14,
          'name' => 'Calibri',
          'color' => ['rgb' => '1E293B'],
        ],
        'alignment' => [
          'horizontal' => Alignment::HORIZONTAL_LEFT,
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
          'startColor' => ['rgb' => '1E293B'], // Slate Blue
        ],
        'alignment' => [
          'horizontal' => Alignment::HORIZONTAL_CENTER,
          'vertical' => Alignment::VERTICAL_CENTER,
          'wrapText' => TRUE,
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
          'size' => 10,
          'name' => 'Calibri',
        ],
        'fill' => [
          'fillType' => Fill::FILL_SOLID,
          'startColor' => ['rgb' => 'F1F5F9'],
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

      // Title Block
      $sheet->setCellValue('A1', 'Invoice Payment Tracking Report');
      $sheet->getStyle('A1')->applyFromArray($titleStyle);
      $sheet->getRowDimension('1')->setRowHeight(35);
      $sheet->mergeCells('A1:R1');

      // Date Exported info
      $sheet->setCellValue('A2', 'Exported On: ' . date('d.m.Y H:i:s'));
      $sheet->getStyle('A2')->getFont()->setItalic(TRUE)->setSize(9);

      // KPI stat block (mini dashboard at the top of excel)
      $sheet->setCellValue('A4', 'TOTAL INVOICED');
      $sheet->setCellValue('B4', $total_invoiced);
      $sheet->setCellValue('D4', 'TOTAL COLLECTED');
      $sheet->setCellValue('E4', $total_collected);
      $sheet->setCellValue('G4', 'TOTAL OUTSTANDING');
      $sheet->setCellValue('H4', $total_outstanding);
      $sheet->setCellValue('J4', 'TOTAL RETENTION');
      $sheet->setCellValue('K4', $total_retention);

      foreach (['A4', 'D4', 'G4', 'J4'] as $kpiLabelCol) {
        $sheet->getStyle($kpiLabelCol)->getFont()->setBold(TRUE)->setSize(9)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('64748B'));
      }
      foreach (['B4', 'E4', 'H4', 'K4'] as $kpiValueCol) {
        $sheet->getStyle($kpiValueCol)->getFont()->setBold(TRUE)->setSize(11);
        $sheet->getStyle($kpiValueCol)->getNumberFormat()->setFormatCode('"₹"#,##0.00');
      }

      // Headers row at line 6
      $headers = [
        'Invoice No',
        'Project',
        'Client',
        'PO Number',
        'RA Bill',
        'Financial Year',
        'Invoice Date',
        'Basic Value',
        'GST (18%)',
        'Gross Value',
        'TDS (2%)',
        'TDS Paid',
        'Retention',
        'Net Receivable',
        'Total Paid',
        'Outstanding Balance',
        'Due Date',
        'Status',
      ];

      foreach ($headers as $colIdx => $headerText) {
        $colLetter = Coordinate::stringFromColumnIndex($colIdx + 1);
        $sheet->setCellValue($colLetter . '6', $headerText);
      }
      $sheet->getStyle('A6:R6')->applyFromArray($headerStyle);
      $sheet->getRowDimension('6')->setRowHeight(28);

      $excelRow = 7;
      foreach ($rows as $row) {
        $sheet->setCellValue('A' . $excelRow, $row['invoice_no']);
        $sheet->setCellValue('B' . $excelRow, $row['project']);
        $sheet->setCellValue('C' . $excelRow, $row['client']);
        $sheet->setCellValue('D' . $excelRow, $row['po_no']);
        $sheet->setCellValue('E' . $excelRow, $row['ra_num']);
        $sheet->setCellValue('F' . $excelRow, $row['fy']);
        $sheet->setCellValue('G' . $excelRow, $row['date']);
        $sheet->setCellValue('H' . $excelRow, $row['basic_raw']);
        $sheet->setCellValue('I' . $excelRow, $row['gst_raw']);
        $sheet->setCellValue('J' . $excelRow, $row['total_raw']);
        $sheet->setCellValue('K' . $excelRow, $row['tds_raw']);
        $sheet->setCellValue('L' . $excelRow, $row['tds_paid'] ? 'Yes' : 'No');
        $sheet->setCellValue('M' . $excelRow, $row['retention_raw']);
        $sheet->setCellValue('N' . $excelRow, $row['expected_raw']);
        $sheet->setCellValue('O' . $excelRow, $row['paid_raw']);
        $sheet->setCellValue('P' . $excelRow, $row['balance_raw']);
        $sheet->setCellValue('Q' . $excelRow, $row['due_date']);
        $sheet->setCellValue('R' . $excelRow, $row['status_label']);

        // Data styles
        $sheet->getStyle("A$excelRow:R$excelRow")->applyFromArray($dataStyle);
        $sheet->getStyle("H$excelRow:K$excelRow")->getNumberFormat()->setFormatCode('"₹"#,##0.00');
        $sheet->getStyle("M$excelRow:P$excelRow")->getNumberFormat()->setFormatCode('"₹"#,##0.00');
        $sheet->getStyle("A$excelRow")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("E$excelRow:G$excelRow")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("L$excelRow")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("Q$excelRow:R$excelRow")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $excelRow++;
      }

      // Summary / Totals Row
      $sheet->setCellValue('A' . $excelRow, 'Total');
      $sheet->mergeCells("A$excelRow:G$excelRow");
      $sheet->getStyle("A$excelRow:G$excelRow")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

      $sheet->setCellValue('H' . $excelRow, '=SUM(H7:H' . ($excelRow - 1) . ')');
      $sheet->setCellValue('I' . $excelRow, '=SUM(I7:I' . ($excelRow - 1) . ')');
      $sheet->setCellValue('J' . $excelRow, '=SUM(J7:J' . ($excelRow - 1) . ')');
      $sheet->setCellValue('K' . $excelRow, '=SUM(K7:K' . ($excelRow - 1) . ')');
      $sheet->setCellValue('M' . $excelRow, '=SUM(M7:M' . ($excelRow - 1) . ')');
      $sheet->setCellValue('N' . $excelRow, '=SUM(N7:N' . ($excelRow - 1) . ')');
      $sheet->setCellValue('O' . $excelRow, '=SUM(O7:O' . ($excelRow - 1) . ')');
      $sheet->setCellValue('P' . $excelRow, '=SUM(P7:P' . ($excelRow - 1) . ')');

      $sheet->getStyle("A$excelRow:R$excelRow")->applyFromArray($totalStyle);
      $sheet->getStyle("H$excelRow:K$excelRow")->getNumberFormat()->setFormatCode('"₹"#,##0.00');
      $sheet->getStyle("M$excelRow:P$excelRow")->getNumberFormat()->setFormatCode('"₹"#,##0.00');

      // Auto-fit Columns
      for ($col = 1; $col <= 18; $col++) {
        $colLetter = Coordinate::stringFromColumnIndex($col);
        $sheet->getColumnDimension($colLetter)->setAutoSize(TRUE);
      }

      $writer = new Xlsx($spreadsheet);
      $response = new Response();
      $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
      $response->headers->set('Content-Disposition', 'attachment; filename="invoices_report_' . date('Y-m-d') . '.xlsx"');
      $response->headers->set('Cache-Control', 'max-age=0');

      ob_start();
      $writer->save('php://output');
      $content = ob_get_clean();
      $response->setContent($content);

      return $response;
    }

    $kpis = [
      'total_invoiced' => '₹' . $this->formatIndianCurrency($total_invoiced),
      'total_collected' => '₹' . $this->formatIndianCurrency($total_collected),
      'total_outstanding' => '₹' . $this->formatIndianCurrency($total_outstanding),
      'total_retention' => '₹' . $this->formatIndianCurrency($total_retention),
      'overdue_count' => $overdue_count,
    ];

    return [
      '#theme' => 'invoice_tracking_report',
      '#rows' => $rows,
      '#kpis' => $kpis,
      '#projects_list' => $projects_list,
      '#month_options' => $month_options,
      '#filters' => [
        'status' => $status_filter,
        'due_date' => $due_filter,
        'search' => $search,
        'project' => $project_filter,
        'invoice_no' => $invoice_no_filter,
        'po_no' => $po_no_filter,
        'month' => $month_filter,
        'retention' => $retention_filter,
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Helper to format Indian currency.
   */
  private function formatIndianCurrency($number) {
    $number = (float) $number;
    $decimal = "";
    if (strpos((string) $number, '.') !== FALSE) {
      list($num_part, $dec_part) = explode('.', sprintf("%.2f", $number));
      $decimal = "." . $dec_part;
    } else {
      $decimal = ".00";
      $num_part = (string) $number;
    }
    $number_val = (int) $num_part;
    $number_str = (string) $number_val;
    $length = strlen($number_str);
    if ($length <= 3) {
      return $number_str . $decimal;
    }
    $last_three = substr($number_str, -3);
    $remaining = substr($number_str, 0, -3);
    $remaining_reversed = strrev($remaining);
    $chunks = str_split($remaining_reversed, 2);
    $chunks_imploded = implode(',', $chunks);
    $chunks_imploded_correct = strrev($chunks_imploded);
    return $chunks_imploded_correct . ',' . $last_three . $decimal;
  }

}
