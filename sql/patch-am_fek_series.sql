-- Adds am_fek_series to an akn_meta table created before the ΦΕΚ τεύχος
-- (series) field existed.

ALTER TABLE /*_*/akn_meta ADD COLUMN am_fek_series VARBINARY(16) DEFAULT NULL;
