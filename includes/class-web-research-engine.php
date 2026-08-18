<?php
/**
 * Web Research Engine
 *
 * Coordinates web research for the AI Vehicle Manager.
 *
 * Responsibilities:
 * - Build research queries
 * - Search the web through Search Provider
 * - Collect source pages
 * - Fetch page content
 * - Normalize research results
 *
 * The engine does not directly write data into ACF.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TL_AI_VM_Web_Research_Engine {

	/**
	 * Singleton instance.
	 *
	 * @var TL_AI_VM_Web_Research_Engine|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return TL_AI_VM_Web_Research_Engine
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
	 * Run research for a vehicle.
	 *
	 * @param int   $vehicle_id Vehicle post ID.
	 * @param array $args       Research arguments.
	 *
	 * @return array
	 */
	public function research(
		$vehicle_id,
		$args = array()
	) {

		$vehicle_id = absint( $vehicle_id );

		if ( ! $vehicle_id ) {

			return array(
				'success' => false,
				'error'   => 'Vehicle ID is missing.',
				'results' => array(),
			);
		}

		$post = get_post( $vehicle_id );

		if ( ! $post ) {

			return array(
				'success' => false,
				'error'   => 'Vehicle post was not found.',
				'vehicle_id' => $vehicle_id,
				'results' => array(),
			);
		}

		$defaults = array(
			'queries'        => array(),
			'limit'          => 10,
			'fetch_pages'    => true,
			'max_pages'      => 5,
			'language'       => '',
			'country'        => '',
			'freshness'      => '',
			'domains'        => array(),
			'field'          => '',
			'max_queries'   => 6,
		);

		$args = wp_parse_args(
			$args,
			$defaults
		);

		$queries = $this->build_queries(
			$vehicle_id,
			$args
		);

		// Keep each asynchronous HTTP step bounded: one search query per request.
		$max_queries = max( 1, min( 8, absint( $args['max_queries'] ) ) );
		// Focused field research needs only the best 1-2 queries. Keeping this small
		// avoids dozens of Google requests when a vehicle has many ACF fields.
		if ( ! empty( $args['field'] ) ) { $max_queries = min( $max_queries, 5 ); }
		$queries = array_slice( $queries, 0, $max_queries );

		if ( empty( $queries ) ) {

			return array(
				'success' => false,
				'error'   => 'No research queries could be generated.',
				'vehicle_id' => $vehicle_id,
				'queries' => array(),
				'results' => array(),
			);
		}

		$search_provider =
			$this->get_search_provider();

		if ( ! $search_provider ) {

			return array(
				'success' => false,
				'error'   => 'Search provider is not available.',
				'vehicle_id' => $vehicle_id,
				'queries' => $queries,
				'results' => array(),
			);
		}

		$search_args = array(
			'limit'     => absint( $args['limit'] ),
			'language'  => $args['language'],
			'country'   => $args['country'],
			'freshness' => $args['freshness'],
		);

		$search_args['limit'] = max(
			1,
			min(
				50,
				$search_args['limit']
			)
		);

		if ( ! empty( $args['domains'] ) ) {

			$search_args['domain'] =
				is_array( $args['domains'] )
					? implode(
						',',
						array_map(
							'sanitize_text_field',
							$args['domains']
						)
					)
					: sanitize_text_field(
						$args['domains']
					);
		}

		// User-configured vehicle/brand/global sources are searched first.
		$search_response = array( 'success' => true, 'results' => array(), 'queries' => array() );
		$target_count = max( 3, min( 8, absint( $args['limit'] ) ) );
		$domains = array();
		if ( class_exists( 'TL_AI_VM_Source_Manager' ) ) {
			$domains = TL_AI_VM_Source_Manager::instance()->get_research_domains( $vehicle_id, isset($args['group_key']) ? $args['group_key'] : '', 4 );
		}
		if ( class_exists( 'TL_AI_VM_Field_Intelligence' ) && ! empty( $args['field'] ) ) {
			$field_domains = TL_AI_VM_Field_Intelligence::instance()->get_domains( $args['field'], isset($args['group_key']) ? $args['group_key'] : '' );
			if ( ! empty( $field_domains ) ) { $domains = array_values( array_unique( array_merge( $field_domains, (array) $domains ) ) ); }
		}
		if ( class_exists( 'TL_AI_VM_Learning_Memory' ) && ! empty( $args['field'] ) ) {
			$learned_domains = TL_AI_VM_Learning_Memory::instance()->learned_sources( $args['field'], 5 );
			if ( ! empty( $learned_domains ) ) { $domains = array_values( array_unique( array_merge( $learned_domains, (array) $domains ) ) ); }
		}
		foreach ( $domains as $domain ) {
			$opts = $search_args;
			$opts['domain'] = $domain;
			$one = $search_provider->search_many( $queries, $opts );
			if ( ! empty( $one ) ) { $search_response['results'] = array_merge( $search_response['results'], $one ); }
			if ( count( $search_response['results'] ) >= $target_count ) { break; }
		}
		// Free/general web fallback. OpenAI is never required for this step.
		if ( count( $search_response['results'] ) < $target_count ) {
			$fallback = $search_provider->search_many( $queries, $search_args );
			if ( ! empty( $fallback ) ) { $search_response['results'] = array_merge( $search_response['results'], $fallback ); }
		}
		$search_response['results'] = $this->deduplicate_results( $search_response['results'] );
		$search_response['total'] = count( $search_response['results'] );
		if ( empty( $search_response['results'] ) ) {
			return array( 'success' => false, 'error' => 'No web search results were found.', 'vehicle_id' => $vehicle_id, 'queries' => $queries, 'search' => $search_response, 'results' => array() );
		}
		$results = $this->normalize_search_results( $search_response['results'] );

		if ( ! empty( $args['fetch_pages'] ) ) {

			$results =
				$this->fetch_source_pages(
					$results,
					$args
				);
		}

		$results =
			$this->deduplicate_results(
				$results
			);

		return array(
			'success' =>
				! empty( $results ),

			'vehicle_id' =>
				$vehicle_id,

			'vehicle_title' =>
				get_the_title(
					$vehicle_id
				),

			'queries' =>
				$queries,

			'total_results' =>
				count( $results ),

			'results' =>
				$results,

			'search' =>
				$search_response,
		);
	}

	/**
	 * Build research queries.
	 *
	 * Uses the dedicated query builder when available.
	 *
	 * @param int   $vehicle_id Vehicle ID.
	 * @param array $args       Arguments.
	 *
	 * @return array
	 */
	private function build_queries(
		$vehicle_id,
		$args
	) {

		if (
			! empty( $args['queries'] ) &&
			is_array( $args['queries'] )
		) {

			return $this->clean_queries(
				$args['queries']
			);
		}

		$builder =
			$this->get_query_builder();

		if ( $builder ) {

			if (
				method_exists(
					$builder,
					'build_for_vehicle'
				)
			) {

				$result =
					$builder->build_for_vehicle(
						$vehicle_id,
						$args
					);

				if ( is_array( $result ) ) {

					return $this->clean_queries(
						$result
					);
				}
			}

			if (
				method_exists(
					$builder,
					'build'
				)
			) {

				$fields = array();

				if ( class_exists( 'TL_AI_VM_Field_Schema' ) ) {
					$fields = TL_AI_VM_Field_Schema::instance()->get_fields(
						get_post_type( $vehicle_id )
					);
				}

				$result =
					$builder->build(
						$vehicle_id,
						$fields,
						$args
					);

				if ( is_array( $result ) ) {

					return $this->clean_queries(
						$result
					);
				}
			}
		}

		/**
		 * Fallback query.
		 *
		 * This keeps the engine functional even if the
		 * query-builder implementation is changed later.
		 */
		$title =
			get_the_title(
				$vehicle_id
			);

		if ( empty( $title ) ) {
			return array();
		}

		return array(
			$title . ' specifications',
			$title . ' technical specifications',
			$title . ' engine gearbox dimensions',
		);
	}

	/**
	 * Fetch source pages.
	 *
	 * @param array $results Search results.
	 * @param array $args    Arguments.
	 *
	 * @return array
	 */
	private function fetch_source_pages(
		$results,
		$args
	) {

		$fetcher =
			$this->get_page_fetcher();

		if ( ! $fetcher ) {
			return $results;
		}

		$max_pages =
			isset(
				$args['max_pages']
			)
				? absint(
					$args['max_pages']
				)
				: 5;

		$parallel_pages = class_exists( 'TL_AI_VM_Field_Intelligence' ) ? TL_AI_VM_Field_Intelligence::instance()->get_settings()['parallel_pages'] : 5;
		$max_pages = max( 1, min( 50, $max_pages, absint( $parallel_pages ) ) );

		$urls = array();
		$indexes = array();
		foreach ( $results as $index => $result ) {
			if ( count( $urls ) >= $max_pages ) { break; }
			if ( empty( $result['url'] ) ) { continue; }
			$urls[] = $result['url'];
			$indexes[] = $index;
		}

		if ( ! empty( $urls ) && method_exists( $fetcher, 'fetch_multiple' ) ) {
			$batch = $fetcher->fetch_multiple( $urls, array( 'timeout' => 12 ) );
			$pages = isset( $batch['pages'] ) && is_array( $batch['pages'] ) ? $batch['pages'] : array();
			foreach ( $pages as $n => $fetched ) {
				if ( ! isset( $indexes[$n] ) || ! is_array( $fetched ) ) { continue; }
				$idx = $indexes[$n];
				$results[$idx]['page'] = $fetched;
				if ( ! empty( $fetched['content'] ) ) { $results[$idx]['content'] = $fetched['content']; }
				if ( ! empty( $fetched['title'] ) && empty( $results[$idx]['title'] ) ) { $results[$idx]['title'] = $fetched['title']; }
			}
		}

		return $results;
	}

	/**
	 * Normalize search results.
	 *
	 * @param array $results Results.
	 *
	 * @return array
	 */
	private function normalize_search_results(
		$results
	) {

		$normalized = array();

		if ( ! is_array( $results ) ) {
			return $normalized;
		}

		foreach ( $results as $result ) {

			if ( ! is_array( $result ) ) {
				continue;
			}

			$url =
				isset(
					$result['url']
				)
					? esc_url_raw(
						$result['url']
					)
					: '';

			if ( empty( $url ) ) {
				continue;
			}

			$normalized[] = array(
				'url' =>
					$url,

				'title' =>
					isset(
						$result['title']
					)
						? sanitize_text_field(
							$result['title']
						)
						: '',

				'snippet' =>
					isset(
						$result['snippet']
					)
						? sanitize_textarea_field(
							$result['snippet']
						)
						: '',

				'domain' =>
					isset(
						$result['domain']
					)
						? sanitize_text_field(
							$result['domain']
						)
						: '',

				'rank' =>
					isset(
						$result['rank']
					)
						? absint(
							$result['rank']
						)
						: 0,

				'published_at' =>
					isset(
						$result['published_at']
					)
						? sanitize_text_field(
							$result['published_at']
						)
						: '',

				'content' => '',

				'page' => array(),

				'raw' =>
					isset(
						$result['raw']
					) &&
					is_array(
						$result['raw']
					)
						? $result['raw']
						: array(),
			);
		}

		return $normalized;
	}

	/**
	 * Remove duplicate URLs.
	 *
	 * @param array $results Results.
	 *
	 * @return array
	 */
	private function deduplicate_results(
		$results
	) {

		$unique = array();
		$seen   = array();

		foreach ( $results as $result ) {

			if (
				empty(
					$result['url']
				)
			) {
				continue;
			}

			$url =
				$this->normalize_url(
					$result['url']
				);

			if (
				isset(
					$seen[ $url ]
				)
			) {
				continue;
			}

			$seen[ $url ] = true;

			$unique[] = $result;
		}

		return $unique;
	}

	/**
	 * Normalize URL.
	 *
	 * @param string $url URL.
	 *
	 * @return string
	 */
	private function normalize_url(
		$url
	) {

		$url = trim(
			$url
		);

		$url = preg_replace(
			'/#.*$/',
			'',
			$url
		);

		$url = preg_replace(
			'/\/+$/',
			'',
			$url
		);

		return strtolower(
			$url
		);
	}

	/**
	 * Clean queries.
	 *
	 * @param array $queries Queries.
	 *
	 * @return array
	 */
	private function clean_queries(
		$queries
	) {

		$clean = array();

		foreach ( $queries as $query ) {

			if (
				! is_string( $query )
			) {
				continue;
			}

			$query =
				sanitize_text_field(
					$query
				);

			if ( empty( $query ) ) {
				continue;
			}

			$clean[] = $query;
		}

		return array_values(
			array_unique(
				$clean
			)
		);
	}

	/**
	 * Get Search Provider.
	 *
	 * @return object|null
	 */
	private function get_search_provider() {

		if (
			class_exists(
				'TL_AI_VM_Search_Provider'
			)
		) {

			return TL_AI_VM_Search_Provider::instance();
		}

		return null;
	}

	/**
	 * Get Search Query Builder.
	 *
	 * @return object|null
	 */
	private function get_query_builder() {

		if (
			class_exists(
				'TL_AI_VM_Search_Query_Builder'
			)
		) {

			return TL_AI_VM_Search_Query_Builder::instance();
		}

		return null;
	}

	/**
	 * Get Page Fetcher.
	 *
	 * @return object|null
	 */
	private function get_page_fetcher() {

		if (
			class_exists(
				'TL_AI_VM_Page_Fetcher'
			)
		) {

			return TL_AI_VM_Page_Fetcher::instance();
		}

		return null;
	}

	/**
	 * Get engine information.
	 *
	 * @return array
	 */
	public function get_info() {

		return array(
			'name' =>
				'TL AI VM Web Research Engine',

			'version' =>
				'1.0.0',

			'search_provider' =>
				class_exists(
					'TL_AI_VM_Search_Provider'
				),

			'query_builder' =>
				class_exists(
					'TL_AI_VM_Search_Query_Builder'
				),

			'page_fetcher' =>
				class_exists(
					'TL_AI_VM_Page_Fetcher'
				),
		);
	}

    /**
     * Web-first deterministic fallback. AI is optional.
     */
    private function web_first_fallback( $vehicle, $field, $sources = array() ) {
        $provider = new TL_AI_VM_Web_First_Research_Provider();
        return $provider->research_field( $vehicle, $field, $sources );
    }

}