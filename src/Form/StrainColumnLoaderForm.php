<?php

namespace Drupal\t4_bulk_strain_loader\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Drupal\t4_bulk_strain_loader\Service\StrainColumnLoader;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form to upload a CSV/TAB file and load column names as strains.
 */
class StrainColumnLoaderForm extends FormBase {

  /**
   * Loader service.
   */
  protected StrainColumnLoader $loader;

  /**
   * Constructs the form.
   */
  public function __construct(StrainColumnLoader $loader) {
    $this->loader = $loader;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('t4_bulk_strain_loader.strain_column_loader')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 't4_bulk_strain_loader_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['input_file'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Input file'),
      '#description' => $this->t('Upload a CSV/TAB file where each column header is a strain name.'),
      '#upload_location' => 'temporary://t4_bulk_strain_loader/',
      '#upload_validators' => [
        'file_validate_extensions' => ['csv tsv tab txt'],
      ],
      '#required' => TRUE,
    ];

    $form['organism_id'] = [
      '#type' => 'number',
      '#title' => $this->t('Organism ID'),
      '#description' => $this->t('Chado organism_id to associate with inserted strains.'),
      '#required' => TRUE,
      '#min' => 1,
      '#step' => 1,
    ];

    $form['delimiter'] = [
      '#type' => 'select',
      '#title' => $this->t('Delimiter'),
      '#options' => [
        'auto' => $this->t('Auto-detect'),
        ',' => $this->t('Comma (CSV)'),
        "\t" => $this->t('Tab (TSV/TAB)'),
      ],
      '#default_value' => 'auto',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Load strains'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $file_ids = (array) $form_state->getValue('input_file');
    $file_id = (int) reset($file_ids);
    $file = File::load($file_id);

    if (!$file) {
      $this->messenger()->addError($this->t('Could not load uploaded file.'));
      return;
    }

    $file->setPermanent();
    $file->save();

    try {
      $result = $this->loader->importFile(
        $file->getFileUri(),
        (int) $form_state->getValue('organism_id'),
        (string) $form_state->getValue('delimiter')
      );
    }
    catch (\RuntimeException $exception) {
      $this->messenger()->addError($this->t('Bulk strain load failed: @message', ['@message' => $exception->getMessage()]));
      return;
    }

    $this->messenger()->addStatus($this->t('Processed @total columns. Inserted @inserted strains, skipped @skipped existing strains.', [
      '@total' => $result['total'],
      '@inserted' => $result['inserted'],
      '@skipped' => $result['skipped'],
    ]));
  }

}
