<?php
/**
 * AI Queue Worker
 *
 * Processes queued AI Vehicle Manager jobs.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TL_AI_VM_Queue_Worker {

	/**
	 * Singleton instance.
	 *
	 * @var TL_AI_VM_Queue_Worker|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return TL_AI_VM_Queue_Worker
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

		add_action(
			'tl_ai_vm_process_queue',
			array( $this, 'process_queue' )
		);

		add_action(
			'tl_ai_vm_process_job',
			array( $this, 'process_job' ),
			10,
			1
		);
	}

	/**
	 * Process pending queue jobs.
	 *
	 * @param int $limit Maximum number of jobs.
	 *
	 * @return array
	 */
	public function process_queue( $limit = 1 ) {

		$limit = max(
			1,
			absint( $limit )
		);

		$queue = $this->get_queue();

		if ( ! $queue ) {

			return array(
				'success' => false,
				'processed' => 0,
				'error' => 'AI Queue is not available.',
			);
		}

		$processed = 0;
		$failed    = 0;
		$results   = array();

		for ( $i = 0; $i < $limit; $i++ ) {

			$job = $this->claim_next_job();

			if ( empty( $job ) ) {
				break;
			}

			$result = $this->process_job(
				$job
			);

			$results[] = $result;

			if (
				! empty(
					$result['success']
				)
			) {
				$processed++;
			} else {
				$failed++;
			}
		}

		return array(
			'success' =>
				$failed === 0,

			'processed' =>
				$processed,

			'failed' =>
				$failed,

			'results' =>
				$results,
		);
	}

	/**
	 * Process a single job.
	 *
	 * @param mixed $job Queue job.
	 *
	 * @return array
	 */
	public function process_job( $job ) {

		$job_id = $this->get_job_id(
			$job
		);

		if ( ! $job_id ) {

			return array(
				'success' => false,
				'error' =>
					'Invalid queue job.',
			);
		}

		$this->log(
			'Processing AI queue job.',
			array(
				'job_id' => $job_id,
			)
		);

		try {

			$action =
				$this->get_job_value(
					$job,
					'action',
					''
				);

			if ( empty( $action ) ) {

				throw new Exception(
					'Queue job action is missing.'
				);
			}

			$result =
				$this->dispatch_action(
					$action,
					$job
				);

			if (
				is_array( $result ) &&
				! empty(
					$result['success']
				)
			) {

				$this->complete_job(
					$job_id,
					$result
				);

				do_action(
					'tl_ai_vm_queue_job_completed',
					$job_id,
					$result,
					$job
				);

				return array(
					'success' => true,
					'job_id'  => $job_id,
					'result'  => $result,
				);
			}

			$error =
				is_array( $result ) &&
				! empty(
					$result['error']
				)
					? $result['error']
					: 'AI queue job failed.';

			throw new Exception(
				$error
			);

		} catch ( Throwable $e ) {

			$this->fail_job(
				$job_id,
				$e->getMessage()
			);

			$this->log(
				'AI queue job failed.',
				array(
					'job_id' => $job_id,
					'error'  => $e->getMessage(),
				)
			);

			do_action(
				'tl_ai_vm_queue_job_failed',
				$job_id,
				$e->getMessage(),
				$job
			);

			return array(
				'success' => false,
				'job_id'  => $job_id,
				'error'   => $e->getMessage(),
			);
		}
	}

	/**
	 * Dispatch a job according to its action.
	 *
	 * @param string $action Job action.
	 * @param mixed  $job    Job data.
	 *
	 * @return array
	 */
	private function dispatch_action(
		$action,
		$job
	) {

		$action =
			sanitize_key(
				$action
			);

		switch ( $action ) {

			case 'research_vehicle_full':

                return TL_AI_VM_Vehicle_Research_Runner::instance()->run(
                    $this->get_vehicle_id( $job ),
                    $this->get_job_value( $job, 'args', array() )
                );

            case 'research_vehicle':

				return $this->research_vehicle(
					$job
				);

			case 'analyze_vehicle':

				return $this->analyze_vehicle(
					$job
				);

			case 'fill_vehicle':

				return $this->fill_vehicle(
					$job
				);

			case 'update_vehicle':

				return $this->update_vehicle(
					$job
				);

			default:

				/**
				 * Allow future modules to register
				 * their own queue actions.
				 */
				$result =
					apply_filters(
						'tl_ai_vm_process_queue_action',
						null,
						$action,
						$job
					);

				if (
					is_array( $result )
				) {
					return $result;
				}

				return array(
					'success' => false,
					'error' =>
						'Unknown queue action: ' .
						$action,
				);
		}
	}

	/**
	 * Research vehicle.
	 *
	 * @param mixed $job Queue job.
	 *
	 * @return array
	 */
	private function research_vehicle(
		$job
	) {

		$vehicle_id =
			$this->get_vehicle_id(
				$job
			);

		if ( ! $vehicle_id ) {

			return array(
				'success' => false,
				'error' =>
					'Vehicle ID is missing.',
			);
		}

		$researcher =
			$this->get_component(
				'TL_AI_VM_Vehicle_Researcher'
			);

		if ( ! $researcher ) {

			return array(
				'success' => false,
				'error' =>
					'Vehicle researcher is not available.',
			);
		}

		if (
			method_exists(
				$researcher,
				'research'
			)
		) {

			$result =
				$researcher->research(
					$vehicle_id
				);

			return $this->normalize_result(
				$result
			);
		}

		return array(
			'success' => false,
			'error' =>
				'Vehicle researcher does not support research().',
		);
	}

	/**
	 * Analyze vehicle data using AI.
	 *
	 * @param mixed $job Queue job.
	 *
	 * @return array
	 */
	private function analyze_vehicle(
		$job
	) {

		$vehicle_id =
			$this->get_vehicle_id(
				$job
			);

		if ( ! $vehicle_id ) {

			return array(
				'success' => false,
				'error' =>
					'Vehicle ID is missing.',
			);
		}

		$analyzer =
			$this->get_component(
				'TL_AI_VM_AI_Field_Analyzer'
			);

		if ( ! $analyzer ) {

			return array(
				'success' => false,
				'error' =>
					'AI Field Analyzer is not available.',
			);
		}

		/**
		 * Give the analyzer access to the vehicle.
		 */
		if (
			method_exists(
				$analyzer,
				'analyze_vehicle'
			)
		) {

			$result =
				$analyzer->analyze_vehicle(
					$vehicle_id
				);

			return $this->normalize_result(
				$result
			);
		}

		if (
			method_exists(
				$analyzer,
				'analyze'
			)
		) {

			$result =
				$analyzer->analyze(
					$vehicle_id
				);

			return $this->normalize_result(
				$result
			);
		}

		return array(
			'success' => false,
			'error' =>
				'AI Field Analyzer does not expose a supported method.',
		);
	}

	/**
	 * Fill vehicle fields.
	 *
	 * This stage is intentionally separated from research
	 * and analysis.
	 *
	 * @param mixed $job Queue job.
	 *
	 * @return array
	 */
	private function fill_vehicle(
		$job
	) {

		$vehicle_id =
			$this->get_vehicle_id(
				$job
			);

		if ( ! $vehicle_id ) {

			return array(
				'success' => false,
				'error' =>
					'Vehicle ID is missing.',
			);
		}

		$writer =
			$this->get_component(
				'TL_AI_VM_Vehicle_Data_Writer'
			);

		if ( ! $writer ) {

			return array(
				'success' => false,
				'error' =>
					'Vehicle Data Writer is not available.',
			);
		}

		$data =
			$this->get_job_value(
				$job,
				'data',
				array()
			);

		if (
			method_exists(
				$writer,
				'write'
			)
		) {

			$result =
				$writer->write(
					$vehicle_id,
					$data
				);

			return $this->normalize_result(
				$result
			);
		}

		if (
			method_exists(
				$writer,
				'write_vehicle'
			)
		) {

			$result =
				$writer->write_vehicle(
					$vehicle_id,
					$data
				);

			return $this->normalize_result(
				$result
			);
		}

		return array(
			'success' => false,
			'error' =>
				'Vehicle Data Writer does not expose a supported method.',
		);
	}

	/**
	 * Update vehicle.
	 *
	 * @param mixed $job Queue job.
	 *
	 * @return array
	 */
	private function update_vehicle(
		$job
	) {

		$vehicle_id =
			$this->get_vehicle_id(
				$job
			);

		if ( ! $vehicle_id ) {

			return array(
				'success' => false,
				'error' =>
					'Vehicle ID is missing.',
			);
		}

		$writer =
			$this->get_component(
				'TL_AI_VM_Vehicle_Data_Writer'
			);

		if ( ! $writer ) {

			return array(
				'success' => false,
				'error' =>
					'Vehicle Data Writer is not available.',
			);
		}

		$data =
			$this->get_job_value(
				$job,
				'data',
				array()
			);

		if (
			method_exists(
				$writer,
				'update'
			)
		) {

			return $this->normalize_result(
				$writer->update(
					$vehicle_id,
					$data
				)
			);
		}

		if (
			method_exists(
				$writer,
				'write'
			)
		) {

			return $this->normalize_result(
				$writer->write(
					$vehicle_id,
					$data
				)
			);
		}

		return array(
			'success' => false,
			'error' =>
				'Vehicle Data Writer does not expose update().',
		);
	}

	/**
	 * Claim the next pending job.
	 *
	 * @return mixed
	 */
	private function claim_next_job() {

		$queue =
			$this->get_queue();

		if ( ! $queue ) {
			return null;
		}

		/**
		 * Try common queue APIs without forcing
		 * the Queue implementation into this class.
		 */
		if (
			method_exists(
				$queue,
				'claim_next'
			)
		) {
			return $queue->claim_next();
		}

		if (
			method_exists(
				$queue,
				'get_next'
			)
		) {
			return $queue->get_next();
		}

		if (
			method_exists(
				$queue,
				'next'
			)
		) {
			return $queue->next();
		}

		return null;
	}

	/**
	 * Complete a job.
	 *
	 * @param int   $job_id Job ID.
	 * @param array $result Result.
	 *
	 * @return bool
	 */
	private function complete_job(
		$job_id,
		$result
	) {

		$queue =
			$this->get_queue();

		if ( ! $queue ) {
			return false;
		}

		if (
			method_exists(
				$queue,
				'complete'
			)
		) {

			return (bool) $queue->complete(
				$job_id,
				$result
			);
		}

		return false;
	}

	/**
	 * Fail a job.
	 *
	 * @param int    $job_id Job ID.
	 * @param string $error  Error.
	 *
	 * @return bool
	 */
	private function fail_job(
		$job_id,
		$error
	) {

		$queue =
			$this->get_queue();

		if ( ! $queue ) {
			return false;
		}

		if (
			method_exists(
				$queue,
				'fail'
			)
		) {

			return (bool) $queue->fail(
				$job_id,
				$error
			);
		}

		return false;
	}

	/**
	 * Get Queue instance.
	 *
	 * @return object|null
	 */
	private function get_queue() {

		if (
			class_exists(
				'TL_AI_VM_AI_Queue'
			)
		) {

			return TL_AI_VM_AI_Queue::instance();
		}

return null;
	}

	/**
	 * Get component singleton.
	 *
	 * @param string $class Class name.
	 *
	 * @return object|null
	 */
	private function get_component(
		$class
	) {

		if (
			! class_exists(
				$class
			)
		) {
			return null;
		}

		if (
			method_exists(
				$class,
				'instance'
			)
		) {

			return $class::instance();
		}

		return null;
	}

	/**
	 * Get job ID.
	 *
	 * @param mixed $job Job.
	 *
	 * @return int
	 */
	private function get_job_id(
		$job
	) {

		if ( is_array( $job ) ) {

			if (
				isset(
					$job['id']
				)
			) {
				return absint(
					$job['id']
				);
			}

			if (
				isset(
					$job['job_id']
				)
			) {
				return absint(
					$job['job_id']
				);
			}
		}

		if (
			is_object( $job )
		) {

			if (
				isset(
					$job->id
				)
			) {
				return absint(
					$job->id
				);
			}

			if (
				isset(
					$job->job_id
				)
			) {
				return absint(
					$job->job_id
				);
			}
		}

		return 0;
	}

	/**
	 * Get vehicle ID from job.
	 *
	 * @param mixed $job Job.
	 *
	 * @return int
	 */
	private function get_vehicle_id(
		$job
	) {

		$vehicle_id =
			$this->get_job_value(
				$job,
				'vehicle_id',
				0
			);

		if ( ! $vehicle_id ) {

			$vehicle_id =
				$this->get_job_value(
					$job,
					'post_id',
					0
				);
		}

		return absint(
			$vehicle_id
		);
	}

	/**
	 * Read a job value.
	 *
	 * @param mixed  $job     Job.
	 * @param string $key     Key.
	 * @param mixed  $default Default value.
	 *
	 * @return mixed
	 */
	private function get_job_value(
		$job,
		$key,
		$default = null
	) {

		if ( is_array( $job ) ) {

			return isset(
				$job[ $key ]
			)
				? $job[ $key ]
				: $default;
		}

		if ( is_object( $job ) ) {

			return isset(
				$job->{$key}
			)
				? $job->{$key}
				: $default;
		}

		return $default;
	}

	/**
	 * Normalize a component result.
	 *
	 * @param mixed $result Result.
	 *
	 * @return array
	 */
	private function normalize_result(
		$result
	) {

		if ( is_array( $result ) ) {

			if (
				! isset(
					$result['success']
				)
			) {

				$result['success'] = true;
			}

			return $result;
		}

		if ( false === $result ) {

			return array(
				'success' => false,
				'error' =>
					'Component returned false.',
			);
		}

		return array(
			'success' => true,
			'result'  => $result,
		);
	}

	/**
	 * Write to logger when available.
	 *
	 * @param string $message Message.
	 * @param array  $context Context.
	 *
	 * @return void
	 */
	private function log(
		$message,
		$context = array()
	) {

		if (
			class_exists(
				'TL_AI_VM_Logger'
			)
		) {

			$logger =
				TL_AI_VM_Logger::instance();

			if (
				method_exists(
					$logger,
					'info'
				)
			) {

				$logger->info(
					$message,
					'queue_worker',
					$context
				);

				return;
			}

			if (
				method_exists(
					$logger,
					'log'
				)
			) {

				$logger->log(
					$message,
					$context
				);
			}
		}
	}

}