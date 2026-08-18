<?php
/**
 * Research Approval System
 *
 * Controls human approval/rejection of research results before
 * any future ACF write operation.
 *
 * This layer does NOT write to ACF.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TL_AI_VM_Research_Approval {

	private static $instance = null;

	const VERSION = '1.0.0';

	const STATUS_PENDING  = 'pending';
	const STATUS_APPROVED = 'approved';
	const DEFAULT_AUTO_THRESHOLD = 90;
	const STATUS_REJECTED = 'rejected';

	const OPTION_PREFIX = 'tl_ai_vm_approval_';

	public static function instance() {

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
	}

	/**
	 * Create an approval record from a research result.
	 *
	 * @param array $research_result Research result.
	 * @return array
	 */
	public function create( $research_result ) {

		if ( ! is_array( $research_result ) ) {
			return array(
				'success' => false,
				'error'   => 'Invalid research result.',
			);
		}

		$id = $this->get_research_id( $research_result );

		if ( empty( $id ) ) {
			$id = wp_generate_uuid4();
		}

		$existing = $this->get( $id );

		if ( $existing ) {
			return $existing;
		}

		$decision = isset( $research_result['decision']['decision'] )
			? sanitize_key( $research_result['decision']['decision'] )
			: '';

		$status = self::STATUS_PENDING;

		$confidence_percentage = 0;
		if ( isset( $research_result['confidence']['percentage'] ) && is_numeric( $research_result['confidence']['percentage'] ) ) {
			$confidence_percentage = (int) $research_result['confidence']['percentage'];
		} elseif ( isset( $research_result['confidence']['score'] ) && is_numeric( $research_result['confidence']['score'] ) ) {
			$confidence_percentage = (int) round( (float) $research_result['confidence']['score'] * 100 );
		}

		$decision = isset( $research_result['decision']['decision'] )
			? sanitize_key( $research_result['decision']['decision'] )
			: '';

		$record = array(
			'success' => true,
			'id'      => $id,
			'version' => self::VERSION,
			'status'  => $status,
			'decision' => $decision,
			'research_id' => $id,
			'created_at' => current_time( 'c', true ),
			'updated_at' => current_time( 'c', true ),
			'created_by' => get_current_user_id(),
			'approved_by' => 0,
			'rejected_by' => 0,
			'approved_at' => null,
			'rejected_at' => null,
			'note' => '',
			'confidence' => isset( $research_result['confidence'] )
				? $research_result['confidence']
				: array(),
			'confidence_percentage' => $confidence_percentage,
			'vehicle_title' => isset( $research_result['vehicle']['title'] )
				? sanitize_text_field( $research_result['vehicle']['title'] )
				: ( isset( $research_result['vehicle_title'] ) ? sanitize_text_field( $research_result['vehicle_title'] ) : '' ),
			'field_label' => isset( $research_result['field']['label'] )
				? sanitize_text_field( $research_result['field']['label'] )
				: '',
			'post_id' => isset( $research_result['vehicle']['post_id'] )
				? absint( $research_result['vehicle']['post_id'] )
				: ( isset( $research_result['post_id'] ) ? absint( $research_result['post_id'] ) : 0 ),
			'field_key' => isset( $research_result['field']['key'] )
				? sanitize_text_field( $research_result['field']['key'] )
				: ( isset( $research_result['field_key'] ) ? sanitize_text_field( $research_result['field_key'] ) : '' ),
		);

		$this->save( $id, $record );

		/**
		 * High-confidence AUTO results are approved immediately.
		 * The final ACF write is still performed by approve_and_write()
		 * and therefore passes through the same writer safeguards.
		 */
		if (
			'auto' === $decision &&
			$confidence_percentage >= $this->get_auto_threshold() &&
			! $this->research_has_conflict( $research_result )
		) {
			$this->approve_and_write( $id, true );
			$record = $this->get( $id );
		}

		$this->log(
			'Research approval record created.',
			array(
				'id' => $id,
				'decision' => $decision,
			)
		);

		return $record;
	}

	/**
	 * Approve a research result.
	 *
	 * @param string $id   Approval/research ID.
	 * @param string $note Optional note.
	 * @return array
	 */
	public function approve( $id, $note = '' ) {

		if ( ! current_user_can( 'manage_options' ) ) {
			return array(
				'success' => false,
				'error'   => 'Permission denied.',
			);
		}

		$id = sanitize_text_field( $id );

		if ( empty( $id ) ) {
			return array(
				'success' => false,
				'error'   => 'Approval ID is required.',
			);
		}

		$record = $this->get( $id );

		if ( ! $record ) {
			return array(
				'success' => false,
				'error'   => 'Approval record not found.',
			);
		}

		$record['status'] = self::STATUS_APPROVED;
		$record['approved_by'] = get_current_user_id();
		$record['approved_at'] = current_time( 'c', true );
		$record['updated_at'] = current_time( 'c', true );
		$record['note'] = sanitize_textarea_field( $note );

		$this->save( $id, $record );

		$this->log(
			'Research approved.',
			array(
				'id' => $id,
				'user_id' => get_current_user_id(),
			)
		);

		return $record;
	}

	/**
	 * Approve a research result and write its normalized value to ACF.
	 *
	 * @param string $id       Approval/research ID.
	 * @param bool   $automatic Whether approval was automatic.
	 * @return array
	 */
	public function approve_and_write( $id, $automatic = false ) {

		$id = sanitize_text_field( $id );

		if ( empty( $id ) ) {
			return array( 'success' => false, 'error' => 'Approval ID is required.' );
		}

		if ( ! $automatic && ! current_user_can( 'manage_options' ) ) {
			return array( 'success' => false, 'error' => 'Permission denied.' );
		}

		$record = $this->get( $id );

		if ( ! $record ) {
			return array( 'success' => false, 'error' => 'Approval record not found.' );
		}

		$research_id = ! empty( $record['research_id'] ) ? $record['research_id'] : $id;
		$research = class_exists( 'TL_AI_VM_Research_Result' )
			? TL_AI_VM_Research_Result::instance()->get( $research_id )
			: null;

		if ( ! is_array( $research ) ) {
			return array( 'success' => false, 'error' => 'Research result not found.' );
		}

		if ( ! $automatic ) {
			$record['status'] = self::STATUS_APPROVED;
			$record['approved_by'] = get_current_user_id();
			$record['approved_at'] = current_time( 'c', true );
			$record['updated_at'] = current_time( 'c', true );
		}

		$writer_result = array( 'success' => false, 'error' => 'ACF writer is not available.' );

		if ( class_exists( 'TL_AI_VM_ACF_Writer' ) ) {
			$writer = TL_AI_VM_ACF_Writer::instance();
			$writer_result = $writer->write(
				array(
					'post_id'         => isset( $research['vehicle']['post_id'] ) ? absint( $research['vehicle']['post_id'] ) : ( isset( $record['post_id'] ) ? absint( $record['post_id'] ) : 0 ),
					'post_type'       => isset( $research['vehicle']['post_type'] ) ? sanitize_key( $research['vehicle']['post_type'] ) : '',
					'field_key'       => isset( $research['field']['key'] ) ? sanitize_text_field( $research['field']['key'] ) : ( isset( $record['field_key'] ) ? sanitize_text_field( $record['field_key'] ) : '' ),
					'field_name'      => isset( $research['field']['name'] ) ? sanitize_key( $research['field']['name'] ) : '',
					'value'           => isset( $research['normalized_value'] ) ? $research['normalized_value'] : null,
					'approved'        => true,
					'approval_status' => 'approved',
				)
			);
		}

		if ( empty( $writer_result['success'] ) ) {
			return array(
				'success' => false,
				'error'   => isset( $writer_result['error'] ) ? $writer_result['error'] : 'ACF write failed.',
				'approval' => $record,
				'writer' => $writer_result,
			);
		}

		$record['status'] = self::STATUS_APPROVED;
		$record['approved_by'] = $automatic ? 0 : get_current_user_id();
		$record['approved_at'] = $automatic ? current_time( 'c', true ) : ( $record['approved_at'] ?? current_time( 'c', true ) );
		$record['updated_at'] = current_time( 'c', true );
		$record['automatic'] = (bool) $automatic;
		$this->save( $id, $record );

		if ( class_exists( 'TL_AI_VM_Learning_Memory' ) ) {
			TL_AI_VM_Learning_Memory::instance()->remember_approval( $research, $record );
		}

		if ( class_exists( 'TL_AI_VM_Research_Result' ) ) {
			TL_AI_VM_Research_Result::instance()->update(
				$research_id,
				array(
					'status' => 'approved',
					'approval' => array(
						'status' => 'approved',
						'user_id' => $automatic ? 0 : get_current_user_id(),
						'approved_at' => $record['approved_at'],
					),
				'metadata' => array(
						'written_to_acf' => true,
						'written_at' => current_time( 'c', true ),
					),
				)
			);
		}

		$this->log(
			$automatic ? 'Research auto-approved and written to ACF.' : 'Research approved and written to ACF.',
			array( 'id' => $id, 'research_id' => $research_id, 'automatic' => (bool) $automatic )
		);

		return array(
			'success' => true,
			'approval' => $record,
			'writer' => $writer_result,
		);
	}

		/** Correct, write and remember a human-supplied value. */
	public function correct_and_write( $id, $value, $note = '', $source = '' ) {
		if ( ! current_user_can( 'manage_options' ) ) { return array( 'success'=>false, 'error'=>'Permission denied.' ); }
		$id=sanitize_text_field($id); $record=$this->get($id); if(!$record){return array('success'=>false,'error'=>'Approval record not found.');}
		$rid=!empty($record['research_id'])?$record['research_id']:$id; $research=class_exists('TL_AI_VM_Research_Result')?TL_AI_VM_Research_Result::instance()->get($rid):null; if(!is_array($research)){return array('success'=>false,'error'=>'Research result not found.');}
		$old=$research['normalized_value']??null; $new=is_array($value)?$value:sanitize_textarea_field((string)$value); $research['normalized_value']=$new; $research['value']=$new;
		if(class_exists('TL_AI_VM_Research_Result')){TL_AI_VM_Research_Result::instance()->update($rid,array('status'=>'corrected','normalized_value'=>$new,'value'=>$new,'correction'=>array('old_value'=>$old,'new_value'=>$new,'note'=>sanitize_textarea_field($note),'corrected_by'=>get_current_user_id(),'corrected_at'=>current_time('c',true))));}
		$writer=class_exists('TL_AI_VM_ACF_Writer')?TL_AI_VM_ACF_Writer::instance()->write(array('post_id'=>absint($research['vehicle']['post_id']??$record['post_id']??0),'post_type'=>sanitize_key($research['vehicle']['post_type']??''),'field_key'=>sanitize_text_field($research['field']['key']??$record['field_key']??''),'field_name'=>sanitize_key($research['field']['name']??''),'value'=>$new,'approved'=>true,'approval_status'=>'approved')):array('success'=>false,'error'=>'ACF writer unavailable.');
		if(empty($writer['success'])){return array('success'=>false,'error'=>$writer['error']??'ACF write failed.');}
		$record['status']=self::STATUS_APPROVED; $record['approved_by']=get_current_user_id(); $record['approved_at']=current_time('c',true); $record['updated_at']=current_time('c',true); $record['note']=sanitize_textarea_field($note); $record['correction']=array('old_value'=>$old,'new_value'=>$new,'preferred_source'=>esc_url_raw($source),'corrected_by'=>get_current_user_id(),'corrected_at'=>current_time('c',true)); $this->save($id,$record);
		if(class_exists('TL_AI_VM_Learning_Memory')){TL_AI_VM_Learning_Memory::instance()->remember_approval($research,$record);} if(method_exists(TL_AI_VM_Learning_Memory::instance(),'remember_correction')){TL_AI_VM_Learning_Memory::instance()->remember_correction($research,$new,$note,$source);}
		return array('success'=>true,'corrected'=>true,'approval'=>$record,'writer'=>$writer);
	}

