-- akn_classification: structured subject classification from
-- <meta><classification><keyword>, one row per keyword. @showAs alone (the
-- flat string MetaExtractor.am_keywords collapses these to) is only a
-- display label — @dictionary + @value identify the actual controlled-
-- vocabulary concept (e.g. a EuroVoc thesaurus entry), which is what makes
-- cross-document subject queries possible. Rebuilt wholesale on every save,
-- like akn_structure/akn_amendment/akn_gazette.

CREATE TABLE /*_*/akn_classification (
  acl_page INT UNSIGNED NOT NULL,
  acl_order INT UNSIGNED NOT NULL,
  acl_dictionary VARBINARY(255) DEFAULT NULL,
  acl_value VARBINARY(255) DEFAULT NULL,
  acl_showas VARBINARY(255) DEFAULT NULL,
  -- The fragment (eId) of the document this keyword applies to, when the
  -- classification is scoped to less than the whole document.
  acl_href VARBINARY(512) DEFAULT NULL,
  PRIMARY KEY (acl_page, acl_order)
) /*$wgDBTableOptions*/;

CREATE INDEX /*i*/acl_dictionary_value ON /*_*/akn_classification (acl_dictionary, acl_value);
