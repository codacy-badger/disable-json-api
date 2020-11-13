<?php
/**
 * Disable_REST_API class
 *
 * Most of the work is done in here
 */
class Disable_REST_API {

	const MENU_SLUG = 'disable_rest_api_settings';
	const CAPABILITY = 'manage_options';
	const VERSION = '1.6-alpha';

	/**
	 * Stores 'disable-json-api/disable-json-api.php' typically
	 *
	 * @var string
	 */
	private $base_file_path;


	/**
	 * Disable_REST_API constructor.
	 *
	 * @param $path
	 */
	public function __construct( $path ) {

		// Set variable so the class knows how to reference the plugin
		$this->base_file_path = plugin_basename( $path );

		// Do logic for upgrading to 1.6 from versions less than 1.6
		add_action( 'init', array( &$this, 'v1_6_option_check' ) );

		// Set up admin page for plugin settings
		add_action( 'admin_menu', array( &$this, 'define_admin_link' ) );

		// This actually does everything in this plugin
		add_filter( 'rest_authentication_errors', array( &$this, 'you_shall_not_pass' ), 20 );

	}


	/**
	 * Checks for a current route being requested, and processes the allowlist
	 *
	 * @param $access
	 *
	 * @return WP_Error|null|boolean
	 */
	public function you_shall_not_pass( $access ) {

		// Return current value of $access and skip all plugin functionality
		if ( $this->allow_rest_api() ) {
			return $access;
		}

		$current_route = $this->get_current_route();

		if ( ! $this->is_route_allowed( $current_route ) ) {
			return $this->get_wp_error( $access );
		}

		// If we got all the way here, return the unmodified $access response
		return $access;

	}


	/**
	 * Current REST route getter.
	 *
	 * @return string
	 */
	private function get_current_route() {
		$rest_route = $GLOBALS['wp']->query_vars['rest_route'];

		return ( empty( $rest_route ) || '/' == $rest_route ) ?
			$rest_route :
			untrailingslashit( $rest_route );
	}


	/**
	 * Checks a route for whether it belongs to the list of allowed routes
	 *
	 * @param $currentRoute
	 *
	 * @return boolean
	 */
	private function is_route_allowed( $currentRoute ) {

		$current_options = get_option( 'disable_rest_api_options', array() );
		$current_user_roles = $this->get_current_user_roles();

		// Loop through user roles belonging to the current user
		foreach ( $current_user_roles as $role ) {

			// If we have a definition for the current user's role
			if ( isset( $current_options['roles'][$role] ) ) {

				// See if this route is specifically allowed
				$is_currentRoute_allowed = array_reduce( DRA_Helpers::get_allowed_routes( $role ), function ( $isMatched, $pattern ) use ( $currentRoute ) {
						return $isMatched || (bool) preg_match( '@^' . htmlspecialchars_decode( $pattern ) . '$@i', $currentRoute );
					}, false );
				if ( $is_currentRoute_allowed ) {
					return true;
				}

				// See if this route is specifically disallowed
				$is_currentRoute_disallowed = array_reduce( DRA_Helpers::get_allowed_routes( $role, false ), function ( $isMatched, $pattern ) use ( $currentRoute ) {
						return $isMatched || (bool) preg_match( '@^' . htmlspecialchars_decode( $pattern ) . '$@i', $currentRoute );
					}, false );
				if ( $is_currentRoute_disallowed ) {
					return false;
				}

				// If the route has no definition, see if the role is set to allow unknown routes by default
				if ( true === $current_options['roles'][$role]['default_allow'] ) {
					return true;
				}

			} elseif ( true === $current_options['default_allow'] ) {

				// If the user role is not defined in the settings, but the settings say that we should ALLOW routes for unknown user roles by default
				return true;

			}

		}

		// If we got all the way here, we didn't find any rules that said you should be allowed to view this route
		return false;

	}


