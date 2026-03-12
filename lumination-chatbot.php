<?php
/**
 * Lumination AI Chatbot
 *
 * AI-powered chat widget for WordPress. Supports two display modes:
 *  - Floating widget (bubble in the bottom-right corner of every page)
 *  - Embedded panel  (via [lumination_chatbot] shortcode)
 *
 * Requires Lumination Core (v1.0.0+) for API access and analytics.
 *
 * @package           LuminationChatbot
 * @author            Lumination Team
 * @license           GPL-3.0-or-later
 * @link              https://lumination.ai
 * @copyright         2026 Lumination Team
 *
 * @wordpress-plugin
 * Plugin Name:       Lumination AI Chatbot
 * Description:       AI-powered chat widget with floating and embedded display modes. The chatbot reads the current page for context and answers questions using the Lumination AI API. Requires Lumination Core.
 * Version:           2.3.2
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Lumination Team
 * Author URI:        https://lumination.ai
 * License:           GPL v3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       lumination-chatbot
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ── Constants ────────────────────────────────────────────────────────────────

define( 'LUMINATION_CHATBOT_VERSION', '2.3.2' );
define( 'LUMINATION_CHATBOT_FILE',    __FILE__ );
define( 'LUMINATION_CHATBOT_DIR',     plugin_dir_path( __FILE__ ) );
define( 'LUMINATION_CHATBOT_URL',     plugin_dir_url( __FILE__ ) );

// ── Auto-update via GitHub releases ──────────────────────────────────────────

require_once LUMINATION_CHATBOT_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

PucFactory::buildUpdateChecker(
	'https://github.com/Borjablm/lumination-chatbot/',
	__FILE__,
	'lumination-chatbot'
);

// ── Option migration from v1 (old lmc_* keys) ────────────────────────────────
//
// Runs at priority 5 so the new option values are in place before anything
// else reads them. Guarded by a flag — only runs once.
//
add_action(
	'plugins_loaded',
	function () {
		if ( get_option( 'lumination_chatbot_migrated_v2' ) ) {
			return;
		}

		// Migrate API credentials to Core option names (only if Core's are empty).
		if ( ! get_option( 'lumination_api_key' ) && get_option( 'lmc_api_key' ) ) {
			update_option( 'lumination_api_key', get_option( 'lmc_api_key' ) );
		}
		if ( ! get_option( 'lumination_api_base_url' ) && get_option( 'lmc_api_base_url' ) ) {
			update_option( 'lumination_api_base_url', get_option( 'lmc_api_base_url' ) );
		}

		// Migrate chatbot-specific options to new names.
		$migrations = array(
			'lmc_primary_color'   => 'lumination_chatbot_color',
			'lmc_bot_title'       => 'lumination_chatbot_title',
			'lmc_welcome_message' => 'lumination_chatbot_welcome',
			'lmc_placeholder'     => 'lumination_chatbot_placeholder',
			'lmc_instructions'    => 'lumination_chatbot_instructions',
		);

		foreach ( $migrations as $old => $new ) {
			$old_value = get_option( $old );
			if ( false !== $old_value && ! get_option( $new ) ) {
				update_option( $new, $old_value );
			}
		}

		// Migrate usage data from wp_lmc_usage → wp_lumination_usage.
		// Only attempted when Core's analytics table exists.
		if ( function_exists( 'lumination_core' ) ) {
			lumination_chatbot_migrate_analytics();
		}

		update_option( 'lumination_chatbot_migrated_v2', '1' );
	},
	5
);

/**
 * One-time INSERT of old wp_lmc_usage rows into the Core analytics table.
 *
 * Maps: page_url → page_url, session_uuid → session_uuid,
 *       tokens_in → tokens_in, tokens_out → tokens_out, credits → credits.
 * Sets tool = 'chatbot', input_type = 'chat'.
 *
 * @since 2.0.0
 */
function lumination_chatbot_migrate_analytics() {
	global $wpdb;

	$old_table = $wpdb->prefix . 'lmc_usage';
	$new_table = $wpdb->prefix . 'lumination_usage';

	// Safety check: old table must exist.
	// Table names are built from $wpdb->prefix + literal strings — no user input involved.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$exists = $wpdb->get_var( "SHOW TABLES LIKE '{$old_table}'" );
	if ( $exists !== $old_table ) {
		return;
	}

	$wpdb->query(
		"INSERT IGNORE INTO `{$new_table}`
			(created_at, tool, page_url, input_type, session_uuid, tokens_in, tokens_out, credits)
		SELECT created_at, 'chatbot', page_url, 'chat', session_uuid, tokens_in, tokens_out, credits
		FROM `{$old_table}`"
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
}

// ── Dependency check + initialisation ────────────────────────────────────────

add_action(
	'plugins_loaded',
	function () {
		$core_ok = function_exists( 'lumination_core' )
				&& defined( 'LUMINATION_CORE_VERSION' )
				&& version_compare( LUMINATION_CORE_VERSION, '1.0.0', '>=' );

		if ( ! $core_ok ) {
			add_action(
				'admin_notices',
				function () {
					if ( ! current_user_can( 'activate_plugins' ) ) {
						return;
					}
					$msg = sprintf(
						wp_kses(
							/* translators: %s: URL to Plugins admin page */
							__( '<strong>Lumination AI Chatbot</strong> requires <strong>Lumination Core</strong> (v1.0.0+) to be installed and active. <a href="%s">Manage plugins &rarr;</a>', 'lumination-chatbot' ),
							array(
								'strong' => array(),
								'a'      => array( 'href' => array() ),
							)
						),
						esc_url( admin_url( 'plugins.php' ) )
					);
					echo '<div class="notice notice-error is-dismissible"><p>' . $msg . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
			);
			return;
		}

		// Core confirmed — load classes and register hooks.
		require_once LUMINATION_CHATBOT_DIR . 'includes/class-chatbot-settings.php';
		require_once LUMINATION_CHATBOT_DIR . 'includes/class-chatbot-ajax.php';
		require_once LUMINATION_CHATBOT_DIR . 'includes/class-chatbot.php';

		// Register settings on Core's hook.
		add_action( 'lumination_core_settings_init', array( 'Lumination_Chatbot_Settings', 'register_settings' ) );

		// Register admin tab in Core's panel.
		add_action(
			'lumination_core_admin_tabs_init',
			function () {
				Lumination_Core_Settings::register_tab(
					array(
						'id'       => 'chatbot',
						'label'    => __( 'Chatbot', 'lumination-chatbot' ),
						'callback' => array( 'Lumination_Chatbot_Settings', 'render_tab' ),
						'priority' => 20,
					)
				);
			}
		);

		// Initialise widget, shortcode, and AJAX.
		Lumination_Chatbot::init();
	},
	20 // Priority 20 — after Core (10) and migration (5).
);
