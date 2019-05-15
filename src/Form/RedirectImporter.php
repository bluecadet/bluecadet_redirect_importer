<?php

namespace Drupal\bluecadet_redirect_importer\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Drupal\redirect\Entity\Redirect;

class RedirectImporter extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'bluecadet_redirect_importer.importer';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $form['redirect_import_file'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Choose a csv file for Taxonomy'),
      '#upload_location' => 'private://',
      '#upload_validators' => [
        'file_validate_extensions' => ['csv'],
      ],
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Manage File.
    $file = $form_state->getValue('redirect_import_file', 0);

    if (isset($file[0]) && !empty($file[0])) {
      $imp_file = File::load($file[0]);
      $imp_file->setPermanent();
      // $imp_file->save();
    }

    // Build Batch
    $ops = [
      [[$this, 'buildDataFromFile'], [$imp_file]],
      [[$this, 'importRedirects'], []],
      [[$this, 'cleanUp'], [$file[0]]],
    ];

    $batch = [
      'title' => t('Importing Redirects...'),
      'operations' => $ops,
      'finished' => [$this, 'importFinished'],
    ];

    batch_set($batch);
  }

  public function buildDataFromFile($file, &$context) {
    $real_path = \Drupal::service('file_system')->realpath($file->getFileUri());

    $fp = fopen($real_path, 'r');
    $raw_data = [];
    $row = 0;

    while (($import_row = fgetcsv($fp, 0, ",")) !== FALSE) {
      if ($row > 1) {
        $raw_data[] = $import_row;
      }

      $row++;
    }

    $context['results']['msg'][] = "Raw Data Created.";

    $context['results']['raw_data'] = $raw_data;
    $context['results']['msg'][] = "Created Raw Data.";
    $context['message'] = "Created Raw Data";
  }

  public function importRedirects(&$context) {
    if (empty($context['sandbox'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['current_row'] = 0;
      $context['sandbox']['max'] = count($context['results']['raw_data']);
    }

    for ($i=0; $i < 1; $i++) {

      $row = $context['results']['raw_data'][$context['sandbox']['current_row']]? : NULL;
      if (!empty($row[0])) {


        $row[2] = $row[2]? : 301;
        $row[3] = $row[3]? : "en";

        redirect_delete_by_path($row[0], $row[3], FALSE);

        $redirect = Redirect::create();
        $redirect->setSource($row[0]);
        $redirect->setRedirect($row[1]);
        $redirect->setStatusCode($row[2]);
        $redirect->setLanguage($row[3]);
        $redirect->save();

      }

      $context['sandbox']['current_row']++;
    }

    $context['finished'] = $context['sandbox']['current_row'] / $context['sandbox']['max'];
    $context['message'] = "Processing Data";

    if ($context['finished'] >= 1) {

      $context['results']['msg'] = "Finished Processing Redirects";
    }
  }

  public function cleanUp($fid, &$context) {
    file_delete($fid);
    $context['results']['msg'][] = "Cleaning up.";
    $context['message'] = "Cleaning up.";
  }

  /**
   *
   */
  public function importFinished($success, $results, $operations) {
    $msgs = [];

    // Validation Step Messages.
    if (isset($results['msg']) && !empty($results['msg'])) {
      foreach ($results['msg'] as $m) {
        $msgs[] = $m;
      }
    }

    $message_render = [
      '#theme' => 'item_list',
      '#items' => $msgs,
    ];

    drupal_set_message(render($message_render));

    if (!$success) {
      drupal_set_message(t('Finished with an error.'), 'error');
    }
  }

}
