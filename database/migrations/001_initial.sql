-- Migration 001: Initial schema
-- Forward: Apply complete initial schema
-- Backward: DROP all tables in reverse dependency order
--
-- This migration is identical to schema.sql for the first release.
-- Future migrations will be incremental diffs.

-- See database/schema.sql for the canonical definition.
-- This file exists to establish the migration numbering convention.

\i ../schema.sql
