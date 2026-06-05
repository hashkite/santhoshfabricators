<?php

namespace Drupal\ra_bill_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller to handle generating Invoice nodes from RA Bill data.
 */
class InvoiceGenerationController extends ControllerBase
{

  /**
   * Generates a draft Invoice from an RA Bill node.
   */
  public function generateInvoice($node)
  {
    if (is_numeric($node)) {
      $node = Node::load($node);
    }
    if (!$node || $node->bundle() !== 'ra_bill') {
      throw new NotFoundHttpException();
    }

    // 1. Fetch metadata from RA Bill
    $project_id = !$node->get('field_project')->isEmpty() ? $node->get('field_project')->target_id : NULL;
    $po_id = !$node->get('field_purchase_order')->isEmpty() ? $node->get('field_purchase_order')->target_id : NULL;
    $bill_date_val = $node->get('field_bill_date')->value;
    $basic_amount = floatval($node->get('field_basic_amount')->value ?? 0.0);
    $total_amount = floatval($node->get('field_total_amount')->value ?? 0.0);
    $ra_no = $node->get('field_ra_bill_number')->value ?? '';
    $ra_formatted = $ra_no ? sprintf('%02d', intval($ra_no)) : '';
    $bill_no = $node->get('field_bill_number')->value ?? '';

    // Calculate Financial Year from Bill Date
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
    } else {
      $fy = date('Y') . '-' . substr(strval(date('Y') + 1), 2);
    }

    // Generate Temporary Invoice Number in GJ/[FY]/[RA_No] format
    $ra_num_str = $ra_no ? sprintf('%02d', intval($ra_no)) : sprintf('%02d', $node->id());
    $temp_inv_no = 'GJ/' . $fy . '/' . $ra_num_str;

    // Calculate final basic and total amounts
    $calculated_basic = 0.0;
    if ($node->hasField('field_ra_bill_items') && !$node->get('field_ra_bill_items')->isEmpty()) {
      $ra_paragraphs = $node->get('field_ra_bill_items')->referencedEntities();
      foreach ($ra_paragraphs as $ra_item) {
        if ($ra_item->bundle() !== 'ra_bill_item') {
          continue;
        }
        $boq_item = !$ra_item->get('field_boq_item')->isEmpty() ? $ra_item->get('field_boq_item')->entity : NULL;
        $current_qty = floatval($ra_item->get('field_current_qty')->value ?? 0.0);
        $unit_rate = $boq_item ? floatval($boq_item->get('field_unit_rate')->value ?? 0.0) : 0.0;
        $calculated_basic += $current_qty * $unit_rate;
      }
    }

    $final_basic = $calculated_basic > 0 ? $calculated_basic : $basic_amount;
    $final_total = $total_amount > 0 ? $total_amount : ($final_basic * 1.18);

    // ── Duplicate Invoice Prevention ──
    // Check if an invoice already exists for this RA Bill with the same total amount.
    $existing_invoices = \Drupal::entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'invoice')
      ->condition('title', '%RA Bill%' . ($ra_formatted ?: $ra_no) . '%', 'LIKE')
      ->condition('field_purchase_order', $po_id ?? 0)
      ->execute();

    if (!empty($existing_invoices)) {
      foreach ($existing_invoices as $existing_id) {
        $existing_invoice = Node::load($existing_id);
        if ($existing_invoice) {
          $existing_total = floatval($existing_invoice->get('field_basic_value_gst')->value ?? 0.0);
          if (abs($existing_total - $final_total) < 0.01) {
            $this->messenger()->addWarning($this->t('An invoice already exists for this RA Bill with the same amount. <a href=":url">View existing invoice</a>.', [
              ':url' => \Drupal\Core\Url::fromRoute('entity.node.canonical', ['node' => $existing_id])->toString(),
            ]));
            return $this->redirect('entity.node.canonical', ['node' => $node->id()]);
          }
        }
      }
    }

    // 2. Create a single consolidated Invoice Item representing the RA Bill
    $project_name = '';
    $project_code = '';
    if ($project_id) {
      $project_node = Node::load($project_id);
      if ($project_node) {
        $project_name = $project_node->label();
        $project_code = $project_node->hasField('field_project_code') && !$project_node->get('field_project_code')->isEmpty()
          ? $project_node->get('field_project_code')->value : '';
      }
    }

    $company_sac = \Drupal::config('power_alpha_helper.invoice_settings')->get('company_sac') ?: '995442';

    $site_str = $project_code ? "SITE-" . $project_code : "SITE";
    if ($project_name) {
      $site_str .= "-" . $project_name;
    }

    $inv_desc = "Installation Cost\n" . $site_str . "\n\nHSN/SAC: " . $company_sac . "\nPR/ITEM: / 00000";
    $inv_desc = mb_substr($inv_desc, 0, 255);

    $invoice_items = [];
    $invoice_item = Paragraph::create([
      'type' => 'invoice_item',
      'field_description' => $inv_desc,
      'field_quantity' => 1.0,
      'field_rate' => $final_basic,
      'field_uom' => 'PU',
    ]);
    $invoice_item->save();

    $invoice_items[] = [
      'target_id' => $invoice_item->id(),
      'target_revision_id' => $invoice_item->getRevisionId(),
    ];

    // 3. Create the Invoice Node
    $invoice_title = 'Invoice for RA Bill ' . ($ra_formatted ? '#' . $ra_formatted : ($bill_no ?: '#' . $node->id()));

    $invoice = Node::create([
      'type' => 'invoice',
      'title' => $invoice_title,
      'field_project' => $project_id,
      'field_purchase_order' => $po_id,
      'field_invoice_date' => $bill_date_val,
      'field_financial_year' => $fy,
      'field_invoice_no' => $temp_inv_no,
      'field_basic_value' => $final_basic,
      'field_basic_value_gst' => $final_total,
      'field_invoice_items' => $invoice_items,
      'status' => 1, // Keep as unpublished draft initially
    ]);

    try {
      $invoice->save();

      // Unpublish existing invoices for this RA Bill since a new one is created
      if (!empty($existing_invoices)) {
        foreach ($existing_invoices as $existing_id) {
          $existing_invoice = Node::load($existing_id);
          if ($existing_invoice && $existing_invoice->isPublished()) {
            $existing_invoice->setUnpublished();
            $existing_invoice->save();
          }
        }
      }

      $this->messenger()->addStatus($this->t('Draft invoice generated successfully from RA Bill @ra. Please review the @count line items and input the exact invoice number before publishing.', [
        '@ra' => $ra_no ? '#' . $ra_no : '#' . $node->id(),
        '@count' => count($invoice_items),
      ]));
      // Redirect to the node edit form
      return $this->redirect('entity.node.canonical', ['node' => $invoice->id()]);
    } catch (\Exception $e) {
      // Clean up any created paragraphs on failure
      foreach ($invoice_items as $item_ref) {
        $p = Paragraph::load($item_ref['target_id']);
        if ($p) {
          $p->delete();
        }
      }
      $this->messenger()->addError($this->t('Failed to generate invoice: @msg', ['@msg' => $e->getMessage()]));
      return $this->redirect('entity.node.canonical', ['node' => $node->id()]);
    }
  }

}
