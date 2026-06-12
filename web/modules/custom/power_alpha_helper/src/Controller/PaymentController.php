<?php

namespace Drupal\power_alpha_helper\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller to handle recording and updating payment entries on Invoices.
 */
class PaymentController extends ControllerBase {

  /**
   * Records a new payment entry and updates invoice status/balances.
   */
  public function updatePayment($node, Request $request) {
    if (is_numeric($node)) {
      $node = Node::load($node);
    }
    if (!$node || $node->bundle() !== 'invoice') {
      throw new NotFoundHttpException();
    }

    $amount = (float) $request->request->get('payment_amount', 0);
    $date = $request->request->get('payment_date', '');
    $notes = $request->request->get('notes', '');

    if ($amount <= 0) {
      $this->messenger()->addError($this->t('Please enter a valid payment amount greater than zero.'));
      return $this->redirect('entity.node.canonical', ['node' => $node->id()]);
    }

    if (empty($date)) {
      $date = date('Y-m-d');
    }

    // Load existing payment logs paragraphs
    $paragraph_entities = [];
    if (!$node->get('field_payment_history')->isEmpty()) {
      $paragraph_entities = $node->get('field_payment_history')->getValue();
    }

    // Create and save new payment_history Paragraph entity
    $new_log = Paragraph::create([
      'type' => 'payment_history',
      'field_date' => $date,
      'field_amount' => number_format($amount, 2, '.', ''),
      'field_description' => $notes,
      'field_recorded_by' => \Drupal::currentUser()->getDisplayName(),
    ]);
    $new_log->save();

    // Append new log to entity reference revisions list
    $paragraph_entities[] = [
      'target_id' => $new_log->id(),
      'target_revision_id' => $new_log->getRevisionId(),
    ];

    // Recalculate total paid from all log paragraphs (both existing and new)
    $total_paid = 0.0;
    foreach ($paragraph_entities as $item) {
      $p = Paragraph::load($item['target_id']);
      if ($p && $p->hasField('field_amount')) {
        $total_paid += (float) ($p->get('field_amount')->value ?? 0);
      }
    }

    // Update TDS and Retention if submitted from the quick payment form.
    if ($request->request->has('tds')) {
      $node->set('field_tds', number_format((float) $request->request->get('tds', 0), 2, '.', ''));
    }
    
    // Checkbox values are only submitted when checked; if not present in request, set as 0.
    $tds_paid = $request->request->get('tds_paid') ? 1 : 0;
    $node->set('field_tds_paid', $tds_paid);

    if ($request->request->has('retention')) {
      $node->set('field_retention', number_format((float) $request->request->get('retention', 0), 2, '.', ''));
    }

    // Load total invoice amount (with GST) and deduct TDS and Retention.
    $total_receivable = (float) ($node->get('field_basic_value_gst')->value ?? 0);
    $tds = $node->hasField('field_tds') ? (float) ($node->get('field_tds')->value ?? 0) : 0.0;
    $retention = $node->hasField('field_retention') ? (float) ($node->get('field_retention')->value ?? 0) : 0.0;
    $expected_payment = $total_receivable - $tds - $retention;
    $remaining_balance = max(0.0, $expected_payment - $total_paid);

    // Determine Status
    $due_date = $node->get('field_payment_due_date')->value;
    $status = 'pending';
    if ($total_paid <= 0.01) {
      if (!empty($due_date) && strtotime($due_date) < time()) {
        $status = 'overdue';
      } else {
        $status = 'pending';
      }
    } elseif ($remaining_balance > 0.01) {
      $status = 'partial';
    } else {
      $status = 'paid';
    }

    // Save fields
    $node->set('field_paid_amount', number_format($total_paid, 2, '.', ''));
    $node->set('field_payment_status', $status);
    $node->set('field_payment_history', $paragraph_entities);

    try {
      $node->save();

      // Show alert depending on state
      if ($status === 'paid') {
        $this->messenger()->addStatus($this->t('Success: Payment completed for Invoice @inv. Remaining balance is ₹0.00.', [
          '@inv' => $node->get('field_invoice_no')->value ?: $node->label(),
        ]));
      } else {
        $this->messenger()->addStatus($this->t('Payment of @amount recorded successfully for Invoice @inv. Remaining balance: @balance.', [
          '@amount' => '₹' . number_format($amount, 2),
          '@inv' => $node->get('field_invoice_no')->value ?: $node->label(),
          '@balance' => '₹' . number_format($remaining_balance, 2),
        ]));
      }
    } catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to save payment: @msg', ['@msg' => $e->getMessage()]));
    }

