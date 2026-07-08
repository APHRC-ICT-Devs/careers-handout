<?php
if ( ! defined( 'WPINC' ) ) {
	die;
}

class Careers_API_Admin {

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_post_careers_api_save', [ $this, 'save_settings' ] );
	}

	public function add_menu() {
		add_options_page(
			'Careers API Settings',
			'Careers API',
			'manage_options',
			'careers-api',
			[ $this, 'render_page' ]
		);
	}

	public function save_settings() {
		check_admin_referer( 'careers_api_save' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		$raw = $_POST['careers_api'] ?? [];

		$meta_key = sanitize_key( $raw['external_id_meta_key'] ?? '_careers_api_job_id' ) ?: '_careers_api_job_id';
		// Force a leading underscore: WordPress treats underscore-prefixed
		// meta as protected (hidden from editing screens), which stops
		// lower-privileged users tampering with the job matching.
		if ( $meta_key[0] !== '_' ) {
			$meta_key = '_' . $meta_key;
		}

		$settings = [
			'enabled'              => ! empty( $raw['enabled'] ),
			'test_mode'            => ! empty( $raw['test_mode'] ),
			'post_type'            => sanitize_key( $raw['post_type'] ?? 'career' ) ?: 'career',
			'endpoint_slug'        => sanitize_title( $raw['endpoint_slug'] ?? 'jobs' ) ?: 'jobs',
			'external_id_meta_key' => $meta_key,
		];

		update_option( CAREERS_API_OPTION, $settings );
		wp_redirect( admin_url( 'options-general.php?page=careers-api&saved=1' ) );
		exit;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = array_merge( [
			'enabled'              => false,
			'test_mode'            => false,
			'post_type'            => 'career',
			'endpoint_slug'        => 'jobs',
			'external_id_meta_key' => '_careers_api_job_id',
		], get_option( CAREERS_API_OPTION, [] ) );

		$post_types = get_post_types( [ 'public' => true ], 'objects' );
		$base_url   = rest_url( CAREERS_API_NAMESPACE . '/' . $settings['endpoint_slug'] . '/' );

		?>
		<div class="wrap">
			<h1>Careers API</h1>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
			<?php endif; ?>

			<?php if ( $settings['enabled'] && $settings['test_mode'] ) : ?>
				<div class="notice notice-warning">
					<p><strong>Test Mode is on:</strong> incoming job ads are saved as hidden drafts and will
					<em>not</em> appear on the careers page. Switch it off before go-live.</p>
				</div>
			<?php endif; ?>

			<p>
				Lets an external system (the MS Dynamics job portal) create, update, and retract job ads
				via authenticated <code>PUT</code>/<code>DELETE</code> requests. This plugin registers
				only these write routes — it is fully separate from the read-only mel API.
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="careers_api_save">
				<?php wp_nonce_field( 'careers_api_save' ); ?>

				<table class="form-table">
					<tr>
						<th scope="row">Enable</th>
						<td>
							<label>
								<input type="checkbox" name="careers_api[enabled]" value="1"
								       <?php checked( $settings['enabled'] ); ?>>
								Accept job ad writes from the job portal
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">Test Mode</th>
						<td>
							<label>
								<input type="checkbox" name="careers_api[test_mode]" value="1"
								       <?php checked( $settings['test_mode'] ); ?>>
								Save incoming ads as <strong>hidden drafts</strong> instead of publishing them
							</label>
							<p class="description">
								For safe testing on a live site: drafts never appear on the careers page and are
								only viewable by logged-in admins/editors (open them under the career post type
								to preview). Switch off to go live — when the job portal re-sends a job, its
								draft is published normally.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Target Post Type</th>
						<td>
							<select name="careers_api[post_type]">
								<?php foreach ( $post_types as $pt ) : ?>
									<option value="<?php echo esc_attr( $pt->name ); ?>"
									        <?php selected( $settings['post_type'], $pt->name ); ?>>
										<?php echo esc_html( $pt->labels->name ); ?> (<?php echo esc_html( $pt->name ); ?>)
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row">Endpoint Slug</th>
						<td>
							<input type="text" name="careers_api[endpoint_slug]"
							       value="<?php echo esc_attr( $settings['endpoint_slug'] ); ?>"
							       class="regular-text">
							<p class="description">
								Access URL: <code><?php echo esc_html( $base_url ); ?><em>{external_id}</em></code>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">External ID Meta Key</th>
						<td>
							<input type="text" name="careers_api[external_id_meta_key]"
							       value="<?php echo esc_attr( $settings['external_id_meta_key'] ); ?>"
							       class="regular-text code">
							<p class="description">
								Matches a Dynamics job ID to a WordPress post, so re-sending the same job
								updates it instead of creating a duplicate. A leading underscore is enforced
								so the field stays protected from manual editing.
							</p>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary">Save Settings</button>
				</p>
			</form>

			<hr>

			<h2>Setup: authorizing the job portal</h2>
			<ol>
				<li>Users &rarr; Add New &rarr; create a user for the job portal, with role <strong>Job Portal Integration</strong>.</li>
				<li>Log in as that user &rarr; Profile &rarr; Application Passwords &rarr; generate one named e.g. "Dynamics Job Portal".</li>
				<li>Give the job portal this site's URL, that username, and the generated application password (shown once).</li>
			</ol>
			<p class="description">
				Application Passwords require HTTPS and won't be available until the site is served over it.
			</p>

			<h2>Request format</h2>
			<table class="widefat striped" style="max-width:800px">
				<thead><tr><th>Method</th><th>Route</th><th>Purpose</th></tr></thead>
				<tbody>
					<tr>
						<td><code>PUT</code></td>
						<td><code><?php echo esc_html( $base_url ); ?>{external_id}</code></td>
						<td>Create or update a job ad, matched by the sender's own external_id</td>
					</tr>
					<tr>
						<td><code>DELETE</code></td>
						<td><code><?php echo esc_html( $base_url ); ?>{external_id}</code></td>
						<td>Retract a job ad (moves it to trash)</td>
					</tr>
				</tbody>
			</table>
			<p class="description" style="max-width:800px">
				<code>PUT</code> JSON body: <code>title</code>, <code>description</code> (HTML),
				<code>short_description</code> (optional, HTML), <code>location</code> (2-letter country
				code), <code>application_deadline</code> (<code>YYYY-MM-DD</code>). To close a job ad,
				<code>PUT</code> again with <code>application_deadline</code> set to a past date.
				Full documentation lives in this plugin's <code>docs/</code> folder.
			</p>
		</div>
		<?php
	}
}
