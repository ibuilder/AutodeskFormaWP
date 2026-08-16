<?php
/**
 * Administration screens.
 *
 * @package Forma_Publisher
 */

namespace Forma_Publisher\Admin;

use Forma_Publisher\Audit_Log;
use Forma_Publisher\Capabilities;
use Forma_Publisher\Post_Types;
use Forma_Publisher\Renderer;
use Forma_Publisher\Scheduler;
use Forma_Publisher\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the plugin admin menu, settings screen, connections screen and log viewer.
 *
 * @since 1.0.0
 */
class Admin {

	/**
	 * Top level menu slug.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const MENU_SLUG = 'publisher-for-autodesk-forma';

	/**
	 * Settings reader.
	 *
	 * @since 1.0.0
	 * @var Settings
	 */
	private $settings;

	/**
	 * Audit trail reader.
	 *
	 * @since 1.0.0
	 * @var Audit_Log
	 */
	private $audit_log;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Settings  $settings  Settings instance.
	 * @param Audit_Log $audit_log Audit log instance.
	 */
	public function __construct( Settings $settings, Audit_Log $audit_log ) {
		$this->settings  = $settings;
		$this->audit_log = $audit_log;
	}

	/**
	 * Hooks admin behaviour into WordPress.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_forma_publisher_add_connection', array( $this, 'handle_add_connection' ) );
		add_action( 'admin_post_forma_publisher_connection_action', array( $this, 'handle_connection_action' ) );
		add_action( 'admin_post_forma_publisher_clear_logs', array( $this, 'handle_clear_logs' ) );
		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
		add_filter( 'manage_' . Post_Types::PROJECT . '_posts_columns', array( $this, 'project_columns' ) );
		add_action( 'manage_' . Post_Types::PROJECT . '_posts_custom_column', array( $this, 'project_column_content' ), 10, 2 );
	}

	/**
	 * Registers the plugin admin menu.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Forma Publisher', 'publisher-for-autodesk-forma' ),
			__( 'Forma', 'publisher-for-autodesk-forma' ),
			'edit_forma_projects',
			self::MENU_SLUG,
			array( $this, 'render_settings_page' ),
			'dashicons-building',
			26
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Forma Publisher Settings', 'publisher-for-autodesk-forma' ),
			__( 'Settings', 'publisher-for-autodesk-forma' ),
			Capabilities::MANAGE,
			self::MENU_SLUG,
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Backend Connections', 'publisher-for-autodesk-forma' ),
			__( 'Connections', 'publisher-for-autodesk-forma' ),
			Capabilities::MANAGE,
			'forma-publisher-connections',
			array( $this, 'render_connections_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Publish Log', 'publisher-for-autodesk-forma' ),
			__( 'Publish Log', 'publisher-for-autodesk-forma' ),
			Capabilities::VIEW_LOGS,
			'forma-publisher-logs',
			array( $this, 'render_logs_page' )
		);
	}

	/**
	 * Renders the settings screen.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Forma Publisher settings.', 'publisher-for-autodesk-forma' ) );
		}

		$settings    = $this->settings->all();
		$connections = $this->settings->connections();
		$ingest_url  = rest_url( 'forma-publisher/v1/ingest' );
		?>
		<div class="wrap forma-publisher-admin">
			<h1><?php esc_html_e( 'Forma Publisher Settings', 'publisher-for-autodesk-forma' ); ?></h1>

			<?php $this->render_notice(); ?>

			<div class="forma-publisher-panel">
				<h2><?php esc_html_e( 'Ingest endpoint', 'publisher-for-autodesk-forma' ); ?></h2>
				<p><?php esc_html_e( 'Point the publishing backend at this signed endpoint:', 'publisher-for-autodesk-forma' ); ?></p>
				<p><code><?php echo esc_html( $ingest_url ); ?></code></p>
			</div>

			<form action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" method="post">
				<?php settings_fields( Settings::GROUP ); ?>

				<h2><?php esc_html_e( 'Publishing', 'publisher-for-autodesk-forma' ); ?></h2>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Editorial approval', 'publisher-for-autodesk-forma' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[require_approval]" value="1" <?php checked( ! empty( $settings['require_approval'] ) ); ?> />
									<?php esc_html_e( 'Hold newly published projects for review instead of publishing them immediately', 'publisher-for-autodesk-forma' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="forma-conflict-policy"><?php esc_html_e( 'When a project was edited here', 'publisher-for-autodesk-forma' ); ?></label>
							</th>
							<td>
								<select id="forma-conflict-policy" name="<?php echo esc_attr( Settings::OPTION ); ?>[conflict_policy]">
									<?php foreach ( Settings::conflict_policies() as $value => $label ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['conflict_policy'], $value ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Applies when a project changed in WordPress after the last synchronization, so an incoming update would discard that work.', 'publisher-for-autodesk-forma' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="forma-default-status"><?php esc_html_e( 'Default post status', 'publisher-for-autodesk-forma' ); ?></label>
							</th>
							<td>
								<select id="forma-default-status" name="<?php echo esc_attr( Settings::OPTION ); ?>[default_post_status]">
									<?php
									$statuses = array(
										'draft'   => __( 'Draft', 'publisher-for-autodesk-forma' ),
										'pending' => __( 'Pending review', 'publisher-for-autodesk-forma' ),
										'publish' => __( 'Published', 'publisher-for-autodesk-forma' ),
										'private' => __( 'Private', 'publisher-for-autodesk-forma' ),
									);

									foreach ( $statuses as $value => $label ) :
										?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['default_post_status'], $value ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Used when the incoming payload does not specify a status.', 'publisher-for-autodesk-forma' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Security', 'publisher-for-autodesk-forma' ); ?></h2>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Transport', 'publisher-for-autodesk-forma' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[require_https]" value="1" <?php checked( ! empty( $settings['require_https'] ) ); ?> />
									<?php esc_html_e( 'Reject publish requests that are not sent over HTTPS', 'publisher-for-autodesk-forma' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="forma-tolerance"><?php esc_html_e( 'Timestamp tolerance', 'publisher-for-autodesk-forma' ); ?></label>
							</th>
							<td>
								<input id="forma-tolerance" type="number" min="30" max="3600" step="10"
									name="<?php echo esc_attr( Settings::OPTION ); ?>[timestamp_tolerance]"
									value="<?php echo esc_attr( (string) $settings['timestamp_tolerance'] ); ?>" class="small-text" />
								<?php esc_html_e( 'seconds', 'publisher-for-autodesk-forma' ); ?>
								<p class="description"><?php esc_html_e( 'Requests signed outside this window are rejected as stale.', 'publisher-for-autodesk-forma' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Media', 'publisher-for-autodesk-forma' ); ?></h2>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Remote media import', 'publisher-for-autodesk-forma' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[allow_media_import]" value="1" <?php checked( ! empty( $settings['allow_media_import'] ) ); ?> />
									<?php esc_html_e( 'Download featured images referenced in publish payloads', 'publisher-for-autodesk-forma' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Images are only downloaded from hosts listed below.', 'publisher-for-autodesk-forma' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="forma-media-hosts"><?php esc_html_e( 'Allowed media hosts', 'publisher-for-autodesk-forma' ); ?></label>
							</th>
							<td>
								<textarea id="forma-media-hosts" rows="4" class="large-text code"
									name="<?php echo esc_attr( Settings::OPTION ); ?>[media_allowed_hosts]"><?php echo esc_textarea( implode( "\n", (array) $settings['media_allowed_hosts'] ) ); ?></textarea>
								<p class="description"><?php esc_html_e( 'One host name per line, for example developer.api.autodesk.com', 'publisher-for-autodesk-forma' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Synchronization', 'publisher-for-autodesk-forma' ); ?></h2>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row">
								<label for="forma-backend-url"><?php esc_html_e( 'Backend service URL', 'publisher-for-autodesk-forma' ); ?></label>
							</th>
							<td>
								<input id="forma-backend-url" type="url" class="regular-text code"
									name="<?php echo esc_attr( Settings::OPTION ); ?>[backend_url]"
									value="<?php echo esc_attr( (string) $settings['backend_url'] ); ?>" />
								<p class="description"><?php esc_html_e( 'Base URL of the publishing backend, used to request scheduled refreshes.', 'publisher-for-autodesk-forma' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="forma-sync-interval"><?php esc_html_e( 'Refresh interval', 'publisher-for-autodesk-forma' ); ?></label>
							</th>
							<td>
								<select id="forma-sync-interval" name="<?php echo esc_attr( Settings::OPTION ); ?>[sync_interval]">
									<?php foreach ( Scheduler::intervals() as $value => $label ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['sync_interval'], $value ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="forma-sync-connection"><?php esc_html_e( 'Signing connection', 'publisher-for-autodesk-forma' ); ?></label>
							</th>
							<td>
								<select id="forma-sync-connection" name="<?php echo esc_attr( Settings::OPTION ); ?>[sync_connection]">
									<option value=""><?php esc_html_e( '— None —', 'publisher-for-autodesk-forma' ); ?></option>
									<?php foreach ( $connections as $key_id => $connection ) : ?>
										<option value="<?php echo esc_attr( $key_id ); ?>" <?php selected( $settings['sync_connection'], $key_id ); ?>>
											<?php echo esc_html( $connection['label'] . ' (' . $key_id . ')' ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Refresh requests sent to the backend are signed with this connection secret.', 'publisher-for-autodesk-forma' ); ?></p>
							</td>
						</tr>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Audit log', 'publisher-for-autodesk-forma' ); ?></h2>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Logging', 'publisher-for-autodesk-forma' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION ); ?>[enable_logging]" value="1" <?php checked( ! empty( $settings['enable_logging'] ) ); ?> />
									<?php esc_html_e( 'Record every publish, update, unpublish and archive request', 'publisher-for-autodesk-forma' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="forma-log-retention"><?php esc_html_e( 'Retention', 'publisher-for-autodesk-forma' ); ?></label>
							</th>
							<td>
								<input id="forma-log-retention" type="number" min="1" max="365"
									name="<?php echo esc_attr( Settings::OPTION ); ?>[log_retention_days]"
									value="<?php echo esc_attr( (string) $settings['log_retention_days'] ); ?>" class="small-text" />
								<?php esc_html_e( 'days', 'publisher-for-autodesk-forma' ); ?>
							</td>
						</tr>
					</tbody>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Renders the connections screen.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_connections_page() {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Forma Publisher connections.', 'publisher-for-autodesk-forma' ) );
		}

		$connections = $this->settings->connections();
		$new_secret  = get_transient( 'forma_publisher_new_secret_' . get_current_user_id() );

		if ( is_array( $new_secret ) ) {
			delete_transient( 'forma_publisher_new_secret_' . get_current_user_id() );
		}
		?>
		<div class="wrap forma-publisher-admin">
			<h1><?php esc_html_e( 'Backend Connections', 'publisher-for-autodesk-forma' ); ?></h1>

			<?php $this->render_notice(); ?>

			<?php if ( is_array( $new_secret ) ) : ?>
				<div class="notice notice-success">
					<p><strong><?php esc_html_e( 'Connection created. Copy the shared secret now — it is not shown again.', 'publisher-for-autodesk-forma' ); ?></strong></p>
					<p>
						<?php esc_html_e( 'Key ID', 'publisher-for-autodesk-forma' ); ?>:
						<code><?php echo esc_html( $new_secret['key_id'] ); ?></code>
					</p>
					<p>
						<?php esc_html_e( 'Shared secret', 'publisher-for-autodesk-forma' ); ?>:
						<code class="forma-publisher-secret"><?php echo esc_html( $new_secret['secret'] ); ?></code>
					</p>
				</div>
			<?php endif; ?>

			<div class="forma-publisher-panel">
				<h2><?php esc_html_e( 'Add a connection', 'publisher-for-autodesk-forma' ); ?></h2>
				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
					<input type="hidden" name="action" value="forma_publisher_add_connection" />
					<?php wp_nonce_field( 'forma_publisher_add_connection' ); ?>
					<p>
						<label for="forma-connection-label"><?php esc_html_e( 'Label', 'publisher-for-autodesk-forma' ); ?></label><br />
						<input id="forma-connection-label" type="text" class="regular-text" name="label" maxlength="120" required />
					</p>
					<?php submit_button( __( 'Create connection', 'publisher-for-autodesk-forma' ), 'primary', 'submit', false ); ?>
				</form>
			</div>

			<h2><?php esc_html_e( 'Existing connections', 'publisher-for-autodesk-forma' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Label', 'publisher-for-autodesk-forma' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Key ID', 'publisher-for-autodesk-forma' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'publisher-for-autodesk-forma' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Last used', 'publisher-for-autodesk-forma' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Actions', 'publisher-for-autodesk-forma' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $connections ) ) : ?>
						<tr>
							<td colspan="5"><?php esc_html_e( 'No connections have been created yet.', 'publisher-for-autodesk-forma' ); ?></td>
						</tr>
					<?php endif; ?>

					<?php foreach ( $connections as $key_id => $connection ) : ?>
						<tr>
							<td><?php echo esc_html( $connection['label'] ); ?></td>
							<td><code><?php echo esc_html( $key_id ); ?></code></td>
							<td>
								<?php
								if ( 'constant' === $connection['source'] ) {
									esc_html_e( 'Defined in wp-config.php', 'publisher-for-autodesk-forma' );
								} elseif ( ! empty( $connection['enabled'] ) ) {
									esc_html_e( 'Enabled', 'publisher-for-autodesk-forma' );
								} else {
									esc_html_e( 'Disabled', 'publisher-for-autodesk-forma' );
								}
								?>
							</td>
							<td>
								<?php
								if ( empty( $connection['last_used'] ) ) {
									esc_html_e( 'Never', 'publisher-for-autodesk-forma' );
								} else {
									echo esc_html(
										wp_date(
											get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
											(int) $connection['last_used']
										)
									);
								}
								?>
							</td>
							<td>
								<?php if ( 'constant' === $connection['source'] ) : ?>
									<span class="description"><?php esc_html_e( 'Managed in code', 'publisher-for-autodesk-forma' ); ?></span>
								<?php else : ?>
									<a href="<?php echo esc_url( $this->action_url( $key_id, ! empty( $connection['enabled'] ) ? 'disable' : 'enable' ) ); ?>">
										<?php
										if ( ! empty( $connection['enabled'] ) ) {
											esc_html_e( 'Disable', 'publisher-for-autodesk-forma' );
										} else {
											esc_html_e( 'Enable', 'publisher-for-autodesk-forma' );
										}
										?>
									</a>
									|
									<a href="<?php echo esc_url( $this->action_url( $key_id, 'delete' ) ); ?>" class="submitdelete">
										<?php esc_html_e( 'Delete', 'publisher-for-autodesk-forma' ); ?>
									</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Renders the publish log screen.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function render_logs_page() {
		if ( ! current_user_can( Capabilities::VIEW_LOGS ) ) {
			wp_die( esc_html__( 'You do not have permission to view the Forma Publisher log.', 'publisher-for-autodesk-forma' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read only pagination parameter.
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		$per_page = 25;
		$log      = $this->audit_log->entries( $paged, $per_page );
		?>
		<div class="wrap forma-publisher-admin">
			<h1><?php esc_html_e( 'Publish Log', 'publisher-for-autodesk-forma' ); ?></h1>

			<?php $this->render_notice(); ?>

			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'When', 'publisher-for-autodesk-forma' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Operation', 'publisher-for-autodesk-forma' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Result', 'publisher-for-autodesk-forma' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Source ID', 'publisher-for-autodesk-forma' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Connection', 'publisher-for-autodesk-forma' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Details', 'publisher-for-autodesk-forma' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $log['items'] ) ) : ?>
						<tr>
							<td colspan="6"><?php esc_html_e( 'No publish activity has been recorded yet.', 'publisher-for-autodesk-forma' ); ?></td>
						</tr>
					<?php endif; ?>

					<?php foreach ( $log['items'] as $entry ) : ?>
						<?php
						$result  = (string) get_post_meta( $entry->ID, '_forma_log_result', true );
						$post_id = (int) get_post_meta( $entry->ID, '_forma_log_post_id', true );
						?>
						<tr>
							<td><?php echo esc_html( get_the_time( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $entry ) ); ?></td>
							<td><?php echo esc_html( (string) get_post_meta( $entry->ID, '_forma_log_operation', true ) ); ?></td>
							<td>
								<span class="forma-publisher-result forma-publisher-result--<?php echo esc_attr( sanitize_html_class( $result ) ); ?>">
									<?php echo esc_html( $this->result_label( $result ) ); ?>
								</span>
							</td>
							<td><code><?php echo esc_html( (string) get_post_meta( $entry->ID, '_forma_log_source_id', true ) ); ?></code></td>
							<td><code><?php echo esc_html( (string) get_post_meta( $entry->ID, '_forma_log_connection', true ) ); ?></code></td>
							<td>
								<?php echo esc_html( (string) get_post_meta( $entry->ID, '_forma_log_message', true ) ); ?>
								<?php if ( $post_id > 0 ) : ?>
									<br />
									<a href="<?php echo esc_url( (string) get_edit_post_link( $post_id ) ); ?>">
										<?php esc_html_e( 'Open project', 'publisher-for-autodesk-forma' ); ?>
									</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php
			$total_pages = (int) ceil( $log['total'] / $per_page );

			if ( $total_pages > 1 ) {
				$links = paginate_links(
					array(
						'base'      => add_query_arg( 'paged', '%#%' ),
						'format'    => '',
						'current'   => $paged,
						'total'     => $total_pages,
						'type'      => 'plain',
						'prev_text' => __( '&laquo; Previous', 'publisher-for-autodesk-forma' ),
						'next_text' => __( 'Next &raquo;', 'publisher-for-autodesk-forma' ),
					)
				);

				if ( $links ) {
					echo '<p class="forma-publisher-pagination">' . wp_kses_post( $links ) . '</p>';
				}
			}
			?>

			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<input type="hidden" name="action" value="forma_publisher_clear_logs" />
				<?php wp_nonce_field( 'forma_publisher_clear_logs' ); ?>
				<?php submit_button( __( 'Delete all log entries', 'publisher-for-autodesk-forma' ), 'delete', 'submit', true ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handles the create connection form submission.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_add_connection() {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Forma Publisher connections.', 'publisher-for-autodesk-forma' ) );
		}

		check_admin_referer( 'forma_publisher_add_connection' );

		$label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';

		if ( '' === $label ) {
			$this->redirect_with_notice( 'forma-publisher-connections', 'missing_label' );
		}

		$created = $this->settings->create_connection( $label );

		set_transient( 'forma_publisher_new_secret_' . get_current_user_id(), $created, 5 * MINUTE_IN_SECONDS );

		$this->audit_log->log(
			array(
				'operation'  => 'connection_created',
				'result'     => 'success',
				'connection' => $created['key_id'],
				'message'    => $label,
			)
		);

		$this->redirect_with_notice( 'forma-publisher-connections', 'connection_created' );
	}

	/**
	 * Handles enable, disable and delete actions on a connection.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_connection_action() {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Forma Publisher connections.', 'publisher-for-autodesk-forma' ) );
		}

		$key_id = isset( $_REQUEST['key_id'] ) ? sanitize_key( wp_unslash( $_REQUEST['key_id'] ) ) : '';
		$task   = isset( $_REQUEST['task'] ) ? sanitize_key( wp_unslash( $_REQUEST['task'] ) ) : '';

		check_admin_referer( 'forma_publisher_connection_' . $task . '_' . $key_id );

		if ( '' === $key_id ) {
			$this->redirect_with_notice( 'forma-publisher-connections', 'unknown_connection' );
		}

		switch ( $task ) {
			case 'enable':
				$this->settings->set_connection_enabled( $key_id, true );
				$notice = 'connection_enabled';
				break;
			case 'disable':
				$this->settings->set_connection_enabled( $key_id, false );
				$notice = 'connection_disabled';
				break;
			case 'delete':
				$this->settings->delete_connection( $key_id );
				$notice = 'connection_deleted';
				break;
			default:
				$notice = 'unknown_connection';
		}

		$this->audit_log->log(
			array(
				'operation'  => 'connection_' . $task,
				'result'     => 'unknown_connection' === $notice ? 'error' : 'success',
				'connection' => $key_id,
			)
		);

		$this->redirect_with_notice( 'forma-publisher-connections', $notice );
	}

	/**
	 * Handles the delete all logs form submission.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function handle_clear_logs() {
		if ( ! current_user_can( Capabilities::VIEW_LOGS ) ) {
			wp_die( esc_html__( 'You do not have permission to manage the Forma Publisher log.', 'publisher-for-autodesk-forma' ) );
		}

		check_admin_referer( 'forma_publisher_clear_logs' );

		$this->audit_log->purge_all();

		$this->redirect_with_notice( 'forma-publisher-logs', 'logs_cleared' );
	}

	/**
	 * Registers the read only sync meta box on the project editor.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_meta_boxes() {
		add_meta_box(
			'forma-publisher-sync',
			__( 'Forma synchronization', 'publisher-for-autodesk-forma' ),
			array( $this, 'render_sync_meta_box' ),
			Post_Types::PROJECT,
			'side',
			'default'
		);
	}

	/**
	 * Renders the read only sync meta box.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Post $post Current post.
	 * @return void
	 */
	public function render_sync_meta_box( $post ) {
		$fields = array(
			__( 'Source ID', 'publisher-for-autodesk-forma' )         => (string) get_post_meta( $post->ID, Post_Types::META_SOURCE_ID, true ),
			__( 'Source system', 'publisher-for-autodesk-forma' )     => (string) get_post_meta( $post->ID, '_forma_source_system', true ),
			__( 'Sync mode', 'publisher-for-autodesk-forma' )         => (string) get_post_meta( $post->ID, '_forma_sync_mode', true ),
			__( 'Publish state', 'publisher-for-autodesk-forma' )     => (string) get_post_meta( $post->ID, '_forma_publish_state', true ),
			__( 'Connection', 'publisher-for-autodesk-forma' )        => (string) get_post_meta( $post->ID, '_forma_connection_id', true ),
			__( 'Last synchronized', 'publisher-for-autodesk-forma' ) => (string) get_post_meta( $post->ID, '_forma_last_synced', true ),
		);

		echo '<ul class="forma-publisher-meta">';

		foreach ( $fields as $label => $value ) {
			if ( '' === $value ) {
				$value = '—';
			}

			printf(
				'<li><strong>%1$s</strong><br /><code>%2$s</code></li>',
				esc_html( $label ),
				esc_html( $value )
			);
		}

		echo '</ul>';

		$source_url = (string) get_post_meta( $post->ID, '_forma_source_url', true );

		if ( '' !== $source_url ) {
			printf(
				'<p><a href="%1$s" rel="nofollow noopener external" target="_blank">%2$s</a></p>',
				esc_url( $source_url ),
				esc_html__( 'View in Autodesk Forma', 'publisher-for-autodesk-forma' )
			);
		}
	}

