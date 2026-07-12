-- Adds akn_gazette.agz_doc_type (act | pd | other): the kind of the primary
-- document published in this Gazette issue. Derivable from the XML root, so it
-- IS populated by GazetteExtractor on the wholesale rebuild (unlike agz_status,
-- which is publication workflow state and is derived from page protection, not
-- stored here — see Instructions.md §2).

ALTER TABLE /*_*/akn_gazette ADD COLUMN agz_doc_type VARBINARY(16) DEFAULT NULL;
