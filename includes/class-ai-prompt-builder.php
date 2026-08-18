<?php
/**
 * AI Prompt Builder
 *
 * Builds dynamic prompts for vehicle research.
 *
 * IMPORTANT:
 * Vehicle fields are never hard-coded here.
 * The current ACF schema is injected dynamically.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TL_AI_VM_Prompt_Builder {

	/**
	 * Singleton instance.
	 *
	 * @var TL_AI_VM_Prompt_Builder|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return TL_AI_VM_Prompt_Builder
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
	 * Build the main vehicle research prompt.
	 *
	 * @param array $vehicle Vehicle information.
	 * @param array $fields  Current ACF fields.
	 * @param array $sources Optional source information.
	 *
	 * @return string
	 */
	public function build_vehicle_prompt(
		$vehicle,
		$fields,
		$sources = array()
	) {

		$vehicle = is_array( $vehicle )
			? $vehicle
			: array();

		$fields = is_array( $fields )
			? $fields
			: array();

		$sources = is_array( $sources )
			? $sources
			: array();

		$vehicle_context =
			$this->build_vehicle_context(
				$vehicle
			);

		$field_schema =
			$this->build_field_schema(
				$fields
			);

		$source_context =
			$this->build_source_context(
				$sources
			);

		$rules =
			$this->build_research_rules();

		$output =
			$this->build_output_rules(
				$fields
			);

		$prompt  = '';
		$prompt .= "You are an automotive research assistant for Tuningland.\n\n";

		$prompt .= "Your task is to research the specified vehicle and ";
		$prompt .= "return structured data for the current ACF schema.\n\n";

		$prompt .= "VEHICLE:\n";
		$prompt .= $vehicle_context;
		$prompt .= "\n\n";

		$prompt .= "CURRENT ACF FIELD SCHEMA:\n";
		$prompt .= $field_schema;
		$prompt .= "\n\n";

		$prompt .= "AVAILABLE SOURCES:\n";
		$prompt .= $source_context;
		$prompt .= "\n\n";

		$prompt .= "RESEARCH RULES:\n";
		$prompt .= $rules;
		$prompt .= "\n\n";

		$prompt .= "OUTPUT RULES:\n";
		$prompt .= $output;

		return trim( $prompt );
	}

	/**
	 * Build vehicle context.
	 *
	 * @param array $vehicle Vehicle data.
	 *
	 * @return string
	 */
	private function build_vehicle_context(
		$vehicle
	) {

		if ( empty( $vehicle ) ) {
			return 'No vehicle information was provided.';
		}

		$lines = array();

		foreach ( $vehicle as $key => $value ) {

			if ( is_array( $value ) ) {

				$value = implode(
					', ',
					array_map(
						'strval',
						$value
					)
				);
			}

			if ( is_object( $value ) ) {
				continue;
			}

			$key = sanitize_text_field(
				$key
			);

			$value = sanitize_text_field(
				(string) $value
			);

			if ( '' === $value ) {
				continue;
			}

			$lines[] =
				'- ' .
				$key .
				': ' .
				$value;
		}

		if ( empty( $lines ) ) {
			return 'No vehicle information was provided.';
		}

		return implode(
			"\n",
			$lines
		);
	}

	/**
	 * Build dynamic ACF schema.
	 *
	 * @param array $fields ACF fields.
	 *
	 * @return string
	 */
	private function build_field_schema(
		$fields
	) {

		if ( empty( $fields ) ) {
			return 'No ACF fields were detected.';
		}

		$schema = array();

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
				sanitize_key(
					$field['name']
				);

			$label =
				isset(
					$field['label']
				)
					? sanitize_text_field(
						$field['label']
					)
					: '';

			$type =
				isset(
					$field['type']
				)
					? sanitize_key(
						$field['type']
					)
					: 'text';

			$required =
				! empty(
					$field['required']
				)
					? 'yes'
					: 'no';

			$line = array();

			$line[] =
				'name=' .
				$name;

			$line[] =
				'label=' .
				$label;

			$line[] =
				'type=' .
				$type;

			$line[] =
				'required=' .
				$required;

			/**
			 * Include choices dynamically.
			 */
			if (
				! empty(
					$field['choices']
				) &&
				is_array(
					$field['choices']
				)
			) {

				$choices = array();

				foreach (
					$field['choices']
					as $choice_key =>
					$choice_label
				) {

					$choices[] =
						(string) $choice_key .
						'=' .
						(string) $choice_label;
				}

				$line[] =
					'choices=' .
					implode(
						' | ',
						$choices
					);
			}

			/**
			 * Parent field information.
			 */
			if (
				! empty(
					$field['parent_name']
				)
			) {

				$line[] =
					'parent=' .
					sanitize_key(
						$field['parent_name']
					);
			}

			$schema[] =
				'- ' .
				implode(
					' ; ',
					$line
				);
		}

		if ( empty( $schema ) ) {
			return 'No usable ACF fields were detected.';
		}

		return implode(
			"\n",
			$schema
		);
	}

	/**
	 * Build source context.
	 *
	 * @param array $sources Sources.
	 *
	 * @return string
	 */
	private function build_source_context(
		$sources
	) {

		if ( empty( $sources ) ) {

			return
				'Use reliable automotive sources available to you. ' .
				'Do not invent URLs or source information.';
		}

		$lines = array();

		foreach ( $sources as $source ) {

			if ( is_string( $source ) ) {

				$lines[] =
					'- ' .
					sanitize_text_field(
						$source
					);

				continue;
			}

			if ( ! is_array( $source ) ) {
				continue;
			}

			$name =
				isset(
					$source['name']
				)
					? sanitize_text_field(
						$source['name']
					)
					: '';

			$url =
				isset(
					$source['url']
				)
					? esc_url_raw(
						$source['url']
					)
					: '';

			if ( empty( $name ) && empty( $url ) ) {
				continue;
			}

			$line = '- ';

			if ( ! empty( $name ) ) {
				$line .= $name;
			}

			if ( ! empty( $url ) ) {
				$line .= ' (' . $url . ')';
			}

			$lines[] = $line;
		}

		if ( empty( $lines ) ) {
			return
				'Use reliable automotive sources available to you.';
		}

		return implode(
			"\n",
			$lines
		);
	}

	/**
	 * Research rules.
	 *
	 * @return string
	 */
	private function build_research_rules() {

		$rules = array();

		$rules[] =
			'1. Research the exact vehicle, not a similar model.';

		$rules[] =
			'2. Pay attention to generation, facelift, engine and production year.';

		$rules[] =
			'3. Prefer official manufacturer information when available.';

		$rules[] =
			'4. Cross-check important technical information using multiple reliable sources.';

		$rules[] =
			'5. Do not invent information when reliable data cannot be found.';

		$rules[] =
			'6. If information is uncertain, return null or mark it as uncertain.';

		$rules[] =
			'7. Preserve the units used by reliable automotive sources unless the field clearly requires another format.';

		$rules[] =
			'8. Distinguish between different engine, trim and market versions.';

		$rules[] =
			'9. Never silently assume that information from one generation applies to another generation.';

		$rules[] =
			'10. The ACF schema supplied in this prompt is dynamic and represents the fields that currently exist on the website.';

		$rules[] =
			'11. Fill every field for which reliable information can be found.';

		$rules[] =
			'12. Do not create additional fields that are not present in the supplied schema.';

		return implode(
			"\n",
			$rules
		);
	}

	/**
	 * Build output instructions.
	 *
	 * @param array $fields ACF fields.
	 *
	 * @return string
	 */
	private function build_output_rules(
		$fields
	) {

		$field_names = array();

		foreach ( $fields as $field ) {

			if (
				is_array( $field ) &&
				! empty(
					$field['name']
				)
			) {

				$field_names[] =
					sanitize_key(
						$field['name']
					);
			}
		}

		$allowed =
			implode(
				', ',
				$field_names
			);

		$output = array();

		$output[] =
			'Return ONLY valid JSON.';

		$output[] =
			'Use the exact ACF field names as JSON keys.';

		$output[] =
			'Do not rename, translate or invent field names.';

		$output[] =
			'Only use fields that exist in the supplied schema.';

		$output[] =
			'If a field cannot be reliably determined, use null.';

		$output[] =
			'Do not put explanations outside the JSON object.';

		if ( ! empty( $allowed ) ) {

			$output[] =
				'Allowed field names: ' .
				$allowed;
		}

		$output[] =
			'For each populated field, preserve the appropriate value type according to its ACF type.';

		$output[] =
			'When possible, include a confidence value and source information separately from the actual ACF value.';

		$output[] =
			'Recommended JSON structure: {"fields": {...}, "confidence": {...}, "sources": {...}}';

		return implode(
			"\n",
			$output
		);
	}

	/**
	 * Build a lightweight prompt for testing.
	 *
	 * @param array $vehicle Vehicle.
	 * @param array $fields  Fields.
	 *
	 * @return string
	 */
	public function build_test_prompt(
		$vehicle,
		$fields
	) {

		return $this->build_vehicle_prompt(
			$vehicle,
			$fields
		);
	}

}