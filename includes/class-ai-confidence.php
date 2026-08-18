<?php
/**
 * AI Confidence System
 *
 * Calculates a normalized confidence score for validated research results.
 *
 * This layer does NOT:
 * - write to ACF
 * - approve research
 * - decide Auto/Review/Ignore
 *
 * It only evaluates evidence and stores a confidence assessment.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TL_AI_VM_Confidence {

	private static $instance = null;

	const VERSION = '1.0.0';

	public static function instance() {

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
	}

	/**
	 * Calculate confidence for a research result.
	 *
	 * @param array $research_result Research result.
	 * @param array $validation      Validation result.
	 * @return array
	 */
	public function calculate( $research_result, $validation = array() ) {

		if ( ! is_array( $research_result ) ) {
			return array(
				'success' => false,
				'error'   => 'Invalid research result.',
			);
		}

		if ( ! is_array( $validation ) || empty( $validation ) ) {
			if ( class_exists( 'TL_AI_VM_Research_Validator' ) ) {
				$validator = TL_AI_VM_Research_Validator::instance();

				if ( method_exists( $validator, 'validate' ) ) {
					$validation = $validator->validate( $research_result );
				}
			}
		}

		if ( ! is_array( $validation ) ) {
			$validation = array();
		}

		$signals = array();

		// Base evidence.
		$signals['answer'] = ! empty( $research_result['answer'] ) ? 1.0 : 0.0;

		$sources = $this->extract_sources( $research_result, $validation );

		$source_count = count( $sources );
		$domain_count = $this->count_unique_domains( $sources );

		$signals['source_count'] = $this->score_source_count( $source_count );
		$signals['domain_diversity'] = $this->score_domain_diversity( $domain_count );
		$signals['validation'] = $this->score_validation( $validation );
		$signals['consistency'] = $this->score_consistency( $validation );
		$signals['normalized_value'] =
			! empty( $research_result['normalized_value'] ) ? 1.0 : 0.0;

		/**
		 * Weighted confidence.
		 *
		 * Evidence quality is intentionally conservative.
		 */
		$weights = array(
			'answer'            => 0.15,
			'source_count'      => 0.20,
			'domain_diversity'  => 0.15,
			'validation'        => 0.25,
			'consistency'       => 0.15,
			'normalized_value'  => 0.10,
		);

		$score = 0.0;

		foreach ( $weights as $key => $weight ) {
			$value = isset( $signals[ $key ] ) ? (float) $signals[ $key ] : 0.0;
			$score += $value * $weight;
		}

		$score = max( 0.0, min( 1.0, $score ) );
		$percentage = (int) round( $score * 100 );

		$level = $this->get_level( $percentage );

		$result = array(
			'success' => true,
			'version' => self::VERSION,
			'score'   => round( $score, 4 ),
			'percentage' => $percentage,
			'level'   => $level,
			'signals' => array(
				'answer'           => round( $signals['answer'], 4 ),
				'source_count'     => round( $signals['source_count'], 4 ),
				'domain_diversity' => round( $signals['domain_diversity'], 4 ),
				'validation'       => round( $signals['validation'], 4 ),
				'consistency'      => round( $signals['consistency'], 4 ),
				'normalized_value' => round( $signals['normalized_value'], 4 ),
			),
			'evidence' => array(
				'source_count' => $source_count,
				'unique_domains' => $domain_count,
			),
			'generated_at' => current_time( 'c', true ),
		);

		$this->log(
			'Confidence calculated.',
			array(
				'percentage' => $percentage,
				'level'      => $level,
				'sources'    => $source_count,
				'domains'    => $domain_count,
			)
		);

		return $result;
	}

	/**
	 * Attach confidence data to a research result.
	 *
	 * @param array $research_result Research result.
	 * @param array $validation Validation result.
	 * @return array
	 */
	public function attach( $research_result, $validation = array() ) {

		if ( ! is_array( $research_result ) ) {
			return array(
				'success' => false,
				'error'   => 'Invalid research result.',
			);
		}

		$confidence = $this->calculate(
			$research_result,
			$validation
		);

		if ( empty( $confidence['success'] ) ) {
			return $confidence;
		}

		$research_result['confidence'] = $confidence;

		return $research_result;
	}

	private function extract_sources( $research_result, $validation ) {

		$sources = array();

		$candidates = array();

		if ( isset( $research_result['sources'] ) ) {
			$candidates = $research_result['sources'];
		} elseif ( isset( $validation['sources'] ) ) {
			$candidates = $validation['sources'];
		}

		if ( ! is_array( $candidates ) ) {
			return array();
		}

		foreach ( $candidates as $source ) {

			if ( is_string( $source ) && '' !== trim( $source ) ) {
				$sources[] = array(
					'url' => trim( $source ),
				);
				continue;
			}

			if ( is_array( $source ) ) {
				$sources[] = $source;
			}
		}

		return $sources;
	}

	private function count_unique_domains( $sources ) {

		$domains = array();

		foreach ( $sources as $source ) {

			$url = '';

			if ( isset( $source['url'] ) ) {
				$url = $source['url'];
			} elseif ( isset( $source['source_url'] ) ) {
				$url = $source['source_url'];
			}

			if ( empty( $url ) ) {
				continue;
			}

			$host = wp_parse_url( $url, PHP_URL_HOST );

			if ( empty( $host ) ) {
				continue;
			}

			$host = strtolower( preg_replace( '/^www\./', '', $host ) );

			$domains[ $host ] = true;
		}

		return count( $domains );
	}

	private function score_source_count( $count ) {

		if ( $count <= 0 ) {
			return 0.0;
		}

		if ( 1 === $count ) {
			return 0.45;
		}

		if ( 2 === $count ) {
			return 0.70;
		}

		if ( 3 === $count ) {
			return 0.85;
		}

		return 1.0;
	}

	private function score_domain_diversity( $count ) {

		if ( $count <= 0 ) {
			return 0.0;
		}

		if ( 1 === $count ) {
			return 0.45;
		}

		if ( 2 === $count ) {
			return 0.75;
		}

		return 1.0;
	}

	private function score_validation( $validation ) {

		if ( empty( $validation ) ) {
			return 0.0;
		}

		if ( isset( $validation['score'] ) && is_numeric( $validation['score'] ) ) {
			$value = (float) $validation['score'];

			if ( $value > 1 ) {
				$value = $value / 100;
			}

			return max( 0.0, min( 1.0, $value ) );
		}

		if (
			isset( $validation['percentage'] ) &&
			is_numeric( $validation['percentage'] )
		) {
			return max(
				0.0,
				min( 1.0, (float) $validation['percentage'] / 100 )
			);
		}

		if ( ! empty( $validation['valid'] ) || ! empty( $validation['success'] ) ) {
			return 0.75;
		}

		return 0.25;
	}

	private function score_consistency( $validation ) {

		if ( isset( $validation['conflict'] ) && $validation['conflict'] ) {
			return 0.10;
		}

		if ( isset( $validation['conflicts'] ) && ! empty( $validation['conflicts'] ) ) {
			return 0.10;
		}

		if ( isset( $validation['consistent'] ) ) {
			return $validation['consistent'] ? 1.0 : 0.15;
		}

		if ( isset( $validation['consistency'] ) && is_numeric( $validation['consistency'] ) ) {
			$value = (float) $validation['consistency'];

			if ( $value > 1 ) {
				$value = $value / 100;
			}

			return max( 0.0, min( 1.0, $value ) );
		}

		return 0.50;
	}

	private function get_level( $percentage ) {

		if ( $percentage >= 85 ) {
			return 'high';
		}

		if ( $percentage >= 60 ) {
			return 'medium';
		}

		return 'low';
	}

	private function log( $message, $context = array() ) {

		if ( class_exists( 'TL_AI_VM_Logger' ) ) {
			$logger = TL_AI_VM_Logger::instance();

			if ( is_object( $logger ) && method_exists( $logger, 'debug' ) ) {
				$logger->debug(
					$message,
					'confidence',
					$context
				);
			}
		}
	}
}