	/**
	 * Adds plugin columns to the project list table.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,string> $columns Existing columns.
	 * @return array<string,string> Columns including plugin columns.
	 */
	public function project_columns( $columns ) {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}

		$columns['forma_source']  = __( 'Source ID', 'publisher-for-autodesk-forma' );
		$columns['forma_synced']  = __( 'Last synced', 'publisher-for-autodesk-forma' );
		$columns['forma_metrics'] = __( 'Metrics', 'publisher-for-autodesk-forma' );

		return $columns;
	}

	/**
	 * Renders plugin column values in the project list table.
	 *
	 * @since 1.0.0
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post id.
	 * @return void
	 */
	public function project_column_content( $column, $post_id ) {
		switch ( $column ) {
			case 'forma_source':
				echo '<code>' . esc_html( (string) get_post_meta( $post_id, Post_Types::META_SOURCE_ID, true ) ) . '</code>';
				break;
			case 'forma_synced':
				$synced = (string) get_post_meta( $post_id, '_forma_last_synced', true );
				$stamp  = '' !== $synced ? strtotime( $synced ) : false;

				if ( $stamp ) {
					echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $stamp ) );
				} else {
					echo '—';
				}
				break;
			case 'forma_metrics':
				echo esc_html( (string) count( Renderer::metrics_for( $post_id ) ) );
				break;
		}
	}

	/**
	 * Builds a nonce protected connection action URL.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key_id Connection key id.
	 * @param string $task   Action name.
	 * @return string Action URL.
	 */
	private function action_url( $key_id, $task ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'forma_publisher_connection_action',
					'key_id' => $key_id,
					'task'   => $task,
				),
				admin_url( 'admin-post.php' )
			),
			'forma_publisher_connection_' . $task . '_' . $key_id
		);
	}

	/**
	 * Redirects back to a plugin screen with a notice code.
	 *
	 * @since 1.0.0
	 *
	 * @param string $page   Admin page slug.
	 * @param string $notice Notice code.
	 * @return void
	 */
	private function redirect_with_notice( $page, $notice ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => $page,
					'forma_notice' => $notice,
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * Prints the notice matching the current request.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function render_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read only notice code from a redirect performed by this plugin.
		$code = isset( $_GET['forma_notice'] ) ? sanitize_key( wp_unslash( $_GET['forma_notice'] ) ) : '';

		if ( '' === $code ) {
			return;
		}

		$messages = array(
			'connection_created'  => array( 'success', __( 'Connection created.', 'publisher-for-autodesk-forma' ) ),
			'connection_enabled'  => array( 'success', __( 'Connection enabled.', 'publisher-for-autodesk-forma' ) ),
			'connection_disabled' => array( 'success', __( 'Connection disabled.', 'publisher-for-autodesk-forma' ) ),
			'connection_deleted'  => array( 'success', __( 'Connection deleted.', 'publisher-for-autodesk-forma' ) ),
			'logs_cleared'        => array( 'success', __( 'Publish log cleared.', 'publisher-for-autodesk-forma' ) ),
			'missing_label'       => array( 'error', __( 'Please provide a label for the connection.', 'publisher-for-autodesk-forma' ) ),
			'unknown_connection'  => array( 'error', __( 'That connection could not be found.', 'publisher-for-autodesk-forma' ) ),
		);

		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $messages[ $code ][0] ),
			esc_html( $messages[ $code ][1] )
		);
	}

	/**
	 * Returns a translated label for a log result code.
	 *
	 * @since 1.0.0
	 *
	 * @param string $result Result code.
	 * @return string Translated label.
	 */
	private function result_label( $result ) {
		switch ( $result ) {
			case 'success':
				return __( 'Success', 'publisher-for-autodesk-forma' );
			case 'error':
				return __( 'Error', 'publisher-for-autodesk-forma' );
			case 'skipped':
				return __( 'Skipped', 'publisher-for-autodesk-forma' );
			default:
				return __( 'Unknown', 'publisher-for-autodesk-forma' );
		}
	}
}
