# t4_bulk_strain_loader

Drupal/Tripal4 extension module skeleton for bulk strain loading.

## What this skeleton does

- Adds an admin form at `/admin/tripal/loaders/bulk-strains`
- Accepts CSV/TAB uploads
- Reads the header row as strain names (one strain per column)
- Inserts missing strains into Chado `stock` (type `stock_type:strain`)
- Skips already-existing `stock.uniquename` values

## Notes

- This is a Tripal4-oriented skeleton and does not use Tripal3 `bulk_csv_loader` patterns.
- The file loading flow follows the same high-level approach as Tripal's GFF3 loader:
  - resolve/read file
  - parse iteratively
  - validate required ontology term
  - insert into Chado with duplicate checks
