<?php
/**
 * Vehicle Filter
 *
 * Finds vehicle posts using dynamically supplied filters.
 *
 * Architectural rules:
 *
 * - Vehicle CPT is discovered/configured dynamically.
 * - Vehicle field names are NEVER hard-coded.
 * - ACF is used when available.
 * - Completion filtering is performed against actual field values.
 * - This class does not write to ACF.
 * - This class is only responsible for discovering/filtering vehicles.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TL_AI_VM_Vehicle_Filter {

	/**
	 * Singleton instance.
	 *
	 * @var TL_AI_VM_Vehicle_Filter|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return TL_AI_VM_Vehicle_Filter
	 */
	public static function instance() {

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
	}

	/**
	 * Find vehicles using dynamic filters.
	 *
	 * @param array $args Filter arguments.
	 *
	 * @return array
	 */
	public function find( $args = array() ) {

		$args =
			$this->normalize_args(
				$args
			);

		/**
		 * CPT is mandatory.
		 */
		if ( '' === $args['post_type'] ) {

			return array(
				'success'     => false,
				'ids'         => array(),
				'count'       => 0,
				'found_posts' => 0,
				'page'        => $args['paged'],
				'filters'     => $args,
				'error'       => array(
					'type'    => 'missing_post_type',
					'message' => 'Vehicle post type could not be determined.',
				),
			);
		}

		/**
		 * Build WP_Query arguments.
		 */
		$query_args = array(
			'post_type'              => $args['post_type'],
			'post_status'            => $args['post_status'],
			'posts_per_page'         => $args['posts_per_page'],
			'paged'                  => $args['paged'],
			'orderby'                => $args['orderby'],
			'order'                  => $args['order'],
			'fields'                 => 'ids',
			'no_found_rows'          => false,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		/**
		 * If no pagination/count is needed,
		 * avoid SQL_CALC_FOUND_ROWS.
		 */
		if (
			-1 === $args['posts_per_page'] &&
			0 === $args['limit'] &&
			! $args['return_found_posts']
		) {

			$query_args['no_found_rows'] = true;
		}

		/**
		 * Search by vehicle title/content.
		 */
		if ( '' !== $args['search'] ) {

			$query_args['s'] =
				$args['search'];
		}

		/**
		 * Build dynamic meta query.
		 */
		$meta_query =
			$this->build_meta_query(
				$args
			);

		if ( ! empty( $meta_query ) ) {

			$query_args['meta_query'] =
				$meta_query;
		}

		/**
		 * Execute query.
		 */
		$query =
			new WP_Query(
				$query_args
			);

		$ids =
			is_array(
				$query->posts
			)
				? array_map(
					'absint',
					$query->posts
				)
				: array();

		/**
		 * Apply completion filtering.
		 *
		 * This is intentionally performed after
		 * WP_Query because ACF values may be arrays,
		 * nested structures, relationship values,
		 * repeater values, etc.
		 */
		if (
			$args['only_empty'] ||
			$args['only_filled']
		) {

			$ids =
				$this->filter_by_completion(
					$ids,
					$args
				);
		}

		/**
		 * Apply final result limit.
		 */
		if (
			$args['limit'] > 0 &&
			count( $ids ) > $args['limit']
		) {

			$ids =
				array_slice(
					$ids,
					0,
					$args['limit']
				);
		}

		/**
		 * Determine found posts.
		 */
		$found_posts =
			$query->found_posts;

		if (
			$query_args['no_found_rows']
		) {

			$found_posts =
				count( $ids );
		}

		/**
		 * When completion filtering is active,
		 * found_posts from WP_Query is not the final
		 * filtered count.
		 *
		 * The exact filtered total is only available
		 * when all candidates have been loaded.
		 */
		if (
			(
				$args['only_empty'] ||
				$args['only_filled']
			) &&
			-1 === $args['posts_per_page']
		) {

			$found_posts =
				count( $ids );
		}

		return array(
			'success' =>
				true,

			'ids' =>
				$ids,

			'count' =>
				count(
					$ids
				),

			'found_posts' =>
				(int) $found_posts,

			'page' =>
				$args['paged'],

			'filters' =>
				$args,
		);
	}

	/**
	 * Find vehicles by brand.
	 *
	 * @param string $brand Brand value.
	 * @param array  $args Additional arguments.
	 *
	 * @return array
	 */
	public function find_by_brand(
		$brand,
		$args = array()
	) {

		$args['brand'] =
			$brand;

		return $this->find(
			$args
		);
	}

	/**
	 * Find vehicles by model.
	 *
	 * @param string $model Model value.
	 * @param array  $args Additional arguments.
	 *
	 * @return array
	 */
	public function find_by_model(
		$model,
		$args = array()
	) {

		$args['model'] =
			$model;

		return $this->find(
			$args
		);
	}

	/**
	 * Find vehicles by year range.
	 *
	 * @param int      $from Start year.
	 * @param int|null $to   End year.
	 * @param array    $args Additional arguments.
	 *
	 * @return array
	 */
	public function find_by_year(
		$from,
		$to = null,
		$args = array()
	) {

		$args['year_from'] =
			absint(
				$from
			);

		if ( null !== $to ) {

			$args['year_to'] =
				absint(
					$to
				);
		}

		return $this->find(
			$args
		);
	}

	/**
	 * Find vehicles with incomplete information.
	 *
	 * By default, a vehicle is considered incomplete
	 * when at least one requested field is empty.
	 *
	 * @param array $fields ACF field names.
	 * @param array $args   Additional arguments.
	 *
	 * @return array
	 */
	public function find_incomplete(
		$fields,
		$args = array()
	) {

		$args['required_fields'] =
			is_array( $fields )
				? $fields
				: array();

		$args['only_empty'] =
			true;

		$args['completion_mode'] =
			'any';

		return $this->find(
			$args
		);
	}

	/**
	 * Find vehicles with all requested fields completed.
	 *
	 * @param array $fields ACF field names.
	 * @param array $args   Additional arguments.
	 *
	 * @return array
	 */
	public function find_completed(
		$fields,
		$args = array()
	) {

		$args['required_fields'] =
			is_array( $fields )
				? $fields
				: array();

		$args['only_filled'] =
			true;

		return $this->find(
			$args
		);
	}

	/**
	 * Find vehicles where all requested fields are empty.
	 *
	 * @param array $fields ACF field names.
	 * @param array $args   Additional arguments.
	 *
	 * @return array
	 */
	public function find_all_empty(
		$fields,
		$args = array()
	) {

		$args['required_fields'] =
			is_array( $fields )
				? $fields
				: array();

		$args['only_empty'] =
			true;

		$args['completion_mode'] =
			'all';

		return $this->find(
			$args
		);
	}

	/**
	 * Build dynamic meta query.
	 *
	 * @param array $args Normalized arguments.
	 *
	 * @return array
	 */
	private function build_meta_query(
		$args
	) {

		$meta_query =
			array();

		/**
		 * Brand.
		 */
		if (
			'' !== $args['brand'] &&
			'' !== $args['brand_field']
		) {

			$meta_query[] =
				array(
					'key'     => $args['brand_field'],
					'value'   => $args['brand'],
					'compare' => $args['brand_compare'],
				);
		}

		/**
		 * Model.
		 */
		if (
			'' !== $args['model'] &&
			'' !== $args['model_field']
		) {

			$meta_query[] =
				array(
					'key'     => $args['model_field'],
					'value'   => $args['model'],
					'compare' => $args['model_compare'],
				);
		}

		/**
		 * Year >=.
		 */
		if (
			null !== $args['year_from'] &&
			'' !== $args['year_field']
		) {

			$meta_query[] =
				array(
					'key'     => $args['year_field'],
					'value'   => $args['year_from'],
					'type'    => 'NUMERIC',
					'compare' => '>=',
				);
		}

		/**
		 * Year <=.
		 */
		if (
			null !== $args['year_to'] &&
			'' !== $args['year_field']
		) {

			$meta_query[] =
				array(
					'key'     => $args['year_field'],
					'value'   => $args['year_to'],
					'type'    => 'NUMERIC',
					'compare' => '<=',
				);
		}

		/**
		 * Explicit relation.
		 *
		 * This currently defaults to AND because
		 * brand/model/year filters normally describe
		 * the same vehicle.
		 */
		if ( count( $meta_query ) > 1 ) {

			$meta_query =
				array_merge(
					array(
						'relation' =>
							$args['meta_relation'],
					),
					$meta_query
				);
		}

		return $meta_query;
	}

	/**
	 * Filter vehicles by completion state.
	 *
	 * @param array $ids  Vehicle IDs.
	 * @param array $args Normalized arguments.
	 *
	 * @return array
	 */
	private function filter_by_completion(
		$ids,
		$args
	) {

		if ( empty( $ids ) ) {
			return array();
		}

		$required_fields =
			$args['required_fields'];

		if ( empty( $required_fields ) ) {
			return array();
		}

		$result =
			array();

		foreach ( $ids as $post_id ) {

			$empty_count  = 0;
			$filled_count = 0;

			foreach (
				$required_fields as $field_name
			) {

				$field_name =
					$this->normalize_field_name(
						$field_name
					);

				if ( '' === $field_name ) {
					continue;
				}

				$value =
					$this->get_field_value(
						$post_id,
						$field_name
					);

				if (
					$this->is_empty(
						$value
					)
				) {

					$empty_count++;

				} else {

					$filled_count++;
				}
			}

			$field_count =
				$empty_count +
				$filled_count;

			if ( $field_count <= 0 ) {
				continue;
			}

			/**
			 * Incomplete:
			 *
			 * ANY empty field.
			 */
			if (
				$args['only_empty'] &&
				'any' === $args['completion_mode'] &&
				$empty_count > 0
			) {

				$result[] =
					absint(
						$post_id
					);

				continue;
			}

			/**
			 * All requested fields empty.
			 */
			if (
				$args['only_empty'] &&
				'all' === $args['completion_mode'] &&
				$empty_count === $field_count
			) {

				$result[] =
					absint(
						$post_id
					);

				continue;
			}

			/**
			 * Completed:
			 *
			 * ALL requested fields must contain
			 * a non-empty value.
			 */
			if (
				$args['only_filled'] &&
				$empty_count === 0 &&
				$filled_count === $field_count
			) {

				$result[] =
					absint(
						$post_id
					);
			}
		}

		return $result;
	}

	/**
	 * Get an ACF field value.
	 *
	 * Falls back to post meta when ACF is unavailable.
	 *
	 * @param int    $post_id    Vehicle ID.
	 * @param string $field_name Field name.
	 *
	 * @return mixed
	 */
	private function get_field_value(
		$post_id,
		$field_name
	) {

		$post_id =
			absint(
				$post_id
			);

		if ( ! $post_id ) {
			return null;
		}

		if (
			function_exists(
				'get_field'
			)
		) {

			return get_field(
				$field_name,
				$post_id
			);
		}

		return get_post_meta(
			$post_id,
			$field_name,
			true
		);
	}

	/**
	 * Normalize filter arguments.
	 *
	 * @param array $args Arguments.
	 *
	 * @return array
	 */
	private function normalize_args(
		$args
	) {

		$defaults = array(

			'post_type' =>
				get_option(
					'tl_ai_vm_vehicle_cpt',
					''
				),

			'post_status' =>
				'publish',

			'posts_per_page' =>
				100,

			'paged' =>
				1,

			'orderby' =>
				'date',

			'order' =>
				'ASC',

			'brand' =>
				'',

			'brand_field' =>
				'',

			'brand_compare' =>
				'=',

			'model' =>
				'',

			'model_field' =>
				'',

			'model_compare' =>
				'=',

			'year_from' =>
				null,

			'year_to' =>
				null,

			'year_field' =>
				'',

			'search' =>
				'',

			'required_fields' =>
				array(),

			'only_empty' =>
				false,

			'only_filled' =>
				false,

			'completion_mode' =>
				'any',

			'meta_relation' =>
				'AND',

			'limit' =>
				0,

			'return_found_posts' =>
				true,
		);

		$args =
			wp_parse_args(
				$args,
				$defaults
			);

		/**
		 * Sanitize CPT.
		 */
		$args['post_type'] =
			sanitize_key(
				$args['post_type']
			);

		/**
		 * Sanitize dynamic field names.
		 */
		$args['brand_field'] =
			$this->normalize_field_name(
				$args['brand_field']
			);

		$args['model_field'] =
			$this->normalize_field_name(
				$args['model_field']
			);

		$args['year_field'] =
			$this->normalize_field_name(
				$args['year_field']
			);

		/**
		 * Sanitize values.
		 */
		$args['brand'] =
			sanitize_text_field(
				(string) $args['brand']
			);

		$args['model'] =
			sanitize_text_field(
				(string) $args['model']
			);

		$args['search'] =
			sanitize_text_field(
				(string) $args['search']
			);

		/**
		 * Status.
		 */
		if (
			is_array(
				$args['post_status']
			)
		) {

			$args['post_status'] =
				array_map(
					'sanitize_key',
					$args['post_status']
				);

		} else {

			$args['post_status'] =
				sanitize_key(
					$args['post_status']
				);
		}

		/**
		 * Pagination.
		 */
		$args['paged'] =
			max(
				1,
				absint(
					$args['paged']
				)
			);

		$args['posts_per_page'] =
			(int) $args['posts_per_page'];

		/**
		 * Protect against accidentally huge queries.
		 *
		 * -1 is intentionally allowed for internal
		 * completion scans.
		 */
		if (
			$args['posts_per_page'] < -1
		) {

			$args['posts_per_page'] =
				100;
		}

		/**
		 * Limit.
		 */
		$args['limit'] =
			max(
				0,
				absint(
					$args['limit']
				)
			);

		/**
		 * Required fields.
		 */
		if (
			is_array(
				$args['required_fields']
			)
		) {

			$normalized_fields =
				array();

			foreach (
				$args['required_fields'] as $field_name
			) {

				$field_name =
					$this->normalize_field_name(
						$field_name
					);

				if ( '' === $field_name ) {
					continue;
				}

				$normalized_fields[] =
					$field_name;
			}

			$args['required_fields'] =
				array_values(
					array_unique(
						$normalized_fields
					)
				);

		} else {

			$args['required_fields'] =
				array();
		}

		/**
		 * Boolean flags.
		 */
		$args['only_empty'] =
			! empty(
				$args['only_empty']
			);

		$args['only_filled'] =
			! empty(
				$args['only_filled']
			);

		/**
		 * Prevent contradictory completion modes.
		 */
		if (
			$args['only_empty'] &&
			$args['only_filled']
		) {

			$args['only_filled'] =
				false;
		}

		/**
		 * Completion mode.
		 */
		$args['completion_mode'] =
			'all' ===
			strtolower(
				(string) $args['completion_mode']
			)
				? 'all'
				: 'any';

		/**
		 * Meta relation.
		 */
		$args['meta_relation'] =
			'OR' ===
			strtoupper(
				(string) $args['meta_relation']
			)
				? 'OR'
				: 'AND';

		/**
		 * Order.
		 */
		$args['order'] =
			'DESC' ===
			strtoupper(
				(string) $args['order']
			)
				? 'DESC'
				: 'ASC';

		/**
		 * Orderby.
		 */
		$allowed_orderby =
			array(
				'date',
				'title',
				'ID',
				'menu_order',
				'modified',
				'author',
				'rand',
			);

		if (
			! in_array(
				$args['orderby'],
				$allowed_orderby,
				true
			)
		) {

			$args['orderby'] =
				'date';
		}

		/**
		 * Comparison operators.
		 */
		$allowed_compare =
			array(
				'=',
				'LIKE',
				'LIKE BINARY',
				'!=',
				'NOT LIKE',
				'>',
				'>=',
				'<',
				'<=',
				'IN',
				'NOT IN',
			);

		if (
			! in_array(
				$args['brand_compare'],
				$allowed_compare,
				true
			)
		) {

			$args['brand_compare'] =
				'=';
		}

		if (
			! in_array(
				$args['model_compare'],
				$allowed_compare,
				true
			)
		) {

			$args['model_compare'] =
				'=';
		}

		/**
		 * Year values.
		 */
		if (
			null !== $args['year_from']
		) {

			$args['year_from'] =
				absint(
					$args['year_from']
				);

			if (
				0 ===
				$args['year_from']
			) {

				$args['year_from'] =
					null;
			}
		}

		if (
			null !== $args['year_to']
		) {

			$args['year_to'] =
				absint(
					$args['year_to']
				);

			if (
				0 ===
				$args['year_to']
			) {

				$args['year_to'] =
					null;
			}
		}

		return $args;
	}

	/**
	 * Normalize field name.
	 *
	 * @param mixed $name Field name.
	 *
	 * @return string
	 */
	private function normalize_field_name(
		$name
	) {

		if (
			! is_scalar(
				$name
			)
		) {

			return '';
		}

		return trim(
			sanitize_key(
				(string) $name
			)
		);
	}

	/**
	 * Check whether a value is empty.
	 *
	 * Important:
	 * - false and 0 are valid values.
	 * - arrays are empty only when they contain no data.
	 *
	 * @param mixed $value Value.
	 *
	 * @return bool
	 */
	private function is_empty(
		$value
	) {

		if ( null === $value ) {
			return true;
		}

		if ( is_string( $value ) ) {

			return '' === trim(
				$value
			);
		}

		if ( is_array( $value ) ) {

			if ( empty( $value ) ) {
				return true;
			}

			/**
			 * ACF structured fields may contain arrays
			 * with empty child values. For now, the
			 * existence of rows/data is considered filled.
			 */
			return false;
		}

		return false;
	}

}