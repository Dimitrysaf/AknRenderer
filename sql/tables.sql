-- akn_meta: one row per Law page, holding the indexed subset of AKN metadata.
-- Populated on save by HookHandler::onPageSaveComplete.

CREATE TABLE /*_*/akn_meta (
  am_page INT UNSIGNED NOT NULL PRIMARY KEY,
  am_work_uri VARBINARY(255) DEFAULT NULL,
  am_expr_uri VARBINARY(255) DEFAULT NULL,
  am_alias VARBINARY(255) DEFAULT NULL,
  am_doc_type VARBINARY(64) DEFAULT NULL,
  am_number VARBINARY(64) DEFAULT NULL,
  am_country VARBINARY(8) DEFAULT NULL,
  am_language VARBINARY(16) DEFAULT NULL,
  am_subtype VARBINARY(64) DEFAULT NULL,
  am_enacted VARBINARY(32) DEFAULT NULL,
  am_fek VARBINARY(255) DEFAULT NULL,
  am_fek_number VARBINARY(64) DEFAULT NULL,
  am_pub_date VARBINARY(32) DEFAULT NULL,
  am_keywords VARBINARY(255) DEFAULT NULL,
  am_updated BINARY(14) NOT NULL
) /*$wgDBTableOptions*/;

CREATE INDEX /*i*/am_work_uri ON /*_*/akn_meta (am_work_uri);
CREATE INDEX /*i*/am_doc_type ON /*_*/akn_meta (am_doc_type);
