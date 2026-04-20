# t4_bulk_strain_loader

Tripal4 plugin module for bulk-loading kveik strains from a spreadsheet file.

## What this module provides

- A Tripal importer plugin: **Kveik Strain Spreadsheet Loader**
- Import support for CSV and TSV spreadsheet exports
- Idempotent upsert behavior keyed by `strain_name`
- Storage table for loaded strain records

## Expected spreadsheet columns

The loader accepts either:

- a header row with recognized names, or
- no header row (fixed order shown below)

Recognized header names:

- `strain_name` (required; aliases: `name`, `strain`)
- `source`
- `brewery`
- `origin`
- `notes`

If no header row is used, the loader assumes this order:

1. strain_name
2. source
3. brewery
4. origin
5. notes

## Using the importer

1. Enable the `t4_bulk_strain_loader` module.
2. Go to Tripal Data Loaders and open **Kveik Strain Spreadsheet Loader**.
3. Upload or select a CSV/TSV file.
4. Choose delimiter mode and whether the file includes a header row.
5. Run the importer job.
