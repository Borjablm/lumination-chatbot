<?php
/**
 * Chatbot AJAX Handler
 *
 * Handles the 'lumination_chatbot_send' AJAX action for both logged-in
 * and guest users. Routes API calls through Lumination_Core_API and logs
 * usage via Lumination_Core_Analytics.
 *
 * @package    LuminationChatbot
 * @since      2.0.0
 * @license    GPL-3.0-or-later
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chatbot AJAX handler class.
 *
 * @since 2.0.0
 */
class Lumination_Chatbot_Ajax {

	/**
	 * Register AJAX hooks.
	 *
	 * @since 2.0.0
	 */
	public static function register() {
		add_action( 'wp_ajax_lumination_chatbot_send',        array( __CLASS__, 'handle_send' ) );
		add_action( 'wp_ajax_nopriv_lumination_chatbot_send', array( __CLASS__, 'handle_send' ) );
	}

	/**
	 * AJAX handler: process a chat message and return the AI reply.
	 *
	 * Expects POST: nonce, message, page_url, history (JSON array), session_uuid.
	 * Optionally accepts a file upload via $_FILES['file'].
	 * Returns: { reply: string } on success.
	 *
	 * @since 2.0.0
	 */
	public static function handle_send() {
		check_ajax_referer( 'lumination_chatbot_nonce', 'nonce' );

		if ( ! Lumination_Core_Security::can_submit( 'chatbot' ) ) {
			Lumination_Core_Security::log_event( 'Unauthorized chatbot access attempt' );
			wp_send_json_error( array( 'message' => __( 'Access denied.', 'lumination-chatbot' ) ) );
		}

		$rate_check = Lumination_Core_Security::check_rate_limit( 'chatbot_send', 20, MINUTE_IN_SECONDS );
		if ( is_wp_error( $rate_check ) ) {
			wp_send_json_error( array( 'message' => $rate_check->get_error_message() ) );
		}

		$message      = isset( $_POST['message'] ) ? sanitize_text_field( wp_unslash( $_POST['message'] ) ) : '';
		$page_url     = isset( $_POST['page_url'] ) ? esc_url_raw( wp_unslash( $_POST['page_url'] ) ) : '';
		$session_uuid = isset( $_POST['session_uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['session_uuid'] ) ) : '';

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below per entry
		$raw_history = isset( $_POST['history'] ) ? wp_unslash( $_POST['history'] ) : '[]';
		$history     = json_decode( $raw_history, true );
		if ( ! is_array( $history ) ) {
			$history = array();
		}

		if ( empty( $message ) ) {
			wp_send_json_error( array( 'message' => __( 'Message is required.', 'lumination-chatbot' ) ) );
		}

		// ── Build prompt ────────────────────────────────────────────────────────

		$instructions = get_option( 'lumination_chatbot_instructions', '' );
		$parts        = array();

		if ( $instructions ) {
			$parts[] = sanitize_textarea_field( $instructions );
		}
		$parts[] = 'You are a helpful, professional tutor. Answer clearly and concisely.';
		$parts[] = 'Use markdown for formatting (bold, lists, headings). Keep responses suitable for a chat widget.';

		// Fetch page context only if enabled.
		$page_context_enabled = (bool) get_option( 'lumination_chatbot_page_context', 1 );
		if ( $page_context_enabled ) {
			$page_context = self::fetch_page_context( $page_url );
			if ( $page_context ) {
				$parts[] = sprintf(
					/* translators: 1: page URL, 2: page text content */
					'The user is currently reading this page (%1$s). Here is the page content:\n%2$s',
					$page_url,
					$page_context
				);
				$parts[] = 'Use this content as context for your answers, but draw on your full knowledge too.';
			} elseif ( $page_url ) {
				/* translators: %s: page URL */
				$parts[] = sprintf( 'The user is currently on: %s', $page_url );
			}
		}

		// Append conversation history (last 12 turns).
		$history_lines = array();
		foreach ( array_slice( $history, -12 ) as $entry ) {
			$role    = strtoupper( sanitize_text_field( $entry['role'] ?? 'user' ) );
			$content = sanitize_textarea_field( $entry['content'] ?? '' );
			if ( $content ) {
				$history_lines[] = $role . ': ' . $content;
			}
		}
		if ( $history_lines ) {
			$parts[] = 'Conversation so far:' . "\n" . implode( "\n\n", $history_lines );
		}

		$parts[] = 'User message:' . "\n" . $message;
		$prompt  = implode( "\n\n", $parts );

		// ── Build API request body ──────────────────────────────────────────────

		$api_body = array(
			'persist'  => false,
			'stream'   => false,
			'messages' => array(
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
		);

		// ── Call Core API ────────────────────────────────────────────────────────

		$result = Lumination_Core_API::request(
			'/lumination-ai/api/v1/agent/chat',
			$api_body,
			'lumination-chatbot'
		);

		if ( is_wp_error( $result ) ) {
			Lumination_Core_Security::log_event( 'Chatbot API error', array( 'error' => $result->get_error_message() ) );
			wp_send_json_error( array( 'message' => __( 'Failed to get a response. Please try again.', 'lumination-chatbot' ) ) );
		}

		// Extract reply — try the standard double-nested path, then fallbacks.
		$reply = '';
		if ( isset( $result['response']['response'] ) && is_string( $result['response']['response'] ) ) {
			$reply = trim( $result['response']['response'] );
		} elseif ( isset( $result['response']['content'] ) && is_string( $result['response']['content'] ) ) {
			$reply = trim( $result['response']['content'] );
		} elseif ( isset( $result['message'] ) && is_string( $result['message'] ) ) {
			$reply = trim( $result['message'] );
		}

		if ( empty( $reply ) ) {
			wp_send_json_error( array( 'message' => __( 'Empty response from the API.', 'lumination-chatbot' ) ) );
		}

		// ── Log usage ────────────────────────────────────────────────────────────

		Lumination_Core_Analytics::log_usage(
			'chatbot',
			$page_url,
			isset( $result['token_count_input'] ) ? (int) $result['token_count_input'] : 0,
			isset( $result['token_count_output'] ) ? (int) $result['token_count_output'] : 0,
			isset( $result['credits_charged'] ) ? (float) $result['credits_charged'] : 0,
			'chat',
			$session_uuid
		);

		wp_send_json_success( array( 'reply' => $reply ) );
	}

	// ── Private helpers ───────────────────────────────────────────────────────

	/**
	 * Fetch and clean page content for AI context.
	 *
	 * Strips scripts, styles, nav, header and footer tags, then returns
	 * plain text capped at 8,000 characters.
	 *
	 * @since 2.0.0
	 *
	 * @param string $url Page URL to fetch.
	 * @return string Cleaned page text, or empty string on failure.
	 */
	private static function fetch_page_context( $url ) {
		if ( empty( $url ) ) {
			return '';
		}

		$response = wp_remote_get( $url, array( 'timeout' => 5 ) );

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$html = wp_remote_retrieve_body( $response );

		// Remove noisy structural elements.
		$html = preg_replace( '/<script[^>]*>[\s\S]*?<\/script>/i', '', $html );
		$html = preg_replace( '/<style[^>]*>[\s\S]*?<\/style>/i', '', $html );
		$html = preg_replace( '/<nav[^>]*>[\s\S]*?<\/nav>/i', '', $html );
		$html = preg_replace( '/<header[^>]*>[\s\S]*?<\/header>/i', '', $html );
		$html = preg_replace( '/<footer[^>]*>[\s\S]*?<\/footer>/i', '', $html );

		$text = wp_strip_all_tags( $html );
		$text = preg_replace( '/\s+/', ' ', $text );

		return trim( substr( $text, 0, 8000 ) );
	}
}
