<?php
/**
 * Data Validator
 *
 * Validates AI/research results before they are written
 * into ACF fields.
 *
 * This class is dynamic and does not hard-code vehicle fields.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TL_AI_VM_Data_Validator {

	/**
	 * Singleton instance.
	 *
	 * @var TL_AI_VM_Data_Validator|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return TL_AI_VM_Data_Validator
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
	 * Validate a complete set of field values.
	 *
	 * @param array $fields Field definitions.
	 * @param array $data   Values.
	 * @param array $args   Validation options.
	 *
	 * @return array
	 */
	public function validate(
		$fields,
		$data,
		$args = array()
	) {

		if ( ! is_array( $fields ) ) {

			return array(
				'success' => false,
				'valid'   => false,
				'errors'  => array(
					'Invalid field definitions.',
				),
				'warnings' => array(),
				'fields'  => array(),
			);
		}

		if ( ! is_array( $data ) ) {

			return array(
				'success' => false,
				'valid'   => false,
				'errors'  => array(
					'Invalid data.',
				),
				'warnings' => array(),
				'fields'  => array(),
			);
		}

		$defaults = array(
			'require_required_fields' => false,
			'allow_empty'             => true,
			'strict_choices'          => true,
			'min_confidence'          => 0,
		);

		$args = wp_parse_args(
			$args,
			$defaults
		);

		$field_map = $this->build_field_map(
			$fields
		);

		$validated = array();
		$errors    = array();
		$warnings  = array();

		/**
		 * Validate supplied values.
		 */
		foreach ( $data as $field_name => $raw_value ) {

			$field_name = sanitize_key(
				$field_name
			);

			if ( empty( $field_name ) ) {
				continue;
			}

			if ( ! isset( $field_map[ $field_name ] ) ) {

				$warnings[] = array(
					'field'   => $field_name,
					'message' => 'Field does not exist in the current ACF schema.',
				);

				continue;
			}

			$field = $field_map[ $field_name ];

			$result = $this->validate_field(
				$field,
				$raw_value,
				$args
			);

			$validated[ $field_name ] =
				$result;

			if ( ! empty( $result['errors'] ) ) {

				foreach (
					$result['errors']
					as $error
				) {

					$errors[] = array(
						'field'   => $field_name,
						'message' => $error,
					);
				}
			}

			if ( ! empty( $result['warnings'] ) ) {

				foreach (
					$result['warnings']
					as $warning
				) {

					$warnings[] = array(
						'field'   => $field_name,
						'message' => $warning,
					);
				}
			}
		}

		/**
		 * Check required fields.
		 */
		if (
			! empty(
				$args['require_required_fields']
			)
		) {

			foreach ( $field_map as $field_name => $field ) {

				if (
					empty(
						$field['required']
					)
				) {
					continue;
				}

				if (
					! array_key_exists(
						$field_name,
						$data
					)
				) {

					$errors[] = array(
						'field'   => $field_name,
						'message' => 'Required field is missing.',
					);

					continue;
				}

				if (
					$this->is_empty_value(
						$data[
							$field_name
						]
					)
				) {

					$errors[] = array(
						'field'   => $field_name,
						'message' => 'Required field is empty.',
					);
				}
			}
		}

		$valid = empty( $errors );

		return array(
			'success' =>
				true,

			'valid' =>
				$valid,

			'total_fields' =>
				count(
					$validated
				),

			'valid_fields' =>
				$this->count_valid_fields(
					$validated
				),

			'invalid_fields' =>
				$this->count_invalid_fields(
					$validated
				),

			'errors' =>
				$errors,

			'warnings' =>
				$warnings,

			'fields' =>
				$validated,
		);
	}

	/**
	 * Validate a single field.
	 *
	 * @param array $field Field definition.
	 * @param mixed $value Value.
	 * @param array $args  Options.
	 *
	 * @return array
	 */
	public function validate_field(
		$field,
		$value,
		$args = array()
	) {

		$errors   = array();
		$warnings = array();

		$type = isset(
			$field['type']
		)
			? sanitize_key(
				$field['type']
			)
			: 'text';

		/**
		 * Confidence check.
		 *
		 * AI results may arrive as:
		 *
		 * value => ...
		 * confidence => 0.87
		 */
		$confidence = null;

		if (
			is_array( $value ) &&
			isset(
				$value['value']
			)
		) {

			$confidence =
				isset(
					$value['confidence']
				)
					? (float) $value['confidence']
					: null;

			$value =
				$value['value'];
		}

		if (
			null !== $confidence &&
			$confidence <
			(float) $args['min_confidence']
		) {

			$warnings[] =
				'AI confidence is below the configured threshold.';
		}

		/**
		 * Empty values.
		 */
		if (
			$this->is_empty_value(
				$value
			)
		) {

			if (
				! empty(
					$field['required']
				) &&
				! empty(
					$args[
						'require_required_fields'
					]
				)
			) {

				$errors[] =
					'Required field is empty.';
			}

			return array(
				'valid'      => empty( $errors ),
				'value'      => $value,
				'type'       => $type,
				'confidence' =>
					$confidence,
				'errors'     => $errors,
				'warnings'   => $warnings,
			);
		}

		/**
		 * Type validation.
		 */
		switch ( $type ) {

			case 'number':

				$result =
					$this->validate_number(
						$value,
						$field
					);

				break;

			case 'text':
			case 'textarea':
			case 'wysiwyg':

				$result =
					$this->validate_text(
						$value,
						$field
					);

				break;

			case 'url':

				$result =
					$this->validate_url(
						$value
					);

				break;

			case 'email':

				$result =
					$this->validate_email(
						$value
					);

				break;

			case 'select':
			case 'radio':

				$result =
					$this->validate_choice(
						$value,
						$field
					);

				break;

			case 'checkbox':

				$result =
					$this->validate_checkbox(
						$value,
						$field
					);

				break;

			case 'true_false':

				$result =
					$this->validate_boolean(
						$value
					);

				break;

			default:

				$result = array(
					'valid'    => true,
					'value'    => $value,
					'errors'   => array(),
					'warnings' => array(),
				);

				break;
		}

		if ( ! empty( $result['errors'] ) ) {

			$errors = array_merge(
				$errors,
				$result['errors']
			);
		}

		if ( ! empty( $result['warnings'] ) ) {

			$warnings = array_merge(
				$warnings,
				$result['warnings']
			);
		}

		return array(
			'valid' =>
				empty( $errors ),

			'value' =>
				isset(
					$result['value']
				)
					? $result['value']
					: $value,

			'type' =>
				$type,

			'confidence' =>
				$confidence,

			'errors' =>
				$errors,

			'warnings' =>
				$warnings,
		);
	}

	/**
	 * Validate number.
	 *
	 * @param mixed $value Value.
	 * @param array $field Field.
	 *
	 * @return array
	 */
	private function validate_number(
		$value,
		$field
	) {

		$errors = array();

		if ( is_string( $value ) ) {

			$value = trim(
				$value
			);

			$value = str_replace(
				',',
				'.',
				$value
			);
		}

		if ( ! is_numeric( $value ) ) {

			return array(
				'valid'    => false,
				'value'    => $value,
				'errors'   => array(
					'Value must be numeric.',
				),
				'warnings' => array(),
			);
		}

		$value = (float) $value;

		/**
		 * ACF minimum.
		 */
		if (
			isset(
				$field['min']
			) &&
			'' !== $field['min'] &&
			is_numeric(
				$field['min']
			) &&
			$value <
			(float) $field['min']
		) {

			$errors[] =
				'Value is below the allowed minimum.';
		}

		/**
		 * ACF maximum.
		 */
		if (
			isset(
				$field['max']
			) &&
			'' !== $field['max'] &&
			is_numeric(
				$field['max']
			) &&
			$value >
			(float) $field['max']
		) {

			$errors[] =
				'Value is above the allowed maximum.';
		}

		return array(
			'valid'    => empty( $errors ),
			'value'    => $value,
			'errors'   => $errors,
			'warnings' => array(),
		);
	}

	/**
	 * Validate text.
	 *
	 * @param mixed $value Value.
	 * @param array $field Field.
	 *
	 * @return array
	 */
	private function validate_text(
		$value,
		$field
	) {

		$errors = array();

		if (
			is_array( $value ) ||
			is_object( $value )
		) {

			return array(
				'valid'    => false,
				'value'    => $value,
				'errors'   => array(
					'Text field cannot contain an object or array.',
				),
				'warnings' => array(),
			);
		}

		$value = (string) $value;

		/**
		 * ACF maxlength.
		 */
		if (
			isset(
				$field['maxlength']
			) &&
			is_numeric(
				$field['maxlength']
			) &&
			mb_strlen(
				$value
			) >
			(int) $field['maxlength']
		) {

			$errors[] =
				'Text exceeds the maximum allowed length.';
		}

		return array(
			'valid'    => empty( $errors ),
			'value'    => $value,
			'errors'   => $errors,
			'warnings' => array(),
		);
	}

	/**
	 * Validate URL.
	 *
	 * @param mixed $value URL.
	 *
	 * @return array
	 */
	private function validate_url(
		$value
	) {

		$value = esc_url_raw(
			(string) $value
		);

		if (
			empty( $value ) ||
			! wp_http_validate_url(
				$value
			)
		) {

			return array(
				'valid'    => false,
				'value'    => $value,
				'errors'   => array(
					'Invalid URL.',
				),
				'warnings' => array(),
			);
		}

		return array(
			'valid'    => true,
			'value'    => $value,
			'errors'   => array(),
			'warnings' => array(),
		);
	}

	/**
	 * Validate email.
	 *
	 * @param mixed $value Email.
	 *
	 * @return array
	 */
	private function validate_email(
		$value
	) {

		$value = sanitize_email(
			(string) $value
		);

		if (
			empty( $value ) ||
			! is_email( $value )
		) {

			return array(
				'valid'    => false,
				'value'    => $value,
				'errors'   => array(
					'Invalid email address.',
				),
				'warnings' => array(),
			);
		}

		return array(
			'valid'    => true,
			'value'    => $value,
			'errors'   => array(),
			'warnings' => array(),
		);
	}

	/**
	 * Validate select/radio choice.
	 *
	 * @param mixed $value Value.
	 * @param array $field Field.
	 *
	 * @return array
	 */
	private function validate_choice(
		$value,
		$field
	) {

		$choices = isset(
			$field['choices']
		) &&
		is_array(
			$field['choices']
		)
			? $field['choices']
			: array();

		if ( empty( $choices ) ) {

			return array(
				'valid'    => true,
				'value'    => $value,
				'errors'   => array(),
				'warnings' => array(),
			);
		}

		$value_string = (string) $value;

		/**
		 * ACF choices can have:
		 *
		 * key => label
		 */
		if (
			array_key_exists(
				$value_string,
				$choices
			)
		) {

			return array(
				'valid'    => true,
				'value'    => $value_string,
				'errors'   => array(),
				'warnings' => array(),
			);
		}

		/**
		 * Also accept the displayed label.
		 */
		foreach ( $choices as $key => $label ) {

			if (
				$this->normalize_text(
					$label
				) ===
				$this->normalize_text(
					$value_string
				)
			) {

				return array(
					'valid'    => true,
					'value'    => $key,
					'errors'   => array(),
					'warnings' => array(),
				);
			}
		}

		return array(
			'valid'    => false,
			'value'    => $value,
			'errors'   => array(
				'Value is not one of the allowed choices.',
			),
			'warnings' => array(),
		);
	}

	/**
	 * Validate checkbox.
	 *
	 * @param mixed $value Value.
	 * @param array $field Field.
	 *
	 * @return array
	 */
	private function validate_checkbox(
		$value,
		$field
	) {

		if ( ! is_array( $value ) ) {

			$value = array(
				$value,
			);
		}

		$choices = isset(
			$field['choices']
		) &&
		is_array(
			$field['choices']
		)
			? $field['choices']
			: array();

		if ( empty( $choices ) ) {

			return array(
				'valid'    => true,
				'value'    => $value,
				'errors'   => array(),
				'warnings' => array(),
			);
		}

		$valid_values = array_keys(
			$choices
		);

		$invalid = array();

		foreach ( $value as $item ) {

			if (
				! in_array(
					(string) $item,
					array_map(
						'strval',
						$valid_values
					),
					true
				)
			) {

				$invalid[] = $item;
			}
		}

		if ( ! empty( $invalid ) ) {

			return array(
				'valid'    => false,
				'value'    => $value,
				'errors'   => array(
					'One or more checkbox values are invalid.',
				),
				'warnings' => array(),
			);
		}

		return array(
			'valid'    => true,
			'value'    => $value,
			'errors'   => array(),
			'warnings' => array(),
		);
	}

	/**
	 * Validate true/false field.
	 *
	 * @param mixed $value Value.
	 *
	 * @return array
	 */
	private function validate_boolean(
		$value
	) {

		if (
			is_bool( $value ) ||
			0 === $value ||
			1 === $value
		) {

			return array(
				'valid'    => true,
				'value'    => (int) $value,
				'errors'   => array(),
				'warnings' => array(),
			);
		}

		$normalized =
			$this->normalize_text(
				$value
			);

		if (
			in_array(
				$normalized,
				array(
					'true',
					'yes',
					'on',
					'1',
					'بله',
					'دارد',
				),
				true
			)
		) {

			return array(
				'valid'    => true,
				'value'    => 1,
				'errors'   => array(),
				'warnings' => array(),
			);
		}

		if (
			in_array(
				$normalized,
				array(
					'false',
					'no',
					'off',
					'0',
					'خیر',
					'ندارد',
				),
				true
			)
		) {

			return array(
				'valid'    => true,
				'value'    => 0,
				'errors'   => array(),
				'warnings' => array(),
			);
		}

		return array(
			'valid'    => false,
			'value'    => $value,
			'errors'   => array(
				'Value could not be interpreted as true or false.',
			),
			'warnings' => array(),
		);
	}

	/**
	 * Build field map.
	 *
	 * @param array $fields Fields.
	 *
	 * @return array
	 */
	private function build_field_map(
		$fields
	) {

		$map = array();

		foreach ( $fields as $field ) {

			if (
				! is_array( $field ) ||
				empty(
					$field['name']
				)
			) {
				continue;
			}

			$name = sanitize_key(
				$field['name']
			);

			$map[ $name ] = $field;
		}

		return $map;
	}

	/**
	 * Check empty value.
	 *
	 * @param mixed $value Value.
	 *
	 * @return bool
	 */
	private function is_empty_value(
		$value
	) {

		if ( null === $value ) {
			return true;
		}

		if ( is_string( $value ) ) {
			return '' === trim( $value );
		}

		if ( is_array( $value ) ) {
			return empty( $value );
		}

		return false;
	}

	/**
	 * Count valid fields.
	 *
	 * @param array $fields Results.
	 *
	 * @return int
	 */
	private function count_valid_fields(
		$fields
	) {

		$count = 0;

		foreach ( $fields as $field ) {

			if (
				isset(
					$field['valid']
				) &&
				$field['valid']
			) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Count invalid fields.
	 *
	 * @param array $fields Results.
	 *
	 * @return int
	 */
	private function count_invalid_fields(
		$fields
	) {

		$count = 0;

		foreach ( $fields as $field ) {

			if (
				isset(
					$field['valid']
				) &&
				! $field['valid']
			) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Normalize text.
	 *
	 * @param mixed $text Text.
	 *
	 * @return string
	 */
	private function normalize_text(
		$text
	) {

		if ( ! is_string( $text ) ) {
			$text = (string) $text;
		}

		$text = strtolower(
			trim(
				$text
			)
		);

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
			'ك',
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

}