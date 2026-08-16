<?php
/**
 * Operator dashboard and editorial review screen.
 *
 * @package Forma_Publisher
 */

namespace Forma_Publisher\Admin;

use Forma_Publisher\Audit_Log;
use Forma_Publisher\Capabilities;
use Forma_Publisher\Ingest_Service;
use Forma_Publisher\Post_Types;
use Forma_Publisher\Repository;
use Forma_Publisher\Review;
use Forma_Publisher\Scheduler;
use Forma_Publisher\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Gives an operator one screen that answers "is this working?".
 *
 * @since 1.1.0
 */
class Dashboard {

	/**
	 * Settings reader.
	 *
	 * @since 1.1.0
	 * @var Settings
	 */
	private $settings;

	/**
	 * Audit trail reader.
	 *
	 * @since 1.1.0
	 * @var Audit_Log
	 */
	private $audit_log;

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 *
	 * @param Settings  $settings  Settings instance.
	 * @param Audit_Log $audit_log Audit log instance.
	 */
	public function __construct( Settings $settings, Audit_Log $audit_log ) {
		$this->settings  = $settings;
		$this->audit_log = $audit_log;
	}

	/**
	 * Hooks the screens into WordPress.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 5 );
		add_action( 'admin_post_forma_publisher_review_action', array( $this, 'handle_review_action' ) );
	}

	/**
	 * Registers the dashboard and review screens.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			Admin::MENU_SLUG,
			__( 'Forma Publisher Overview', 'publisher-for-autodesk-forma' ),
			__( 'Overview', 'publisher-for-autodesk-forma' ),
			'edit_forma_projects',
			'forma-publisher-overview',
			array( $this, 'render_overview' )
		);

		$attention = Review::attention_count();

		$label = __( 'Review', 'publisher-for-autodesk-forma' );

		if ( $attention > 0 ) {
			$label .= sprintf(
				' <span class="update-plugins count-%1$d"><span class="update-count">%2$s</span></span>',
				$attention,
				esc_html( number_format_i18n( $attention ) )
			);
		}

		add_submenu_page(
			Admin::MENU_SLUG,
			__( 'Editorial Review', 'publisher-for-autodesk-forma' ),
			$label,
			'edit_forma_projects',
			'forma-publisher-review',
			array( $this, 'render_review' )
		);
	}

	/**
	 * Renders the operator overview.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function render_overview() {
		if ( ! current_user_can( 'edit_forma_projects' ) ) {
			wp_die( esc_html__( 'You do not have permission to view Forma Publisher.', 'publisher-for-autodesk-forma' ) );
		}

		$counts      = wp_count_posts( Post_Types::PROJECT );
		$connections = $this->settings->connections();
		$enabled     = array_filter(
			$connections,
			static function ( $connection ) {
				return ! empty( $connection['enabled'] );
			}
		);

		$log        = $this->audit_log->entries( 1, 20 );
		$last_used  = 0;
		$failures   = 0;
		$last_error = '';

		foreach ( $connections as $connection ) {
			$last_used = max( $last_used, (int) $connection['last_used'] );
		}

		foreach ( $log['items'] as $entry ) {
			if ( 'error' === (string) get_post_meta( $entry->ID, '_forma_log_result', true ) ) {
				++$failures;

				if ( '' === $last_error ) {
					$last_error = (string) get_post_meta( $entry->ID, '_forma_log_message', true );
				}
			}
		}

		$sync_interval = (string) $this->settings->get( 'sync_interval', 'none' );
		$next_sync     = wp_next_scheduled( Scheduler::SYNC_HOOK );
		$next_purge    = wp_next_scheduled( Audit_Log::CLEANUP_HOOK );
		$attention     = Review::attention_count();
		$date_format   = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<div class="wrap forma-publisher-admin">
			<h1><?php esc_html_e( 'Forma Publisher Overview', 'publisher-for-autodesk-forma' ); ?></h1>

			<?php if ( empty( $enabled ) ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php esc_html_e( 'No enabled connection exists yet, so no backend can publish to this site.', 'publisher-for-autodesk-forma' ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=forma-publisher-connections' ) ); ?>">
							<?php esc_html_e( 'Create one', 'publisher-for-autodesk-forma' ); ?>
						</a>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( $attention > 0 ) : ?>
				<div class="notice notice-info">
					<p>
						<?php
						printf(
							/* translators: %s: number of items awaiting review. */
							esc_html( _n( '%s project needs editorial attention.', '%s projects need editorial attention.', $attention, 'publisher-for-autodesk-forma' ) ),
							esc_html( number_format_i18n( $attention ) )
						);
						?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=forma-publisher-review' ) ); ?>">
							<?php esc_html_e( 'Review now', 'publisher-for-autodesk-forma' ); ?>
						</a>
					</p>
				</div>
			<?php endif; ?>

			<div class="forma-publisher-stats">
				<?php
				$tiles = array(
					array(
						'label' => __( 'Published', 'publisher-for-autodesk-forma' ),
						'value' => isset( $counts->publish ) ? (int) $counts->publish : 0,
					),
					array(
						'label' => __( 'Awaiting review', 'publisher-for-autodesk-forma' ),
						'value' => $attention,
					),
					array(
						'label' => __( 'Drafts', 'publisher-for-autodesk-forma' ),
						'value' => isset( $counts->draft ) ? (int) $counts->draft : 0,
					),
					array(
						'label' => __( 'Enabled connections', 'publisher-for-autodesk-forma' ),
						'value' => count( $enabled ),
					),
					array(
						'label' => __( 'Recent failures', 'publisher-for-autodesk-forma' ),
						'value' => $failures,
					),
				);

				foreach ( $tiles as $tile ) :
					?>
					<div class="forma-publisher-stat">
						<span class="forma-publisher-stat__value"><?php echo esc_html( number_format_i18n( $tile['value'] ) ); ?></span>
						<span class="forma-publisher-stat__label"><?php echo esc_html( $tile['label'] ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>

			<?php if ( '' !== $last_error ) : ?>
				<div class="notice notice-error">
					<p>
						<strong><?php esc_html_e( 'Most recent failure:', 'publisher-for-autodesk-forma' ); ?></strong>
						<?php echo esc_html( $last_error ); ?>
					</p>
				</div>
			<?php endif; ?>

			<div class="forma-publisher-panel">
				<h2><?php esc_html_e( 'Pipeline', 'publisher-for-autodesk-forma' ); ?></h2>
				<table class="widefat striped">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Ingest endpoint', 'publisher-for-autodesk-forma' ); ?></th>
							<td><code><?php echo esc_html( rest_url( 'forma-publisher/v1/ingest' ) ); ?></code></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Last accepted publish', 'publisher-for-autodesk-forma' ); ?></th>
							<td>
								<?php
								if ( $last_used > 0 ) {
									echo esc_html( wp_date( $date_format, $last_used ) );
								} else {
									esc_html_e( 'No publish has been accepted yet.', 'publisher-for-autodesk-forma' );
								}
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Scheduled refresh', 'publisher-for-autodesk-forma' ); ?></th>
							<td>
								<?php
								if ( 'none' === $sync_interval ) {
									esc_html_e( 'Disabled', 'publisher-for-autodesk-forma' );
								} elseif ( $next_sync ) {
									printf(
										/* translators: 1: interval name, 2: formatted date and time. */
										esc_html__( '%1$s, next run %2$s', 'publisher-for-autodesk-forma' ),
										esc_html( $sync_interval ),
										esc_html( wp_date( $date_format, $next_sync ) )
									);
								} else {
									esc_html_e( 'Enabled but not scheduled. Visit any admin page to reschedule.', 'publisher-for-autodesk-forma' );
								}
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Log cleanup', 'publisher-for-autodesk-forma' ); ?></th>
							<td>
								<?php
								if ( $next_purge ) {
									echo esc_html( wp_date( $date_format, $next_purge ) );
								} else {
									esc_html_e( 'Not scheduled', 'publisher-for-autodesk-forma' );
								}
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Local edit policy', 'publisher-for-autodesk-forma' ); ?></th>
							<td>
								<?php
								$policies = Settings::conflict_policies();
								$policy   = (string) $this->settings->get( 'conflict_policy', 'hold' );

								echo esc_html( isset( $policies[ $policy ] ) ? $policies[ $policy ] : $policy );
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'WP-Cron', 'publisher-for-autodesk-forma' ); ?></th>
							<td>
								<?php
								if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
									esc_html_e( 'Disabled in configuration. Ensure a system cron calls wp-cron.php.', 'publisher-for-autodesk-forma' );
								} else {
									esc_html_e( 'Enabled. It only fires on site traffic, so a quiet site may refresh late.', 'publisher-for-autodesk-forma' );
								}
								?>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="forma-publisher-panel">
				<h2><?php esc_html_e( 'Recent activity', 'publisher-for-autodesk-forma' ); ?></h2>
				<?php if ( empty( $log['items'] ) ) : ?>
					<p><?php esc_html_e( 'No publish activity has been recorded yet.', 'publisher-for-autodesk-forma' ); ?></p>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'When', 'publisher-for-autodesk-forma' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Operation', 'publisher-for-autodesk-forma' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Result', 'publisher-for-autodesk-forma' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Source ID', 'publisher-for-autodesk-forma' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( array_slice( $log['items'], 0, 10 ) as $entry ) : ?>
								<?php $result = (string) get_post_meta( $entry->ID, '_forma_log_result', true ); ?>
								<tr>
									<td><?php echo esc_html( get_the_time( $date_format, $entry ) ); ?></td>
									<td><?php echo esc_html( (string) get_post_meta( $entry->ID, '_forma_log_operation', true ) ); ?></td>
									<td>
										<span class="forma-publisher-result forma-publisher-result--<?php echo esc_attr( sanitize_html_class( $result ) ); ?>">
											<?php echo esc_html( $result ); ?>
										</span>
									</td>
									<td><code><?php echo esc_html( (string) get_post_meta( $entry->ID, '_forma_log_source_id', true ) ); ?></code></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=forma-publisher-logs' ) ); ?>">
							<?php esc_html_e( 'View the full publish log', 'publisher-for-autodesk-forma' ); ?>
						</a>
					</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the editorial review screen.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function render_review() {
		if ( ! current_user_can( 'edit_forma_projects' ) ) {
			wp_die( esc_html__( 'You do not have permission to review Forma projects.', 'publisher-for-autodesk-forma' ) );
		}

		$held        = Review::held_posts();
		$repository  = new Repository();
		$pending     = $repository->projects(
			array(
				'post_status'    => 'pending',
				'posts_per_page' => 50,
			)
		);
		$date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<div class="wrap forma-publisher-admin">
			<h1><?php esc_html_e( 'Editorial Review', 'publisher-for-autodesk-forma' ); ?></h1>

			<?php $this->render_notice(); ?>

			<div class="forma-publisher-panel">
				<h2><?php esc_html_e( 'Updates held because the project was edited here', 'publisher-for-autodesk-forma' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'These projects changed in WordPress after the last synchronization, so the incoming update was not applied automatically.', 'publisher-for-autodesk-forma' ); ?>
				</p>

				<?php if ( empty( $held ) ) : ?>
					<p><?php esc_html_e( 'Nothing is waiting. Every project matches its published version.', 'publisher-for-autodesk-forma' ); ?></p>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Project', 'publisher-for-autodesk-forma' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Incoming title', 'publisher-for-autodesk-forma' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Held since', 'publisher-for-autodesk-forma' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Actions', 'publisher-for-autodesk-forma' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							foreach ( $held as $post ) :
								$payload  = Review::held( $post->ID );
								$held_at  = (string) get_post_meta( $post->ID, Review::META_HELD_AT, true );
								$stamp    = '' !== $held_at ? strtotime( $held_at ) : false;
								$incoming = isset( $payload['project']['title'] ) ? (string) $payload['project']['title'] : '';
								?>
								<tr>
									<td>
										<a href="<?php echo esc_url( (string) get_edit_post_link( $post->ID ) ); ?>">
											<?php echo esc_html( get_the_title( $post ) ); ?>
										</a>
									</td>
									<td><?php echo esc_html( $incoming ); ?></td>
									<td><?php echo esc_html( $stamp ? wp_date( $date_format, $stamp ) : '—' ); ?></td>
									<td>
										<a class="button button-primary" href="<?php echo esc_url( $this->action_url( $post->ID, 'apply' ) ); ?>">
											<?php esc_html_e( 'Apply update', 'publisher-for-autodesk-forma' ); ?>
										</a>
										<a class="button" href="<?php echo esc_url( $this->action_url( $post->ID, 'discard' ) ); ?>">
											<?php esc_html_e( 'Keep local edits', 'publisher-for-autodesk-forma' ); ?>
										</a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<div class="forma-publisher-panel">
				<h2><?php esc_html_e( 'Projects awaiting approval', 'publisher-for-autodesk-forma' ); ?></h2>

				<?php if ( empty( $pending ) ) : ?>
					<p><?php esc_html_e( 'No projects are pending review.', 'publisher-for-autodesk-forma' ); ?></p>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Project', 'publisher-for-autodesk-forma' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Received', 'publisher-for-autodesk-forma' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Actions', 'publisher-for-autodesk-forma' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $pending as $post ) : ?>
								<tr>
									<td>
										<a href="<?php echo esc_url( (string) get_edit_post_link( $post->ID ) ); ?>">
											<?php echo esc_html( get_the_title( $post ) ); ?>
										</a>
									</td>
									<td><?php echo esc_html( get_the_time( $date_format, $post ) ); ?></td>
									<td>
										<?php if ( current_user_can( 'publish_forma_projects' ) ) : ?>
											<a class="button button-primary" href="<?php echo esc_url( $this->action_url( $post->ID, 'approve' ) ); ?>">
												<?php esc_html_e( 'Approve and publish', 'publisher-for-autodesk-forma' ); ?>
											</a>
										<?php endif; ?>
										<a class="button" href="<?php echo esc_url( (string) get_permalink( $post->ID ) ); ?>">
											<?php esc_html_e( 'Preview', 'publisher-for-autodesk-forma' ); ?>
										</a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Handles apply, discard and approve actions.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function handle_review_action() {
		$post_id = isset( $_REQUEST['post_id'] ) ? absint( wp_unslash( $_REQUEST['post_id'] ) ) : 0;
		$task    = isset( $_REQUEST['task'] ) ? sanitize_key( wp_unslash( $_REQUEST['task'] ) ) : '';

		check_admin_referer( 'forma_publisher_review_' . $task . '_' . $post_id );

		if ( ! $post_id || ! current_user_can( 'edit_forma_project', $post_id ) ) {
			wp_die( esc_html__( 'You do not have permission to review that project.', 'publisher-for-autodesk-forma' ) );
		}

		switch ( $task ) {
			case 'apply':
				$service = new Ingest_Service( $this->settings, new Repository(), $this->audit_log );
				$result  = $service->apply_held_update( $post_id );
				$notice  = is_wp_error( $result ) ? 'review_failed' : 'review_applied';
				break;

			case 'discard':
				Review::clear( $post_id );
				// Treat the local version as the agreed state so the next update
				// is not immediately held again for the same reason.
				Review::record_sync( $post_id );

				$this->audit_log->log(
					array(
						'operation' => 'review_discarded',
						'result'    => 'success',
						'post_id'   => $post_id,
						'source_id' => (string) get_post_meta( $post_id, Post_Types::META_SOURCE_ID, true ),
					)
				);

				$notice = 'review_discarded';
				break;

			case 'approve':
				if ( ! current_user_can( 'publish_forma_projects' ) ) {
					wp_die( esc_html__( 'You do not have permission to publish Forma projects.', 'publisher-for-autodesk-forma' ) );
				}

				wp_update_post(
					array(
						'ID'          => $post_id,
						'post_status' => 'publish',
					)
				);

				Review::record_sync( $post_id );

				$this->audit_log->log(
					array(
						'operation' => 'review_approved',
						'result'    => 'success',
						'post_id'   => $post_id,
						'source_id' => (string) get_post_meta( $post_id, Post_Types::META_SOURCE_ID, true ),
					)
				);

				$notice = 'review_approved';
				break;

			default:
				$notice = 'review_failed';
		}

		Review::flush_attention_count();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => 'forma-publisher-review',
					'forma_notice' => $notice,
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * Builds a nonce protected review action URL.
	 *
	 * @since 1.1.0
	 *
	 * @param int    $post_id Project post id.
	 * @param string $task    Action name.
	 * @return string Action URL.
	 */
	private function action_url( $post_id, $task ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'  => 'forma_publisher_review_action',
					'post_id' => (int) $post_id,
					'task'    => $task,
				),
				admin_url( 'admin-post.php' )
			),
			'forma_publisher_review_' . $task . '_' . $post_id
		);
	}

	/**
	 * Prints the notice matching the current request.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	private function render_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read only notice code from a redirect performed by this plugin.
		$code = isset( $_GET['forma_notice'] ) ? sanitize_key( wp_unslash( $_GET['forma_notice'] ) ) : '';

		$messages = array(
			'review_applied'   => array( 'success', __( 'The held update was applied.', 'publisher-for-autodesk-forma' ) ),
			'review_discarded' => array( 'success', __( 'The held update was discarded and the local version kept.', 'publisher-for-autodesk-forma' ) ),
			'review_approved'  => array( 'success', __( 'The project was published.', 'publisher-for-autodesk-forma' ) ),
			'review_failed'    => array( 'error', __( 'That review action could not be completed.', 'publisher-for-autodesk-forma' ) ),
		);

		if ( '' === $code || ! isset( $messages[ $code ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $messages[ $code ][0] ),
			esc_html( $messages[ $code ][1] )
		);
	}
}
