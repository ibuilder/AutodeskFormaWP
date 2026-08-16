<?php
/**
 * Canonical publish payload schema.
 *
 * @package Forma_Publisher
 */

namespace Forma_Publisher;

defined( 'ABSPATH' ) || exit;

/**
 * Describes the canonical payload accepted by the ingest endpoint.
 *
 * The schema is intentionally decoupled from Autodesk response shapes: the
 * backend service normalizes upstream data into this contract before signing.
 *
 * @since 1.0.0
 */
class Schema {

	/**
	 * Supported canonical schema version.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	const VERSION = '1.0';

	/**
	 * Operations the ingest endpoint understands.
	 *
	 * @since 1.0.0
	 *
	 * @return string[] Operation names.
	 */
	public static function operations() {
		return array( 'publish', 'update', 'unpublish', 'archive', 'delete' );
	}

	/**
	 * Returns the REST argument schema for the ingest endpoint.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,array<string,mixed>> REST arguments.
	 */
	public static function ingest_args() {
		return array(
			'schema_version' => array(
				'type'     => 'string',
				'required' => true,
				'enum'     => array( self::VERSION ),
			),
			'operation'      => array(
				'type'     => 'string',
				'required' => true,
				'enum'     => self::operations(),
			),
			'mode'           => array(
				'type'     => 'string',
				'required' => false,
				'default'  => 'snapshot',
				'enum'     => array( 'snapshot', 'sync' ),
			),
			'job_id'         => array(
				'type'      => 'string',
				'required'  => true,
				'minLength' => 1,
				'maxLength' => 128,
			),
			'generated_at'   => array(
				'type'     => 'string',
				'required' => false,
				'format'   => 'date-time',
			),
			'project'        => array(
				'type'                 => 'object',
				'required'             => true,
				'additionalProperties' => false,
				'properties'           => self::project_properties(),
			),
		);
	}

	/**
	 * Returns the JSON schema properties for a canonical project.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,array<string,mixed>> Project properties.
	 */
	public static function project_properties() {
		return array(
			'source_id'         => array(
				'type'      => 'string',
				'required'  => true,
				'minLength' => 1,
				'maxLength' => 255,
			),
			'source_system'     => array(
				'type'      => 'string',
				'maxLength' => 64,
				'default'   => 'autodesk-forma',
			),
			'title'             => array(
				'type'      => 'string',
				'required'  => true,
				'minLength' => 1,
				'maxLength' => 255,
			),
			'slug'              => array(
				'type'      => 'string',
				'maxLength' => 200,
			),
			'summary'           => array(
				'type'      => 'string',
				'maxLength' => 2000,
			),
			'content'           => array(
				'type'      => 'string',
				'maxLength' => 200000,
			),
			'status'            => array(
				'type' => 'string',
				'enum' => array( 'publish', 'draft', 'pending', 'private' ),
			),
			'source_url'        => array(
				'type'   => 'string',
				'format' => 'uri',
			),
			'hub_id'            => array(
				'type'      => 'string',
				'maxLength' => 255,
			),
			'project_id'        => array(
				'type'      => 'string',
				'maxLength' => 255,
			),
			'proposal_id'       => array(
				'type'      => 'string',
				'maxLength' => 255,
			),
			'source_updated_at' => array(
				'type'   => 'string',
				'format' => 'date-time',
			),
			'tags'              => array(
				'type'     => 'array',
				'maxItems' => 50,
				'items'    => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
			),
			'statuses'          => array(
				'type'     => 'array',
				'maxItems' => 20,
				'items'    => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
			),
			'location'          => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => array(
					'latitude'  => array(
						'type'    => 'number',
						'minimum' => -90,
						'maximum' => 90,
					),
					'longitude' => array(
						'type'    => 'number',
						'minimum' => -180,
						'maximum' => 180,
					),
					'address'   => array(
						'type'      => 'string',
						'maxLength' => 500,
					),
				),
			),
			'featured_image'    => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => array(
					'url'      => array(
						'type'     => 'string',
						'format'   => 'uri',
						'required' => true,
					),
					'alt'      => array(
						'type'      => 'string',
						'maxLength' => 255,
					),
					'filename' => array(
						'type'      => 'string',
						'maxLength' => 200,
					),
				),
			),
			'metrics'           => array(
				'type'     => 'array',
				'maxItems' => 200,
				'items'    => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'key'       => array(
							'type'      => 'string',
							'required'  => true,
							'maxLength' => 100,
						),
						'label'     => array(
							'type'      => 'string',
							'maxLength' => 200,
						),
						'value'     => array(
							'type' => array( 'number', 'string', 'null' ),
						),
						'unit'      => array(
							'type'      => 'string',
							'maxLength' => 32,
						),
						'category'  => array(
							'type'      => 'string',
							'maxLength' => 100,
						),
						'precision' => array(
							'type'    => 'integer',
							'minimum' => 0,
							'maximum' => 8,
						),
					),
				),
			),
			'assets'            => array(
				'type'     => 'array',
				'maxItems' => 200,
				'items'    => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'properties'           => array(
						'source_id' => array(
							'type'      => 'string',
							'required'  => true,
							'maxLength' => 255,
						),
						'title'     => array(
							'type'      => 'string',
							'required'  => true,
							'maxLength' => 255,
						),
						'kind'      => array(
							'type' => 'string',
							'enum' => array( 'image', 'document', 'model', 'dataset', 'link' ),
						),
						'url'       => array(
							'type'   => 'string',
							'format' => 'uri',
						),
						'mime_type' => array(
							'type'      => 'string',
							'maxLength' => 128,
						),
						'size'      => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'checksum'  => array(
							'type'      => 'string',
							'maxLength' => 128,
						),
						'summary'   => array(
							'type'      => 'string',
							'maxLength' => 2000,
						),
					),
				),
			),
		);
	}
}
