<?php

namespace Drupal\ra_bill_manager\Validation;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Service to validate RA Bill Item quantities against BOQ Items.
 */
class BOQValidator {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new BOQValidator.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Finds or creates a BOQ Item matching the Excel row.
   *
   * @param int|string $project_id
   *   The Project (project_sites) node ID.
   * @param string $item_code
   *   The Item Code (SL.NO).
   * @param string $description
   *   The Item Description.
   * @param string $uom
   *   The Unit of Measurement.
   * @param float $approved_qty
   *   The PO Approved Qty.
   * @param float $rate
   *   The Unit Rate.
   *
   * @return \Drupal\node\NodeInterface
   *   The BOQ Item node.
   */
   public function getOrCreateBOQItem($project_id, $item_code, $description, $uom = 'Nos', $approved_qty = 0.0, $rate = 0.0, $part_number = '') {
    $node_storage = $this->entityTypeManager->getStorage('node');
    $description = trim($description);
    $item_code = trim($item_code);
    $uom = $this->normalizeUom($uom);

    // Helper to populate part number on existing node if empty
    $update_part_no = function($node) use ($part_number) {
      if ($node && !empty($part_number) && $node->hasField('field_part_number') && $node->get('field_part_number')->isEmpty()) {
        $node->set('field_part_number', $part_number);
        $node->save();
      }
      return $node;
    };

    // 1. Try to match by BOTH Code and Description (perfect match)
    if (!empty($item_code) && !empty($description)) {
      $query = $node_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'boq_item')
        ->condition('field_project', $project_id)
        ->condition('field_item_code', $item_code)
        ->condition('field_item_description', $description);
      $ids = $query->execute();
      if (!empty($ids)) {
        return $update_part_no($node_storage->load(reset($ids)));
      }
    }

