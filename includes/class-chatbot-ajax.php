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

		// ── Handle file upload ──────────────────────────────────────────────────

		$file_context = '';
		$file_image   = null; // base64 image data for vision API

		$file_upload_enabled = (bool) get_option( 'lumination_chatbot_file_upload', 0 );
		if ( $file_upload_enabled && ! empty( $_FILES['file'] ) && isset( $_FILES['file']['error'] ) && UPLOAD_ERR_OK === $_FILES['file']['error'] ) {
			$file_result = self::process_file_upload();
			if ( is_wp_error( $file_result ) ) {
				wp_send_json_error( array( 'message' => $file_result->get_error_message() ) );
			}
			if ( ! empty( $file_result['text'] ) ) {
				$file_context = $file_result['text'];
			}
			if ( ! empty( $file_result['image'] ) ) {
				$file_image = $file_result['image'];
			}
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

		// Append file context if present.
		if ( $file_context ) {
			$parts[] = "The user has attached a file. Here is the extracted text content:\n" . $file_context;
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

		// ── Build API messages ──────────────────────────────────────────────────

		$api_messages = array();

		if ( $file_image ) {
			// Vision: send image + text as multipart content.
			$api_messages[] = array(
				'role'    => 'user',
				'content' => array(
					array(
						'type'   => 'image',
						'source' => array(
							'type'         => 'base64',
							'media_type'   => $file_image['media_type'],
							'data'         => $file_image['data'],
						),
					),
					array(
						'type' => 'text',
						'text' => $prompt,
					),
				),
			);
		} else {
			$api_messages[] = array(
				'role'    => 'user',
				'content' => $prompt,
			);
		}

		// ── Call Core API ────────────────────────────────────────────────────────

		$result = Lumination_Core_API::request(
			'/lumination-ai/api/v1/agent/chat',
			array(
				'persist'  => false,
				'stream'   => false,
				'messages' => $api_messages,
			),
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
	 * Process an uploaded file for the chat message.
	 *
	 * Returns extracted text for documents or base64 data for images.
	 *
	 * @since 2.2.0
	 *
	 * @return array|WP_Error Array with 'text' and/or 'image' keys, or WP_Error.
	 */
	private static function process_file_upload() {
		// Nonce already verified in handle_send() before this method is called.
		$file = $_FILES['file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing

		$max_mb   = (int) get_option( 'lumination_chatbot_file_max_size', 2 );
		$max_size = $max_mb * 1024 * 1024;

		if ( $file['size'] > $max_size ) {
			return new WP_Error(
				'file_too_large',
				sprintf(
					/* translators: %d: max file size in MB */
					__( 'File too large. Maximum size is %d MB.', 'lumination-chatbot' ),
					$max_mb
				)
			);
		}

		$allowed_types = array(
			'image/jpeg'    => 'image',
			'image/png'     => 'image',
			'image/webp'    => 'image',
			'image/gif'     => 'image',
			'application/pdf' => 'document',
			'text/plain'    => 'document',
		);

		$mime = wp_check_filetype( $file['name'] );
		$type = isset( $mime['type'] ) ? $mime['type'] : '';

		// Fallback: check actual MIME if wp_check_filetype returns empty.
		if ( empty( $type ) && function_exists( 'mime_content_type' ) ) {
			$type = mime_content_type( $file['tmp_name'] );
		}

		if ( ! isset( $allowed_types[ $type ] ) ) {
			return new WP_Error( 'invalid_file_type', __( 'Unsupported file type. Please upload an image (JPG, PNG, WebP, GIF) or document (PDF, TXT).', 'lumination-chatbot' ) );
		}

		$category = $allowed_types[ $type ];

		if ( 'image' === $category ) {
			// Read file and base64-encode for vision API.
			$data = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $data ) {
				return new WP_Error( 'file_read_error', __( 'Could not read uploaded file.', 'lumination-chatbot' ) );
			}
			return array(
				'text'  => '',
				'image' => array(
					'media_type' => $type,
					'data'       => base64_encode( $data ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				),
			);
		}

		// Document: extract text.
		$text = '';

		if ( 'text/plain' === $type ) {
			$text = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $text ) {
				$text = '';
			}
		} elseif ( 'application/pdf' === $type ) {
			// Basic PDF text extraction — strip binary, find text runs.
			$raw = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false !== $raw ) {
				$text = self::extract_pdf_text( $raw );
			}
		}

		// Cap at 8000 chars like page context.
		$text = trim( substr( $text, 0, 8000 ) );

		return array(
			'text'  => $text,
			'image' => null,
		);
	}

	/**
	 * Basic PDF text extraction.
	 *
	 * Extracts text from PDF stream objects. Not perfect for all PDFs
	 * but handles common cases without external dependencies.
	 *
	 * @since 2.2.0
	 *
	 * @param string $raw Raw PDF binary content.
	 * @return string Extracted text.
	 */
	private static function extract_pdf_text( $raw ) {
		$text = '';

		// Try to find text between BT and ET markers (text objects).
		if ( preg_match_all( '/BT\s*(.*?)\s*ET/s', $raw, $matches ) ) {
			foreach ( $matches[1] as $block ) {
				// Extract text from Tj and TJ operators.
				if ( preg_match_all( '/\(([^)]*)\)/', $block, $texts ) ) {
					$text .= implode( ' ', $texts[1] ) . "\n";
				}
				// Handle hex strings.
				if ( preg_match_all( '/<([0-9a-fA-F]+)>/', $block, $hex_texts ) ) {
					foreach ( $hex_texts[1] as $hex ) {
						$text .= pack( 'H*', $hex ) . ' ';
					}
				}
			}
		}

		// Clean up non-printable characters.
		$text = preg_replace( '/[^\x20-\x7E\n\r\t]/', '', $text );
		$text = preg_replace( '/\s+/', ' ', $text );

		return trim( $text );
	}

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
