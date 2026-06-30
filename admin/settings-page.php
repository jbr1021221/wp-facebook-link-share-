<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FB_Auto_Share_Settings {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'wp_ajax_fb_auto_share_test_connection', array( __CLASS__, 'test_connection' ) );
	}

	public static function add_menu_page() {
		add_options_page(
			'FB Auto Share Settings',
			'FB Auto Share',
			'manage_options',
			'fb-auto-share',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	public static function register_settings() {
		register_setting( 'fb_auto_share_options_group', 'fb_auto_share_page_id', 'sanitize_text_field' );
		register_setting( 'fb_auto_share_options_group', 'fb_auto_share_access_token', 'sanitize_text_field' );

		add_settings_section(
			'fb_auto_share_main_section',
			'Facebook Graph API Settings',
			null,
			'fb-auto-share'
		);

		add_settings_field(
			'fb_auto_share_page_id',
			'Facebook Page ID',
			array( __CLASS__, 'render_page_id_field' ),
			'fb-auto-share',
			'fb_auto_share_main_section'
		);

		add_settings_field(
			'fb_auto_share_access_token',
			'Facebook Page Access Token',
			array( __CLASS__, 'render_access_token_field' ),
			'fb-auto-share',
			'fb_auto_share_main_section'
		);
	}

	public static function render_page_id_field() {
		$val = get_option( 'fb_auto_share_page_id', '' );
		echo '<input type="text" name="fb_auto_share_page_id" value="' . esc_attr( $val ) . '" class="regular-text">';
	}

	public static function render_access_token_field() {
		$val = get_option( 'fb_auto_share_access_token', '' );
		echo '<input type="password" name="fb_auto_share_access_token" value="' . esc_attr( $val ) . '" class="regular-text">';
		echo '<p class="description">Requires <code>pages_manage_posts</code> permission and should be a long-lived token.</p>';
		
		// IMPORTANT NOTE from requirements:
		echo '<p class="description" style="color: #666; font-style: italic;">Note: Short-lived tokens from Graph API Explorer will break after ~1 hour. You must generate a long-lived (60-day or non-expiring) page access token.</p>';
	}

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1>FB Auto Share Settings</h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'fb_auto_share_options_group' );
				do_settings_sections( 'fb-auto-share' );
				submit_button();
				?>
			</form>

			<hr>

			<h2>Test Connection</h2>
			<p>Click below to test if the API can reach your Facebook Page using the saved credentials.</p>
			<button id="fb-auto-share-test-btn" class="button button-secondary">Test Connection</button>
			<span class="spinner" id="fb-auto-share-spinner"></span>
			<div id="fb-auto-share-test-result" style="margin-top: 10px;"></div>

			<hr>

			<h2>Recent Auto-Shares</h2>
			<?php self::render_log_viewer(); ?>
		</div>

		<script>
		jQuery(document).ready(function($) {
			$('#fb-auto-share-test-btn').on('click', function(e) {
				e.preventDefault();
				$('#fb-auto-share-spinner').addClass('is-active');
				$('#fb-auto-share-test-result').html('');

				$.post(ajaxurl, {
					action: 'fb_auto_share_test_connection',
					_ajax_nonce: '<?php echo wp_create_nonce( "fb_auto_share_test_nonce" ); ?>'
				}, function(response) {
					$('#fb-auto-share-spinner').removeClass('is-active');
					if (response.success) {
						$('#fb-auto-share-test-result').html('<div class="notice notice-success inline" style="margin-left: 0;"><p>Success! Connected to Page: <strong>' + response.data + '</strong></p></div>');
					} else {
						$('#fb-auto-share-test-result').html('<div class="notice notice-error inline" style="margin-left: 0;"><p>Error: ' + response.data + '</p></div>');
					}
				}).fail(function() {
					$('#fb-auto-share-spinner').removeClass('is-active');
					$('#fb-auto-share-test-result').html('<div class="notice notice-error inline" style="margin-left: 0;"><p>An unexpected error occurred.</p></div>');
				});
			});
		});
		</script>
		<?php
	}

	public static function render_log_viewer() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'fb_auto_share_log';

		// Make sure table exists
		if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) != $table_name ) {
			echo '<p>Log table not found. Please reactivate the plugin.</p>';
			return;
		}

		$logs = $wpdb->get_results( "SELECT * FROM $table_name ORDER BY created_at DESC LIMIT 20" );

		if ( empty( $logs ) ) {
			echo '<p>No auto-shares attempted yet.</p>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>Date</th>';
		echo '<th>Post</th>';
		echo '<th>Status</th>';
		echo '<th>FB Post ID / Error</th>';
		echo '</tr></thead><tbody>';

		foreach ( $logs as $log ) {
			$post_title = get_the_title( $log->post_id );
			$post_link = get_edit_post_link( $log->post_id );
			
			if ( $post_link ) {
				$post_display = sprintf( '<a href="%s">%s</a>', esc_url( $post_link ), esc_html( $post_title ) );
			} else {
				$post_display = esc_html( $post_title );
			}

			echo '<tr>';
			echo '<td>' . esc_html( $log->created_at ) . '</td>';
			echo '<td>' . wp_kses_post( $post_display ) . '</td>';
			
			$status_color = 'inherit';
			if ( $log->status === 'success' ) $status_color = 'green';
			elseif ( $log->status === 'failed' ) $status_color = 'red';

			echo '<td style="color:' . esc_attr( $status_color ) . '"><strong>' . esc_html( ucfirst( $log->status ) ) . '</strong></td>';
			
			$detail = '';
			if ( $log->status === 'success' ) {
				$detail = $log->facebook_post_id;
			} else {
				$detail = $log->error_message;
				
				// Hide full access token in logs if it was captured in the error message
				$token = get_option( 'fb_auto_share_access_token' );
				if ( ! empty( $token ) && strlen( $token ) > 10 ) {
					$masked_token = substr( $token, 0, 4 ) . '...' . substr( $token, -4 );
					$detail = str_replace( $token, $masked_token, $detail );
				}
			}
			
			echo '<td>' . esc_html( $detail ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	public static function test_connection() {
		check_ajax_referer( 'fb_auto_share_test_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized' );
		}

		$page_id = get_option( 'fb_auto_share_page_id' );
		$access_token = get_option( 'fb_auto_share_access_token' );

		if ( empty( $page_id ) || empty( $access_token ) ) {
			wp_send_json_error( 'Please save Page ID and Access Token first.' );
		}

		$url = "https://graph.facebook.com/v25.0/{$page_id}?access_token={$access_token}";

		$response = wp_remote_get( $url );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( $response->get_error_message() );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		$response_code = wp_remote_retrieve_response_code( $response );

		if ( $response_code >= 400 || isset( $data['error'] ) ) {
			$error_msg = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Unknown error.';
			// Mask token in error message if present
			if ( strlen( $access_token ) > 10 ) {
				$masked_token = substr( $access_token, 0, 4 ) . '...' . substr( $access_token, -4 );
				$error_msg = str_replace( $access_token, $masked_token, $error_msg );
			}
			wp_send_json_error( $error_msg );
		}

		if ( isset( $data['name'] ) ) {
			wp_send_json_success( $data['name'] );
		}

		wp_send_json_error( 'Connected, but Page name could not be read.' );
	}
}

FB_Auto_Share_Settings::init();
