<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FB_Auto_Share_Post_Hook {

	public static function init() {
		// Register meta field for REST API / Gutenberg compatibility
		add_action( 'init', array( __CLASS__, 'register_meta_field' ) );
		
		// Run Facebook sharing at priority 20 (fallback for non-REST requests)
		add_action( 'save_post', array( __CLASS__, 'maybe_share_to_facebook' ), 20, 3 );
		
		// Run Facebook sharing specifically after REST API meta sync
		add_action( 'rest_after_insert_post', array( __CLASS__, 'maybe_share_to_facebook_rest' ), 10, 2 );
		
		// Reset attempt flag if post is unpublished
		add_action( 'transition_post_status', array( __CLASS__, 'reset_share_flag_on_unpublish' ), 10, 3 );
		
		add_action( 'wp_head', array( __CLASS__, 'output_og_tags' ) );
	}

	public static function register_meta_field() {
		register_post_meta( 'post', '_fb_auto_share_enabled', array(
			'show_in_rest'  => true,
			'single'        => true,
			'type'          => 'boolean',
			'default'       => false,
			'auth_callback' => function() {
				return current_user_can( 'edit_posts' );
			},
		) );
	}


	public static function output_og_tags() {
		if ( ! is_singular( 'post' ) ) {
			return;
		}

		$post_id = get_queried_object_id();

		// Only output if sharing is enabled for this post
		if ( ! get_post_meta( $post_id, '_fb_auto_share_enabled', true ) ) {
			return;
		}

		// Check if SEO plugin is active
		if ( function_exists( 'YoastSEO' ) || class_exists( 'RankMath' ) ) {
			return;
		}

		$post = get_post( $post_id );
		$title = get_the_title( $post );
		
		// Use excerpt if available, otherwise trim content
		$description_text = $post->post_excerpt ? $post->post_excerpt : wp_strip_all_tags( $post->post_content );
		$description = wp_trim_words( $description_text, 20 );
		
		$url = get_permalink( $post_id );
		$image = get_the_post_thumbnail_url( $post_id, 'full' );

		echo "<!-- FB Auto Share: Basic Open Graph Tags -->\n";
		echo '<meta property="og:title" content="' . esc_attr( $title ) . '">' . "\n";
		echo '<meta property="og:description" content="' . esc_attr( $description ) . '">' . "\n";
		echo '<meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";
		if ( $image ) {
			echo '<meta property="og:image" content="' . esc_url( $image ) . '">' . "\n";
		}
	}

	public static function reset_share_flag_on_unpublish( $new_status, $old_status, $post ) {
		// If post is changed from publish to something else, allow re-sharing later
		if ( $old_status === 'publish' && $new_status !== 'publish' ) {
			delete_post_meta( $post->ID, '_fb_share_attempted' );
		}
	}

	public static function maybe_share_to_facebook_rest( $post, $request ) {
		self::do_share_check( $post->ID, $post );
	}

	/**
	 * Attempt to share to Facebook when a post is saved and published.
	 *
	 * IMPORTANT NOTES:
	 * We use priority 20 on 'save_post' as a fallback for non-REST requests (e.g., Quick Edit, programmatic).
	 * If it's a REST request, we skip this and rely on 'rest_after_insert_post' instead, because
	 * the REST API controller saves post meta AFTER 'save_post' fires, meaning checking the meta
	 * during 'save_post' in a REST context yields stale or empty data.
	 */
	public static function maybe_share_to_facebook( $post_id, $post, $update ) {
		// Skip if this is a REST request, let rest_after_insert_post handle it
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		self::do_share_check( $post_id, $post );
	}

	private static function do_share_check( $post_id, $post ) {
		// Skip if it's an autosave
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Skip if it's a revision
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Restrict to 'post' post_type.
		if ( $post->post_type !== 'post' ) {
			return;
		}

		// Only act when the saved state is published
		if ( $post->post_status !== 'publish' ) {
			return;
		}

		// Avoid re-triggering on every subsequent edit/save of an already-published post
		if ( get_post_meta( $post_id, '_fb_share_attempted', true ) ) {
			return;
		}

		// Set flag immediately so we don't attempt twice per publish action
		update_post_meta( $post_id, '_fb_share_attempted', true );

		$is_enabled = get_post_meta( $post_id, '_fb_auto_share_enabled', true );
		if ( ! $is_enabled ) {
			self::log_result( $post_id, 'skipped', '', 'Facebook sharing not enabled for this post.' );
			return;
		}

		$permalink = get_permalink( $post_id );
		$description = self::build_description( $post );
		
		// Attempt to post to Facebook
		$result = FB_API::post_link( $permalink, $description );

		if ( is_wp_error( $result ) ) {
			$error_msg = $result->get_error_message();
			if ( ! has_post_thumbnail( $post_id ) ) {
				$error_msg .= ' (Note: Post had no featured image, which might affect FB Open Graph scrape).';
			}
			self::log_result( $post_id, 'failed', '', $error_msg );
		} else {
			self::log_result( $post_id, 'success', $result['id'], '' );
		}
	}

	private static function build_description( $post ) {
		$title = $post->post_title;
		$excerpt = $post->post_excerpt;

		$description = $title;
		if ( ! empty( $excerpt ) ) {
			$description .= "\n\n" . $excerpt;
		}

		// Trim to a reasonable length just in case
		// Facebook link posts work fine with shorter text, keep the 1000 char safety trim
		if ( mb_strlen( $description ) > 1000 ) {
			$description = mb_substr( $description, 0, 997 ) . '...';
		}

		return $description;
	}

	private static function log_result( $post_id, $status, $fb_post_id = '', $error_message = '' ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'fb_auto_share_log';

		$wpdb->insert(
			$table_name,
			array(
				'post_id'          => $post_id,
				'facebook_post_id' => $fb_post_id,
				'status'           => $status,
				'error_message'    => $error_message,
				'created_at'       => current_time( 'mysql' ),
			),
			array(
				'%d',
				'%s',
				'%s',
				'%s',
				'%s',
			)
		);
	}
}

FB_Auto_Share_Post_Hook::init();
