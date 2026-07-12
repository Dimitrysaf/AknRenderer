-- Consolidation metadata on akn_revision: links a codified version to the
-- amendment tag that produced it and records the in-force window. Set during
-- approval in Special:PendingAmendments, NOT by the Indexer's per-save rebuild,
-- so these stay nullable and are left untouched by ordinary reindexing.
--
-- One ALTER per column so this runs on SQLite (the dev DB), which only supports
-- ADD COLUMN one at a time.

ALTER TABLE /*_*/akn_revision ADD COLUMN ar_applied_tag INT UNSIGNED DEFAULT NULL;
ALTER TABLE /*_*/akn_revision ADD COLUMN ar_in_force_from VARBINARY(32) DEFAULT NULL;
ALTER TABLE /*_*/akn_revision ADD COLUMN ar_in_force_to VARBINARY(32) DEFAULT NULL;
