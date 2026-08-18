<?php
/**
 * Data Extractor
 *
 * Extracts structured vehicle information from
 * web/search results.
 *
 * This class is intentionally generic:
 * it does not know vehicle field names in advance.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TL_AI_VM_Data_Extractor {

	/**
	 * Singleton instance.
	 *
	 * @var TL_AI_VM_Data_Extractor|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return TL_AI_VM_Data_Extractor
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
	 * Extract a field value from supplied content.
	 *
	 * This method prepares the extraction request.
	 * The actual AI extraction can be connected later.
	 *
	 * @param array  $field   ACF field definition.
	 * @param string $content Source content.
	 * @param array  $context Vehicle/context information.
	 *
	 * @return array
	 */
	public function extract_field(
		$field,
		$content,
		$context = array()
	) {

		if ( ! is_array( $field ) ) {

			return array(
				'success' => false,
				'error'   => 'Invalid field definition.',
				'value'   => null,
			);
		}

		$content = $this->clean_content(
			$content
		);

		if ( empty( $content ) ) {

			return array(
				'success' => false,
				'error'   => 'Source content is empty.',
				'value'   => null,
			);
		}

		$field_data = $this->normalize_field(
			$field
		);

		/**
		 * Give the AI extraction engine an opportunity
		 * to process the field.
		 */
		$response = apply_filters(
			'tl_ai_vm_extract_field',
			null,
			$field_data,
			$content,
			$context
		);

		if (
			is_array( $response ) &&
			isset( $response['success'] )
		) {

			return $this->normalize_extraction_response(
				$response,
				$field_data
			);
		}

		/**
		 * Generic local extraction.
		 *
		 * This is intentionally conservative.
		 * We only try to detect obvious values from
		 * structured HTML/text. Complex extraction
		 * will be handled by the AI layer.
		 */
		return $this->basic_extract(
			$field_data,
			$content
		);
	}

	/**
	 * Extract multiple fields.
	 *
	 * @param array  $fields  ACF fields.
	 * @param string $content Source content.
	 * @param array  $context Context.
	 *
	 * @return array
	 */
	public function extract_fields(
		$fields,
		$content,
		$context = array()
	) {

		if ( ! is_array( $fields ) ) {

			return array(
				'success' => false,
				'error'   => 'Invalid fields.',
				'fields'  => array(),
			);
		}

		$results = array();

		foreach ( $fields as $field ) {

			if ( ! is_array( $field ) ) {
				continue;
			}

			$field_name = isset(
				$field['name']
			)
				? sanitize_key(
					$field['name']
				)
				: '';

			if ( empty( $field_name ) ) {
				continue;
			}

			$results[ $field_name ] =
				$this->extract_field(
					$field,
					$content,
					$context
				);
		}

		return array(
			'success' => ! empty( $results ),
			'total'   => count( $results ),
			'fields'  => $results,
		);
	}

	/**
	 * Extract information from multiple sources.
	 *
	 * @param array $fields  ACF fields.
	 * @param array $sources Sources.
	 * @param array $context Context.
	 *
	 * @return array
	 */
	public function extract_from_sources(
		$fields,
		$sources,
		$context = array()
	) {

		if (
			! is_array( $fields ) ||
			! is_array( $sources )
		) {

			return array(
				'success' => false,
				'fields'  => array(),
				'sources' => array(),
			);
		}

		$field_results  = array();
		$source_results = array();

		foreach ( $sources as $source ) {

			if ( ! is_array( $source ) ) {
				continue;
			}

			$content = '';

			if (
				isset(
					$source['content']
				)
			) {
				$content =
					$source['content'];
			}

			/**
			 * Some search providers return only
			 * title/snippet/url.
			 *
			 * Full page fetching will be handled
			 * by the page fetcher later.
			 */
			if ( empty( $content ) ) {

				$content = trim(
					(
						isset(
							$source['title']
						)
							? $source['title']
							: ''
					)
					. "\n"
					. (
						isset(
							$source['snippet']
						)
							? $source['snippet']
							: ''
					)
				);
			}

			if ( empty( $content ) ) {
				continue;
			}

			$source_id = isset(
				$source['id']
			)
				? sanitize_key(
					$source['id']
				)
				: '';

			$source_results[] = array(
				'id' =>
					$source_id,

				'url' =>
					isset(
						$source['url']
					)
						? esc_url_raw(
							$source['url']
						)
						: '',

				'domain' =>
					isset(
						$source['domain']
					)
						? sanitize_text_field(
							$source['domain']
						)
						: '',
			);

			$extracted =
				$this->extract_fields(
					$fields,
					$content,
					array_merge(
						$context,
						array(
							'source' =>
								$source,
						)
					)
				);

			if (
				empty(
					$extracted['fields']
				)
			) {
				continue;
			}

			foreach (
				$extracted['fields']
				as $field_name => $result
			) {

				if (
					! isset(
						$field_results[
							$field_name
						]
					)
				) {

					$field_results[
						$field_name
					] = array();
				}

				$field_results[
					$field_name
				][] = array(
					'source_id' =>
						$source_id,

					'value' =>
						isset(
							$result['value']
						)
							? $result['value']
							: null,

					'confidence' =>
						isset(
							$result[
								'confidence'
							]
						)
							? (float) $result['confidence']
							: 0,

					'evidence' =>
						isset(
							$result[
								'evidence'
							]
						)
							? $result[
								'evidence'
							]
							: '',
				);
			}
		}

		return array(
			'success' =>
				! empty(
					$field_results
				),

			'total_sources' =>
				count(
					$source_results
				),

			'total_fields' =>
				count(
					$field_results
				),

			'sources' =>
				$source_results,

			'fields' =>
				$field_results,
		);
	}

	/**
	 * Normalize field information.
	 *
	 * @param array $field Field.
	 *
	 * @return array
	 */
	private function normalize_field(
		$field
	) {

		return array(
			'key' =>
				isset(
					$field['key']
				)
					? sanitize_text_field(
						$field['key']
					)
					: '',

			'name' =>
				isset(
					$field['name']
				)
					? sanitize_key(
						$field['name']
					)
					: '',

			'label' =>
				isset(
					$field['label']
				)
					? sanitize_text_field(
						$field['label']
					)
					: '',

			'type' =>
				isset(
					$field['type']
				)
					? sanitize_key(
						$field['type']
					)
					: '',

			'instructions' =>
				isset(
					$field['instructions']
				)
					? sanitize_textarea_field(
						$field['instructions']
					)
					: '',

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

			'min' =>
				isset(
					$field['min']
				)
					? $field['min']
					: null,

			'max' =>
				isset(
					$field['max']
				)
					? $field['max']
					: null,
		);
	}

	/**
	 * Basic conservative extraction.
	 *
	 * This is NOT intended to replace the AI.
	 *
	 * @param array  $field   Field.
	 * @param string $content Content.
	 *
	 * @return array
	 */
	private function basic_extract(
		$field,
		$content
	) {

		$name  = $field['name'];
		$label = $field['label'];

		/**
		 * Look for:
		 *
		 * Label: Value
		 * Label - Value
		 * Label = Value
		 */
		$patterns = array();

		if ( ! empty( $label ) ) {

			$patterns[] =
				preg_quote(
					$label,
					'/'
				);
		}

		if ( ! empty( $name ) ) {

			$patterns[] =
				preg_quote(
					str_replace(
						'_',
						' ',
						$name
					),
					'/'
				);
		}

		if ( empty( $patterns ) ) {

			return array(
				'success'    => false,
				'value'      => null,
				'confidence' => 0,
				'evidence'   => '',
				'method'     => 'none',
			);
		}

		$label_pattern =
			implode(
				'|',
				$patterns
			);

		$regex =
			'/(' .
			$label_pattern .
			')\s*[:=\-]\s*' .
			'([^\r\n<]{1,300})/iu';

		if (
			preg_match(
				$regex,
				$content,
				$matches
			)
		) {

			$value =
				isset(
					$matches[2]
				)
					? trim(
						$matches[2]
					)
					: '';

			$value =
				$this->sanitize_extracted_value(
					$value,
					$field
				);

			if ( '' !== $value ) {

				return array(
					'success'    => true,
					'value'      => $value,
					'confidence' => 0.55,
					'evidence'   =>
						$matches[0],
					'method'     =>
						'basic_pattern',
				);
			}
		}

		return array(
			'success'    => false,
			'value'      => null,
			'confidence' => 0,
			'evidence'   => '',
			'method'     => 'basic_pattern',
		);
	}

	/**
	 * Sanitize extracted value according to field type.
	 *
	 * @param mixed $value Value.
	 * @param array $field Field.
	 *
	 * @return mixed
	 */
	private function sanitize_extracted_value(
		$value,
		$field
	) {

		$type =
			isset(
				$field['type']
			)
				? $field['type']
				: 'text';

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

			case 'textarea':
			case 'wysiwyg':

				return sanitize_textarea_field(
					$value
				);

			case 'url':

				return esc_url_raw(
					$value
				);

			case 'email':

				return sanitize_email(
					$value
				);

			case 'true_false':

				$value = strtolower(
					trim(
						$value
					)
				);

				if (
					in_array(
						$value,
						array(
							'1',
							'true',
							'yes',
							'on',
							'بله',
						),
						true
					)
				) {
					return 1;
				}

				if (
					in_array(
						$value,
						array(
							'0',
							'false',
							'no',
							'off',
							'خیر',
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
	 * Clean source content before extraction.
	 *
	 * @param string $content Content.
	 *
	 * @return string
	 */
	private function clean_content(
		$content
	) {

		if ( ! is_string( $content ) ) {
			return '';
		}

		$content = wp_strip_all_tags(
			$content
		);

		$content = html_entity_decode(
			$content,
			ENT_QUOTES | ENT_HTML5,
			'UTF-8'
		);

		$content = preg_replace(
			'/[ \t]+/',
			' ',
			$content
		);

		$content = preg_replace(
			'/\n{3,}/',
			"\n\n",
			$content
		);

		return trim(
			$content
		);
	}

	/**
	 * Normalize AI extraction response.
	 *
	 * @param array $response Response.
	 * @param array $field    Field.
	 *
	 * @return array
	 */
	private function normalize_extraction_response(
		$response,
		$field
	) {

		$value =
			isset(
				$response['value']
			)
				? $response['value']
				: null;

		$confidence =
			isset(
				$response['confidence']
			)
				? (float) $response['confidence']
				: 0;

		$confidence = max(
			0,
			min(
				1,
				$confidence
			)
		);

		return array(
			'success' =>
				! empty(
					$response['success']
				),

			'value' => $value,

			'confidence' =>
				$confidence,

			'evidence' =>
				isset(
					$response['evidence']
				)
					? sanitize_textarea_field(
						$response['evidence']
					)
					: '',

			'method' =>
				isset(
					$response['method']
				)
					? sanitize_key(
						$response['method']
					)
					: 'ai',

			'field' =>
				$field['name'],
		);
	}

}