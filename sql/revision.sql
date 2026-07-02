-- akn_revision: one row per DISTINCT (page, effective date) = one codified
-- version (FRBR expression). Identity is the effective date, not the
-- MediaWiki revision: saves that don't change the declared FRBRExpression/
-- FRBRWork date (typo fixes, formatting, structural touch-ups) update the
-- existing row's ar_rev in place rather than creating a new "version", so a
-- law's version table only grows when a genuinely new codified text takes
-- effect. Populated on save by the Indexer. The "in force" version is
-- computed at read time (greatest ar_effective not after today).

CREATE TABLE /*_*/akn_revision (
  ar_page INT UNSIGNED NOT NULL,
  ar_effective VARBINARY(32) NOT NULL,
  ar_rev INT UNSIGNED NOT NULL,
  ar_fek VARBINARY(255) DEFAULT NULL,
  ar_fek_series VARBINARY(16) DEFAULT NULL,
  ar_fek_number VARBINARY(64) DEFAULT NULL,
  ar_fek_date VARBINARY(32) DEFAULT NULL,
  PRIMARY KEY (ar_page, ar_effective)
) /*$wgDBTableOptions*/;

CREATE UNIQUE INDEX /*i*/ar_rev ON /*_*/akn_revision (ar_rev);
