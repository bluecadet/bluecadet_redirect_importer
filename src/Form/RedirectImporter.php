<?php

namespace Drupal\bluecadet_redirect_importer\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\file\Entity\File;
use Drupal\redirect\Entity\Redirect;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form to upload and import Redirects.
 */
class RedirectImporter extends FormBase {

  /**
   * The Messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;


  /**
   * Class constructor.
   */
  public function __construct(MessengerInterface $messenger) {
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    // Instantiates this form class.
    return new static(
      // Load the service required to construct this class.
      $container->get('messenger')
    );
  }

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

    $form['message'] = [
      '#markup' => "<hr><p>Please uplaod a csv file with <em>2 Header Rows</em> and 4 columns.<br />
      The Columns shoud be <strong><em>From</em></strong>, <strong><em>To</em></strong>, <strong><em>Redirect Status</em></strong>, <strong><em>Language</em></strong>.</p>
      <p class='color-error'>Items with the same From/Language will be overwritten.</p>
      <p>You can use the <a href='https://docs.google.com/spreadsheets/d/15bIUcZd4PZeCC_htpSZGNXPsPyL3NUvsMegO9jJJKYU' target='_blank'>Template Spreadsheet</a> to get started.</p><hr><br/>",
    ];

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
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Manage File.
    $file = $form_state->getValue('redirect_import_file', 0);

    if (isset($file[0]) && !empty($file[0])) {
      $imp_file = File::load($file[0]);
      $imp_file->setPermanent();
      $imp_file->save();

      ksm($imp_file);
    }
    else {
      $this->messenger->addWarning('No file Attached. Nothing Happened');
      return;
    }

    // Build Batch.
    $ops = [
      [[$this, 'buildDataFromFile'], [$imp_file]],
      [[$this, 'importRedirects'], []],
      [[$this, 'cleanUp'], [$imp_file]],
    ];

    $batch = [
      'title' => t('Importing Redirects...'),
      'operations' => $ops,
      'finished' => [$this, 'importFinished'],
    ];

    // batch_set($batch);
  }

  /**
   * Build Data array from CSV file.
   */
  public function buildDataFromFile($file, &$context) {
    ksm($file->getFileUri());
    $real_path = \Drupal::service('file_system')->realpath($file->getFileUri());
    ksm($real_path);
    $fp = fopen($real_path, 'r');
    ksm($fp);
    $raw_data = [];
    $row = 0;

    // while (($import_row = fgetcsv($fp, 0, ",")) !== FALSE) {
    //   // Skip 2 header Rows.
    //   if ($row > 1) {
    //     $raw_data[] = $import_row;
    //   }

    //   $row++;
    // }

    $context['results']['msg'][] = "Raw Data Created.";

    $context['results']['raw_data'] = $raw_data;
    $context['results']['msg'][] = "Created Raw Data.";
    $context['message'] = "Created Raw Data";
  }

  /**
   * Import actual redirects.
   */
  public function importRedirects(&$context) {
    if (empty($context['sandbox'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['current_row'] = 0;
      $context['sandbox']['max'] = count($context['results']['raw_data']);
    }

    for ($i = 0; $i < 10; $i++) {

      $row = $context['results']['raw_data'][$context['sandbox']['current_row']] ?: NULL;
      if (!empty($row[0])) {

        // Set Defaults.
        $row[2] = $row[2] ?: 301;
        $row[3] = $row[3] ?: "en";

        redirect_delete_by_path($row[0], $row[3], FALSE);

        $redirect = Redirect::create();
        $redirect->setSource($row[0]);
        $redirect->setRedirect($row[1]);
        $redirect->setStatusCode($row[2]);
        $redirect->setLanguage($row[3]);
        $redirect->save();

        $context['results']['msg'][] = "Added " . $row[0];
      }

      $context['sandbox']['current_row']++;
    }

    $context['finished'] = $context['sandbox']['current_row'] / $context['sandbox']['max'];
    $context['message'] = "Processing Data";

    if ($context['finished'] >= 1) {

      $context['results']['msg'][] = "Finished Processing Redirects";
    }
  }

  /**
   * Cleanup, delete files, etc.
   */
  public function cleanUp($imp_file, &$context) {

    $imp_file->setTemporary();
    $imp_file->save();
    $imp_file->delete();

    // \Drupal::service('file_system')->delete($fid);
    $context['results']['msg'][] = "Cleaning up.";
    $context['message'] = "Cleaning up.";
  }

  /**
   * Finish function for the batch process.
   */
  public function importFinished($success, $results, $operations) {

    if (!empty($results['msg'])) {
      $message_render = [
        '#theme' => 'item_list',
        '#items' => $results['msg'],
      ];

      $msg = render($message_render);
      $this->messenger->addMessage($msg);
    }

    if (!$success) {
      $this->messenger->addError('Finished with an error.');
    }
  }

}
