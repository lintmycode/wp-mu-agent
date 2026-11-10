# Changelog

## 1.0.0 — 2026-11-10
- Initial release: `mau_agent` role, app passwords for that role only with an IP lock, REST allowlist,
  change log with undo, signed draft previews, `discard`, `schema`, WP-CLI `status / schema / setup / revoke / log`.
- Uploads are logged as creates (WordPress skips the `wp_insert_post` action for attachments), and a missing
  log table is recreated on the next write instead of silently breaking undo. `status` shows `log_table`.
