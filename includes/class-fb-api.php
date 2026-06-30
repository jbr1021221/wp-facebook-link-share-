<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FB_API {

	/**
	 * Post a link to a Facebook Page via the Graph API.
	 *
	 * IMPORTANT NOTES:
	 * - Facebook will scrape Open Graph tags from the URL for the preview image/title/description.
	 * - The site MUST be publicly reachable for the preview to render correctly.
	 * - The Page Access Token must have pages_manage_posts permission and be long-lived
	 *   (60-day or non-expiring). Short-lived tokens from Graph API Explorer will break after ~1 hour.
	 *
	 * @param string $url The publicly accessible URL of the post.
	 * @param string $message The message to post with the link.
	 * @return array|WP_Error Array with 'id' on success, WP_Error on failure.
	 */
	public static function post_link( $url, $message ) {
		$page_id = get_option( 'fb_auto_share_page_id' );
		$access_token = get_option( 'fb_auto_share_access_token' );

		if ( empty( $page_id ) || empty( $access_token ) ) {
			return new WP_Error( 'missing_credentials', 'Facebook Page ID or Access Token is not set.' );
		}

		$api_url = "https://graph.facebook.com/v25.0/{$page_id}/feed";

		$body = array(
			'link'         => $url,
			'message'      => $message,
			'access_token' => $access_token,
		);

		$args = array(
			'body'    => $body,
			'timeout' => 15, // Link posts are quicker than video uploads
		);

		return self::make_request( $api_url, $args );
	}

	/**
	 * Helper method to make the request with simple retry logic.
	 */
	private static function make_request( $url, $args, $retries = 1 ) {
		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			$error_code = $response->get_error_code();
			// Retry on timeout
			if ( $error_code === 'http_request_failed' && strpos( $response->get_error_message(), 'timeout' ) !== false ) {
				if ( $retries > 0 ) {
					sleep( 5 );
					return self::make_request( $url, $args, $retries - 1 );
				}
			}
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		$response_code = wp_remote_retrieve_response_code( $response );

		if ( $response_code >= 400 || isset( $data['error'] ) ) {
			$error_message = isset( $data['error']['message'] ) ? $data['error']['message'] : 'Unknown API error.';
			return new WP_Error( 'api_error', $error_message );
		}

		if ( isset( $data['id'] ) ) {
			return array( 'id' => $data['id'] );
		}

		return new WP_Error( 'unknown_error', 'Failed to get post ID from response.' );
	}
}
