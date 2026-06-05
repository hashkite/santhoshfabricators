<?php

namespace Drupal\ra_bill_manager\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;
use Drupal\file\Entity\File;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an Excel Import Form for RA Bills on the Purchase Order page.
 */
class RAExcelImportForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ra_bill_manager_excel_import_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $po_nid = NULL) {
    // Get PO NID from route match if not passed explicitly
    if (!$po_nid) {
      $node = \Drupal::routeMatch()->getParameter('node');
      if ($node instanceof \Drupal\node\NodeInterface && $node->bundle() === 'purchase_order') {
        $po_nid = $node->id();
      }
    }

    if (!$po_nid) {
      return ['#markup' => $this->t('Purchase Order not found.')];
    }

    $form['po_nid'] = [
      '#type' => 'hidden',
      '#value' => $po_nid,
    ];

    $form['excel_file'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Upload RA Bill Excel Sheet'),
      '#upload_validators' => [
        'file_validate_extensions' => ['xlsx xls'],
      ],
      '#upload_location' => 'public://ra-bills',
      '#required' => TRUE,
      '#description' => $this->t('Allowed extensions: .xlsx, .xls'),
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import Excel'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $po_nid = $form_state->getValue('po_nid');
    $fids = $form_state->getValue('excel_file');

    if (empty($fids) || !$po_nid) {
      $this->messenger()->addError($this->t('Invalid import request.'));
      return;
    }

    $fid = reset($fids);
    $po_node = Node::load($po_nid);

    if (!$po_node || $po_node->bundle() !== 'purchase_order') {
      $this->messenger()->addError($this->t('Associated Purchase Order not found.'));
      return;
    }

    // Get the Project reference from the Purchase Order
    $project_id = !$po_node->get('field_project')->isEmpty() ? $po_node->get('field_project')->target_id : NULL;

    if (!$project_id) {
      $this->messenger()->addError($this->t('This Purchase Order does not have a Project associated with it. Please link a Project first.'));
      return;
    }

    // Make the file permanent so it isn't cleaned up by system cron
    $file = File::load($fid);
    if ($file) {
      $file->setPermanent();
      $file->save();
    }

    // Create the RA Bill node.
    // Our hook_node_presave will trigger automatically on save() to parse the file,
    // generate paragraphs, validate quantities, and populate totals.
    $ra_bill = Node::create([
      'type' => 'ra_bill',
      'title' => 'Imported RA Bill - ' . $po_node->label(),
      'field_purchase_order' => $po_nid,
      'field_project' => $project_id,
      'field_excel_file' => [
        'target_id' => $fid,
      ],
      'status' => 1,
    ]);

    try {
      $ra_bill->save();
      $this->messenger()->addStatus($this->t('RA Bill successfully imported and validated.'));
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to save RA Bill: @msg', ['@msg' => $e->getMessage()]));
    }

    // Redirect back to the Purchase Order page
    $form_state->setRedirect('entity.node.canonical', ['node' => $po_nid]);
  }

}
