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

    public static $upload_description = 'Provide a CSV or TAB-delimited file with a header row containing at minimum the columns Name and Uniquename. Optional columns: Description, taxid.';
    public static $upload_title = 'Strain File';

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

        $organism_options = $this->getOrganismOptions();

        $form['organism_id'] = [
            '#type'          => 'select',
            '#title'         => t('Default Organism'),
            '#description'   => t('Chado organism used when a row has no taxid or the taxid cannot be resolved.'),
            '#required'      => TRUE,
            '#options'       => $organism_options,
            '#empty_option'  => t('- Select an organism -'),
        ];

        $form['uniquename_clash'] = [
            '#type'          => 'radios',
            '#title'         => t('On uniquename clash'),
            '#description'   => t('What to do when a row\'s Uniquename already exists in the stock table.'),
            '#required'      => TRUE,
            '#default_value' => 'update',
            '#options'       => [
                'ignore' => t('Ignore: skip the row, leaving the existing stock unchanged.'),
                'update' => t('Update: overwrite the existing stock\'s fields with values from this row.'),
                'rename' => t('Rename: insert a new stock with a UUID-suffixed uniquename.'),
            ],
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

            // Detect cross-reference columns: any header starting with
            // "field_xref_". The suffix is the chado.db.name. Multiple columns
            // sharing the same header are supported (each contributes one
            // accession), so we work directly off $raw_header rather than
            // $col_map (which de-dupes).
            //
            // $xref_col_map: db_name => list<int> of column indexes.
            $xref_col_map = [];
            $xref_reserved = [];
            foreach ($raw_header as $i => $col) {
                $name = trim((string) $col);
                if (strpos($name, 'field_xref_') === 0) {
                    $db_name = substr($name, strlen('field_xref_'));
                    if ($db_name === '') {
                        continue;
                    }
                    $xref_col_map[$db_name][] = $i;
                    $xref_reserved[$name] = TRUE;
                }
            }
            $this->logger->info('Detected xref columns: @cols', [
                '@cols' => $xref_col_map ? implode(', ', array_keys($xref_col_map)) : '(none)',
            ]);

            // Open a Connection to the default Tripal DBX managed Chado schema.
            // All TripalImporter runs are exectuted in a trans
            // action, so if anything goes wrong the Chado database will be rolled back to its previous state.

            $chado = $this->getChadoConnection();

            if (!$chado instanceof Connection) {
                throw new \RuntimeException('Could not get Chado database connection.');
            }

            // Validate that every referenced db exists in chado.db; abort
            // up-front if any are missing so we don't insert partial data.
            $xref_db_ids = [];
            $missing_dbs = [];
            foreach (array_keys($xref_col_map) as $db_name) {
                $db_id = $this->findDbIdByName($chado, $db_name);
                if ($db_id === NULL) {
                    $missing_dbs[] = $db_name;
                } else {
                    $xref_db_ids[$db_name] = $db_id;
                }
            }
            if ($missing_dbs) {
                throw new \RuntimeException(sprintf(
                    'The following xref db(s) are not present in chado.db: %s. Add them before importing.',
                    implode(', ', $missing_dbs)
                ));
            }

            // Any column not in the reserved set (and not an xref column) is
            // treated as an entity field, with the column name used verbatim
            // as the field machine name.
            $reserved_cols = ['Name' => TRUE, 'Uniquename' => TRUE, 'Description' => TRUE, 'taxid' => TRUE]
                + $xref_reserved;
            $extra_col_map = array_diff_key($col_map, $reserved_cols);
            $this->logger->info('Extra (non-reserved) columns mapped as field names: @cols', [
                '@cols' => $extra_col_map ? implode(', ', array_keys($extra_col_map)) : '(none)',
            ]);
            $stock_type_id = $this->resolveStrainTypeId($chado);
            $default_organism_id = (int) ($this->arguments['run_args']['organism_id'] ?? 0);
            $clash_mode = (string) ($this->arguments['run_args']['uniquename_clash'] ?? 'update');
            if (!in_array($clash_mode, ['ignore', 'update', 'rename'], TRUE)) {
                $clash_mode = 'update';
            }
            $this->logger->info('Uniquename clash handling: @mode', ['@mode' => $clash_mode]);

            $inserted = 0;
            $updated = 0;
            $skipped = 0;
            $row_num = 0;


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

                $existing_stock_id = $this->findStockIdByUniquename($chado, $uniquename);
                $stock_id = NULL;

                if ($existing_stock_id !== NULL) {
                    if ($clash_mode === 'ignore') {
                        $this->logger->notice('Row @row: uniquename @u exists (stock_id @sid) — ignoring.', [
                            '@row' => $row_num,
                            '@u' => $uniquename,
                            '@sid' => $existing_stock_id,
                        ]);
                        $skipped++;
                        continue;
                    }

                    if ($clash_mode === 'update') {
                        $update_fields = [
                            'organism_id' => $organism_id,
                            'name'        => $name,
                            'type_id'     => 3, // $stock_type_id, must equal the cvterm_id used when creating germplasm
                        ];
                        if ($description !== '') {
                            $update_fields['description'] = $description;
                        }
                        $chado->update('stock')
                            ->fields($update_fields)
                            ->condition('stock_id', $existing_stock_id)
                            ->execute();
                        $stock_id = $existing_stock_id;
                        $updated++;
                        $this->logger->notice('Row @row: uniquename @u exists (stock_id @sid) — updated.', [
                            '@row' => $row_num,
                            '@u' => $uniquename,
                            '@sid' => $existing_stock_id,
                        ]);
                    }
                    elseif ($clash_mode === 'rename') {
                        $final_uniquename = $this->ensureUniqueStockUniquename($chado, $uniquename);
                        $this->logger->notice('Row @row: uniquename @orig exists, using @new.', [
                            '@row' => $row_num,
                            '@orig' => $uniquename,
                            '@new' => $final_uniquename,
                        ]);
                        $fields = [
                            'organism_id' => $organism_id,
                            'name'        => $name,
                            'uniquename'  => $final_uniquename,
                            'type_id'     => 3,
                        ];
                        if ($description !== '') {
                            $fields['description'] = $description;
                        }
                        $chado->insert('stock')->fields($fields)->execute();
                        $stock_id = (int) $chado->lastInsertId('stock_stock_id_seq');
                        $inserted++;
                    }
                }
                else {
                    // No clash — straightforward insert.
                    $fields = [
                        'organism_id' => $organism_id,
                        'name'        => $name,
                        'uniquename'  => $uniquename,
                        'type_id'     => 3,
                    ];
                    if ($description !== '') {
                        $fields['description'] = $description;
                    }
                    $chado->insert('stock')->fields($fields)->execute();
                    $stock_id = (int) $chado->lastInsertId('stock_stock_id_seq');
                    $inserted++;
                }

                $this->setItemsHandled($row_num);

                // Process cross-reference columns: insert dbxref + stock_dbxref
                // for each non-empty xref value. Multiple columns of the same
                // db are supported.
                $this->applyStockXrefs($chado, $stock_id, $row, $xref_col_map, $xref_db_ids, $row_num);

                // Collect extra-column values to attach as entity field values.
                $extra_field_values = [];
                foreach ($extra_col_map as $field_name => $idx) {
                    $value = trim((string) ($row[$idx] ?? ''));
                    if ($value !== '') {
                        $extra_field_values[$field_name] = $value;
                    }
                }

                // Publish the new/updated stock as a Strain entity in Drupal.
                $entity = $this->publishStockAsStrainNode(
                    $stock_id,
                    $extra_field_values
                );
            }

        } finally {
            fclose($handle);
        }

        $this->logger->info(
            'Completed: @total row(s) processed, @inserted inserted, @updated updated, @skipped skipped.',
            [
                '@total'    => $row_num ?? 0,
                '@inserted' => $inserted ?? 0,
                '@updated'  => $updated ?? 0,
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
            // Publish the last entered stock record. Because the process is in a transaction,
            // the ChadoPublish plugin will see only the exact last record when it queries for unpublished stocks.  
            $publisher->publish([
                'bundle' => 'germplasm',
                'datastore' => 'chado_storage',
                'batch_size' => 1,
                // Only publish records that don't already have a Tripal
                // entity. ChadoPublish::publish() does NOT support filtering
                // by record_id, so 'republish' => TRUE would re-publish every
                // entity in the bundle on every row (O(N^2)). For
                // already-published stocks (clash mode 'update' or new
                // stock_dbxref rows added by applyStockXrefs) we instead
                // refresh the single affected entity below by calling
                // $entity->save(): TripalEntity::preSave() invokes
                // ChadoStorage::loadValues() which re-reads the field values
                // from Chado, including newly-linked dbxref rows.
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

        // Track whether anything changed so we know to save. We always want to
        // save once per row regardless of extra fields so the entity's Drupal
        // field cache is refreshed from Chado (this picks up new stock_dbxref
        // linker rows added by applyStockXrefs()).
        $changed = TRUE;

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
            foreach ($extra_field_values as $field_name => $value) {
                if (!$entity->hasField($field_name)) {
                    $this->logger->warning(
                        'Entity for stock_id @id has no field @field; skipping. Value was: @value',
                        ['@id' => $stock_id, '@field' => $field_name, '@value' => $value]
                    );
                    continue;
                }

                $def = $entity->getFieldDefinition($field_name);
                $type = $def->getType();

                try {
                    switch ($type) {
                        case 'geolocation':
                            [$lat, $lng] = array_map('trim', explode(',', $value, 2));
                            $entity->set($field_name, ['lat' => (float) $lat, 'lng' => (float) $lng]);
                            break;

                        case 'geofield':
                            // Accept either "lat,lng" or a raw WKT string.
                            if (stripos($value, 'POINT') === 0) {
                                $entity->set($field_name, ['value' => $value]);
                            } else {
                                [$lat, $lng] = array_map('trim', explode(',', $value, 2));
                                $entity->set($field_name, ['value' => "POINT($lng $lat)"]);
                            }
                            break;

                        default:
                            // Atomic scalar fallback (string, integer, datetime, etc.).
                            $entity->set($field_name, $value);
                    }
                } catch (\Exception $e) {
                    $this->logger->error('Failed to set @field on stock_id @id (@type): @error', [
                        '@field' => $field_name,
                        '@id' => $stock_id,
                        '@type' => $type,
                        '@error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Always save the just-touched entity once per row. Saving an existing
        // TripalEntity triggers TripalEntity::preSave() which calls each
        // storage backend's loadValues() and re-reads field values from Chado.
        // This is how the new stock_dbxref linker rows added in
        // applyStockXrefs() get mirrored into the Drupal field tables, without
        // having to republish the whole bundle (ChadoPublish has no
        // per-record-id filter).
        try {
            $entity->save();
            $this->logger->info('stock_id @id: entity saved (field cache refreshed from Chado).', ['@id' => $stock_id]);
            if ($extra_field_values) {
                // Reload and verify the extra-field values persisted.
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
            }
        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to save entity for stock_id @id: @error',
                ['@id' => $stock_id, '@error' => $e->getMessage()]
            );
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
     * Returns the stock_id for an existing stock.uniquename, or NULL.
     */
    protected function findStockIdByUniquename(Connection $chado, string $uniquename): ?int
    {
        $id = $chado->select('stock', 's')
            ->fields('s', ['stock_id'])
            ->condition('uniquename', $uniquename)
            ->range(0, 1)
            ->execute()
            ->fetchField();
        return $id ? (int) $id : NULL;
    }

    /**
     * Returns the db_id for an entry in chado.db, or NULL when not found.
     */
    protected function findDbIdByName(Connection $chado, string $db_name): ?int
    {
        $id = $chado->select('db', 'd')
            ->fields('d', ['db_id'])
            ->condition('name', $db_name)
            ->range(0, 1)
            ->execute()
            ->fetchField();
        return $id ? (int) $id : NULL;
    }

    /**
     * Returns an existing dbxref_id for (db_id, accession), or NULL.
     */
    protected function findDbxrefId(Connection $chado, int $db_id, string $accession): ?int
    {
        $id = $chado->select('dbxref', 'x')
            ->fields('x', ['dbxref_id'])
            ->condition('db_id', $db_id)
            ->condition('accession', $accession)
            ->range(0, 1)
            ->execute()
            ->fetchField();
        return $id ? (int) $id : NULL;
    }

    /**
     * Inserts a chado.dbxref row if missing and returns its dbxref_id.
     */
    protected function findOrCreateDbxref(Connection $chado, int $db_id, string $accession): int
    {
        $existing = $this->findDbxrefId($chado, $db_id, $accession);
        if ($existing !== NULL) {
            return $existing;
        }
        $chado->insert('dbxref')
            ->fields(['db_id' => $db_id, 'accession' => $accession])
            ->execute();
        return (int) $chado->lastInsertId('dbxref_dbxref_id_seq');
    }

    /**
     * For each xref column in this row, ensure a chado.dbxref exists and link
     * it to the stock via chado.stock_dbxref (idempotent).
     *
     * @param array<string, list<int>> $xref_col_map  db_name => [col indexes]
     * @param array<string, int>       $xref_db_ids   db_name => db_id
     */
    protected function applyStockXrefs(
        Connection $chado,
        int $stock_id,
        array $row,
        array $xref_col_map,
        array $xref_db_ids,
        int $row_num
    ): void {
        foreach ($xref_col_map as $db_name => $indexes) {
            $db_id = $xref_db_ids[$db_name] ?? NULL;
            if (!$db_id) {
                continue;
            }
            foreach ($indexes as $idx) {
                $value = trim((string) ($row[$idx] ?? ''));
                if ($value === '') {
                    continue;
                }

                // If the value is prefixed with the db name (case-insensitive),
                // strip it. e.g. "PMID:30150001" with db "pubmed" or "PMID"
                // becomes "30150001".
                $accession = $value;
                if (strpos($accession, ':') !== FALSE) {
                    [$prefix, $rest] = explode(':', $accession, 2);
                    if (strcasecmp(trim($prefix), $db_name) === 0) {
                        $accession = trim($rest);
                    }
                }
                if ($accession === '') {
                    continue;
                }

                try {
                    $dbxref_id = $this->findOrCreateDbxref($chado, $db_id, $accession);

                    // Link to stock if not already linked.
                    $linked = $chado->select('stock_dbxref', 'sd')
                        ->fields('sd', ['stock_dbxref_id'])
                        ->condition('stock_id', $stock_id)
                        ->condition('dbxref_id', $dbxref_id)
                        ->range(0, 1)
                        ->execute()
                        ->fetchField();
                    if (!$linked) {
                        $chado->insert('stock_dbxref')
                            ->fields(['stock_id' => $stock_id, 'dbxref_id' => $dbxref_id])
                            ->execute();
                        $this->logger->notice(
                            'Row @row: linked xref @db:@acc (dbxref_id @xid) to stock_id @sid.',
                            [
                                '@row' => $row_num,
                                '@db'  => $db_name,
                                '@acc' => $accession,
                                '@xid' => $dbxref_id,
                                '@sid' => $stock_id,
                            ]
                        );
                    } else {
                        $this->logger->info(
                            'Row @row: xref @db:@acc already linked to stock_id @sid (skip).',
                            [
                                '@row' => $row_num,
                                '@db'  => $db_name,
                                '@acc' => $accession,
                                '@sid' => $stock_id,
                            ]
                        );
                    }
                } catch (\Exception $e) {
                    $this->logger->error(
                        'Row @row: failed to attach xref @db:@acc to stock_id @sid: @err',
                        [
                            '@row' => $row_num,
                            '@db' => $db_name,
                            '@acc' => $accession,
                            '@sid' => $stock_id,
                            '@err' => $e->getMessage(),
                        ]
                    );
                }
            }
        }
    }

    /**
     * Builds a select-options array of all organisms in chado.organism.
     *
     * Keys are organism_id; values are human-readable labels of the form
     * "Genus species (infraspecific)" with abbreviation when present.
     *
     * @return array<int, string>
     */
    protected function getOrganismOptions(): array
    {
        $options = [];
        try {
            $chado = $this->getChadoConnection();
            if (!$chado instanceof Connection) {
                return $options;
            }
            $rows = $chado->select('organism', 'o')
                ->fields('o', ['organism_id', 'genus', 'species', 'abbreviation', 'infraspecific_name'])
                ->orderBy('genus')
                ->orderBy('species')
                ->execute();
            foreach ($rows as $r) {
                $label = trim(((string) $r->genus) . ' ' . ((string) $r->species));
                if (!empty($r->infraspecific_name)) {
                    $label .= ' ' . $r->infraspecific_name;
                }
                if (!empty($r->abbreviation)) {
                    $label .= ' (' . $r->abbreviation . ')';
                }
                $options[(int) $r->organism_id] = $label !== '' ? $label : ('organism_id ' . $r->organism_id);
            }
        } catch (\Exception $e) {
            $this->logger->error('Could not load organism list: @err', ['@err' => $e->getMessage()]);
        }
        return $options;
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
