<?php
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Authenticated write endpoints used by the Dynamics job portal to create,
 * update, and retract job ads. This plugin registers ONLY these routes —
 * it is fully separate from the read-only mel API.
 */
class Careers_API_Endpoint {

	/** Meta keys are hardcoded on purpose: the Career Deadline Checker
	 *  snippet reads these exact keys, so they must stay in sync. */
	const META_DEADLINE = 'application_deadline';
	const META_LOCATION = 'location';
	const META_SHORT    = 'career_short';

	private string $post_type;
	private string $slug;
	private string $external_id_key;
	private bool $test_mode;

	public function __construct( array $cfg ) {
		$this->post_type       = $cfg['post_type'] ?? 'career';
		$this->slug            = $cfg['endpoint_slug'] ?? 'jobs';
		$this->external_id_key = $cfg['external_id_meta_key'] ?? '_careers_api_job_id';
		$this->test_mode       = ! empty( $cfg['test_mode'] );

		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes() {
		register_rest_route( CAREERS_API_NAMESPACE, '/' . $this->slug . '/(?P<external_id>[A-Za-z0-9_-]+)', [
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'upsert' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => $this->upsert_args(),
			],
			[
				'methods'             => 'DELETE',
				'callback'            => [ $this, 'retract' ],
				'permission_callback' => [ $this, 'check_permission' ],
			],
		] );
	}

	public function check_permission(): bool {
		return current_user_can( CAREERS_API_CAPABILITY );
	}

	// -------------------------------------------------------------------------
	// Handlers
	// -------------------------------------------------------------------------

	public function upsert( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$external_id = $request->get_param( 'external_id' );
		$title       = sanitize_text_field( $request->get_param( 'title' ) );
		$content     = wp_kses_post( $request->get_param( 'description' ) );
		$short       = wp_kses_post( (string) $request->get_param( 'short_description' ) );
		$location    = strtoupper( sanitize_text_field( $request->get_param( 'location' ) ) );
		$deadline    = $request->get_param( 'application_deadline' );

		$existing = $this->find_by_external_id( $external_id );

		// Test mode saves the ad as a hidden draft: the careers page only
		// lists published posts, so nothing goes public. Re-sending the same
		// job after test mode is switched off publishes it normally.
		$postarr = [
			'post_type'    => $this->post_type,
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => $this->test_mode ? 'draft' : 'publish',
		];

		if ( $existing ) {
			$postarr['ID'] = $existing->ID;
			$post_id       = wp_update_post( $postarr, true );
			$status_code   = 200;
		} else {
			$post_id     = wp_insert_post( $postarr, true );
			$status_code = 201;
		}

		if ( is_wp_error( $post_id ) ) {
			return new WP_Error( 'careers_api_save_failed', $post_id->get_error_message(), [ 'status' => 500 ] );
		}

		update_post_meta( $post_id, $this->external_id_key, $external_id );
		update_post_meta( $post_id, self::META_LOCATION, $location );
		update_post_meta( $post_id, self::META_DEADLINE, $deadline );

		if ( $short !== '' ) {
			update_post_meta( $post_id, self::META_SHORT, $short );
		}

		return new WP_REST_Response( [
			'id'          => $post_id,
			'external_id' => $external_id,
			'post_status' => get_post_status( $post_id ),
			'permalink'   => get_permalink( $post_id ),
			'test_mode'   => $this->test_mode,
		], $status_code );
	}

	public function retract( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$external_id = $request->get_param( 'external_id' );
		$existing    = $this->find_by_external_id( $external_id );

		if ( ! $existing ) {
			return new WP_Error( 'not_found', 'No job ad found for that external_id.', [ 'status' => 404 ] );
		}

		if ( ! wp_trash_post( $existing->ID ) ) {
			return new WP_Error( 'careers_api_trash_failed', 'Could not trash the post.', [ 'status' => 500 ] );
		}

		return new WP_REST_Response( [
			'id'          => $existing->ID,
			'external_id' => $external_id,
			'post_status' => 'trash',
		], 200 );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function find_by_external_id( string $external_id ): ?WP_Post {
		// Explicit status list: 'any' would exclude trashed posts, and a
		// retracted job that gets re-sent must update the trashed post
		// rather than spawn a duplicate.
		$posts = get_posts( [
			'post_type'      => $this->post_type,
			'post_status'    => array_keys( get_post_stati() ),
			'posts_per_page' => 1,
			'meta_key'       => $this->external_id_key,
			'meta_value'     => $external_id,
			'no_found_rows'  => true,
		] );

		return $posts[0] ?? null;
	}

	private function upsert_args(): array {
		return [
			'title'                => [
				'required'          => true,
				'validate_callback' => fn( $v ) => is_string( $v ) && trim( $v ) !== '',
			],
			'description'          => [
				'required'          => true,
				'validate_callback' => fn( $v ) => is_string( $v ) && trim( $v ) !== '',
			],
			'short_description'    => [
				'required' => false,
			],
			'location'             => [
				'required'          => true,
				'validate_callback' => fn( $v ) => is_string( $v ) && preg_match( '/^[A-Za-z]{2}$/', $v ),
			],
			'application_deadline' => [
				'required'          => true,
				'validate_callback' => fn( $v ) => is_string( $v ) && DateTime::createFromFormat( 'Y-m-d', $v ) !== false,
			],
		];
	}
}