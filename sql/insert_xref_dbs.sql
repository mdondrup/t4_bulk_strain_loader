-- Insert the chado.db rows required by the field_xref_* columns in
-- germplasm_test_data.csv.txt. Run against the Chado schema, e.g.:
--   psql -d <drupal_db> -f sql/insert_xref_dbs.sql
-- Adjust the schema name below if your Chado is not in schema "chado".

SET search_path = chado, public;

INSERT INTO db (name, description, urlprefix, url) VALUES
    ('ena_biosample',
     'European Nucleotide Archive BioSample accessions',
     'https://www.ebi.ac.uk/ena/browser/view/{accession}',
     'https://www.ebi.ac.uk/ena/browser/home'),
    ('ena_run',
     'European Nucleotide Archive sequencing run accessions',
     'https://www.ebi.ac.uk/ena/browser/view/{accession}',
     'https://www.ebi.ac.uk/ena/browser/home'),
    ('ena_bioproject',
     'European Nucleotide Archive BioProject accessions',
     'https://www.ebi.ac.uk/ena/browser/view/{accession}',
     'https://www.ebi.ac.uk/ena/browser/home'),
    ('pubmed',
     'NCBI PubMed literature database',
     'https://pubmed.ncbi.nlm.nih.gov/{accession}/',
     'https://pubmed.ncbi.nlm.nih.gov/')
ON CONFLICT (name) DO UPDATE SET
    description = EXCLUDED.description,
    urlprefix = EXCLUDED.urlprefix,
    url = EXCLUDED.url
    RETURNING db_id, name;
