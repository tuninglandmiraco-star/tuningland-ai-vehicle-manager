<?php
/**
 * Source Manager
 *
 * Manages source classification, priorities and reliability
 * for AI vehicle research.
 *
 * Responsibilities:
 *
 * - Maintain source category configuration.
 * - Classify sources by explicit type or domain.
 * - Normalize and compare domains safely.
 * - Calculate source reliability.
 * - Enrich source records.
 * - Rank sources.
 * - Select top sources.
 *
 * This class does NOT perform web requests.
 * It does NOT perform AI analysis.
 * It only manages source metadata and ranking.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


final class TL_AI_VM_Source_Manager {

	/**
	 * Singleton instance.
	 *
	 * @var TL_AI_VM_Source_Manager|null
	 */
	private static $instance = null;


	/**
	 * Option used for source configuration.
	 *
	 * @var string
	 */
	private $option_name = 'tl_ai_vm_source_settings';


	/**
	 * Cached settings.
	 *
	 * @var array|null
	 */
	private $settings_cache = null;


	/**
	 * Default source categories.
	 *
	 * @var array
	 */
	private $default_settings = array(
		'official' => array(
			'priority' => 100,
			'domains'  => array(),
		),

		'manufacturer' => array(
			'priority' => 95,
			'domains'  => array(),
		),

		'technical' => array(
			'priority' => 85,
			'domains'  => array(),
		),

		'automotive' => array(
			'priority' => 75,
			'domains'  => array(),
		),

		'general' => array(
			'priority' => 50,
			'domains'  => array(),
		),
	);


	/**
	 * Singleton.
	 *
	 * @return TL_AI_VM_Source_Manager
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
	 * Get default settings.
	 *
	 * @return array
	 */
	public function get_default_settings() {

		return $this->default_settings;
	}


	/**
	 * Get source settings.
	 *
	 * Performs a deep merge so a partial saved configuration
	 * cannot accidentally remove default categories/options.
	 *
	 * @return array
	 */
	public function get_settings() {

		if ( null !== $this->settings_cache ) {
			return $this->settings_cache;
		}


		$saved = get_option(
			$this->option_name,
			array()
		);


		if ( ! is_array( $saved ) ) {
			$saved = array();
		}


		$settings = $this->default_settings;


		foreach ( $saved as $type => $data ) {

			$type = sanitize_key(
				$type
			);


			if (
				empty( $type ) ||
				! is_array( $data )
			) {
				continue;
			}


			if ( ! isset( $settings[ $type ] ) ) {

				/**
				 * Allow future/custom source categories.
				 */
				$settings[ $type ] = array(
					'priority' => 50,
					'domains'  => array(),
				);
			}


			if ( isset( $data['priority'] ) ) {

				$settings[ $type ]['priority'] =
					$this->normalize_priority(
						$data['priority']
					);
			}


			if (
				isset( $data['domains'] ) &&
				is_array( $data['domains'] )
			) {

				$settings[ $type ]['domains'] =
					$this->normalize_domains(
						$data['domains']
					);
			}
		}


		/**
		 * Normalize defaults as well.
		 */
		foreach ( $settings as $type => $data ) {

			if ( ! is_array( $data ) ) {

				$settings[ $type ] = array(
					'priority' => 50,
					'domains'  => array(),
				);

				continue;
			}


			$settings[ $type ]['priority'] =
				isset( $data['priority'] )
					? $this->normalize_priority(
						$data['priority']
					)
					: 50;


			$settings[ $type ]['domains'] =
				isset( $data['domains'] ) &&
				is_array( $data['domains'] )
					? $this->normalize_domains(
						$data['domains']
					)
					: array();
		}


		$this->settings_cache = $settings;


		return $settings;
	}


	/**
	 * Save source settings.
	 *
	 * @param array $settings Settings.
	 *
	 * @return bool
	 */
	public function save_settings(
		$settings
	) {

		if ( ! is_array( $settings ) ) {
			return false;
		}


		/**
		 * Start from defaults so missing categories
		 * are never accidentally removed.
		 */
		$clean = $this->default_settings;


		foreach ( $settings as $type => $data ) {

			$type = sanitize_key(
				$type
			);


			if (
				empty( $type ) ||
				! is_array( $data )
			) {
				continue;
			}


			/**
			 * Support custom categories.
			 */
			if ( ! isset( $clean[ $type ] ) ) {

				$clean[ $type ] = array(
					'priority' => 50,
					'domains'  => array(),
				);
			}


			$priority =
				isset( $data['priority'] )
					? $data['priority']
					: $clean[ $type ]['priority'];


			$domains =
				isset( $data['domains'] ) &&
				is_array( $data['domains'] )
					? $data['domains']
					: $clean[ $type ]['domains'];


			$clean[ $type ] = array(
				'priority' =>
					$this->normalize_priority(
						$priority
					),

				'domains' =>
					$this->normalize_domains(
						$domains
					),
			);
		}


		$result = update_option(
			$this->option_name,
			$clean,
			false
		);


		/**
		 * update_option() returns false when the value
		 * is unchanged. The settings are still valid.
		 */
		$this->settings_cache = $clean;


		return $result || $this->settings_equal(
			$this->get_saved_settings_raw(),
			$clean
		);
	}


	/**
	 * Reset source settings to defaults.
	 *
	 * @return bool
	 */
	public function reset_settings() {

		$result = update_option(
			$this->option_name,
			$this->default_settings,
			false
		);


		$this->settings_cache =
			$this->default_settings;


		return $result || $this->settings_equal(
			$this->get_saved_settings_raw(),
			$this->default_settings
		);
	}


	/**
	 * Get raw saved settings.
	 *
	 * Internal helper.
	 *
	 * @return array
	 */
	private function get_saved_settings_raw() {

		$saved = get_option(
			$this->option_name,
			array()
		);


		return is_array( $saved )
			? $saved
			: array();
	}


	/**
	 * Compare settings.
	 *
	 * @param array $a First settings.
	 * @param array $b Second settings.
	 *
	 * @return bool
	 */
	private function settings_equal(
		$a,
		$b
	) {

		return wp_json_encode( $a ) ===
			wp_json_encode( $b );
	}


	/**
	 * Classify a source.
	 *
	 * Classification order:
	 *
	 * 1. Explicit valid category.
	 * 2. Configured domain.
	 * 3. General.
	 *
	 * @param array $source Source data.
	 *
	 * @return string
	 */

    /**
     * Get configured source domains in priority order.
     *
     * @param string $type Optional source category.
     * @return array
     */
    public function get_configured_domains( $type = '' ) {
        $settings = $this->get_settings();
        $domains = array();

        if ( '' !== $type ) {
            $type = sanitize_key( $type );
            if ( isset( $settings[ $type ]['domains'] ) ) {
                return $this->normalize_domains( $settings[ $type ]['domains'] );
            }
        }

        foreach ( $settings as $data ) {
            if ( ! is_array( $data ) || empty( $data['domains'] ) ) {
                continue;
            }
            foreach ( $data['domains'] as $domain ) {
                $domain = $this->normalize_domain( $domain );
                if ( $domain ) {
                    $domains[] = $domain;
                }
            }
        }

        return array_values( array_unique( $domains ) );
    }

	public function classify(
		$source
	) {

		if ( ! is_array( $source ) ) {
			return 'general';
		}


		/**
		 * Explicit category.
		 *
		 * "category" is preferred because the Source Manager
		 * itself enriches sources with this field.
		 */
		$explicit_category = '';


		if ( isset( $source['category'] ) ) {

			$explicit_category =
				sanitize_key(
					$source['category']
				);
		}


		if ( '' !== $explicit_category ) {

			$settings =
				$this->get_settings();


			if (
				isset(
					$settings[
						$explicit_category
					]
				)
			) {

				return $explicit_category;
			}
		}


		/**
		 * Backward-compatible explicit type.
		 */
		$explicit_type = '';


		if ( isset( $source['type'] ) ) {

			$explicit_type =
				sanitize_key(
					$source['type']
				);
		}


		if ( '' !== $explicit_type ) {

			$settings =
				$this->get_settings();


			if (
				isset(
					$settings[
						$explicit_type
					]
				)
			) {

				return $explicit_type;
			}
		}


		/**
		 * Resolve domain from source data.
		 */
		$domain =
			$this->get_source_domain(
				$source
			);


		if ( '' === $domain ) {
			return 'general';
		}


		$settings =
			$this->get_settings();


		/**
		 * Find the highest-priority matching category.
		 *
		 * This is important when a domain is accidentally
		 * configured under more than one category.
		 */
		$matched_category = 'general';
		$matched_priority = -1;


		foreach ( $settings as $category => $data ) {

			if (
				empty( $data['domains'] ) ||
				! is_array( $data['domains'] )
			) {
				continue;
			}


			foreach ( $data['domains'] as $configured_domain ) {

				if (
					$this->domain_matches(
						$domain,
						$configured_domain
					)
				) {

					$priority =
						isset(
							$data['priority']
						)
							? (float) $data['priority']
							: 0;


					if (
						$priority >
						$matched_priority
					) {

						$matched_category =
							$category;

						$matched_priority =
							$priority;
					}

					break;
				}
			}
		}


		return $matched_category;
	}


	/**
	 * Get category priority.
	 *
	 * @param string $category Category.
	 *
	 * @return float
	 */
	public function get_priority(
		$category
	) {

		$category =
			sanitize_key(
				$category
			);


		if ( '' === $category ) {
			return 50;
		}


		$settings =
			$this->get_settings();


		if (
			isset(
				$settings[
					$category
				]['priority']
			)
		) {

			return $this->normalize_priority(
				$settings[
					$category
				]['priority']
			);
		}


		return 50;
	}


	/**
	 * Calculate reliability for a source.
	 *
	 * Important:
	 *
	 * A reliability value of 0 from Source Collector means
	 * "not yet assigned", not "known to be completely unreliable".
	 *
	 * Therefore 0 is treated as an unset value.
	 *
	 * @param array $source Source.
	 *
	 * @return float
	 */
	public function get_reliability(
		$source
	) {

		if ( ! is_array( $source ) ) {
			return 50;
		}


		$category =
			$this->classify(
				$source
			);


		$category_score =
			$this->get_priority(
				$category
			);


		/**
		 * Look for an existing reliability score.
		 */
		$has_existing = false;
		$existing     = 0;


		if (
			isset( $source['reliability'] ) &&
			is_numeric( $source['reliability'] )
		) {

			$existing =
				(float) $source['reliability'];


			/**
			 * 0 means "not assigned" in our source model.
			 */
			if ( $existing > 0 ) {
				$has_existing = true;
			}
		}


		/**
		 * If no independent reliability score exists,
		 * category priority is the source reliability.
		 */
		if ( ! $has_existing ) {

			return $this->normalize_priority(
				$category_score
			);
		}


		/**
		 * Blend independent source reliability with
		 * category priority.
		 *
		 * Category gets 40%.
		 * Existing source assessment gets 60%.
		 */
		$score =
			(
				$category_score * 0.40
			) +
			(
				$existing * 0.60
			);


		return $this->normalize_priority(
			$score
		);
	}


	/**
	 * Enrich a source with classification and reliability.
	 *
	 * @param array $source Source.
	 *
	 * @return array
	 */
	public function enrich(
		$source
	) {

		if ( ! is_array( $source ) ) {
			return array();
		}


		$category =
			$this->classify(
				$source
			);


		$source['category'] =
			$category;


		$source['category_priority'] =
			$this->get_priority(
				$category
			);


		$source['reliability'] =
			$this->get_reliability(
				$source
			);


		/**
		 * Keep domain normalized when possible.
		 */
		$domain =
			$this->get_source_domain(
				$source
			);


		if ( '' !== $domain ) {
			$source['domain'] = $domain;
		}


		return $source;
	}


	/**
	 * Enrich and rank sources.
	 *
	 * @param array $sources Sources.
	 *
	 * @return array
	 */
	public function rank_sources(
		$sources
	) {

		if ( ! is_array( $sources ) ) {
			return array();
		}


		$result = array();


		foreach ( $sources as $source ) {

			if ( ! is_array( $source ) ) {
				continue;
			}


			$source =
				$this->enrich(
					$source
				);


			if ( empty( $source ) ) {
				continue;
			}


			$result[] =
				$source;
		}


		usort(
			$result,
			function ( $a, $b ) {

				$a_score =
					isset(
						$a['reliability']
					)
						? (float) $a['reliability']
						: 0;


				$b_score =
					isset(
						$b['reliability']
					)
						? (float) $b['reliability']
						: 0;


				if ( $a_score === $b_score ) {

					$a_priority =
						isset(
							$a['category_priority']
						)
							? (float) $a['category_priority']
							: 0;


					$b_priority =
						isset(
							$b['category_priority']
						)
							? (float) $b['category_priority']
							: 0;


					if (
						$a_priority ===
						$b_priority
					) {

						return 0;
					}


					return $b_priority <=> $a_priority;
				}


				return $b_score <=> $a_score;
			}
		);


		return $result;
	}


	/**
	 * Get top sources.
	 *
	 * @param array $sources Sources.
	 * @param int   $limit   Maximum number.
	 *
	 * @return array
	 */
	public function get_top_sources(
		$sources,
		$limit = 10
	) {

		$sources =
			$this->rank_sources(
				$sources
			);


		$limit =
			absint(
				$limit
			);


		if ( $limit < 1 ) {
			return array();
		}


		return array_slice(
			$sources,
			0,
			$limit
		);
	}


	/**
	 * Get top sources with optional category diversity.
	 *
	 * This method is useful for research workflows where
	 * we do not want all selected sources to come from
	 * the same category.
	 *
	 * @param array $sources   Sources.
	 * @param int   $limit     Maximum number.
	 * @param int   $per_type  Maximum sources per category.
	 *
	 * @return array
	 */
	public function get_diverse_sources(
		$sources,
		$limit = 10,
		$per_type = 3
	) {

		$sources =
			$this->rank_sources(
				$sources
			);


		$limit =
			max(
				1,
				absint(
					$limit
				)
			);


		$per_type =
			max(
				1,
				absint(
					$per_type
				)
			);


		$result =
			array();


		$counts =
			array();


		/**
		 * First pass:
		 * respect category diversity.
		 */
		foreach ( $sources as $source ) {

			if ( count( $result ) >= $limit ) {
				break;
			}


			$category =
				isset(
					$source['category']
				)
					? sanitize_key(
						$source['category']
					)
					: 'general';


			if (
				! isset(
					$counts[ $category ]
				)
			) {

				$counts[ $category ] = 0;
			}


			if (
				$counts[ $category ] >=
				$per_type
			) {

				continue;
			}


			$result[] =
				$source;


			$counts[ $category ]++;
		}


		/**
		 * Second pass:
		 * fill remaining slots with highest-ranked
		 * sources regardless of category.
		 */
		if ( count( $result ) < $limit ) {

			$selected_ids =
				array();


			foreach ( $result as $source ) {

				if ( isset( $source['id'] ) ) {

					$selected_ids[
						(string) $source['id']
					] = true;
				}
			}


			foreach ( $sources as $source ) {

				if ( count( $result ) >= $limit ) {
					break;
				}


				$id =
					isset( $source['id'] )
						? (string) $source['id']
						: '';


				if (
					'' !== $id &&
					isset(
						$selected_ids[ $id ]
					)
				) {

					continue;
				}


				$result[] =
					$source;


				if ( '' !== $id ) {

					$selected_ids[ $id ] =
						true;
				}
			}
		}


		return $result;
	}


	/**
	 * Get source domain.
	 *
	 * @param array $source Source.
	 *
	 * @return string
	 */
	public function get_source_domain(
		$source
	) {

		if ( ! is_array( $source ) ) {
			return '';
		}


		/**
		 * Prefer explicitly supplied domain.
		 */
		if (
			isset( $source['domain'] ) &&
			is_string( $source['domain'] ) &&
			'' !== trim(
				$source['domain']
			)
		) {

			$domain =
				$this->normalize_domain(
					$source['domain']
				);


			if ( '' !== $domain ) {
				return $domain;
			}
		}


		/**
		 * Fall back to URL.
		 */
		if (
			isset( $source['url'] ) &&
			is_string( $source['url'] )
		) {

			return $this->get_domain(
				$source['url']
			);
		}


		return '';
	}


	/**
	 * Extract domain from URL.
	 *
	 * @param string $url URL.
	 *
	 * @return string
	 */
	public function get_domain(
		$url
	) {

		if ( ! is_string( $url ) ) {
			return '';
		}


		$url =
			trim(
				$url
			);


		if ( '' === $url ) {
			return '';
		}


		/**
		 * URLs without a scheme can otherwise be parsed
		 * incorrectly by wp_parse_url().
		 */
		if (
			false ===
			strpos(
				$url,
				'://'
			)
		) {

			$url =
				'https://' .
				$url;
		}


		$parsed =
			wp_parse_url(
				$url
			);


		if (
			! is_array( $parsed ) ||
			empty(
				$parsed['host']
			)
		) {

			return '';
		}


		return $this->normalize_domain(
			$parsed['host']
		);
	}


	/**
	 * Check whether a domain matches a configured domain.
	 *
	 * Matching rules:
	 *
	 * example.com
	 * www.example.com
	 * sub.example.com
	 *
	 * are considered matches for example.com.
	 *
	 * But:
	 *
	 * fakeexample.com
	 *
	 * is NOT considered a match.
	 *
	 * @param string $domain     Actual domain.
	 * @param string $configured Configured domain.
	 *
	 * @return bool
	 */
	private function domain_matches(
		$domain,
		$configured
	) {

		$domain =
			$this->normalize_domain(
				$domain
			);


		$configured =
			$this->normalize_domain(
				$configured
			);


		if (
			'' === $domain ||
			'' === $configured
		) {

			return false;
		}


		if ( $domain === $configured ) {
			return true;
		}


		return 0 === strpos(
			$domain,
			$configured . '.'
		);
	}


	/**
	 * Normalize a domain.
	 *
	 * Supports:
	 *
	 * example.com
	 * www.example.com
	 * https://example.com/path
	 * http://www.example.com/path
	 *
	 * @param string $domain Domain or URL.
	 *
	 * @return string
	 */
	private function normalize_domain(
		$domain
	) {

		if ( ! is_string( $domain ) ) {
			return '';
		}


		$domain =
			trim(
				strtolower(
					$domain
				)
			);


		if ( '' === $domain ) {
			return '';
		}


		/**
		 * If this is actually a URL, extract host.
		 */
		if (
			false !==
			strpos(
				$domain,
				'://'
			)
		) {

			$parsed =
				wp_parse_url(
					$domain
				);


			if (
				is_array( $parsed ) &&
				! empty(
					$parsed['host']
				)
			) {

				$domain =
					$parsed['host'];
			}
		}


		/**
		 * Remove protocol if malformed input omitted
		 * proper URL parsing.
		 */
		$domain =
			preg_replace(
				'#^https?://#i',
				'',
				$domain
			);


		/**
		 * Remove www.
		 */
		$domain =
			preg_replace(
				'/^www\./i',
				'',
				$domain
			);


		/**
		 * Remove path/query/fragment.
		 */
		$domain =
			preg_replace(
				'/[\/?#].*$/',
				'',
				$domain
			);


		/**
		 * Remove port.
		 */
		$domain =
			preg_replace(
				'/:\d+$/',
				'',
				$domain
			);


		/**
		 * Remove accidental whitespace.
		 */
		$domain =
			preg_replace(
				'/\s+/',
				'',
				$domain
			);


		return trim(
			$domain,
			'.'
		);
	}


	/**
	 * Normalize domain list.
	 *
	 * @param array $domains Domains.
	 *
	 * @return array
	 */
	private function normalize_domains(
		$domains
	) {

		if ( ! is_array( $domains ) ) {
			return array();
		}


		$result =
			array();


		foreach ( $domains as $domain ) {

			$domain =
				$this->normalize_domain(
					$domain
				);


			if ( '' === $domain ) {
				continue;
			}


			$result[] =
				$domain;
		}


		return array_values(
			array_unique(
				$result
			)
		);
	}


	/**
	 * Normalize priority/reliability value.
	 *
	 * @param mixed $value Value.
	 *
	 * @return float
	 */
	private function normalize_priority(
		$value
	) {

		if ( ! is_numeric( $value ) ) {
			$value = 50;
		}


		$value =
			(float) $value;


		if ( $value < 0 ) {
			$value = 0;
		}


		if ( $value > 100 ) {
			$value = 100;
		}


		return $value;
	}


    /**
     * Get user-managed source profiles.
     * Structure: global => sources, brands => keyed profiles, vehicles => post-id keyed sources.
     */
    public function get_profiles() {
        $saved = get_option( 'tl_ai_vm_source_profiles', array() );
        if ( ! is_array( $saved ) ) { $saved = array(); }
        $profiles = array(
            'global' => array(),
            'brands' => array(),
            'vehicles' => array(),
        );
        foreach ( $profiles as $key => $default ) {
            if ( isset( $saved[ $key ] ) && is_array( $saved[ $key ] ) ) { $profiles[ $key ] = $saved[ $key ]; }
        }
        $profiles['global'] = $this->normalize_profile_sources( $profiles['global'] );
        $brands = array();
        foreach ( $profiles['brands'] as $key => $brand ) {
            if ( ! is_array( $brand ) ) { continue; }
            $name = isset( $brand['name'] ) ? sanitize_text_field( $brand['name'] ) : $key;
            $aliases = isset( $brand['aliases'] ) && is_array( $brand['aliases'] ) ? array_values( array_filter( array_map( 'sanitize_text_field', $brand['aliases'] ) ) ) : array();
            $brands[ sanitize_key( $key ) ] = array( 'name' => $name, 'aliases' => $aliases, 'sources' => $this->normalize_profile_sources( isset( $brand['sources'] ) ? $brand['sources'] : array() ) );
        }
        $profiles['brands'] = $brands;
        $vehicles = array();
        foreach ( $profiles['vehicles'] as $post_id => $sources ) {
            $post_id = absint( $post_id );
            if ( $post_id ) { $vehicles[ $post_id ] = $this->normalize_profile_sources( $sources ); }
        }
        $profiles['vehicles'] = $vehicles;
        // Useful safe defaults: these are only seed sources and can be edited/deleted in Source Data.
        if ( empty($profiles['global']) && empty($profiles['brands']) && empty($profiles['vehicles']) ) {
            $profiles['global'] = array(
                array('url'=>'https://mycarlubs.com/','label'=>'MyCarLubs','priority'=>80,'enabled'=>true,'groups'=>array(),'type'=>'technical'),
            );
            $profiles['brands'] = array(
                'toyota' => array('name'=>'Toyota','aliases'=>array('Toyota','تويوتا'),'sources'=>array( array('url'=>'https://www.toyota.com/owners/','label'=>'Toyota Owners','priority'=>100,'enabled'=>true,'groups'=>array(),'type'=>'official') )),
                'hyundai' => array('name'=>'Hyundai','aliases'=>array('Hyundai','هیوندای'),'sources'=>array( array('url'=>'https://owners.hyundaiusa.com/','label'=>'Hyundai Owners','priority'=>100,'enabled'=>true,'groups'=>array(),'type'=>'official') )),
            );
        }
        return $profiles;
    }

    public function save_profiles( $profiles ) {
        if ( ! is_array( $profiles ) ) { return false; }
        $clean = array( 'global' => array(), 'brands' => array(), 'vehicles' => array() );
        $clean['global'] = $this->normalize_profile_sources( isset( $profiles['global'] ) ? $profiles['global'] : array() );
        if ( ! empty( $profiles['brands'] ) && is_array( $profiles['brands'] ) ) {
            foreach ( $profiles['brands'] as $key => $brand ) {
                if ( ! is_array( $brand ) ) { continue; }
                $key = sanitize_key( $key );
                $name = isset( $brand['name'] ) ? sanitize_text_field( $brand['name'] ) : $key;
                if ( ! $key || ! $name ) { continue; }
                $aliases = isset( $brand['aliases'] ) && is_array( $brand['aliases'] ) ? array_values( array_filter( array_map( 'sanitize_text_field', $brand['aliases'] ) ) ) : array();
                $clean['brands'][ $key ] = array( 'name' => $name, 'aliases' => $aliases, 'sources' => $this->normalize_profile_sources( isset( $brand['sources'] ) ? $brand['sources'] : array() ) );
            }
        }
        if ( ! empty( $profiles['vehicles'] ) && is_array( $profiles['vehicles'] ) ) {
            foreach ( $profiles['vehicles'] as $post_id => $sources ) {
                $post_id = absint( $post_id );
                if ( $post_id ) { $clean['vehicles'][ $post_id ] = $this->normalize_profile_sources( $sources ); }
            }
        }
        $this->settings_cache = null;
        return update_option( 'tl_ai_vm_source_profiles', $clean, false );
    }

    /** Return highest-priority configured domains for a vehicle/field. */
    public function get_research_domains( $post_id, $group_key = '', $limit = 6 ) {
        $profiles = $this->get_profiles();
        $items = array();
        $add = function( $sources, $boost ) use ( &$items, $group_key ) {
            foreach ( (array) $sources as $source ) {
                if ( ! is_array( $source ) || empty( $source['enabled'] ) || empty( $source['url'] ) ) { continue; }
                $groups = isset( $source['groups'] ) && is_array( $source['groups'] ) ? $source['groups'] : array();
                if ( $group_key && ! empty( $groups ) && ! in_array( sanitize_key( $group_key ), array_map( 'sanitize_key', $groups ), true ) ) { continue; }
                $domain = $this->domain_from_url( $source['url'] );
                if ( ! $domain ) { continue; }
                $items[] = array( 'domain' => $domain, 'priority' => (float) $source['priority'] + $boost, 'label' => isset($source['label']) ? $source['label'] : $domain );
            }
        };
        $post_id = absint( $post_id );
        if ( $post_id && isset( $profiles['vehicles'][ $post_id ] ) ) { $add( $profiles['vehicles'][ $post_id ], 1000 ); }
        $brand_key = $this->detect_brand_key( $post_id, $profiles );
        if ( $brand_key && isset( $profiles['brands'][ $brand_key ] ) ) { $add( $profiles['brands'][ $brand_key ]['sources'], 500 ); }
        $add( $profiles['global'], 100 );
        usort( $items, function( $a, $b ) { return $a['priority'] == $b['priority'] ? 0 : ( $a['priority'] < $b['priority'] ? 1 : -1 ); } );
        $domains = array();
        foreach ( $items as $item ) { if ( ! in_array( $item['domain'], $domains, true ) ) { $domains[] = $item['domain']; } if ( count($domains) >= max(1,absint($limit)) ) { break; } }
        return $domains;
    }

    public function get_detected_brand( $post_id ) {
        $profiles = $this->get_profiles();
        $key = $this->detect_brand_key( absint($post_id), $profiles );
        return $key && isset($profiles['brands'][$key]) ? $profiles['brands'][$key] : array();
    }

    private function detect_brand_key( $post_id, $profiles ) {
        if ( ! $post_id || empty($profiles['brands']) ) { return ''; }
        $haystack = strtolower( get_the_title($post_id) );
        if ( function_exists('get_field') ) {
            $schema = TL_AI_VM_Field_Schema::instance()->get_fields( get_post_type($post_id) );
            foreach ( $schema as $field ) {
                $text = strtolower( (isset($field['label'])?$field['label']:'').' '.(isset($field['name'])?$field['name']:'') );
                if ( preg_match('/\\b(brand|make|manufacturer|مارک|برند)\\b/i', $text) ) {
                    $v = get_field( !empty($field['key'])?$field['key']:$field['name'], $post_id, false );
                    if ( is_scalar($v) && trim((string)$v) ) { $haystack .= ' '.strtolower((string)$v); break; }
                }
            }
        }
        foreach ( $profiles['brands'] as $key => $brand ) {
            $names = array_merge( array($brand['name']), isset($brand['aliases'])?$brand['aliases']:array() );
            foreach ( $names as $name ) {
                $name = strtolower(trim((string)$name));
                if ( $name && false !== strpos($haystack, $name) ) { return $key; }
            }
        }
        return '';
    }

    private function normalize_profile_sources( $sources ) {
        $out = array();
        if ( ! is_array($sources) ) { return $out; }
        foreach ( $sources as $source ) {
            if ( ! is_array($source) ) { continue; }
            $url = isset($source['url']) ? esc_url_raw(trim((string)$source['url'])) : '';
            if ( ! $url ) { continue; }
            $groups = isset($source['groups']) && is_array($source['groups']) ? array_values(array_filter(array_map('sanitize_key',$source['groups']))) : array();
            $out[] = array(
                'url' => $url,
                'label' => isset($source['label']) ? sanitize_text_field($source['label']) : $this->domain_from_url($url),
                'priority' => isset($source['priority']) ? max(1,min(100,(float)$source['priority'])) : 80,
                'enabled' => !isset($source['enabled']) || !empty($source['enabled']),
                'groups' => $groups,
                'type' => isset($source['type']) ? sanitize_key($source['type']) : 'technical',
            );
        }
        return $out;
    }

    private function domain_from_url( $url ) {
        $host = wp_parse_url($url, PHP_URL_HOST);
        if ( ! $host ) { return ''; }
        return $this->normalize_domain($host);
    }

}
