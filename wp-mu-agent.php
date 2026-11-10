<?php
/**
 * Plugin Name: nitida — MAU site agent
 * Description: Lets the MAU site agent (a Telegram bot on its own VPS) edit this site through the REST API and nothing more: a restricted role, application passwords for that role only and only from the agent's IP, signed draft previews, and an undo log.
 * Version: 1.0.1
 * Author: nitida
 * License: MIT
 *
 * Installed as a Composer package (type wordpress-muplugin) into web/app/mu-plugins/, loaded by
 * roots/bedrock-autoloader. Spec: ~/projects/sys/specs/site-agent-build.md §1. Set up per site with
 * `wp mau-agent setup` (src/cli.php); the onboarding script in ~/projects/sys/mau-agent/ drives it.
 *
 * Who can do what:
 *   - one user, `mau-agent`, with role `mau_agent`: edit and publish posts, pages and the content types
 *     enabled at setup, upload media. No delete, no unfiltered_html (kses filters what it writes),
 *     no new terms, no settings, users, plugins or themes.
 *   - it authenticates only with an application password, only from the IPs saved at setup, and only
 *     on an allowlist of REST routes. It cannot log in to wp-admin.
 *   - every other user keeps exactly the application-password setting the site had before
 *     (wp-mu-security turns them off; a site with WP_MU_SECURITY_ALLOW_APP_PASSWORDS=true keeps them).
 */

namespace Nitida\Agent;

defined('ABSPATH') || exit;

const VERSION     = '1.0.1';
const ROLE        = 'mau_agent';
const LOGIN       = 'mau-agent';
const APP_NAME    = 'mau-agent-vps';
const OPT_IPS     = 'mau_agent_allowed_ips';   // list of IPs; '*' accepted outside production only
const OPT_TYPES   = 'mau_agent_types';         // post type names the agent may edit
const OPT_ROUTES  = 'mau_agent_extra_routes';  // extra REST route prefixes, e.g. /casino/v1/events
const OPT_DB      = 'mau_agent_db';
const DB_VERSION  = '1';
const PREVIEW_TTL = 259200;                    // 72 h
const UNDO_DAYS   = 30;
const COALESCE    = 600;                       // updates to one post within 10 min are one change for undo

define('MAU_AGENT_VERSION', VERSION);

// Post types that are never content the agent edits, even when they are in REST.
const SKIP_TYPES = ['attachment', 'revision', 'nav_menu_item', 'wp_block', 'wp_template', 'wp_template_part',
    'wp_navigation', 'wp_global_styles', 'wp_font_family', 'wp_font_face', 'customize_changeset'];
// Bookkeeping meta that undo must neither restore nor delete.
const SKIP_META = ['_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_encloseme', '_pingme'];

function is_agent(?\WP_User $user = null): bool
{
    $user ??= wp_get_current_user();
    return $user instanceof \WP_User && $user->exists() && in_array(ROLE, (array) $user->roles, true);
}

/** @return string[] post type names enabled at setup that still exist */
function enabled_types(): array
{
    return array_values(array_filter((array) get_option(OPT_TYPES, []), 'post_type_exists'));
}

// ---------------------------------------------------------------------------------
// 1. Role. Rebuilt by `wp mau-agent setup`; capabilities follow the enabled types.
// ---------------------------------------------------------------------------------
/** @param string[] $types */
function role_caps(array $types): array
{
    $caps = ['read', 'upload_files'];
    foreach (array_unique(array_merge(['post', 'page'], $types)) as $type) {
        $obj = get_post_type_object($type);
        if (!$obj) {
            continue;
        }
        // The four "write" capabilities of the type, whatever capability_type it maps to. Never delete_*.
        foreach (['edit_posts', 'edit_others_posts', 'edit_published_posts', 'publish_posts'] as $cap) {
            $caps[] = $obj->cap->$cap;
        }
    }
    return array_values(array_unique($caps));
}

/** @param string[] $types */
function ensure_role(array $types): void
{
    $wanted = array_fill_keys(role_caps($types), true);
    $role   = get_role(ROLE) ?: add_role(ROLE, 'MAU Agent', $wanted);
    foreach (array_keys($role->capabilities) as $cap) {
        if (!isset($wanted[$cap])) {
            $role->remove_cap($cap);
        }
    }
    foreach (array_keys($wanted) as $cap) {
        $role->add_cap($cap);
    }
}

