<?php
/**
 * Research Storage
 *
 * Stores AI research data for vehicles before
 * the final values are written into ACF.
 *
 * Important:
 *
 * This class is only a persistence layer.
 *
 * It MUST NOT:
 *
 * - Write directly to ACF.
 * - Perform web research.
 * - Call the AI API.
 * - Decide whether a value is correct.
 * - Validate vehicle-specific semantics.
 *
 * Research data is stored as an intermediate package
 * between research/validation and the final ACF writer.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


final class TL_AI_VM_Research_Storage {

	/**
	 * Singleton instance.
	 *
	 * @var TL_AI_VM_Research_Storage|null
	 */
	private static $instance = null;


	/**
	 * Meta key used for research data.
	 *
	 * @var string
	 */
	private $meta_key = '_tl_ai_vm_research_data';


	/**
	 * Current storage schema version.
	 *
	 * @var int
	 */
	private $schema_version = 1;


	/**
	 * Get singleton instance.
	 *
	 * @return TL_AI_VM_Research_Storage
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
	 * Get research data for a vehicle.
	 *
	 * @param int $post_id Vehicle post ID.
	 *
	 * @return array
	 */
	public function get( $post_id ) {

		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return array();
		}


		$data = get_post_meta(
			$post_id,
			$this->meta_key,
			true
		);


		if ( ! is_array( $data ) ) {
			return array();
		}


		return $this->normalize_data(
			$data,
			false
		);
	}


	/**
	 * Check whether research data exists.
	 *
	 * @param int $post_id Vehicle post ID.
	 *
	 * @return bool
	 */
	public function exists( $post_id ) {

		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return false;
		}


		return metadata_exists(
			'post',
			$post_id,
			$this->meta_key
		);
	}


	/**
	 * Save complete research data.
	 *
	 * This method never writes to ACF.
	 *
	 * @param int   $post_id Vehicle post ID.
	 * @param array $data    Research data.
	 *
	 * @return bool
	 */
	public function save(
		$post_id,
		$data
	) {

		$post_id = absint( $post_id );


		if (
			! $post_id ||
			! is_array( $data )
		) {
			return false;
		}


		$data = $this->normalize_data(
			$data,
			true
		);


		/**
		 * Get the existing value before updating.
		 *
		 * WordPress update_post_meta() can return false
		 * when the value is unchanged. Therefore we cannot
		 * interpret false alone as a storage failure.
		 */
		$existing = get_post_meta(
			$post_id,
			$this->meta_key,
			true
		);


		$updated = update_post_meta(
			$post_id,
			$this->meta_key,
			$data
		);


		if ( false !== $updated ) {
			return true;
		}


		/**
		 * If the new value is identical to the old value,
		 * storage is already in the desired state.
		 */
		if (
			is_array( $existing ) &&
			$this->values_equal(
				$existing,
				$data
			)
		) {
			return true;
		}


		return false;
	}


	/**
	 * Get the complete research package.
	 *
	 * Alias for get(), useful for future modules.
	 *
	 * @param int $post_id Vehicle post ID.
	 *
	 * @return array
	 */
	public function get_package( $post_id ) {

		return $this->get(
			$post_id
		);
	}


	/**
	 * Add search results to research data.
	 *
	 * @param int   $post_id Vehicle post ID.
	 * @param array $results Search results.
	 *
	 * @return bool
	 */
	public function add_search_results(
		$post_id,
		$results
	) {

		$post_id = absint(
			$post_id
		);


		if (
			! $post_id ||
			! is_array( $results )
		) {
			return false;
		}


		$data = $this->get(
			$post_id
		);


		if (
			empty( $data['sources'] ) ||
			! is_array( $data['sources'] )
		) {
			$data['sources'] = array();
		}


		foreach ( $results as $result ) {

			if ( ! is_array( $result ) ) {
				continue;
			}


			if ( empty( $result['url'] ) ) {
				continue;
			}


			/**
			 * Normalize source URL.
			 */
			$result['url'] = esc_url_raw(
				$result['url']
			);


			if ( empty( $result['url'] ) ) {
				continue;
			}


			$data['sources'][] = $result;
		}


		$data['sources'] =
			$this->deduplicate_sources(
				$data['sources']
			);


		return $this->save(
			$post_id,
			$data
		);
	}


	/**
	 * Replace all research sources.
	 *
	 * Useful when a complete research cycle is rebuilt.
	 *
	 * @param int   $post_id Vehicle post ID.
	 * @param array $sources Sources.
	 *
	 * @return bool
	 */
	public function save_sources(
		$post_id,
		$sources
	) {

		$post_id = absint(
			$post_id
		);


		if (
			! $post_id ||
			! is_array( $sources )
		) {
			return false;
		}


		$data = $this->get(
			$post_id
		);


		$data['sources'] =
			$this->deduplicate_sources(
				$sources
			);


		return $this->save(
			$post_id,
			$data
		);
	}


	/**
	 * Get stored sources.
	 *
	 * @param int $post_id Vehicle post ID.
	 *
	 * @return array
	 */
	public function get_sources(
		$post_id
	) {

		$data = $this->get(
			$post_id
		);


		if (
			empty( $data['sources'] ) ||
			! is_array( $data['sources'] )
		) {
			return array();
		}


		return $data['sources'];
	}


	/**
	 * Save AI analysis.
	 *
	 * @param int   $post_id Vehicle post ID.
	 * @param array $analysis AI analysis.
	 *
	 * @return bool
	 */
	public function save_analysis(
		$post_id,
		$analysis
	) {

		$post_id = absint(
			$post_id
		);


		if (
			! $post_id ||
			! is_array( $analysis )
		) {
			return false;
		}


		$data = $this->get(
			$post_id
		);


		$data['analysis'] =
			$analysis;


		return $this->save(
			$post_id,
			$data
		);
	}


	/**
	 * Get AI analysis.
	 *
	 * @param int $post_id Vehicle post ID.
	 *
	 * @return array
	 */
	public function get_analysis(
		$post_id
	) {

		$data = $this->get(
			$post_id
		);


		if (
			empty( $data['analysis'] ) ||
			! is_array( $data['analysis'] )
		) {
			return array();
		}


		return $data['analysis'];
	}


	/**
	 * Store a proposed ACF value.
	 *
	 * IMPORTANT:
	 *
	 * This does NOT write to ACF.
	 *
	 * The value is stored as an intermediate
	 * research proposal.
	 *
	 * @param int    $post_id   Vehicle post ID.
	 * @param string $field_key ACF field name/key.
	 * @param mixed  $value     Proposed value.
	 * @param array  $evidence  Supporting evidence.
	 *
	 * @return bool
	 */
	public function save_proposed_field(
		$post_id,
		$field_key,
		$value,
		$evidence = array()
	) {

		$post_id =
			absint(
				$post_id
			);


		$field_key =
			sanitize_key(
				$field_key
			);


		if (
			! $post_id ||
			empty( $field_key )
		) {
			return false;
		}


		$data =
			$this->get(
				$post_id
			);


		if (
			empty(
				$data['proposed_fields']
			) ||
			! is_array(
				$data['proposed_fields']
			)
		) {

			$data['proposed_fields'] =
				array();
		}


		$existing =
			isset(
				$data['proposed_fields'][ $field_key ]
			) &&
			is_array(
				$data['proposed_fields'][ $field_key ]
			)
				? $data['proposed_fields'][ $field_key ]
				: array();


		$data['proposed_fields'][ $field_key ] =
			array(
				'value' =>
					$value,

				'evidence' =>
					is_array(
						$evidence
					)
						? $evidence
						: array(),

				'created_at' =>
					isset(
						$existing['created_at']
					)
						? absint(
							$existing['created_at']
						)
						: current_time(
							'timestamp'
						),

				'updated_at' =>
					current_time(
						'timestamp'
					),
			);


		return $this->save(
			$post_id,
			$data
		);
	}


	/**
	 * Get proposed fields.
	 *
	 * @param int $post_id Vehicle post ID.
	 *
	 * @return array
	 */
	public function get_proposed_fields(
		$post_id
	) {

		$data =
			$this->get(
				$post_id
			);


		if (
			empty(
				$data['proposed_fields']
			) ||
			! is_array(
				$data['proposed_fields']
			)
		) {
			return array();
		}


		return $data['proposed_fields'];
	}


	/**
	 * Get one proposed field.
	 *
	 * @param int    $post_id   Vehicle post ID.
	 * @param string $field_key Field name/key.
	 *
	 * @return array|null
	 */
	public function get_proposed_field(
		$post_id,
		$field_key
	) {

		$field_key =
			sanitize_key(
				$field_key
			);


		if ( empty( $field_key ) ) {
			return null;
		}


		$fields =
			$this->get_proposed_fields(
				$post_id
			);


		if (
			! isset(
				$fields[ $field_key ]
			)
		) {
			return null;
		}


		return is_array(
			$fields[ $field_key ]
		)
			? $fields[ $field_key ]
			: null;
	}


	/**
	 * Delete one proposed field.
	 *
	 * This does not affect ACF.
	 *
	 * @param int    $post_id   Vehicle post ID.
	 * @param string $field_key Field name/key.
	 *
	 * @return bool
	 */
	public function delete_proposed_field(
		$post_id,
		$field_key
	) {

		$post_id =
			absint(
				$post_id
			);

		$field_key =
			sanitize_key(
				$field_key
			);


		if (
			! $post_id ||
			empty( $field_key )
		) {
			return false;
		}


		$data =
			$this->get(
				$post_id
			);


		if (
			empty(
				$data['proposed_fields']
			) ||
			! is_array(
				$data['proposed_fields']
			)
		) {
			return false;
		}


		if (
			! array_key_exists(
				$field_key,
				$data['proposed_fields']
			)
		) {
			return false;
		}


		unset(
			$data['proposed_fields'][ $field_key ]
		);


		return $this->save(
			$post_id,
			$data
		);
	}


	/**
	 * Clear all proposed fields.
	 *
	 * @param int $post_id Vehicle post ID.
	 *
	 * @return bool
	 */
	public function clear_proposed_fields(
		$post_id
	) {

		$post_id =
			absint(
				$post_id
			);


		if ( ! $post_id ) {
			return false;
		}


		$data =
			$this->get(
				$post_id
			);


		$data['proposed_fields'] =
			array();


		return $this->save(
			$post_id,
			$data
		);
	}


	/**
	 * Save generic research metadata.
	 *
	 * Useful for pipeline state such as:
	 *
	 * - research status
	 * - confidence
	 * - validation status
	 * - review status
	 * - research version
	 *
	 * @param int   $post_id Vehicle post ID.
	 * @param array $meta    Metadata.
	 *
	 * @return bool
	 */
	public function update_meta(
		$post_id,
		$meta
	) {

		$post_id =
			absint(
				$post_id
			);


		if (
			! $post_id ||
			! is_array( $meta )
		) {
			return false;
		}


		$data =
			$this->get(
				$post_id
			);


		if (
			empty(
				$data['meta']
			) ||
			! is_array(
				$data['meta']
			)
		) {
			$data['meta'] =
				array();
		}


		$data['meta'] =
			array_merge(
				$data['meta'],
				$meta
			);


		return $this->save(
			$post_id,
			$data
		);
	}


	/**
	 * Get generic research metadata.
	 *
	 * @param int $post_id Vehicle post ID.
	 *
	 * @return array
	 */
	public function get_meta(
		$post_id
	) {

		$data =
			$this->get(
				$post_id
			);


		if (
			empty(
				$data['meta']
			) ||
			! is_array(
				$data['meta']
			)
		) {
			return array();
		}


		return $data['meta'];
	}


	/**
	 * Delete research data.
	 *
	 * This removes only the intermediate research package.
	 * It does NOT delete ACF values.
	 *
	 * @param int $post_id Vehicle post ID.
	 *
	 * @return bool
	 */
	public function delete(
		$post_id
	) {

		$post_id =
			absint(
				$post_id
			);


		if ( ! $post_id ) {
			return false;
		}


		return delete_post_meta(
			$post_id,
			$this->meta_key
		);
	}


	/**
	 * Clear only sources.
	 *
	 * @param int $post_id Vehicle post ID.
	 *
	 * @return bool
	 */
	public function clear_sources(
		$post_id
	) {

		$post_id =
			absint(
				$post_id
			);


		if ( ! $post_id ) {
			return false;
		}


		$data =
			$this->get(
				$post_id
			);


		$data['sources'] =
			array();


		return $this->save(
			$post_id,
			$data
		);
	}


	/**
	 * Clear only analysis.
	 *
	 * @param int $post_id Vehicle post ID.
	 *
	 * @return bool
	 */
	public function clear_analysis(
		$post_id
	) {

		$post_id =
			absint(
				$post_id
			);


		if ( ! $post_id ) {
			return false;
		}


		$data =
			$this->get(
				$post_id
			);


		$data['analysis'] =
			array();


		return $this->save(
			$post_id,
			$data
		);
	}


	/**
	 * Get storage schema version.
	 *
	 * @return int
	 */
	public function get_schema_version() {

		return absint(
			$this->schema_version
		);
	}


	/**
	 * Normalize stored research data.
	 *
	 * @param array $data    Research data.
	 * @param bool  $touch   Whether to update updated_at.
	 *
	 * @return array
	 */
	private function normalize_data(
		$data,
		$touch = true
	) {

		if ( ! is_array( $data ) ) {
			$data = array();
		}


		/**
		 * Storage schema.
		 */
		$data['schema_version'] =
			isset(
				$data['schema_version']
			)
				? max(
					1,
					absint(
						$data['schema_version']
					)
				)
				: $this->schema_version;


		/**
		 * Sources.
		 */
		if (
			empty(
				$data['sources']
			) ||
			! is_array(
				$data['sources']
			)
		) {
			$data['sources'] =
				array();
		}


		$data['sources'] =
			$this->deduplicate_sources(
				$data['sources']
			);


		/**
		 * Proposed fields.
		 */
		if (
			empty(
				$data['proposed_fields']
			) ||
			! is_array(
				$data['proposed_fields']
			)
		) {
			$data['proposed_fields'] =
				array();
		}


		/**
		 * Analysis.
		 */
		if (
			isset(
				$data['analysis']
			) &&
			! is_array(
				$data['analysis']
			)
		) {
			$data['analysis'] =
				array();
		}


		/**
		 * Generic metadata.
		 */
		if (
			isset(
				$data['meta']
			) &&
			! is_array(
				$data['meta']
			)
		) {
			$data['meta'] =
				array();
		}


		/**
		 * Created timestamp.
		 */
		if (
			empty(
				$data['created_at']
			)
		) {

			$data['created_at'] =
				current_time(
					'timestamp'
				);
		} else {

			$data['created_at'] =
				absint(
					$data['created_at']
				);
		}


		/**
		 * Updated timestamp.
		 */
		if ( $touch ) {

			$data['updated_at'] =
				current_time(
					'timestamp'
				);

		} elseif (
			isset(
				$data['updated_at']
			)
		) {

			$data['updated_at'] =
				absint(
					$data['updated_at']
				);
		}


		return $data;
	}


	/**
	 * Remove duplicate sources.
	 *
	 * @param array $sources Sources.
	 *
	 * @return array
	 */
	private function deduplicate_sources(
		$sources
	) {

		if ( ! is_array( $sources ) ) {
			return array();
		}


		$unique = array();
		$seen   = array();


		foreach ( $sources as $source ) {

			if (
				! is_array( $source ) ||
				empty(
					$source['url']
				)
			) {
				continue;
			}


			$url =
				$this->normalize_url(
					$source['url']
				);


			if ( empty( $url ) ) {
				continue;
			}


			if (
				isset(
					$seen[ $url ]
				)
			) {
				continue;
			}


			$seen[ $url ] = true;


			/**
			 * Always keep the normalized URL.
			 */
			$source['url'] =
				esc_url_raw(
					$source['url']
				);


			$unique[] =
				$source;
		}


		return array_values(
			$unique
		);
	}


	/**
	 * Normalize URL for comparison.
	 *
	 * This intentionally removes fragments because
	 * #section does not represent a different source.
	 *
	 * @param string $url URL.
	 *
	 * @return string
	 */
	private function normalize_url(
		$url
	) {

		if ( ! is_string( $url ) ) {
			return '';
		}


		$url =
			esc_url_raw(
				trim(
					$url
				)
			);


		if ( empty( $url ) ) {
			return '';
		}


		/**
		 * Remove URL fragment.
		 */
		$url =
			preg_replace(
				'/#.*$/',
				'',
				$url
			);


		/**
		 * Normalize host casing.
		 */
		$parsed =
			wp_parse_url(
				$url
			);


		if (
			is_array( $parsed ) &&
			! empty(
				$parsed['host']
			)
		) {

			$host =
				strtolower(
					$parsed['host']
				);


			$scheme =
				isset(
					$parsed['scheme']
				)
					? strtolower(
						$parsed['scheme']
					)
					: 'https';


			$path =
				isset(
					$parsed['path']
				)
					? $parsed['path']
					: '';


			$query =
				isset(
					$parsed['query']
				)
					? '?' . $parsed['query']
					: '';


			$port =
				isset(
					$parsed['port']
				)
					? ':' . absint(
						$parsed['port']
					)
					: '';


			$url =
				$scheme .
				'://' .
				$host .
				$port .
				$path .
				$query;
		}


		return strtolower(
			rtrim(
				$url,
				'/'
			)
		);
	}


	/**
	 * Compare two values safely.
	 *
	 * @param mixed $a First value.
	 * @param mixed $b Second value.
	 *
	 * @return bool
	 */
	private function values_equal(
		$a,
		$b
	) {

		return serialize(
			$a
		) === serialize(
			$b
		);
	}

}
