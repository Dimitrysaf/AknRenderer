-- akn_structure: the eId tree of each Law page. Root = the page itself
-- (ast_page); top-level provisions have ast_parent = NULL. Rebuilt on save
-- by HookHandler::onPageSaveComplete. This is the consolidated structure —
-- amendments/temporal data are metadata, not stored here.

CREATE TABLE /*_*/akn_structure (
  ast_page INT UNSIGNED NOT NULL,
  ast_eid VARBINARY(255) NOT NULL,
  ast_parent VARBINARY(255) DEFAULT NULL,
  ast_type VARBINARY(32) NOT NULL,
  ast_num VARBINARY(128) DEFAULT NULL,
  ast_heading VARBINARY(255) DEFAULT NULL,
  ast_order INT UNSIGNED NOT NULL,
  PRIMARY KEY (ast_page, ast_eid)
) /*$wgDBTableOptions*/;

CREATE INDEX /*i*/ast_page_order ON /*_*/akn_structure (ast_page, ast_order);
CREATE INDEX /*i*/ast_parent ON /*_*/akn_structure (ast_page, ast_parent);
CREATE INDEX /*i*/ast_eid ON /*_*/akn_structure (ast_eid);
