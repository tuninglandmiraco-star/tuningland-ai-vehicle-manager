<?php
/**
 * Research Result Intermediate Layer
 *
 * Stores research results separately from ACF so later modules can
 * validate, score, approve and finally write data to ACF.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TL_AI_VM_Research_Result {

	private static $instance = null;

	const OPTION_NAME = 'tl_ai_vm_research_results';
	const MAX_RESULTS = 1000;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Create a result, replacing the latest result for the same vehicle/field.
	 * This prevents duplicate rows when a job is retried or resumed.
	 */
	public function create_or_update( $data = array() ) {
		$data = is_array( $data ) ? $data : array();
		$vehicle_id = ! empty( $data['vehicle']['post_id'] ) ? (int) $data['vehicle']['post_id'] : 0;
		$field_key = ! empty( $data['field']['key'] ) ? (string) $data['field']['key'] : '';
		$field_name = ! empty( $data['field']['name'] ) ? (string) $data['field']['name'] : '';
		if ( ! $vehicle_id || ( '' === $field_key && '' === $field_name ) ) {
			return $this->create( $data );
		}
		$results = $this->get_all( array( 'limit' => 0 ) );
		foreach ( $results as $existing ) {
			if ( empty( $existing['vehicle']['post_id'] ) || (int) $existing['vehicle']['post_id'] !== $vehicle_id ) { continue; }
			$same_key = $field_key && ! empty( $existing['field']['key'] ) && $existing['field']['key'] === $field_key;
			$same_name = $field_name && ! empty( $existing['field']['name'] ) && $existing['field']['name'] === $field_name;
			if ( $same_key || $same_name ) {
				$id = isset( $existing['id'] ) ? $existing['id'] : '';
				if ( $id && $this->update( $id, $data ) ) { return $id; }
			}
		}
		return $this->create( $data );
	}

	/**
	 * Create a research result.
	 *
	 * @param array $data Result data.
	 * @return string|false Result ID or false.
	 */
	public function create( $data = array() ) {
		$data = is_array( $data ) ? $data : array();
		$result = $this->normalize( $data );

		$results = $this->get_all();
		array_unshift( $results, $result );

		if ( count( $results ) > self::MAX_RESULTS ) {
			$results = array_slice( $results, 0, self::MAX_RESULTS );
		}

		$saved = update_option( self::OPTION_NAME, $results, false );

		if ( $saved || $this->exists( $result['id'] ) ) {
			$this->log(
				'Research result created.',
				array(
					'result_id' => $result['id'],
					'post_id'   => $result['vehicle']['post_id'],
					'field_key' => $result['field']['key'],
				)
			);
			return $result['id'];
		}

		return false;
	}

	/**
	 * Update an existing result.
	 */
	public function update( $id, $data = array() ) {
		$id = sanitize_text_field( $id );

		if ( empty( $id ) || ! is_array( $data ) ) {
			return false;
		}

		$results = $this->get_all();

		foreach ( $results as $index => $existing ) {
			if ( isset( $existing['id'] ) && $existing['id'] === $id ) {
				$merged = array_replace_recursive( $existing, $data );
				$merged['id'] = $id;
				$merged['updated_at'] = current_time( 'c', true );
				$results[ $index ] = $this->normalize( $merged );

				return update_option(
					self::OPTION_NAME,
					$results,
					false
				);
			}
		}

		return false;
	}

	public function get( $id ) {
		$id = sanitize_text_field( $id );

		if ( empty( $id ) ) {
			return null;
		}

		foreach ( $this->get_all() as $result ) {
			if ( isset( $result['id'] ) && $result['id'] === $id ) {
				return $result;
			}
		}

		return null;
	}

	public function exists( $id ) {
		return null !== $this->get( $id );
	}

	public function delete( $id ) {
		$id = sanitize_text_field( $id );

		if ( empty( $id ) ) {
			return false;
		}

		$results = $this->get_all();
		$new_results = array();

		foreach ( $results as $result ) {
			if ( ! isset( $result['id'] ) || $result['id'] !== $id ) {
				$new_results[] = $result;
			}
		}

		if ( count( $new_results ) === count( $results ) ) {
			return false;
		}

		return update_option(
			self::OPTION_NAME,
			$new_results,
			false
		);
	}

	public function get_all( $args = array() ) {
		$results = get_option(
			self::OPTION_NAME,
			array()
		);

		if ( ! is_array( $results ) ) {
			$results = array();
		}

		$args = wp_parse_args(
			$args,
			array(
				'status'    => '',
				'post_id'   => 0,
				'field_key' => '',
				'limit'     => 100,
			)
		);

		$output = array();

		foreach ( $results as $result ) {
			if (
				! empty( $args['status'] ) &&
				(
					! isset( $result['status'] ) ||
					$result['status'] !== $args['status']
				)
			) {
				continue;
			}

			if (
				! empty( $args['post_id'] ) &&
				(
					! isset( $result['vehicle']['post_id'] ) ||
					(int) $result['vehicle']['post_id'] !== (int) $args['post_id']
				)
			) {
				continue;
			}

			if (
				! empty( $args['field_key'] ) &&
				(
					! isset( $result['field']['key'] ) ||
					$result['field']['key'] !== $args['field_key']
				)
			) {
				continue;
			}

			$output[] = $result;

			if (
				(int) $args['limit'] > 0 &&
				count( $output ) >= (int) $args['limit']
			) {
				break;
			}
		}

		return $output;
	}

	public function clear() {
		return delete_option( self::OPTION_NAME );
	}

	public function get_stats() {
		$results = $this->get_all(
			array(
				'limit' => 0,
			)
		);

		$stats = array(
			'total'      => count( $results ),
			'pending'    => 0,
			'researched' => 0,
			'validated'  => 0,
			'approved'   => 0,
			'rejected'   => 0,
			'failed'     => 0,
		);

		foreach ( $results as $result ) {
			if (
				isset( $result['status'] ) &&
				isset( $stats[ $result['status'] ] )
			) {
				$stats[ $result['status'] ]++;
			}
		}

		return $stats;
	}

	private function normalize( $data ) {
		$now = current_time( 'c', true );

		return array(
			'id' => ! empty( $data['id'] )
				? sanitize_text_field( $data['id'] )
				: wp_generate_uuid4(),

			'version' => '1.0.0',

			'created_at' => ! empty( $data['created_at'] )
				? sanitize_text_field( $data['created_at'] )
				: $now,

			'updated_at' => ! empty( $data['updated_at'] )
				? sanitize_text_field( $data['updated_at'] )
				: $now,

			'status' => $this->sanitize_status(
				isset( $data['status'] )
					? $data['status']
					: 'pending'
			),

			'vehicle' => array(
				'post_id' => isset( $data['vehicle']['post_id'] )
					? (int) $data['vehicle']['post_id']
					: 0,

				'post_type' => isset( $data['vehicle']['post_type'] )
					? sanitize_key( $data['vehicle']['post_type'] )
					: '',

				'title' => isset( $data['vehicle']['title'] )
					? sanitize_text_field( $data['vehicle']['title'] )
					: '',
			),

			'field' => array(
				'key' => isset( $data['field']['key'] )
					? sanitize_text_field( $data['field']['key'] )
					: '',

				'name' => isset( $data['field']['name'] )
					? sanitize_key( $data['field']['name'] )
					: '',

				'label' => isset( $data['field']['label'] )
					? sanitize_text_field( $data['field']['label'] )
					: '',

				'type' => isset( $data['field']['type'] )
					? sanitize_key( $data['field']['type'] )
					: '',

				'parent' => isset( $data['field']['parent'] )
					? sanitize_text_field( $data['field']['parent'] )
					: '',
			),

			'query' => isset( $data['query'] )
				? sanitize_text_field( $data['query'] )
				: '',

			'raw_answer' => isset( $data['raw_answer'] )
				? $this->sanitize_value( $data['raw_answer'] )
				: '',

			'normalized_value' => isset( $data['normalized_value'] )
				? $this->sanitize_value( $data['normalized_value'] )
				: null,

			'expected_data_type' => isset( $data['expected_data_type'] )
				? sanitize_text_field( $data['expected_data_type'] )
				: '',

			'unit' => isset( $data['unit'] )
				? sanitize_text_field( $data['unit'] )
				: '',

			'sources' => isset( $data['sources'] ) &&
				is_array( $data['sources'] )
				? $this->sanitize_sources( $data['sources'] )
				: array(),

			'validation' => array(
				'status' => isset( $data['validation']['status'] )
					? $this->sanitize_status(
						$data['validation']['status']
					)
					: 'pending',

				'issues' => isset( $data['validation']['issues'] )
					? $this->sanitize_value(
						$data['validation']['issues']
					)
					: array(),
			),

			'confidence' => isset( $data['confidence'] )
				? $this->sanitize_confidence(
					$data['confidence']
				)
				: null,

			'decision' => isset( $data['decision'] )
				? $this->sanitize_decision(
					$data['decision']
				)
				: 'pending',

			'approval' => array(
				'status' => isset( $data['approval']['status'] )
					? $this->sanitize_approval(
						$data['approval']['status']
					)
					: 'pending',

				'user_id' => isset( $data['approval']['user_id'] )
					? (int) $data['approval']['user_id']
					: 0,

				'approved_at' => isset(
					$data['approval']['approved_at']
				)
					? sanitize_text_field(
						$data['approval']['approved_at']
					)
					: null,
			),

			'metadata' => isset( $data['metadata'] )
				? $this->sanitize_value(
					$data['metadata']
				)
				: array(),
		);
	}

	private function sanitize_status( $status ) {
		$allowed = array(
			'pending',
			'researched',
			'validated',
			'approved',
			'rejected',
			'failed',
		);

		$status = sanitize_key( $status );

		return in_array(
			$status,
			$allowed,
			true
		) ? $status : 'pending';
	}

	private function sanitize_decision( $decision ) {
		$allowed = array(
			'pending',
			'auto',
			'review',
			'ignore',
		);

		$decision = sanitize_key( $decision );

		return in_array(
			$decision,
			$allowed,
			true
		) ? $decision : 'pending';
	}

	private function sanitize_approval( $status ) {
		$allowed = array(
			'pending',
			'approved',
			'rejected',
		);

		$status = sanitize_key( $status );

		return in_array(
			$status,
			$allowed,
			true
		) ? $status : 'pending';
	}

	private function sanitize_confidence( $value ) {
		if ( is_array( $value ) ) {
			return $this->sanitize_value( $value );
		}

		if ( is_numeric( $value ) ) {
			$value = (float) $value;
			return max(
				0,
				min( 1, $value )
			);
		}

		return null;
	}

	private function sanitize_sources( $sources ) {
		$output = array();

		foreach ( $sources as $source ) {
			if ( ! is_array( $source ) ) {
				continue;
			}

			$output[] = array(
				'url' => isset( $source['url'] )
					? esc_url_raw( $source['url'] )
					: '',

				'title' => isset( $source['title'] )
					? sanitize_text_field( $source['title'] )
					: '',

				'domain' => isset( $source['domain'] )
					? sanitize_text_field( $source['domain'] )
					: '',

				'snippet' => isset( $source['snippet'] )
					? sanitize_textarea_field( $source['snippet'] )
					: '',

				'data' => isset( $source['data'] )
					? $this->sanitize_value( $source['data'] )
					: array(),
			);
		}

		return $output;
	}

	private function sanitize_value( $value ) {
		if ( is_array( $value ) ) {
			$output = array();

			foreach ( $value as $key => $item ) {
				$output[
					sanitize_key( (string) $key )
				] = $this->sanitize_value( $item );
			}

			return $output;
		}

		if ( is_object( $value ) ) {
			return $this->sanitize_value(
				(array) $value
			);
		}

		if (
			is_bool( $value ) ||
			is_null( $value ) ||
			is_numeric( $value )
		) {
			return $value;
		}

		return sanitize_textarea_field(
			(string) $value
		);
	}

	private function log( $message, $context = array() ) {
		if ( class_exists( 'TL_AI_VM_Logger' ) ) {
			TL_AI_VM_Logger::instance()->info(
				$message,
				'research_result',
				$context
			);
		}
	}
}