    return $this->redirect('entity.node.canonical', ['node' => $node->id()]);
  }

  /**
   * Updates an existing payment log paragraph and updates the invoice totals.
   */
  public function editPayment($node, $paragraph, Request $request) {
    if (!\Drupal::currentUser()->isAuthenticated()) {
      throw new \Symfony\Component\Security\Core\Exception\AccessDeniedException();
    }

    if (is_numeric($node)) {
      $node = Node::load($node);
    }
    if (!$node || $node->bundle() !== 'invoice') {
      throw new NotFoundHttpException();
    }

    if (is_numeric($paragraph)) {
      $paragraph = Paragraph::load($paragraph);
    }
    if (!$paragraph || $paragraph->bundle() !== 'payment_history') {
      throw new NotFoundHttpException();
    }

    $amount = (float) $request->request->get('payment_amount', 0);
    $date = $request->request->get('payment_date', '');
    $notes = $request->request->get('notes', '');

    if ($amount <= 0) {
      $this->messenger()->addError($this->t('Please enter a valid payment amount greater than zero.'));
      return $this->redirect('entity.node.canonical', ['node' => $node->id()]);
    }

    if (empty($date)) {
      $date = date('Y-m-d');
    }

    $paragraph->set('field_date', $date);
    $paragraph->set('field_amount', number_format($amount, 2, '.', ''));
    $paragraph->set('field_description', $notes);
    $paragraph->save();

    // Recalculate total paid from all logs
    $total_paid = 0.0;
    if (!$node->get('field_payment_history')->isEmpty()) {
      foreach ($node->get('field_payment_history') as $item) {
        $p = Paragraph::load($item->target_id);
        if ($p && $p->hasField('field_amount')) {
          $total_paid += (float) ($p->get('field_amount')->value ?? 0);
        }
      }
    }

    // Load total invoice amount (with GST) and deduct TDS and Retention.
    $total_receivable = (float) ($node->get('field_basic_value_gst')->value ?? 0);
    $tds = $node->hasField('field_tds') ? (float) ($node->get('field_tds')->value ?? 0) : 0.0;
    $retention = $node->hasField('field_retention') ? (float) ($node->get('field_retention')->value ?? 0) : 0.0;
    $expected_payment = $total_receivable - $tds - $retention;
    $remaining_balance = max(0.0, $expected_payment - $total_paid);

    // Determine Status
    $due_date = $node->get('field_payment_due_date')->value;
    $status = 'pending';
    if ($total_paid <= 0.01) {
      if (!empty($due_date) && strtotime($due_date) < time()) {
        $status = 'overdue';
      } else {
        $status = 'pending';
      }
    } elseif ($remaining_balance > 0.01) {
      $status = 'partial';
    } else {
      $status = 'paid';
    }

    // Save fields on Node
    $node->set('field_paid_amount', number_format($total_paid, 2, '.', ''));
    $node->set('field_payment_status', $status);
    
    try {
      $node->save();
      $this->messenger()->addStatus($this->t('Payment log updated successfully.'));
    } catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to save payment: @msg', ['@msg' => $e->getMessage()]));
    }

    return $this->redirect('entity.node.canonical', ['node' => $node->id()]);
  }

  /**
   * Deletes an existing payment log paragraph and updates the invoice totals.
   */
  public function deletePayment($node, $paragraph) {
    if (!\Drupal::currentUser()->isAuthenticated()) {
      throw new \Symfony\Component\Security\Core\Exception\AccessDeniedException();
    }

    if (is_numeric($node)) {
      $node = Node::load($node);
    }
    if (!$node || $node->bundle() !== 'invoice') {
      throw new NotFoundHttpException();
    }

    if (is_numeric($paragraph)) {
      $paragraph = Paragraph::load($paragraph);
    }
    if (!$paragraph || $paragraph->bundle() !== 'payment_history') {
      throw new NotFoundHttpException();
    }

    $pid = $paragraph->id();

    // Remove the paragraph reference from field_payment_history
    $paragraph_entities = [];
    if (!$node->get('field_payment_history')->isEmpty()) {
      foreach ($node->get('field_payment_history')->getValue() as $item) {
        if ($item['target_id'] != $pid) {
          $paragraph_entities[] = $item;
        }
      }
    }
    $node->set('field_payment_history', $paragraph_entities);

    // Delete the paragraph entity itself
    $paragraph->delete();

    // Recalculate total paid
    $total_paid = 0.0;
    foreach ($paragraph_entities as $item) {
      $p = Paragraph::load($item['target_id']);
      if ($p && $p->hasField('field_amount')) {
        $total_paid += (float) ($p->get('field_amount')->value ?? 0);
      }
    }

    // Load total invoice amount (with GST) and deduct TDS and Retention.
    $total_receivable = (float) ($node->get('field_basic_value_gst')->value ?? 0);
    $tds = $node->hasField('field_tds') ? (float) ($node->get('field_tds')->value ?? 0) : 0.0;
    $retention = $node->hasField('field_retention') ? (float) ($node->get('field_retention')->value ?? 0) : 0.0;
    $expected_payment = $total_receivable - $tds - $retention;
    $remaining_balance = max(0.0, $expected_payment - $total_paid);

    // Determine Status
    $due_date = $node->get('field_payment_due_date')->value;
    $status = 'pending';
    if ($total_paid <= 0.01) {
      if (!empty($due_date) && strtotime($due_date) < time()) {
        $status = 'overdue';
      } else {
        $status = 'pending';
      }
    } elseif ($remaining_balance > 0.01) {
      $status = 'partial';
    } else {
      $status = 'paid';
    }

    // Save fields on Node
    $node->set('field_paid_amount', number_format($total_paid, 2, '.', ''));
    $node->set('field_payment_status', $status);
    
    try {
      $node->save();
      $this->messenger()->addStatus($this->t('Payment log deleted successfully.'));
    } catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to update invoice: @msg', ['@msg' => $e->getMessage()]));
    }

    return $this->redirect('entity.node.canonical', ['node' => $node->id()]);
  }

}
