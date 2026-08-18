<?php
/**
 * Auto / Review / Ignore Decision Layer
 *
 * Converts validation + confidence evidence into a processing decision.
 *
 * This layer does NOT write to ACF.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TL_AI_VM_Decision {

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
	 * Decide how a research result should be handled.
	 *
	 * @param array $research_result Research result.
	 * @return array
	 */
	public function decide( $research_result ) {

		if ( ! is_array( $research_result ) ) {
			return array(
				'success' => false,
				'error'   => 'Invalid research result.',
			);
		}

		$confidence = isset( $research_result['confidence'] )
			? $research_result['confidence']
			: array();

		if ( empty( $confidence ) && class_exists( 'TL_AI_VM_Confidence' ) ) {
			$confidence_engine = TL_AI_VM_Confidence::instance();

			if ( method_exists( $confidence_engine, 'calculate' ) ) {
				$validation = isset( $research_result['validation'] )
					? $research_result['validation']
					: array();

				$confidence = $confidence_engine->calculate(
					$research_result,
					$validation
				);
			}
		}

		$percentage = $this->get_confidence_percentage( $confidence );
		$validation = isset( $research_result['validation'] )
			? $research_result['validation']
			: array();

		$has_conflict = $this->has_conflict( $validation );
		$has_answer   = ! empty( $research_result['answer'] );
		$has_sources  = $this->has_sources( $research_result );

		/**
		 * Conservative rules:
		 *
		 * AUTO:
		 * - confidence >= 90
		 * - answer exists
		 * - source evidence exists
		 * - no conflict
		 *
		 * REVIEW:
		 * - confidence >= 60
		 * - or useful evidence exists but Auto requirements are not met
		 *
		 * IGNORE:
		 * - no answer/evidence
		 * - very low confidence
		 * - explicit unrecoverable conflict
		 */
		if (
			$percentage >= 90 &&
			$has_answer &&
			$has_sources &&
			! $has_conflict
		) {
			$decision = 'auto';
			$reason   = 'High confidence with sufficient evidence and no detected conflict.';
		} elseif (
			$percentage >= 60 ||
			( $has_answer && $has_sources && ! $has_conflict )
		) {
			$decision = 'review';
			$reason   = 'Evidence is potentially usable but requires review before writing.';
		} else {
			$decision = 'ignore';
			$reason   = $has_conflict
				? 'Conflicting evidence prevents automatic processing.'
				: 'Insufficient confidence or evidence.';
		}

		$result = array(
			'success' => true,
			'version' => self::VERSION,
			'decision' => $decision,
			'reason' => $reason,
			'confidence_percentage' => $percentage,
			'checks' => array(
				'has_answer' => $has_answer,
				'has_sources' => $has_sources,
				'has_conflict' => $has_conflict,
			),
			'generated_at' => current_time( 'c', true ),
		);

		$this->log(
			'Research decision calculated.',
			array(
				'decision' => $decision,
				'confidence' => $percentage,
			)
		);

		return $result;
	}

	/**
	 * Attach decision to a research result.
	 *
	 * @param array $research_result Research result.
	 * @return array
	 */
	public function attach( $research_result ) {

		if ( ! is_array( $research_result ) ) {
			return array(
				'success' => false,
				'error'   => 'Invalid research result.',
			);
		}

		$decision = $this->decide( $research_result );

		if ( empty( $decision['success'] ) ) {
			return $decision;
		}

		$research_result['decision'] = $decision;

		return $research_result;
	}

	/**
	 * Return confidence as percentage.
	 *
	 * @param array $confidence Confidence data.
	 * @return int
	 */
	private function get_confidence_percentage( $confidence ) {

		if ( ! is_array( $confidence ) ) {
			return 0;
		}

		if (
			isset( $confidence['percentage'] ) &&
			is_numeric( $confidence['percentage'] )
		) {
			return max(
				0,
				min( 100, (int) round( $confidence['percentage'] ) )
			);
		}

		if (
			isset( $confidence['score'] ) &&
			is_numeric( $confidence['score'] )
		) {
			$score = (float) $confidence['score'];

			if ( $score <= 1 ) {
				$score *= 100;
			}

			return max(
				0,
				min( 100, (int) round( $score ) )
			);
		}

		return 0;
	}

	/**
	 * Detect evidence conflicts.
	 *
	 * Supports the structures currently used by the pipeline
	 * without requiring a hard-coded validator response shape.
	 *
	 * @param array $validation Validation result.
	 * @return bool
	 */
	private function has_conflict( $validation ) {

		if ( ! is_array( $validation ) ) {
			return false;
		}

		if ( ! empty( $validation['conflict'] ) ) {
			return true;
		}

		if (
			isset( $validation['conflicts'] ) &&
			! empty( $validation['conflicts'] )
		) {
			return true;
		}

		if (
			isset( $validation['consistent'] ) &&
			false === $validation['consistent']
		) {
			return true;
		}

		if (
			isset( $validation['consistency'] ) &&
			is_numeric( $validation['consistency'] ) &&
			(float) $validation['consistency'] < 0.25
		) {
			return true;
		}

		return false;
	}

	/**
	 * Check whether usable source evidence exists.
	 *
	 * @param array $research_result Research result.
	 * @return bool
	 */
	private function has_sources( $research_result ) {

		$sources = isset( $research_result['sources'] )
			? $research_result['sources']
			: array();

		if ( ! is_array( $sources ) ) {
			return false;
		}

		foreach ( $sources as $source ) {

			if ( is_string( $source ) && '' !== trim( $source ) ) {
				return true;
			}

			if ( is_array( $source ) ) {

				$url = '';

				if ( isset( $source['url'] ) ) {
					$url = $source['url'];
				} elseif ( isset( $source['source_url'] ) ) {
					$url = $source['source_url'];
				}

				if ( ! empty( $url ) ) {
					return true;
				}
			}
		}

		return false;
	}

	private function log( $message, $context = array() ) {

		if ( class_exists( 'TL_AI_VM_Logger' ) ) {

			$logger = TL_AI_VM_Logger::instance();

			if (
				is_object( $logger ) &&
				method_exists( $logger, 'debug' )
			) {
				$logger->debug(
					$message,
					'decision',
					$context
				);
			}
		}
	}
}
