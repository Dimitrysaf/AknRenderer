-- akn_amendment: recorded amendment relationships from a document's
-- <meta><analysis><activeModifications>/<passiveModifications> — which
-- provision (in this document or another) was changed by which act, how,
-- and (when resolvable via <force>/<lifecycle>) when. Rebuilt wholesale on
-- every save, like akn_structure — it mirrors what the current XML declares,
-- not a history of edits.

CREATE TABLE /*_*/akn_amendment (
  ama_page INT UNSIGNED NOT NULL,
  ama_order INT UNSIGNED NOT NULL,
  -- 'active': this document modifies another. 'passive': this document was
  -- modified by another.
  ama_direction VARBINARY(8) NOT NULL,
  -- repeal | substitution | insertion | replacement | renumbering | split | join
  ama_type VARBINARY(32) DEFAULT NULL,
  ama_source_href VARBINARY(512) DEFAULT NULL,
  ama_dest_href VARBINARY(512) DEFAULT NULL,
  ama_date VARBINARY(32) DEFAULT NULL,
  PRIMARY KEY (ama_page, ama_order)
) /*$wgDBTableOptions*/;

CREATE INDEX /*i*/ama_date ON /*_*/akn_amendment (ama_date);
