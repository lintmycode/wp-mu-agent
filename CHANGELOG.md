# Changelog

## 1.2.0 — 2026-11-11
- **Terms, opt-in per site:** `wp mau-agent terms on` lets the agent create and rename terms of its enabled types'
  taxonomies (core REST routes, now allowlisted for POST) and delete them through `POST term-delete/{taxonomy}/{id}`.
  Every term change is logged in the same table (`term_create / term_update / term_delete`); `POST term-undo/{taxonomy}/{id}`
  reverses the last one, recreating a deleted term on its posts and child terms. The term capabilities are granted at
  check time, so `terms off` removes them at once. `ping` and `status` report `terms`. Off = 1.1.0 behaviour.

## 1.1.0 — 2026-11-11
- **Delete on request:** `POST /mau-agent/v1/trash/{id}` moves one item of an enabled type to the trash (never a
  permanent delete). The runtime calls it only after the client confirmed in chat. It is logged with a snapshot,
  so `undo` takes it out of the trash and restores status, slug, meta and terms. The role still has no `delete_*`
  capability; this route is the only way in.

## 1.0.1 — 2026-11-10
- **Security:** the REST allowlist did nothing on sites running ACF. ACF's `rest_pre_dispatch` callback (priority 10)
  returns null and overwrote our 403, so the agent could reach users, posts, pages and custom routes (its role's
  capabilities still applied). The check now runs at `PHP_INT_MAX` on `rest_pre_dispatch` and again on
  `rest_request_before_callbacks`, which also covers requests inside `/batch/v1`.

## 1.0.0 — 2026-11-10
- Initial release: `mau_agent` role, app passwords for that role only with an IP lock, REST allowlist,
  change log with undo, signed draft previews, `discard`, `schema`, WP-CLI `status / schema / setup / revoke / log`.
- Uploads are logged as creates (WordPress skips the `wp_insert_post` action for attachments), and a missing
  log table is recreated on the next write instead of silently breaking undo. `status` shows `log_table`.
