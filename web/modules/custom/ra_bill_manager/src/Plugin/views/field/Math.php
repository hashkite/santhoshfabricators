<?php

namespace Drupal\ra_bill_manager\Plugin\views\field;

use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Drupal\Core\Form\FormStateInterface;

/**
 * A handler to provide a custom math expression field in Views.
 *
 * @ingroup views_field_handlers
 *
 * @ViewsField("math")
 */
class Math extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function usesGroupBy() {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    // Dynamic field, no database query needed.
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['expression'] = ['default' => ''];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);
    $form['expression'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Expression'),
      '#description' => $this->t('Enter the expression, e.g. [field_cumulative_qty] - [field_approved_quantity].'),
      '#default_value' => $this->options['expression'],
      '#required' => TRUE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $expression = $this->options['expression'] ?? '';
    if (empty($expression)) {
      return '';
    }

    // Replace tokens using rendered values of other fields
    $tokens = [];
    foreach ($this->view->field as $id => $field) {
      // Avoid recursive rendering of Math fields
      if ($field instanceof Math) {
        continue;
      }
      // Get the last rendered value or render it if not already rendered
      $raw_rendered = $field->last_render ?? $field->advancedRender($values);
      $clean_val = preg_replace('/[^0-9\.\-]/', '', strip_tags($raw_rendered));
      $tokens["[$id]"] = is_numeric($clean_val) ? floatval($clean_val) : 0.0;
    }

    // Replace tokens in expression
    $evaluated_expr = strtr($expression, $tokens);
    
    // Clean up any remaining unresolved tokens or non-numeric characters for security
    $evaluated_expr = preg_replace('/[^0-9\+\-\*\/\(\)\. ]/', '0', $evaluated_expr);

    // Safely evaluate the expression
    $result = 0.0;
    if (!empty($evaluated_expr) && preg_match('/^[0-9\+\-\*\/\(\)\. ]+$/', $evaluated_expr)) {
      try {
        // Use a safe evaluation pattern via eval, which is secure here because of the strict character whitelist
        $result = eval("return ($evaluated_expr);");
      }
      catch (\Throwable $e) {
        $result = 0.0;
      }
    }

    return is_numeric($result) ? round($result, 4) : 0.0;
  }

}
