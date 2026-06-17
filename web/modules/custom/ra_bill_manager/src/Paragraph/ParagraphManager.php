<?php

namespace Drupal\ra_bill_manager\Paragraph;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ra_bill_manager\Validation\BOQValidator;

/**
 * Service to manage RA Bill Item Paragraphs.
 */
class ParagraphManager {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The BOQ validation service.
   *
   * @var \Drupal\ra_bill_manager\Validation\BOQValidator
   */
  protected $boqValidator;

  /**
   * Constructs a new ParagraphManager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, BOQValidator $boq_validator) {
    $this->entityTypeManager = $entity_type_manager;
    $this->boqValidator = $boq_validator;
  }

  /**
   * Deletes all paragraphs referenced by an RA Bill node.
   *
   * @param \Drupal\node\NodeInterface $ra_bill
   *   The RA Bill node.
   */
  public function clearBillItems($ra_bill) {
    if ($ra_bill->hasField('field_ra_bill_items') && !$ra_bill->get('field_ra_bill_items')->isEmpty()) {
      $items = $ra_bill->get('field_ra_bill_items')->referencedEntities();
      foreach ($items as $item) {
        $item->delete();
      }
      $ra_bill->set('field_ra_bill_items', []);
    }
  }

  /**
   * Creates a single RA Bill Item paragraph.
   *
   * @param int|string|null $ra_bill_id
   *   The RA Bill node ID (if saved).
   * @param int|string $project_id
   *   The Project node ID.
   * @param array $item_data
   *   The parsed item data from Excel.
   *
   * @return \Drupal\paragraphs\ParagraphInterface
   *   The created Paragraph entity.
   */
  public function createBillItem($ra_bill_id, $project_id, array $item_data) {
    $item_code = $item_data['item_code'] ?? '';
    $part_number = $item_data['part_number'] ?? '';
    $description = $item_data['description'] ?? '';
    $uom = $item_data['uom'] ?? 'Nos';
    $po_qty = floatval($item_data['po_qty'] ?? 0.0);
    $rate = floatval($item_data['rate'] ?? 0.0);
    $current_qty = floatval($item_data['current_qty'] ?? 0.0);

    // Get or create matching BOQ item
    $boq_item = $this->boqValidator->getOrCreateBOQItem($project_id, $item_code, $description, $uom, $po_qty, $rate, $part_number);

    // Calculate previous quantity from other bills
    $prev_qty = $this->boqValidator->getPreviousQuantity($boq_item->id(), $ra_bill_id);

    // Validate quantities
    $validation = $this->boqValidator->validate($boq_item, $current_qty, $prev_qty);

    // Calculate claimed amount based on rate
    $unit_rate = floatval($boq_item->get('field_unit_rate')->value ?? 0.0);
    $amount_claimed = $current_qty * $unit_rate;

    // Create the Paragraph entity
    $paragraph = $this->entityTypeManager->getStorage('paragraph')->create([
      'type' => 'ra_bill_item',
      'field_boq_item' => $boq_item->id(),
      'field_item_code' => $item_code,
      'field_item_description' => $description,
      'field_previous_qty' => $prev_qty,
      'field_current_qty' => $current_qty,
      'field_cumulative_qty' => $prev_qty + $current_qty,
      'field_amount_claimed' => $amount_claimed,
      'field_validation_status' => $validation['status'],
    ]);
    $paragraph->save();

    return $paragraph;
  }

}
