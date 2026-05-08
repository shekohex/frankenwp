-- Safe, repeatable index additions for common WordPress plugin query patterns.
--
-- Usage against a running stack:
--   docker compose exec -T db mariadb -uroot -p"$DB_ROOT_PASSWORD" "$DB_NAME" < docker/mariadb/optimize-wordpress.sql
--
-- These statements are written to be idempotent on MariaDB versions that support
-- ADD INDEX IF NOT EXISTS.

-- Rank Math analytics cleanup performs object_type/object_id lookups and deletes.
ALTER TABLE wp_rank_math_analytics_objects
  ADD INDEX IF NOT EXISTS idx_object_lookup (object_type, object_id);

-- Rank Math redirection cache does repeated object_id/object_type lookups.
-- The plugin also issues OR queries against from_url, but that SQL shape still may
-- not fully use indexes when combined with ORDER BY. This index is still valuable
-- for direct object lookups and for any future plugin query improvements.
ALTER TABLE wp_rank_math_redirections_cache
  ADD INDEX IF NOT EXISTS idx_object_lookup (object_id, object_type);

-- Prefix index for exact/prefix URL matches on text columns.
ALTER TABLE wp_rank_math_redirections_cache
  ADD INDEX IF NOT EXISTS idx_from_url_prefix (from_url(191));

ANALYZE TABLE
  wp_rank_math_analytics_objects,
  wp_rank_math_redirections_cache;
