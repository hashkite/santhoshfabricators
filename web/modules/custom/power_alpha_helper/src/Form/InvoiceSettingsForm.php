<?php

namespace Drupal\power_alpha_helper\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;

/**
 * Invoice settings configuration form.
 */
class InvoiceSettingsForm extends ConfigFormBase
{

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames()
  {
    return ['power_alpha_helper.invoice_settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return 'power_alpha_helper_invoice_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {
    $config = $this->config('power_alpha_helper.invoice_settings');

    $form['company_details'] = [
      '#type' => 'details',
      '#title' => $this->t('Seller Company Details'),
      '#open' => TRUE,
    ];

    $form['company_details']['company_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Company Name'),
      '#default_value' => $config->get('company_name') ?: 'SANTHOSH FABRICATORS PVT. LTD.',
      '#required' => TRUE,
    ];

    $form['company_details']['company_logo'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Company Logo Image'),
      '#description' => $this->t('Upload the logo image (JPEG, PNG, or SVG). If none is uploaded, the default vector logo will be used.'),
      '#upload_location' => 'public://invoice_logos/',
      '#default_value' => $config->get('company_logo') ?: [],
      '#upload_validators' => [
        'file_validate_is_image' => [],
        'file_validate_extensions' => ['png jpg jpeg svg'],
      ],
    ];

    $form['company_details']['company_tagline'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Company Tagline/Subtitle'),
      '#default_value' => $config->get('company_tagline') ?: 'Spl. Fabrication & Erection of Pipe Lines & Structures, Maintenance of W. T. Plants, D. M. Plants & RO-MB Plants.',
    ];

    $form['company_details']['company_address'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Company Address'),
      '#default_value' => $config->get('company_address') ?: 'PLOT NO 50/19, TIRUPATI PARK, SIKKA, SIKKA JAMNAGAR, Jamnagar, Gujarat, 361141',
      '#required' => TRUE,
    ];

    $form['company_details']['company_gstin'] = [
      '#type' => 'textfield',
      '#title' => $this->t('GSTIN'),
      '#default_value' => $config->get('company_gstin') ?: '24AAQCS3102E1ZP',
      '#required' => TRUE,
    ];

    $form['company_details']['company_pan'] = [
      '#type' => 'textfield',
      '#title' => $this->t('PAN'),
      '#default_value' => $config->get('company_pan') ?: 'AAQCS3102E',
    ];

    $form['company_details']['company_sac'] = [
      '#type' => 'textfield',
      '#title' => $this->t('SAC Code'),
      '#default_value' => $config->get('company_sac') ?: '995442',
    ];

    $form['company_details']['company_ra'] = [
      '#type' => 'textfield',
      '#title' => $this->t('RA Bill Number'),
      '#default_value' => $config->get('company_ra') ?: '10',
    ];

    $form['buyer_defaults'] = [
      '#type' => 'details',
      '#title' => $this->t('Default Buyer Details (If not set on PO/Project)'),
      '#open' => TRUE,
    ];

    $form['buyer_defaults']['default_buyer_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Buyer Company Name'),
      '#default_value' => $config->get('default_buyer_name') ?: 'N/A',
    ];

    $form['buyer_defaults']['default_buyer_address'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Buyer Address'),
      '#default_value' => $config->get('default_buyer_address') ?: "N/A",
    ];

    $form['buyer_defaults']['default_buyer_gstin'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Buyer GSTIN'),
      '#default_value' => $config->get('default_buyer_gstin') ?: 'N/A',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $config = $this->config('power_alpha_helper.invoice_settings');
    $new_logo = $form_state->getValue('company_logo');
    $old_logo = $config->get('company_logo') ?: [];

    // Manage File Permanent Status and usage
    if ($new_logo !== $old_logo) {
      if (!empty($old_logo)) {
        $fid = reset($old_logo);
        $file = File::load($fid);
        if ($file) {
          \Drupal::service('file.usage')->delete($file, 'power_alpha_helper', 'invoice_settings', 1);
        }
      }

      if (!empty($new_logo)) {
        $fid = reset($new_logo);
        $file = File::load($fid);
        if ($file) {
          $file->setPermanent();
          $file->save();
          \Drupal::service('file.usage')->add($file, 'power_alpha_helper', 'invoice_settings', 1);
        }
      }
    }

    $config
      ->set('company_name', $form_state->getValue('company_name'))
      ->set('company_tagline', $form_state->getValue('company_tagline'))
      ->set('company_address', $form_state->getValue('company_address'))
      ->set('company_gstin', $form_state->getValue('company_gstin'))
      ->set('company_pan', $form_state->getValue('company_pan'))
      ->set('company_sac', $form_state->getValue('company_sac'))
      ->set('company_ra', $form_state->getValue('company_ra'))
      ->set('company_logo', $new_logo)
      ->set('default_buyer_name', $form_state->getValue('default_buyer_name'))
      ->set('default_buyer_address', $form_state->getValue('default_buyer_address'))
      ->set('default_buyer_gstin', $form_state->getValue('default_buyer_gstin'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