// ---------------------------------------------------------------------------------
// 2. Application passwords: on for the agent role only, everyone else unchanged.
//    The site's own answer (false under wp-mu-security) is remembered at priority 100,
//    then the global switch is turned on so the per-user filter can decide.
// ---------------------------------------------------------------------------------
function site_default(?bool $set = null): bool
{
    static $value = true;
    if ($set !== null) {
        $value = $set;
    }
    return $value;
}

add_filter('wp_is_application_passwords_available', function ($available): bool {
    site_default((bool) $available);
    return true;
}, 100);

add_filter('wp_is_application_passwords_available_for_user', function ($available, $user): bool {
    if ($user instanceof \WP_User && is_agent($user)) {
        return true;
    }
    return site_default() && (bool) $available;
}, 100, 2);

// IP lock: the agent's password works only from the VPS. On jaws nginx sets REMOTE_ADDR from
// CF-Connecting-IP (nginx-cloudflare-refresh), so this is the real client IP behind Cloudflare too.
function ip_allowed(): bool
{
    $ips = (array) get_option(OPT_IPS, []);
    if (in_array('*', $ips, true) && wp_get_environment_type() !== 'production') {
        return true;
    }
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', $ips, true);   // empty list = nobody (fail closed)
}

add_action('wp_authenticate_application_password_errors', function (\WP_Error $error, \WP_User $user): void {
    if (is_agent($user) && !ip_allowed()) {
        $error->add('mau_agent_ip', 'The agent cannot sign in from this address.');
    }
}, 10, 2);

// No wp-admin: the login form refuses the agent whatever password is typed.
add_filter('wp_authenticate_user', function ($user) {
    if ($user instanceof \WP_User && is_agent($user)) {
        return new \WP_Error('mau_agent_login', 'This account cannot log in.');
    }
    return $user;
}, 100);

// ---------------------------------------------------------------------------------
// 3. REST allowlist for the agent. Anything not listed answers 403, including DELETE,
//    /wp/v2/users (it could otherwise edit its own profile and passwords), settings,
//    plugins, themes and batch.
// ---------------------------------------------------------------------------------
function route_allowed(string $method, string $route): bool
{
    if ($method === 'DELETE') {
        return false;
    }
    $read  = in_array($method, ['GET', 'HEAD'], true);
    $write = in_array($method, ['POST', 'PUT', 'PATCH'], true);
    $route = untrailingslashit($route) ?: '/';

    if (str_starts_with($route, '/mau-agent/v1/')) {
        return true;
    }
    if ($read && preg_match('#^(/|/wp/v2/users/me|/wp/v2/(types|taxonomies)(/[\w-]+)?)$#', $route)) {
        return true;
    }
    if (($read || $write) && preg_match('#^/wp/v2/media(/\d+)?$#', $route)) {
        return true;
    }
    foreach (enabled_types() as $type) {
        $obj  = get_post_type_object($type);
        $base = '/' . ($obj->rest_namespace ?: 'wp/v2') . '/' . ($obj->rest_base ?: $type);
        if (($read || $write) && preg_match('#^' . preg_quote($base, '#') . '(/\d+)?$#', $route)) {
            return true;
        }
        // Terms: read only. The agent picks existing categories, it does not create them.
        foreach (get_object_taxonomies($type, 'objects') as $tax) {
            if ($read && $tax->show_in_rest
                && preg_match('#^/' . preg_quote(($tax->rest_namespace ?: 'wp/v2') . '/' . ($tax->rest_base ?: $tax->name), '#') . '(/\d+)?$#', $route)) {
                return true;
            }
        }
    }
    foreach ((array) get_option(OPT_ROUTES, []) as $prefix) {
        if ($prefix !== '' && str_starts_with($route, untrailingslashit($prefix))) {
            return true;
        }
    }
    return false;
}

// The allowlist is enforced twice, both at PHP_INT_MAX so no other plugin has the last word:
// - rest_pre_dispatch: ACF registers ACF_Rest_API::initialize there at priority 10 and returns null,
//   which wiped out our 403 when we also ran at 10 (1.0.0: the allowlist did nothing on ACF sites).
//   A non-null result from another plugin (e.g. a cached response) is overridden too.
// - rest_request_before_callbacks: runs right before the route callback, also for each request inside
//   /batch/v1 (batch sub-requests never go through rest_pre_dispatch).
function deny(\WP_REST_Request $request): ?\WP_Error
{
    if (!is_agent() || route_allowed($request->get_method(), $request->get_route())) {
        return null;
    }
    return new \WP_Error('mau_agent_route', 'Not available to the agent.', ['status' => 403]);
}

add_filter('rest_pre_dispatch', fn($result, $server, \WP_REST_Request $request) => deny($request) ?? $result, PHP_INT_MAX, 3);
add_filter('rest_request_before_callbacks', fn($response, $handler, \WP_REST_Request $request) => deny($request) ?? $response, PHP_INT_MAX, 3);

