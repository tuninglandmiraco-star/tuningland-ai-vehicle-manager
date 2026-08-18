<?php
/**
 * Search Query Builder
 *
 * Builds dynamic research queries from vehicle data
 * and the currently detected ACF fields.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TL_AI_VM_Search_Query_Builder {

	private static $instance = null;

	/**
	 * Singleton.
	 *
	 * @return TL_AI_VM_Search_Query_Builder
	 */
	public static function instance() {

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
	}

	/**
	 * Build research queries for a vehicle.
	 *
	 * @param int   $post_id Vehicle ID.
	 * @param array $fields  ACF fields.
	 * @param array $options Options.
	 *
	 * @return array
	 */
	public function build(
		$post_id,
		$fields = array(),
		$options = array()
	) {

		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return array();
		}

		$post = get_post( $post_id );

		if ( ! $post ) {
			return array();
		}

		$vehicle_name = $this->get_vehicle_name( $post );

		if ( empty( $vehicle_name ) ) {
			return array();
		}

		$defaults = array(
			'language' =>
				'',
			'country' =>
				'',
			'max_queries' =>
				20,
		);

		$options =
			wp_parse_args(
				$options,
				$defaults
			);

		$queries = array();

		/* Focused-field queries must be first so the research engine does not
		 * spend its bounded query budget on unrelated vehicle searches. */
		$target_field = ! empty( $options['field'] ) ? sanitize_key( $options['field'] ) : '';
		if ( '' !== $target_field && is_array( $fields ) ) {
			foreach ( $fields as $target ) {
				if ( ! is_array( $target ) ) { continue; }
				$name = sanitize_key( isset( $target['name'] ) ? $target['name'] : '' );
				$key  = sanitize_key( isset( $target['key'] ) ? $target['key'] : '' );
				if ( $target_field !== $name && $target_field !== $key ) { continue; }

				$label = $this->get_field_search_term(
					isset( $target['label'] ) ? $target['label'] : '',
					isset( $target['name'] ) ? $target['name'] : '',
					isset( $target['instructions'] ) ? $target['instructions'] : ''
				);

				foreach ( $this->field_aliases( $label, $target ) as $alias ) {
					$q = $this->make_query( $vehicle_name, $alias );
					if ( $q ) { $queries[] = $q; }
				}

				$hay = strtolower( $label . ' ' . ( isset( $target['name'] ) ? $target['name'] : '' ) );
				if ( false !== strpos( $hay, 'viscos' ) || false !== strpos( $hay, 'روغن' ) ) {
					foreach ( array( 'engine oil SAE viscosity', 'recommended engine oil viscosity', 'oil grade SAE' ) as $term ) {
						$q = $this->make_query( $vehicle_name, $term );
						if ( $q ) { $queries[] = $q; }
					}
				} elseif ( false !== strpos( $hay, 'bulb' ) || false !== strpos( $hay, 'lamp' ) || false !== strpos( $hay, 'headlight' ) || false !== strpos( $hay, 'لامپ' ) || false !== strpos( $hay, 'چراغ' ) ) {
					foreach ( array( 'headlight bulb size', 'high beam bulb type', 'low beam bulb type', 'lamp base bulb code' ) as $term ) {
						$q = $this->make_query( $vehicle_name, $term );
						if ( $q ) { $queries[] = $q; }
					}
				}
				break;
			}
		}

		/**
		 * General vehicle query.
		 */
		$queries[] =
			$this->make_query(
				$vehicle_name,
				'official specifications'
			);

		/**
		 * Build queries from ACF fields.
		 */
		if ( is_array( $fields ) ) {

			foreach ( $fields as $field ) {

				if (
					! is_array( $field )
				) {
					continue;
				}

				$label =
					isset(
						$field['label']
					)
						? $field['label']
						: '';

				$name =
					isset(
						$field['name']
					)
						? $field['name']
						: '';

				$instructions =
					isset(
						$field['instructions']
					)
						? $field['instructions']
						: '';

				$search_term =
					$this->get_field_search_term(
						$label,
						$name,
						$instructions
					);

				if (
					empty(
						$search_term
					)
				) {
					continue;
				}

				$queries[] =
					$this->make_query(
						$vehicle_name,
						$search_term
					);
			}
		}

		// Add field-aware aliases and ACF choice vocabulary.
		if ( ! empty( $options['field'] ) ) {
			$target = sanitize_key( $options['field'] );
		foreach ( $fields as $f ) {
				if ( ! is_array( $f ) ) { continue; }
				if ( $target === sanitize_key( $f['name'] ?? '' ) || $target === sanitize_key( $f['key'] ?? '' ) ) {
					$label = $this->get_field_search_term( $f['label'] ?? '', $f['name'] ?? '', $f['instructions'] ?? '' );
					$aliases = $this->field_aliases( $label, $f );
					foreach ( $aliases as $alias ) { $queries[] = $this->make_query( $vehicle_name, $alias ); }
					if ( ! empty( $f['choices'] ) && is_array( $f['choices'] ) ) {
						$choices = array_slice( array_values( array_filter( array_map( 'strval', $f['choices'] ) ) ), 0, 8 );
						if ( $choices ) { $queries[] = $this->make_query( $vehicle_name, $label . ' ' . implode( ' ', $choices ) ); }
					}
				}
			}
		}

		/**
		 * Remove duplicates.
		 */
		$queries =
			array_values(
				array_unique(
					$queries
				)
			);

		/**
		 * Limit number of queries.
		 */
		$max_queries =
			absint(
				$options['max_queries']
			);

		if ( $max_queries < 1 ) {
			$max_queries = 20;
		}

		if (
			count( $queries ) >
			$max_queries
		) {

			$queries =
				array_slice(
					$queries,
					0,
					$max_queries
				);
		}

		/**
		 * Add language/country context.
		 */
		$queries =
			$this->add_context(
				$queries,
				$options
			);

		return $queries;
	}

	/**
	 * Build focused queries for missing fields.
	 *
	 * @param int   $post_id Vehicle ID.
	 * @param array $fields  ACF fields.
	 * @param array $options Options.
	 *
	 * @return array
	 */
	public function build_missing_field_queries(
		$post_id,
		$fields = array(),
		$options = array()
	) {

		$post_id = absint(
			$post_id
		);

		if ( ! $post_id ) {
			return array();
		}

		$post =
			get_post(
				$post_id
			);

		if ( ! $post ) {
			return array();
		}

		$vehicle_name = $this->get_vehicle_name( $post );

		if ( empty( $vehicle_name ) ) {
			return array();
		}

		$queries = array();

		if ( ! is_array( $fields ) ) {
			return $queries;
		}

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
				sanitize_key(
					$field['name']
				);

			$current_value =
				get_field(
					$field_name,
					$post_id
				);

			/**
			 * Only search for fields which are empty.
			 */
			if (
				! $this->is_empty(
					$current_value
				)
			) {
				continue;
			}

			$label =
				isset(
					$field['label']
				)
					? $field['label']
					: $field_name;

			$instructions =
				isset(
					$field['instructions']
				)
					? $field['instructions']
					: '';

			$term =
				$this->get_field_search_term(
					$label,
					$field_name,
					$instructions
				);

			if ( empty( $term ) ) {
				continue;
			}

			$queries[] =
				$this->make_query(
					$vehicle_name,
					$term
				);
		}

		$queries =
			array_values(
				array_unique(
					$queries
				)
			);

		$max_queries =
			isset(
				$options['max_queries']
			)
				? absint(
					$options['max_queries']
				)
				: 20;

		return array_slice(
			$queries,
			0,
			max(
				1,
				$max_queries
			)
		);
	}

	/**
	 * Get vehicle display name.
	 *
	 * @param WP_Post $post Vehicle post.
	 *
	 * @return string
	 */
	private function get_vehicle_name( $post ) {

		$name = sanitize_text_field( get_the_title( $post ) );
		$parts = array( $name );
		if ( function_exists( 'get_field' ) ) {
			$post_type = get_post_type( $post );
			if ( class_exists( 'TL_AI_VM_Field_Schema' ) ) {
				$fields = TL_AI_VM_Field_Schema::instance()->get_fields( $post_type );
				foreach ( $fields as $field ) {
					$label = strtolower( (string) ( $field['label'] ?? '' ) . ' ' . (string) ( $field['name'] ?? '' ) );
					if ( preg_match( '/\b(brand|make|manufacturer|model|year|generation|engine|engine[_ ]code|motor|موتور|سال|برند|مدل)\b/iu', $label ) ) {
						$v = get_field( ! empty( $field['key'] ) ? $field['key'] : $field['name'], $post->ID, false );
						if ( is_scalar( $v ) && trim( (string) $v ) !== '' ) { $parts[] = sanitize_text_field( (string) $v ); }
					}
				}
			}
		}
		return trim( preg_replace( '/\s+/u', ' ', implode( ' ', array_unique( array_filter( $parts ) ) ) ) );
	}

	/**
	 * Convert an ACF field into a useful search term.
	 *
	 * @param string $label        Field label.
	 * @param string $name         Field name.
	 * @param string $instructions Instructions.
	 *
	 * @return string
	 */
	private function get_field_search_term(
		$label,
		$name,
		$instructions
	) {

		$label =
			$this->clean_term(
				$label
			);

		$name =
			$this->clean_term(
				$name
			);

		$instructions =
			$this->clean_term(
				$instructions
			);

		/**
		 * Label is normally the best search term.
		 */
		if ( ! empty( $label ) ) {
			return $label;
		}

		if ( ! empty( $instructions ) ) {
			return $instructions;
		}

		return $name;
	}

	/**
	 * Build a query.
	 *
	 * @param string $vehicle Vehicle name.
	 * @param string $term    Search term.
	 *
	 * @return string
	 */
	private function make_query(
		$vehicle,
		$term
	) {

		$vehicle =
			$this->clean_term(
				$vehicle
			);

		$term =
			$this->clean_term(
				$term
			);

		if (
			empty( $vehicle ) ||
			empty( $term )
		) {
			return '';
		}

		return trim(
			$vehicle .
			' ' .
			$term
		);
	}

	private function field_aliases( $term, $field ) {
		$hay = strtolower( (string) $term . ' ' . (string) ( $field['name'] ?? '' ) );
		$aliases = array( (string) $term );
		/* Semantic guardrails: search for the exact data class instead of a broad word. */
		if ( preg_match( '/(api|سطح\s*کیفی|استاندارد.*api)/iu', $hay ) ) {
			$aliases[] = 'API engine oil service category';
			$aliases[] = 'ACEA engine oil specification';
		}
		if ( preg_match( '/(حجم|capacity|volume|ظرفیت)/iu', $hay ) && preg_match( '/(روغن|oil|fluid)/iu', $hay ) ) {
			$aliases[] = preg_match( '/(گیربکس|gearbox|transmission)/iu', $hay ) ? 'transmission fluid capacity liters' : 'engine oil capacity liters';
		}
		if ( preg_match( '/(گیربکس|gearbox|transmission)/iu', $hay ) && ! preg_match( '/(حجم|capacity|volume|ظرفیت)/iu', $hay ) ) {
			$aliases[] = 'transmission fluid type specification';
			$aliases[] = 'gearbox oil specification';
		}
		$map = array(
			'bulb' => array( 'headlight bulb', 'high beam bulb', 'low beam bulb', 'lamp base', 'bulb type' ),
			'headlight' => array( 'headlight bulb', 'bulb type', 'lamp base' ),
			'روغن' => array( 'engine oil', 'oil viscosity', 'SAE viscosity', 'API specification' ),
			'oil' => array( 'engine oil', 'oil viscosity', 'SAE viscosity', 'API specification' ),
			'viscosity' => array( 'engine oil SAE viscosity', 'recommended oil viscosity', 'SAE grade' ),
			'api' => array( 'API engine oil standard', 'API service category', 'ACEA engine oil specification' ),
			'capacity' => array( 'engine oil capacity litres', 'oil fill capacity L', 'oil capacity liters' ),
			'volume' => array( 'engine oil capacity litres', 'fluid capacity liters' ),
			'حجم' => array( 'engine oil capacity liters', 'fluid capacity L' ),
			'ظرفیت' => array( 'engine oil capacity liters', 'fluid capacity L' ),
			'gearbox' => array( 'transmission fluid type specification', 'gearbox oil specification', 'transmission fluid capacity liters' ),
			'transmission' => array( 'transmission fluid type specification', 'gearbox oil specification', 'transmission fluid capacity liters' ),
			'گیربکس' => array( 'transmission fluid type specification', 'gearbox oil specification', 'transmission fluid capacity liters' ),
			'filter' => array( 'oil filter', 'air filter', 'cabin filter' ),
		);
		foreach ( $map as $key => $vals ) { if ( false !== strpos( $hay, $key ) ) { $aliases = array_merge( $aliases, $vals ); } }
		return array_values( array_unique( array_filter( $aliases ) ) );
	}

	/**
	 * Add optional search context.
	 *
	 * @param array $queries Queries.
	 * @param array $options Options.
	 *
	 * @return array
	 */
	private function add_context(
		$queries,
		$options
	) {

		$language =
			isset(
				$options['language']
			)
				? sanitize_text_field(
					$options['language']
				)
				: '';

		$country =
			isset(
				$options['country']
			)
				? sanitize_text_field(
					$options['country']
				)
				: '';

		if (
			empty( $language ) &&
			empty( $country )
		) {
			return $queries;
		}

		foreach (
			$queries as $index => $query
		) {

			if ( ! empty( $language ) ) {

				$query .=
					' ' .
					$language;
			}

			if ( ! empty( $country ) ) {

				$query .=
					' ' .
					$country;
			}

			$queries[
				$index
			] = trim(
				$query
			);
		}

		return $queries;
	}

	/**
	 * Clean a search term.
	 *
	 * @param string $term Term.
	 *
	 * @return string
	 */
	private function clean_term(
		$term
	) {

		$term =
			wp_strip_all_tags(
				(string) $term
			);

		$term =
			html_entity_decode(
				$term,
				ENT_QUOTES,
				'UTF-8'
			);

		$term =
			preg_replace(
				'/\s+/',
				' ',
				$term
			);

		return trim(
			$term
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