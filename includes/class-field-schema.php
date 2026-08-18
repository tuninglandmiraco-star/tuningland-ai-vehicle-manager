<?php
/**
 * Field Schema Engine
 *
 * Converts dynamically discovered ACF structures into a normalized,
 * AI-ready schema for the Tuningland AI Vehicle Manager.
 *
 * Architecture:
 *
 * ACF Discovery
 *      ↓
 * Field Schema
 *      ↓
 * Semantic Analyzer
 *      ↓
 * Research Engine
 *      ↓
 * Research Result
 *      ↓
 * Validation / Confidence
 *      ↓
 * Approval
 *      ↓
 * ACF Writer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TL_AI_VM_Field_Schema {

	/**
	 * Singleton instance.
	 *
	 * @var TL_AI_VM_Field_Schema|null
	 */
	private static $instance = null;

	/**
	 * In-memory schema cache.
	 *
	 * @var array
	 */
	private $schema_cache = array();

	/**
	 * Current schema version.
	 *
	 * @var string
	 */
	const SCHEMA_VERSION = '1.1.0';

	/**
	 * Get singleton instance.
	 *
	 * @return TL_AI_VM_Field_Schema
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
	 * Build normalized schema for a CPT.
	 *
	 * @param string $post_type CPT slug.
	 * @param bool   $refresh   Whether to rebuild.
	 *
	 * @return array
	 */
	public function build( $post_type, $refresh = false ) {

		$post_type = sanitize_key( $post_type );

		if ( empty( $post_type ) ) {
			return array(
				'success' => false,
				'error'   => 'No post type was provided.',
			);
		}

		/**
		 * Make sure the CPT actually exists.
		 */
		if ( ! post_type_exists( $post_type ) ) {
			return array(
				'success' => false,
				'error'   => 'The selected post type does not exist.',
			);
		}

		/**
		 * Return memory cache when possible.
		 */
		if (
			! $refresh &&
			isset( $this->schema_cache[ $post_type ] )
		) {
			return $this->schema_cache[ $post_type ];
		}

		$scanner = TL_AI_VM_ACF_Scanner::instance();

		$scan = $scanner->scan(
			$post_type,
			$refresh
		);

		if ( empty( $scan['success'] ) ) {
			return array(
				'success' => false,
				'error'   => isset( $scan['error'] )
					? $scan['error']
					: 'Unable to scan ACF.',
			);
		}

		$post_type_object = get_post_type_object(
			$post_type
		);

		/**
		 * Base schema.
		 */
		$schema = array(

			'success' => true,

			'schema_version' => self::SCHEMA_VERSION,

			'generated_at' => current_time(
				'c',
				true
			),

			'post_type' => array(

				'name' => $post_type,

				'label' => (
					$post_type_object &&
					! empty( $post_type_object->label )
				)
					? $post_type_object->label
					: $post_type,

				'singular' => (
					$post_type_object &&
					! empty(
						$post_type_object->labels->singular_name
					)
				)
					? $post_type_object->labels->singular_name
					: $post_type,

				'public' => (
					$post_type_object
					? (bool) $post_type_object->public
					: false
				),

				'show_ui' => (
					$post_type_object
					? (bool) $post_type_object->show_ui
					: false
				),

				'show_in_rest' => (
					$post_type_object
					? (bool) $post_type_object->show_in_rest
					: false
				),
			),

			'summary' => array(

				'total_groups' => isset(
					$scan['total_groups']
				)
					? (int) $scan['total_groups']
					: 0,

				'total_fields' => isset(
					$scan['total_fields']
				)
					? (int) $scan['total_fields']
					: 0,

				'active_groups' => 0,

				'required_fields' => 0,

				'nested_fields' => 0,
			),

			'groups' => array(),

			/**
			 * AI metadata intentionally remains separate
			 * from raw ACF data.
			 */
			'ai' => array(

				'status' => 'pending',

				'analyzed' => false,

				'analysis_version' => null,

				'last_analysis_at' => null,

				'fields_analyzed' => 0,

				'fields_pending' => 0,

				'fields_review' => 0,

				'fields_ignored' => 0,
			),

			/**
			 * Research layer.
			 *
			 * This does NOT contain research results yet.
			 * It only describes the research state.
			 */
			'research' => array(

				'status' => 'not_started',

				'last_run_at' => null,

				'fields_researched' => 0,

				'fields_pending' => 0,

				'fields_failed' => 0,
			),

			/**
			 * Validation layer.
			 */
			'validation' => array(

				'status' => 'not_started',

				'last_validation_at' => null,

				'validated_fields' => 0,

				'failed_fields' => 0,

				'low_confidence_fields' => 0,
			),
		);

		/**
		 * Normalize discovered groups.
		 */
		if ( ! empty( $scan['groups'] ) ) {

			foreach ( $scan['groups'] as $group ) {

				$normalized_group = $this->normalize_group(
					$group
				);

				$schema['groups'][] = $normalized_group;

				/**
				 * Update summary counters.
				 */
				if ( ! empty( $normalized_group['active'] ) ) {
					$schema['summary']['active_groups']++;
				}

				$this->count_field_statistics(
					$normalized_group['fields'],
					$schema['summary']
				);
			}
		}

		/**
		 * Calculate pending AI fields.
		 */
		$schema['ai']['fields_pending'] =
			$schema['summary']['total_fields'];

		$this->schema_cache[ $post_type ] = $schema;

		/**
		 * Optional logging.
		 */
		if ( class_exists( 'TL_AI_VM_Logger' ) ) {

			TL_AI_VM_Logger::instance()->debug(
				'Field schema built.',
				'schema',
				array(
					'post_type'    => $post_type,
					'total_groups' => $schema['summary']['total_groups'],
					'total_fields' => $schema['summary']['total_fields'],
				)
			);
		}

		return $schema;
	}

	/**
	 * Normalize Field Group.
	 *
	 * @param array $group ACF group.
	 *
	 * @return array
	 */
	private function normalize_group( $group ) {

		$normalized = array(

			'key' => isset( $group['key'] )
				? sanitize_text_field(
					$group['key']
				)
				: '',

			'id' => isset( $group['id'] )
				? (int) $group['id']
				: 0,

			'title' => isset( $group['title'] )
				? sanitize_text_field(
					$group['title']
				)
				: '',

			'active' => isset( $group['active'] )
				? (bool) $group['active']
				: true,

			'menu_order' => isset( $group['menu_order'] )
				? (int) $group['menu_order']
				: 0,

			'total_fields' => isset(
				$group['total_fields']
			)
				? (int) $group['total_fields']
				: 0,

			'fields' => array(),
		);

		if ( ! empty( $group['fields'] ) ) {

			foreach ( $group['fields'] as $field ) {

				$normalized['fields'][] =
					$this->normalize_field(
						$field
					);
			}
		}

		return $normalized;
	}

	/**
	 * Normalize ACF field.
	 *
	 * Important:
	 * Raw ACF configuration remains available while
	 * AI metadata is kept in a separate namespace.
	 *
	 * @param array $field ACF field.
	 *
	 * @return array
	 */
	private function normalize_field( $field ) {

		$type = isset( $field['type'] )
			? sanitize_key(
				$field['type']
			)
			: '';

		$normalized = array(

			'key' => isset( $field['key'] )
				? sanitize_text_field(
					$field['key']
				)
				: '',

			'name' => isset( $field['name'] )
				? sanitize_key(
					$field['name']
				)
				: '',

			'label' => isset( $field['label'] )
				? sanitize_text_field(
					$field['label']
				)
				: '',

			'type' => $type,

			'required' => ! empty(
				$field['required']
			),

			'instructions' => isset(
				$field['instructions']
			)
				? wp_strip_all_tags(
					$field['instructions']
				)
				: '',

			'placeholder' => isset(
				$field['placeholder']
			)
				? sanitize_text_field(
					$field['placeholder']
				)
				: '',

			'default_value' => isset(
				$field['default_value']
			)
				? $this->normalize_value(
					$field['default_value']
				)
				: null,

			'constraints' => array(

				'min' => isset(
					$field['min']
				)
					? $this->normalize_value(
						$field['min']
					)
					: null,

				'max' => isset(
					$field['max']
				)
					? $this->normalize_value(
						$field['max']
					)
					: null,

				'step' => isset(
					$field['step']
				)
					? $this->normalize_value(
						$field['step']
					)
					: null,

				'maxlength' => isset(
					$field['maxlength']
				)
					? $this->normalize_value(
						$field['maxlength']
					)
					: null,
			),

			'choices' => isset(
				$field['choices']
			)
				? $this->normalize_choices(
					$field['choices']
				)
				: array(),

			'conditional_logic' => isset(
				$field['conditional_logic']
			)
				? $field['conditional_logic']
				: array(),

			/**
			 * Additional ACF configuration useful
			 * for semantic analysis.
			 */
			'config' => array(

				'multiple' => isset(
					$field['multiple']
				)
					? (bool) $field['multiple']
					: false,

				'allow_null' => isset(
					$field['allow_null']
				)
					? (bool) $field['allow_null']
					: false,

				'return_format' => isset(
					$field['return_format']
				)
					? sanitize_text_field(
						$field['return_format']
					)
					: null,

				'display_format' => isset(
					$field['display_format']
				)
					? sanitize_text_field(
						$field['display_format']
					)
					: null,

				'ui' => isset(
					$field['ui']
				)
					? (bool) $field['ui']
					: false,
			),

			'sub_fields' => array(),

			/**
			 * AI semantic layer.
			 *
			 * No research data is stored here.
			 */
			'ai' => array(

				'status' => 'pending',

				'mode' => 'auto',

				'analyzed' => false,

				'semantic_name' => null,

				'meaning' => null,

				'search_terms' => array(),

				'expected_data_type' => null,

				'unit' => null,

				'entity_type' => null,

				'confidence' => null,

				'analysis_version' => null,

				'last_analyzed_at' => null,
			),

			/**
			 * Research state only.
			 *
			 * Actual source/results belong to the
			 * future Research Result layer.
			 */
			'research' => array(

				'status' => 'not_started',

				'query' => null,

				'last_run_at' => null,

				'result_id' => null,

				'source_count' => 0,

				'confidence' => null,
			),

			/**
			 * Validation state.
			 */
			'validation' => array(

				'status' => 'not_started',

				'validated' => false,

				'confidence' => null,

				'issues' => array(),

				'last_validated_at' => null,
			),

			/**
			 * Future ACF writer state.
			 *
			 * No field is written automatically merely
			 * because it was researched.
			 */
			'writer' => array(

				'status' => 'not_started',

				'approved' => false,

				'last_written_at' => null,
			),
		);

		/**
		 * Recursively normalize nested fields.
		 */
		if (
			! empty( $field['sub_fields'] ) &&
			is_array( $field['sub_fields'] )
		) {

			foreach ( $field['sub_fields'] as $sub_field ) {

				$normalized['sub_fields'][] =
					$this->normalize_field(
						$sub_field
					);
			}
		}

		return $normalized;
	}

	/**
	 * Count field statistics recursively.
	 *
	 * @param array $fields   Fields.
	 * @param array $summary  Summary reference.
	 *
	 * @return void
	 */
	private function count_field_statistics(
		$fields,
		&$summary
	) {

		if ( empty( $fields ) ) {
			return;
		}

		foreach ( $fields as $field ) {

			if ( ! empty( $field['required'] ) ) {
				$summary['required_fields']++;
			}

			if (
				! empty( $field['sub_fields'] ) &&
				is_array( $field['sub_fields'] )
			) {

				$summary['nested_fields']++;

				$this->count_field_statistics(
					$field['sub_fields'],
					$summary
				);
			}
		}
	}

	/**
	 * Normalize generic values while preserving
	 * arrays and scalar types.
	 *
	 * @param mixed $value Value.
	 *
	 * @return mixed
	 */
	private function normalize_value( $value ) {

		if ( is_array( $value ) ) {

			$result = array();

			foreach ( $value as $key => $item ) {

				$result[ $key ] =
					$this->normalize_value(
						$item
					);
			}

			return $result;
		}

		if ( is_object( $value ) ) {

			return $this->normalize_value(
				(array) $value
			);
		}

		if (
			is_bool( $value ) ||
			is_null( $value ) ||
			is_numeric( $value )
		) {
			return $value;
		}

		return sanitize_text_field(
			(string) $value
		);
	}

	/**
	 * Normalize ACF choices.
	 *
	 * @param mixed $choices Choices.
	 *
	 * @return array
	 */
	private function normalize_choices( $choices ) {

		if ( ! is_array( $choices ) ) {
			return array();
		}

		$result = array();

		foreach ( $choices as $key => $label ) {

			$result[ sanitize_text_field(
				(string) $key
			) ] = sanitize_text_field(
				(string) $label
			);
		}

		return $result;
	}

	/**
	 * Get flat list of fields.
	 *
	 * @param string $post_type CPT slug.
	 *
	 * @return array
	 */
	public function get_fields( $post_type ) {

		$schema = $this->build(
			$post_type
		);

		if ( empty( $schema['success'] ) ) {
			return array();
		}

		if ( empty( $schema['groups'] ) ) {
			return array();
		}

		$fields = array();

		foreach ( $schema['groups'] as $group ) {

			if ( empty( $group['fields'] ) ) {
				continue;
			}

			foreach ( $group['fields'] as $field ) {

				$this->flatten_field(
					$field,
					$fields,
					$group
				);
			}
		}

		return $fields;
	}

	/**
	 * Recursively flatten fields.
	 *
	 * @param array  $field  Field.
	 * @param array  $result Result reference.
	 * @param array  $group  Parent group.
	 * @param string $parent Parent field name.
	 * @param string $path   Field path.
	 *
	 * @return void
	 */
	private function flatten_field(
		$field,
		&$result,
		$group,
		$parent = '',
		$path = ''
	) {

		$name = isset(
			$field['name']
		)
			? $field['name']
			: '';

		$current_path = $path
			? $path . '.' . $name
			: $name;

		$result[] = array(

			'group_key' => isset(
				$group['key']
			)
				? $group['key']
				: '',

			'group_title' => isset(
				$group['title']
			)
				? $group['title']
				: '',

			'parent' => $parent,

			'path' => $current_path,

			'key' => isset(
				$field['key']
			)
				? $field['key']
				: '',

			'name' => $name,

			'label' => isset(
				$field['label']
			)
				? $field['label']
				: '',

			'type' => isset(
				$field['type']
			)
				? $field['type']
				: '',

			'required' => ! empty(
				$field['required']
			),

			'ai' => isset(
				$field['ai']
			)
				? $field['ai']
				: array(),

			'research' => isset(
				$field['research']
			)
				? $field['research']
				: array(),

			'validation' => isset(
				$field['validation']
			)
				? $field['validation']
				: array(),

			'writer' => isset(
				$field['writer']
			)
				? $field['writer']
				: array(),
		);

		if (
			! empty( $field['sub_fields'] ) &&
			is_array( $field['sub_fields'] )
		) {

			foreach ( $field['sub_fields'] as $sub_field ) {

				$this->flatten_field(
					$sub_field,
					$result,
					$group,
					$name,
					$current_path
				);
			}
		}
	}

	/**
	 * Find a field by ACF field key.
	 *
	 * @param string $post_type CPT slug.
	 * @param string $field_key ACF field key.
	 *
	 * @return array|null
	 */
	public function find_field(
		$post_type,
		$field_key
	) {

		$field_key = sanitize_text_field(
			$field_key
		);

		if ( empty( $field_key ) ) {
			return null;
		}

		$fields = $this->get_fields(
			$post_type
		);

		foreach ( $fields as $field ) {

			if (
				isset( $field['key'] ) &&
				$field['key'] === $field_key
			) {
				return $field;
			}
		}

		return null;
	}

	/**
	 * Find a field by name.
	 *
	 * @param string $post_type CPT slug.
	 * @param string $field_name Field name.
	 *
	 * @return array|null
	 */
	public function find_field_by_name(
		$post_type,
		$field_name
	) {

		$field_name = sanitize_key(
			$field_name
		);

		if ( empty( $field_name ) ) {
			return null;
		}

		$fields = $this->get_fields(
			$post_type
		);

		foreach ( $fields as $field ) {

			if (
				isset( $field['name'] ) &&
				$field['name'] === $field_name
			) {
				return $field;
			}
		}

		return null;
	}

	/**
	 * Get schema summary.
	 *
	 * @param string $post_type CPT slug.
	 *
	 * @return array
	 */
	public function get_summary( $post_type ) {

		$schema = $this->build(
			$post_type
		);

		if (
			empty( $schema['success'] ) ||
			empty( $schema['summary'] )
		) {

			return array(
				'total_groups'    => 0,
				'total_fields'    => 0,
				'active_groups'   => 0,
				'required_fields' => 0,
				'nested_fields'   => 0,
			);
		}

		return $schema['summary'];
	}

	/**
	 * Get complete schema.
	 *
	 * @param string $post_type CPT slug.
	 * @param bool   $refresh Rebuild schema.
	 *
	 * @return array
	 */
	public function get_schema(
		$post_type,
		$refresh = false
	) {

		return $this->build(
			$post_type,
			$refresh
		);
	}

	/**
	 * Clear schema cache.
	 *
	 * @param string|null $post_type CPT slug.
	 *
	 * @return void
	 */
	public function clear_cache(
		$post_type = null
	) {

		if ( null === $post_type ) {

			$this->schema_cache = array();

			return;
		}

		$post_type = sanitize_key(
			$post_type
		);

		if (
			isset(
				$this->schema_cache[ $post_type ]
			)
		) {

			unset(
				$this->schema_cache[ $post_type ]
			);
		}
	}

	/**
	 * Export schema as JSON.
	 *
	 * @param string $post_type CPT slug.
	 *
	 * @return string
	 */
	public function to_json(
		$post_type
	) {

		$schema = $this->build(
			$post_type
		);

		return wp_json_encode(
			$schema,
			JSON_UNESCAPED_UNICODE |
			JSON_UNESCAPED_SLASHES |
			JSON_PRETTY_PRINT
		);
	}
}