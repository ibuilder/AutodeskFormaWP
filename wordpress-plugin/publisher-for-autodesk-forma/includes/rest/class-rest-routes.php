<?php
/**
 * REST API routes for signed backend traffic.
 *
 * @package Forma_Publisher
 */

namespace Forma_Publisher;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the signed ingest endpoints used by the publishing backend.
 *
 * @since 1.0.0
 */
class REST_Routes {

	/**
	 * REST namespace.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const NAMESPACE_V1 = 'forma-publisher/v1';

	/**
	 * Settings reader.
	 *
	 * @since 1.0.0
	 * @var Settings
	 */
	private $settings;

	/**
	 * Content repository.
	 *
	 * @since 1.0.0
	 * @var Repository
	 */
	private $repository;

	/**
	 * Audit trail writer.
	 *
	 * @since 1.0.0
	 * @var Audit_Log
	 */
	private $audit_log;

	/**
	 * Signature verifier.
	 *
	 * @since 1.0.0
	 * @var Signature
	 */
	private $signature;

	/**
	 * Connection key id verified for the current request.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private $verified_connection = '';

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param Settings   $settings   Settings instance.
	 * @param Repository $repository Repository instance.
	 * @param Audit_Log  $audit_log  Audit log instance.
	 */
	public function __construct( Settings $settings, Repository $repository, Audit_Log $audit_log ) {
		$this->settings   = $settings;
		$this->repository = $repository;
		$this->audit_log  = $audit_log;
		$this->signature  = new Signature( $settings );
	}

	/**
	 * Hooks route registration into WordPress.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers every plugin REST route.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/ingest',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_ingest' ),
					'permission_callback' => array( $this, 'authorize' ),
					'args'                => Schema::ingest_args(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/status',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_status' ),
					'permission_callback' => array( $this, 'authorize' ),
					'args'                => array(),
				),
			)
		);

		/*
		 * The source id is sent in the request body rather than in the path.
		 * Autodesk identifiers are URNs containing colons, and percent encoding
		 * a path segment would make the client and server disagree about the
		 * route string that is part of the signed canonical request.
		 */
		register_rest_route(
			self::NAMESPACE_V1,
			'/lookup',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_project_lookup' ),
					'permission_callback' => array( $this, 'authorize' ),
					'args'                => array(
						'source_id' => array(
							'type'              => 'string',
							'required'          => true,
							'minLength'         => 1,
							'maxLength'         => 255,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	/**
	 * Verifies the request signature.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Inbound request.
	 * @return true|\WP_Error True when the signature is valid.
	 */
	public function authorize( \WP_REST_Request $request ) {
		$result = $this->signature->verify( $request );

		if ( is_wp_error( $result ) ) {
			$this->verified_connection = '';

			return $result;
		}

		$this->verified_connection = $result;

		return true;
	}

	/**
	 * Handles a publish, update, unpublish, archive or delete operation.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Inbound request.
	 * @return \WP_REST_Response|\WP_Error Result payload, or an error.
	 */
	public function handle_ingest( \WP_REST_Request $request ) {
		if ( '' === $this->verified_connection ) {
			return new \WP_Error(
				'forma_publisher_unauthorized',
				__( 'The request signature could not be verified.', 'publisher-for-autodesk-forma' ),
				array( 'status' => 401 )
			);
		}

		$payload = array(
			'schema_version' => $request->get_param( 'schema_version' ),
			'operation'      => $request->get_param( 'operation' ),
			'mode'           => $request->get_param( 'mode' ),
			'job_id'         => $request->get_param( 'job_id' ),
			'generated_at'   => $request->get_param( 'generated_at' ),
			'project'        => $request->get_param( 'project' ),
		);

		$service = new Ingest_Service( $this->settings, $this->repository, $this->audit_log );
		$result  = $service->handle( $payload, $this->verified_connection );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->settings->touch_connection( $this->verified_connection );

		return new \WP_REST_Response(
			array(
				'success' => true,
				'result'  => $result,
			),
			200
		);
	}

	/**
	 * Returns receiver status information for the backend service.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Inbound request.
	 * @return \WP_REST_Response|\WP_Error Status payload, or an error.
	 */
	public function handle_status( \WP_REST_Request $request ) {
		unset( $request );

		if ( '' === $this->verified_connection ) {
			return new \WP_Error(
				'forma_publisher_unauthorized',
				__( 'The request signature could not be verified.', 'publisher-for-autodesk-forma' ),
				array( 'status' => 401 )
			);
		}

		$counts = wp_count_posts( Post_Types::PROJECT );

		return new \WP_REST_Response(
			array(
				'success'        => true,
				'plugin_version' => FORMA_PUBLISHER_VERSION,
				'schema_version' => Schema::VERSION,
				'connection'     => $this->verified_connection,
				'site_url'       => home_url(),
				'projects'       => array(
					'publish' => isset( $counts->publish ) ? (int) $counts->publish : 0,
					'draft'   => isset( $counts->draft ) ? (int) $counts->draft : 0,
					'private' => isset( $counts->private ) ? (int) $counts->private : 0,
				),
				'server_time'    => time(),
			),
			200
		);
	}

	/**
	 * Returns the stored state for a single upstream project.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request Inbound request.
	 * @return \WP_REST_Response|\WP_Error Project payload, or an error.
	 */
	public function handle_project_lookup( \WP_REST_Request $request ) {
		if ( '' === $this->verified_connection ) {
			return new \WP_Error(
				'forma_publisher_unauthorized',
				__( 'The request signature could not be verified.', 'publisher-for-autodesk-forma' ),
				array( 'status' => 401 )
			);
		}

		$source_id = (string) $request->get_param( 'source_id' );
		$post_id   = $this->repository->find_by_source_id( Post_Types::PROJECT, $source_id );

		if ( ! $post_id ) {
			return new \WP_Error(
				'forma_publisher_not_found',
				__( 'No published project matches that source id.', 'publisher-for-autodesk-forma' ),
				array( 'status' => 404 )
			);
		}

		$post = get_post( $post_id );

		return new \WP_REST_Response(
			array(
				'success'      => true,
				'post_id'      => $post_id,
				'source_id'    => $source_id,
				'post_status'  => $post instanceof \WP_Post ? $post->post_status : '',
				'permalink'    => get_permalink( $post_id ),
				'payload_hash' => (string) get_post_meta( $post_id, '_forma_payload_hash', true ),
				'last_synced'  => (string) get_post_meta( $post_id, '_forma_last_synced', true ),
				'sync_mode'    => (string) get_post_meta( $post_id, '_forma_sync_mode', true ),
				'state'        => (string) get_post_meta( $post_id, '_forma_publish_state', true ),
			),
			200
		);
	}
}
