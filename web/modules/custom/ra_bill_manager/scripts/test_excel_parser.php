<?php

/**
 * @file
 * Script to test the ExcelParser service with the two Excel sheets.
 *
 * Usage: ddev drush php:script web/modules/custom/ra_bill_manager/scripts/test_excel_parser.php
 */

use Drupal\ra_bill_manager\Parser\ExcelParser;

echo "=== Excel Parser Test ===\n\n";

$excel_parser = \Drupal::service('ra_bill_manager.excel_parser');

$files = [
  'ABS' => __DIR__ . '/../../../../../ABS.xlsx',
  'Mundra' => __DIR__ . '/../../../../../RA06_62MLD_Mundra_INVOICE.xlsx',
];

foreach ($files as $name => $path) {
  echo "--- Testing: $name ($path) ---\n";
  if (!file_exists($path)) {
    echo "ERROR: File not found.\n\n";
    continue;
  }

  try {
    $data = $excel_parser->parse($path);
    echo "Metadata parsed successfully:\n";
    print_r($data['metadata']);

    echo "Items parsed successfully. Total items: " . count($data['items']) . "\n";
    echo "First 3 items:\n";
    $first_three = array_slice($data['items'], 0, 3);
    foreach ($first_three as $idx => $item) {
      echo "  Item " . ($idx + 1) . ":\n";
      foreach ($item as $k => $v) {
        echo "    $k: " . (is_scalar($v) ? $v : json_encode($v)) . "\n";
      }
    }
    echo "\n";
  }
  catch (\Exception $e) {
    echo "ERROR during parsing: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n\n";
  }
}
