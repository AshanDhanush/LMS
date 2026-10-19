-- Run once on databases created before username-based CSV imports were added.
ALTER TABLE users
  ADD COLUMN username VARCHAR(64) NULL AFTER name,
  ADD UNIQUE KEY uq_users_username (username);
