<?php
/**
 * AI Queue Manager
 *
 * Handles queued AI vehicle processing jobs.
 *
 * Responsibilities:
 *
 * - Create jobs
 * - Prevent duplicate active jobs
 * - Track job lifecycle
 * - Track attempts
 * - Store job arguments
 * - Store errors
 * - Support retry / cancel / cleanup
 * - Recover stale processing jobs
 * - Provide queue statistics
 *
 * IMPORTANT:
 * This class is intentionally generic.
 * It must not contain vehicle-specific fields,
 * research logic, ACF logic or AI business logic.
 *
 * Current storage:
 * WordPress option.
 *
 * Future storage:
 * This class can later be migrated to a dedicated
 * database table without requiring changes to
 * higher-level queue consumers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


final class TL_AI_VM_AI_Queue {

	/**
	 * Singleton instance.
	 *
	 * @var TL_AI_VM_AI_Queue|null
	 */
	private static $instance = null;


	/**
	 * WordPress option containing queue jobs.
	 *
	 * @var string
	 */
	private $option_name = 'tl_ai_vm_queue';


	/**
	 * Maximum attempts allowed for a job.
	 *
	 * @var int
	 */
	private $max_attempts = 3;


	/**
	 * Number of seconds after which a processing
	 * job is considered stale.
	 *
	 * A worker that crashes may leave a job in
	 * "processing" state. This timeout allows the
	 * queue to recover that job.
	 *
	 * @var int
	 */
	private $processing_timeout = 900;


	/**
	 * Allowed job statuses.
	 *
	 * @var array
	 */
	private $statuses = array(
		'pending',
		'processing',
		'completed',
		'failed',
		'cancelled',
	);


	/**
	 * Get singleton instance.
	 *
	 * @return TL_AI_VM_AI_Queue
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
	 * Get all queue jobs.
	 *
	 * @return array
	 */
	public function get_jobs() {

		$jobs = get_option(
			$this->option_name,
			array()
		);

		if ( ! is_array( $jobs ) ) {
			return array();
		}

		return $this->normalize_jobs(
			$jobs
		);
	}


	/**
	 * Save all queue jobs.
	 *
	 * @param array $jobs Jobs.
	 *
	 * @return bool
	 */
	private function save_jobs( $jobs ) {

		if ( ! is_array( $jobs ) ) {
			return false;
		}

		return update_option(
			$this->option_name,
			$jobs,
			false
		);
	}


	/**
	 * Normalize old / incomplete jobs.
	 *
	 * This keeps backward compatibility if the queue
	 * already contains jobs created by an older version
	 * of this class.
	 *
	 * @param array $jobs Jobs.
	 *
	 * @return array
	 */
	private function normalize_jobs( $jobs ) {

		$normalized = array();

		foreach ( $jobs as $job ) {

			if ( ! is_array( $job ) ) {
				continue;
			}

			if ( empty( $job['id'] ) ) {
				continue;
			}

			$job['id'] = sanitize_text_field(
				$job['id']
			);

			$job['post_id'] = isset( $job['post_id'] )
				? absint( $job['post_id'] )
				: 0;

			$job['action'] = isset( $job['action'] )
				? sanitize_key( $job['action'] )
				: '';

			$job['args'] = isset( $job['args'] ) &&
				is_array( $job['args'] )
				? $job['args']
				: array();

			$job['meta'] = isset( $job['meta'] ) &&
				is_array( $job['meta'] )
				? $job['meta']
				: array();

			$job['status'] = isset( $job['status'] ) &&
				in_array(
					$job['status'],
					$this->statuses,
					true
				)
				? $job['status']
				: 'pending';

			$job['attempts'] = isset( $job['attempts'] )
				? absint( $job['attempts'] )
				: 0;

			$job['max_attempts'] =
				isset( $job['max_attempts'] )
					? absint( $job['max_attempts'] )
					: $this->max_attempts;

			$job['created_at'] =
				isset( $job['created_at'] )
					? absint( $job['created_at'] )
					: 0;

			$job['started_at'] =
				isset( $job['started_at'] )
					? absint( $job['started_at'] )
					: 0;

			$job['completed_at'] =
				isset( $job['completed_at'] )
					? absint( $job['completed_at'] )
					: 0;

			$job['error'] =
				isset( $job['error'] ) &&
				is_string( $job['error'] )
					? $job['error']
					: '';

			/**
			 * New field.
			 *
			 * Used to identify when a worker last
			 * touched the job.
			 */
			$job['heartbeat_at'] =
				isset( $job['heartbeat_at'] )
					? absint( $job['heartbeat_at'] )
					: $job['started_at'];

			$normalized[] = $job;
		}

		return $normalized;
	}


	/**
	 * Add a new queue job.
	 *
	 * @param int    $post_id Vehicle post ID.
	 * @param string $action  Job action.
	 * @param array  $args    Additional arguments.
	 *
	 * @return string|false
	 */
	public function add(
		$post_id,
		$action = 'research',
		$args = array()
	) {

		$post_id = absint(
			$post_id
		);

		$action = sanitize_key(
			$action
		);

		if (
			! $post_id ||
			'' === $action
		) {
			return false;
		}

		$args = is_array( $args )
			? $args
			: array();

		/**
		 * Clean stale processing jobs before checking
		 * for an active duplicate.
		 */
		$this->recover_stale_jobs();

		/**
		 * Prevent duplicate active jobs.
		 *
		 * NOTE:
		 * Because the current storage layer is a single
		 * WordPress option, absolute database-level atomic
		 * uniqueness is not possible here.
		 *
		 * The future dedicated queue table should enforce
		 * this at database level.
		 */
		$existing = $this->find_active_job(
			$post_id,
			$action
		);

		if ( $existing ) {
			return isset( $existing['id'] )
				? $existing['id']
				: false;
		}

		$job_id =
			'job_' . wp_generate_uuid4();

		$timestamp =
			current_time(
				'timestamp'
			);

		$job = array(

			'id' =>
				$job_id,

			'post_id' =>
				$post_id,

			'action' =>
				$action,

			'args' =>
				$args,

			'status' =>
				'pending',

			'attempts' =>
				0,

			'max_attempts' =>
				$this->max_attempts,

			'created_at' =>
				$timestamp,

			'started_at' =>
				0,

			'heartbeat_at' =>
				0,

			'completed_at' =>
				0,

			'error' =>
				'',

			'meta' =>
				array(),
		);

		$jobs =
			$this->get_jobs();

		$jobs[] =
			$job;

		if (
			! $this->save_jobs(
				$jobs
			)
		) {
			return false;
		}

		do_action(
			'tl_ai_vm_queue_job_added',
			$job
		);

		return $job_id;
	}


	/**
	 * Add multiple jobs.
	 *
	 * @param array  $post_ids Vehicle IDs.
	 * @param string $action   Action.
	 * @param array  $args     Arguments.
	 *
	 * @return array
	 */
	public function add_many(
		$post_ids,
		$action = 'research',
		$args = array()
	) {

		$job_ids = array();

		if ( ! is_array( $post_ids ) ) {
			return $job_ids;
		}

		foreach ( $post_ids as $post_id ) {

			$job_id =
				$this->add(
					$post_id,
					$action,
					$args
				);

			if ( $job_id ) {
				$job_ids[] =
					$job_id;
			}
		}

		return $job_ids;
	}


	/**
	 * Get a job by ID.
	 *
	 * @param string $job_id Job ID.
	 *
	 * @return array|null
	 */
	public function get(
		$job_id
	) {

		$job_id =
			sanitize_text_field(
				$job_id
			);

		if ( '' === $job_id ) {
			return null;
		}

		$jobs =
			$this->get_jobs();

		foreach ( $jobs as $job ) {

			if (
				isset( $job['id'] ) &&
				$job['id'] === $job_id
			) {
				return $job;
			}
		}

		return null;
	}


	/**
	 * Find an active job for a vehicle/action pair.
	 *
	 * @param int    $post_id Vehicle ID.
	 * @param string $action  Action.
	 *
	 * @return array|null
	 */
	public function find_active_job(
		$post_id,
		$action
	) {

		$post_id =
			absint(
				$post_id
			);

		$action =
			sanitize_key(
				$action
			);

		if (
			! $post_id ||
			'' === $action
		) {
			return null;
		}

		$jobs =
			$this->get_jobs();

		foreach ( $jobs as $job ) {

			if (
				empty( $job['post_id'] ) ||
				empty( $job['action'] )
			) {
				continue;
			}

			if (
				absint(
					$job['post_id']
				) !== $post_id
			) {
				continue;
			}

			if (
				$job['action'] !== $action
			) {
				continue;
			}

			if (
				isset( $job['status'] ) &&
				in_array(
					$job['status'],
					array(
						'pending',
						'processing',
					),
					true
				)
			) {
				return $job;
			}
		}

		return null;
	}


	/**
	 * Get next pending job.
	 *
	 * @return array|null
	 */
	public function get_next() {

		$this->recover_stale_jobs();

		$jobs =
			$this->get_jobs();

		foreach ( $jobs as $job ) {

			if (
				isset( $job['status'] ) &&
				$job['status'] === 'pending'
			) {
				return $job;
			}
		}

		return null;
	}


	/**
	 * Start processing a job.
	 *
	 * @param string $job_id Job ID.
	 *
	 * @return bool
	 */
	public function start(
		$job_id
	) {

		$job =
			$this->get(
				$job_id
			);

		if ( ! $job ) {
			return false;
		}

		/**
		 * Only pending jobs can be started.
		 */
		if (
			! isset( $job['status'] ) ||
			$job['status'] !== 'pending'
		) {
			return false;
		}

		$attempts =
			$this->get_attempts(
				$job_id
			);

		$max_attempts =
			isset( $job['max_attempts'] )
				? absint(
					$job['max_attempts']
				)
				: $this->max_attempts;

		if (
			$max_attempts > 0 &&
			$attempts >= $max_attempts
		) {

			$this->fail(
				$job_id,
				'Maximum job attempts exceeded.'
			);

			return false;
		}

		$now =
			current_time(
				'timestamp'
			);

		$updated =
			$this->update_status(
				$job_id,
				'processing',
				array(
					'started_at' =>
						$now,

					'heartbeat_at' =>
						$now,

					'attempts' =>
						$attempts + 1,

					'error' =>
						'',

					'completed_at' =>
						0,
				)
			);

		if ( $updated ) {

			$updated_job =
				$this->get(
					$job_id
				);

			do_action(
				'tl_ai_vm_queue_job_started',
				$updated_job
			);
		}

		return $updated;
	}


	/**
	 * Update heartbeat for a processing job.
	 *
	 * Workers should call this periodically during
	 * long-running operations.
	 *
	 * @param string $job_id Job ID.
	 *
	 * @return bool
	 */
	public function heartbeat(
		$job_id
	) {

		$job =
			$this->get(
				$job_id
			);

		if ( ! $job ) {
			return false;
		}

		if (
			! isset( $job['status'] ) ||
			$job['status'] !== 'processing'
		) {
			return false;
		}

		return $this->update_job(
			$job_id,
			array(
				'heartbeat_at' =>
					current_time(
						'timestamp'
					),
			)
		);
	}


	/**
	 * Check whether a job is stale.
	 *
	 * @param array $job Job.
	 *
	 * @return bool
	 */
	private function is_stale_job(
		$job
	) {

		if ( ! is_array( $job ) ) {
			return false;
		}

		if (
			! isset( $job['status'] ) ||
			$job['status'] !== 'processing'
		) {
			return false;
		}

		$last_activity =
			isset( $job['heartbeat_at'] ) &&
			absint(
				$job['heartbeat_at']
			)
				? absint(
					$job['heartbeat_at']
				)
				: absint(
					isset( $job['started_at'] )
						? $job['started_at']
						: 0
				);

		if ( ! $last_activity ) {
			return false;
		}

		$now =
			current_time(
				'timestamp'
			);

		return (
			$now - $last_activity
		) >= $this->processing_timeout;
	}


	/**
	 * Recover stale processing jobs.
	 *
	 * A stale processing job becomes pending again,
	 * provided it still has attempts available.
	 *
	 * If attempts are exhausted, the job becomes failed.
	 *
	 * @return int Number of recovered jobs.
	 */
	public function recover_stale_jobs() {

		$jobs =
			$this->get_jobs();

		$changed =
			false;

		$recovered =
			0;

		foreach (
			$jobs as $index => $job
		) {

			if (
				! $this->is_stale_job(
					$job
				)
			) {
				continue;
			}

			$attempts =
				isset( $job['attempts'] )
					? absint(
						$job['attempts']
					)
					: 0;

			$max_attempts =
				isset( $job['max_attempts'] )
					? absint(
						$job['max_attempts']
					)
					: $this->max_attempts;

			$jobs[ $index ]['started_at'] =
				0;

			$jobs[ $index ]['heartbeat_at'] =
				0;

			$jobs[ $index ]['completed_at'] =
				0;

			$jobs[ $index ]['error'] =
				'';

			if (
				$max_attempts > 0 &&
				$attempts >= $max_attempts
			) {

				$jobs[ $index ]['status'] =
					'failed';

				$jobs[ $index ]['completed_at'] =
					current_time(
						'timestamp'
					);

				$jobs[ $index ]['error'] =
					'Job became stale after maximum attempts were used.';

			} else {

				$jobs[ $index ]['status'] =
					'pending';

				$recovered++;
			}

			$changed =
				true;
		}

		if ( ! $changed ) {
			return 0;
		}

		if (
			! $this->save_jobs(
				$jobs
			)
		) {
			return 0;
		}

		/**
		 * Notify other modules after recovery.
		 */
		foreach ( $jobs as $job ) {

			if (
				isset( $job['status'] ) &&
				$job['status'] === 'pending' &&
				isset( $job['error'] ) &&
				$job['error'] === ''
			) {

				/**
				 * Do not fire this action for every normal
				 * pending job. Only consumers that need
				 * recovery details should use the dedicated
				 * recovery action below.
				 */
			}
		}

		do_action(
			'tl_ai_vm_queue_stale_jobs_recovered',
			$recovered
		);

		return $recovered;
	}


	/**
	 * Mark job as completed.
	 *
	 * @param string $job_id Job ID.
	 *
	 * @return bool
	 */
	public function complete(
		$job_id
	) {

		$job =
			$this->get(
				$job_id
			);

		if ( ! $job ) {
			return false;
		}

		if (
			! isset( $job['status'] ) ||
			$job['status'] !== 'processing'
		) {
			return false;
		}

		$updated =
			$this->update_status(
				$job_id,
				'completed',
				array(
					'completed_at' =>
						current_time(
							'timestamp'
						),

					'heartbeat_at' =>
						0,
				)
			);

		if ( $updated ) {

			$updated_job =
				$this->get(
					$job_id
				);

			do_action(
				'tl_ai_vm_queue_job_completed',
				$updated_job
			);
		}

		return $updated;
	}


	/**
	 * Mark job as failed.
	 *
	 * @param string $job_id Job ID.
	 * @param string $error  Error message.
	 *
	 * @return bool
	 */
	public function fail(
		$job_id,
		$error = ''
	) {

		$error =
			is_string( $error )
				? sanitize_text_field(
					$error
				)
				: 'Unknown queue error.';

		$job =
			$this->get(
				$job_id
			);

		if ( ! $job ) {
			return false;
		}

		$updated =
			$this->update_status(
				$job_id,
				'failed',
				array(
					'error' =>
						$error,

					'completed_at' =>
						current_time(
							'timestamp'
						),

					'heartbeat_at' =>
						0,
				)
			);

		if ( $updated ) {

			$updated_job =
				$this->get(
					$job_id
				);

			do_action(
				'tl_ai_vm_queue_job_failed',
				$updated_job
			);
		}

		return $updated;
	}


	/**
	 * Retry a failed job.
	 *
	 * @param string $job_id Job ID.
	 *
	 * @return bool
	 */
	public function retry(
		$job_id
	) {

		$job =
			$this->get(
				$job_id
			);

		if ( ! $job ) {
			return false;
		}

		if (
			! isset( $job['status'] ) ||
			$job['status'] !== 'failed'
		) {
			return false;
		}

		$attempts =
			$this->get_attempts(
				$job_id
			);

		$max_attempts =
			isset( $job['max_attempts'] )
				? absint(
					$job['max_attempts']
				)
				: $this->max_attempts;

		if (
			$max_attempts > 0 &&
			$attempts >= $max_attempts
		) {
			return false;
		}

		$updated =
			$this->update_status(
				$job_id,
				'pending',
				array(
					'error' =>
						'',

					'started_at' =>
						0,

					'heartbeat_at' =>
						0,

					'completed_at' =>
						0,
				)
			);

		if ( $updated ) {

			$updated_job =
				$this->get(
					$job_id
				);

			do_action(
				'tl_ai_vm_queue_job_retried',
				$updated_job
			);
		}

		return $updated;
	}


	/**
	 * Cancel a job.
	 *
	 * @param string $job_id Job ID.
	 *
	 * @return bool
	 */
	public function cancel(
		$job_id
	) {

		$job =
			$this->get(
				$job_id
			);

		if ( ! $job ) {
			return false;
		}

		if (
			isset( $job['status'] ) &&
			in_array(
				$job['status'],
				array(
					'completed',
					'cancelled',
				),
				true
			)
		) {
			return false;
		}

		$updated =
			$this->update_status(
				$job_id,
				'cancelled',
				array(
					'heartbeat_at' =>
						0,
				)
			);

		if ( $updated ) {

			$updated_job =
				$this->get(
					$job_id
				);

			do_action(
				'tl_ai_vm_queue_job_cancelled',
				$updated_job
			);
		}

		return $updated;
	}


	/**
	 * Update job status.
	 *
	 * @param string $job_id Job ID.
	 * @param string $status Status.
	 * @param array  $extra  Additional data.
	 *
	 * @return bool
	 */
	private function update_status(
		$job_id,
		$status,
		$extra = array()
	) {

		$job_id =
			sanitize_text_field(
				$job_id
			);

		$status =
			sanitize_key(
				$status
			);

		if (
			'' === $job_id ||
			! in_array(
				$status,
				$this->statuses,
				true
			)
		) {
			return false;
		}

		$jobs =
			$this->get_jobs();

		foreach (
			$jobs as $index => $job
		) {

			if (
				! isset( $job['id'] ) ||
				$job['id'] !== $job_id
			) {
				continue;
			}

			$jobs[ $index ]['status'] =
				$status;

			if ( is_array( $extra ) ) {

				foreach (
					$extra as $key => $value
				) {

					$jobs[ $index ][ $key ] =
						$value;
				}
			}

			return $this->save_jobs(
				$jobs
			);
		}

		return false;
	}


	/**
	 * Get attempt count.
	 *
	 * @param string $job_id Job ID.
	 *
	 * @return int
	 */
	public function get_attempts(
		$job_id
	) {

		$job =
			$this->get(
				$job_id
			);

		if ( ! $job ) {
			return 0;
		}

		return isset( $job['attempts'] )
			? absint(
				$job['attempts']
			)
			: 0;
	}


	/**
	 * Get queue statistics.
	 *
	 * @return array
	 */
	public function get_stats() {

		$this->recover_stale_jobs();

		$jobs =
			$this->get_jobs();

		$stats = array(

			'total' =>
				count( $jobs ),

			'pending' =>
				0,

			'processing' =>
				0,

			'completed' =>
				0,

			'failed' =>
				0,

			'cancelled' =>
				0,

			'stale' =>
				0,
		);

		foreach ( $jobs as $job ) {

			if (
				isset( $job['status'] ) &&
				isset(
					$stats[
						$job['status']
					]
				)
			) {

				$stats[
					$job['status']
				]++;
			}

			if (
				$this->is_stale_job(
					$job
				)
			) {
				$stats['stale']++;
			}
		}

		return $stats;
	}


	/**
	 * Get jobs by status.
	 *
	 * @param string $status Job status.
	 *
	 * @return array
	 */
	public function get_by_status(
		$status
	) {

		$status =
			sanitize_key(
				$status
			);

		if (
			! in_array(
				$status,
				$this->statuses,
				true
			)
		) {
			return array();
		}

		$result =
			array();

		foreach (
			$this->get_jobs() as $job
		) {

			if (
				isset( $job['status'] ) &&
				$job['status'] === $status
			) {

				$result[] =
					$job;
			}
		}

		return $result;
	}


	/**
	 * Get jobs for a vehicle.
	 *
	 * @param int $post_id Vehicle ID.
	 *
	 * @return array
	 */
	public function get_by_post(
		$post_id
	) {

		$post_id =
			absint(
				$post_id
			);

		if ( ! $post_id ) {
			return array();
		}

		$result =
			array();

		foreach (
			$this->get_jobs() as $job
		) {

			if (
				isset( $job['post_id'] ) &&
				absint(
					$job['post_id']
				) === $post_id
			) {

				$result[] =
					$job;
			}
		}

		return $result;
	}


	/**
	 * Update job arguments.
	 *
	 * @param string $job_id Job ID.
	 * @param array  $args   Arguments.
	 *
	 * @return bool
	 */
	public function update_args(
		$job_id,
		$args
	) {

		if ( ! is_array( $args ) ) {
			return false;
		}

		return $this->update_job(
			$job_id,
			array(
				'args' =>
					$args,
			)
		);
	}


	/**
	 * Update job metadata.
	 *
	 * @param string $job_id Job ID.
	 * @param array  $meta   Metadata.
	 *
	 * @return bool
	 */
	public function update_meta(
		$job_id,
		$meta
	) {

		if ( ! is_array( $meta ) ) {
			return false;
		}

		return $this->update_job(
			$job_id,
			array(
				'meta' =>
					$meta,
			)
		);
	}


	/**
	 * Generic job data updater.
	 *
	 * @param string $job_id Job ID.
	 * @param array  $data   Data to update.
	 *
	 * @return bool
	 */
	private function update_job(
		$job_id,
		$data
	) {

		$job_id =
			sanitize_text_field(
				$job_id
			);

		if (
			'' === $job_id ||
			! is_array( $data )
		) {
			return false;
		}

		$jobs =
			$this->get_jobs();

		foreach (
			$jobs as $index => $job
		) {

			if (
				! isset( $job['id'] ) ||
				$job['id'] !== $job_id
			) {
				continue;
			}

			foreach (
				$data as $key => $value
			) {

				$jobs[ $index ][ $key ] =
					$value;
			}

			return $this->save_jobs(
				$jobs
			);
		}

		return false;
	}


	/**
	 * Remove completed and cancelled jobs.
	 *
	 * Failed jobs are retained.
	 *
	 * @return bool
	 */
	public function cleanup() {

		$jobs =
			$this->get_jobs();

		$keep =
			array();

		foreach ( $jobs as $job ) {

			if (
				isset( $job['status'] ) &&
				in_array(
					$job['status'],
					array(
						'completed',
						'cancelled',
					),
					true
				)
			) {
				continue;
			}

			$keep[] =
				$job;
		}

		return $this->save_jobs(
			$keep
		);
	}


	/**
	 * Clear the entire queue.
	 *
	 * Intended for administrative maintenance only.
	 *
	 * @return bool
	 */
	public function clear() {

		return delete_option(
			$this->option_name
		);
	}
}
