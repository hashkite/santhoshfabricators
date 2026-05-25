<?php

namespace Drupal\power_alpha_helper\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\Entity\Node;

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

}
