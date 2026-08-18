<?php
/**
 * Source Collector
 *
 * Manages normalized web research sources for AI vehicle research.
 *
 * Responsibilities:
 *
 * - Create normalized source records.
 * - Extract and normalize source domains.
 * - Clean source content.
 * - Deduplicate sources.
 * - Preserve source metadata.
 * - Build bounded AI research context.
 *
 * This class does NOT decide source reliability or category.
 * Source classification and reliability are handled by
 * TL_AI_VM_Source_Manager.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TL_AI_VM_Source_Collector {

	/**
	 * Singleton instance.
	 *
	 * @var TL_AI_VM_Source_Collector|null
	 */
	private static $instance = null;


	/**
	 * Get singleton instance.
	 *
	 * @return TL_AI_VM_Source_Collector
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
	 * Create a normalized source record.
	 *
	 * Reliability/category are intentionally not calculated here.
	 * They belong to Source Manager.
	 *
	 * @param string $url     Source URL.
	 * @param string $title   Source title.
	 * @param string $content Source content.
	 * @param array  $args    Additional source metadata.
	 *
	 * @return array
	 */
	public function create_source(
		$url,
		$title = '',
		$content = '',
		$args = array()
	) {

		$url = is_string( $url )
			? trim( $url )
			: '';

		$url = esc_url_raw( $url );

		if ( '' === $url ) {

			return array(
				'success' => false,
				'error'   => 'Invalid source URL.',
			);
		}


		$domain = $this->get_domain( $url );

		/**
		 * Optional explicitly supplied domain.
		 *
		 * We normalize it, but URL-derived domain remains
		 * the fallback when no valid domain was supplied.
		 */
		if (
			is_array( $args ) &&
			isset( $args['domain'] ) &&
			is_string( $args['domain'] ) &&
			'' !== trim( $args['domain'] )
		) {

			$custom_domain =
				$this->normalize_domain(
					$args['domain']
				);

			if ( '' !== $custom_domain ) {
				$domain = $custom_domain;
			}
		}


		$type = 'web';

		if (
			is_array( $args ) &&
			isset( $args['type'] )
		) {

			$type =
				sanitize_key(
					$args['type']
				);

			if ( '' === $type ) {
				$type = 'web';
			}
		}


		$published = '';

		if (
			is_array( $args ) &&
			isset( $args['published'] )
		) {

			$published =
				sanitize_text_field(
					$args['published']
				);
		}


		$author = '';

		if (
			is_array( $args ) &&
			isset( $args['author'] )
		) {

			$author =
				sanitize_text_field(
					$args['author']
				);
		}


		$retrieved = current_time( 'timestamp' );

		if (
			is_array( $args ) &&
			isset( $args['retrieved'] )
		) {

			$retrieved =
				absint(
					$args['retrieved']
				);

			if ( ! $retrieved ) {
				$retrieved = current_time( 'timestamp' );
			}
		}


		/**
		 * Reliability may already exist when a source is being
		 * passed between research stages.
		 *
		 * Source Manager can later recalculate/enrich it.
		 */
		$reliability = 0;

		if (
			is_array( $args ) &&
			isset( $args['reliability'] ) &&
			is_numeric( $args['reliability'] )
		) {

			$reliability =
				$this->normalize_reliability(
					$args['reliability']
				);
		}


		$source = array(
			'id' =>
				'src_' . wp_generate_uuid4(),

			'type' =>
				$type,

			'url' =>
				$url,

			'title' =>
				sanitize_text_field(
					$title
				),

			'domain' =>
				$domain,

			'content' =>
				$this->clean_content(
					$content
				),

			'published' =>
				$published,

			'author' =>
				$author,

			'reliability' =>
				$reliability,

			'retrieved' =>
				$retrieved,
		);


		/**
		 * Preserve optional source metadata without allowing
		 * callers to overwrite the core source structure.
		 *
		 * This is important for future research providers,
		 * citations, extraction methods, language information,
		 * HTTP metadata, etc.
		 */
		if ( is_array( $args ) ) {

			$reserved_keys = array(
				'id',
				'type',
				'url',
				'title',
				'domain',
				'content',
				'published',
				'author',
				'reliability',
				'retrieved',
			);

			foreach ( $args as $key => $value ) {

				$key =
					sanitize_key(
						$key
					);

				if ( '' === $key ) {
					continue;
				}

				if (
					in_array(
						$key,
						$reserved_keys,
						true
					)
				) {
					continue;
				}

				$source[ $key ] = $value;
			}
		}


		/**
		 * Allow other modules to enrich the normalized source.
		 */
		$source =
			apply_filters(
				'tl_ai_vm_source_created',
				$source,
				$url,
				$title,
				$content,
				$args
			);


		if ( ! is_array( $source ) ) {

			return array(
				'success' => false,
				'error'   => 'Source normalization failed.',
			);
		}


		return $source;
	}


	/**
	 * Add a source to an existing collection.
	 *
	 * Duplicate URLs are ignored.
	 *
	 * @param array  $sources Source collection.
	 * @param string $url     Source URL.
	 * @param string $title   Source title.
	 * @param string $content Source content.
	 * @param array  $args    Additional data.
	 *
	 * @return array
	 */
	public function add_source(
		$sources,
		$url,
		$title = '',
		$content = '',
		$args = array()
	) {

		if ( ! is_array( $sources ) ) {
			$sources = array();
		}


		$source =
			$this->create_source(
				$url,
				$title,
				$content,
				$args
			);


		if (
			isset( $source['success'] ) &&
			false === $source['success']
		) {

			return $sources;
		}


		if (
			empty( $source['url'] )
		) {

			return $sources;
		}


		$normalized_url =
			$this->normalize_url(
				$source['url']
			);


		/**
		 * Avoid duplicate URLs.
		 */
		foreach ( $sources as $existing ) {

			if ( ! is_array( $existing ) ) {
				continue;
			}

			if ( empty( $existing['url'] ) ) {
				continue;
			}

			if (
				$normalized_url ===
				$this->normalize_url(
					$existing['url']
				)
			) {

				return $sources;
			}
		}


		$sources[] = $source;


		return $sources;
	}


	/**
	 * Remove duplicate sources.
	 *
	 * Keeps the first occurrence of each normalized URL.
	 *
	 * @param array $sources Sources.
	 *
	 * @return array
	 */
	public function deduplicate(
		$sources
	) {

		if ( ! is_array( $sources ) ) {
			return array();
		}


		$result = array();
		$seen   = array();


		foreach ( $sources as $source ) {

			if (
				! is_array( $source ) ||
				empty( $source['url'] )
			) {
				continue;
			}


			$url =
				$this->normalize_url(
					$source['url']
				);


			if ( '' === $url ) {
				continue;
			}


			$hash = md5( $url );


			if ( isset( $seen[ $hash ] ) ) {
				continue;
			}


			$seen[ $hash ] = true;


			$result[] =
				$source;
		}


		return $result;
	}


	/**
	 * Sort sources by reliability.
	 *
	 * This method only sorts an already-enriched source list.
	 * It does not calculate reliability.
	 *
	 * @param array $sources Sources.
	 *
	 * @return array
	 */
	public function sort_by_reliability(
		$sources
	) {

		if ( ! is_array( $sources ) ) {
			return array();
		}


		usort(
			$sources,
			function ( $a, $b ) {

				$a_score =
					isset( $a['reliability'] ) &&
					is_numeric( $a['reliability'] )
						? (float) $a['reliability']
						: 0;

				$b_score =
					isset( $b['reliability'] ) &&
					is_numeric( $b['reliability'] )
						? (float) $b['reliability']
						: 0;


				if ( $a_score === $b_score ) {
					return 0;
				}


				return (
					$b_score > $a_score
				)
					? 1
					: -1;
			}
		);


		return $sources;
	}


	/**
	 * Select the most useful sources.
	 *
	 * Source Manager should normally enrich sources before this
	 * method is called.
	 *
	 * @param array $sources Sources.
	 * @param int   $limit   Maximum number of sources.
	 *
	 * @return array
	 */
	public function select_best(
		$sources,
		$limit = 10
	) {

		$sources =
			$this->deduplicate(
				$sources
			);


		$sources =
			$this->sort_by_reliability(
				$sources
			);


		$limit =
			max(
				1,
				absint(
					$limit
				)
			);


		return array_slice(
			$sources,
			0,
			$limit
		);
	}


	/**
	 * Build a bounded research context for AI.
	 *
	 * Important:
	 * Source URLs and metadata are included as context.
	 * The source content itself is treated as untrusted external
	 * data and must never be interpreted as system instructions.
	 *
	 * @param array $sources   Sources.
	 * @param int   $max_chars Maximum context characters.
	 *
	 * @return string
	 */
	public function build_ai_context(
		$sources,
		$max_chars = 30000
	) {

		$sources =
			$this->select_best(
				$sources
			);


		$max_chars =
			max(
				1000,
				absint(
					$max_chars
				)
			);


		$output = '';


		foreach ( $sources as $index => $source ) {

			$title =
				isset( $source['title'] ) &&
				is_string( $source['title'] )
					? $source['title']
					: '';


			$url =
				isset( $source['url'] ) &&
				is_string( $source['url'] )
					? $source['url']
					: '';


			$domain =
				isset( $source['domain'] ) &&
				is_string( $source['domain'] )
					? $source['domain']
					: '';


			$content =
				isset( $source['content'] ) &&
				is_string( $source['content'] )
					? $source['content']
					: '';


			$reliability =
				isset( $source['reliability'] ) &&
				is_numeric( $source['reliability'] )
					? (float) $source['reliability']
					: 0;


			$block =
				"\n\n" .
				'--- SOURCE ' .
				( $index + 1 ) .
				" ---\n" .
				'Title: ' .
				$title .
				"\n" .
				'Domain: ' .
				$domain .
				"\n" .
				'URL: ' .
				$url .
				"\n" .
				'Reliability: ' .
				$reliability .
				"\n\n" .
				"BEGIN SOURCE CONTENT\n" .
				$content .
				"\n" .
				"END SOURCE CONTENT\n";


			$current_length =
				strlen( $output );


			$block_length =
				strlen( $block );


			if (
				$current_length +
				$block_length >
				$max_chars
			) {

				$remaining =
					$max_chars -
					$current_length;


				/**
				 * Do not append a meaningless tiny fragment.
				 */
				if ( $remaining > 500 ) {

					$output .=
						substr(
							$block,
							0,
							$remaining
						);
				}


				break;
			}


			$output .=
				$block;
		}


		return trim( $output );
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

		$url =
			is_string( $url )
				? trim( $url )
				: '';


		if ( '' === $url ) {
			return '';
		}


		$parsed =
			wp_parse_url(
				$url
			);


		if (
			! is_array( $parsed ) ||
			empty( $parsed['host'] )
		) {

			return '';
		}


		return $this->normalize_domain(
			$parsed['host']
		);
	}


	/**
	 * Normalize a domain.
	 *
	 * @param string $domain Domain.
	 *
	 * @return string
	 */
	public function normalize_domain(
		$domain
	) {

		if ( ! is_string( $domain ) ) {
			return '';
		}


		$domain =
			strtolower(
				trim(
					$domain
				)
			);


		/**
		 * A caller may accidentally provide a URL instead
		 * of a bare domain.
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
				! empty( $parsed['host'] )
			) {

				$domain =
					$parsed['host'];
			}
		}


		/**
		 * Remove protocol remnants.
		 */
		$domain =
			preg_replace(
				'#^https?://#i',
				'',
				$domain
			);


		/**
		 * Remove path/query/fragment.
		 */
		$domain =
			preg_replace(
				'#[/?#].*$#',
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
		 * Normalize www.
		 */
		$domain =
			preg_replace(
				'/^www\./i',
				'',
				$domain
			);


		return sanitize_text_field(
			$domain
		);
	}


	/**
	 * Clean source content.
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


		/**
		 * Remove HTML and scripts/styles before normalizing
		 * whitespace.
		 */
		$content =
			preg_replace(
				'#<(script|style|noscript)\b[^>]*>.*?</\1>#is',
				' ',
				$content
			);


		$content =
			wp_strip_all_tags(
				$content
			);


		$content =
			html_entity_decode(
				$content,
				ENT_QUOTES | ENT_HTML5,
				'UTF-8'
			);


		$content =
			preg_replace(
				'/[ \t]+/',
				' ',
				$content
			);


		$content =
			preg_replace(
				"/\r\n|\r/",
				"\n",
				$content
			);


		$content =
			preg_replace(
				"/\n{3,}/",
				"\n\n",
				$content
			);


		return trim(
			$content
		);
	}


	/**
	 * Normalize URL for duplicate detection.
	 *
	 * The goal is not to rewrite URLs aggressively.
	 * It only removes harmless differences that commonly
	 * represent the same URL.
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


		if ( '' === $url ) {
			return '';
		}


		$parsed =
			wp_parse_url(
				$url
			);


		if (
			! is_array( $parsed ) ||
			empty( $parsed['host'] )
		) {

			return rtrim(
				$url,
				'/'
			);
		}


		$scheme =
			isset( $parsed['scheme'] )
				? strtolower(
					$parsed['scheme']
				)
				: 'https';


		$host =
			$this->normalize_domain(
				$parsed['host']
			);


		$normalized =
			$scheme .
			'://' .
			$host;


		if ( ! empty( $parsed['port'] ) ) {

			$normalized .=
				':' .
				absint(
					$parsed['port']
				);
		}


		if ( ! empty( $parsed['path'] ) ) {

			$normalized .=
				'/' .
				ltrim(
					$parsed['path'],
					'/'
				);
		}


		/**
		 * Preserve query strings because they may identify
		 * different research pages.
		 */
		if ( ! empty( $parsed['query'] ) ) {

			$normalized .=
				'?' .
				$parsed['query'];
		}


		/**
		 * Fragments normally do not identify a different
		 * server-side document and are therefore omitted.
		 */


		return rtrim(
			$normalized,
			'/'
		);
	}


	/**
	 * Normalize reliability score.
	 *
	 * @param mixed $value Score.
	 *
	 * @return float
	 */
	private function normalize_reliability(
		$value
	) {

		$value =
			is_numeric( $value )
				? (float) $value
				: 0;


		$value =
			max(
				0,
				min(
					100,
					$value
				)
			);


		return $value;
	}

}