// ---------------------------------------------------------------------------------
// 4. Change log + undo. Every change the agent makes is recorded with the post as it
//    was before, so `POST /mau-agent/v1/undo/{post}` can put it back. Only posts the
//    agent itself changed can be undone; nothing a human wrote is reachable.
// ---------------------------------------------------------------------------------
function table(): string
{
    global $wpdb;
    return $wpdb->prefix . 'mau_agent_log';
}

function install_table(): void
{
    if (get_option(OPT_DB) === DB_VERSION) {
        return;
    }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    // `snapshot`, not `before`: BEFORE is a reserved word in MySQL.
    dbDelta('CREATE TABLE ' . table() . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  post_id bigint(20) unsigned NOT NULL,
  action varchar(20) NOT NULL,
  snapshot longtext NULL,
  created_at datetime NOT NULL,
  undone_at datetime NULL,
  PRIMARY KEY  (id),
  KEY post_id (post_id),
  KEY created_at (created_at)
) " . $wpdb->get_charset_collate() . ';');
    update_option(OPT_DB, DB_VERSION, false);
}
add_action('init', __NAMESPACE__ . '\install_table');

/** Set while undo writes, so the restore itself is not logged as a new change. */
function restoring(?bool $set = null): bool
{
    static $value = false;
    if ($set !== null) {
        $value = $set;
    }
    return $value;
}

function snapshot(int $post_id): array
{
    $post = get_post($post_id);
    $meta = [];
    foreach (get_post_meta($post_id) as $key => $values) {
        if (!in_array($key, SKIP_META, true)) {
            $meta[$key] = array_map('maybe_unserialize', $values);
        }
    }
    $terms = [];
    foreach (get_object_taxonomies($post->post_type) as $tax) {
        $ids = wp_get_object_terms($post_id, $tax, ['fields' => 'ids']);
        $terms[$tax] = is_wp_error($ids) ? [] : $ids;
    }
    return [
        'post'  => [
            'post_title'   => $post->post_title,
            'post_content' => $post->post_content,
            'post_excerpt' => $post->post_excerpt,
            'post_status'  => $post->post_status,
            'post_name'    => $post->post_name,
            'post_date'    => $post->post_date,
            'menu_order'   => $post->menu_order,
        ],
        'meta'  => $meta,
        'terms' => $terms,
    ];
}

function log_change(int $post_id, string $action, ?array $snapshot): void
{
    global $wpdb;
    $row = [
        'post_id'    => $post_id,
        'action'     => $action,
        'snapshot'   => $snapshot === null ? null : wp_json_encode($snapshot),
        'created_at' => current_time('mysql', true),
    ];
    if ($wpdb->insert(table(), $row) === false) {
        // The table can be gone while the version option survives (dropped by hand, partial DB restore).
        // Without the log, undo silently stops working: recreate it and write the row again.
        delete_option(OPT_DB);
        install_table();
        $wpdb->insert(table(), $row);
    }
}

add_action('wp_insert_post', function (int $post_id, \WP_Post $post, bool $update): void {
    if (!$update && !restoring() && is_agent() && !in_array($post->post_type, ['revision', 'customize_changeset'], true)) {
        log_change($post_id, 'create', null);
    }
}, 10, 3);

// Uploads never reach the wp_insert_post action (wp_insert_post returns early for attachments): log them here.
add_action('add_attachment', function (int $post_id): void {
    if (!restoring() && is_agent()) {
        log_change($post_id, 'create', null);
    }
});

add_action('pre_post_update', function (int $post_id): void {
    if (restoring() || !is_agent()) {
        return;
    }
    $post = get_post($post_id);
    if (!$post || in_array($post->post_type, ['revision', 'customize_changeset'], true)) {
        return;
    }
    global $wpdb;
    // One message from the client can mean several REST calls on the same post: keep the first
    // "before" so undo returns to the state before the whole change, not halfway.
    $recent = $wpdb->get_var($wpdb->prepare(
        'SELECT id FROM ' . table() . ' WHERE post_id = %d AND undone_at IS NULL AND created_at > %s LIMIT 1',
        $post_id,
        gmdate('Y-m-d H:i:s', time() - COALESCE)
    ));
    if (!$recent) {
        log_change($post_id, 'update', snapshot($post_id));
    }
});