	/**
	 * Add a menu
	 *
	 * @return void
	 */
	public function define_admin_link() {

		add_options_page( esc_html__( 'Disable REST API Settings', 'disable-json-api' ), esc_html__( 'Disable REST API', 'disable-json-api' ), self::CAPABILITY, self::MENU_SLUG, array(
			&$this,
			'settings_page'
		) );
		add_filter( "plugin_action_links_$this->base_file_path", array( &$this, 'settings_link' ) );
		add_action( 'admin_enqueue_scripts', array( &$this, 'admin_enqueues' ) );

	}


	/**
	 * Add Settings Link to plugins page
	 *
	 * @param $links
	 *
	 * @return array
	 */
	public function settings_link( $links ) {

		$settings_url  = menu_page_url( self::MENU_SLUG, false );
		$settings_link = "<a href='$settings_url'>" . esc_html__( "Settings", "disable-json-api" ) . "</a>";
		array_unshift( $links, $settings_link );

		return $links;
	}


	/**
	 * Menu Callback
	 *
	 * @return void
	 */
	public function settings_page() {

		$this->maybe_process_settings_form();

		// Render the settings template
		include( __DIR__ . "/../admin.php" );

	}

	/**
	 * Enqueues for adding CSS and JavaScript to the admin settings page
	 */
	public function admin_enqueues( $hook_suffix ) {
		if ( $hook_suffix == 'settings_page_' . self::MENU_SLUG ) {
			$admin_css_file         = WP_PLUGIN_DIR . '/' . plugin_dir_path( $this->base_file_path ) . 'css/admin.css';
			$admin_css_url          = plugin_dir_url( $this->base_file_path ) . 'css/admin.css';

			$admin_js_header_file   = WP_PLUGIN_DIR . '/' . plugin_dir_path( $this->base_file_path ) . 'js/admin-header.js';
			$admin_js_header_url    = plugin_dir_url( $this->base_file_path ) . 'js/admin-header.js';

			$admin_js_footer_file   = WP_PLUGIN_DIR . '/' . plugin_dir_path( $this->base_file_path ) . 'js/admin-footer.js';
			$admin_js_footer_url    = plugin_dir_url( $this->base_file_path ) . 'js/admin-footer.js';

			wp_enqueue_style( 'admin-css', $admin_css_url, array(), filemtime( $admin_css_file ), 'all' );
			wp_enqueue_script( 'admin-header', $admin_js_header_url, array( 'jquery' ), filemtime( $admin_js_header_file ), false );
			wp_enqueue_script( 'admin-footer', $admin_js_footer_url, array( 'jquery' ), filemtime( $admin_js_footer_file ), true );
		}
	}


	/**
	 * Process the admin page settings form submission
	 *
	 * @return void
	 */
	private function maybe_process_settings_form() {

		if ( ! ( isset( $_POST['_wpnonce'] ) && check_admin_referer( 'DRA_admin_nonce' ) ) ) {
			return;
		}

		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// Confirm a valid role has been passed
		$role = ( isset( $_POST['role'] ) ) ? $_POST['role'] : 'dra-undefined';
		if ( ! DRA_Helpers::is_valid_role( $role ) ) {
			add_settings_error( 'DRA-notices', esc_attr( 'settings_updated' ), esc_html__( 'Invalid user role detected when processing form. No updates have been made.', 'disable-json-api' ), 'error' );
			return;
		}

		// Catch the `default_allow` value for this role
		$default_allow = ( isset( $_POST['default_allow'] ) && "1" == $_POST['default_allow'] ) ? true : false;

		// Catch the routes that should be allowed
		$rest_routes = ( isset( $_POST['rest_routes'] ) ) ? wp_unslash( $_POST['rest_routes'] ) : array();

//		var_dump($rest_routes);

		// Retrieve all current rules for all roles
		$arr_option = get_option( 'disable_rest_api_options' );

		// If resetting or allowlist is empty, clear the option and exit the function
		if ( empty( $rest_routes ) || isset( $_POST['reset'] ) ) {

			// Unauthorized users default to no routes allowed. All other user roles default to allowing all routes
			$rest_routes_for_setting = DRA_Helpers::build_routes_rule_for_all( $default_allow );
			$msg = esc_html__( 'All allowlists have been reset for this user role.', 'disable-json-api' );

		} else {

			// Get back the full list of true/false routes based on the posted routes allowed
			$rest_routes_for_setting = DRA_Helpers::build_routes_rule( $rest_routes );
			$msg = esc_html__( 'Allowlist settings saved for this user role.', 'disable-json-api' );

		}

//		var_dump($rest_routes_for_setting);
//		var_dump($msg);
//		wp_die();

		// Save only the rules for this role back to itself
		$arr_option['roles'][$role] = array(
			'default_allow'     => $default_allow,
			'allow_list'        => $rest_routes_for_setting,
		);

		// Save allowlist to the Options table and return with message for user
		update_option( 'disable_rest_api_options', $arr_option );
		add_settings_error( 'DRA-notices', esc_attr( 'settings_updated' ), $msg, 'updated' );

	}


