-- akn_revision: one row per MediaWiki revision of a Law page = one codified
-- version (FRBR expression). Populated on save by the Indexer. The "in force"
-- version is computed at read time (greatest ar_effective not after today).

CREATE TABLE /*_*/akn_revision (
  ar_rev INT UNSIGNED NOT NULL PRIMARY KEY,
  ar_page INT UNSIGNED NOT NULL,
  ar_effective VARBINARY(32) DEFAULT NULL,
  ar_fek VARBINARY(255) DEFAULT NULL,
  ar_fek_number VARBINARY(64) DEFAULT NULL
) /*$wgDBTableOptions*/;

CREATE INDEX /*i*/ar_page_effective ON /*_*/akn_revision (ar_page, ar_effective);
