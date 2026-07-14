CREATE TABLE /*_*/gb_related (
  rel_page INT UNSIGNED NOT NULL,
  rel_group VARBINARY(24) NOT NULL,
  rel_rank SMALLINT UNSIGNED NOT NULL,
  rel_target VARBINARY(255) NOT NULL,
  rel_target_id INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (rel_page, rel_group, rel_rank)
) /*$wgDBTableOptions*/;

CREATE INDEX /*i*/gb_related_target ON /*_*/gb_related (rel_target_id);