	/**
	 * Allow carte blanche access for logged-in users (or allow override via filter)
	 *
	 * @return bool
	 */
	private function allow_rest_api() {
		return (bool) apply_filters( 'dra_allow_rest_api', false );
	}


	/**
	 * If $access is already a WP_Error object, add our error to the list
	 * Otherwise return a new one
	 *
	 * @param $access
	 *
	 * @return WP_Error
	 */
	private function get_wp_error( $access ) {
		$error_message = esc_html__( 'DRA: Only authenticated users can access the REST API.', 'disable-json-api' );

		if ( is_wp_error( $access ) ) {
			$access->add( 'rest_cannot_access', $error_message, array( 'status' => rest_authorization_required_code() ) );

			return $access;
		}

		return new WP_Error( 'rest_cannot_access', $error_message, array( 'status' => rest_authorization_required_code() ) );
	}


	/**
	 * Helper function to migrate from pre-version-1.6 to the new option
	 */
	public function v1_6_option_check() {

		// If our new option already exists, we can bail
		if ( get_option( 'disable_rest_api_options') ) {
			return;
		}

		// Migrate from the old Options variable to the new one
		$this->create_settings_option( true );

	}


	/**
	 * Create settings option for the plugin
	 * Optionally migrate data from older versions of the plugin and clean up the old Option if applicable
	 *
	 * @param false $is_upgrade
	 */
	private function create_settings_option( $is_upgrade = false ) {

		// Define the basic structure of our new option
		$arr_option = array(
			'version'           => self::VERSION,       // the current version of this plugin
			'default_allow'     => true,                // if a role is not specifically defined in the settings, should the default be to ALLOW the route or not?
			'roles'             => array(),             // array of the user roles in this install of wordpress
		);

		// Default list of allowed routes. By default, nothing is allowed
		$allowed_routes = ( $is_upgrade ) ? get_option( 'DRA_route_whitelist', array() ) : array();

		// Build the rules for this role based on the merge with the previously allowed rules (if any)
		$new_rules = DRA_Helpers::build_routes_rule( $allowed_routes );

		// Define the "unauthenticated" rules based on the old option value (or default value of "nothing")
		$arr_option['roles']['none'] = array(
			'default_allow'     => false,
			'allow_list'        => $new_rules,
		);

		// Save new option
		update_option( 'disable_rest_api_options', $arr_option );

		// delete the old option if applicable
		if ( $is_upgrade ) {
			delete_option( 'DRA_route_whitelist' );
		}

	}


	/**
	 * Return array with list of roles the current user belongs to
	 *
	 * @return array
	 */
	private function get_current_user_roles() {
		if ( ! is_user_logged_in() ) {
			return array(
				'name' => 'none',
			);
		}

		$user = wp_get_current_user();
		return ( array ) $user->roles;

	}

}
