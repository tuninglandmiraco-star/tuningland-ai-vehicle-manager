<?php
/**
 * Bulk Vehicle Processing
 *
 * Provides a safe batch-processing engine for the vehicle pipeline.
 *
 * This class does not research or write by itself. It coordinates approved
 * writer items in small batches and supports dry-run mode.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TL_AI_VM_Bulk_Processor {

	private static $instance = null;

	const DEFAULT_BATCH_SIZE = 10;
	const MAX_BATCH_SIZE     = 50;

	public static function instance() {

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
	}

	/**
	 * Process a batch of approved writer items.
	 *
	 * Each item is passed directly to the ACF Writer, which performs
	 * its own approval and schema validation.
	 *
	 * @param array $items
	 * @param array $args
	 * @return array
	 */
	public function process( $items, $args = array() ) {

		if ( ! is_array( $items ) ) {
			return array(
				'success' => false,
				'error'   => 'Bulk items must be an array.',
			);
		}

		$args = wp_parse_args(
			$args,
			array(
				'batch_size' => self::DEFAULT_BATCH_SIZE,
				'dry_run'    => true,
				'stop_on_error' => false,
			)
		);

		$batch_size = max(
			1,
			min(
				self::MAX_BATCH_SIZE,
				absint( $args['batch_size'] )
			)
		);

		$chunks = array_chunk(
			$items,
			$batch_size
		);

		$summary = array(
			'success'     => true,
			'total_items' => count( $items ),
			'total_batches' => count( $chunks ),
			'processed'   => 0,
			'succeeded'   => 0,
			'failed'      => 0,
			'dry_run'     => (bool) $args['dry_run'],
			'batches'     => array(),
		);

		$writer = TL_AI_VM_ACF_Writer::instance();

		foreach ( $chunks as $batch_index => $chunk ) {

			$batch_result = $writer->write_batch(
				$chunk,
				(bool) $args['dry_run']
			);

			$summary['batches'][ $batch_index ] = $batch_result;

			$summary['processed'] += count( $chunk );

			if ( isset( $batch_result['written'] ) ) {
				$summary['succeeded'] += (int) $batch_result['written'];
			}

			if ( isset( $batch_result['failed'] ) ) {
				$summary['failed'] += (int) $batch_result['failed'];
			}

			if (
				! empty( $batch_result['failed'] ) &&
				! empty( $args['stop_on_error'] )
			) {
				$summary['success'] = false;
				break;
			}
		}

		if ( $summary['failed'] > 0 ) {
			$summary['success'] = false;
		}

		$this->log(
			'Bulk vehicle processing completed.',
			array(
				'total_items' => $summary['total_items'],
				'processed'   => $summary['processed'],
				'succeeded'   => $summary['succeeded'],
				'failed'      => $summary['failed'],
				'dry_run'     => $summary['dry_run'],
			)
		);

		return $summary;
	}

	/**
	 * Process a single vehicle's approved fields.
	 *
	 * @param int   $post_id
	 * @param array $items
	 * @param bool  $dry_run
	 * @return array
	 */
	public function process_vehicle( $post_id, $items, $dry_run = true ) {

		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return array(
				'success' => false,
				'error'   => 'Invalid vehicle post ID.',
			);
		}

		if ( ! is_array( $items ) ) {
			return array(
				'success' => false,
				'error'   => 'Vehicle items must be an array.',
			);
		}

		$normalized = array();

		foreach ( $items as $item ) {

			if ( ! is_array( $item ) ) {
				continue;
			}

			$item['post_id'] = $post_id;
			$normalized[]    = $item;
		}

		return $this->process(
			$normalized,
			array(
				'batch_size'    => self::DEFAULT_BATCH_SIZE,
				'dry_run'       => $dry_run,
				'stop_on_error' => false,
			)
		);
	}

	/**
	 * Return a safe batch-size value.
	 *
	 * @param int $size
	 * @return int
	 */
	public function normalize_batch_size( $size ) {

		$size = absint( $size );

		if ( $size < 1 ) {
			$size = self::DEFAULT_BATCH_SIZE;
		}

		return min(
			self::MAX_BATCH_SIZE,
			$size
		);
	}

	/**
	 * Central logger helper.
	 *
	 * @param string $message
	 * @param array  $context
	 * @return void
	 */
	private function log( $message, $context = array() ) {

		if ( class_exists( 'TL_AI_VM_Logger' ) ) {

			$logger = TL_AI_VM_Logger::instance();

			if ( method_exists( $logger, 'info' ) ) {
				$logger->info(
					$message,
					'bulk',
					$context
				);
			}
		}
	}
}