/**
	 * Reject a research result.
	 *
	 * @param string $id   Approval/research ID.
	 * @param string $note Optional note.
	 * @return array
	 */
	public function reject( $id, $note = '' ) {

		if ( ! current_user_can( 'manage_options' ) ) {
			return array(
				'success' => false,
				'error'   => 'Permission denied.',
			);
		}

		$id = sanitize_text_field( $id );

		if ( empty( $id ) ) {
			return array(
				'success' => false,
				'error'   => 'Approval ID is required.',
			);
		}

		$record = $this->get( $id );

		if ( ! $record ) {
			return array(
				'success' => false,
				'error'   => 'Approval record not found.',
			);
		}

		$record['status'] = self::STATUS_REJECTED;
		$record['rejected_by'] = get_current_user_id();
		$record['rejected_at'] = current_time( 'c', true );
		$record['updated_at'] = current_time( 'c', true );
		$record['note'] = sanitize_textarea_field( $note );

		$this->save( $id, $record );

		if ( class_exists( 'TL_AI_VM_Learning_Memory' ) && class_exists( 'TL_AI_VM_Research_Result' ) ) {
			$research = TL_AI_VM_Research_Result::instance()->get( $id );
			if ( is_array( $research ) ) { TL_AI_VM_Learning_Memory::instance()->remember_rejection( $research, $note ); }
		}

		$this->log(
			'Research rejected.',
			array(
				'id' => $id,
				'user_id' => get_current_user_id(),
			)
		);

		return $record;
	}

	/**
	 * Reset an approval back to pending.
	 *
	 * @param string $id ID.
	 * @return array
	 */
	public function reset( $id ) {

		if ( ! current_user_can( 'manage_options' ) ) {
			return array(
				'success' => false,
				'error'   => 'Permission denied.',
			);
		}

		$record = $this->get( $id );

		if ( ! $record ) {
			return array(
				'success' => false,
				'error'   => 'Approval record not found.',
			);
		}

		$record['status'] = self::STATUS_PENDING;
		$record['approved_by'] = 0;
		$record['rejected_by'] = 0;
		$record['approved_at'] = null;
		$record['rejected_at'] = null;
		$record['updated_at'] = current_time( 'c', true );

		$this->save( $id, $record );

		return $record;
	}

	/**
	 * Check whether a result is approved.
	 *
	 * @param string $id ID.
	 * @return bool
	 */
	public function is_approved( $id ) {

		$record = $this->get( $id );

		return (
			is_array( $record ) &&
			isset( $record['status'] ) &&
			self::STATUS_APPROVED === $record['status']
		);
	}

	/**
	 * Get an approval record.
	 *
	 * @param string $id ID.
	 * @return array|null
	 */
	public function get( $id ) {

		$id = sanitize_text_field( $id );

		if ( empty( $id ) ) {
			return null;
		}

		$record = get_option(
			self::OPTION_PREFIX . md5( $id ),
			false
		);

		if ( ! is_array( $record ) ) {
			return null;
		}

		return $record;
	}

	/**
	 * List approval records.
	 *
	 * @param string $status Optional status filter.
	 * @param int    $limit  Maximum records.
	 * @return array
	 */
	public function get_records( $status = '', $limit = 100 ) {

		global $wpdb;

		$prefix = self::OPTION_PREFIX;
		$like   = $wpdb->esc_like( $prefix ) . '%';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options}
				 WHERE option_name LIKE %s
				 ORDER BY option_id DESC
				 LIMIT %d",
				$like,
				max( 1, min( 500, (int) $limit ) )
			),
			ARRAY_A
		);

		$records = array();

		if ( ! is_array( $rows ) ) {
			return $records;
		}

		foreach ( $rows as $row ) {

			if ( empty( $row['option_value'] ) ) {
				continue;
			}

			$record = maybe_unserialize( $row['option_value'] );

			if ( ! is_array( $record ) ) {
				continue;
			}

			if (
				! empty( $status ) &&
				(
					! isset( $record['status'] ) ||
					$record['status'] !== $status
				)
			) {
				continue;
			}

			$records[] = $record;
		}

		return $records;
	}

	/**
	 * Save approval record.
	 *
	 * @param string $id     ID.
	 * @param array  $record Record.
	 * @return bool
	 */
	private function save( $id, $record ) {

		return update_option(
			self::OPTION_PREFIX . md5( $id ),
			$record,
			false
		);
	}

	/**
	 * Get research ID from supported result structures.
	 *
	 * @param array $research_result Research result.
	 * @return string
	 */
	private function get_research_id( $research_result ) {

		$keys = array(
			'id',
			'research_id',
			'result_id',
			'uuid',
		);

		foreach ( $keys as $key ) {

			if (
				isset( $research_result[ $key ] ) &&
				! empty( $research_result[ $key ] )
			) {
				return sanitize_text_field(
					$research_result[ $key ]
				);
			}
		}

		return '';
	}

	private function research_has_conflict( $research_result ) {

		$validation = isset( $research_result['validation'] ) && is_array( $research_result['validation'] )
			? $research_result['validation']
			: array();

		if ( ! empty( $validation['conflicts'] ) || ! empty( $validation['has_conflict'] ) ) {
			return true;
		}

		$issues = isset( $validation['issues'] ) && is_array( $validation['issues'] )
			? $validation['issues']
			: array();

		foreach ( $issues as $issue ) {
			$text = is_scalar( $issue ) ? strtolower( (string) $issue ) : '';
			if ( false !== strpos( $text, 'conflict' ) ) {
				return true;
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
					'approval',
					$context
				);
			}
		}
	}
    /**
     * Configurable automatic approval threshold.
     *
     * @return int
     */
    private function get_auto_threshold() {
        $value = absint( get_option( 'tl_ai_vm_auto_threshold', self::DEFAULT_AUTO_THRESHOLD ) );
        return max( 50, min( 100, $value ) );
    }

}
