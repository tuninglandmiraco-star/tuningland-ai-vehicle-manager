<?php
/**
 * Automatic ACF Writer
 *
 * Writes only approved vehicle research data into ACF.
 * This class is intentionally conservative:
 * - ACF must be available.
 * - The target post must exist and belong to the selected Vehicle CPT.
 * - The field must exist in the discovered schema.
 * - Data must be explicitly approved.
 * - Dry-run is supported.
 *
 * No automatic write is performed merely by discovering or researching data.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TL_AI_VM_ACF_Writer {

	private static $instance = null;

	public static function instance() {

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
	}

	/**
	 * Write one approved field value.
	 *
	 * Expected $item:
	 * [
	 *   'post_id'        => 123,
	 *   'post_type'      => 'vehicle',
	 *   'field_key'      => 'field_xxxxx',
	 *   'field_name'     => 'engine_power',
	 *   'value'          => '150',
	 *   'approved'       => true,
	 *   'approval_status'=> 'approved',
	 * ]
	 *
	 * @param array $item
	 * @param bool  $dry_run
	 * @return array
	 */
	public function write( $item, $dry_run = false ) {

		$validation = $this->validate_item( $item );

		if ( empty( $validation['success'] ) ) {
			return $validation;
		}

		$post_id   = $validation['post_id'];
		$field_key = $validation['field_key'];
		$value     = $item['value'];

		if ( $dry_run ) {
			$this->log(
				'ACF writer dry-run: write skipped.',
				'writer',
				array(
					'post_id'   => $post_id,
					'field_key' => $field_key,
				)
			);

			return array(
				'success'   => true,
				'dry_run'   => true,
				'updated'   => false,
				'post_id'   => $post_id,
				'field_key' => $field_key,
				'value'     => $value,
			);
		}

		$result = update_field(
			$field_key,
			$value,
			$post_id
		);

		/*
		 * ACF update_field() can return false when the value is unchanged.
		 * Therefore false is not automatically treated as a failure.
		 * We verify the stored value afterwards.
		 */
		$stored = get_field(
			$field_key,
			$post_id,
			false
		);

		if ( ! $this->values_match( $stored, $value ) ) {

			$this->log(
				'ACF writer failed verification after update.',
				'writer',
				array(
					'post_id'   => $post_id,
					'field_key' => $field_key,
				)
			);

			return array(
				'success' => false,
				'updated' => false,
				'error'   => 'ACF value could not be verified after writing.',
			);
		}

		$this->log(
			'Approved vehicle field written to ACF.',
			'writer',
			array(
				'post_id'   => $post_id,
				'field_key' => $field_key,
			)
		);

		return array(
			'success'   => true,
			'updated'   => (bool) $result,
			'post_id'   => $post_id,
			'field_key' => $field_key,
			'value'     => $stored,
		);
	}

	/**
	 * Write multiple approved items.
	 *
	 * @param array $items
	 * @param bool  $dry_run
	 * @return array
	 */
	public function write_batch( $items, $dry_run = false ) {

		if ( ! is_array( $items ) ) {
			return array(
				'success' => false,
				'error'   => 'Writer batch must be an array.',
			);
		}

		$results = array();
		$success = 0;
		$failed  = 0;

		foreach ( $items as $index => $item ) {

			$result = $this->write(
				$item,
				$dry_run
			);

			$results[ $index ] = $result;

			if ( ! empty( $result['success'] ) ) {
				$success++;
			} else {
				$failed++;
			}
		}

		return array(
			'success' => ( 0 === $failed ),
			'total'   => count( $items ),
			'written' => $success,
			'failed'  => $failed,
			'results' => $results,
			'dry_run' => (bool) $dry_run,
		);
	}

	/**
	 * Validate before any ACF write.
	 *
	 * @param array $item
	 * @return array
	 */
	private function validate_item( $item ) {

		if ( ! function_exists( 'update_field' ) || ! function_exists( 'get_field' ) ) {
			return array(
				'success' => false,
				'error'   => 'ACF is not available.',
			);
		}

		if ( ! is_array( $item ) ) {
			return array(
				'success' => false,
				'error'   => 'Writer item must be an array.',
			);
		}

		$post_id = isset( $item['post_id'] )
			? absint( $item['post_id'] )
			: 0;

		if ( ! $post_id || ! get_post( $post_id ) ) {
			return array(
				'success' => false,
				'error'   => 'Invalid vehicle post ID.',
			);
		}

		$selected_cpt = TL_AI_VM_Vehicle_CPT::instance()->get_selected_vehicle_cpt();

		if (
			! empty( $selected_cpt ) &&
			get_post_type( $post_id ) !== $selected_cpt
		) {
			return array(
				'success' => false,
				'error'   => 'The target post does not belong to the selected Vehicle CPT.',
			);
		}

		$field_key = isset( $item['field_key'] )
			? sanitize_text_field( $item['field_key'] )
			: '';

		$field_name = isset( $item['field_name'] )
			? sanitize_key( $item['field_name'] )
			: '';

		if ( empty( $field_key ) && empty( $field_name ) ) {
			return array(
				'success' => false,
				'error'   => 'No ACF field key or field name was provided.',
			);
		}

		/*
		 * Prefer the ACF field key. If only a field name is supplied,
		 * resolve it through the discovered schema first.
		 */
		if ( empty( $field_key ) && ! empty( $field_name ) ) {

			$schema = TL_AI_VM_Field_Schema::instance()->get_fields(
				$selected_cpt
			);

			foreach ( $schema as $field ) {

				if (
					isset( $field['name'], $field['key'] ) &&
					$field['name'] === $field_name
				) {
					$field_key = $field['key'];
					break;
				}
			}
		}

		if ( empty( $field_key ) ) {
			return array(
				'success' => false,
				'error'   => 'The ACF field could not be resolved from the discovered schema.',
			);
		}

		$approved = ! empty( $item['approved'] );

		$status = isset( $item['approval_status'] )
			? sanitize_key( $item['approval_status'] )
			: '';

		if ( ! $approved && 'approved' !== $status ) {
			return array(
				'success' => false,
				'error'   => 'This research result has not been approved for writing.',
			);
		}

		if ( ! array_key_exists( 'value', $item ) ) {
			return array(
				'success' => false,
				'error'   => 'No value was supplied for the ACF field.',
			);
		}

		return array(
			'success'   => true,
			'post_id'   => $post_id,
			'field_key' => $field_key,
		);
	}

	/**
	 * Compare stored ACF value with requested value.
	 *
	 * @param mixed $stored
	 * @param mixed $expected
	 * @return bool
	 */
	private function values_match( $stored, $expected ) {

		if ( is_array( $stored ) || is_object( $stored ) ) {
			return wp_json_encode( $stored ) === wp_json_encode( $expected );
		}

		return (string) $stored === (string) $expected;
	}

	/**
	 * Send an entry to the central logger when available.
	 *
	 * @param string $message
	 * @param string $module
	 * @param array  $context
	 * @return void
	 */
	private function log( $message, $module, $context = array() ) {

		if (
			class_exists( 'TL_AI_VM_Logger' ) &&
			method_exists(
				TL_AI_VM_Logger::instance(),
				'info'
			)
		) {
			TL_AI_VM_Logger::instance()->info(
				$message,
				$module,
				$context
			);
		}
	}
}
