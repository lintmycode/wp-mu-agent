# nitida/wp-mu-agent

WordPress must-use plugin that lets the **MAU site agent** (a Telegram bot running on its own VPS) edit a
site through the REST API, and nothing more. Spec: `~/projects/sys/specs/site-agent-build.md` §1.
Runbook: `~/projects/sys/mau-agent/README.md`.

## What it does

| | |
|---|---|
| **Role `mau_agent`** | edit + publish posts, pages and the content types enabled at setup; upload media. No `delete_*` (deleting = `trash/{id}` only, reversible), no `unfiltered_html` (WordPress kses-filters what it writes), no new terms, no settings, users, plugins, themes |
| **One user, `mau-agent`** | cannot log in to wp-admin (the login form refuses it); authenticates only with an application password |
| **App passwords** | on for the agent role only. Every other user keeps what the site had: off under `wp-mu-security`, on where `WP_MU_SECURITY_ALLOW_APP_PASSWORDS=true` |
| **IP lock** | the agent's password works only from the IPs saved at setup (`REMOTE_ADDR`; on jaws nginx sets it from `CF-Connecting-IP`). Empty list = nobody. `*` accepted outside production only |
| **REST allowlist** | the agent gets 403 on everything except: `/mau-agent/v1/*`, `GET /`, `GET /wp/v2/users/me`, `GET` types/taxonomies, media (read/upload), the enabled types (read/write), their taxonomies (read), and extra prefixes saved at setup (e.g. `/casino/v1/events`). `DELETE` is always refused |
| **Change log + undo** | every agent create/update is logged with the post as it was before (fields, meta, terms). Updates to one post within 10 min count as one change |
| **Draft previews** | signed link, no login, valid 72 h, `noindex`, not cached |

## REST routes (`/wp-json/mau-agent/v1/`, agent only)

| Route | |
|---|---|
| `GET ping` | `{ok, version, site, user, types}` |
| `GET schema` | content types in REST: `type, rest_base, namespace, label, fields, taxonomies, meta, acf` |
| `POST preview/{id}` | `{url, expires}` for an unpublished post |
| `POST trash/{id}` | move one item of an enabled type to the trash (1.1.0; the runtime calls it only after the client confirmed); logged with a snapshot |
| `POST undo/{id}` | last change was a trash → untrash and restore status, slug, meta, terms; the agent created it → trash (attachments deleted); else restore the last change. 404 when there is nothing to undo (only agent changes ≤ 30 days) |
| `POST discard/{id}` | trash an unpublished draft / delete an upload the agent made (cancelled change) |
| `GET log?month=YYYY-MM` | agent changes in that month |

## WP-CLI

```bash
wp mau-agent status [--format=json]
wp mau-agent schema [--format=json]
wp mau-agent setup --ip=<ip>[,<ip>] [--types=posts,viatura] [--routes=/casino/v1/events] [--porcelain]   # prints a new app password
wp mau-agent revoke          # delete the agent's app passwords, take the role away, clear IPs
wp mau-agent log [--month=YYYY-MM]
```
`setup` is idempotent and rotates the password each run. It refuses to touch a `mau-agent` user that has
any other role.

## Install (Bedrock)

```bash
composer require nitida/wp-mu-agent
```
`composer/installers` puts it in `web/app/mu-plugins/wp-mu-agent/`; keep that directory git-ignored like the
other `wp-mu-*` packages. Then `wp mau-agent setup …` (the onboarding script in `sys/mau-agent/` does it).

## Tested

1.0.0 on the `wp7` sandbox (WordPress 7.1, PHP 8.3), 2026-11-10: IP lock (401 from a wrong IP), ping,
blocked routes (users, settings, plugins, own profile, batch, products not enabled, DELETE), kses on content,
signed preview (valid → 200 + noindex, tampered → 404), undo of two updates as one change, second undo → 404,
undo of an agent-created draft → trash, discard refused on a human page, wp-admin login refused, admins'
app-password setting unchanged. Then from the runtime (`mau-agent` repo): create → preview → publish → undo,
discard of a draft, media upload + discard.
