<?php
/**
 * Page Fetcher
 *
 * Fetches web pages and extracts readable content.
 *
 * This class is intentionally separated from the search provider.
 * Search finds URLs; this class reads the actual pages.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TL_AI_VM_Page_Fetcher {

	/**
	 * Singleton instance.
	 *
	 * @var TL_AI_VM_Page_Fetcher|null
	 */
	private static $instance = null;

	/**
	 * Maximum response size.
	 *
	 * @var int
	 */
	private $max_body_size = 5242880;

	/**
	 * Request timeout.
	 *
	 * @var int
	 */
	private $timeout = 20;

	/**
	 * Get singleton instance.
	 *
	 * @return TL_AI_VM_Page_Fetcher
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
	 * Fetch a URL.
	 *
	 * @param string $url  URL.
	 * @param array  $args Arguments.
	 *
	 * @return array
	 */
	public function fetch( $url, $args = array() ) {

		$url = esc_url_raw( $url );

		if ( empty( $url ) ) {

			return array(
				'success' => false,
				'error'   => 'Invalid URL.',
				'url'     => '',
			);
		}

		$defaults = array(
			'timeout'     => $this->timeout,
			'max_size'    => $this->max_body_size,
			'user_agent' => $this->get_user_agent(),
		);

		$args = wp_parse_args(
			$args,
			$defaults
		);

		$args['timeout'] = max(
			1,
			min(
				60,
				absint(
					$args['timeout']
				)
			)
		);

		$args['max_size'] = max(
			1024,
			min(
				20971520,
				absint(
					$args['max_size']
				)
			)
		);

		/**
		 * Block obviously invalid protocols.
		 */
		$scheme = wp_parse_url(
			$url,
			PHP_URL_SCHEME
		);

		if (
			! in_array(
				strtolower(
					(string) $scheme
				),
				array(
					'http',
					'https',
				),
				true
			)
		) {

			return array(
				'success' => false,
				'error'   => 'Only HTTP and HTTPS URLs are supported.',
				'url'     => $url,
			);
		}

		/**
		 * Allow the application to intercept fetching.
		 */
		$custom_response = apply_filters(
			'tl_ai_vm_page_fetch',
			null,
			$url,
			$args
		);

		if (
			is_array( $custom_response ) &&
			isset(
				$custom_response['success']
			)
		) {

			return $this->normalize_response(
				$custom_response,
				$url
			);
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'    => $args['timeout'],
				'redirection' => 5,
				'user-agent' => $args['user_agent'],
				'headers'    => array(
					'Accept' =>
						'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
					'Accept-Language' =>
						'fa,en;q=0.8',
				),
			)
		);

		if ( is_wp_error( $response ) ) {

			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
				'code'    => $response->get_error_code(),
				'url'     => $url,
			);
		}

		$status_code = wp_remote_retrieve_response_code(
			$response
		);

		$headers = wp_remote_retrieve_headers(
			$response
		);

		$body = wp_remote_retrieve_body(
			$response
		);

		if ( $status_code < 200 || $status_code >= 400 ) {

			return array(
				'success'     => false,
				'error'       => 'HTTP request failed.',
				'status_code' => $status_code,
				'url'         => $url,
			);
		}

		if ( empty( $body ) ) {

			return array(
				'success'     => false,
				'error'       => 'The page returned an empty response.',
				'status_code' => $status_code,
				'url'         => $url,
			);
		}

		if (
			strlen( $body ) >
			$args['max_size']
		) {

			$body = substr(
				$body,
				0,
				$args['max_size']
			);
		}

		$content_type = '';

		if (
			is_object( $headers ) &&
			method_exists(
				$headers,
				'get'
			)
		) {

			$content_type =
				(string) $headers->get(
					'content-type'
				);
		} elseif (
			is_array( $headers ) &&
			isset(
				$headers['content-type']
			)
		) {

			$content_type =
				(string) $headers['content-type'];
		}

		$title = $this->extract_title(
			$body
		);

		$text = $this->extract_text(
			$body
		);

		$meta = $this->extract_metadata(
			$body
		);

		return array(
			'success' => true,

			'url' => $url,

			'final_url' =>
				esc_url_raw(
					wp_remote_retrieve_header(
						$response,
						'location'
					)
				),

			'status_code' =>
				$status_code,

			'content_type' =>
				sanitize_text_field(
					$content_type
				),

			'title' => $title,

			'content' => $text,

			'content_length' =>
				strlen(
					$text
				),

			'metadata' => $meta,

			'raw_length' =>
				strlen(
					$body
				),
		);
	}

	/**
	 * Fetch multiple pages.
	 *
	 * @param array $urls  URLs.
	 * @param array $args  Arguments.
	 *
	 * @return array
	 */
	public function fetch_multiple(
		$urls,
		$args = array()
	) {

		if ( ! is_array( $urls ) ) { return array( 'success' => false, 'total' => 0, 'pages' => array() ); }
		$urls = array_values( array_unique( array_filter( array_map( 'esc_url_raw', $urls ) ) ) );
		if ( empty( $urls ) ) { return array( 'success' => false, 'total' => 0, 'pages' => array() ); }
		$args = wp_parse_args( $args, array( 'timeout' => $this->timeout, 'max_size' => $this->max_body_size ) );
		$pages = array();
		if ( function_exists( 'curl_multi_init' ) && count( $urls ) > 1 ) {
			$mh = curl_multi_init(); $handles = array();
			foreach ( $urls as $url ) {
				$ch = curl_init();
				curl_setopt_array( $ch, array( CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => max( 3, min( 20, absint( $args['timeout'] ) ) ), CURLOPT_SSL_VERIFYPEER => true, CURLOPT_USERAGENT => $this->get_user_agent(), CURLOPT_HTTPHEADER => array( 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', 'Accept-Language: fa,en;q=0.8' ) ) );
				curl_multi_add_handle( $mh, $ch ); $handles[] = array( 'handle' => $ch, 'url' => $url );
			}
			do { $status = curl_multi_exec( $mh, $running ); if ( $running ) { curl_multi_select( $mh, 0.2 ); } } while ( $running && $status === CURLM_OK );
			foreach ( $handles as $item ) {
				$ch = $item['handle']; $url = $item['url']; $html = curl_multi_getcontent( $ch ); $code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
				if ( $code >= 200 && $code < 400 && $html ) {
					if ( strlen( $html ) > absint( $args['max_size'] ) ) { $html = substr( $html, 0, absint( $args['max_size'] ) ); }
					$pages[] = $this->normalize_response( array( 'success' => true, 'url' => $url, 'status_code' => $code, 'content' => $this->clean_external_content( $html ), 'title' => $this->extract_title( $html ), 'metadata' => $this->extract_metadata( $html ), 'content_length' => strlen( $html ) ), $url );
				} else { $pages[] = array( 'success' => false, 'url' => $url, 'status_code' => $code, 'error' => 'HTTP request failed.' ); }
				curl_multi_remove_handle( $mh, $ch ); curl_close( $ch );
			}
			curl_multi_close( $mh );
		} else { foreach ( $urls as $url ) { $pages[] = $this->fetch( $url, $args ); } }
		$successful = 0; foreach ( $pages as $page ) { if ( ! empty( $page['success'] ) ) { $successful++; } }
		return array( 'success' => $successful > 0, 'total' => count( $pages ), 'successful' => $successful, 'failed' => count( $pages ) - $successful, 'pages' => $pages );
	}

	/**
	 * Extract page title.
	 *
	 * @param string $html HTML.
	 *
	 * @return string
	 */
	private function extract_title( $html ) {

		if ( empty( $html ) ) {
			return '';
		}

		if (
			preg_match(
				'/<title[^>]*>(.*?)<\/title>/is',
				$html,
				$matches
			)
		) {

			return sanitize_text_field(
				html_entity_decode(
					$matches[1],
					ENT_QUOTES | ENT_HTML5,
					'UTF-8'
				)
			);
		}

		return '';
	}

	/**
	 * Extract readable text.
	 *
	 * @param string $html HTML.
	 *
	 * @return string
	 */
	private function extract_text( $html ) {

		if ( empty( $html ) ) {
			return '';
		}

		/**
		 * Remove elements that usually contain
		 * navigation, scripts or styling rather
		 * than useful vehicle information.
		 */
		$remove_tags = array(
			'script',
			'style',
			'noscript',
			'iframe',
			'svg',
			'canvas',
			'form',
			'nav',
		);

		foreach ( $remove_tags as $tag ) {

			$html = preg_replace(
				'/<' . $tag . '\b[^>]*>.*?<\/' . $tag . '>/is',
				' ',
				$html
			);
		}

		/**
		 * Convert common structural elements
		 * into line breaks before stripping HTML.
		 */
		$html = preg_replace(
			'/<\/?(p|div|section|article|li|tr|h[1-6]|br|td|th)[^>]*>/i',
			"\n",
			$html
		);

		$text = wp_strip_all_tags(
			$html
		);

		$text = html_entity_decode(
			$text,
			ENT_QUOTES | ENT_HTML5,
			'UTF-8'
		);

		/**
		 * Normalize whitespace without destroying
		 * Persian or multilingual text.
		 */
		$text = preg_replace(
			"/[ \t]+/",
			' ',
			$text
		);

		$text = preg_replace(
			"/\n[ \t]+/",
			"\n",
			$text
		);

		$text = preg_replace(
			"/\n{3,}/",
			"\n\n",
			$text
		);

		return trim(
			$text
		);
	}

	/**
	 * Extract useful metadata.
	 *
	 * @param string $html HTML.
	 *
	 * @return array
	 */
	private function extract_metadata( $html ) {

		$metadata = array();

		if ( empty( $html ) ) {
			return $metadata;
		}

		/**
		 * Meta description.
		 */
		if (
			preg_match(
				'/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)["\']/i',
				$html,
				$matches
			)
		) {

			$metadata['description'] =
				sanitize_textarea_field(
					html_entity_decode(
						$matches[1],
						ENT_QUOTES | ENT_HTML5,
						'UTF-8'
					)
				);
		}

		/**
		 * Open Graph description.
		 */
		if (
			preg_match(
				'/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']*)["\']/i',
				$html,
				$matches
			)
		) {

			$metadata['og_description'] =
				sanitize_textarea_field(
					html_entity_decode(
						$matches[1],
						ENT_QUOTES | ENT_HTML5,
						'UTF-8'
					)
				);
		}

		/**
		 * Open Graph title.
		 */
		if (
			preg_match(
				'/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']*)["\']/i',
				$html,
				$matches
			)
		) {

			$metadata['og_title'] =
				sanitize_text_field(
					html_entity_decode(
						$matches[1],
						ENT_QUOTES | ENT_HTML5,
						'UTF-8'
					)
				);
		}

		/**
		 * Canonical URL.
		 */
		if (
			preg_match(
				'/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']*)["\']/i',
				$html,
				$matches
			)
		) {

			$metadata['canonical'] =
				esc_url_raw(
					html_entity_decode(
						$matches[1],
						ENT_QUOTES | ENT_HTML5,
						'UTF-8'
					)
				);
		}

		return $metadata;
	}

	/**
	 * Get user agent.
	 *
	 * @return string
	 */
	private function get_user_agent() {

		return 'Tuningland-AI-Vehicle-Manager/1.0; '
			. home_url( '/' );
	}

	/**
	 * Normalize custom fetch response.
	 *
	 * @param array  $response Response.
	 * @param string $url      URL.
	 *
	 * @return array
	 */
	private function normalize_response(
		$response,
		$url
	) {

		return array(
			'success' =>
				! empty(
					$response['success']
				),

			'url' =>
				isset(
					$response['url']
				)
					? esc_url_raw(
						$response['url']
					)
					: $url,

			'title' =>
				isset(
					$response['title']
				)
					? sanitize_text_field(
						$response['title']
					)
					: '',

			'content' =>
				isset(
					$response['content']
				)
					? $this->clean_external_content(
						$response['content']
					)
					: '',

			'metadata' =>
				isset(
					$response['metadata']
				) &&
				is_array(
					$response['metadata']
				)
					? $response['metadata']
					: array(),

			'status_code' =>
				isset(
					$response['status_code']
				)
					? absint(
						$response['status_code']
					)
					: 0,

			'error' =>
				isset(
					$response['error']
				)
					? sanitize_text_field(
						$response['error']
					)
					: '',
		);
	}

	/**
	 * Clean externally supplied content.
	 *
	 * @param string $content Content.
	 *
	 * @return string
	 */
	private function clean_external_content(
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
			"/\n{3,}/",
			"\n\n",
			$content
		);

		return trim(
			$content
		);
	}

	/**
	 * Check whether a URL is fetchable.
	 *
	 * @param string $url URL.
	 *
	 * @return bool
	 */
	public function is_fetchable( $url ) {

		$url = esc_url_raw(
			$url
		);

		if ( empty( $url ) ) {
			return false;
		}

		$scheme = wp_parse_url(
			$url,
			PHP_URL_SCHEME
		);

		return in_array(
			strtolower(
				(string) $scheme
			),
			array(
				'http',
				'https',
			),
			true
		);
	}

	/**
	 * Get fetcher configuration.
	 *
	 * @return array
	 */
	public function get_config() {

		return array(
			'timeout' =>
				$this->timeout,

			'max_body_size' =>
				$this->max_body_size,

			'transport' =>
				'wp_safe_remote_get',
		);
	}

}