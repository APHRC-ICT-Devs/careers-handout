<?php
/**
 * Plugin Name: Careers API
 * Plugin URI:  https://aphrc.org
 * Description: Authenticated REST API that lets the MS Dynamics job portal create, update, and retract job ads on the careers page.
 * Version:     1.0.0
 * Author:      ADS Kenya
 * License:     GPL-2.0+
 * Text Domain: careers-api
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'CAREERS_API_VERSION', '1.0.0' );
define( 'CAREERS_API_DIR', plugin_dir_path( __FILE__ ) );
define( 'CAREERS_API_OPTION', 'careers_api_settings' );
define( 'CAREERS_API_NAMESPACE', 'careers-api/v1' );
define( 'CAREERS_API_ROLE', 'careers_api_integration' );
define( 'CAREERS_API_CAPABILITY', 'careers_api_write_jobs' );

require_once CAREERS_API_DIR . 'includes/class-endpoint.php';
require_once CAREERS_API_DIR . 'includes/class-admin.php';

/**
 * Dedicated least-privilege role for the Dynamics job portal's WP user:
 * just enough to authenticate and write job ads via this API.
 */
function careers_api_register_role() {
	$role = get_role( CAREERS_API_ROLE );

	if ( ! $role ) {
		add_role( CAREERS_API_ROLE, 'Job Portal Integration', [
			'read'                  => true,
			CAREERS_API_CAPABILITY  => true,
		] );
	} elseif ( ! $role->has_cap( CAREERS_API_CAPABILITY ) ) {
		$role->add_cap( CAREERS_API_CAPABILITY );
	}
}
register_activation_hook( __FILE__, 'careers_api_register_role' );
add_action( 'plugins_loaded', 'careers_api_register_role' );

add_action( 'plugins_loaded', function () {
	if ( is_admin() ) {
		new Careers_API_Admin();
	}

	$settings = get_option( CAREERS_API_OPTION, [] );
	if ( ! empty( $settings['enabled'] ) ) {
		new Careers_API_Endpoint( $settings );
	}
} );