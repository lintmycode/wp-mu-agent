<?php
/**
 * `wp mau-agent …` — what ~/projects/sys/mau-agent/onboard-client.sh calls on jaws.
 * Output formats are part of that contract: change both together.
 */

namespace Nitida\Agent;

defined('ABSPATH') || exit;

use WP_CLI;
use WP_Application_Passwords;

class Cli
{
    /**
     * State of the agent on this site.
     *
     * [--format=<format>]
     * : json or table. Default: table.
     */
    public function status(array $args, array $assoc): void
    {
        $user   = get_user_by('login', LOGIN);
        $hasApp = false;
        if ($user) {
            foreach (WP_Application_Passwords::get_user_application_passwords($user->ID) as $item) {
                $hasApp = $hasApp || $item['name'] === APP_NAME;
            }
        }
        $data = [
            'version'      => VERSION,
            'log_table'    => (bool) $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare('SHOW TABLES LIKE %s', table())),
            'role'         => (bool) get_role(ROLE),
            'user'         => $user ? $user->user_login : null,
            'user_is_agent'=> $user ? is_agent($user) : false,
            'app_password' => $hasApp,
            'allowed_ips'  => array_values((array) get_option(OPT_IPS, [])),
            'types'        => enabled_types(),
            'extra_routes' => array_values((array) get_option(OPT_ROUTES, [])),
        ];
        $this->out($data, $assoc);
    }

    /**
     * Content types this site exposes to the REST API (fields, taxonomies, meta, ACF).
     *
     * [--format=<format>]
     * : json or table. Default: table.
     */
    public function schema(array $args, array $assoc): void
    {
        $rows = schema();
        if (($assoc['format'] ?? 'table') === 'json') {
            WP_CLI::line(wp_json_encode($rows));
            return;
        }
        WP_CLI\Utils\format_items('table', array_map(fn($r) => [
            'rest_base'  => $r['rest_base'],
            'label'      => $r['label'],
            'fields'     => implode(',', $r['fields']),
            'taxonomies' => implode(',', $r['taxonomies']),
            'meta'       => implode(',', array_merge($r['meta'], array_map(fn($a) => "acf:$a", $r['acf']))),
        ], $rows), ['rest_base', 'label', 'fields', 'taxonomies', 'meta']);
    }

    /**
     * Create or refresh the agent: role, user, allowed IPs, a new application password.
     * Idempotent. Revokes the previous 'mau-agent-vps' password, so the VPS config must be updated
     * with the printed one (onboard-client.sh does that).
     *
     * --ip=<ips>
     * : Comma list of IPs the agent may sign in from. '*' only outside production.
     *
     * [--types=<types>]
     * : Comma list of post types or REST bases the agent may edit. Default: post.
     *
     * [--routes=<prefixes>]
     * : Comma list of extra REST route prefixes the agent may call, e.g. /casino/v1/events.
     *
     * [--email=<email>]
     * : Email for the agent user. Default: ola@maumarketing.pt.
     *
     * [--porcelain]
     * : Print only the new application password.
     */
    public function setup(array $args, array $assoc): void
    {
        $ips = array_values(array_filter(array_map('trim', explode(',', (string) ($assoc['ip'] ?? '')))));
        if (!$ips) {
            WP_CLI::error('--ip is required.');
        }
        foreach ($ips as $ip) {
            if ($ip === '*' ? wp_get_environment_type() === 'production' : !filter_var($ip, FILTER_VALIDATE_IP)) {
                WP_CLI::error("Not allowed as an agent IP here: $ip");
            }
        }

        $types = [];
        foreach (array_filter(array_map('trim', explode(',', (string) ($assoc['types'] ?? 'post')))) as $t) {
            $name = $this->typeName($t);
            if (!$name) {
                WP_CLI::error("Unknown or not-in-REST content type: $t (see `wp mau-agent schema`).");
            }
            $types[] = $name;
        }
        $routes = array_values(array_filter(array_map('trim', explode(',', (string) ($assoc['routes'] ?? '')))));

        install_table();
        update_option(OPT_IPS, $ips, false);
        update_option(OPT_TYPES, array_values(array_unique($types)), false);
        update_option(OPT_ROUTES, $routes, false);
        ensure_role($types);

        $user = get_user_by('login', LOGIN);
        if ($user && array_diff($user->roles, [ROLE])) {
            // Never demote a real account that happens to have this login.
            WP_CLI::error('User ' . LOGIN . ' exists with other roles (' . implode(',', $user->roles) . '). Fix it by hand.');
        }
        if (!$user) {
            $id = wp_insert_user([
                'user_login'   => LOGIN,
                'user_email'   => $assoc['email'] ?? 'ola@maumarketing.pt',
                'user_pass'    => wp_generate_password(64, true, true),   // never stored or shown; login is refused anyway
                'display_name' => 'Assistente MAU',
                'role'         => ROLE,
            ]);
            if (is_wp_error($id)) {
                WP_CLI::error($id->get_error_message());
            }
            $user = get_user_by('id', $id);
        } else {
            $user->set_role(ROLE);
        }

        foreach (WP_Application_Passwords::get_user_application_passwords($user->ID) as $item) {
            if ($item['name'] === APP_NAME) {
                WP_Application_Passwords::delete_application_password($user->ID, $item['uuid']);
            }
        }
        $created = WP_Application_Passwords::create_new_application_password($user->ID, ['name' => APP_NAME]);
        if (is_wp_error($created)) {
            WP_CLI::error($created->get_error_message());
        }
        [$password] = $created;

        if (isset($assoc['porcelain'])) {
            WP_CLI::line($password);
            return;
        }
        WP_CLI::success(sprintf('Agent ready: user %s, types %s, IPs %s.', LOGIN, implode(',', $types), implode(',', $ips)));
        WP_CLI::line("Application password (shown once): $password");
    }

    /**
     * Switch the agent off on this site: delete its application passwords, take its role away,
     * forget the allowed IPs. The change log stays.
     */
    public function revoke(array $args, array $assoc): void
    {
        $user = get_user_by('login', LOGIN);
        if ($user) {
            WP_Application_Passwords::delete_all_application_passwords($user->ID);
            if (is_agent($user)) {
                $user->set_role('');
            }
        }
        update_option(OPT_IPS, [], false);
        WP_CLI::success('Agent revoked.');
    }

    /**
     * Changes the agent made in a month.
     *
     * [--month=<YYYY-MM>]
     * : Default: this month.
     *
     * [--format=<format>]
     * : json or table. Default: table.
     */
    public function log(array $args, array $assoc): void
    {
        install_table();
        $rows = log_rows($assoc['month'] ?? gmdate('Y-m'));
        if (($assoc['format'] ?? 'table') === 'json') {
            WP_CLI::line(wp_json_encode($rows));
            return;
        }
        WP_CLI\Utils\format_items('table', $rows, ['id', 'post_id', 'action', 'created_at', 'undone_at']);
    }

    /** Accept a post type name or its REST base ("viaturas" → "viatura"). */
    private function typeName(string $t): ?string
    {
        foreach (get_post_types(['show_in_rest' => true], 'objects') as $obj) {
            if (!in_array($obj->name, SKIP_TYPES, true) && ($obj->name === $t || ($obj->rest_base ?: $obj->name) === $t)) {
                return $obj->name;
            }
        }
        return null;
    }

    private function out(array $data, array $assoc): void
    {
        if (($assoc['format'] ?? 'table') === 'json') {
            WP_CLI::line(wp_json_encode($data));
            return;
        }
        foreach ($data as $k => $v) {
            WP_CLI::line(str_pad($k, 14) . (is_array($v) ? implode(', ', $v) : var_export($v, true)));
        }
    }
}

WP_CLI::add_command('mau-agent', Cli::class);