function restore(int $post_id, array $snap): void
{
    restoring(true);
    try {
        wp_update_post(wp_slash(['ID' => $post_id] + $snap['post']));
        foreach (array_keys(get_post_meta($post_id)) as $key) {
            if (!in_array($key, SKIP_META, true) && !array_key_exists($key, $snap['meta'])) {
                delete_post_meta($post_id, $key);   // meta the agent added
            }
        }
        foreach ($snap['meta'] as $key => $values) {
            delete_post_meta($post_id, $key);
            foreach ($values as $value) {
                add_post_meta($post_id, $key, wp_slash($value));
            }
        }
        foreach ($snap['terms'] as $tax => $ids) {
            wp_set_object_terms($post_id, array_map('intval', $ids), $tax);
        }
    } finally {
        restoring(false);
    }
}

/** @return array{action:string, post:int}|\WP_Error */
function undo(int $post_id)
{
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT * FROM ' . table() . ' WHERE post_id = %d AND undone_at IS NULL AND created_at > %s ORDER BY id DESC',
        $post_id,
        gmdate('Y-m-d H:i:s', time() - UNDO_DAYS * DAY_IN_SECONDS)
    ));
    if (!$rows) {
        return new \WP_Error('mau_agent_nothing', 'No agent change to undo on this post.', ['status' => 404]);
    }
    $now = current_time('mysql', true);
    // The agent created this post: undoing removes it (to the trash; a human can still restore it).
    if (array_filter($rows, fn($r) => $r->action === 'create')) {
        restoring(true);
        try {
            get_post_type($post_id) === 'attachment' ? wp_delete_attachment($post_id, false) : wp_trash_post($post_id);
        } finally {
            restoring(false);
        }
        $wpdb->query($wpdb->prepare('UPDATE ' . table() . ' SET undone_at = %s WHERE post_id = %d AND undone_at IS NULL', $now, $post_id));
        return ['action' => 'trashed', 'post' => $post_id];
    }
    $row = $rows[0];
    restore($post_id, json_decode($row->snapshot, true));
    $wpdb->update(table(), ['undone_at' => $now], ['id' => $row->id]);
    return ['action' => 'restored', 'post' => $post_id];
}

// ---------------------------------------------------------------------------------
// 5. Draft previews. A signed link the client can open without logging in, valid 72 h.
// ---------------------------------------------------------------------------------
function preview_token(int $post_id, int $expires): string
{
    return $expires . '.' . hash_hmac('sha256', $post_id . '|' . $expires, wp_salt('auth'));
}

function preview_valid(int $post_id, string $token): bool
{
    $expires = (int) strtok($token, '.');
    return $post_id > 0 && $expires >= time() && hash_equals(preview_token($post_id, $expires), $token);
}

function preview_request(): int
{
    $id = (int) ($_GET['p'] ?? 0);
    return isset($_GET['mau_preview']) && preview_valid($id, (string) $_GET['mau_preview']) ? $id : 0;
}

add_action('pre_get_posts', function (\WP_Query $query): void {
    if (!is_admin() && $query->is_main_query() && preview_request()) {
        // WP_Query hides non-public posts from visitors unless their status is asked for explicitly.
        $query->set('post_status', ['publish', 'draft', 'pending', 'future', 'private']);
    }
});

add_action('template_redirect', function (): void {
    if (preview_request()) {
        nocache_headers();
        header('X-Robots-Tag: noindex, nofollow');
        defined('DONOTCACHEPAGE') || define('DONOTCACHEPAGE', true);
    }
});

// ---------------------------------------------------------------------------------
// 6. Schema: what the site exposes to REST, for the onboarding script and the runtime.
// ---------------------------------------------------------------------------------
function schema(): array
{
    $out = [];
    foreach (get_post_types(['show_in_rest' => true], 'objects') as $type) {
        if (in_array($type->name, SKIP_TYPES, true)) {
            continue;
        }
        $fields = [];
        foreach (['title' => 'title', 'editor' => 'content', 'excerpt' => 'excerpt', 'thumbnail' => 'featured_media'] as $support => $field) {
            if (post_type_supports($type->name, $support)) {
                $fields[] = $field;
            }
        }
        $meta = [];
        foreach (array_merge(get_registered_meta_keys('post', ''), get_registered_meta_keys('post', $type->name)) as $key => $args) {
            if (!empty($args['show_in_rest']) && !str_starts_with($key, '_')) {
                $meta[] = $key;
            }
        }
        $acf = [];
        if (function_exists('acf_get_field_groups')) {
            foreach (acf_get_field_groups(['post_type' => $type->name]) as $group) {
                if (!empty($group['show_in_rest'])) {
                    foreach (acf_get_fields($group) ?: [] as $field) {
                        $acf[] = $field['name'];
                    }
                }
            }
        }
        $taxonomies = [];
        foreach (get_object_taxonomies($type->name, 'objects') as $tax) {
            if ($tax->show_in_rest) {
                $taxonomies[] = $tax->rest_base ?: $tax->name;
            }
        }
        $out[] = [
            'type'       => $type->name,
            'rest_base'  => $type->rest_base ?: $type->name,
            'namespace'  => $type->rest_namespace ?: 'wp/v2',
            'label'      => $type->labels->name,
            'fields'     => $fields,
            'taxonomies' => $taxonomies,
            'meta'       => array_values(array_unique($meta)),
            'acf'        => array_values(array_unique($acf)),
        ];
    }
    return $out;
}

