<?php

namespace Drupal\t4_bulk_strain_loader\Service;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\Connection;

/**
 * Loads strain columns from CSV/TAB files into Chado stock records.
 */
class StrainColumnLoader {

  /**
   * Drupal file system service.
   */
  protected FileSystemInterface $fileSystem;

  /**
   * Constructs the loader service.
   */
  public function __construct(FileSystemInterface $file_system) {
    $this->fileSystem = $file_system;
  }

  /**
   * Imports a file and inserts header columns as strains.
   *
   * @param string $uri
   *   Drupal file URI.
   * @param int $organism_id
   *   Chado organism_id.
   * @param string $delimiter
   *   Delimiter choice: auto, comma, or tab.
   *
   * @return array<string,int>
   *   Import summary counts.
   */
  public function importFile(string $uri, int $organism_id, string $delimiter = 'auto'): array {
    $path = $this->fileSystem->realpath($uri);
    if (!$path || !is_readable($path)) {
      throw new \RuntimeException(sprintf('Cannot read file at %s', $uri));
    }

    $handle = fopen($path, 'r');
    if (!$handle) {
      throw new \RuntimeException(sprintf('Cannot open file at %s', $path));
    }

    try {
      $first_line = fgets($handle);
      if ($first_line === FALSE) {
        throw new \RuntimeException('Input file is empty.');
      }

      rewind($handle);
      $csv_delimiter = $this->resolveDelimiter($delimiter, $first_line);
      $header = fgetcsv($handle, 0, $csv_delimiter);

      if (!$header) {
        throw new \RuntimeException('Could not parse header row from input file.');
      }

      $strain_names = $this->normalizeHeaderColumns($header);
      if (!$strain_names) {
        throw new \RuntimeException('No valid strain column names found in header row.');
      }

      $chado = Database::getConnection('default', 'chado');
      $stock_type_id = $this->getStrainTypeId($chado);

      $inserted = 0;
      $skipped = 0;
      $transaction = $chado->startTransaction();
      foreach ($strain_names as $name) {
        $exists = (bool) $chado->select('stock', 's')
          ->fields('s', ['stock_id'])
          ->condition('uniquename', $name)
          ->condition('organism_id', $organism_id)
          ->condition('type_id', $stock_type_id)
          ->range(0, 1)
          ->execute()
          ->fetchField();

        if ($exists) {
          $skipped++;
          continue;
        }

        try {
          $chado->insert('stock')
            ->fields([
              'organism_id' => $organism_id,
              'name' => $name,
              'uniquename' => $name,
              'type_id' => $stock_type_id,
            ])
            ->execute();
        }
        catch (\Throwable $exception) {
          throw new \RuntimeException(sprintf('Failed to insert strain "%s".', $name), 0, $exception);
        }

        $inserted++;
      }
      // Commit via transaction object destruction (Drupal DB transaction API).
      unset($transaction);

      return [
        'total' => count($strain_names),
        'inserted' => $inserted,
        'skipped' => $skipped,
      ];
    }
    finally {
      fclose($handle);
    }
  }

  /**
   * Resolves delimiter from user choice and file content.
   */
  protected function resolveDelimiter(string $delimiter, string $first_line): string {
    if ($delimiter === ',' || $delimiter === "\t") {
      return $delimiter;
    }

    $comma_count = substr_count($first_line, ',');
    $tab_count = substr_count($first_line, "\t");
    return $tab_count > $comma_count ? "\t" : ',';
  }

  /**
   * Cleans and de-duplicates header values.
   *
   * @return array<int,string>
   *   Normalized strain names.
   */
  protected function normalizeHeaderColumns(array $header): array {
    $seen = [];
    $strain_names = [];

    foreach ($header as $column) {
      $name = trim((string) $column);
      if ($name === '') {
        continue;
      }
      if (isset($seen[$name])) {
        continue;
      }
      $seen[$name] = TRUE;
      $strain_names[] = $name;
    }

    return $strain_names;
  }

  /**
   * Gets the cvterm_id used for strain stocks.
   */
  protected function getStrainTypeId(Connection $chado): int {
    $stock_type_id = $chado->select('cvterm', 'cvt')
      ->fields('cvt', ['cvterm_id'])
      ->join('cv', 'cv', 'cv.cv_id = cvt.cv_id')
      ->condition('cv.name', 'stock_type')
      ->condition('cvt.name', 'strain')
      ->range(0, 1)
      ->execute()
      ->fetchField();

    if (!$stock_type_id) {
      throw new \RuntimeException('Could not find stock_type:strain cvterm in Chado.');
    }

    return (int) $stock_type_id;
  }

}
