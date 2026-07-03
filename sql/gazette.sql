-- akn_gazette: a gazette issue's own identity (series/number/date), one row
-- per page whose XML root is an <officialGazette> — separate from
-- akn_meta.am_fek_*/akn_revision.ar_fek_*, which record a LAW's citation of
-- the ΦΕΚ that published it. Sharing one table for both "I was published in
-- this ΦΕΚ" and "I am this ΦΕΚ" would overload the same columns with two
-- different meanings depending on namespace; kept separate on purpose.
-- Rebuilt wholesale on every save, like akn_structure/akn_amendment.

CREATE TABLE /*_*/akn_gazette (
  agz_page INT UNSIGNED NOT NULL PRIMARY KEY,
  agz_series VARBINARY(16) DEFAULT NULL,
  agz_number VARBINARY(64) DEFAULT NULL,
  agz_date VARBINARY(32) DEFAULT NULL
) /*$wgDBTableOptions*/;

CREATE INDEX /*i*/agz_series_number_date ON /*_*/akn_gazette (agz_series, agz_number, agz_date);
