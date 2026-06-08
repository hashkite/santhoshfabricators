<?php

namespace Drupal\ra_bill_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\Entity\Node;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller for inline editing of RA Bill item quantities.
 *
 * Handles AJAX requests from the Abstract table view to update
 * 'IN THIS BILL' quantities without navigating to the node edit form.
 */
class InlineEditController extends ControllerBase {

  /**
   * Updates quantities for RA Bill items via AJAX.
   *
   * Expects a JSON POST body with:
   * - updates: array of {paragraph_id, current_qty}
   *
   * Returns JSON with recalculated row data and updated totals.
   */
  public function updateQuantities($node, Request $request) {
    if (is_numeric($node)) {
      $node = Node::load($node);
    }
    if (!$node || $node->bundle() !== 'ra_bill') {
      return new JsonResponse(['error' => 'Invalid RA Bill node.'], 400);
    }

    $content = json_decode($request->getContent(), TRUE);
    if (empty($content['updates']) || !is_array($content['updates'])) {
      return new JsonResponse(['error' => 'No updates provided.'], 400);
    }

    /** @var \Drupal\ra_bill_manager\Validation\BOQValidator $boq_validator */
    $boq_validator = \Drupal::service('ra_bill_manager.boq_validator');

    $updated_rows = [];
    $total_basic = 0.0;

    // Process each update
    foreach ($content['updates'] as $update) {
      $paragraph_id = $update['paragraph_id'] ?? NULL;
      $new_qty = floatval($update['current_qty'] ?? 0.0);

      if (!$paragraph_id) {
        continue;
      }

      $paragraph = \Drupal::entityTypeManager()->getStorage('paragraph')->load($paragraph_id);
      if (!$paragraph || $paragraph->bundle() !== 'ra_bill_item') {
        continue;
      }

      // Update the current quantity
      $paragraph->set('field_current_qty', $new_qty);

      // Recalculate with BOQ validation
      $boq_item_id = !$paragraph->get('field_boq_item')->isEmpty() ? $paragraph->get('field_boq_item')->target_id : NULL;

      if ($boq_item_id) {
        $boq_item = \Drupal::entityTypeManager()->getStorage('node')->load($boq_item_id);
        if ($boq_item) {
          $prev_qty = $boq_validator->getPreviousQuantity($boq_item_id, $node->id());
          $validation = $boq_validator->validate($boq_item, $new_qty, $prev_qty);

          $unit_rate = floatval($boq_item->get('field_unit_rate')->value ?? 0.0);
          $cumulative_qty = $prev_qty + $new_qty;
          $amount_claimed = $new_qty * $unit_rate;

          $paragraph->set('field_previous_qty', $prev_qty);
          $paragraph->set('field_cumulative_qty', $cumulative_qty);
          $paragraph->set('field_amount_claimed', $amount_claimed);
          $paragraph->set('field_validation_status', $validation['status']);
          $paragraph->save();

          $approved_qty = floatval($boq_item->get('field_approved_quantity')->value ?? 0.0);
          $po_amount = $approved_qty * $unit_rate;
          $prev_amount = $prev_qty * $unit_rate;
          $current_amount = $new_qty * $unit_rate;
          $cumulative_amount = $cumulative_qty * $unit_rate;

          $updated_rows[] = [
            'paragraph_id' => $paragraph_id,
            'current_qty' => $new_qty,
            'cumulative_qty' => $cumulative_qty,
            'prev_qty' => $prev_qty,
            'po_amount' => $po_amount,
            'prev_amount' => $prev_amount,
            'current_amount' => $current_amount,
            'cumulative_amount' => $cumulative_amount,
            'validation_status' => $validation['status'],
          ];
        }
      }
    }

    // Recalculate all items for the node totals
    $all_items = $node->get('field_ra_bill_items')->referencedEntities();
    $calculated_basic = 0.0;
    $total_po_amount = 0.0;
    $total_prev_amount = 0.0;
    $total_this_bill_amount = 0.0;
    $total_upto_date_amount = 0.0;
    $has_over_claimed = FALSE;

    foreach ($all_items as $item) {
      if ($item->bundle() !== 'ra_bill_item') {
        continue;
      }

      $boq_item = !$item->get('field_boq_item')->isEmpty() ? $item->get('field_boq_item')->entity : NULL;
      if (!$boq_item) {
        continue;
      }

      $unit_rate = floatval($boq_item->get('field_unit_rate')->value ?? 0.0);
      $approved_qty = floatval($boq_item->get('field_approved_quantity')->value ?? 0.0);
      $current_qty = floatval($item->get('field_current_qty')->value ?? 0.0);
      $prev_qty = floatval($item->get('field_previous_qty')->value ?? 0.0);
      $cumulative_qty = floatval($item->get('field_cumulative_qty')->value ?? 0.0);

      $total_po_amount += $approved_qty * $unit_rate;
      $total_prev_amount += $prev_qty * $unit_rate;
      $total_this_bill_amount += $current_qty * $unit_rate;
      $total_upto_date_amount += $cumulative_qty * $unit_rate;
      $calculated_basic += $current_qty * $unit_rate;

      if ($item->get('field_validation_status')->value === 'over_claimed') {
        $has_over_claimed = TRUE;
      }
    }

    // Update node totals
    $cgst = $calculated_basic * 0.09;
    $sgst = $calculated_basic * 0.09;
    $total = $calculated_basic + $cgst + $sgst;

    $node->set('field_basic_amount', $calculated_basic);
    $node->set('field_cgst', $cgst);
    $node->set('field_sgst', $sgst);
    $node->set('field_total_amount', $total);
    $node->set('field_validation_status', $has_over_claimed ? 'need_review' : 'verified');
    $node->save();

    return new JsonResponse([
      'success' => TRUE,
      'rows' => $updated_rows,
      'totals' => [
        'basic_amount' => $calculated_basic,
        'total_po_amount' => $total_po_amount,
        'total_prev_amount' => $total_prev_amount,
        'total_this_bill_amount' => $total_this_bill_amount,
        'total_upto_date_amount' => $total_upto_date_amount,
        'total_amount' => $total,
      ],
    ]);
  }

