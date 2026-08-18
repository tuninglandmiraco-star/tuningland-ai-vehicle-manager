<?php
/**
 * Logger
 *
 * Central logging system for Tuningland AI Vehicle Manager.
 *
 * Supported modules:
 * - core
 * - cpt
 * - acf
 * - schema
 * - analyzer
 * - research
 * - source
 * - validation
 * - confidence
 * - review
 * - writer
 * - bulk
 * - admin
 * - ai
 *
 * The current implementation stores logs in a WordPress option.
 * The public API is intentionally isolated so the storage layer
 * can later be migrated to a dedicated database table.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TL_AI_VM_Logger {

	/**
	 * Singleton instance.
	 *
	 * @var TL_AI_VM_Logger|null
	 */
	private static $instance = null;

	/**
	 * Option name.
	 */
	const OPTION_NAME = 'tl_ai_vm_logs';

	/**
	 * Maximum number of stored log entries.
	 */
	const MAX_LOGS = 500;

	/**
	 * Allowed log levels.
	 *
	 * @var array
	 */
	private $allowed_levels = array(
		'debug',
		'info',
		'success',
		'warning',
		'error',
	);

	/**
	 * Get singleton instance.
	 *
	 * @return TL_AI_VM_Logger
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
	 * Write a log entry.
	 *
	 * @param string $message Log message.
	 * @param string $level   Log level.
	 * @param string $module  Module name.
	 * @param array  $context Additional context.
	 *
	 * @return bool
	 */
	public function log(
		$message,
		$level = 'info',
		$module = 'core',
		$context = array()
	) {

		$level = sanitize_key(
			$level
		);

		if (
			! in_array(
				$level,
				$this->allowed_levels,
				true
			)
		) {
			$level = 'info';
		}

		$module = sanitize_key(
			$module
		);

		if ( empty( $module ) ) {
			$module = 'core';
		}

		$message = wp_strip_all_tags(
			(string) $message
		);

		$message = trim(
			$message
		);

		if ( '' === $message ) {
			return false;
		}

		if ( ! is_array( $context ) ) {
			$context = array();
		}

		$logs = get_option(
			self::OPTION_NAME,
			array()
		);

		if ( ! is_array( $logs ) ) {
			$logs = array();
		}

		$entry = array(
			'id' => wp_generate_uuid4(),

			'timestamp' => current_time(
				'c',
				true
			),

			'timestamp_local' => current_time(
				'mysql'
			),

			'level' => $level,

			'module' => $module,

			'message' => $message,

			'context' => $this->sanitize_context(
				$context
			),

			'user_id' => get_current_user_id(),

			'request' => $this->get_request_context(),
		);

		/**
		 * Newest entries are stored first.
		 */
		array_unshift(
			$logs,
			$entry
		);

		/**
		 * Keep the option bounded.
		 */
		if (
			count( $logs ) >
			self::MAX_LOGS
		) {

			$logs = array_slice(
				$logs,
				0,
				self::MAX_LOGS
			);
		}

		return update_option(
			self::OPTION_NAME,
			$logs,
			false
		);
	}

	/**
	 * Debug log.
	 *
	 * @param string $message
	 * @param string $module
	 * @param array  $context
	 *
	 * @return bool
	 */
	public function debug(
		$message,
		$module = 'core',
		$context = array()
	) {

		return $this->log(
			$message,
			'debug',
			$module,
			$context
		);
	}

	/**
	 * Info log.
	 *
	 * @param string $message
	 * @param string $module
	 * @param array  $context
	 *
	 * @return bool
	 */
	public function info(
		$message,
		$module = 'core',
		$context = array()
	) {

		return $this->log(
			$message,
			'info',
			$module,
			$context
		);
	}

	/**
	 * Success log.
	 *
	 * @param string $message
	 * @param string $module
	 * @param array  $context
	 *
	 * @return bool
	 */
	public function success(
		$message,
		$module = 'core',
		$context = array()
	) {

		return $this->log(
			$message,
			'success',
			$module,
			$context
		);
	}

	/**
	 * Warning log.
	 *
	 * @param string $message
	 * @param string $module
	 * @param array  $context
	 *
	 * @return bool
	 */
	public function warning(
		$message,
		$module = 'core',
		$context = array()
	) {

		return $this->log(
			$message,
			'warning',
			$module,
			$context
		);
	}

	/**
	 * Error log.
	 *
	 * @param string $message
	 * @param string $module
	 * @param array  $context
	 *
	 * @return bool
	 */
	public function error(
		$message,
		$module = 'core',
		$context = array()
	) {

		return $this->log(
			$message,
			'error',
			$module,
			$context
		);
	}

	/**
	 * Get logs.
	 *
	 * Supported filters:
	 *
	 * level
	 * module
	 * limit
	 *
	 * @param array $args Filter arguments.
	 *
	 * @return array
	 */
	public function get_logs(
		$args = array()
	) {

		$logs = $this->get_all_logs();

		$defaults = array(
			'level'  => '',
			'module' => '',
			'limit'  => 100,
		);

		$args = wp_parse_args(
			$args,
			$defaults
		);

		$level = sanitize_key(
			$args['level']
		);

		$module = sanitize_key(
			$args['module']
		);

		$limit = absint(
			$args['limit']
		);

		$result = array();

		foreach ( $logs as $log ) {

			if (
				! empty( $level ) &&
				(
					empty( $log['level'] ) ||
					$log['level'] !== $level
				)
			) {
				continue;
			}

			if (
				! empty( $module ) &&
				(
					empty( $log['module'] ) ||
					$log['module'] !== $module
				)
			) {
				continue;
			}

			$result[] = $log;

			if (
				$limit > 0 &&
				count( $result ) >= $limit
			) {
				break;
			}
		}

		return $result;
	}

	/**
	 * Get one log by ID.
	 *
	 * @param string $id Log UUID.
	 *
	 * @return array|null
	 */
	public function get_log(
		$id
	) {

		$id = sanitize_text_field(
			$id
		);

		if ( empty( $id ) ) {
			return null;
		}

		$logs = $this->get_all_logs();

		foreach ( $logs as $log ) {

			if (
				isset( $log['id'] ) &&
				$log['id'] === $id
			) {
				return $log;
			}
		}

		return null;
	}

	/**
	 * Get all logs.
	 *
	 * @return array
	 */
	private function get_all_logs() {

		$logs = get_option(
			self::OPTION_NAME,
			array()
		);

		if ( ! is_array( $logs ) ) {
			return array();
		}

		return $logs;
	}

	/**
	 * Get log statistics.
	 *
	 * @return array
	 */
	public function get_stats() {

		$logs = $this->get_all_logs();

		$stats = array(
			'total'   => count( $logs ),
			'debug'   => 0,
			'info'    => 0,
			'success' => 0,
			'warning' => 0,
			'error'   => 0,
		);

		foreach ( $logs as $log ) {

			if (
				isset( $log['level'] ) &&
				isset( $stats[ $log['level'] ] )
			) {

				$stats[
					$log['level']
				]++;
			}
		}

		return $stats;
	}

	/**
	 * Get available modules from stored logs.
	 *
	 * @return array
	 */
	public function get_modules() {

		$logs = $this->get_all_logs();

		$modules = array();

		foreach ( $logs as $log ) {

			if (
				empty( $log['module'] )
			) {
				continue;
			}

			$module = sanitize_key(
				$log['module']
			);

			if ( ! in_array(
				$module,
				$modules,
				true
			) ) {

				$modules[] = $module;
			}
		}

		sort(
			$modules
		);

		return $modules;
	}

	/**
	 * Get recent errors.
	 *
	 * @param int $limit Number of entries.
	 *
	 * @return array
	 */
	public function get_recent_errors(
		$limit = 20
	) {

		return $this->get_logs(
			array(
				'level' => 'error',
				'limit' => absint( $limit ),
			)
		);
	}

	/**
	 * Clear all logs.
	 *
	 * @return bool
	 */
	public function clear() {

		return delete_option(
			self::OPTION_NAME
		);
	}

	/**
	 * Clear logs belonging to one module.
	 *
	 * @param string $module Module name.
	 *
	 * @return bool
	 */
	public function clear_module(
		$module
	) {

		$module = sanitize_key(
			$module
		);

		if ( empty( $module ) ) {
			return false;
		}

		$logs = $this->get_all_logs();

		if ( empty( $logs ) ) {
			return true;
		}

		$remaining = array();

		foreach ( $logs as $log ) {

			if (
				isset( $log['module'] ) &&
				$log['module'] === $module
			) {
				continue;
			}

			$remaining[] = $log;
		}

		return update_option(
			self::OPTION_NAME,
			$remaining,
			false
		);
	}

	/**
	 * Get request context.
	 *
	 * Avoid storing sensitive request data.
	 *
	 * @return array
	 */
	private function get_request_context() {

		$request = array(
			'method' => '',
			'uri'    => '',
		);

		if (
			isset(
				$_SERVER['REQUEST_METHOD']
			)
		) {

			$request['method'] =
				sanitize_text_field(
					wp_unslash(
						$_SERVER['REQUEST_METHOD']
					)
				);
		}

		if (
			isset(
				$_SERVER['REQUEST_URI']
			)
		) {

			$request['uri'] =
				sanitize_text_field(
					wp_unslash(
						$_SERVER['REQUEST_URI']
					)
				);
		}

		return $request;
	}

	/**
	 * Sanitize context recursively.
	 *
	 * @param mixed $value Context value.
	 *
	 * @return mixed
	 */
	private function sanitize_context(
		$value
	) {

		if ( is_array( $value ) ) {

			$result = array();

			foreach (
				$value as $key => $item
			) {

				$key = $this->sanitize_context_key(
					$key
				);

				$result[ $key ] =
					$this->sanitize_context(
						$item
					);
			}

			return $result;
		}

		if ( is_object( $value ) ) {

			return $this->sanitize_context(
				(array) $value
			);
		}

		if (
			is_bool( $value ) ||
			is_null( $value )
		) {

			return $value;
		}

		if ( is_int( $value ) ) {
			return $value;
		}

		if ( is_float( $value ) ) {
			return $value;
		}

		return sanitize_textarea_field(
			(string) $value
		);
	}

	/**
	 * Sanitize context array keys.
	 *
	 * @param mixed $key Context key.
	 *
	 * @return int|string
	 */
	private function sanitize_context_key(
		$key
	) {

		if ( is_int( $key ) ) {
			return $key;
		}

		$key = (string) $key;

		/**
		 * Keep common developer-friendly characters.
		 * This is intentionally less aggressive than sanitize_key().
		 */
		$key = preg_replace(
			'/[^a-zA-Z0-9_\-\.]/',
			'_',
			$key
		);

		$key = trim(
			$key,
			'_'
		);

		if ( '' === $key ) {
			$key = 'value';
		}

		return substr(
			$key,
			0,
			100
		);
	}
}
