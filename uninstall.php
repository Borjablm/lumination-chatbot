<?php
/**
 * Lumination AI Chatbot Uninstall
 *
 * Removes chatbot-specific options when the plugin is deleted.
 * The shared analytics table (wp_lumination_usage) is owned by Core
 * and removed when Lumination Core is deleted.
 *
 * @package    LuminationChatbot
 * @since      2.0.0
 * @license    GPL-3.0-or-later
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- uninstall.php; prefix avoids collision with other uninstall scripts.
$lumination_chatbot_options = array(
	'lumination_chatbot_color',
	'lumination_chatbot_title',
	'lumination_chatbot_welcome',
	'lumination_chatbot_placeholder',
	'lumination_chatbot_instructions',
	'lumination_chatbot_floating_enabled',
	'lumination_chatbot_migrated_v2',
);

foreach ( $lumination_chatbot_options as $lumination_chatbot_option ) {
	delete_option( $lumination_chatbot_option );
}
