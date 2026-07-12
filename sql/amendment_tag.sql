-- akn_amendment_tag: the AUTHORED amendment-tagging workflow (the editor's core
-- differentiator). One row per "this Gazette provision amends that Law/Decree
-- provision" instruction, created in action=akn-edit with status=pending and
-- resolved by Special:PendingAmendments.
--
-- IMPORTANT — this is NOT a derived index. Unlike akn_amendment (which the
-- Indexer rebuilds wholesale from <meta><analysis> on every save), these rows
-- are durable authored/workflow state and MUST survive reindexing. That is
-- exactly why it is a separate table: reindexing akn_amendment must never touch
-- this one. Keyed like akn_amendment ((page, order)) for style consistency, but
-- the order is assigned once at creation and never regenerated.

CREATE TABLE /*_*/akn_amendment_tag (
  -- The Gazette page that declares the change, and a stable per-page id.
  amt_source_page INT UNSIGNED NOT NULL,
  amt_order INT UNSIGNED NOT NULL,
  -- Which provision in the Gazette makes the change (eId).
  amt_source_eid VARBINARY(255) DEFAULT NULL,
  -- The resolved Law/Decree target (page id nullable until matched) + provision.
  amt_target_page INT UNSIGNED DEFAULT NULL,
  amt_target_eid VARBINARY(255) DEFAULT NULL,
  -- replace | insert | repeal | renumber
  amt_action VARBINARY(16) NOT NULL,
  -- Effective date of the change (YYYY-MM-DD).
  amt_effective VARBINARY(32) DEFAULT NULL,
  -- pending | applied | rejected
  amt_status VARBINARY(16) NOT NULL DEFAULT 'pending',
  -- The Law/Decree revision created when this tag was approved (nullable).
  amt_applied_rev INT UNSIGNED DEFAULT NULL,
  -- Reason captured on rejection (required by Special:PendingAmendments).
  amt_reason VARBINARY(255) DEFAULT NULL,
  -- MediaWiki timestamp the tag was created (for queue ordering).
  amt_timestamp BINARY(14) DEFAULT NULL,
  PRIMARY KEY (amt_source_page, amt_order)
) /*$wgDBTableOptions*/;

-- The Special:PendingAmendments queue filters/groups on these.
CREATE INDEX /*i*/amt_status ON /*_*/akn_amendment_tag (amt_status);
CREATE INDEX /*i*/amt_target ON /*_*/akn_amendment_tag (amt_target_page, amt_target_eid);
