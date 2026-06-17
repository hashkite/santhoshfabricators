<?php
/**
 * Migration script to move part number field from paragraph to BOQ item.
 *
 * Usage: ddev drush php:script web/modules/custom/ra_bill_manager/scripts/migrate_part_number.php
 */

use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

echo "=== Migrating Part Number Field configuration ===\n";

$field_name = 'field_part_number';

// 1. Create Field Storage for node.field_part_number if not exists
if (!FieldStorageConfig::loadByName('node', $field_name)) {
  $field_storage = FieldStorageConfig::create([
    'field_name' => $field_name,
    'entity_type' => 'node',
    'type' => 'string',
    'cardinality' => 1,
    'settings' => [
      'max_length' => 255,
      'is_ascii' => FALSE,
      'case_sensitive' => FALSE,
    ],
  ]);
  $field_storage->save();
  echo "Created Node Field Storage for $field_name.\n";
} else {
  echo "Node Field Storage for $field_name already exists.\n";
}

// 2. Create Field Instance for node.boq_item.field_part_number if not exists
if (!FieldConfig::loadByName('node', 'boq_item', $field_name)) {
  $field_instance = FieldConfig::create([
    'field_name' => $field_name,
    'entity_type' => 'node',
    'bundle' => 'boq_item',
    'label' => 'Part Number',
    'required' => FALSE,
  ]);
  $field_instance->save();
  echo "Created Field Instance for $field_name on bundle boq_item.\n";
} else {
  echo "Field Instance for $field_name on bundle boq_item already exists.\n";
}

// Configure form display for node.boq_item
$form_display = \Drupal::entityTypeManager()->getStorage('entity_form_display')->load('node.boq_item.default');
if ($form_display) {
  $form_display->setComponent($field_name, [
    'type' => 'string_textfield',
    'weight' => 2,
    'settings' => [
      'size' => 60,
      'placeholder' => '',
    ],
  ])->save();
  echo "Added field_part_number to boq_item form display.\n";
}

// Configure view display for node.boq_item
$view_display = \Drupal::entityTypeManager()->getStorage('entity_view_display')->load('node.boq_item.default');
if ($view_display) {
  $view_display->setComponent($field_name, [
    'type' => 'string',
    'weight' => 2,
    'label' => 'above',
    'settings' => [],
  ])->save();
  echo "Added field_part_number to boq_item view display.\n";
}

// 3. Delete Field Instance on paragraph.ra_bill_item
$paragraph_field = FieldConfig::loadByName('paragraph', 'ra_bill_item', $field_name);
if ($paragraph_field) {
  $paragraph_field->delete();
  echo "Deleted paragraph.ra_bill_item.field_part_number field instance.\n";
} else {
  echo "paragraph.ra_bill_item.field_part_number field instance does not exist.\n";
}

// 4. Delete Field Storage on paragraph
$paragraph_storage = FieldStorageConfig::loadByName('paragraph', $field_name);
if ($paragraph_storage) {
  $paragraph_storage->delete();
  echo "Deleted paragraph.field_part_number field storage.\n";
} else {
  echo "paragraph.field_part_number field storage does not exist.\n";
}

echo "=== Migration Completed Successfully ===\n";