  /**
   * Automatically creates the next RA Bill based on the current one.
   */
  public function createNextBill($node) {
    if (is_numeric($node)) {
      $node = Node::load($node);
    }
    if (!$node || $node->bundle() !== 'ra_bill') {
      throw new NotFoundHttpException();
    }

    $po_id = !$node->get('field_purchase_order')->isEmpty() ? $node->get('field_purchase_order')->target_id : NULL;
    $project_id = !$node->get('field_project')->isEmpty() ? $node->get('field_project')->target_id : NULL;
    $vendor_id = !$node->get('field_vendor')->isEmpty() ? $node->get('field_vendor')->target_id : NULL;
    $current_ra_num = intval($node->get('field_ra_bill_number')->value ?? 0);
    $next_ra_num = $current_ra_num + 1;

    // Parse and dynamically increment the bill number format
    $prev_bill_no = !$node->get('field_bill_number')->isEmpty() ? $node->get('field_bill_number')->value : '';
    $next_bill_no = '';
    if (!empty($prev_bill_no)) {
      if (preg_match('/^([^\/]+)\/([0-9]{4}-[0-9]{2})\/([0-9]+)/', $prev_bill_no, $matches)) {
        $prefix = $matches[1];
        $fy = $matches[2];
        $next_bill_no = $prefix . '/' . $fy . '/' . $next_ra_num;
      }
      elseif (preg_match('/^([0-9]+)$/', $prev_bill_no, $matches)) {
        $next_bill_no = strval(intval($matches[1]) + 1);
      }
      elseif (preg_match('/^(.*[^0-9])([0-9]+)$/', $prev_bill_no, $matches)) {
        $base = $matches[1];
        $num = intval($matches[2]);
        $next_bill_no = $base . ($num + 1);
      }
      else {
        $next_bill_no = $prev_bill_no . '-next';
      }
    }

    // Create the next RA Bill node
    $next_bill = Node::create([
      'type' => 'ra_bill',
      'title' => 'RA Bill ' . $next_ra_num,
      'field_purchase_order' => $po_id,
      'field_project' => $project_id,
      'field_vendor' => $vendor_id,
      'field_ra_bill_number' => $next_ra_num,
      'field_bill_number' => $next_bill_no,
      'field_bill_date' => date('Y-m-d'), // default to today
      'status' => 1,
      'moderation_state' => 'draft',
    ]);

    // Copy item details, carry quantities
    $next_paragraphs = [];
    if ($node->hasField('field_ra_bill_items') && !$node->get('field_ra_bill_items')->isEmpty()) {
      $current_items = $node->get('field_ra_bill_items')->referencedEntities();
      foreach ($current_items as $current_item) {
        if ($current_item->bundle() !== 'ra_bill_item') {
          continue;
        }

        $boq_item_id = !$current_item->get('field_boq_item')->isEmpty() ? $current_item->get('field_boq_item')->target_id : NULL;
        $item_code = $current_item->get('field_item_code')->value ?? '';
        $description = $current_item->get('field_item_description')->value ?? '';
        
        // Cumulative Qty in current bill becomes Previous Qty in the next bill
        $prev_qty = floatval($current_item->get('field_cumulative_qty')->value ?? 0.0);
        $current_qty = 0.0; // Starts at 0
        $cumulative_qty = $prev_qty + $current_qty; // Which is just prev_qty

        // Get UOM and rate from BOQ validator/node
        $unit_rate = 0.0;
        if ($boq_item_id) {
          $boq_item = Node::load($boq_item_id);
          if ($boq_item) {
            $unit_rate = floatval($boq_item->get('field_unit_rate')->value ?? 0.0);
          }
        }
        $amount_claimed = $current_qty * $unit_rate;

        // Create new paragraph item
        $new_paragraph = \Drupal::entityTypeManager()->getStorage('paragraph')->create([
          'type' => 'ra_bill_item',
          'field_boq_item' => $boq_item_id,
          'field_item_code' => $item_code,
          'field_item_description' => $description,
          'field_previous_qty' => $prev_qty,
          'field_current_qty' => $current_qty,
          'field_cumulative_qty' => $cumulative_qty,
          'field_amount_claimed' => $amount_claimed,
          'field_validation_status' => 'valid', // starts as valid with 0 current_qty
        ]);
        $new_paragraph->save();
        $next_paragraphs[] = $new_paragraph;
      }
    }
    
    $next_bill->set('field_ra_bill_items', $next_paragraphs);

    try {
      $next_bill->save();
      
      // Run recalculation on the entire PO just in case
      if ($po_id && function_exists('ra_bill_manager_recalculate_po_bills')) {
        ra_bill_manager_recalculate_po_bills($po_id);
      }

      $this->messenger()->addStatus($this->t('Next RA Bill (RA No. @num) created successfully.', [
        '@num' => $next_ra_num,
      ]));
      return $this->redirect('entity.node.canonical', ['node' => $next_bill->id()]);
    }
    catch (\Exception $e) {
      // Clean up paragraphs if save failed
      foreach ($next_paragraphs as $p) {
        $p->delete();
      }
      $this->messenger()->addError($this->t('Failed to create Next RA Bill: @msg', ['@msg' => $e->getMessage()]));
      return $this->redirect('entity.node.canonical', ['node' => $node->id()]);
    }
  }

