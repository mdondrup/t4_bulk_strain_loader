<?php

namespace Drupal\t4_bulk_strain_loader\Plugin\TripalImporter;

use Drupal\Core\Database\Connection;
use Drupal\tripal_chado\TripalImporter\ChadoImporterBase;
use Drupal\tripal_chado\TripalEntity;
use Drupal\tripal_chado\Plugin\TripalBackendPublish\ChadoPublish;

/**
 * Provides a Strain Bulk Loader.
 *
 * @TripalImporter(
 *   id = "strain_bulk_loader",
 *   label = @Translation("Strain Bulk Loader"),
 *   description = @Translation("Imports strains from a CSV/TAB file where each column header is a strain name.")
 * )
 */

#[TripalImporter(
    id: 'strain_bulk_loader',
    label: new TranslatableMarkup('Strain Bulk Loader'),
    description: new TranslatableMarkup('Imports strains from a CSV/TAB file with columns: Name, Uniquename, Description, taxid.'),
    file_types: [
        'tsv',
        'txt',
        'csv',
    ],
    upload_description: new TranslatableMarkup('Provide a CSV or TAB-delimited file with a header row containing at minimum the columns Name and Uniquename. Optional columns: Description, taxid.'),
    upload_title: new TranslatableMarkup('Strain File'),
    use_analysis: false,
    require_analysis: false,
    use_button: true,
    button_text: new TranslatableMarkup('Import Strain file'),
    file_upload: true,
    file_remote: true,
    file_local: true,
    file_required: true,
    publish: [
        'bundle' => [
            'Strain',
        ],
    ],
)]

class StrainBulkLoader extends ChadoImporterBase
{


