<?php
/**
 * Multi-Source Validation
 *
 * Validates a stored Research Result without writing to ACF.
 * This stage compares sources and the normalized result structure.
 *
 * It is intentionally independent from the final confidence/decision layers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TL_AI_VM_Research_Validator {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Validate one Research Result.
	 *
	 * @param string|array $result Research Result ID or result array.
	 * @param bool          $persist Whether to save validation back to storage.
	 * @return array
	 */
	public function validate( $result, $persist = true ) {

		$storage = TL_AI_VM_Research_Result::instance();

		if ( is_string( $result ) ) {
			$result = $storage->get( $result );
		}

		if ( ! is_array( $result ) || empty( $result['id'] ) ) {
			return array(
				'success' => false,
				'error'   => 'Invalid Research Result.',
			);
		}

		$issues = array();
		$warnings = array();
		$checks = array();

		$this->check_identity( $result, $issues, $checks );
		$this->check_answer( $result, $issues, $checks );
		$this->check_sources( $result, $issues, $warnings, $checks );
		$this->check_normalized_value( $result, $issues, $warnings, $checks );

		$source_count = ! empty( $result['sources'] ) &&
			is_array( $result['sources'] )
			? count( $result['sources'] )
			: 0;

		$unique_domains = $this->get_unique_domains(
			$result['sources']
		);

		$conflicting_sources = $this->detect_conflicts(
			$result
		);

		if ( ! empty( $conflicting_sources ) ) {
			$warnings[] = array(
				'code'    => 'source_conflict',
				'message' => 'Sources may contain conflicting information.',
				'details' => $conflicting_sources,
			);
		}

		$status = empty( $issues )
			? 'validated'
			: 'failed';

		$validation = array(
			'status' => $status,
			'validated_at' => current_time( 'c', true ),
			'source_count' => $source_count,
			'unique_domain_count' => count( $unique_domains ),
			'checks' => $checks,
			'issues' => $issues,
			'warnings' => $warnings,
			'conflicts' => $conflicting_sources,
		);

		if ( $persist ) {
			$storage->update(
				$result['id'],
				array(
					'status' => $status,
					'validation' => $validation,
				)
			);
		}

		if ( class_exists( 'TL_AI_VM_Logger' ) ) {
			TL_AI_VM_Logger::instance()->info(
				'Research Result validation completed.',
				'validation',
				array(
					'result_id' => $result['id'],
					'status' => $status,
					'source_count' => $source_count,
					'unique_domain_count' => count( $unique_domains ),
					'issue_count' => count( $issues ),
					'warning_count' => count( $warnings ),
				)
			);
		}

		return array(
			'success' => true,
			'status' => $status,
			'result_id' => $result['id'],
			'source_count' => $source_count,
			'unique_domain_count' => count( $unique_domains ),
			'checks' => $checks,
			'issues' => $issues,
			'warnings' => $warnings,
			'conflicts' => $conflicting_sources,
		);
	}

	/**
	 * Validate multiple results.
	 *
	 * @param array $results Result IDs or result arrays.
	 * @param bool  $persist Persist results.
	 * @return array
	 */
	public function validate_many( $results, $persist = true ) {

		$output = array();

		if ( ! is_array( $results ) ) {
			return $output;
		}

		foreach ( $results as $result ) {
			$output[] = $this->validate(
				$result,
				$persist
			);
		}

		return $output;
	}

	private function check_identity(
		$result,
		&$issues,
		&$checks
	) {

		$vehicle_ok =
			! empty( $result['vehicle']['post_id'] ) &&
			! empty( $result['vehicle']['post_type'] );

		$field_ok =
			! empty( $result['field']['key'] ) ||
			! empty( $result['field']['name'] );

		$checks['vehicle_identity'] = $vehicle_ok;
		$checks['field_identity'] = $field_ok;

		if ( ! $vehicle_ok ) {
			$issues[] = array(
				'code' => 'missing_vehicle_identity',
				'message' => 'Vehicle identity is incomplete.',
			);
		}

		if ( ! $field_ok ) {
			$issues[] = array(
				'code' => 'missing_field_identity',
				'message' => 'ACF field identity is incomplete.',
			);
		}
	}

	private function check_answer(
		$result,
		&$issues,
		&$checks
	) {

		$has_answer =
			isset( $result['raw_answer'] ) &&
			'' !== trim(
				(string) $result['raw_answer']
			);

		$checks['answer_present'] = $has_answer;

		if ( ! $has_answer ) {
			$issues[] = array(
				'code' => 'missing_answer',
				'message' => 'No research answer was stored.',
			);
		}
	}

	private function check_sources(
		$result,
		&$issues,
		&$warnings,
		&$checks
	) {

		$sources = isset( $result['sources'] ) &&
			is_array( $result['sources'] )
			? $result['sources']
			: array();

		$valid_sources = 0;
		$invalid_sources = 0;

		foreach ( $sources as $source ) {

			if (
				! is_array( $source ) ||
				empty( $source['url'] ) ||
				! filter_var(
					$source['url'],
					FILTER_VALIDATE_URL
				)
			) {
				$invalid_sources++;
				continue;
			}

			$valid_sources++;
		}

		$checks['sources_present'] = ! empty( $sources );
		$checks['valid_sources'] = $valid_sources;
		$checks['invalid_sources'] = $invalid_sources;

		if ( 0 === $valid_sources ) {
			$issues[] = array(
				'code' => 'no_valid_sources',
				'message' => 'No valid research source was found.',
			);
		}

		if ( $invalid_sources > 0 ) {
			$warnings[] = array(
				'code' => 'invalid_sources',
				'message' => 'One or more stored sources are invalid.',
				'count' => $invalid_sources,
			);
		}
	}

	private function check_normalized_value(
		$result,
		&$issues,
		&$warnings,
		&$checks
	) {

		$has_normalized =
			array_key_exists(
				'normalized_value',
				$result
			) &&
			null !== $result['normalized_value'] &&
			'' !== $result['normalized_value'];

		$checks['normalized_value_present'] = $has_normalized;

		if ( ! $has_normalized ) {
			$warnings[] = array(
				'code' => 'missing_normalized_value',
				'message' => 'A normalized value has not been produced yet.',
			);
		}
	}

	private function get_unique_domains( $sources ) {

		$domains = array();

		if ( ! is_array( $sources ) ) {
			return $domains;
		}

		foreach ( $sources as $source ) {

			if (
				! is_array( $source ) ||
				empty( $source['url'] )
			) {
				continue;
			}

			$domain = wp_parse_url(
				$source['url'],
				PHP_URL_HOST
			);

			if ( ! empty( $domain ) ) {
				$domain = strtolower(
					preg_replace(
						'/^www\./',
						'',
						$domain
					)
				);

				$domains[ $domain ] = true;
			}
		}

		return array_keys( $domains );
	}

	private function detect_conflicts( $result ) {

		$sources = isset( $result['sources'] ) &&
			is_array( $result['sources'] )
			? $result['sources']
			: array();

		$values = array();

		foreach ( $sources as $source ) {

			if (
				! is_array( $source ) ||
				! isset( $source['data'] ) ||
				! is_array( $source['data'] )
			) {
				continue;
			}

			if (
				isset( $source['data']['value'] ) &&
				'' !== $source['data']['value']
			) {
				$values[] = $source['data']['value'];
			}
		}

		$values = array_map(
			'strval',
			$values
		);

		$unique = array_values(
			array_unique( $values )
		);

		if ( count( $unique ) <= 1 ) {
			return array();
		}

		return array(
			'values' => $unique,
			'source_values' => $values,
		);
	}
}
