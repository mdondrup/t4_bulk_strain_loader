<?php

namespace Drupal\t4_bulk_strain_loader\Plugin\TripalImporter;

use Drupal\tripal\TripalImporter\TripalImporterBase;

/**
 * Kveik strain spreadsheet importer.
 *
 * @TripalImporter(
 *   id = "kveik_strain_spreadsheet_loader",
 *   label = @Translation("Kveik Strain Spreadsheet Loader"),
 *   description = @Translation("Load kveik strain data from CSV or TSV spreadsheets."),
 *   file_types = {"csv", "tsv", "txt"},
 *   upload_description = @Translation("Upload a spreadsheet export in CSV or TSV format."),
 *   upload_title = @Translation("Kveik strain spreadsheet"),
 *   use_analysis = False,
 *   require_analysis = False,
 *   button_text = @Translation("Import kveik strains"),
 *   file_upload = True,
 *   file_local = True,
 *   file_remote = False,
 *   file_required = True,
 *   cardinality = 1,
 *   menu_path = "",
 *   callback = "",
 *   callback_module = "",
 *   callback_path = ""
 * )
 */
class KveikStrainSpreadsheetImporter extends TripalImporterBase {

  /**
   * {@inheritdoc}
   */
  public function form($form, &$form_state) {
    $form['delimiter_mode'] = [
      '#type' => 'select',
      '#title' => t('Delimiter'),
      '#options' => [
        'auto' => t('Auto-detect'),
        'comma' => t('Comma (CSV)'),
        'tab' => t('Tab (TSV)'),
        'semicolon' => t('Semicolon'),
      ],
      '#default_value' => 'auto',
    ];

    $form['has_header'] = [
      '#type' => 'checkbox',
      '#title' => t('Spreadsheet includes a header row'),
      '#default_value' => 1,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function formSubmit($form, &$form_state) {
    // No custom submit handling required.
  }

  /**
   * {@inheritdoc}
   */
  public function formValidate($form, &$form_state) {
    $delimiter_mode = $form_state->getValue('delimiter_mode');
    if (!in_array($delimiter_mode, ['auto', 'comma', 'tab', 'semicolon'], TRUE)) {
      $form_state->setErrorByName('delimiter_mode', t('Invalid delimiter selection.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function addAnalysis($form, &$form_state) {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function run() {
    $args = $this->getArguments();
    $run_args = $args['run_args'] ?? [];
    $file_path = $this->resolveFilePath($args);

    $delimiter = $this->resolveDelimiter((string) ($run_args['delimiter_mode'] ?? 'auto'), $file_path);
    $has_header = !empty($run_args['has_header']);

    $records = $this->parseRecords($file_path, $delimiter, $has_header);
    if (empty($records)) {
      throw new \Exception('No strain rows were found in the spreadsheet.');
    }

    $this->setTotalItems(max(1, count($records)));
    $db = \Drupal::database();
    $changed = \Drupal::time()->getRequestTime();

    foreach ($records as $record) {
      $db->merge('t4_bulk_strain_loader_strain')
        ->key([
          'strain_name' => $record['strain_name'],
        ])
        ->fields([
          'source' => $record['source'],
          'brewery' => $record['brewery'],
          'origin' => $record['origin'],
          'notes' => $record['notes'],
          'raw_row' => json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
          'changed' => $changed,
        ])
        ->execute();

      $this->addItemsHandled(1);
    }

    $this->logger->notice(t('Imported @count kveik strain row(s).', ['@count' => count($records)]));
  }

  /**
   * {@inheritdoc}
   */
  public function postRun() {
    // No post-run action required.
  }

  /**
   * Resolve the importer file path from argument payload.
   */
  private function resolveFilePath(array $args) {
    $file = $args['file']['file_path'] ?? NULL;
    if (!$file && !empty($args['files'][0]['file_path'])) {
      $file = $args['files'][0]['file_path'];
    }

    if (!$file || !file_exists($file) || !is_readable($file)) {
      throw new \Exception('The selected spreadsheet file is missing or unreadable.');
    }

    return $file;
  }

  /**
   * Resolve delimiter from form selection.
   */
  private function resolveDelimiter($mode, $file_path) {
    switch ($mode) {
      case 'comma':
        return ',';

      case 'tab':
        return "\t";

      case 'semicolon':
        return ';';

      case 'auto':
      default:
        return $this->detectDelimiter($file_path);
    }
  }

  /**
   * Detect delimiter from the first non-empty line.
   */
  private function detectDelimiter($file_path) {
    $handle = fopen($file_path, 'r');
    if (!$handle) {
      $this->logger->warning(t('Could not open @file to auto-detect delimiter; defaulting to comma.', ['@file' => $file_path]));
      return ',';
    }

    $line = '';
    while (($line = fgets($handle)) !== FALSE) {
      $line = trim($line);
      if ($line !== '') {
        break;
      }
    }
    fclose($handle);

    if ($line === '') {
      return ',';
    }

    $counts = [
      ',' => substr_count($line, ','),
      "\t" => substr_count($line, "\t"),
      ';' => substr_count($line, ';'),
    ];

    arsort($counts);
    return (string) array_key_first($counts);
  }

  /**
   * Parse spreadsheet rows into normalized records.
   */
  private function parseRecords($file_path, $delimiter, $has_header) {
    $rows = [];
    $file = new \SplFileObject($file_path, 'r');
    $file->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY);
    $file->setCsvControl($delimiter);

    $header = [];
    if ($has_header) {
      while (!$file->eof()) {
        $header_candidate = $file->fgetcsv();
        if (!$this->isEmptyRow($header_candidate)) {
          $header = $this->normalizeHeader($header_candidate);
          break;
        }
      }
    }

    foreach ($file as $line_number => $row) {
      if (!is_array($row) || $this->isEmptyRow($row)) {
        continue;
      }

      $normalized = $this->normalizeRow($row, $header);
      $strain_name = trim((string) ($normalized['strain_name'] ?? $normalized['name'] ?? $normalized['strain'] ?? ''));
      if ($strain_name === '') {
        throw new \InvalidArgumentException('Missing required strain name on row ' . ($line_number + 1) . '. Expected one of: strain_name, name, strain.');
      }

      $rows[] = [
        'strain_name' => $strain_name,
        'source' => $this->valueFromAliases($normalized, ['source']),
        'brewery' => $this->valueFromAliases($normalized, ['brewery']),
        'origin' => $this->valueFromAliases($normalized, ['origin']),
        'notes' => $this->valueFromAliases($normalized, ['notes', 'comment', 'comments']),
      ];
    }

    return $rows;
  }

  /**
   * Convert header labels to machine-safe keys.
   */
  private function normalizeHeader(array $header) {
    $normalized = [];
    foreach ($header as $cell) {
      $key = mb_strtolower(trim((string) $cell));
      $key = preg_replace('/[^a-z0-9]+/', '_', $key);
      $key = trim((string) $key, '_');
      $normalized[] = $key !== '' ? $key : 'column_' . count($normalized);
    }
    return $normalized;
  }

  /**
   * Normalize row values as an associative or positional mapping.
   */
  private function normalizeRow(array $row, array $header) {
    $clean = [];
    foreach ($row as $value) {
      $clean[] = trim((string) $value);
    }

    if (!empty($header)) {
      $values = array_slice(array_pad($clean, count($header), ''), 0, count($header));
      return array_combine($header, $values);
    }

    return [
      'strain_name' => $clean[0] ?? '',
      'source' => $clean[1] ?? '',
      'brewery' => $clean[2] ?? '',
      'origin' => $clean[3] ?? '',
      'notes' => $clean[4] ?? '',
    ];
  }

  /**
   * Determine if a row is empty.
   */
  private function isEmptyRow($row) {
    if (!is_array($row)) {
      return TRUE;
    }
    foreach ($row as $value) {
      if (trim((string) $value) !== '') {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Return the first non-empty value from candidate keys.
   */
  private function valueFromAliases(array $row, array $aliases) {
    foreach ($aliases as $key) {
      if (!empty($row[$key])) {
        return (string) $row[$key];
      }
    }
    return '';
  }

}
