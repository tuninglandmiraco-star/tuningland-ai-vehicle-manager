<?php
/**
 * Research Queue
 *
 * Manages AI vehicle research jobs.
 *
 * Architectural rules:
 *
 * - This class manages queue state only.
 * - It NEVER writes directly to ACF.
 * - It NEVER performs AI research.
 * - It NEVER validates research results.
 * - Research results are stored separately by Research Storage.
 *
 * Queue lifecycle:
 *
 * pending
 *    ↓
 * running
 *    ↓
 * completed
 *
 * Failure:
 *
 * running → failed
 *
 * Recovery:
 *
 * stale running → pending
 *
 * The queue is intentionally isolated from:
 *
 * - Research Validator
 * - Research Storage
 * - Vehicle Filter
 * - ACF Writer
 * - AI Provider
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TL_AI_VM_Research_Queue {

	/**
	 * Singleton instance.
	 *
	 * @var TL_AI_VM_Research_Queue|null
	 */
	private static $instance = null;

	/**
	 * Option name.
	 *
	 * @var string
	 */
	private $option_name = 'tl_ai_vm_research_queue';

	/**
	 * Queue schema version.
	 *
	 * @var string
	 */
	private $schema_version = '2.0.0';

	/**
	 * Default stale timeout.
	 *
	 * @var int
	 */
	private $stale_timeout = 1800;

	/**
	 * Get singleton instance.
	 *
	 * @return TL_AI_VM_Research_Queue
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
	 * Add a vehicle research job.
	 *
	 * @param int   $post_id Vehicle post ID.
	 * @param array $args    Job arguments.
	 *
	 * @return string|false
	 */
	public function add(
		$post_id,
		$args = array()
	) {

		$post_id = absint( $post_id );

		if (
			! $post_id ||
			! get_post( $post_id )
		) {
			return false;
		}

		$defaults = array(
			'priority'       => 10,
			'overwrite'     => false,
			'only_empty'    => false,
			'status'        => 'pending',
			'created_by'     => get_current_user_id(),
			'sources'       => array(),
			'fields'        => array(),
			'notes'         => '',
			'mode'          => 'review',
			'force'         => false,
			'metadata'      => array(),
		);

		$args = wp_parse_args(
			$args,
			$defaults
		);

		/**
		 * Do not create duplicate active jobs
		 * unless explicitly forced.
		 */
		if ( empty( $args['force'] ) ) {

			$existing =
				$this->find_by_post_id(
					$post_id,
					array(
						'pending',
						'running',
					)
				);

			if ( ! empty( $existing ) ) {

				return $existing[0]['id'];
			}
		}

		$now =
			current_time(
				'timestamp'
			);

		$id =
			$this->generate_id();

		$job = array(
			'schema_version' =>
				$this->schema_version,

			'id' =>
				$id,

			'post_id' =>
				$post_id,

			'status' =>
				$this->sanitize_status(
					$args['status']
				),

			'mode' =>
				$this->sanitize_mode(
					$args['mode']
				),

			'priority' =>
				max(
					0,
					absint(
						$args['priority']
					)
				),

			'overwrite' =>
				! empty(
					$args['overwrite']
				),

			'only_empty' =>
				! empty(
					$args['only_empty']
				),

			'created_by' =>
				absint(
					$args['created_by']
				),

			'created_at' =>
				$now,

			'started_at' =>
				0,

			'completed_at' =>
				0,

			'updated_at' =>
				$now,

			'locked_at' =>
				0,

			'attempts' =>
				0,

			'max_attempts' =>
				3,

			'error' =>
				'',

			'result_id' =>
				'',

			'sources' =>
				is_array(
					$args['sources']
				)
					? $args['sources']
					: array(),

			'fields' =>
				is_array(
					$args['fields']
				)
					? $args['fields']
					: array(),

			'notes' =>
				sanitize_textarea_field(
					$args['notes']
				),

			'metadata' =>
				is_array(
					$args['metadata']
				)
					? $args['metadata']
					: array(),
		);

		$queue =
			$this->get_all();

		$queue[] = $job;

		if (
			! $this->save(
				$queue
			)
		) {
			return false;
		}

		return $id;
	}

	/**
	 * Get one job.
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

		if ( empty( $job_id ) ) {
			return null;
		}

		$queue =
			$this->get_all();

		foreach ( $queue as $job ) {

			if (
				isset(
					$job['id']
				) &&
				$job['id'] === $job_id
			) {

				return $this->normalize_job(
					$job
				);
			}
		}

		return null;
	}

	/**
	 * Get all jobs.
	 *
	 * @param array $statuses Optional statuses.
	 *
	 * @return array
	 */
	public function get_all(
		$statuses = array()
	) {

		$queue =
			get_option(
				$this->option_name,
				array()
			);

		if ( ! is_array( $queue ) ) {
			$queue = array();
		}

		$normalized = array();

		foreach ( $queue as $job ) {

			if ( ! is_array( $job ) ) {
				continue;
			}

			$normalized[] =
				$this->normalize_job(
					$job
				);
		}

		if ( empty( $statuses ) ) {
			return $normalized;
		}

		$statuses =
			array_map(
				array(
					$this,
					'sanitize_status',
				),
				$statuses
			);

		$result = array();

		foreach ( $normalized as $job ) {

			if (
				in_array(
					$job['status'],
					$statuses,
					true
				)
			) {

				$result[] =
					$job;
			}
		}

		return $result;
	}

	/**
	 * Update a job.
	 *
	 * @param string $job_id Job ID.
	 * @param array  $data   Data.
	 *
	 * @return bool
	 */
	public function update(
		$job_id,
		$data
	) {

		$job_id =
			sanitize_text_field(
				$job_id
			);

		if (
			empty( $job_id ) ||
			! is_array( $data )
		) {
			return false;
		}

		$queue =
			$this->get_all();

		$found = false;

		foreach ( $queue as $index => $job ) {

			if (
				! isset(
					$job['id']
				) ||
				$job['id'] !== $job_id
			) {
				continue;
			}

			$data =
				$this->sanitize_update_data(
					$data
				);

			$data['updated_at'] =
				current_time(
					'timestamp'
				);

			$queue[ $index ] =
				wp_parse_args(
					$data,
					$job
				);

			$queue[ $index ] =
				$this->normalize_job(
					$queue[ $index ]
				);

			$found = true;

			break;
		}

		if ( ! $found ) {
			return false;
		}

		return $this->save(
			$queue
		);
	}

	/**
	 * Start a job.
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
		 * Only pending jobs can start normally.
		 */
		if (
			'pending' !==
			$job['status']
		) {
			return false;
		}

		/**
		 * Prevent exceeding maximum attempts.
		 */
		if (
			$job['attempts'] >=
			$job['max_attempts']
		) {

			return $this->fail(
				$job_id,
				'Maximum research attempts exceeded.'
			);
		}

		$now =
			current_time(
				'timestamp'
			);

		return $this->update(
			$job_id,
			array(
				'status' =>
					'running',

				'started_at' =>
					$now,

				'locked_at' =>
					$now,

				'attempts' =>
					$job['attempts'] + 1,

				'error' =>
					'',
			)
		);
	}

	/**
	 * Complete a job.
	 *
	 * @param string $job_id   Job ID.
	 * @param string $result_id Optional Research Result ID.
	 *
	 * @return bool
	 */
	public function complete(
		$job_id,
		$result_id = ''
	) {

		$data = array(
			'status' =>
				'completed',

			'completed_at' =>
				current_time(
					'timestamp'
				),

			'locked_at' =>
				0,

			'error' =>
				'',
		);

		if ( ! empty( $result_id ) ) {

			$data['result_id'] =
				sanitize_text_field(
					$result_id
				);
		}

		return $this->update(
			$job_id,
			$data
		);
	}

	/**
	 * Fail a job.
	 *
	 * @param string $job_id Job ID.
	 * @param string $error  Error message.
	 *
	 * @return bool
	 */
	public function fail(
		$job_id,
		$error
	) {

		return $this->update(
			$job_id,
			array(
				'status' =>
					'failed',

				'locked_at' =>
					0,

				'error' =>
					sanitize_textarea_field(
						$error
					),
			)
		);
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
			in_array(
				$job['status'],
				array(
					'completed',
					'failed',
					'cancelled',
				),
				true
			)
		) {
			return false;
		}

		return $this->update(
			$job_id,
			array(
				'status' =>
					'cancelled',

				'locked_at' =>
					0,
			)
		);
	}

	/**
	 * Remove a job permanently.
	 *
	 * @param string $job_id Job ID.
	 *
	 * @return bool
	 */
	public function remove(
		$job_id
	) {

		$job_id =
			sanitize_text_field(
				$job_id
			);

		if ( empty( $job_id ) ) {
			return false;
		}

		$queue =
			$this->get_all();

		$new_queue = array();
		$removed   = false;

		foreach ( $queue as $job ) {

			if (
				isset(
					$job['id']
				) &&
				$job['id'] === $job_id
			) {

				$removed = true;

				continue;
			}

			$new_queue[] =
				$job;
		}

		if ( ! $removed ) {
			return false;
		}

		return $this->save(
			$new_queue
		);
	}

	/**
	 * Find jobs for a vehicle.
	 *
	 * @param int   $post_id  Vehicle ID.
	 * @param array $statuses Optional statuses.
	 *
	 * @return array
	 */
	public function find_by_post_id(
		$post_id,
		$statuses = array()
	) {

		$post_id =
			absint(
				$post_id
			);

		if ( ! $post_id ) {
			return array();
		}

		$jobs =
			$this->get_all(
				$statuses
			);

		$result = array();

		foreach ( $jobs as $job ) {

			if (
				isset(
					$job['post_id']
				) &&
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
	 * Get next pending job.
	 *
	 * Higher priority first.
	 *
	 * @return array|null
	 */
	public function get_next() {

		/**
		 * First recover stale jobs.
		 */
		$this->recover_stale_jobs();

		$jobs =
			$this->get_all(
				array(
					'pending',
				)
			);

		if ( empty( $jobs ) ) {
			return null;
		}

		usort(
			$jobs,
			function ( $a, $b ) {

				$priority_a =
					absint(
						$a['priority']
					);

				$priority_b =
					absint(
						$b['priority']
					);

				if (
					$priority_a ===
					$priority_b
				) {

					return
						absint(
							$a['created_at']
						)
						<=>
						absint(
							$b['created_at']
						);
				}

				return
					$priority_b
					<=>
					$priority_a;
			}
		);

		return $jobs[0];
	}

	/**
	 * Recover stale running jobs.
	 *
	 * A worker may die while a job is running.
	 * Such jobs should become pending again.
	 *
	 * @param int|null $timeout Timeout in seconds.
	 *
	 * @return int Number of recovered jobs.
	 */
	public function recover_stale_jobs(
		$timeout = null
	) {

		if ( null === $timeout ) {
			$timeout = $this->stale_timeout;
		}

		$timeout =
			max(
				60,
				absint(
					$timeout
				)
			);

		$now =
			current_time(
				'timestamp'
			);

		$queue =
			$this->get_all();

		$changed = false;
		$count   = 0;

		foreach ( $queue as $index => $job ) {

			if (
				'running' !==
				$job['status']
			) {
				continue;
			}

			$locked_at =
				absint(
					$job['locked_at']
				);

			if ( ! $locked_at ) {
				$locked_at =
					absint(
						$job['started_at']
					);
			}

			if ( ! $locked_at ) {
				continue;
			}

			if (
				( $now - $locked_at ) <
				$timeout
			) {
				continue;
			}

			/**
			 * If attempts are exhausted, permanently fail.
			 */
			if (
				$job['attempts'] >=
				$job['max_attempts']
			) {

				$queue[ $index ]['status'] =
					'failed';

				$queue[ $index ]['error'] =
					'Research worker timed out and maximum attempts were reached.';

			} else {

				$queue[ $index ]['status'] =
					'pending';

				$queue[ $index ]['error'] =
					'Previous research worker timed out. Job returned to queue.';
			}

			$queue[ $index ]['locked_at'] =
				0;

			$queue[ $index ]['updated_at'] =
				$now;

			$changed = true;
			$count++;
		}

		if ( $changed ) {

			$this->save(
				$queue
			);
		}

		return $count;
	}

	/**
	 * Clear completed / failed / cancelled jobs.
	 *
	 * @return bool
	 */
	public function clear_finished() {

		$queue =
			$this->get_all();

		$new_queue = array();

		foreach ( $queue as $job ) {

			if (
				in_array(
					$job['status'],
					array(
						'completed',
						'failed',
						'cancelled',
					),
					true
				)
			) {
				continue;
			}

			$new_queue[] =
				$job;
		}

		return $this->save(
			$new_queue
		);
	}

	/**
	 * Count jobs.
	 *
	 * @param string $status Optional status.
	 *
	 * @return int
	 */
	public function count(
		$status = ''
	) {

		if ( empty( $status ) ) {

			return count(
				$this->get_all()
			);
		}

		return count(
			$this->get_all(
				array(
					$status,
				)
			)
		);
	}

	/**
	 * Get queue statistics.
	 *
	 * @return array
	 */
	public function get_stats() {

		$jobs =
			$this->get_all();

		$stats = array(
			'total' =>
				count(
					$jobs
				),

			'pending' =>
				0,

			'running' =>
				0,

			'completed' =>
				0,

			'failed' =>
				0,

			'cancelled' =>
				0,
		);

		foreach ( $jobs as $job ) {

			$status =
				$job['status'];

			if (
				isset(
					$stats[ $status ]
				)
			) {

				$stats[ $status ]++;
			}
		}

		return $stats;
	}

	/**
	 * Save queue.
	 *
	 * @param array $queue Queue.
	 *
	 * @return bool
	 */
	private function save(
		$queue
	) {

		if ( ! is_array( $queue ) ) {
			return false;
		}

		return update_option(
			$this->option_name,
			array_values(
				$queue
			),
			false
		);
	}

	/**
	 * Normalize job.
	 *
	 * Provides backward compatibility with
	 * jobs created by previous versions.
	 *
	 * @param array $job Job.
	 *
	 * @return array
	 */
	private function normalize_job(
		$job
	) {

		$defaults = array(
			'schema_version' =>
				$this->schema_version,

			'id' =>
				'',

			'post_id' =>
				0,

			'status' =>
				'pending',

			'mode' =>
				'review',

			'priority' =>
				10,

			'overwrite' =>
				false,

			'only_empty' =>
				false,

			'created_by' =>
				0,

			'created_at' =>
				0,

			'started_at' =>
				0,

			'completed_at' =>
				0,

			'updated_at' =>
				0,

			'locked_at' =>
				0,

			'attempts' =>
				0,

			'max_attempts' =>
				3,

			'error' =>
				'',

			'result_id' =>
				'',

			'sources' =>
				array(),

			'fields' =>
				array(),

			'notes' =>
				'',

			'metadata' =>
				array(),
		);

		$job =
			wp_parse_args(
				$job,
				$defaults
			);

		$job['schema_version'] =
			sanitize_text_field(
				$job['schema_version']
			);

		$job['id'] =
			sanitize_text_field(
				$job['id']
			);

		$job['post_id'] =
			absint(
				$job['post_id']
			);

		$job['status'] =
			$this->sanitize_status(
				$job['status']
			);

		$job['mode'] =
			$this->sanitize_mode(
				$job['mode']
			);

		$job['priority'] =
			max(
				0,
				absint(
					$job['priority']
				)
			);

		$job['created_by'] =
			absint(
				$job['created_by']
			);

		$job['created_at'] =
			absint(
				$job['created_at']
			);

		$job['started_at'] =
			absint(
				$job['started_at']
			);

		$job['completed_at'] =
			absint(
				$job['completed_at']
			);

		$job['updated_at'] =
			absint(
				$job['updated_at']
			);

		$job['locked_at'] =
			absint(
				$job['locked_at']
			);

		$job['attempts'] =
			absint(
				$job['attempts']
			);

		$job['max_attempts'] =
			max(
				1,
				absint(
					$job['max_attempts']
				)
			);

		$job['overwrite'] =
			! empty(
				$job['overwrite']
			);

		$job['only_empty'] =
			! empty(
				$job['only_empty']
			);

		$job['error'] =
			sanitize_textarea_field(
				$job['error']
			);

		$job['result_id'] =
			sanitize_text_field(
				$job['result_id']
			);

		$job['notes'] =
			sanitize_textarea_field(
				$job['notes']
			);

		$job['sources'] =
			is_array(
				$job['sources']
			)
				? $job['sources']
				: array();

		$job['fields'] =
			is_array(
				$job['fields']
			)
				? $job['fields']
				: array();

		$job['metadata'] =
			is_array(
				$job['metadata']
			)
				? $job['metadata']
				: array();

		return $job;
	}

	/**
	 * Sanitize update data.
	 *
	 * @param array $data Update data.
	 *
	 * @return array
	 */
	private function sanitize_update_data(
		$data
	) {

		$allowed = array(
			'status',
			'mode',
			'priority',
			'overwrite',
			'only_empty',
			'started_at',
			'completed_at',
			'locked_at',
			'attempts',
			'max_attempts',
			'error',
			'result_id',
			'sources',
			'fields',
			'notes',
			'metadata',
		);

		$result = array();

		foreach ( $allowed as $key ) {

			if (
				! array_key_exists(
					$key,
					$data
				)
			) {
				continue;
			}

			$result[ $key ] =
				$data[ $key ];
		}

		if (
			isset(
				$result['status']
			)
		) {

			$result['status'] =
				$this->sanitize_status(
					$result['status']
				);
		}

		if (
			isset(
				$result['mode']
			)
		) {

			$result['mode'] =
				$this->sanitize_mode(
					$result['mode']
				);
		}

		if (
			isset(
				$result['priority']
			)
		) {

			$result['priority'] =
				max(
					0,
					absint(
						$result['priority']
					)
				);
		}

		foreach (
			array(
				'started_at',
				'completed_at',
				'locked_at',
				'attempts',
				'max_attempts',
			) as $key
		) {

			if (
				isset(
					$result[ $key ]
				)
			) {

				$result[ $key ] =
					absint(
						$result[ $key ]
					);
			}
		}

		foreach (
			array(
				'overwrite',
				'only_empty',
			) as $key
		) {

			if (
				isset(
					$result[ $key ]
				)
			) {

				$result[ $key ] =
					! empty(
						$result[ $key ]
					);
			}
		}

		if (
			isset(
				$result['error']
			)
		) {

			$result['error'] =
				sanitize_textarea_field(
					$result['error']
				);
		}

		if (
			isset(
				$result['result_id']
			)
		) {

			$result['result_id'] =
				sanitize_text_field(
					$result['result_id']
				);
		}

		if (
			isset(
				$result['notes']
			)
		) {

			$result['notes'] =
				sanitize_textarea_field(
					$result['notes']
				);
		}

		if (
			isset(
				$result['sources']
			) &&
			! is_array(
				$result['sources']
			)
		) {

			$result['sources'] =
				array();
		}

		if (
			isset(
				$result['fields']
			) &&
			! is_array(
				$result['fields']
			)
		) {

			$result['fields'] =
				array();
		}

		if (
			isset(
				$result['metadata']
			) &&
			! is_array(
				$result['metadata']
			)
		) {

			$result['metadata'] =
				array();
		}

		return $result;
	}

	/**
	 * Generate unique job ID.
	 *
	 * @return string
	 */
	private function generate_id() {

		return 'job_' .
			wp_generate_uuid4();
	}

	/**
	 * Sanitize job status.
	 *
	 * @param string $status Status.
	 *
	 * @return string
	 */
	private function sanitize_status(
		$status
	) {

		$status =
			sanitize_key(
				$status
			);

		$allowed = array(
			'pending',
			'running',
			'completed',
			'failed',
			'cancelled',
		);

		if (
			! in_array(
				$status,
				$allowed,
				true
			)
		) {

			return 'pending';
		}

		return $status;
	}

	/**
	 * Sanitize execution mode.
	 *
	 * These modes represent workflow intent.
	 *
	 * @param string $mode Mode.
	 *
	 * @return string
	 */
	private function sanitize_mode(
		$mode
	) {

		$mode =
			sanitize_key(
				$mode
			);

		$allowed = array(
			'auto',
			'review',
			'ignore',
		);

		if (
			! in_array(
				$mode,
				$allowed,
				true
			)
		) {

			return 'review';
		}

		return $mode;
	}

}
