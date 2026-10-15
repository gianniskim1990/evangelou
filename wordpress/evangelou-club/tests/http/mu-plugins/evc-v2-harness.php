<?php
/**
 * CI-ONLY mu-plugin (UI-04H). Copied by the workflow into the DISPOSABLE
 * WordPress site used for real HTTP checks; it never exists in the plugin
 * package. Loads the TEST-ONLY v2 harness from the repository checkout and
 * refuses to do anything unless ALL hold:
 *   - EVC_HTTP_HARNESS=on in the server environment,
 *   - a disposable test database name (evc_wp_tests*),
 *   - the staff flag is on (test site wp-config) and the packaged plugin is active.
 */
defined('ABSPATH') || exit;

add_action('plugins_loaded', function () {
    $repo = (string) getenv('EVC_HARNESS_PLUGIN_SRC');
    if (getenv('EVC_HTTP_HARNESS') !== 'on' || !defined('DB_NAME') || !preg_match('/^evc_wp_tests[a-z0-9_]*$/D', DB_NAME)
        || !class_exists('EVC_Rest_V2_Routes') || !class_exists('EVC_Staff_Feature') || !EVC_Staff_Feature::enabled()
        || $repo === '' || !is_file($repo . '/tests/wordpress/support/class-evc-test-v2-harness.php')) {
        return;
    }
    require_once $repo . '/tests/support/class-evc-fixed-clock.php';
    require_once $repo . '/tests/support/class-evc-mock-membership-adapter.php';
    require_once $repo . '/tests/support/class-evc-in-memory-prepared-redemption-store.php';
    require_once $repo . '/tests/support/class-evc-fake-member-lookup.php';
    require_once $repo . '/tests/wordpress/support/class-evc-test-v2-harness.php';

    EVC_Test_V2_Harness::$persistent = true;
    EVC_Test_V2_Harness::load();
    EVC_Test_V2_Harness::install();
    add_action('shutdown', array('EVC_Test_V2_Harness', 'save'));

    // A proxy-style reply: an HTML error page instead of the JSON the client expects.
    add_action('rest_api_init', function () {
        register_rest_route(EVC_Rest_V2_Routes::NAMESPACE_V2, '/harness/proxy', array('methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => function () {
            return new WP_REST_Response(null, 502);
        }));
    });
    add_filter('rest_pre_serve_request', function ($served, $result, $request) {
        if ($request instanceof WP_REST_Request && $request->get_route() === '/' . EVC_Rest_V2_Routes::NAMESPACE_V2 . '/harness/proxy') {
            status_header(502);
            header('Content-Type: text/html; charset=utf-8');
            echo '<html><head><title>502 Bad Gateway</title></head><body><h1>502 Bad Gateway</h1></body></html>';
            return true;
        }
        return $served;
    }, 5, 3);
}, 20);
