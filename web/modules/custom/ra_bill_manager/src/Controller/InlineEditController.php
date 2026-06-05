<?php

namespace Drupal\ra_bill_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\Entity\Node;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

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

}
