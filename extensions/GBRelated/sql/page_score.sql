-- wiki-quality score per game page (length + images + reviews); component
-- columns let the save hook refresh length/image without clobbering reviews
CREATE TABLE IF NOT EXISTS /*_*/gb_page_score (
  gps_page INT UNSIGNED NOT NULL PRIMARY KEY,
  gps_length INT UNSIGNED NOT NULL DEFAULT 0,
  gps_image INT UNSIGNED NOT NULL DEFAULT 0,
  gps_reviews INT UNSIGNED NOT NULL DEFAULT 0,
  gps_score INT UNSIGNED NOT NULL DEFAULT 0
) /*$wgDBTableOptions*/;

CREATE INDEX /*i*/gps_score ON /*_*/gb_page_score (gps_score);
