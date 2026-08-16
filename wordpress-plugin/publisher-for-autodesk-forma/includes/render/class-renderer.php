<?php
/**
 * Shared rendering entry points for shortcodes and blocks.
 *
 * @package Forma_Publisher
 */

namespace Forma_Publisher;

defined( 'ABSPATH' ) || exit;

/**
 * Builds view data and renders the matching template.
 *
 * @since 1.0.0
 */
class Renderer {

	/**
	 * Returns the default attributes for the project list view.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,mixed> Default attributes.
	 */
	public static function project_list_defaults() {
		return array(
			'limit'          => 10,
			'columns'        => 3,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'tag'            => '',
			'status'         => '',
			'show_excerpt'   => true,
			'show_thumbnail' => true,
			'show_metrics'   => false,
		);
	}

	/**
	 * Renders a list of published projects.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $atts View attributes.
	 * @return string Rendered markup.
	 */
	public static function project_list( array $atts ) {
		$atts = array_merge( self::project_list_defaults(), $atts );

		$query_args = array(
			'posts_per_page' => max( 1, min( 50, (int) $atts['limit'] ) ),
			'orderby'        => self::normalize_orderby( $atts['orderby'] ),
			'order'          => 'ASC' === strtoupper( (string) $atts['order'] ) ? 'ASC' : 'DESC',
		);

		$tax_query = array();

		if ( '' !== trim( (string) $atts['tag'] ) ) {
			$tax_query[] = array(
				'taxonomy' => Taxonomies::TAG,
				'field'    => 'slug',
				'terms'    => self::split_list( $atts['tag'] ),
			);
		}

		if ( '' !== trim( (string) $atts['status'] ) ) {
			$tax_query[] = array(
				'taxonomy' => Taxonomies::STATUS,
				'field'    => 'slug',
				'terms'    => self::split_list( $atts['status'] ),
			);
		}

		if ( ! empty( $tax_query ) ) {
			$query_args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Term filtering is the documented purpose of this view.
		}

		$repository = new Repository();
		$projects   = $repository->projects( $query_args );

		return Templates::render(
			'project-list.php',
			array(
				'projects'       => $projects,
				'columns'        => max( 1, min( 6, (int) $atts['columns'] ) ),
				'show_excerpt'   => (bool) $atts['show_excerpt'],
				'show_thumbnail' => (bool) $atts['show_thumbnail'],
				'show_metrics'   => (bool) $atts['show_metrics'],
			)
		);
	}

	/**
	 * Renders a single project.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $atts View attributes.
	 * @return string Rendered markup.
	 */
	public static function project( array $atts ) {
		$defaults = array(
			'id'             => '',
			'show_metrics'   => true,
			'show_assets'    => true,
			'show_thumbnail' => true,
			'show_content'   => true,
		);

		$atts       = array_merge( $defaults, $atts );
		$repository = new Repository();
		$post       = $repository->resolve_project( $atts['id'] );

		if ( ! $post instanceof \WP_Post ) {
			return self::notice( __( 'That Forma project could not be found.', 'publisher-for-autodesk-forma' ) );
		}

		if ( ! self::is_readable_post( $post ) ) {
			return '';
		}

		return Templates::render(
			'project.php',
			array(
				'project'        => $post,
				'metrics'        => (bool) $atts['show_metrics'] ? self::metrics_for( $post->ID ) : array(),
				'assets'         => (bool) $atts['show_assets'] ? $repository->assets_for_project( $post->ID ) : array(),
				'location'       => self::location_for( $post->ID ),
				'show_thumbnail' => (bool) $atts['show_thumbnail'],
				'show_content'   => (bool) $atts['show_content'],
			)
		);
	}

	/**
	 * Renders the metric table for a project.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $atts View attributes.
	 * @return string Rendered markup.
	 */
	public static function metrics( array $atts ) {
		$defaults = array(
			'project'  => '',
			'category' => '',
			'keys'     => '',
			'layout'   => 'table',
		);

		$atts       = array_merge( $defaults, $atts );
		$repository = new Repository();
		$post       = $repository->resolve_project( $atts['project'] );

		if ( ! $post instanceof \WP_Post ) {
			return self::notice( __( 'That Forma project could not be found.', 'publisher-for-autodesk-forma' ) );
		}

		if ( ! self::is_readable_post( $post ) ) {
			return '';
		}

		$metrics = self::metrics_for( $post->ID );

		if ( '' !== trim( (string) $atts['category'] ) ) {
			$categories = self::split_list( $atts['category'] );
			$metrics    = array_values(
				array_filter(
					$metrics,
					static function ( $metric ) use ( $categories ) {
						return in_array( sanitize_title( (string) $metric['category'] ), $categories, true );
					}
				)
			);
		}

		if ( '' !== trim( (string) $atts['keys'] ) ) {
			$keys    = self::split_list( $atts['keys'] );
			$metrics = array_values(
				array_filter(
					$metrics,
					static function ( $metric ) use ( $keys ) {
						return in_array( sanitize_title( (string) $metric['key'] ), $keys, true );
					}
				)
			);
		}

		return Templates::render(
			'metrics.php',
			array(
				'project' => $post,
				'metrics' => $metrics,
				'layout'  => 'cards' === $atts['layout'] ? 'cards' : 'table',
			)
		);
	}