// ---------------------------------------------------------------------------------
// 7. REST routes for the runtime (agent role only).
// ---------------------------------------------------------------------------------
add_action('rest_api_init', function (): void {
    $agent = fn() => is_agent();
    $id    = ['id' => ['validate_callback' => fn($v) => is_numeric($v) && (int) $v > 0]];

    register_rest_route('mau-agent/v1', '/ping', [
        'methods'             => 'GET',
        'permission_callback' => $agent,
        'callback'            => fn() => [
            'ok'      => true,
            'version' => VERSION,
            'site'    => get_bloginfo('name'),
            'user'    => wp_get_current_user()->user_login,
            'types'   => enabled_types(),
        ],
    ]);

    register_rest_route('mau-agent/v1', '/schema', [
        'methods'             => 'GET',
        'permission_callback' => $agent,
        'callback'            => fn() => schema(),
    ]);

    register_rest_route('mau-agent/v1', '/preview/(?P<id>\d+)', [
        'methods'             => 'POST',
        'permission_callback' => fn(\WP_REST_Request $r) => is_agent() && current_user_can('edit_post', (int) $r['id']),
        'args'                => $id,
        'callback'            => function (\WP_REST_Request $r) {
            $post = get_post((int) $r['id']);
            if (!$post || $post->post_status === 'publish') {
                return new \WP_Error('mau_agent_preview', 'Only unpublished posts have a preview.', ['status' => 400]);
            }
            $expires = time() + PREVIEW_TTL;
            return [
                'url'     => add_query_arg(['p' => $post->ID, 'post_type' => $post->post_type, 'mau_preview' => preview_token($post->ID, $expires)], home_url('/')),
                'expires' => gmdate('c', $expires),
            ];
        },
    ]);

    register_rest_route('mau-agent/v1', '/undo/(?P<id>\d+)', [
        'methods'             => 'POST',
        'permission_callback' => $agent,
        'args'                => $id,
        'callback'            => fn(\WP_REST_Request $r) => undo((int) $r['id']),
    ]);

    // Cancel: throw away a draft or an upload the agent made for a change the client cancelled.
    // The role has no delete capability, so this is the only way it removes anything.
    register_rest_route('mau-agent/v1', '/discard/(?P<id>\d+)', [
        'methods'             => 'POST',
        'permission_callback' => $agent,
        'args'                => $id,
        'callback'            => function (\WP_REST_Request $r) {
            $post = get_post((int) $r['id']);
            $mine = $post && (int) $post->post_author === get_current_user_id();
            $open = $post && ($post->post_type === 'attachment' || in_array($post->post_status, ['draft', 'auto-draft', 'pending'], true));
            if (!$mine || !$open) {
                return new \WP_Error('mau_agent_discard', 'Only unpublished drafts and uploads made by the agent can be discarded.', ['status' => 403]);
            }
            $post->post_type === 'attachment' ? wp_delete_attachment($post->ID, true) : wp_trash_post($post->ID);
            return ['ok' => true, 'post' => $post->ID];
        },
    ]);

    register_rest_route('mau-agent/v1', '/log', [
        'methods'             => 'GET',
        'permission_callback' => $agent,
        'args'                => ['month' => ['validate_callback' => fn($v) => (bool) preg_match('/^\d{4}-\d{2}$/', $v)]],
        'callback'            => fn(\WP_REST_Request $r) => log_rows($r['month'] ?: gmdate('Y-m')),
    ]);
});

function log_rows(string $month): array
{
    global $wpdb;
    return $wpdb->get_results($wpdb->prepare(
        'SELECT id, post_id, action, created_at, undone_at FROM ' . table() . ' WHERE created_at LIKE %s ORDER BY id',
        $wpdb->esc_like($month) . '%'
    ), ARRAY_A);
}

if (defined('WP_CLI') && WP_CLI) {
    require __DIR__ . '/src/cli.php';
}
