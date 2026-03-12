<?php
/**
 * Chatbot Settings Tab
 *
 * Registers chatbot options and renders the "Chatbot" tab in the Core admin panel.
 *
 * Options managed here:
 *   lumination_chatbot_title            — widget title
 *   lumination_chatbot_welcome          — opening message (empty = none)
 *   lumination_chatbot_placeholder      — input placeholder text
 *   lumination_chatbot_instructions     — custom AI system instructions
 *   lumination_chatbot_floating_enabled — '1' / '' toggle for floating widget
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
 * Chatbot admin settings tab.
 *
 * @since 2.0.0
 */
class Lumination_Chatbot_Settings {

	/**
	 * Register chatbot settings with WordPress.
	 *
	 * Hook this into 'lumination_core_settings_init'.
	 *
	 * @since 2.0.0
	 */
	public static function register_settings() {
		$group = 'lumination_chatbot_settings';

		register_setting( $group, 'lumination_chatbot_title', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'AI Assistant',
		) );
		register_setting( $group, 'lumination_chatbot_welcome', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
			'default'           => '',
		) );
		register_setting( $group, 'lumination_chatbot_placeholder', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'Ask me anything…',
		) );
		register_setting( $group, 'lumination_chatbot_instructions', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
			'default'           => '',
		) );
		register_setting( $group, 'lumination_chatbot_page_context', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 1,
		) );
		register_setting( $group, 'lumination_chatbot_file_upload', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 0,
		) );
		register_setting( $group, 'lumination_chatbot_file_max_size', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 2,
		) );
		register_setting( $group, 'lumination_chatbot_suggested_prompts', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
			'default'           => '',
		) );
		register_setting( $group, 'lumination_chatbot_floating_enabled', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 1,
		) );
	}

	/**
	 * Render the Chatbot admin tab body.
	 *
	 * @since 2.0.0
	 */
	public static function render_tab() {
		settings_errors( 'lumination_chatbot_messages' );
		?>
		<form action="options.php" method="post" style="max-width: 800px;">
			<?php settings_fields( 'lumination_chatbot_settings' ); ?>

			<h2><?php esc_html_e( 'Appearance', 'lumination-chatbot' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Brand Colours', 'lumination-chatbot' ); ?></th>
					<td>
						<p class="description">
							<?php
							printf(
								/* translators: %s: link to Core Appearance tab */
								esc_html__( 'The chatbot uses your site-wide brand colours. Set them on the %s.', 'lumination-chatbot' ),
								'<a href="' . esc_url( admin_url( 'tools.php?page=lumination-core&tab=appearance' ) ) . '">'
								. esc_html__( 'Appearance tab', 'lumination-chatbot' ) . '</a>'
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="lumination_chatbot_title"><?php esc_html_e( 'Widget Title', 'lumination-chatbot' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="lumination_chatbot_title"
							name="lumination_chatbot_title"
							value="<?php echo esc_attr( get_option( 'lumination_chatbot_title', 'AI Assistant' ) ); ?>"
							class="regular-text"
							placeholder="AI Assistant"
						/>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="lumination_chatbot_welcome"><?php esc_html_e( 'Welcome Message', 'lumination-chatbot' ); ?></label>
					</th>
					<td>
						<textarea
							id="lumination_chatbot_welcome"
							name="lumination_chatbot_welcome"
							rows="3"
							class="large-text"
						><?php echo esc_textarea( get_option( 'lumination_chatbot_welcome', '' ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Shown when the chat opens. Leave empty for no greeting.', 'lumination-chatbot' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="lumination_chatbot_placeholder"><?php esc_html_e( 'Input Placeholder', 'lumination-chatbot' ); ?></label>
					</th>
					<td>
						<input
							type="text"
							id="lumination_chatbot_placeholder"
							name="lumination_chatbot_placeholder"
							value="<?php echo esc_attr( get_option( 'lumination_chatbot_placeholder', 'Ask me anything…' ) ); ?>"
							class="regular-text"
							placeholder="Ask me anything…"
						/>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'AI Behaviour', 'lumination-chatbot' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="lumination_chatbot_instructions"><?php esc_html_e( 'Custom Instructions', 'lumination-chatbot' ); ?></label>
					</th>
					<td>
						<textarea
							id="lumination_chatbot_instructions"
							name="lumination_chatbot_instructions"
							rows="5"
							class="large-text"
						><?php echo esc_textarea( get_option( 'lumination_chatbot_instructions', '' ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Extra context or persona instructions for the AI.', 'lumination-chatbot' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Page Context', 'lumination-chatbot' ); ?></th>
					<td>
						<label>
							<input
								type="checkbox"
								name="lumination_chatbot_page_context"
								value="1"
								<?php checked( 1, (int) get_option( 'lumination_chatbot_page_context', 1 ) ); ?>
							/>
							<?php esc_html_e( 'Send current page content as context to the AI', 'lumination-chatbot' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'When enabled, the chatbot fetches and sends the content of the page the user is currently reading so the AI can answer questions about it.', 'lumination-chatbot' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="lumination_chatbot_suggested_prompts"><?php esc_html_e( 'Suggested Prompts', 'lumination-chatbot' ); ?></label>
					</th>
					<td>
						<textarea
							id="lumination_chatbot_suggested_prompts"
							name="lumination_chatbot_suggested_prompts"
							rows="3"
							class="large-text"
						><?php echo esc_textarea( get_option( 'lumination_chatbot_suggested_prompts', '' ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Clickable starter questions shown below the welcome message. One prompt per line (max 3 shown).', 'lumination-chatbot' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'File Uploads', 'lumination-chatbot' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Uploads', 'lumination-chatbot' ); ?></th>
					<td>
						<label>
							<input
								type="checkbox"
								name="lumination_chatbot_file_upload"
								value="1"
								<?php checked( 1, (int) get_option( 'lumination_chatbot_file_upload', 0 ) ); ?>
							/>
							<?php esc_html_e( 'Allow users to attach files to chat messages', 'lumination-chatbot' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Supports images (JPG, PNG, WebP, GIF) and text documents (PDF, TXT). Images are sent to the AI using vision capabilities.', 'lumination-chatbot' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="lumination_chatbot_file_max_size"><?php esc_html_e( 'Max File Size (MB)', 'lumination-chatbot' ); ?></label>
					</th>
					<td>
						<input
							type="number"
							id="lumination_chatbot_file_max_size"
							name="lumination_chatbot_file_max_size"
							value="<?php echo esc_attr( get_option( 'lumination_chatbot_file_max_size', 2 ) ); ?>"
							class="small-text"
							min="1"
							max="10"
							step="1"
						/>
						<p class="description"><?php esc_html_e( 'Maximum file size in megabytes (1–10 MB).', 'lumination-chatbot' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Display Modes', 'lumination-chatbot' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Floating Widget', 'lumination-chatbot' ); ?></th>
					<td>
						<label>
							<input
								type="checkbox"
								name="lumination_chatbot_floating_enabled"
								value="1"
								<?php checked( 1, (int) get_option( 'lumination_chatbot_floating_enabled', 1 ) ); ?>
							/>
							<?php esc_html_e( 'Show floating chat bubble on all frontend pages', 'lumination-chatbot' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'The bubble appears in the bottom-right corner of every page on your site.', 'lumination-chatbot' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Embedded Widget', 'lumination-chatbot' ); ?></th>
					<td>
						<p>
							<?php esc_html_e( 'To embed a full chat panel inside any page or post, use the shortcode:', 'lumination-chatbot' ); ?>
							<code>[lumination_chatbot]</code>
						</p>
						<p class="description"><?php esc_html_e( 'The embedded panel is always visible — no bubble button. Useful for dedicated "Chat with AI" pages.', 'lumination-chatbot' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save Settings', 'lumination-chatbot' ) ); ?>
		</form>
		<?php
	}
}
