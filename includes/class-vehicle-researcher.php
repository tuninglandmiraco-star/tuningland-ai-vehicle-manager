<?php
/**
 * Vehicle Researcher
 *
 * Coordinates preparation of vehicle data for AI research.
 *
 * Architectural rules:
 *
 * - Vehicle CPT is discovered dynamically.
 * - ACF fields are discovered dynamically.
 * - No vehicle-specific field names are hard-coded.
 * - This class NEVER writes to ACF.
 * - Research data is prepared for Research / Validation workflows.
 * - Existing research data may be included as context.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TL_AI_VM_Vehicle_Researcher {

	/**
	 * Singleton instance.
	 *
	 * @var TL_AI_VM_Vehicle_Researcher|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return TL_AI_VM_Vehicle_Researcher
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
	 * Prepare a vehicle for AI research.
	 *
	 * @param int   $post_id Vehicle post ID.
	 * @param array $args    Options.
	 *
	 * @return array
	 */
	public function prepare(
		$post_id,
		$args = array()
	) {

		$post_id = absint( $post_id );

		if ( ! $post_id ) {

			return array(
				'success' => false,
				'error'   => 'Invalid vehicle post ID.',
			);
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {

			return array(
				'success' => false,
				'error'   => 'Vehicle post does not exist.',
			);
		}

		$args = wp_parse_args(
			$args,
			array(
				'include_content'        => true,
				'include_current_data'  => true,
				'include_research_data' => true,
				'only_empty'            => false,
				'only_filled'           => false,
			)
		);

		$post_type = $post->post_type;

		/**
		 * Get selected vehicle CPT.
		 */
		$selected_cpt = sanitize_key(
			get_option(
				'tl_ai_vm_vehicle_cpt',
				''
			)
		);

		/**
		 * If a CPT has been configured,
		 * the vehicle must belong to it.
		 */
		if (
			'' !== $selected_cpt &&
			$post_type !== $selected_cpt
		) {

			return array(
				'success' => false,
				'error'   =>
					'This post does not belong to the selected vehicle CPT.',
			);
		}

		/**
		 * Make sure the post type is valid.
		 */
		if (
			! post_type_exists(
				$post_type
			)
		) {

			return array(
				'success' => false,
				'error'   => 'Vehicle post type does not exist.',
			);
		}

		/**
		 * ACF Scanner must be available.
		 */
		if (
			! class_exists(
				'TL_AI_VM_ACF_Scanner'
			)
		) {

			return array(
				'success' => false,
				'error'   =>
					'ACF Scanner is not available.',
			);
		}

		$scanner =
			TL_AI_VM_ACF_Scanner::instance();

		if ( ! $scanner ) {

			return array(
				'success' => false,
				'error'   =>
					'Unable to initialize ACF Scanner.',
			);
		}

		/**
		 * Discover ACF fields dynamically.
		 */
		$fields =
			$scanner->get_flat_fields(
				$post_type
			);

		if ( ! is_array( $fields ) ) {
			$fields = array();
		}

		/**
		 * Get current ACF values.
		 */
		$current_data = array();

		if (
			! empty(
				$args['include_current_data']
			)
		) {

			$current_data =
				$this->get_current_data(
					$post_id,
					$fields
				);
		}

		/**
		 * Apply empty/filled filtering at preparation level.
		 *
		 * This does not remove the complete field schema.
		 * Instead it controls which current values are
		 * exposed as research targets.
		 */
		$research_fields = $fields;

		if (
			! empty(
				$args['only_empty']
			)
		) {

			$research_fields =
				$this->filter_fields_by_state(
					$post_id,
					$fields,
					'empty'
				);
		}

		if (
			! empty(
				$args['only_filled']
			)
		) {

			$research_fields =
				$this->filter_fields_by_state(
					$post_id,
					$fields,
					'filled'
				);
		}

		/**
		 * Build generic vehicle identity.
		 *
		 * This is context only.
		 * It must not be interpreted as verified
		 * technical vehicle data.
		 */
		$vehicle =
			$this->build_vehicle_identity(
				$post,
				$args
			);

		/**
		 * Existing Research Storage data.
		 *
		 * Researcher does not modify it.
		 */
		$research_data = array();

		if (
			! empty(
				$args['include_research_data']
			) &&
			class_exists(
				'TL_AI_VM_Research_Storage'
			)
		) {

			$storage =
				TL_AI_VM_Research_Storage::instance();

			if ( $storage ) {

				$research_data =
					$storage->get(
						$post_id
					);
			}
		}

		return array(
			'success' => true,

			'post_id' =>
				$post_id,

			'post_type' =>
				$post_type,

			'vehicle' =>
				$vehicle,

			/**
			 * Complete discovered schema.
			 */
			'fields' =>
				$fields,

			/**
			 * Fields that are relevant to the
			 * current research mode.
			 */
			'research_fields' =>
				$research_fields,

			'field_count' =>
				count(
					$fields
				),

			'research_field_count' =>
				count(
					$research_fields
				),

			'current_data' =>
				$current_data,

			'research_data' =>
				$research_data,

			'meta' => array(
				'prepared_at' =>
					current_time(
						'timestamp'
					),

				'acf_available' =>
					function_exists(
						'get_field'
					),

				'only_empty' =>
					! empty(
						$args['only_empty']
					),

				'only_filled' =>
					! empty(
						$args['only_filled']
					),
			),
		);
	}

	/**
	 * Get current ACF values.
	 *
	 * @param int   $post_id Vehicle ID.
	 * @param array $fields  Discovered fields.
	 *
	 * @return array
	 */
	public function get_current_data(
		$post_id,
		$fields
	) {

		$post_id = absint( $post_id );

		if (
			! $post_id ||
			! is_array( $fields )
		) {
			return array();
		}

		$data = array();

		/**
		 * Prefer ACF when available.
		 */
		$use_acf =
			function_exists(
				'get_field'
			);

		foreach ( $fields as $field ) {

			if (
				! is_array( $field ) ||
				empty(
					$field['name']
				)
			) {
				continue;
			}

			$field_name =
				$this->normalize_field_name(
					$field['name']
				);

			if ( '' === $field_name ) {
				continue;
			}

			if ( $use_acf ) {

				$value =
					get_field(
						$field_name,
						$post_id
					);

			} else {

				$value =
					get_post_meta(
						$post_id,
						$field_name,
						true
					);
			}

			$data[ $field_name ] =
				$value;
		}

		return $data;
	}

	/**
	 * Build an AI-ready research package.
	 *
	 * This method prepares context only.
	 * It does not execute AI research and does not write ACF.
	 *
	 * @param int   $post_id Vehicle ID.
	 * @param array $sources Optional known sources.
	 * @param array $args    Options.
	 *
	 * @return array
	 */
	public function build_research_package(
		$post_id,
		$sources = array(),
		$args = array()
	) {

		$prepared =
			$this->prepare(
				$post_id,
				$args
			);

		if (
			empty(
				$prepared['success']
			)
		) {

			return $prepared;
		}

		/**
		 * Build AI schema dynamically.
		 */
		$schema = array();

		if (
			class_exists(
				'TL_AI_VM_Field_Mapper'
			)
		) {

			$mapper =
				TL_AI_VM_Field_Mapper::instance();

			if ( $mapper ) {

				$schema =
					$mapper->get_ai_schema(
						$prepared['research_fields']
					);

				if ( ! is_array( $schema ) ) {
					$schema = array();
				}
			}
		}

		/**
		 * Normalize sources.
		 */
		$sources =
			is_array(
				$sources
			)
				? $sources
				: array();

		/**
		 * Return a research package.
		 *
		 * Important:
		 * This package is input/context for the research
		 * engine. It is NOT an ACF update package.
		 */
		return array(
			'success' => true,

			'version' =>
				'2.1.0',

			'vehicle' =>
				$prepared['vehicle'],

			'post_id' =>
				$prepared['post_id'],

			'post_type' =>
				$prepared['post_type'],

			'fields' =>
				$prepared['fields'],

			'research_fields' =>
				$prepared['research_fields'],

			'field_schema' =>
				$schema,

			'current_data' =>
				$prepared['current_data'],

			'existing_research' =>
				$prepared['research_data'],

			'sources' =>
				$sources,

			'meta' => array(
				'created_at' =>
					current_time(
						'timestamp'
					),

				'researcher' =>
					'TL_AI_VM_Vehicle_Researcher',

				'researcher_version' =>
					'2.1.0',

				'field_count' =>
					count(
						$prepared['fields']
					),

				'research_field_count' =>
					count(
						$prepared['research_fields']
					),
			),
		);
	}

	/**
	 * Get fields currently empty.
	 *
	 * @param int   $post_id Vehicle ID.
	 * @param array $fields  Fields.
	 *
	 * @return array
	 */
	public function get_empty_fields(
		$post_id,
		$fields
	) {

		return $this->filter_fields_by_state(
			$post_id,
			$fields,
			'empty'
		);
	}

	/**
	 * Get fields currently filled.
	 *
	 * @param int   $post_id Vehicle ID.
	 * @param array $fields  Fields.
	 *
	 * @return array
	 */
	public function get_filled_fields(
		$post_id,
		$fields
	) {

		return $this->filter_fields_by_state(
			$post_id,
			$fields,
			'filled'
		);
	}

	/**
	 * Filter fields according to their current state.
	 *
	 * @param int    $post_id Vehicle ID.
	 * @param array  $fields  Fields.
	 * @param string $state   empty|filled.
	 *
	 * @return array
	 */
	private function filter_fields_by_state(
		$post_id,
		$fields,
		$state
	) {

		$post_id = absint( $post_id );

		if (
			! $post_id ||
			! is_array( $fields )
		) {
			return array();
		}

		$state =
			sanitize_key(
				$state
			);

		if (
			! in_array(
				$state,
				array(
					'empty',
					'filled',
				),
				true
			)
		) {
			return array();
		}

		$current =
			$this->get_current_data(
				$post_id,
				$fields
			);

		$result = array();

		foreach ( $fields as $field ) {

			if (
				! is_array( $field ) ||
				empty(
					$field['name']
				)
			) {
				continue;
			}

			$name =
				$this->normalize_field_name(
					$field['name']
				);

			if ( '' === $name ) {
				continue;
			}

			$value =
				array_key_exists(
					$name,
					$current
				)
					? $current[ $name ]
					: null;

			$is_empty =
				$this->is_empty(
					$value
				);

			if (
				'empty' === $state &&
				$is_empty
			) {

				$result[] = $field;
			}

			if (
				'filled' === $state &&
				! $is_empty
			) {

				$result[] = $field;
			}
		}

		return $result;
	}

	/**
	 * Build generic vehicle identity.
	 *
	 * This data is contextual and must not be treated
	 * as verified technical information.
	 *
	 * @param WP_Post $post Vehicle post.
	 * @param array   $args Options.
	 *
	 * @return array
	 */
	private function build_vehicle_identity(
		$post,
		$args = array()
	) {

		$result = array(
			'post_id' =>
				(int) $post->ID,

			'post_type' =>
				$post->post_type,

			'title' =>
				get_the_title(
					$post->ID
				),

			'slug' =>
				$post->post_name,

			'url' =>
				get_permalink(
					$post->ID
				),
		);

		/**
		 * Content is optional because it may contain
		 * outdated or manually entered information.
		 */
		if (
			! empty(
				$args['include_content']
			)
		) {

			$result['content'] =
				wp_strip_all_tags(
					$post->post_content
				);
		}

		return $result;
	}

	/**
	 * Normalize field name.
	 *
	 * Used only as an internal key.
	 *
	 * @param mixed $name Field name.
	 *
	 * @return string
	 */
	private function normalize_field_name(
		$name
	) {

		if ( ! is_scalar( $name ) ) {
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

		/**
		 * False and zero are legitimate values.
		 */
		if (
			false === $value ||
			0 === $value ||
			'0' === $value
		) {
			return false;
		}

		if ( is_string( $value ) ) {

			return '' === trim(
				$value
			);
		}

		if ( is_array( $value ) ) {

			return empty(
				$value
			);
		}

		return false;
	}

}