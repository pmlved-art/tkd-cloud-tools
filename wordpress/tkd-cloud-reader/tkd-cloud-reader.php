<?php
/**
 * Plugin Name: TKD Cloud Reader (candidate)
 * Description: Minimal Application Password protected order reader. Server schema review required.
 * Version: 0.4.2
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) { exit; }

final class TKD_Cloud_Reader {
    const VERSION = '0.4.2';
    const SCHEMA = 'tkd-order-minimal-v1-candidate';
    const CAP = 'tkd_cloud_read_orders';
    private static $application_user_id = 0;

    public static function boot() {
        add_action('application_password_did_authenticate', array(__CLASS__, 'authenticated'), 10, 2);
        add_action('rest_api_init', array(__CLASS__, 'routes'));
        add_filter('rest_post_dispatch', array(__CLASS__, 'no_store'), PHP_INT_MAX, 3);
    }

    public static function activate() {
        TKD_Cloud_Operator_Role::ensure_role();
        $caps = array('read' => true, self::CAP => true);
        $role = get_role('tkd_cloud_reader');
        if ($role) {
            // Never reuse an existing role with additional granted privileges.
            foreach ($role->capabilities as $cap => $granted) {
                if ($granted && !isset($caps[$cap])) {
                    wp_die('Existing tkd_cloud_reader role has unexpected privileges. Review it before activation.');
                }
            }
            if (empty($role->capabilities['read']) || empty($role->capabilities[self::CAP])) {
                wp_die('Existing tkd_cloud_reader role differs from the required minimal role.');
            }
        } else {
            add_role('tkd_cloud_reader', 'TKD Cloud Reader', $caps);
        }
    }

    public static function authenticated($user, $item) {
        self::$application_user_id = (int) $user->ID;
    }

    public static function routes() {
        TKD_Cloud_Read_Extensions::routes();
        TKD_Cloud_Operations::routes();
        foreach (array('/health' => 'health', '/orders/(?P<number>[A-Za-z0-9-]{1,64})' => 'order') as $route => $callback) {
            register_rest_route('tkd-cloud/v1', $route, array(
                'methods' => 'GET',
                'callback' => array(__CLASS__, $callback),
                'permission_callback' => array(__CLASS__, 'permission'),
            ));
        }
    }

    public static function application_permission($request) {
        if (!is_ssl()) {
            return new WP_Error('tkd_https_required', 'HTTPS required.', array('status' => 403));
        }
        if (self::$application_user_id < 1 || self::$application_user_id !== (int) get_current_user_id()) {
            $data = array('status' => 401);
            if ($request->get_method() === 'GET' && $request->get_route() === '/tkd-cloud/v1/health') {
                $data['auth_diagnostics'] = self::auth_diagnostics();
            }
            return new WP_Error('tkd_application_password_required', 'Application Password authentication required.', $data);
        }
        $user = wp_get_current_user();
        // Explicit grant also prevents the multisite super-admin capability bypass.
        if (empty($user->allcaps[self::CAP]) || !current_user_can(self::CAP)) {
            return new WP_Error('tkd_forbidden', 'Order read permission required.', array('status' => 403));
        }
        return true;
    }

    public static function permission($request) {
        $auth = self::application_permission($request);
        if ($auth !== true) { return $auth; }
        if ($request->get_method() !== 'GET') {
            return new WP_Error('tkd_method_not_allowed', 'GET required.', array('status' => 405));
        }
        return true;
    }

    private static function auth_diagnostics() {
        // Request-local observations only. Never decode Basic or return values.
        // Core: rest_application_password_collect_status(), wp-includes/rest-api.php.
        global $wp_rest_application_password_status, $wp_rest_application_password_uuid;
        $http = isset($_SERVER['HTTP_AUTHORIZATION']) && is_string($_SERVER['HTTP_AUTHORIZATION']) && $_SERVER['HTTP_AUTHORIZATION'] !== '';
        $redirect = isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) && is_string($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) && $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] !== '';
        $basic = ($http && preg_match('/\ABasic[ \t]+/i', $_SERVER['HTTP_AUTHORIZATION']) === 1)
            || ($redirect && preg_match('/\ABasic[ \t]+/i', $_SERVER['REDIRECT_HTTP_AUTHORIZATION']) === 1);
        $native_user = $wp_rest_application_password_status instanceof WP_User;
        return array(
            'http_authorization_present' => $http,
            'redirect_http_authorization_present' => $redirect,
            'authorization_basic_scheme' => $basic,
            'php_auth_user_present' => isset($_SERVER['PHP_AUTH_USER']) && is_string($_SERVER['PHP_AUTH_USER']) && $_SERVER['PHP_AUTH_USER'] !== '',
            'php_auth_pw_present' => isset($_SERVER['PHP_AUTH_PW']) && is_string($_SERVER['PHP_AUTH_PW']) && $_SERVER['PHP_AUTH_PW'] !== '',
            'native_app_password_status_is_user' => $native_user,
            'native_app_password_user_matches_current' => $native_user && (int) $wp_rest_application_password_status->ID > 0
                && (int) $wp_rest_application_password_status->ID === (int) get_current_user_id(),
            'native_app_password_uuid_present' => is_string($wp_rest_application_password_uuid) && $wp_rest_application_password_uuid !== '',
        );
    }

    private static function schema_error() {
        return new WP_Error('tkd_schema_unverified', 'Expected order schema unavailable or inconsistent.', array('status' => 503));
    }

    public static function health($request) {
        if (!post_type_exists('tkd_order')) { return self::schema_error(); }
        return new WP_REST_Response(array('post_type_exists' => true, 'bridge_version' => self::VERSION, 'schema_version' => self::SCHEMA));
    }

    public static function order($request) {
        // Use only the path parameter; a query/body parameter cannot override it.
        $params = $request->get_url_params();
        $number = isset($params['number']) ? $params['number'] : null;
        return self::read_number($number);
    }

    public static function read_number($number) {
        if (!post_type_exists('tkd_order')) { return self::schema_error(); }
        if (!is_string($number) || !preg_match('/\ATKD-[A-Za-z0-9-]{1,60}\z/D', $number)) {
            return new WP_Error('tkd_invalid_number', 'Full TKD order number required.', array('status' => 400));
        }
        $statuses = array_values(array_diff(get_post_stati(), array('trash', 'auto-draft')));
        if (!$statuses) { return self::schema_error(); }
        $ids = get_posts(array(
            'post_type' => 'tkd_order', 'post_status' => $statuses,
            'posts_per_page' => 2, 'fields' => 'ids', 'no_found_rows' => true,
            'suppress_filters' => true, 'orderby' => 'ID', 'order' => 'ASC',
            'meta_query' => array(array('key' => '_tkd_order_number', 'value' => $number, 'compare' => '=', 'type' => 'BINARY')),
        ));
        if (count($ids) > 1) {
            return new WP_Error('tkd_duplicate_number', 'Order number is not unique.', array('status' => 409));
        }
        if (!$ids) { return new WP_Error('tkd_not_found', 'Order not found.', array('status' => 404)); }
        $id = (int) $ids[0];
        $post = get_post($id);
        if (!$post || $post->post_type !== 'tkd_order') { return self::schema_error(); }
        if (in_array($post->post_status, array('trash', 'auto-draft'), true)) {
            return new WP_Error('tkd_not_found', 'Order not found.', array('status' => 404));
        }
        $numbers = get_post_meta($id, '_tkd_order_number', false);
        $statuses = get_post_meta($id, '_tkd_status', false);
        if (count($numbers) !== 1 || $numbers[0] !== $number || count($statuses) !== 1
            || !is_string($statuses[0]) || $statuses[0] === '' || strlen($statuses[0]) > 64
            || !preg_match('/\A[A-Za-z0-9_-]+\z/D', $statuses[0])) {
            return self::schema_error();
        }
        $modified = $post->post_modified_gmt;
        $date = is_string($modified) ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $modified, new DateTimeZone('UTC')) : false;
        if (!$date || $date->format('Y-m-d H:i:s') !== $modified || substr($modified, 0, 4) === '0000') {
            return self::schema_error();
        }
        return new WP_REST_Response(array(
            'id' => $id, 'number' => $number, 'status' => $statuses[0],
            'post_modified_gmt' => $modified, 'schema_version' => self::SCHEMA, 'bridge_version' => self::VERSION,
        ));
    }

    public static function no_store($response, $server, $request) {
        $route = $request->get_route();
        if ($route === '/tkd-cloud/v1' || strpos($route, '/tkd-cloud/v1/') === 0) {
            $response = rest_ensure_response($response);
            $response->header('Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0');
            $response->header('Pragma', 'no-cache');
            $response->header('Expires', '0');
            $response->header('Vary', 'Authorization, Cookie');
        }
        return $response;
    }
}
require_once __DIR__ . '/read-extensions.php';
require_once __DIR__ . '/native-contracts.php';
require_once __DIR__ . '/document-contracts.php';
require_once __DIR__ . '/reservation-contracts.php';
require_once __DIR__ . '/delivery-creation-lock.php';
require_once __DIR__ . '/document-operations.php';
require_once __DIR__ . '/document-pdf.php';
require_once __DIR__ . '/operations.php';
require_once __DIR__ . '/operator-role.php';
TKD_Cloud_Operator_Role::boot();
TKD_Cloud_Delivery_Creation_Lock::boot();
TKD_Cloud_Reader::boot();
register_activation_hook(__FILE__, array('TKD_Cloud_Reader', 'activate'));
// Deactivation unregisters code on subsequent requests; no roles or data are deleted.