    // 2. Try matching by Code, but verify description is compatible
    if (!empty($item_code)) {
      $query = $node_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'boq_item')
        ->condition('field_project', $project_id)
        ->condition('field_item_code', $item_code);
      $ids = $query->execute();
      if (!empty($ids)) {
        foreach ($ids as $id) {
          $node = $node_storage->load($id);
          if ($node) {
            $node_desc = trim($node->get('field_item_description')->value ?? '');
            // Accept if descriptions are similar or database description is empty
            if (empty($node_desc) || strcasecmp($node_desc, $description) === 0 || stripos($node_desc, $description) !== FALSE || stripos($description, $node_desc) !== FALSE) {
              return $update_part_no($node);
            }
          }
        }
      }
    }

    // 3. Try matching by Description, but verify code is compatible
    if (!empty($description)) {
      $query = $node_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'boq_item')
        ->condition('field_project', $project_id)
        ->condition('field_item_description', $description);
      $ids = $query->execute();
      if (!empty($ids)) {
        foreach ($ids as $id) {
          $node = $node_storage->load($id);
          if ($node) {
            $node_code = trim($node->get('field_item_code')->value ?? '');
            // Accept if codes match, or database code is empty, or Excel code is empty
            if (empty($node_code) || empty($item_code) || $node_code === $item_code) {
              return $update_part_no($node);
            }
          }
        }
      }
    }

    // 4. Create a new BOQ Item if no match was found
    $title = !empty($item_code) ? ($item_code . ' - ' . substr($description, 0, 50)) : substr($description, 0, 80);
    $boq_item = $node_storage->create([
      'type' => 'boq_item',
      'title' => $title,
      'field_project' => $project_id,
      'field_item_code' => $item_code,
      'field_part_number' => $part_number,
      'field_item_description' => $description,
      'field_unit' => $uom,
      'field_approved_quantity' => $approved_qty,
      'field_unit_rate' => $rate,
      'field_total_value' => $approved_qty * $rate,
      'status' => 1,
    ]);
    $boq_item->save();

    return $boq_item;
  }

  /**
   * Calculates the cumulative quantity claimed in other bills for a BOQ Item.
   *
   * @param int|string $boq_item_id
   *   The BOQ Item node ID.
   * @param int|string|null $current_bill_id
   *   The current RA Bill node ID to exclude (optional).
   *
   * @return float
   *   The sum of quantities in other bills.
   */
  public function getPreviousQuantity($boq_item_id, $current_bill_id = NULL) {
    $paragraph_storage = $this->entityTypeManager->getStorage('paragraph');
    
    $query = $paragraph_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'ra_bill_item')
      ->condition('field_boq_item', $boq_item_id);

    if ($current_bill_id) {
      $current_bill = $this->entityTypeManager->getStorage('node')->load($current_bill_id);
      if ($current_bill && $current_bill->bundle() === 'ra_bill') {
        $po_id = !$current_bill->get('field_purchase_order')->isEmpty() ? $current_bill->get('field_purchase_order')->target_id : NULL;
        $project_id = !$current_bill->get('field_project')->isEmpty() ? $current_bill->get('field_project')->target_id : NULL;
        $current_ra_num = intval($current_bill->get('field_ra_bill_number')->value ?? 0);
        
        $bill_query = $this->entityTypeManager->getStorage('node')->getQuery()
          ->accessCheck(FALSE)
          ->condition('type', 'ra_bill');
          
        if ($po_id) {
          $bill_query->condition('field_purchase_order', $po_id);
        }
        elseif ($project_id) {
          $bill_query->condition('field_project', $project_id);
        }
        else {
          // If no PO or Project, we cannot group, fallback to original logic
          $bill_query->condition('nid', $current_bill_id, '<>');
        }
        
        $bill_ids = $bill_query->execute();
        
        if (!empty($bill_ids)) {
          $bills = $this->entityTypeManager->getStorage('node')->loadMultiple($bill_ids);
          $valid_parent_ids = [];
          foreach ($bills as $bill) {
            $ra_num = intval($bill->get('field_ra_bill_number')->value ?? 0);
            // Only include bills with a lower RA number
            if ($ra_num < $current_ra_num) {
              $valid_parent_ids[] = $bill->id();
            }
          }
          if (!empty($valid_parent_ids)) {
            $query->condition('parent_id', $valid_parent_ids, 'IN');
          }
          else {
            // No preceding bills found, previous quantity must be 0
            return 0.0;
          }
        }
        else {
          return 0.0;
        }
      }
      else {
        $query->condition('parent_id', $current_bill_id, '<>');
      }
    }

    $p_ids = $query->execute();
    if (empty($p_ids)) {
      return 0.0;
    }

    $previous_qty = 0.0;
    $paragraphs = $paragraph_storage->loadMultiple($p_ids);
    foreach ($paragraphs as $paragraph) {
      $parent = $paragraph->getParentEntity();
      if ($parent && $parent->bundle() === 'ra_bill') {
        $previous_qty += floatval($paragraph->get('field_current_qty')->value ?? 0.0);
      }
    }

    return $previous_qty;
  }

  /**
   * Validates the current item quantities against approved BOQ quantities.
   *
   * @param \Drupal\node\NodeInterface $boq_item
   *   The BOQ Item node.
   * @param float $current_qty
   *   The current quantity in this bill.
   * @param float $previous_qty
   *   The previous quantity claimed.
   *
   * @return array
   *   An array with 'status' (valid or over_claimed) and 'approved_qty'.
   */
  public function validate($boq_item, $current_qty, $previous_qty) {
    $approved_qty = floatval($boq_item->get('field_approved_quantity')->value ?? 0.0);
    $cumulative_qty = $previous_qty + $current_qty;

    $status = 'valid';
    // Use epsilon for float comparison to avoid issues with double precision
    if ($cumulative_qty - $approved_qty > 0.0001) {
      $status = 'over_claimed';
    }

    return [
      'status' => $status,
      'approved_qty' => $approved_qty,
      'cumulative_qty' => $cumulative_qty,
    ];
  }

  /**
   * Normalizes UOM values to match Drupal field_unit allowed values keys.
   */
  public function normalizeUom($uom) {
    $uom = strtolower(trim($uom));
    $map = [
      'nos' => 'nos',
      'no' => 'nos',
      'no.' => 'nos',
      'kg' => 'kg',
      'kgs' => 'kg',
      'ton' => 'ton',
      'tons' => 'ton',
      'mt' => 'ton',
      'meter' => 'meter',
      'mtr' => 'meter',
      'meters' => 'meter',
      'sqm' => 'sqm',
      'sq.mtr' => 'sqm',
      'sq.m' => 'sqm',
      'cum' => 'cum',
      'cu.mtr' => 'cum',
      'cu.m' => 'cum',
      'inch_dia' => 'inch_dia',
      'inch' => 'inch_dia',
      'lot' => 'lot',
      'l.s' => 'lot',
      'set' => 'lot',
    ];
    return $map[$uom] ?? 'nos';
  }

}