    public static $file_types = ['csv', 'tsv', 'txt'];

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return t('Strain Bulk Loader');
    }

    /**
     * {@inheritdoc}
     */
    public function getDescription()
    {
        return t('Imports strains from a CSV/TAB file with columns: Name, Uniquename, Description, taxid.');
    }

    /**
     * @see ChadoImporterBase::form()
     */
    public function form($form, &$form_state)
    {

        // Always call the parent form.
        $form = parent::form($form, $form_state);

        $form['organism_id'] = [
            '#type'          => 'number',
            '#title'         => t('Default Organism ID'),
            '#description'   => t('Chado organism_id used when a row has no taxid or the taxid cannot be resolved.'),
            '#required'      => TRUE,
            '#min'           => 1,
            '#step'          => 1,
        ];

        return $form;
    }

    /**
     * @see ChadoImporterBase::formSubmit()
     */
    public function formSubmit($form, &$form_state) {}

    /**
     * @see ChadoImporterBase::formValidate()
     */

    public function formValidate($form, &$form_state) {}

    /**
     * @see ChadoImporterBase::run()
     */
    public function run()
    {

        // All values provided by the user in the Importer's form widgets are
        // made available to us here by the Class' arguments member variable.
        $arguments = $this->arguments['run_args'];

        // The path to the uploaded file is always made available using the
        // 'files' argument. The importer can support multiple files, therefore
        // this is an array of files, where each has a 'file_path' key specifying
        // where the file is located on the server.
        $file_path = $this->arguments['files'][0]['file_path'];

        $this->loadTxtFile($file_path);
    }

    /**
     * @see ChadoImporterBase::postRun()
     */
    public function postRun() {}

    /**
     * Parses a CSV or TAB-delimited file and inserts each row as a strain.
     *
     * Expected header columns (case-sensitive): Name, Uniquename, Description, taxid.
     * Name and Uniquename are required; Description and taxid are optional.
     *
     * @param string $file_path
     *   Absolute path to the uploaded file.
     */
    protected function loadTxtFile(string $file_path): void
    {
        $this->logger->info('Loading file: @file', ['@file' => $file_path]);

        if (!is_readable($file_path)) {
            throw new \RuntimeException(sprintf('Cannot read file: %s', $file_path));
        }

        $handle = fopen($file_path, 'r');
        if (!$handle) {
            throw new \RuntimeException(sprintf('Cannot open file: %s', $file_path));
        }

        try {
            // Auto-detect delimiter from the first line, then rewind.
            $first_line = fgets($handle);
            if ($first_line === FALSE) {
                throw new \RuntimeException('Input file is empty.');
            }
            rewind($handle);

            $delimiter = $this->detectDelimiter($first_line);
            $this->logger->info('Detected delimiter: @delim', [
                '@delim' => $delimiter === "\t" ? 'tab' : 'comma',
            ]);

            $raw_header = fgetcsv($handle, 0, $delimiter);
            if (!$raw_header) {
                throw new \RuntimeException('Could not parse header row from input file.');
            }

            // Build column-name => index map.
            $col_map = $this->mapHeaderColumns($raw_header);
            $this->logger->info('Parsed header columns: @cols', [
                '@cols' => implode(', ', array_keys($col_map)),
            ]);

            foreach (['Name', 'Uniquename'] as $required) {
                if (!isset($col_map[$required])) {
                    throw new \RuntimeException(sprintf('Required column "%s" not found in header.', $required));
                }
            }

            // Any column not in the reserved set is treated as an entity field,
            // with the column name used verbatim as the field machine name.
            $reserved_cols = ['Name' => TRUE, 'Uniquename' => TRUE, 'Description' => TRUE, 'taxid' => TRUE];
            $extra_col_map = array_diff_key($col_map, $reserved_cols);
            $this->logger->info('Extra (non-reserved) columns mapped as field names: @cols', [
                '@cols' => $extra_col_map ? implode(', ', array_keys($extra_col_map)) : '(none)',
            ]);

            // Open a Connection to the default Tripal DBX managed Chado schema.

            $chado = $this->getChadoConnection();

            if (!$chado instanceof Connection) {
                throw new \RuntimeException('Could not get Chado database connection.');
            }
            $stock_type_id = $this->resolveStrainTypeId($chado);
            $default_organism_id = (int) ($this->arguments['run_args']['organism_id'] ?? 0);

            $inserted = 0;
            $skipped = 0;
            $row_num = 0;

            $transaction = $chado->startTransaction();

            while (($row = fgetcsv($handle, 0, $delimiter)) !== FALSE) {
                $row_num++;

                $name       = trim((string) ($row[$col_map['Name']] ?? ''));
                $uniquename = trim((string) ($row[$col_map['Uniquename']] ?? ''));

                $description = isset($col_map['Description'])
                    ? trim((string) ($row[$col_map['Description']] ?? ''))
                    : '';
                $taxid = isset($col_map['taxid'])
                    ? trim((string) ($row[$col_map['taxid']] ?? ''))
                    : '';

                if ($name === '' || $uniquename === '') {
                    $this->logger->warning('Row @row: missing Name or Uniquename — skipping.', ['@row' => $row_num]);
                    $skipped++;
                    continue;
                }

                // Resolve organism_id from taxid when provided.
                $organism_id = $default_organism_id;
                if ($taxid !== '') {
                    $resolved = $this->resolveOrganismByTaxid($chado, $taxid);
                    if ($resolved) {
                        $organism_id = $resolved;
                    } else {
                        $this->logger->warning('Row @row: taxid @taxid not found — using default organism_id.', [
                            '@row'   => $row_num,
                            '@taxid' => $taxid,
                        ]);
                    }
                }

                if (!$organism_id) {
                    $this->logger->warning('Row @row: no organism_id available — skipping.', ['@row' => $row_num]);
                    $skipped++;
                    continue;
                }

                $final_uniquename = $this->ensureUniqueStockUniquename($chado, $uniquename);
                if ($final_uniquename !== $uniquename) {
                    $this->logger->notice('Row @row: uniquename @orig exists, using @new.', [
                        '@row' => $row_num,
                        '@orig' => $uniquename,
                        '@new' => $final_uniquename,
                    ]);
                }

                $fields = [
                    'organism_id' => $organism_id,
                    'name'        => $name,
                    'uniquename'  => $final_uniquename,
                    'type_id'     => 3, // $stock_type_id,
                ];
                if ($description !== '') {
                    $fields['description'] = $description;
                }

                $chado->insert('stock')->fields($fields)->execute();
                $inserted++;
                $this->setItemsHandled($row_num);

                // Collect extra-column values to attach as entity field values.
                $extra_field_values = [];
                foreach ($extra_col_map as $field_name => $idx) {
                    $value = trim((string) ($row[$idx] ?? ''));
                    if ($value !== '') {
                        $extra_field_values[$field_name] = $value;
                    }
                }

                // Now publish the newly created stock as a Strain node in Drupal.
                $entity = $this->publishStockAsStrainNode(
                    (int) $chado->lastInsertId('stock_stock_id_seq'),
                    $extra_field_values
                );
            }

            unset($transaction);
        } finally {
            fclose($handle);
        }

        $this->logger->info(
            'Completed: @total row(s) processed, @inserted inserted, @skipped skipped.',
            [
                '@total'    => $row_num ?? 0,
                '@inserted' => $inserted ?? 0,
                '@skipped'  => $skipped ?? 0,
            ]
        );
    }

    protected function publishStockAsStrainNode(int $stock_id, array $extra_field_values = []): ?\Drupal\tripal\Entity\TripalEntity
    {
        // Publish the stock record as a Tripal entity using the ChadoPublish plugin.
        // ChadoPublish is a Drupal plugin with dependency injection, so it must be
        // instantiated via its plugin manager.
        try {
            /** @var \Drupal\tripal\TripalBackendPublish\PluginManager\TripalBackendPublishManager $publish_manager */
            $publish_manager = \Drupal::service('tripal.backend_publish');
            /** @var \Drupal\tripal_chado\Plugin\TripalBackendPublish\ChadoPublish $publisher */
            $publisher = $publish_manager->createInstance('chado_storage');
            $res = $publisher->publish([
                'bundle' => 'germplasm',
                'datastore' => 'chado_storage',
                'batch_size' => 1,
                'republish' => FALSE,
            ]);
            // publish() returns [title => entity_id] for up to the first 100
            // published entities. It does not include chado record IDs, so we
            // cannot verify $stock_id here — the entity lookup below handles
            // the "was this record actually published?" check.
        } catch (\Exception $e) {
            $this->logger->error('Failed to publish stock_id @id: @error', [
                '@id' => $stock_id,
                '@error' => $e->getMessage(),
            ]);
            return NULL;
        }

        // Retrieve the published entity.
        $entity_lookup = \Drupal::service('tripal.tripal_entity.lookup');
        $entity_id = $entity_lookup->getEntityId($stock_id, NULL, NULL, 'stock');

        if (!$entity_id) {
            $this->logger->warning('Published stock_id @id but entity lookup returned NULL.', [
                '@id' => $stock_id,
            ]);
            return NULL;
        }

        $entity = \Drupal::entityTypeManager()->getStorage('tripal_entity')->load($entity_id);
        if (!$entity) {
            return NULL;
        }

        // Apply any extra columns as field values on the entity. The column
        // name is used verbatim as the field machine name.
        if ($extra_field_values) {
            $this->logger->info(
                'stock_id @id: attempting to set @count extra field value(s): @fields',
                [
                    '@id' => $stock_id,
                    '@count' => count($extra_field_values),
                    '@fields' => implode(', ', array_keys($extra_field_values)),
                ]
            );
            $available_fields = array_keys($entity->getFieldDefinitions());
            $this->logger->info(
                'stock_id @id: entity bundle @bundle has fields: @all',
                [
                    '@id' => $stock_id,
                    '@bundle' => $entity->bundle(),
                    '@all' => implode(', ', $available_fields),
                ]
            );
            $changed = FALSE;
            foreach ($extra_field_values as $field_name => $value) {
                if (!$entity->hasField($field_name)) {
                    $this->logger->warning(
                        'Entity for stock_id @id has no field @field; skipping. Value was: @value',
                        ['@id' => $stock_id, '@field' => $field_name, '@value' => $value]
                    );
                    continue;
                }
                $this->logger->info(
                    'stock_id @id: setting field @field = @value',
                    ['@id' => $stock_id, '@field' => $field_name, '@value' => $value]
                );
                try {
                    $entity->set($field_name, $value);
                    $stored = $entity->get($field_name)->getValue();
                    $this->logger->info(
                        'stock_id @id: field @field after set() contains: @stored',
                        ['@id' => $stock_id, '@field' => $field_name, '@stored' => print_r($stored, TRUE)]
                    );
                    $changed = TRUE;
                } catch (\Exception $e) {
                    $this->logger->error(
                        'Failed to set field @field on stock_id @id: @error',
                        ['@field' => $field_name, '@id' => $stock_id, '@error' => $e->getMessage()]
                    );
                }
            }
            if ($changed) {
                try {
                    $entity->save();
                    $this->logger->info('stock_id @id: entity saved.', ['@id' => $stock_id]);
                    // Reload and verify the values persisted.
                    $reloaded = \Drupal::entityTypeManager()
                        ->getStorage('tripal_entity')
                        ->loadUnchanged($entity->id());
                    foreach ($extra_field_values as $field_name => $value) {
                        if ($reloaded && $reloaded->hasField($field_name)) {
                            $persisted = $reloaded->get($field_name)->getValue();
                            $this->logger->info(
                                'stock_id @id: reloaded field @field contains: @persisted',
                                ['@id' => $stock_id, '@field' => $field_name, '@persisted' => print_r($persisted, TRUE)]
                            );
                        }
                    }
                } catch (\Exception $e) {
                    $this->logger->error(
                        'Failed to save entity for stock_id @id: @error',
                        ['@id' => $stock_id, '@error' => $e->getMessage()]
                    );
                }
            }
        }

        return $entity;
    }


    /**
     * Detects the delimiter from the first line of the file.
     */
    protected function detectDelimiter(string $first_line): string
    {
        return substr_count($first_line, "\t") >= substr_count($first_line, ',') ? "\t" : ',';
    }

    /**
     * Returns a map of trimmed column name => zero-based index.
     *
     * @return array<string, int>
     */
    protected function mapHeaderColumns(array $header): array
    {
        $map = [];
        foreach ($header as $i => $col) {
            $name = trim((string) $col);
            if ($name !== '' && !isset($map[$name])) {
                $map[$name] = $i;
            }
        }
        return $map;
    }

    /**
     * Ensures stock.uniquename is globally unique by appending a UUID on conflict.
     */
    protected function ensureUniqueStockUniquename(Connection $chado, string $base_uniquename): string
    {
        $base_uniquename = trim($base_uniquename);
        if ($base_uniquename === '') {
            $base_uniquename = 'strain';
        }

        $candidate = $base_uniquename;
        $max_length = 255;

        while ($this->stockUniquenameExists($chado, $candidate)) {
            $uuid = \Drupal::service('uuid')->generate();
            $suffix = '--' . $uuid;
            $prefix_max = max(1, $max_length - strlen($suffix));
            $candidate = substr($base_uniquename, 0, $prefix_max) . $suffix;
        }

        return $candidate;
    }

    /**
     * Checks if a stock.uniquename already exists.
     */
    protected function stockUniquenameExists(Connection $chado, string $uniquename): bool
    {
        return (bool) $chado->select('stock', 's')
            ->fields('s', ['stock_id'])
            ->condition('uniquename', $uniquename)
            ->range(0, 1)
            ->execute()
            ->fetchField();
    }

    /**
     * Looks up a Chado organism_id by NCBI taxid via organismprop.
     *
     * @return int|null
     *   The organism_id, or NULL if not found.
     */
    protected function resolveOrganismByTaxid(object $chado, string $taxid): ?int
    {
        // Try organismprop (standard Tripal storage for NCBI taxon IDs).
        $query = $chado->select('cvterm', 'cvt');
        $query->join('cv', 'cv', 'cv.cv_id = cvt.cv_id');
        $type_id = $query
            ->fields('cvt', ['cvterm_id'])
            ->condition('cvt.name', 'taxon_id')
            ->range(0, 1)
            ->execute()
            ->fetchField();

        if ($type_id) {
            $organism_id = $chado->select('organismprop', 'op')
                ->fields('op', ['organism_id'])
                ->condition('type_id', $type_id)
                ->condition('value', $taxid)
                ->range(0, 1)
                ->execute()
                ->fetchField();

            if ($organism_id) {
                return (int) $organism_id;
            }
        }

        return NULL;
    }

    /**
     * Returns the cvterm_id for stock_type:germplasm from Chado.
     */
    protected function resolveStrainTypeId(object $chado): int
    {
        // Get the feature property CV object
        $cv = $chado->select('cv')
            ->fields('cv')
            ->condition('name', 'germplasm_ontology')
            ->execute()
            ->fetchObject();


        if (is_null($cv)) {
            throw new \Exception(t("Cannot find the 'germplasm_ontology' ontology'", []));
        }

        $id = $chado->select('cvterm')
            ->fields('cvterm', ['cvterm_id'])
            ->condition('name', 'generated germplasm')
            ->condition('cv_id', $cv->cv_id)
            ->range(0, 1)
            ->execute()
            ->fetchField();

        if (!$id) {
            throw new \RuntimeException('Could not find stock_type:germplasm cvterm in Chado.');
        }

        return (int) $id;
    }
}
