<?php
/**
 * Vehicle Research Runner
 *
 * Orchestrates: semantic analysis -> web research -> AI extraction ->
 * intermediate Research Result -> validation -> confidence -> decision -> approval.
 * ACF is only written through the approval/writer layer.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TL_AI_VM_Vehicle_Research_Runner {
    private static $instance = null;
    const AUTO_THRESHOLD = 90;

    public static function instance() {
        if ( null === self::$instance ) { self::$instance = new self(); }
        return self::$instance;
    }
    private function __construct() {}

    public function run( $post_id, $args = array() ) {
        $post_id = absint( $post_id );
        $post = $post_id ? get_post( $post_id ) : null;
        if ( ! $post ) { return array( 'success' => false, 'error' => 'Vehicle post was not found.' ); }

        $selected = sanitize_key( get_option( 'tl_ai_vm_vehicle_cpt', '' ) );
        if ( $selected && $selected !== $post->post_type ) {
            return array( 'success' => false, 'error' => 'Vehicle does not belong to the selected Vehicle CPT.' );
        }
        if ( ! class_exists( 'TL_AI_VM_Research_Result' ) ) { return array( 'success' => false, 'error' => 'Research Result layer is unavailable.' ); }
        if ( ! class_exists( 'TL_AI_VM_Web_Research_Engine' ) ) { return array( 'success' => false, 'error' => 'Web Research Engine is unavailable.' ); }
        if ( ! class_exists( 'TL_AI_VM_AI_Field_Analyzer' ) ) { return array( 'success' => false, 'error' => 'AI Field Analyzer is unavailable.' ); }

        $args = wp_parse_args( $args, array(
            'only_empty' => true,
            'use_ai' => true,
            'max_fields' => 50,
            'search_limit' => 8,
            'selected_groups' => array(),
            'groups_filter_enabled' => false,
        ) );

        $analyzer = TL_AI_VM_AI_Field_Analyzer::instance();
        $analysis = $analyzer->analyze_vehicle( $post_id, array( 'use_ai' => ! empty( $args['use_ai'] ) ) );
        if ( empty( $analysis['success'] ) ) { return $analysis; }

        $fields = $analyzer->get_researchable_fields( $post->post_type );
        if ( ! is_array( $fields ) ) { $fields = array(); }
        if ( ! empty( $args['only_empty'] ) ) { $fields = $this->only_empty_fields( $post_id, $fields ); }
        if ( ! empty( $args['groups_filter_enabled'] ) ) {
            $selected_groups = array_map( 'sanitize_key', (array) $args['selected_groups'] );
            $fields = array_values( array_filter( $fields, function( $field ) use ( $selected_groups ) {
                return ! empty( $field['group_key'] ) && in_array( sanitize_key( $field['group_key'] ), $selected_groups, true );
            } ) );
        }
        if ( ! empty( $args['max_fields'] ) ) { $fields = array_slice( $fields, 0, absint( $args['max_fields'] ) ); }
        if ( empty( $fields ) ) { return array( 'success' => true, 'post_id' => $post_id, 'message' => 'No empty researchable fields were found.', 'created' => 0, 'auto_written' => 0, 'review' => 0 ); }

        $engine = TL_AI_VM_Web_Research_Engine::instance();
        $storage = TL_AI_VM_Research_Result::instance();
        $validator = class_exists( 'TL_AI_VM_Research_Validator' ) ? TL_AI_VM_Research_Validator::instance() : null;
        $confidence = class_exists( 'TL_AI_VM_Confidence' ) ? TL_AI_VM_Confidence::instance() : null;
        $decision_engine = class_exists( 'TL_AI_VM_Decision' ) ? TL_AI_VM_Decision::instance() : null;
        $approval = class_exists( 'TL_AI_VM_Research_Approval' ) ? TL_AI_VM_Research_Approval::instance() : null;
        $client = class_exists( 'TL_AI_VM_AI_Client' ) ? TL_AI_VM_AI_Client::instance() : null;

        $created = 0; $auto_written = 0; $review = 0; $ignored = 0; $errors = array();

        foreach ( $fields as $field ) {
            $field_key = ! empty( $field['key'] ) ? $field['key'] : ( ! empty( $field['name'] ) ? $field['name'] : '' );
            if ( ! $field_key ) { continue; }

            // Field Intelligence can route a field to internal data or disable it entirely.
            $field_rule = class_exists( 'TL_AI_VM_Field_Intelligence' ) ? TL_AI_VM_Field_Intelligence::instance()->get_rule( $field_key, ! empty($field['group_key']) ? $field['group_key'] : '' ) : array();
            if ( isset( $field_rule['mode'] ) && 'disabled' === $field_rule['mode'] ) { $ignored++; continue; }

            $internal_answer = null;
            if ( class_exists( 'TL_AI_VM_Field_Intelligence' ) && TL_AI_VM_Field_Intelligence::instance()->is_internal( $field ) ) {
                $internal_answer = TL_AI_VM_Field_Intelligence::instance()->resolve_internal_asset( $post_id, $field );
                if ( ! empty( $internal_answer['success'] ) ) {
                    $item = array(
                        'vehicle' => array( 'post_id'=>$post_id, 'post_type'=>$post->post_type, 'title'=>get_the_title($post_id) ),
                        'field' => array( 'key'=>!empty($field['key'])?$field['key']:'', 'name'=>!empty($field['name'])?$field['name']:'', 'label'=>!empty($field['label'])?$field['label']:'', 'type'=>!empty($field['type'])?$field['type']:'', 'parent'=>!empty($field['parent'])?$field['parent']:'' ),
                        'query' => 'Internal Tuningland asset lookup', 'raw_answer' => 'Resolved from the internal Media Library.', 'normalized_value'=>$internal_answer['value'],
                        'expected_data_type'=>'image', 'unit'=>'', 'sources'=>array(array('url'=>isset($internal_answer['url'])?$internal_answer['url']:'','title'=>'Tuningland Media Library','domain'=>wp_parse_url(home_url('/'),PHP_URL_HOST))),
                        'status'=>'researched', 'metadata'=>array('runner'=>'vehicle_research_runner','research_at'=>current_time('c',true),'method'=>isset($internal_answer['method'])?$internal_answer['method']:'internal_asset')
                    );
                    $result_id=$storage->create_or_update($item);
                    if(!$result_id){$errors[]=array('field'=>$field_key,'error'=>'Could not store internal asset result.');continue;}
                    $created++; $stored=$storage->get($result_id);
                    $validation_result=$validator?$validator->validate($stored,true):array('success'=>true);
                    $confidence_result=$confidence?$confidence->calculate($stored,$validation_result):array('success'=>true,'percentage'=>isset($internal_answer['confidence'])?(int)round($internal_answer['confidence']*100):95,'score'=>isset($internal_answer['confidence'])?(float)$internal_answer['confidence']:0.95);
                    if(!empty($confidence_result['success'])||isset($confidence_result['percentage'])){$storage->update($result_id,array('confidence'=>$confidence_result));}
                    $stored=$storage->get($result_id); $decision_result=$decision_engine?$decision_engine->decide($stored):array('decision'=>'auto'); $decision=!empty($decision_result['decision'])?$decision_result['decision']:'auto'; $storage->update($result_id,array('decision'=>$decision)); $stored=$storage->get($result_id);
                    if($approval){$approval_record=$approval->create($stored);if(is_array($approval_record)&&isset($approval_record['status'])&&'approved'===$approval_record['status'])$auto_written++;else $review++;}
                    continue;
                }
            }

            // AI-first path: ask an enabled provider before touching web search.
            // Web research remains the deterministic fallback when AI cannot answer.
            if ( $client && $this->ai_available() ) {
                $ai_first = $this->ai_first_answer( $post, $field, $client );
                if ( ! empty( $ai_first['success'] ) ) {
                    $result_id = $this->store_answer_result( $post, $field, $ai_first, array(), 'ai_first' );
                    if ( $result_id ) {
                        $created++;
                        $stored = $storage->get( $result_id );
                        $validation_result = $validator ? $validator->validate( $stored, true ) : array( 'success' => true );
                        if ( $confidence ) { $confidence_result = $confidence->calculate( $stored, $validation_result ); if ( ! empty($confidence_result['success']) ) { $storage->update($result_id,array('confidence'=>$confidence_result)); } }
                        $stored = $storage->get( $result_id );
                        $decision_result = $decision_engine ? $decision_engine->decide( $stored ) : array('decision'=>'review');
                        $decision = !empty($decision_result['decision']) ? $decision_result['decision'] : 'review';
                        $storage->update($result_id,array('decision'=>$decision));
                        $stored = $storage->get($result_id);
                        if($approval){$approval_record=$approval->create($stored);if(is_array($approval_record)&&isset($approval_record['status'])&&'approved'===$approval_record['status'])$auto_written++;else $review++;} else { $review++; }
                        continue;
                    }
                }
            }

            // AI-first: one provider is asked first; web is only fallback.
        if ( $client && $this->ai_available() ) {
            $ai_first = $this->ai_first_answer( $post, $field, $client );
            if ( ! empty( $ai_first['success'] ) ) {
                return $this->finalize_answer_result( $post_id, $field, $ai_first, array(), 'ai_first' );
            }
        }

        $research = $engine->research( $post_id, array(
                'field' => ! empty( $field['name'] ) ? $field['name'] : $field_key,
                'group_key' => ! empty( $field['group_key'] ) ? $field['group_key'] : '',
                'limit' => max( 1, min( 20, absint( $args['search_limit'] ) ) ),
                'fetch_pages' => true,
                'max_pages' => 3,
            ) );
            if ( empty( $research['success'] ) || empty( $research['results'] ) ) {
                $errors[] = array( 'field' => $field_key, 'error' => isset( $research['error'] ) ? $research['error'] : 'No research results.' );
                continue;
            }

            $sources = $research['results'];
            $answer = $this->extract_answer( $post, $field, $sources, $client );
            if ( empty( $answer['success'] ) ) {
                $errors[] = array( 'field' => $field_key, 'error' => isset( $answer['error'] ) ? $answer['error'] : 'AI extraction failed.' );
                continue;
            }

            $item = array(
                'vehicle' => array( 'post_id' => $post_id, 'post_type' => $post->post_type, 'title' => get_the_title( $post_id ) ),
                'field' => array(
                    'key' => ! empty( $field['key'] ) ? $field['key'] : '',
                    'name' => ! empty( $field['name'] ) ? $field['name'] : '',
                    'label' => ! empty( $field['label'] ) ? $field['label'] : '',
                    'type' => ! empty( $field['type'] ) ? $field['type'] : '',
                    'parent' => ! empty( $field['parent'] ) ? $field['parent'] : '',
                ),
                'query' => ! empty( $research['queries'] ) ? implode( ' | ', (array) $research['queries'] ) : '',
                'raw_answer' => $answer['raw'],
                'normalized_value' => $answer['value'],
                'expected_data_type' => isset( $field['ai']['expected_data_type'] ) ? $field['ai']['expected_data_type'] : '',
                'unit' => isset( $field['ai']['unit'] ) ? $field['ai']['unit'] : '',
                'sources' => $sources,
                'status' => 'researched',
                'metadata' => array( 'runner' => 'vehicle_research_runner', 'research_at' => current_time( 'c', true ), 'method' => isset($answer['method']) ? sanitize_key($answer['method']) : '' ),
            );

            $result_id = $storage->create_or_update( $item );
            if ( ! $result_id ) { $errors[] = array( 'field' => $field_key, 'error' => 'Could not store Research Result.' ); continue; }
            $created++;
            $stored = $storage->get( $result_id );

            $validation_result = $validator ? $validator->validate( $stored, true ) : array( 'success' => false );
            $stored = $storage->get( $result_id );
            $confidence_result = $confidence ? $confidence->calculate( $stored, $validation_result ) : array( 'success' => false );
            if ( ! empty( $confidence_result['success'] ) ) { $storage->update( $result_id, array( 'confidence' => $confidence_result ) ); }
            $stored = $storage->get( $result_id );
            $decision_result = $decision_engine ? $decision_engine->decide( $stored ) : array( 'success' => false, 'decision' => 'review' );
            $decision = ! empty( $decision_result['decision'] ) ? $decision_result['decision'] : 'review';
            $storage->update( $result_id, array( 'decision' => $decision ) );
            $stored = $storage->get( $result_id );

            if ( $approval ) {
                $approval_record = $approval->create( $stored );
                if ( is_array( $approval_record ) && isset( $approval_record['status'] ) && 'approved' === $approval_record['status'] ) { $auto_written++; }
                elseif ( 'review' === $decision ) { $review++; }
                else { $ignored++; }
            } elseif ( 'review' === $decision ) { $review++; }
        }

        $success = $created > 0 || empty( $errors );
        $message = $success
            ? 'Vehicle AI research completed.'
            : 'Vehicle AI research did not produce any Research Results.';

        if ( class_exists( 'TL_AI_VM_Logger' ) ) {
            TL_AI_VM_Logger::instance()->log(
                $message,
                $success ? 'success' : 'error',
                'runner',
                array(
                    'post_id' => $post_id,
                    'created' => $created,
                    'auto_written' => $auto_written,
                    'review' => $review,
                    'ignored' => $ignored,
                    'errors' => array_slice( $errors, 0, 10 ),
                )
            );
        }

        return array(
            'success' => $success,
            'post_id' => $post_id,
            'created' => $created,
            'auto_written' => $auto_written,
            'review' => $review,
            'ignored' => $ignored,
            'errors' => $errors,
            'message' => $message,
        );
    }

    /** Search only, without fetching source pages. */
    public function search_field( $post_id, $field, $args = array() ) {
        $post_id = absint( $post_id );
        if ( ! $post_id || ! get_post( $post_id ) ) { return array( 'success' => false, 'error' => 'Vehicle post was not found.' ); }
        $field_name = ! empty( $field['name'] ) ? $field['name'] : ( ! empty( $field['key'] ) ? $field['key'] : '' );
        if ( ! $field_name ) { return array( 'success' => false, 'error' => 'Field name is missing.' ); }
        $args = wp_parse_args( $args, array( 'search_limit' => 3 ) );
        return TL_AI_VM_Web_Research_Engine::instance()->research( $post_id, array(
            'field' => $field_name,
            'limit' => max( 1, min( 5, absint( $args['search_limit'] ) ) ),
            'fetch_pages' => false,
        ) );
    }

    /** Extract a value from already collected sources. */
    public function extract_field_answer( $post_id, $field, $sources ) {
        $post = absint( $post_id ) ? get_post( absint( $post_id ) ) : null;
        if ( ! $post ) { return array( 'success' => false, 'error' => 'Vehicle post was not found.' ); }
        $client = class_exists( 'TL_AI_VM_AI_Client' ) ? TL_AI_VM_AI_Client::instance() : null;
        return $this->extract_answer( $post, $field, is_array( $sources ) ? $sources : array(), $client, array( 'timeout' => 20 ) );
    }

    /** Store, validate, score, decide and route one already-extracted field. */
    public function finalize_field( $post_id, $field, $research, $answer ) {
        $post_id = absint( $post_id );
        $post = $post_id ? get_post( $post_id ) : null;
        if ( ! $post ) { return array( 'success' => false, 'error' => 'Vehicle post was not found.' ); }
        if ( class_exists( 'TL_AI_VM_Learning_Memory' ) && isset( $answer['value'] ) ) { $rr = TL_AI_VM_Learning_Memory::instance()->apply_rules( $field, $answer['value'] ); $answer['value'] = $rr['value']; if ( ! empty($rr['applied']) ) { $answer['method']='learned_rules'; $answer['learned_rules']=$rr['applied']; } }
        $storage = TL_AI_VM_Research_Result::instance();
        $validator = class_exists( 'TL_AI_VM_Research_Validator' ) ? TL_AI_VM_Research_Validator::instance() : null;
        $confidence = class_exists( 'TL_AI_VM_Confidence' ) ? TL_AI_VM_Confidence::instance() : null;
        $decision_engine = class_exists( 'TL_AI_VM_Decision' ) ? TL_AI_VM_Decision::instance() : null;
        $approval = class_exists( 'TL_AI_VM_Research_Approval' ) ? TL_AI_VM_Research_Approval::instance() : null;
        $item = array(
            'vehicle' => array( 'post_id' => $post_id, 'post_type' => $post->post_type, 'title' => get_the_title( $post_id ) ),
            'field' => array( 'key' => isset( $field['key'] ) ? $field['key'] : '', 'name' => isset( $field['name'] ) ? $field['name'] : '', 'label' => isset( $field['label'] ) ? $field['label'] : '', 'type' => isset( $field['type'] ) ? $field['type'] : '', 'parent' => isset( $field['parent'] ) ? $field['parent'] : '' ),
            'query' => ! empty( $research['queries'] ) ? implode( ' | ', (array) $research['queries'] ) : '',
            'raw_answer' => isset( $answer['raw'] ) ? $answer['raw'] : '',
            'normalized_value' => isset( $answer['value'] ) ? $answer['value'] : '',
            'expected_data_type' => isset( $field['ai']['expected_data_type'] ) ? $field['ai']['expected_data_type'] : '',
            'unit' => isset( $field['ai']['unit'] ) ? $field['ai']['unit'] : '',
            'sources' => isset( $research['results'] ) ? $research['results'] : array(),
            'status' => 'researched',
            'metadata' => array( 'runner' => 'async_vehicle_research_runner', 'research_at' => current_time( 'c', true ), 'method' => isset($answer['method']) ? sanitize_key($answer['method']) : '' ),
        );
        $result_id = $storage->create_or_update( $item );
        if ( ! $result_id ) { return array( 'success' => false, 'error' => 'Could not store Research Result.' ); }
        $stored = $storage->get( $result_id );
        $validation_result = $validator ? $validator->validate( $stored, true ) : array( 'success' => false );
        $stored = $storage->get( $result_id );
        $confidence_result = $confidence ? $confidence->calculate( $stored, $validation_result ) : array( 'success' => false );
        if ( ! empty( $confidence_result['success'] ) ) { $storage->update( $result_id, array( 'confidence' => $confidence_result ) ); }
        $stored = $storage->get( $result_id );
        $decision_result = $decision_engine ? $decision_engine->decide( $stored ) : array( 'success' => false, 'decision' => 'review' );
        $decision = ! empty( $decision_result['decision'] ) ? $decision_result['decision'] : 'review';
        $storage->update( $result_id, array( 'decision' => $decision ) );
        $stored = $storage->get( $result_id );
        $auto_written = 0; $review = 0; $ignored = 0;
        if ( $approval ) {
            $approval_record = $approval->create( $stored );
            if ( is_array( $approval_record ) && isset( $approval_record['status'] ) && 'approved' === $approval_record['status'] ) { $auto_written = 1; }
            elseif ( 'review' === $decision ) { $review = 1; } else { $ignored = 1; }
        } elseif ( 'review' === $decision ) { $review = 1; }
        return array( 'success' => true, 'result_id' => $result_id, 'field_key' => isset( $field['key'] ) ? $field['key'] : '', 'field_label' => isset( $field['label'] ) ? $field['label'] : '', 'decision' => $decision, 'auto_written' => $auto_written, 'review' => $review, 'ignored' => $ignored );
    }

    /**
     * Process exactly one field. Used by the asynchronous pipeline so a
     * single HTTP request never has to process the whole vehicle.
     *
     * @param int   $post_id Vehicle ID.
     * @param array $field   Normalized field.
     * @param array $args    Processing options.
     * @return array
     */
    public function process_field( $post_id, $field, $args = array() ) {
        $post_id = absint( $post_id );
        $post = $post_id ? get_post( $post_id ) : null;
        if ( ! $post || ! is_array( $field ) ) {
            return array( 'success' => false, 'error' => 'Vehicle or field is invalid.' );
        }

        $args = wp_parse_args( $args, array(
            'search_limit' => 3,
            'max_pages' => 1,
        ) );

        $field_key = ! empty( $field['key'] ) ? $field['key'] : ( ! empty( $field['name'] ) ? $field['name'] : '' );
        if ( ! $field_key ) {
            return array( 'success' => false, 'error' => 'Field key is missing.' );
        }

        $engine = TL_AI_VM_Web_Research_Engine::instance();
        $storage = TL_AI_VM_Research_Result::instance();
        $validator = class_exists( 'TL_AI_VM_Research_Validator' ) ? TL_AI_VM_Research_Validator::instance() : null;
        $confidence = class_exists( 'TL_AI_VM_Confidence' ) ? TL_AI_VM_Confidence::instance() : null;
        $decision_engine = class_exists( 'TL_AI_VM_Decision' ) ? TL_AI_VM_Decision::instance() : null;
        $approval = class_exists( 'TL_AI_VM_Research_Approval' ) ? TL_AI_VM_Research_Approval::instance() : null;
        $client = class_exists( 'TL_AI_VM_AI_Client' ) ? TL_AI_VM_AI_Client::instance() : null;

        $research = $engine->research( $post_id, array(
            'field' => ! empty( $field['name'] ) ? $field['name'] : $field_key,
            'group_key' => ! empty( $field['group_key'] ) ? $field['group_key'] : '',
            'limit' => max( 1, min( 5, absint( $args['search_limit'] ) ) ),
            'fetch_pages' => true,
            'max_pages' => max( 1, min( 1, absint( $args['max_pages'] ) ) ),
        ) );

        if ( empty( $research['success'] ) || empty( $research['results'] ) ) {
            return array( 'success' => false, 'error' => isset( $research['error'] ) ? $research['error'] : 'No research results were found.', 'stage' => 'search' );
        }

        $answer = $this->extract_answer( $post, $field, $research['results'], $client );
        if ( ! empty( $answer['success'] ) && class_exists( 'TL_AI_VM_Learning_Memory' ) ) {
            $rule_result = TL_AI_VM_Learning_Memory::instance()->apply_rules( $field, $answer['value'] );
            $answer['value'] = $rule_result['value'];
            if ( ! empty($rule_result['applied']) ) { $answer['method'] = 'learned_rules'; $answer['learned_rules'] = $rule_result['applied']; }
        }
        if ( empty( $answer['success'] ) ) {
            return array( 'success' => false, 'error' => isset( $answer['error'] ) ? $answer['error'] : 'AI extraction failed.', 'stage' => 'extraction' );
        }

        $item = array(
            'vehicle' => array( 'post_id' => $post_id, 'post_type' => $post->post_type, 'title' => get_the_title( $post_id ) ),
            'field' => array(
                'key' => ! empty( $field['key'] ) ? $field['key'] : '',
                'name' => ! empty( $field['name'] ) ? $field['name'] : '',
                'label' => ! empty( $field['label'] ) ? $field['label'] : '',
                'type' => ! empty( $field['type'] ) ? $field['type'] : '',
                'parent' => ! empty( $field['parent'] ) ? $field['parent'] : '',
            ),
            'query' => ! empty( $research['queries'] ) ? implode( ' | ', (array) $research['queries'] ) : '',
            'raw_answer' => $answer['raw'],
            'normalized_value' => $answer['value'],
            'expected_data_type' => isset( $field['ai']['expected_data_type'] ) ? $field['ai']['expected_data_type'] : '',
            'unit' => isset( $field['ai']['unit'] ) ? $field['ai']['unit'] : '',
            'sources' => $research['results'],
            'status' => 'researched',
            'metadata' => array( 'runner' => 'async_vehicle_research_runner', 'research_at' => current_time( 'c', true ), 'method' => isset($answer['method']) ? sanitize_key($answer['method']) : '' ),
        );

        $result_id = $storage->create_or_update( $item );
        if ( ! $result_id ) {
            return array( 'success' => false, 'error' => 'Could not store Research Result.', 'stage' => 'storage' );
        }

        $stored = $storage->get( $result_id );
        $validation_result = $validator ? $validator->validate( $stored, true ) : array( 'success' => false );
        $stored = $storage->get( $result_id );
        $confidence_result = $confidence ? $confidence->calculate( $stored, $validation_result ) : array( 'success' => false );
        if ( ! empty( $confidence_result['success'] ) ) { $storage->update( $result_id, array( 'confidence' => $confidence_result ) ); }
        $stored = $storage->get( $result_id );
        $decision_result = $decision_engine ? $decision_engine->decide( $stored ) : array( 'success' => false, 'decision' => 'review' );
        $decision = ! empty( $decision_result['decision'] ) ? $decision_result['decision'] : 'review';
        $storage->update( $result_id, array( 'decision' => $decision ) );
        $stored = $storage->get( $result_id );

        $auto_written = 0;
        $review = 0;
        $ignored = 0;
        if ( $approval ) {
            $approval_record = $approval->create( $stored );
            if ( is_array( $approval_record ) && isset( $approval_record['status'] ) && 'approved' === $approval_record['status'] ) { $auto_written = 1; }
            elseif ( 'review' === $decision ) { $review = 1; }
            else { $ignored = 1; }
        } elseif ( 'review' === $decision ) {
            $review = 1;
        }

        return array(
            'success' => true,
            'result_id' => $result_id,
            'field_key' => $field_key,
            'field_label' => ! empty( $field['label'] ) ? $field['label'] : $field_key,
            'decision' => $decision,
            'auto_written' => $auto_written,
            'review' => $review,
            'ignored' => $ignored,
        );
    }

    private function only_empty_fields( $post_id, $fields ) {
        $out = array();
        foreach ( $fields as $field ) {
            $name = ! empty( $field['name'] ) ? $field['name'] : '';
            $key = ! empty( $field['key'] ) ? $field['key'] : $name;
            if ( ! $name && ! $key ) { continue; }
            $value = function_exists( 'get_field' ) ? get_field( $key, $post_id, false ) : get_post_meta( $post_id, $name, true );
            if ( '' === $value || null === $value || array() === $value ) { $out[] = $field; }
        }
        return $out;
    }

    private function ai_available() {
        return class_exists( 'TL_AI_VM_AI_Client' ) && ! empty( TL_AI_VM_AI_Client::instance()->get_enabled_providers_in_order() );
    }

    private function ai_first_answer( $post, $field, $client ) {
        $field_json = wp_json_encode( $field, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        $vehicle = get_the_title( $post->ID );
        $prompt = "You are the primary vehicle-data research assistant for Tuningland.\n" .
            "Answer the requested vehicle field only when you have a reliable factual answer. Do not guess.\n" .
            "If you have web/search/grounding tools available through this endpoint, use them before answering.\n" .
            "If you cannot verify the value, return null so the application can fall back to web research.\n" .
            "Return JSON only: {\"value\":...,\"raw\":\"brief evidence/reason\",\"confidence\":0..1}.\n" .
            "VEHICLE: " . $vehicle . "\nFIELD: " . $field_json;
        $instructions = 'Never invent a vehicle specification. Distinguish engine oil, transmission fluid, brake fluid, coolant, capacity, viscosity and engine displacement. For ACF select fields return an allowed choice only. If uncertain, return {"value":null}. Prefer a directly verified answer over model memory.';
        $result = $client->request_json( $prompt, $instructions, array( 'temperature'=>0, 'max_output_tokens'=>500, 'timeout'=>45 ) );
        if ( empty($result['success']) || empty($result['json']) || !is_array($result['json']) ) { return array('success'=>false,'error'=>isset($result['error'])?$result['error']:'AI unavailable'); }
        $json=$result['json'];
        if ( !array_key_exists('value',$json) || $json['value'] === null || $json['value'] === '' ) { return array('success'=>false,'error'=>'AI did not return a verified value.','provider'=>$result['provider']??''); }
        return array('success'=>true,'value'=>$json['value'],'raw'=>$json['raw']??$json['value'],'confidence'=>isset($json['confidence'])?(float)$json['confidence']:0.75,'method'=>$result['provider']??'ai_first','provider'=>$result['provider']??'');
    }

    private function store_answer_result( $post, $field, $answer, $sources, $method ) {
        $storage=TL_AI_VM_Research_Result::instance();
        $item=array(
            'vehicle'=>array('post_id'=>$post->ID,'post_type'=>$post->post_type,'title'=>get_the_title($post->ID)),
            'field'=>array('key'=>$field['key']??'','name'=>$field['name']??'','label'=>$field['label']??'','type'=>$field['type']??'','parent'=>$field['parent']??''),
            'query'=>'AI-first vehicle field research', 'raw_answer'=>$answer['raw']??'', 'normalized_value'=>$answer['value']??'',
            'expected_data_type'=>$field['ai']['expected_data_type']??'', 'unit'=>$field['ai']['unit']??'', 'sources'=>$sources,
            'status'=>'researched', 'metadata'=>array('runner'=>'vehicle_research_runner','research_at'=>current_time('c',true),'method'=>$method,'ai_provider'=>$answer['provider']??($answer['method']??''))
        );
        return $storage->create_or_update($item);
    }

    private function finalize_answer_result( $post_id, $field, $answer, $sources, $method ) {
        $post=get_post($post_id); if(!$post) return array('success'=>false,'error'=>'Vehicle not found.');
        if(class_exists('TL_AI_VM_Learning_Memory')){$rr=TL_AI_VM_Learning_Memory::instance()->apply_rules($field,$answer['value']);$answer['value']=$rr['value'];if(!empty($rr['applied'])){$answer['method']='learned_rules';$answer['learned_rules']=$rr['applied'];}}
        $storage=TL_AI_VM_Research_Result::instance();$validator=class_exists('TL_AI_VM_Research_Validator')?TL_AI_VM_Research_Validator::instance():null;$confidence=class_exists('TL_AI_VM_Confidence')?TL_AI_VM_Confidence::instance():null;$decision_engine=class_exists('TL_AI_VM_Decision')?TL_AI_VM_Decision::instance():null;$approval=class_exists('TL_AI_VM_Research_Approval')?TL_AI_VM_Research_Approval::instance():null;
        $item=array('vehicle'=>array('post_id'=>$post_id,'post_type'=>$post->post_type,'title'=>get_the_title($post_id)),'field'=>array('key'=>$field['key']??'','name'=>$field['name']??'','label'=>$field['label']??'','type'=>$field['type']??'','parent'=>$field['parent']??''),'query'=>'AI-first vehicle field research','raw_answer'=>$answer['raw']??'','normalized_value'=>$answer['value']??'','expected_data_type'=>$field['ai']['expected_data_type']??'','unit'=>$field['ai']['unit']??'','sources'=>$sources,'status'=>'researched','metadata'=>array('runner'=>'async_vehicle_research_runner','research_at'=>current_time('c',true),'method'=>$method,'ai_provider'=>$answer['provider']??($answer['method']??'')));
        $id=$storage->create_or_update($item); if(!$id)return array('success'=>false,'error'=>'Could not store Research Result.');
        $stored=$storage->get($id);$vr=$validator?$validator->validate($stored,true):array('success'=>true);if($confidence){$cr=$confidence->calculate($stored,$vr);if(!empty($cr['success']))$storage->update($id,array('confidence'=>$cr));}$stored=$storage->get($id);$dr=$decision_engine?$decision_engine->decide($stored):array('decision'=>'review');$decision=$dr['decision']??'review';$storage->update($id,array('decision'=>$decision));$stored=$storage->get($id);$aw=0;$review=0;$ignored=0;if($approval){$ar=$approval->create($stored);if(is_array($ar)&&($ar['status']??'')==='approved')$aw=1;elseif($decision==='review')$review=1;else$ignored=1;}else$review=1;
        return array('success'=>true,'result_id'=>$id,'field_key'=>$field['key']??'','field_label'=>$field['label']??'','decision'=>$decision,'auto_written'=>$aw,'review'=>$review,'ignored'=>$ignored,'method'=>$method);
    }

    private function extract_answer( $post, $field, $sources, $client, $request_options = array() ) {
        $source_text = array();

        foreach ( array_slice( $sources, 0, 10 ) as $source ) {
            if ( ! is_array( $source ) ) {
                continue;
            }

            $source_text[] =
                'TITLE: ' . ( isset( $source['title'] ) ? $source['title'] : '' ) .
                "\nURL: " . ( isset( $source['url'] ) ? $source['url'] : '' ) .
                "\nSNIPPET: " . ( isset( $source['snippet'] ) ? wp_strip_all_tags( $source['snippet'] ) : '' ) .
                "\nCONTENT: " . ( isset( $source['content'] ) ? wp_strip_all_tags( $source['content'] ) : '' );
        }

        $field_json = wp_json_encode( $field, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        $prompt =
            'Extract the most reliable value for this ACF vehicle field from the supplied web sources. ' .
            'Do not guess. If sources disagree, choose no value. Return JSON only: ' .
            '{"value":...,"raw":"brief evidence-based answer","confidence":0..1}. ' .
            'FIELD: ' . $field_json .
            "\nVEHICLE: " . get_the_title( $post->ID ) .
            "\nSOURCES:\n" . implode( "\n\n---\n\n", $source_text );

        // Deterministic extraction is always attempted first and does not need OpenAI.
        $local = $this->deterministic_extract( $field, $sources );
        if ( ! empty( $local['success'] ) ) {
            return $local;
        }

        $ai_enabled = $client && ! empty( $client->get_enabled_providers_in_order() );
        if ( ! $ai_enabled || ! $client ) {
            return array(
                'success' => false,
                'error'   => 'No reliable deterministic value could be extracted and no configured AI provider is available.',
            );
        }

        $response = $client->request_json(
            $prompt,
            'You extract factual vehicle data from the supplied sources. Never invent facts. For enum fields return only an allowed ACF choice. Return valid JSON only.',
            wp_parse_args( $request_options, array( 'temperature' => 0, 'max_output_tokens' => 500 ) )
        );

        if ( empty( $response['success'] ) || empty( $response['json'] ) || ! is_array( $response['json'] ) ) {
            return array( 'success' => false, 'error' => 'AI could not extract a structured value.' );
        }

        $json = $response['json'];
        if ( ! array_key_exists( 'value', $json ) || '' === $json['value'] || null === $json['value'] ) {
            return array( 'success' => false, 'error' => 'No reliable value was found.' );
        }

        return array(
            'success' => true,
            'value'   => $json['value'],
            'raw'     => isset( $json['raw'] ) ? $json['raw'] : $json['value'],
            'confidence' => isset( $json['confidence'] ) ? (float) $json['confidence'] : 0.75,
            'method'  => isset( $response['provider'] ) ? $response['provider'] : 'ai',
        );
    }

    /**
     * Extract only values that are semantically compatible with the target field.
     *
     * IMPORTANT: Never select a value merely because it is common on the page.
     * A page can contain engine-oil viscosity, gearbox fluid, capacities and bulb
     * codes at the same time. The previous implementation mixed these concepts.
     */
    private function deterministic_extract( $field, $sources ) {
        $type = isset( $field['type'] ) ? sanitize_key( $field['type'] ) : '';
        $label = trim( (string) ( $field['label'] ?? '' ) );
        $name  = trim( (string) ( $field['name'] ?? '' ) );
        $instructions = trim( (string) ( $field['instructions'] ?? '' ) );
        $semantic = strtolower( $label . ' ' . str_replace( '_', ' ', $name ) . ' ' . $instructions );

        $hay = '';
        foreach ( (array) $sources as $source ) {
            if ( ! is_array( $source ) ) { continue; }
            foreach ( array( 'title', 'snippet', 'content' ) as $part ) {
                if ( isset( $source[ $part ] ) && is_scalar( $source[ $part ] ) ) {
                    $hay .= "\n" . wp_strip_all_tags( (string) $source[ $part ] );
                }
            }
        }
        $hay = $this->normalize_digits( html_entity_decode( $hay, ENT_QUOTES, 'UTF-8' ) );
        $hay = preg_replace( '/[\x{00A0}\t]+/u', ' ', $hay );
        if ( '' === trim( $hay ) ) { return array( 'success' => false ); }

        $is_transmission = (bool) preg_match( '/(gearbox|gear box|transmission|transaxle|گیربکس|جعبه\s*دنده|روغن\s*گیربکس)/iu', $semantic );
        $is_engine_oil   = (bool) preg_match( '/(engine\s*oil|motor\s*oil|oil\s*grade|oil\s*viscos|sae|viscos|گرانروی|ویسکوز|روغن\s*موتور|روغن\s*ماشین)/iu', $semantic );
        $is_api          = (bool) preg_match( '/(\bapi\b|api\s*standard|سطح\s*کیفی|استاندارد\s*api)/iu', $semantic );
        $is_capacity     = (bool) preg_match( '/(capacity|volume|مقدار|حجم|ظرفیت|لیتر|liter|litre)/iu', $semantic );
        $is_bulb         = (bool) preg_match( '/(bulb|lamp|headlight|high\s*beam|low\s*beam|نور\s*بالا|نور\s*پایین|لامپ|چراغ)/iu', $semantic );
        $is_coolant      = (bool) preg_match( '/(coolant|cooling\s*system|antifreeze|anti[- ]?freeze|خنک\s*کننده|مایع\s*خنک|ضد\s*یخ)/iu', $semantic );

        /*
         * API field: only API/ACEA-style standards are accepted. SAE grades are
         * deliberately rejected even if they occur much more often on the page.
         */
        if ( $is_api && ! $is_capacity ) {
            if ( preg_match_all( '/\bAPI\s+(?:[A-Z]{1,3}\d?|[A-Z]{1,3}-[A-Z0-9]+)\b/i', $hay, $m ) ) {
                $values = array_map( 'strtoupper', array_map( 'trim', $m[0] ) );
                $counts = array_count_values( $values );
                arsort( $counts );
                $value = array_key_first( $counts );
                return array( 'success'=>true, 'value'=>$value, 'raw'=>'Matched an API engine-oil quality standard.', 'confidence'=> count($counts) > 1 ? 0.76 : 0.86, 'method'=>'api_standard_pattern' );
            }
            if ( preg_match_all( '/\bACEA\s+[A-Z][0-9](?:\/[^\s,;]+)?\b/i', $hay, $m ) ) {
                $values = array_map( 'strtoupper', array_map( 'trim', $m[0] ) );
                $counts = array_count_values( $values ); arsort( $counts );
                return array( 'success'=>true, 'value'=>array_key_first($counts), 'raw'=>'Matched an ACEA engine-oil quality standard.', 'confidence'=>0.80, 'method'=>'acea_standard_pattern' );
            }
            return array( 'success'=>false );
        }

        /* Engine displacement is different from fluid capacity. */
        $is_engine_displacement = (bool) preg_match( '/(engine\s*(size|capacity|displacement)|engine\s*volume|حجم\s*موتور|حجم موتور|حجم پیشرانه|displacement)/iu', $semantic );
        if ( $is_engine_displacement && ! $is_engine_oil && ! $is_transmission && ! $is_coolant ) {
            $patterns = array(
                '/(?:engine\s*(?:size|capacity|displacement|volume)|حجم\s*موتور|حجم پیشرانه)\s*[:=\-]?\s*([0-9]+(?:[.,][0-9]+)?)\s*(?:L|liter|litre|لیتر|cc|cm3|cm³)\b/iu',
                '/\b([0-9]{3,4})\s*cc\b/iu',
            );
            foreach ( $patterns as $pattern ) {
                if ( preg_match( $pattern, $hay, $m ) ) {
                    $unit = preg_match( '/cc|cm3|cm³/i', $m[0] ) ? 'cc' : 'L';
                    $value = str_replace( ',', '.', trim( $m[1] ) );
                    return array( 'success' => true, 'value' => $value . ' ' . $unit, 'raw' => 'Matched engine displacement, not a fluid capacity.', 'confidence' => 0.90, 'method' => 'engine_displacement_context' );
                }
            }
        }

        /*
         * Coolant/antifreeze capacity gets its own extractor. A vehicle page can
         * legitimately contain several litre values (engine displacement, oil
         * capacity, total coolant capacity and concentrate amount). We therefore
         * require coolant context and prefer TOTAL/FULL-SYSTEM capacity over a
         * concentrate amount unless the field explicitly asks for concentrate.
         */
        if ( $is_coolant && $is_capacity ) {
            $litre_pattern = '/([0-9]+(?:[.,][0-9]+)?)\s*(?:L|liter|litre|liters|litres|لیتر)\b/iu';
            $want_concentrate = (bool) preg_match( '/(concentrate|غلظت|کنسانتره)/iu', $semantic );
            $candidates = array();
            foreach ( preg_split( '/\n+/u', $hay ) as $line ) {
                if ( ! preg_match( $litre_pattern, $line, $lm ) ) { continue; }
                $context_ok = (bool) preg_match( '/(coolant|cooling\s*system|antifreeze|anti[- ]?freeze|خنک\s*کننده|مایع\s*خنک|ضد\s*یخ)/iu', $line );
                if ( ! $context_ok ) { continue; }
                $value = str_replace( ',', '.', trim( $lm[1] ) );
                if ( ! is_numeric( $value ) ) { continue; }
                $score = 50;
                if ( preg_match( '/(capacity|system capacity|cooling system capacity|total capacity|full capacity|system fill|مجموع|کل ظرفیت|ظرفیت سیستم|حجم کل)/iu', $line ) ) { $score += 30; }
                if ( preg_match( '/(total|full|complete|کل|مجموع)/iu', $line ) ) { $score += 15; }
                if ( preg_match( '/(concentrate|غلظت|کنسانتره)/iu', $line ) ) { $score += $want_concentrate ? 25 : -25; }
                if ( preg_match( '/(engine size|engine displacement|حجم موتور|موتور)[^\n]{0,30}\b' . preg_quote( $value, '/' ) . '\s*(?:L|liter|litre|لیتر)/iu', $line ) ) { $score -= 40; }
                $candidates[] = array( 'value' => $value . ' L', 'score' => $score, 'line' => trim( $line ) );
            }
            if ( $candidates ) {
                usort( $candidates, function( $a, $b ) { return $b['score'] <=> $a['score']; } );
                $best = $candidates[0];
                return array( 'success' => true, 'value' => $best['value'], 'raw' => 'Matched coolant/antifreeze capacity from a context-specific source line: ' . $best['line'], 'confidence' => min( 0.96, max( 0.72, $best['score'] / 100 ) ), 'method' => 'coolant_capacity_context' );
            }
            return array( 'success' => false );
        }

        /* Capacities must be numeric litres, never an oil grade. */
        if ( $is_capacity ) {
            $litre_pattern = '/([0-9]+(?:[.,][0-9]+)?)\s*(?:L|liter|litre|liters|litres|لیتر)\b/iu';
            /* First use context lines. This prevents an engine-oil capacity field
             * from stealing a transmission capacity that happens to be on the same page. */
            foreach ( preg_split('/\n+/u', $hay ) as $line ) {
                $line_has_transmission = (bool) preg_match('/(transmission|gearbox|gear box|transaxle|گیربکس|جعبه\s*دنده)/iu', $line);
                $line_has_engine_oil = (bool) preg_match('/(engine\s*oil|motor\s*oil|oil\s*capacity|oil\s*fill|lubricant|روغن\s*موتور|ظرفیت\s*روغن|حجم\s*روغن)/iu', $line);
                $line_has_engine_displacement = (bool) preg_match('/(engine\s*(size|capacity|displacement)|engine\s*[0-9]+(?:\.[0-9]+)?\s*L|حجم\s*موتور|حجم موتور|موتور [0-9]+(?:\.[0-9]+)?)/iu', $line);
                $line_is_capacity = (bool) preg_match('/(capacity|volume|fill|amount|مقدار|حجم|ظرفیت|پرکردن|لیتر|liter|litre)/iu', $line);
                if ( ! $line_is_capacity || ! preg_match($litre_pattern, $line, $lm) ) { continue; }
                if ( $is_transmission && $line_has_transmission ) {
                    $v = str_replace(',', '.', $lm[1]) . ' L';
                    return array('success'=>true,'value'=>$v,'raw'=>'Matched a transmission fluid capacity in a context-matched source line.','confidence'=>0.88,'method'=>'transmission_capacity_context');
                }
                if ( ! $is_transmission && $line_has_engine_oil && ! $line_has_transmission && ! $line_has_engine_displacement ) {
                    $v = str_replace(',', '.', $lm[1]) . ' L';
                    return array('success'=>true,'value'=>$v,'raw'=>'Matched an engine-oil capacity in a context-matched source line.','confidence'=>0.88,'method'=>'engine_oil_capacity_context');
                }
            }
            /* Conservative fallback: only accept a value when the page exposes a single capacity. */
            $matches = array();
            if ( preg_match_all( $litre_pattern, $hay, $matches ) ) {
                $values = array();
                foreach ( $matches[1] as $v ) { $v = str_replace(',', '.', trim($v)); if ( is_numeric($v) ) { $values[] = $v . ' L'; } }
                $values = array_values(array_unique($values));
                if ( 1 === count($values) ) {
                    return array('success'=>true,'value'=>$values[0],'raw'=>'Matched the only litre capacity exposed by the source.','confidence'=>0.74,'method'=>'single_capacity_fallback');
                }
            }
            return array( 'success'=>false );
        }

        /* Transmission fluid/specification: never return an SAE engine-oil grade. */
        if ( $is_transmission && ! $is_capacity ) {
            $patterns = array(
                '/\b(?:ATF|CVT|DCT|DSG|AMT|MPS6|TF[- ]?80|TF[- ]?81|AW[- ]?1|JWS[- ]?[0-9A-Z-]+|DEXRON(?:[- ]?[A-Z0-9]+)?|MERCON(?:[- ]?[A-Z0-9]+)?)\b/i',
                '/\b(?:Volvo|Aisin)\s+[A-Z]{1,5}[- ]?[0-9A-Z]{1,8}\b/i',
            );
            foreach ( $patterns as $pattern ) {
                if ( preg_match_all( $pattern, $hay, $m ) ) {
                    $values = array_map( 'strtoupper', array_map( 'trim', $m[0] ) );
                    $values = array_values( array_filter( $values, function($v){ return !preg_match('/^[0-9]{1,2}W[- ]?[0-9]{1,2}$/i',$v); } ) );
                    if ( $values ) {
                        $counts = array_count_values($values); arsort($counts);
                        return array('success'=>true,'value'=>array_key_first($counts),'raw'=>'Matched a transmission fluid type/specification.', 'confidence'=>count($counts)>1?0.72:0.82,'method'=>'transmission_fluid_pattern');
                    }
                }
            }
            return array('success'=>false);
        }

        /* Bulb fields: respect high/low beam semantics instead of any bulb code. */
        if ( $is_bulb ) {
            $beam = false;
            if ( preg_match('/(high\s*beam|نور\s*بالا)/iu', $semantic) ) { $beam = 'high'; }
            elseif ( preg_match('/(low\s*beam|نور\s*پایین)/iu', $semantic) ) { $beam = 'low'; }
            $lines = preg_split('/\n+/u', $hay);
            $codes = '/\b(?:H[1-9][0-9]?|D[1-9][0-9]?S|HB[1-9]|HIR[1-9]|900[3-8]|901[0-2]|9145|9140|PSX24W|H11B)\b/i';
            $candidate_lines = array();
            foreach ( $lines as $line ) {
                if ( preg_match('/(bulb|lamp|headlight|beam|لامپ|چراغ|نور)/iu', $line) && ( ! $beam || ( 'high' === $beam ? preg_match('/(high|بالا)/iu',$line) : preg_match('/(low|پایین)/iu',$line) ) ) ) { $candidate_lines[] = $line; }
            }
            $search_text = $candidate_lines ? implode("\n",$candidate_lines) : $hay;
            if ( preg_match_all($codes,$search_text,$m) ) {
                $values=array_map('strtoupper',array_map('trim',$m[0])); $counts=array_count_values($values); arsort($counts);
                return array('success'=>true,'value'=>array_key_first($counts),'raw'=>'Matched a compatible automotive bulb code.', 'confidence'=>count($counts)>1?0.70:0.82,'method'=>'bulb_semantic_pattern');
            }
            return array('success'=>false);
        }

        /* Engine-oil viscosity only. */
        if ( $is_engine_oil && ! $is_api && ! $is_capacity && ! $is_transmission ) {
            if ( preg_match_all( '/\b(?:[0-9]{1,2})\s*[Ww]\s*[-\/ ]?\s*[0-9]{1,2}\b/u', $hay, $matches ) ) {
                $values=array(); foreach($matches[0] as $v){ $v=strtoupper(preg_replace('/\s*[\/ ]\s*/','-',trim($v))); $v=preg_replace('/\s*-\s*/','-',$v); $values[]=$v; }
                $counts=array_count_values($values); arsort($counts);
                return array('success'=>true,'value'=>array_key_first($counts),'raw'=>'Matched an engine-oil SAE viscosity grade.', 'confidence'=>count($counts)>1?0.74:0.84,'method'=>'sae_viscosity_pattern');
            }
            return array('success'=>false);
        }

        /*
         * Generic text fields are not allowed to steal unrelated values from a
         * page. Only an explicit "Label: value" pattern is safe without AI.
         */
        if ( ! in_array( $type, array( 'image', 'gallery', 'file', 'wysiwyg', 'textarea' ), true ) ) {
            $labels = array_filter( array( $label, str_replace('_',' ',$name) ) );
            foreach ( $labels as $needle ) {
                $pattern = '/' . preg_quote($needle,'/') . '\s*[:=\-]\s*([^\r\n<]{1,160})/iu';
                if ( preg_match($pattern,$hay,$m) ) {
                    $value=trim($m[1]);
                    if($value!=='' && strlen($value)<161){
                        return array('success'=>true,'value'=>$value,'raw'=>'Matched an explicit field label/value pair.','confidence'=>0.62,'method'=>'explicit_label_value');
                    }
                }
            }
        }

        return array( 'success' => false );
    }

    private function normalize_digits( $text ) {
        $map = array(
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
        );
        return strtr( (string) $text, $map );
    }
}
