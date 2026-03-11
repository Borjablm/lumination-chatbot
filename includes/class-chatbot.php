<?php
/**
 * Chatbot Widget
 *
 * Handles both display modes:
 *  - Floating  : output via wp_footer hook on every frontend page (when enabled).
 *  - Embedded  : output via [lumination_chatbot] shortcode on specific pages.
 *
 * PHP renders the full widget HTML for both modes. One shared JS file
 * attaches behaviour based on the 'data-mode' attribute.
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
 * Chatbot Widget class.
 *
 * @since 2.0.0
 */
class Lumination_Chatbot {

	/**
	 * Initialize hooks.
	 *
	 * @since 2.0.0
	 */
	public static function init() {
		add_shortcode( 'lumination_chatbot', array( __CLASS__, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_floating_widget' ) );

		Lumination_Chatbot_Ajax::register();
	}

	// ── Asset enqueuing ───────────────────────────────────────────────────────

	/**
	 * Enqueue chatbot CSS + JS when needed.
	 *
	 * Loads on every page when floating is enabled.
	 * Also loads on pages that contain the [lumination_chatbot] shortcode.
	 * Does nothing if the API is not configured.
	 *
	 * @since 2.0.0
	 */
	public static function enqueue_assets() {
		if ( ! Lumination_Core_API::is_configured() ) {
			return;
		}

		$floating_enabled = (bool) get_option( 'lumination_chatbot_floating_enabled', 1 );

		if ( ! $floating_enabled ) {
			// Only load if the shortcode is present on this page.
			global $post;
			if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'lumination_chatbot' ) ) {
				return;
			}
		}

		wp_enqueue_style(
			'lumination-chatbot',
			LUMINATION_CHATBOT_URL . 'assets/css/chatbot.css',
			array(),
			LUMINATION_CHATBOT_VERSION
		);

		$color_css = self::get_color_css();
		if ( $color_css ) {
			wp_add_inline_style( 'lumination-chatbot', $color_css );
		}

		wp_enqueue_script(
			'lumination-chatbot',
			LUMINATION_CHATBOT_URL . 'assets/js/chatbot.js',
			array(),
			LUMINATION_CHATBOT_VERSION,
			true
		);

		wp_localize_script(
			'lumination-chatbot',
			'luminationChatbotConfig',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'lumination_chatbot_nonce' ),
			)
		);
	}

	// ── Color CSS ────────────────────────────────────────────────────────────

	/**
	 * Build inline CSS from Core brand color settings.
	 *
	 * Falls back to the chatbot's own lumination_chatbot_color option
	 * if Core colors are not configured, for migration compatibility.
	 *
	 * @since 2.1.0
	 * @return string Inline CSS string, or empty if no colors set.
	 */
	private static function get_color_css() {
		$primary    = Lumination_Core_Settings::get_color( 'primary' );
		$hover      = Lumination_Core_Settings::get_color( 'primary_hover' );
		$text       = Lumination_Core_Settings::get_color( 'button_text' );
		$background = Lumination_Core_Settings::get_color( 'tool_background' );

		// Migration fallback: use chatbot's own color if Core primary is unset.
		if ( ! $primary ) {
			$chatbot_color = get_option( 'lumination_chatbot_color', '' );
			if ( $chatbot_color ) {
				$primary = $chatbot_color;
			}
		}

		$vars = array();
		if ( $primary ) {
			$vars[] = '--lmc-primary:' . sanitize_hex_color( $primary );
		}
		if ( $hover ) {
			$vars[] = '--lmc-primary-hover:' . sanitize_hex_color( $hover );
		}
		if ( $text ) {
			$vars[] = '--lmc-btn-text:' . sanitize_hex_color( $text );
		}
		if ( $background ) {
			$vars[] = '--lmc-bg:' . sanitize_hex_color( $background );
		}

		if ( empty( $vars ) ) {
			return '';
		}

		return '.lmc-chatbot{' . implode( ';', $vars ) . '}';
	}

	// ── Floating widget ───────────────────────────────────────────────────────

	/**
	 * Output the floating chatbot widget in wp_footer.
	 *
	 * Only renders if floating is enabled, API is configured, and the current
	 * user passes the capability gate.
	 *
	 * @since 2.0.0
	 */
	public static function render_floating_widget() {
		if ( ! (bool) get_option( 'lumination_chatbot_floating_enabled', 1 ) ) {
			return;
		}

		if ( ! Lumination_Core_API::is_configured() ) {
			return;
		}

		if ( ! Lumination_Core_Security::can_submit( 'chatbot' ) ) {
			return;
		}

		self::render_widget_html( 'floating' );
	}

	// ── Embedded shortcode ────────────────────────────────────────────────────

	/**
	 * Render the [lumination_chatbot] shortcode.
	 *
	 * @since 2.0.0
	 *
	 * @param array $atts Shortcode attributes (currently unused).
	 * @return string Widget HTML or error message.
	 */
	public static function render_shortcode( $atts ) {
		if ( ! Lumination_Core_Security::can_submit( 'chatbot' ) ) {
			return '<p class="lumination-notice">' .
				esc_html__( 'Chat access is restricted on this site.', 'lumination-chatbot' ) .
				'</p>';
		}

		if ( ! Lumination_Core_API::is_configured() ) {
			return '<p class="lumination-notice">' .
				esc_html__( 'Lumination is not configured. Please ask the site administrator to set up the API connection.', 'lumination-chatbot' ) .
				'</p>';
		}

		ob_start();
		self::render_widget_html( 'embed' );
		return ob_get_clean();
	}

	// ── Shared HTML renderer ──────────────────────────────────────────────────

	/**
	 * Output the full chatbot widget HTML.
	 *
	 * Both modes share this markup. The data-mode attribute tells the JS
	 * how to behave; CSS handles the visual difference.
	 *
	 * @since 2.0.0
	 *
	 * @param string $mode 'floating' or 'embed'.
	 */
	private static function render_widget_html( $mode ) {
		$title       = get_option( 'lumination_chatbot_title', __( 'AI Assistant', 'lumination-chatbot' ) );
		$welcome     = get_option( 'lumination_chatbot_welcome', '' );
		$placeholder = get_option( 'lumination_chatbot_placeholder', __( 'Ask me anything…', 'lumination-chatbot' ) );

		$wrapper_class = 'lmc-chatbot lmc-chatbot--' . esc_attr( $mode );
		$panel_class   = 'lmc-panel' . ( 'floating' === $mode ? ' lmc-hidden' : '' );
		?>
		<div
			class="<?php echo esc_attr( $wrapper_class ); ?>"
			data-mode="<?php echo esc_attr( $mode ); ?>"
			data-welcome="<?php echo esc_attr( $welcome ); ?>"
		>
			<?php if ( 'floating' === $mode ) : ?>
			<button class="lmc-bubble" type="button" aria-label="<?php esc_attr_e( 'Open chat assistant', 'lumination-chatbot' ); ?>">
				<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
				</svg>
			</button>
			<?php endif; ?>

			<div class="<?php echo esc_attr( $panel_class ); ?>" role="dialog" aria-label="<?php echo esc_attr( $title ); ?>">
				<div class="lmc-header">
					<span class="lmc-title"><?php echo esc_html( $title ); ?></span>
					<?php if ( 'floating' === $mode ) : ?>
					<button class="lmc-close" type="button" aria-label="<?php esc_attr_e( 'Close chat', 'lumination-chatbot' ); ?>">&times;</button>
					<?php endif; ?>
				</div>

				<div class="lmc-messages" aria-live="polite" aria-atomic="false"></div>

				<form class="lmc-form" novalidate>
					<input
						class="lmc-input"
						type="text"
						placeholder="<?php echo esc_attr( $placeholder ); ?>"
						autocomplete="off"
						aria-label="<?php echo esc_attr( $placeholder ); ?>"
					/>
					<button class="lmc-send" type="submit" aria-label="<?php esc_attr_e( 'Send message', 'lumination-chatbot' ); ?>">
						<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
							<line x1="22" y1="2" x2="11" y2="13"/>
							<polygon points="22 2 15 22 11 13 2 9 22 2"/>
						</svg>
					</button>
				</form>
			</div>
		</div>
		<?php
	}
}
