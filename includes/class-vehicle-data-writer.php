<?php
/**
 * Vehicle Data Writer
 *
 * Writes validated vehicle data into ACF fields.
 *
 * IMPORTANT:
 * This class does not research data.
 * It only writes data after validation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TL_AI_VM_Vehicle_Data_Writer {

	/**
	 * Singleton instance.
	 *
	 * @var TL_AI_VM_Vehicle_Data_Writer|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return TL_AI_VM_Vehicle_Data_Writer
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
	 * Write validated data to a vehicle.
	 *
	 * @param int   $post_id Vehicle post ID.
	 * @param array $data    Field data.
	 * @param array $args    Options.
	 *
	 * @return array
	 */
	public function write(
		$post_id,
		$data,
		$args = array()
	) {

		$post_id = absint(
			$post_id
		);

		if ( ! $post_id ) {

			return array(
				'success' => false,
				'written' => 0,
				'errors'  => array(
					'Invalid vehicle post ID.',
				),
			);
		}

		if ( ! get_post( $post_id ) ) {

			return array(
				'success' => false,
				'written' => 0,
				'errors'  => array(
					'Vehicle post does not exist.',
				),
			);
		}

		if ( ! function_exists( 'update_field' ) ) {

			return array(
				'success' => false,
				'written' => 0,
				'errors'  => array(
					'ACF update_field() is not available.',
				),
			);
		}

		if ( ! is_array( $data ) ) {

			return array(
				'success' => false,
				'written' => 0,
				'errors'  => array(
					'Invalid vehicle data.',
				),
			);
		}

		$defaults = array(
			'allow_overwrite' => true,
			'dry_run'         => false,
			'only_fields'     => array(),
		);

		$args = wp_parse_args(
			$args,
			$defaults
		);

		$written = 0;
		$skipped = 0;
		$errors  = array();
		$changes = array();

		foreach ( $data as $field_name => $field_data ) {

			$field_name = sanitize_key(
				$field_name
			);

			if ( empty( $field_name ) ) {
				continue;
			}

			/**
			 * Optional whitelist.
			 */
			if (
				! empty(
					$args['only_fields']
				) &&
				is_array(
					$args['only_fields']
				) &&
				! in_array(
					$field_name,
					$args['only_fields'],
					true
				)
			) {

				$skipped++;

				continue;
			}

			/**
			 * Support both:
			 *
			 * field => value
			 *
			 * and:
			 *
			 * field => array(
			 *     'value' => ...
			 * )
			 */
			$value = $field_data;

			if (
				is_array( $field_data ) &&
				array_key_exists(
					'value',
					$field_data
				)
			) {

				$value =
					$field_data['value'];
			}

			/**
			 * Empty values are not written.
			 */
			if (
				$this->is_empty_value(
					$value
				)
			) {

				$skipped++;

				continue;
			}

			/**
			 * Read current value.
			 */
			$current_value =
				get_field(
					$field_name,
					$post_id
				);

			/**
			 * Prevent accidental overwrite.
			 */
			if (
				! $args['allow_overwrite'] &&
				! $this->is_empty_value(
					$current_value
				)
			) {

				$skipped++;

				continue;
			}

			$changes[] = array(
				'field' =>
					$field_name,

				'old_value' =>
					$current_value,

				'new_value' =>
					$value,
			);

			/**
			 * Dry-run mode.
			 */
			if ( ! empty( $args['dry_run'] ) ) {

				$written++;

				continue;
			}

			/**
			 * Update ACF field.
			 */
			$result =
				update_field(
					$field_name,
					$value,
					$post_id
				);

			if ( false === $result ) {

				/**
				 * update_field() can return false
				 * even when the stored value is
				 * effectively unchanged.
				 *
				 * Verify the actual value before
				 * treating it as an error.
				 */
				$after =
					get_field(
						$field_name,
						$post_id
					);

				if (
					! $this->values_equal(
						$after,
						$value
					)
				) {

					$errors[] = array(
						'field'   =>
							$field_name,

						'message' =>
							'ACF field could not be updated.',
					);

					continue;
				}
			}

			$written++;
		}

		/**
		 * Clear post cache.
		 */
		clean_post_cache(
			$post_id
		);

		return array(
			'success' =>
				empty( $errors ),

			'post_id' =>
				$post_id,

			'written' =>
				$written,

			'skipped' =>
				$skipped,

			'errors' =>
				$errors,

			'changes' =>
				$changes,

			'dry_run' =>
				! empty(
					$args['dry_run']
				),
		);
	}

	/**
	 * Write a single field.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $field_name Field name/key.
	 * @param mixed  $value      Value.
	 *
	 * @return array
	 */
	public function write_field(
		$post_id,
		$field_name,
		$value
	) {

		return $this->write(
			$post_id,
			array(
				$field_name => $value,
			)
		);
	}

	/**
	 * Preview changes without writing.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $data    Data.
	 *
	 * @return array
	 */
	public function preview(
		$post_id,
		$data
	) {

		return $this->write(
			$post_id,
			$data,
			array(
				'dry_run' => true,
			)
		);
	}

	/**
	 * Check whether a value is empty.
	 *
	 * @param mixed $value Value.
	 *
	 * @return bool
	 */
	private function is_empty_value(
		$value
	) {

		if ( null === $value ) {
			return true;
		}

		if ( is_string( $value ) ) {

			return '' === trim(
				$value
			);
		}

		if ( is_array( $value ) ) {

			return empty(
				$value
			);
		}

		return false;
	}

	/**
	 * Compare two values.
	 *
	 * @param mixed $first  First value.
	 * @param mixed $second Second value.
	 *
	 * @return bool
	 */
	private function values_equal(
		$first,
		$second
	) {

		if (
			is_array( $first ) ||
			is_object( $first ) ||
			is_array( $second ) ||
			is_object( $second )
		) {

			return wp_json_encode(
				$first
			) === wp_json_encode(
				$second
			);
		}

		return (string) $first ===
			(string) $second;
	}

}