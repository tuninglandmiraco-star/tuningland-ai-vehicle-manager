<?php
/**
 * Field Mapper
 *
 * Maps extracted/researched vehicle data to dynamically
 * discovered ACF fields.
 *
 * No vehicle field names are hard-coded here.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TL_AI_VM_Field_Mapper {

	/**
	 * Singleton instance.
	 *
	 * @var TL_AI_VM_Field_Mapper|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return TL_AI_VM_Field_Mapper
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
	 * Build a searchable field map.
	 *
	 * @param array $fields ACF fields.
	 *
	 * @return array
	 */
	public function build_map( $fields ) {

		if ( ! is_array( $fields ) ) {
			return array();
		}

		$map = array();

		foreach ( $fields as $field ) {

			if ( ! is_array( $field ) ) {
				continue;
			}

			$name = isset( $field['name'] )
				? sanitize_key( $field['name'] )
				: '';

			if ( empty( $name ) ) {
				continue;
			}

			$map[ $name ] = array(
				'key'          => isset( $field['key'] )
					? sanitize_text_field( $field['key'] )
					: '',

				'name'         => $name,

				'label'        => isset( $field['label'] )
					? sanitize_text_field( $field['label'] )
					: '',

				'type'         => isset( $field['type'] )
					? sanitize_key( $field['type'] )
					: '',

				'required'     => ! empty( $field['required'] ),

				'parent_name'  => isset( $field['parent_name'] )
					? sanitize_key( $field['parent_name'] )
					: '',

				'group_key'    => isset( $field['group_key'] )
					? sanitize_text_field( $field['group_key'] )
					: '',

				'group_title'  => isset( $field['group_title'] )
					? sanitize_text_field( $field['group_title'] )
					: '',

				'choices'      => isset( $field['choices'] )
					&& is_array( $field['choices'] )
					? $field['choices']
					: array(),
			);
		}

		return $map;
	}

	/**
	 * Map extracted data to ACF fields.
	 *
	 * @param array $fields       ACF fields.
	 * @param array $extracted    Extracted data.
	 * @param array $context      Vehicle context.
	 *
	 * @return array
	 */
	public function map(
		$fields,
		$extracted,
		$context = array()
	) {

		if ( ! is_array( $fields ) ) {

			return array(
				'success' => false,
				'error'   => 'Invalid ACF fields.',
				'fields'  => array(),
			);
		}

		if ( ! is_array( $extracted ) ) {

			return array(
				'success' => false,
				'error'   => 'Invalid extracted data.',
				'fields'  => array(),
			);
		}

		$field_map = $this->build_map(
			$fields
		);

		$mapped = array();

		foreach ( $extracted as $key => $value ) {

			/**
			 * If the extracted result already uses
			 * an exact ACF field name, use it directly.
			 */
			$exact_name = sanitize_key(
				$key
			);

			if (
				isset(
					$field_map[ $exact_name ]
				)
			) {

				$mapped[ $exact_name ] =
					$this->prepare_mapping(
						$field_map[
							$exact_name
						],
						$value,
						'exact'
					);

				continue;
			}

			/**
			 * Otherwise try intelligent matching.
			 */
			$match = $this->find_best_field(
				$key,
				$field_map,
				$context
			);

			if ( empty( $match ) ) {
				continue;
			}

			$mapped[
				$match['name']
			] = $this->prepare_mapping(
				$match,
				$value,
				$match['method']
			);
		}

		return array(
			'success' =>
				! empty( $mapped ),

			'total' =>
				count( $mapped ),

			'fields' =>
				$mapped,
		);
	}

	/**
	 * Find the best matching ACF field.
	 *
	 * @param string $source_key Source key.
	 * @param array  $field_map  Field map.
	 * @param array  $context    Context.
	 *
	 * @return array|null
	 */
	private function find_best_field(
		$source_key,
		$field_map,
		$context = array()
	) {

		$source_key = $this->normalize_text(
			$source_key
		);

		if ( empty( $source_key ) ) {
			return null;
		}

		$best       = null;
		$best_score = 0;

		foreach ( $field_map as $field ) {

			$score = 0;

			$field_name = $this->normalize_text(
				$field['name']
			);

			$field_label = $this->normalize_text(
				$field['label']
			);

			/**
			 * Exact normalized name.
			 */
			if (
				$source_key === $field_name
			) {
				$score += 100;
			}

			/**
			 * Name contains source.
			 */
			if (
				! empty( $field_name ) &&
				false !== strpos(
					$field_name,
					$source_key
				)
			) {
				$score += 45;
			}

			/**
			 * Source contains field name.
			 */
			if (
				! empty( $field_name ) &&
				false !== strpos(
					$source_key,
					$field_name
				)
			) {
				$score += 45;
			}

			/**
			 * Label matching.
			 */
			if (
				! empty( $field_label ) &&
				$source_key === $field_label
			) {
				$score += 90;
			}

			if (
				! empty( $field_label ) &&
				false !== strpos(
					$field_label,
					$source_key
				)
			) {
				$score += 35;
			}

			/**
			 * Token similarity.
			 */
			$similarity = $this->token_similarity(
				$source_key,
				$field_name . ' ' . $field_label
			);

			$score += (int) (
				$similarity * 40
			);

			/**
			 * Context can optionally provide hints.
			 */
			if (
				isset(
					$context['preferred_fields']
				) &&
				is_array(
					$context[
						'preferred_fields'
					]
				) &&
				in_array(
					$field['name'],
					$context[
						'preferred_fields'
					],
					true
				)
			) {
				$score += 20;
			}

			if ( $score > $best_score ) {

				$best_score = $score;

				$best = $field;

				$best['score'] = $score;

				$best['method'] =
					$score >= 90
						? 'strong_match'
						: 'semantic_match';
			}
		}

		/**
		 * Avoid unsafe mappings.
		 *
		 * A weak match should not automatically overwrite
		 * an ACF field.
		 */
		if (
			null === $best ||
			$best_score < 35
		) {
			return null;
		}

		return $best;
	}

	/**
	 * Prepare mapped value.
	 *
	 * @param array  $field  Field.
	 * @param mixed  $value  Value.
	 * @param string $method Mapping method.
	 *
	 * @return array
	 */
	private function prepare_mapping(
		$field,
		$value,
		$method
	) {

		return array(
			'field_key' =>
				$field['key'],

			'field_name' =>
				$field['name'],

			'field_label' =>
				$field['label'],

			'field_type' =>
				$field['type'],

			'value' =>
				$this->normalize_value(
					$value,
					$field
				),

			'mapping_method' =>
				sanitize_key(
					$method
				),

			'confidence' =>
				$this->mapping_confidence(
					$method
				),
		);
	}

	/**
	 * Normalize value according to ACF type.
	 *
	 * @param mixed $value Value.
	 * @param array $field Field.
	 *
	 * @return mixed
	 */
	private function normalize_value(
		$value,
		$field
	) {

		$type = isset(
			$field['type']
		)
			? $field['type']
			: 'text';

		if (
			is_array( $value ) ||
			is_object( $value )
		) {
			return $value;
		}

		$value = trim(
			(string) $value
		);

		switch ( $type ) {

			case 'number':

				$value = str_replace(
					',',
					'.',
					$value
				);

				$value = preg_replace(
					'/[^0-9.\-+]/',
					'',
					$value
				);

				return is_numeric(
					$value
				)
					? $value
					: '';

			case 'url':

				return esc_url_raw(
					$value
				);

			case 'email':

				return sanitize_email(
					$value
				);

			case 'textarea':

				return sanitize_textarea_field(
					$value
				);

			case 'wysiwyg':

				return wp_kses_post(
					$value
				);

			case 'true_false':

				$normalized =
					$this->normalize_text(
						$value
					);

				if (
					in_array(
						$normalized,
						array(
							'1',
							'true',
							'yes',
							'on',
							'بله',
							'دارد',
							'دارد.',
						),
						true
					)
				) {
					return 1;
				}

				if (
					in_array(
						$normalized,
						array(
							'0',
							'false',
							'no',
							'off',
							'خیر',
							'ندارد',
							'ندارد.',
						),
						true
					)
				) {
					return 0;
				}

				return '';

			default:

				return sanitize_text_field(
					$value
				);
		}
	}

	/**
	 * Calculate mapping confidence.
	 *
	 * @param string $method Method.
	 *
	 * @return float
	 */
	private function mapping_confidence(
		$method
	) {

		switch ( $method ) {

			case 'exact':
				return 1.0;

			case 'strong_match':
				return 0.9;

			case 'semantic_match':
				return 0.65;

			default:
				return 0.5;
		}
	}

	/**
	 * Normalize text for matching.
	 *
	 * @param string $text Text.
	 *
	 * @return string
	 */
	private function normalize_text(
		$text
	) {

		if ( ! is_string( $text ) ) {
			return '';
		}

		$text = wp_strip_all_tags(
			$text
		);

		$text = strtolower(
			trim(
				$text
			)
		);

		/**
		 * Normalize common separators.
		 */
		$text = str_replace(
			array(
				'_',
				'-',
				'/',
				'|',
				':',
				'.',
			),
			' ',
			$text
		);

		/**
		 * Normalize Persian/Arabic characters.
		 */
		$text = str_replace(
			array(
				'ي',
				'ى',
				'ئ',
			),
			'ی',
			$text
		);

		$text = str_replace(
			array(
				'ك',
			),
			'ک',
			$text
		);

		$text = preg_replace(
			'/\s+/u',
			' ',
			$text
		);

		return trim(
			$text
		);
	}

	/**
	 * Calculate token similarity.
	 *
	 * @param string $source Source.
	 * @param string $target Target.
	 *
	 * @return float
	 */
	private function token_similarity(
		$source,
		$target
	) {

		$source = $this->normalize_text(
			$source
		);

		$target = $this->normalize_text(
			$target
		);

		if (
			empty( $source ) ||
			empty( $target )
		) {
			return 0;
		}

		$source_tokens =
			array_unique(
				preg_split(
					'/\s+/u',
					$source
				)
			);

		$target_tokens =
			array_unique(
				preg_split(
					'/\s+/u',
					$target
				)
			);

		if (
			empty( $source_tokens ) ||
			empty( $target_tokens )
		) {
			return 0;
		}

		$intersection = array_intersect(
			$source_tokens,
			$target_tokens
		);

		return count(
			$intersection
		) / max(
			count(
				$source_tokens
			),
			count(
				$target_tokens
			)
		);
	}

	/**
	 * Generate a prompt-friendly field schema.
	 *
	 * This will later be sent to the AI so the AI
	 * understands which fields exist right now.
	 *
	 * @param array $fields ACF fields.
	 *
	 * @return array
	 */
	public function get_ai_schema(
		$fields
	) {

		if ( ! is_array( $fields ) ) {
			return array();
		}

		$schema = array();

		foreach ( $fields as $field ) {

			if ( ! is_array( $field ) ) {
				continue;
			}

			if (
				empty(
					$field['name']
				)
			) {
				continue;
			}

			$schema[] = array(
				'name' =>
					$field['name'],

				'label' =>
					isset(
						$field['label']
					)
						? $field['label']
						: '',

				'type' =>
					isset(
						$field['type']
					)
						? $field['type']
						: 'text',

				'required' =>
					! empty(
						$field['required']
					),

				'choices' =>
					isset(
						$field['choices']
					) &&
					is_array(
						$field['choices']
					)
						? $field['choices']
						: array(),
			);
		}

		return $schema;
	}

}