	/**
	 * Renders the asset list for a project.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $atts View attributes.
	 * @return string Rendered markup.
	 */
	public static function assets( array $atts ) {
		$defaults = array(
			'project' => '',
			'kind'    => '',
			'limit'   => 50,
		);

		$atts       = array_merge( $defaults, $atts );
		$repository = new Repository();
		$post       = $repository->resolve_project( $atts['project'] );

		if ( ! $post instanceof \WP_Post ) {
			return self::notice( __( 'That Forma project could not be found.', 'publisher-for-autodesk-forma' ) );
		}

		if ( ! self::is_readable_post( $post ) ) {
			return '';
		}

		$assets = $repository->assets_for_project( $post->ID, (int) $atts['limit'] );

		if ( '' !== trim( (string) $atts['kind'] ) ) {
			$kinds  = self::split_list( $atts['kind'] );
			$assets = array_values(
				array_filter(
					$assets,
					static function ( $asset ) use ( $kinds ) {
						return in_array( sanitize_title( (string) get_post_meta( $asset->ID, '_forma_asset_kind', true ) ), $kinds, true );
					}
				)
			);
		}

		return Templates::render(
			'assets.php',
			array(
				'project' => $post,
				'assets'  => $assets,
			)
		);
	}

	/**
	 * Returns the stored metrics for a project.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Project post id.
	 * @return array<int,array<string,mixed>> Metric rows.
	 */
	public static function metrics_for( $post_id ) {
		$metrics = get_post_meta( (int) $post_id, '_forma_metrics', true );

		if ( ! is_array( $metrics ) ) {
			return array();
		}

		$clean = array();

		foreach ( $metrics as $metric ) {
			if ( ! is_array( $metric ) || empty( $metric['key'] ) ) {
				continue;
			}

			$clean[] = array(
				'key'       => (string) $metric['key'],
				'label'     => isset( $metric['label'] ) ? (string) $metric['label'] : (string) $metric['key'],
				'value'     => isset( $metric['value'] ) ? $metric['value'] : null,
				'unit'      => isset( $metric['unit'] ) ? (string) $metric['unit'] : '',
				'category'  => isset( $metric['category'] ) ? (string) $metric['category'] : '',
				'precision' => isset( $metric['precision'] ) ? (int) $metric['precision'] : 2,
			);
		}

		return $clean;
	}

	/**
	 * Returns the stored location for a project.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Project post id.
	 * @return array<string,mixed> Location data.
	 */
	public static function location_for( $post_id ) {
		$location = get_post_meta( (int) $post_id, '_forma_location', true );

		return is_array( $location ) ? $location : array();
	}

	/**
	 * Formats a metric value for display.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $metric Metric row.
	 * @return string Formatted value including its unit.
	 */
	public static function format_metric( array $metric ) {
		$value = isset( $metric['value'] ) ? $metric['value'] : null;

		if ( null === $value || '' === $value ) {
			$formatted = '—';
		} elseif ( is_numeric( $value ) ) {
			$precision = isset( $metric['precision'] ) ? max( 0, min( 8, (int) $metric['precision'] ) ) : 2;
			$formatted = number_format_i18n( (float) $value, $precision );
		} else {
			$formatted = (string) $value;
		}

		$unit = isset( $metric['unit'] ) ? trim( (string) $metric['unit'] ) : '';

		if ( '' !== $unit && '—' !== $formatted ) {
			$formatted .= ' ' . $unit;
		}

		return $formatted;
	}

	/**
	 * Returns a human readable label for an asset kind.
	 *
	 * @since 1.0.0
	 *
	 * @param string $kind Asset kind slug.
	 * @return string Translated label.
	 */
	public static function asset_kind_label( $kind ) {
		$labels = array(
			'image'    => __( 'Image', 'publisher-for-autodesk-forma' ),
			'document' => __( 'Document', 'publisher-for-autodesk-forma' ),
			'model'    => __( 'Model', 'publisher-for-autodesk-forma' ),
			'dataset'  => __( 'Dataset', 'publisher-for-autodesk-forma' ),
			'link'     => __( 'Link', 'publisher-for-autodesk-forma' ),
		);

		$kind = (string) $kind;

		return isset( $labels[ $kind ] ) ? $labels[ $kind ] : __( 'File', 'publisher-for-autodesk-forma' );
	}

	/**
	 * Wraps a message in the plugin notice markup.
	 *
	 * Messages are only shown to users who can edit projects so that visitors
	 * never see integration diagnostics.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Message text.
	 * @return string Rendered markup, or an empty string for visitors.
	 */
	public static function notice( $message ) {
		if ( ! current_user_can( 'edit_forma_projects' ) ) {
			return '';
		}

		Assets::enqueue_frontend_style();

		return '<p class="forma-publisher-notice">' . esc_html( $message ) . '</p>';
	}

	/**
	 * Checks whether the current user may see a project.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Post $post Project post.
	 * @return bool True when the post may be rendered.
	 */
	private static function is_readable_post( \WP_Post $post ) {
		if ( 'publish' === $post->post_status ) {
			return true;
		}

		return current_user_can( 'read_forma_project', $post->ID );
	}

	/**
	 * Normalizes an orderby attribute to a safe value.
	 *
	 * @since 1.0.0
	 *
	 * @param string $orderby Requested order key.
	 * @return string Safe orderby value.
	 */
	private static function normalize_orderby( $orderby ) {
		$allowed = array( 'date', 'title', 'modified', 'menu_order', 'rand' );
		$orderby = strtolower( trim( (string) $orderby ) );

		return in_array( $orderby, $allowed, true ) ? $orderby : 'date';
	}

	/**
	 * Splits a comma separated attribute into a lowercase list.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Raw attribute value.
	 * @return string[] Normalized values.
	 */
	private static function split_list( $value ) {
		$parts = preg_split( '/\s*,\s*/', strtolower( trim( (string) $value ) ) );

		if ( ! is_array( $parts ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'sanitize_title', $parts ) ) );
	}
}