  /**
   * Adds a new item to the RA Bill.
   *
   * Expects a JSON payload with:
   * - item_code (custom code)
   * - description (custom description)
   * - uom (custom unit of measurement)
   * - rate (custom unit rate)
   * - approved_qty (custom approved quantity)
   * - current_qty (initial quantity for this bill)
   */
  public function addItem($node, Request $request) {
    if (is_numeric($node)) {
      $node = Node::load($node);
    }
    if (!$node || $node->bundle() !== 'ra_bill') {
      return new JsonResponse(['error' => 'Invalid RA Bill node.'], 400);
    }

    $content = json_decode($request->getContent(), TRUE);
    $current_qty = floatval($content['current_qty'] ?? 0.0);

    $po_id = !$node->get('field_purchase_order')->isEmpty() ? $node->get('field_purchase_order')->target_id : NULL;
    $project_id = !$node->get('field_project')->isEmpty() ? $node->get('field_project')->target_id : NULL;

    if (!$project_id) {
      return new JsonResponse(['error' => 'RA Bill does not have an associated project.'], 400);
    }

    /** @var \Drupal\ra_bill_manager\Validation\BOQValidator $boq_validator */
    $boq_validator = \Drupal::service('ra_bill_manager.boq_validator');
    /** @var \Drupal\ra_bill_manager\Paragraph\ParagraphManager $paragraph_creator */
    $paragraph_creator = \Drupal::service('ra_bill_manager.paragraph_creator');

    // Create or find a matching BOQ item
    $item_code = trim($content['item_code'] ?? '');
    $description = trim($content['description'] ?? '');
    $uom = trim($content['uom'] ?? 'Nos');
    $rate = floatval($content['rate'] ?? 0.0);
    $approved_qty = floatval($content['approved_qty'] ?? 0.0);

    if (empty($description)) {
      return new JsonResponse(['error' => 'Description is required.'], 400);
    }

    $boq_item = $boq_validator->getOrCreateBOQItem($project_id, $item_code, $description, $uom, $approved_qty, $rate);

    // Check if this BOQ item is already in the RA Bill
    if ($node->hasField('field_ra_bill_items') && !$node->get('field_ra_bill_items')->isEmpty()) {
      $existing_items = $node->get('field_ra_bill_items')->referencedEntities();
      foreach ($existing_items as $existing_item) {
        if ($existing_item->bundle() === 'ra_bill_item') {
          $existing_boq_id = !$existing_item->get('field_boq_item')->isEmpty() ? $existing_item->get('field_boq_item')->target_id : NULL;
          if ($existing_boq_id == $boq_item->id()) {
            return new JsonResponse(['error' => 'This item is already added to this RA Bill.'], 400);
          }
        }
      }
    }

    // 2. Create the ra_bill_item paragraph
    $item_data = [
      'item_code' => $boq_item->get('field_item_code')->value ?? '',
      'description' => $boq_item->get('field_item_description')->value ?? '',
      'uom' => $boq_item->get('field_unit')->value ?? 'Nos',
      'po_qty' => floatval($boq_item->get('field_approved_quantity')->value ?? 0.0),
      'rate' => floatval($boq_item->get('field_unit_rate')->value ?? 0.0),
      'current_qty' => $current_qty,
    ];

    try {
      $paragraph = $paragraph_creator->createBillItem($node->id(), $project_id, $item_data);
      
      // Link the new paragraph to the bill node
      $node->get('field_ra_bill_items')->appendItem($paragraph);
      $node->save();

      // Recalculate subsequent bills
      if ($po_id && function_exists('ra_bill_manager_recalculate_po_bills')) {
        ra_bill_manager_recalculate_po_bills($po_id);
      }

      // Re-load the updated paragraph to get recalculated prev_qty, cumulative_qty, validation status
      $paragraph = \Drupal::entityTypeManager()->getStorage('paragraph')->load($paragraph->id());
      $prev_qty = floatval($paragraph->get('field_previous_qty')->value ?? 0.0);
      $cumulative_qty = floatval($paragraph->get('field_cumulative_qty')->value ?? 0.0);
      $validation_status = $paragraph->get('field_validation_status')->value ?? 'valid';

      // Load BOQ details again for amounts
      $approved_qty = floatval($boq_item->get('field_approved_quantity')->value ?? 0.0);
      $rate = floatval($boq_item->get('field_unit_rate')->value ?? 0.0);

      $po_amount = $approved_qty * $rate;
      $prev_amount = $prev_qty * $rate;
      $current_amount = $current_qty * $rate;
      $cumulative_amount = $cumulative_qty * $rate;
      $balance_qty = $approved_qty - $cumulative_qty;
      $balance_amount = $balance_qty * $rate;

      // Recalculate totals across all items to return
      $all_items = $node->get('field_ra_bill_items')->referencedEntities();
      $total_po_amount = 0.0;
      $total_prev_amount = 0.0;
      $total_this_bill_amount = 0.0;
      $total_upto_date_amount = 0.0;
      foreach ($all_items as $item) {
        if ($item->bundle() === 'ra_bill_item') {
          $item_boq = !$item->get('field_boq_item')->isEmpty() ? $item->get('field_boq_item')->entity : NULL;
          if ($item_boq) {
            $r = floatval($item_boq->get('field_unit_rate')->value ?? 0.0);
            $total_po_amount += floatval($item_boq->get('field_approved_quantity')->value ?? 0.0) * $r;
            $total_prev_amount += floatval($item->get('field_previous_qty')->value ?? 0.0) * $r;
            $total_this_bill_amount += floatval($item->get('field_current_qty')->value ?? 0.0) * $r;
            $total_upto_date_amount += floatval($item->get('field_cumulative_qty')->value ?? 0.0) * $r;
          }
        }
      }

      $total_basic = $total_this_bill_amount;
      $cgst = $total_basic * 0.09;
      $sgst = $total_basic * 0.09;
      $total = $total_basic + $cgst + $sgst;

      return new JsonResponse([
        'success' => TRUE,
        'item' => [
          'paragraph_id' => $paragraph->id(),
          'item_code' => $item_data['item_code'],
          'description' => $item_data['description'],
          'uom' => $item_data['uom'],
          'rate' => $item_data['rate'],
          'approved_qty' => $approved_qty,
          'po_amount' => $po_amount,
          'prev_qty' => $prev_qty,
          'current_qty' => $current_qty,
          'cumulative_qty' => $cumulative_qty,
          'balance_qty' => $balance_qty,
          'prev_amount' => $prev_amount,
          'current_amount' => $current_amount,
          'cumulative_amount' => $cumulative_amount,
          'balance_amount' => $balance_amount,
          'validation_status' => $validation_status,
        ],
        'totals' => [
          'basic_amount' => $total_basic,
          'total_po_amount' => $total_po_amount,
          'total_prev_amount' => $total_prev_amount,
          'total_this_bill_amount' => $total_this_bill_amount,
          'total_upto_date_amount' => $total_upto_date_amount,
          'total_amount' => $total,
        ],
      ]);
    }
    catch (\Exception $e) {
      return new JsonResponse(['error' => 'Failed to add item: ' . $e->getMessage()], 500);
    }
  }

}
