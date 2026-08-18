<?php
/**
 * Asynchronous Vehicle Research Controller.
 *
 * Breaks a vehicle research job into short, resumable HTTP steps.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TL_AI_VM_Async_Research {
    private static $instance = null;
    const ACTION = 'async_vehicle_research';

    public static function instance() {
        if ( null === self::$instance ) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'tl_ai_vm_async_research_cron', array( $this, 'cron_tick' ), 10, 1 );
    }

    public function start( $post_id, $args = array() ) {
        $post_id = absint( $post_id );
        $post = $post_id ? get_post( $post_id ) : null;
        if ( ! $post ) { return array( 'success' => false, 'error' => 'Vehicle post was not found.' ); }

        $queue = TL_AI_VM_Research_Queue::instance();
        $existing = $queue->find_by_post_id( $post_id, array( 'pending', 'running' ) );
        foreach ( $existing as $job ) {
            if ( isset( $job['metadata']['async'] ) && $job['metadata']['async'] ) {
                return array( 'success' => true, 'job_id' => $job['id'], 'existing' => true );
            }
        }

        $args = wp_parse_args( $args, array(
            'only_empty' => true,
            'use_ai' => true,
            'max_fields' => 50,
            'search_limit' => 1,
            'selected_groups' => array(),
            'groups_filter_enabled' => true,
            'batch_size' => 5,
        ) );

        $job_id = $queue->add( $post_id, array(
            'priority' => 20,
            'only_empty' => ! empty( $args['only_empty'] ),
            'mode' => 'auto',
            'metadata' => array(
                'async' => true,
                'stage' => 'prepare',
                'progress' => 0,
                'message' => 'Preparing vehicle research…',
                'current_index' => 0,
                'total' => 0,
                'fields' => array(),
                'stats' => array( 'created' => 0, 'auto_written' => 0, 'review' => 0, 'ignored' => 0, 'failed' => 0 ),
                'args' => array(
                    'only_empty' => ! empty( $args['only_empty'] ),
                    'use_ai' => ! empty( $args['use_ai'] ),
                    'max_fields' => max( 1, min( 100, absint( $args['max_fields'] ) ) ),
                    'search_limit' => max( 1, min( 2, absint( $args['search_limit'] ) ) ),
                    'selected_groups' => array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $args['selected_groups'] ) ) ) ),
                    'groups_filter_enabled' => ! empty( $args['groups_filter_enabled'] ) || array_key_exists( 'selected_groups', $args ),
                    'batch_size' => 5,
                ),
                'errors' => array(),
                'last_result' => array(),
                'results' => array(),
            ),
        ) );

        if ( ! $job_id ) { return array( 'success' => false, 'error' => 'Could not create research job.' ); }

        $this->schedule_cron( $job_id, 30 );
        return array( 'success' => true, 'job_id' => $job_id, 'existing' => false );
    }

    public function tick( $job_id ) {
        $queue = TL_AI_VM_Research_Queue::instance();
        $job = $queue->get( $job_id );
        if ( ! $job ) { return array( 'success' => false, 'error' => 'Research job was not found.' ); }

        if ( ! isset( $job['metadata']['async'] ) || ! $job['metadata']['async'] ) {
            return array( 'success' => false, 'error' => 'This is not an asynchronous research job.' );
        }

        if ( in_array( $job['status'], array( 'completed', 'failed', 'cancelled' ), true ) ) {
            return $this->status_response( $job );
        }

        if ( 'pending' === $job['status'] ) { $queue->start( $job_id ); $job = $queue->get( $job_id ); }

        $meta = isset( $job['metadata'] ) && is_array( $job['metadata'] ) ? $job['metadata'] : array();
        $stage = isset( $meta['stage'] ) ? sanitize_key( $meta['stage'] ) : 'prepare';

        try {
            if ( 'prepare' === $stage ) {
                return $this->prepare( $job );
            }
            if ( 'field' === $stage ) {
                // IMPORTANT: one pipeline stage per AJAX request.
                // The previous 10-iteration loop made a single HTTP request
                // perform several web searches/fetches and could hit the host
                // connection timeout. The UI still polls rapidly, so the queue
                // remains asynchronous without creating long PHP requests.
                return $this->process_current_field( $job );
            }
            if ( 'complete' === $stage ) {
                $queue->complete( $job_id );
                return $this->status_response( $queue->get( $job_id ) );
            }
            $queue->fail( $job_id, 'Unknown asynchronous research stage: ' . $stage );
            return $this->status_response( $queue->get( $job_id ) );
        } catch ( Throwable $e ) {
            $this->append_error( $job, $e->getMessage(), 'system' );
            $queue->fail( $job_id, $e->getMessage() );
            return $this->status_response( $queue->get( $job_id ) );
        }
    }

    public function cancel( $job_id ) {
        $queue = TL_AI_VM_Research_Queue::instance();
        $job = $queue->get( $job_id );
        if ( ! $job ) { return array( 'success' => false, 'error' => 'Research job was not found.' ); }
        $ok = $queue->cancel( $job_id );
        return array( 'success' => $ok, 'status' => 'cancelled', 'job_id' => $job_id );
    }

    public function status( $job_id ) {
        $job = TL_AI_VM_Research_Queue::instance()->get( $job_id );
        if ( ! $job ) { return array( 'success' => false, 'error' => 'Research job was not found.' ); }
        return $this->status_response( $job );
    }

    public function cron_tick( $job_id ) {
        $result = $this->tick( $job_id );
        if ( ! empty( $result['success'] ) && ! in_array( $result['status'], array( 'completed', 'failed', 'cancelled' ), true ) ) {
            $this->schedule_cron( $job_id, 30 );
        }
    }

    private function prepare( $job ) {
        $post_id = absint( $job['post_id'] );
        $post = get_post( $post_id );
        if ( ! $post ) { return $this->fail_response( $job, 'Vehicle post was not found.' ); }

        $analyzer = TL_AI_VM_AI_Field_Analyzer::instance();
        // Local-only analysis keeps this preparation request short. AI semantic analysis
        // can be added per field later without turning preparation into a long request.
        $analysis = $analyzer->analyze_vehicle( $post_id, array( 'use_ai' => false ) );
        if ( empty( $analysis['success'] ) ) { return $this->fail_response( $job, isset( $analysis['error'] ) ? $analysis['error'] : 'Field analysis failed.' ); }

        $fields = $analyzer->get_researchable_fields( $post->post_type );
        if ( ! is_array( $fields ) ) { $fields = array(); }
        if ( ! empty( $job['only_empty'] ) ) {
            $fields = $this->only_empty_fields( $post_id, $fields );
        }

        // Only research the ACF groups explicitly selected by the user.
        $selected_groups = isset( $job['metadata']['args']['selected_groups'] ) && is_array( $job['metadata']['args']['selected_groups'] )
            ? $job['metadata']['args']['selected_groups']
            : array();
        if ( ! empty( $job['metadata']['args']['groups_filter_enabled'] ) ) {
            $fields = array_values( array_filter( $fields, function( $field ) use ( $selected_groups ) {
                return ! empty( $field['group_key'] ) && in_array( sanitize_key( $field['group_key'] ), $selected_groups, true );
            } ) );
        }
        $args = isset( $job['metadata']['args'] ) ? $job['metadata']['args'] : array();
        $max = isset( $args['max_fields'] ) ? max( 1, min( 100, absint( $args['max_fields'] ) ) ) : 50;
        $fields = array_slice( $fields, 0, $max );

        $meta = $job['metadata'];
        $meta['fields'] = array_map( array( $this, 'compact_field' ), $fields );
        $meta['total'] = count( $fields );
        $meta['current_index'] = 0;
        $meta['stage'] = empty( $fields ) ? 'complete' : 'field';
        $meta['field_stage'] = 'search';
        $meta['progress'] = empty( $fields ) ? 100 : 5;
        $meta['batch_size'] = 5;
        $meta['active_fields'] = array();
        $meta['message'] = empty( $fields ) ? 'No empty researchable fields were found.' : 'Vehicle prepared. Starting 5-field parallel research…';
        TL_AI_VM_Research_Queue::instance()->update( $job['id'], array( 'metadata' => $meta ) );
        $job = TL_AI_VM_Research_Queue::instance()->get( $job['id'] );
        if ( empty( $fields ) ) { TL_AI_VM_Research_Queue::instance()->complete( $job['id'] ); $job = TL_AI_VM_Research_Queue::instance()->get( $job['id'] ); }
        return $this->status_response( $job );
    }

    private function process_current_field( $job ) {
        $queue = TL_AI_VM_Research_Queue::instance();
        $meta = $job['metadata'];
        $fields = isset( $meta['fields'] ) && is_array( $meta['fields'] ) ? $meta['fields'] : array();
        $index = isset( $meta['current_index'] ) ? absint( $meta['current_index'] ) : 0;
        $total = count( $fields );
        if ( $index >= $total ) { return $this->complete_job( $job ); }
        $field = $fields[ $index ];
        $label = ! empty( $field['label'] ) ? $field['label'] : ( ! empty( $field['name'] ) ? $field['name'] : 'Field ' . ( $index + 1 ) );
        $stage = isset( $meta['field_stage'] ) ? sanitize_key( $meta['field_stage'] ) : 'search';

        if ( 'search' === $stage ) {
            // AI-first: when Gemini Worker is enabled, send the whole 5-field lane
            // to Gemini in one request. Web search is not touched unless the batch
            // explicitly reports that AI could not answer a field.
            if ( $this->ai_batch_enabled() ) {
                $ai_result = $this->process_ai_batch( $job, $batch_fields = array_slice( $fields, (int) floor( $index / 5 ) * 5, 5 ) );
                if ( ! empty( $ai_result['handled'] ) ) {
                    $meta = $queue->get( $job['id'] )['metadata'];
                    $batch_start = (int) floor( $index / 5 ) * 5;
                    $batch_end = min( $total, $batch_start + 5 );
                    $meta['current_index'] = $batch_end;
                    $meta['batch_start'] = $batch_start;
                    $meta['batch_end'] = $batch_end;
                    $meta['field_stage'] = 'search';
                    $meta['stage'] = $batch_end >= $total ? 'complete' : 'field';
                    $meta['progress'] = $batch_end >= $total ? 100 : max( 5, min( 95, 5 + (int) floor( ( $batch_end / max( 1, $total ) ) * 90 ) ) );
                    $meta['message'] = $batch_end >= $total ? 'Gemini-first research completed.' : sprintf( 'Gemini completed lane %d–%d. Continuing…', $batch_start + 1, $batch_end );
                    $queue->update( $job['id'], array( 'metadata' => $meta ) );
                    if ( $batch_end >= $total ) { $queue->complete( $job['id'] ); }
                    return $this->status_response( $queue->get( $job['id'] ) );
                }
            }
            if ( ! $this->ai_batch_enabled() ) {
                $meta['message'] = 'Gemini Worker is not enabled/configured for batch research; using Web fallback.';
                $queue->update( $job['id'], array( 'metadata' => $meta ) );
            }
            $batch_size = 5;
            $batch_start = (int) floor( $index / $batch_size ) * $batch_size;
            $batch_end   = min( $total, $batch_start + $batch_size );
            $batch_fields = array_slice( $fields, $batch_start, $batch_end - $batch_start );
            $meta['active_fields'] = array();
            foreach ( $batch_fields as $bi => $bf ) {
                $meta['active_fields'][] = array(
                    'index' => $batch_start + $bi,
                    'field_key' => ! empty( $bf['key'] ) ? $bf['key'] : ( ! empty( $bf['name'] ) ? $bf['name'] : '' ),
                    'label' => ! empty( $bf['label'] ) ? $bf['label'] : ( ! empty( $bf['name'] ) ? $bf['name'] : 'Field ' . ( $batch_start + $bi + 1 ) ),
                    'status' => 'queued',
                    'stage' => 'waiting',
                    'message' => 'Waiting for shared research…',
                    'sources' => array(),
                    'value' => '',
                );
            }

            // Search a small number of combined queries for the whole batch instead of
            // performing one Google request per field. This is the main speed optimization.
            $queries = $this->build_batch_queries( $job['post_id'], $batch_fields );
            $domains = $this->collect_batch_domains( $job['post_id'], $batch_fields );
            $search_args = isset( $meta['args'] ) ? $meta['args'] : array();
            $search_args['search_limit'] = max( 3, min( 8, absint( $search_args['search_limit'] ?? 5 ) ) );
            $search_args['queries'] = $queries;
            $search_args['domains'] = $domains;
            $search_args['fetch_pages'] = false;

            $meta['message'] = sprintf( 'Running 5 research lanes: fields %d–%d of %d…', $batch_start + 1, $batch_end, $total );
            foreach ( $meta['active_fields'] as $ai => $af ) { $meta['active_fields'][$ai]['status'] = 'researching'; $meta['active_fields'][$ai]['stage'] = 'search'; $meta['active_fields'][$ai]['message'] = 'Searching shared sources and comparing evidence…'; }
            $meta['progress'] = max( 5, min( 88, 5 + (int) floor( ( $batch_start / max( 1, $total ) ) * 80 ) ) );
            $meta['batch_start'] = $batch_start;
            $meta['batch_end'] = $batch_end;
            $queue->update( $job['id'], array( 'metadata' => $meta ) );

            $result = TL_AI_VM_Web_Research_Engine::instance()->research( $job['post_id'], array(
                'queries' => $queries,
                'domains' => $domains,
                'limit' => 8,
                'fetch_pages' => false,
                'max_queries' => 3,
            ) );
            if ( empty( $result['success'] ) || empty( $result['results'] ) ) {
                foreach ( $batch_fields as $bf ) {
                    $this->append_error( $job, isset( $result['error'] ) ? $result['error'] : 'No batch search results.', isset( $bf['name'] ) ? $bf['name'] : '' );
                }
                $meta = $queue->get( $job['id'] )['metadata'];
                $meta['current_index'] = $batch_end;
                $meta['field_stage'] = 'search';
                if ( $batch_end >= $total ) { $meta['stage'] = 'complete'; $meta['progress'] = 100; $meta['message'] = 'Research completed with some failed fields.'; $queue->update( $job['id'], array( 'metadata' => $meta ) ); $queue->complete( $job['id'] ); return $this->status_response( $queue->get( $job['id'] ) ); }
                $queue->update( $job['id'], array( 'metadata' => $meta ) );
                return $this->status_response( $queue->get( $job['id'] ) );
            }

            $meta['batch_sources'] = isset( $result['results'] ) && is_array( $result['results'] ) ? array_slice( $result['results'], 0, 8 ) : array();
            $meta['batch_queries'] = isset( $result['queries'] ) ? $result['queries'] : $queries;
            $meta['batch_source_index'] = 0;
            $meta['research'] = array( 'queries' => $meta['batch_queries'], 'results' => $meta['batch_sources'] );
            $meta['source_index'] = 0;
            $meta['field_stage'] = 'batch_fetch';
            $meta['message'] = sprintf( 'Shared evidence found: %d sources. Five fields are now being compared against the same evidence…', count( $meta['batch_sources'] ) );
            foreach ( $meta['active_fields'] as $ai => $af ) { $meta['active_fields'][$ai]['stage'] = 'source_review'; $meta['active_fields'][$ai]['message'] = 'Comparing source snippets/page data…'; $meta['active_fields'][$ai]['sources'] = array_slice( array_map( function( $src ) { return array( 'title' => isset($src['title']) ? $src['title'] : '', 'url' => isset($src['url']) ? $src['url'] : '', 'domain' => isset($src['domain']) ? $src['domain'] : '' ); }, $meta['batch_sources'] ), 0, 5 ); }
            $queue->update( $job['id'], array( 'metadata' => $meta ) );
            return $this->status_response( $queue->get( $job['id'] ) );
        }

        if ( 'batch_fetch' === $stage ) {
            $sources = isset( $meta['batch_sources'] ) && is_array( $meta['batch_sources'] ) ? $meta['batch_sources'] : array();
            $source_index = isset( $meta['batch_source_index'] ) ? absint( $meta['batch_source_index'] ) : 0;
            if ( $source_index >= count( $sources ) ) {
                $meta['research']['results'] = $sources;
                $meta['field_stage'] = 'extract';
                $meta['message'] = sprintf( 'Five active fields: extracting and comparing evidence… (%d–%d)', $batch_start + 1, $batch_end );
                foreach ( $meta['active_fields'] as $ai => $af ) { $meta['active_fields'][$ai]['stage'] = 'extract'; $meta['active_fields'][$ai]['status'] = 'processing'; $meta['active_fields'][$ai]['message'] = 'Comparing evidence and extracting the most reliable value…'; }
                $queue->update( $job['id'], array( 'metadata' => $meta ) );
                return $this->status_response( $queue->get( $job['id'] ) );
            }
            $url = ! empty( $sources[ $source_index ]['url'] ) ? $sources[ $source_index ]['url'] : '';
            if ( $url && class_exists( 'TL_AI_VM_Page_Fetcher' ) ) {
                $fetched = TL_AI_VM_Page_Fetcher::instance()->fetch( $url, array( 'timeout' => 7 ) );
                if ( is_array( $fetched ) && ! empty( $fetched['content'] ) ) {
                    $sources[ $source_index ]['content'] = $fetched['content'];
                    $sources[ $source_index ]['page'] = $fetched;
                }
            }
            $meta['batch_sources'] = $sources;
            $meta['batch_source_index'] = $source_index + 1;
            $queue->update( $job['id'], array( 'metadata' => $meta ) );
            return $this->status_response( $queue->get( $job['id'] ) );
        }

        if ( 'fetch' === $stage ) {
            $sources = isset( $meta['research']['results'] ) && is_array( $meta['research']['results'] ) ? $meta['research']['results'] : array();
            $source_index = isset( $meta['source_index'] ) ? absint( $meta['source_index'] ) : 0;
            if ( $source_index >= count( $sources ) ) {
                $meta['field_stage'] = 'extract';
                $meta['message'] = 'Sources collected. Extracting the vehicle value…';
                $queue->update( $job['id'], array( 'metadata' => $meta ) );
                return $this->status_response( $queue->get( $job['id'] ) );
            }
            $url = ! empty( $sources[ $source_index ]['url'] ) ? $sources[ $source_index ]['url'] : '';
            if ( $url && class_exists( 'TL_AI_VM_Page_Fetcher' ) ) {
                $fetched = TL_AI_VM_Page_Fetcher::instance()->fetch( $url, array( 'timeout' => 7 ) );
                if ( is_array( $fetched ) && ! empty( $fetched['content'] ) ) {
                    $sources[ $source_index ]['content'] = $fetched['content'];
                    $sources[ $source_index ]['page'] = $fetched;
                }
            }
            // One page per request keeps the request bounded. Search snippets remain usable
            // when a page blocks automated fetching.
            $meta['research']['results'] = $sources;
            $meta['source_index'] = $source_index + 1;
            $meta['field_stage'] = 'extract';
            $meta['message'] = 'Best source collected. Asking AI for a structured value…';
            $queue->update( $job['id'], array( 'metadata' => $meta ) );
            return $this->status_response( $queue->get( $job['id'] ) );
        }

        if ( 'extract' === $stage ) {
            $sources = isset( $meta['batch_sources'] ) && is_array( $meta['batch_sources'] ) ? $meta['batch_sources'] : ( isset( $meta['research']['results'] ) && is_array( $meta['research']['results'] ) ? $meta['research']['results'] : array() );
            if ( isset( $meta['active_fields'] ) && is_array( $meta['active_fields'] ) ) {
                foreach ( $meta['active_fields'] as $ai => $af ) {
                    if ( isset($af['index']) && absint($af['index']) === $index ) { $meta['active_fields'][$ai]['status'] = 'processing'; $meta['active_fields'][$ai]['stage'] = 'extract'; $meta['active_fields'][$ai]['message'] = 'Comparing source values for this field…'; }
                }
            }
            $answer = TL_AI_VM_Vehicle_Research_Runner::instance()->extract_field_answer( $job['post_id'], $field, $sources );
            if ( empty( $answer['success'] ) ) {
                $this->append_error( $job, isset( $answer['error'] ) ? $answer['error'] : 'AI extraction failed.', isset( $field['name'] ) ? $field['name'] : '' );
                $job = $queue->get( $job['id'] );
                return $this->advance_field( $job, $job['metadata'], $index, $total, $label );
            }
            $meta['answer'] = $answer;
            $meta['field_stage'] = 'finalize';
            $meta['progress'] = max( 10, min( 95, 5 + (int) floor( ( ( $index + 0.8 ) / max( 1, $total ) ) * 90 ) ) );
            $meta['message'] = sprintf( 'Field %d/%d extracted. Validating while the other lanes continue…', $index + 1, $total );
            if ( isset( $meta['active_fields'] ) && is_array( $meta['active_fields'] ) ) { foreach ( $meta['active_fields'] as $ai => $af ) { if ( isset($af['index']) && absint($af['index']) === $index ) { $meta['active_fields'][$ai]['status']='validating'; $meta['active_fields'][$ai]['stage']='validation'; $meta['active_fields'][$ai]['message']='Checking type, confidence and learned rules…'; $meta['active_fields'][$ai]['value']=isset($answer['value'])?$answer['value']:''; } } }
            $queue->update( $job['id'], array( 'metadata' => $meta ) );
            return $this->status_response( $queue->get( $job['id'] ) );
        }

        if ( 'finalize' === $stage ) {
            $research = isset( $meta['research'] ) ? $meta['research'] : array();
            $answer = isset( $meta['answer'] ) ? $meta['answer'] : array();
            $result = TL_AI_VM_Vehicle_Research_Runner::instance()->finalize_field( $job['post_id'], $field, $research, $answer );
            if ( empty( $result['success'] ) ) {
                $this->append_error( $job, isset( $result['error'] ) ? $result['error'] : 'Could not finalize research result.', isset( $field['name'] ) ? $field['name'] : '' );
            } else {
                foreach ( array( 'auto_written', 'review', 'ignored' ) as $key ) {
                    $meta['stats'][ $key ] = ( isset( $meta['stats'][ $key ] ) ? absint( $meta['stats'][ $key ] ) : 0 ) + ( isset( $result[ $key ] ) ? absint( $result[ $key ] ) : 0 );
                }
                $meta['stats']['created'] = ( isset( $meta['stats']['created'] ) ? absint( $meta['stats']['created'] ) : 0 ) + 1;
                $meta['last_result'] = $result;
                if ( isset( $meta['active_fields'] ) && is_array( $meta['active_fields'] ) ) { foreach ( $meta['active_fields'] as $ai => $af ) { if ( isset($af['index']) && absint($af['index']) === $index ) { $meta['active_fields'][$ai]['status']='done'; $meta['active_fields'][$ai]['stage']='complete'; $meta['active_fields'][$ai]['message']='Research result stored; ready for approval/writing.'; if(isset($answer['value'])) $meta['active_fields'][$ai]['value']=$answer['value']; } } }
                if ( ! isset( $meta['results'] ) || ! is_array( $meta['results'] ) ) { $meta['results'] = array(); }
                if ( ! empty( $result['result_id'] ) && class_exists( 'TL_AI_VM_Research_Result' ) ) {
                    $stored_result = TL_AI_VM_Research_Result::instance()->get( $result['result_id'] );
                    if ( is_array( $stored_result ) ) {
                        $meta['results'][] = $this->compact_result( $stored_result );
                        if ( count( $meta['results'] ) > 100 ) { $meta['results'] = array_slice( $meta['results'], -100 ); }
                    }
                }
            }
            return $this->advance_field( $job, $meta, $index, $total, $label );
        }

        $meta['field_stage'] = 'search';
        $queue->update( $job['id'], array( 'metadata' => $meta ) );
        return $this->status_response( $queue->get( $job['id'] ) );
    }

    private function advance_field( $job, $meta, $index, $total, $label ) {
        $queue = TL_AI_VM_Research_Queue::instance();
        $meta['current_index'] = $index + 1;
        if ( isset( $meta['active_fields'] ) && is_array( $meta['active_fields'] ) ) { foreach ( $meta['active_fields'] as $ai => $af ) { if ( isset($af['index']) && absint($af['index']) === $index && !empty($af['status']) && 'done' !== $af['status'] ) { $meta['active_fields'][$ai]['status']='done'; $meta['active_fields'][$ai]['stage']='complete'; } } }
        $meta['answer'] = array();
        $meta['source_index'] = 0;
        $batch_size = 5;
        $batch_end = isset( $meta['batch_end'] ) ? absint( $meta['batch_end'] ) : min( $total, $index + 1 );
        if ( $meta['current_index'] >= $total ) {
            $meta['stage'] = 'complete'; $meta['progress'] = 100; $meta['message'] = 'Research completed.';
            $queue->update( $job['id'], array( 'metadata' => $meta ) );
            $queue->complete( $job['id'] );
        } else {
            $meta['stage'] = 'field'; $meta['field_stage'] = 'search';
            if ( $meta['current_index'] >= $batch_end ) { $meta['batch_sources'] = array(); $meta['batch_queries'] = array(); $meta['batch_source_index'] = 0; $meta['research'] = array(); }
            $meta['progress'] = max( 5, min( 95, 5 + (int) floor( ( $meta['current_index'] / max( 1, $total ) ) * 90 ) ) );
            $meta['message'] = sprintf( 'Completed field %d of %d. Continuing…', $meta['current_index'], $total );
            $queue->update( $job['id'], array( 'metadata' => $meta ) );
        }
        return $this->status_response( $queue->get( $job['id'] ) );
    }

    private function complete_job( $job ) {
        $queue = TL_AI_VM_Research_Queue::instance();
        $meta = $job['metadata']; $meta['stage'] = 'complete'; $meta['progress'] = 100; $meta['message'] = 'Research completed.';
        $queue->update( $job['id'], array( 'metadata' => $meta ) ); $queue->complete( $job['id'] );
        return $this->status_response( $queue->get( $job['id'] ) );
    }

    private function build_batch_queries( $post_id, $fields ) {
        $title = get_the_title( $post_id );
        $chunks = array_chunk( $fields, 4 );
        $queries = array();
        foreach ( $chunks as $chunk ) {
            $labels = array();
            foreach ( $chunk as $f ) {
                $v = ! empty( $f['label'] ) ? $f['label'] : ( ! empty( $f['name'] ) ? $f['name'] : '' );
                if ( $v ) { $labels[] = sanitize_text_field( $v ); }
            }
            if ( ! empty( $labels ) ) {
                $queries[] = $title . ' ' . implode( ' ', $labels ) . ' specifications';
            }
        }
        if ( empty( $queries ) ) { $queries[] = $title . ' technical specifications'; }
        return array_slice( array_values( array_unique( $queries ) ), 0, 3 );
    }

    private function collect_batch_domains( $post_id, $fields ) {
        $domains = array();
        if ( class_exists( 'TL_AI_VM_Source_Manager' ) ) {
            $domains = (array) TL_AI_VM_Source_Manager::instance()->get_research_domains( $post_id, '', 6 );
        }
        if ( class_exists( 'TL_AI_VM_Field_Intelligence' ) ) {
            foreach ( $fields as $f ) {
                $name = ! empty( $f['name'] ) ? $f['name'] : '';
                if ( ! $name ) { continue; }
                $fd = TL_AI_VM_Field_Intelligence::instance()->get_domains( $name, ! empty( $f['group_key'] ) ? $f['group_key'] : '' );
                $domains = array_merge( $domains, (array) $fd );
            }
        }
        if ( class_exists( 'TL_AI_VM_Learning_Memory' ) ) {
            foreach ( $fields as $f ) {
                $name = ! empty( $f['name'] ) ? $f['name'] : '';
                if ( $name ) { $domains = array_merge( $domains, (array) TL_AI_VM_Learning_Memory::instance()->learned_sources( $name, 3 ) ); }
            }
        }
        $domains = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $domains ) ) ) );
        return array_slice( $domains, 0, 8 );
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

    public function compact_field( $field ) {
        return array(
            'group_key' => isset( $field['group_key'] ) ? $field['group_key'] : '',
            'group_title' => isset( $field['group_title'] ) ? $field['group_title'] : '',
            'key' => isset( $field['key'] ) ? $field['key'] : '',
            'name' => isset( $field['name'] ) ? $field['name'] : '',
            'label' => isset( $field['label'] ) ? $field['label'] : '',
            'type' => isset( $field['type'] ) ? $field['type'] : '',
            'parent' => isset( $field['parent'] ) ? $field['parent'] : '',
            'ai' => isset( $field['ai'] ) && is_array( $field['ai'] ) ? $field['ai'] : array(),
        );
    }

    private function ai_batch_enabled() {
        if ( ! class_exists( 'TL_AI_VM_AI_Client' ) ) { return false; }
        $client = TL_AI_VM_AI_Client::instance();
        return $client->get_provider_enabled( 'gemini_worker' ) && $client->provider_configured( 'gemini_worker' ) && method_exists( $client, 'request_batch_json' );
    }

    private function process_ai_batch( $job, $batch_fields ) {
        $queue = TL_AI_VM_Research_Queue::instance();
        $post_id = absint( $job['post_id'] );
        $title = get_the_title( $post_id );
        $client = TL_AI_VM_AI_Client::instance();
        $runner = TL_AI_VM_Vehicle_Research_Runner::instance();
        $handled = false;
        $attempted = false;
        $ai_fields = array();

        foreach ( (array) $batch_fields as $field ) {
            $key = ! empty( $field['key'] ) ? $field['key'] : ( ! empty( $field['name'] ) ? $field['name'] : '' );
            if ( ! $key ) { continue; }
            $intel = class_exists( 'TL_AI_VM_Field_Intelligence' ) ? TL_AI_VM_Field_Intelligence::instance() : null;
            if ( $intel && $intel->is_internal( $field ) ) {
                $resolved = $intel->resolve_internal_asset( $post_id, $field );
                if ( ! empty( $resolved['success'] ) ) {
                    $research = array( 'queries' => array( 'Internal Tuningland c-cat asset lookup' ), 'results' => array( array( 'title' => 'Tuningland c-cat', 'url' => isset($resolved['term_url']) ? $resolved['term_url'] : '', 'domain' => wp_parse_url( home_url('/'), PHP_URL_HOST ), 'evidence' => isset($resolved['url']) ? $resolved['url'] : '' ) ) );
                    $answer = array( 'success'=>true, 'value'=>$resolved['value'], 'raw'=>isset($resolved['url'])?$resolved['url']:'', 'method'=>isset($resolved['method'])?$resolved['method']:'internal_asset' );
                    $result = $runner->finalize_field( $post_id, $field, $research, $answer );
                    $handled = true;
                    $this->mark_ai_field_done( $job, $key, $result, $answer['value'], 'Internal c-cat asset resolver' );
                    continue;
                }
            }
            $ai_fields[] = array(
                'key' => $key,
                'name' => isset($field['name']) ? $field['name'] : '',
                'label' => isset($field['label']) ? $field['label'] : '',
                'type' => isset($field['type']) ? $field['type'] : '',
                'group' => isset($field['group_title']) ? $field['group_title'] : '',
                'expected_data_type' => isset($field['ai']['expected_data_type']) ? $field['ai']['expected_data_type'] : '',
                'unit' => isset($field['ai']['unit']) ? $field['ai']['unit'] : '',
                'rule' => class_exists('TL_AI_VM_Field_Intelligence') ? TL_AI_VM_Field_Intelligence::instance()->get_rule($key, isset($field['group_key'])?$field['group_key']:'') : array(),
            );
        }

        if ( ! empty( $ai_fields ) ) {
            $attempted = true;
            $meta = $queue->get( $job['id'] )['metadata'];
            $meta['message'] = 'Gemini is researching the active 5-field lane…';
            foreach ( (array) $meta['active_fields'] as $i => $af ) { $meta['active_fields'][$i]['status']='researching'; $meta['active_fields'][$i]['stage']='gemini'; $meta['active_fields'][$i]['message']='Asking Gemini + Google grounding when needed…'; }
            $queue->update( $job['id'], array('metadata'=>$meta) );

            $result = $client->request_batch_json(
                array( 'name' => $title, 'post_id' => $post_id, 'brand' => get_post_meta($post_id,'brand',true) ),
                $ai_fields,
                array( 'timeout' => 55, 'max_output_tokens' => 5000 )
            );
            if ( ! empty( $result['success'] ) ) {
                $handled = true;
                $by_key = array();
                foreach ( (array) $result['results'] as $item ) { if ( ! empty($item['field_key']) ) { $by_key[ sanitize_key($item['field_key']) ] = $item; } }
                foreach ( $ai_fields as $field_info ) {
                    $key = sanitize_key($field_info['key']);
                    if ( empty( $by_key[$key] ) ) { continue; }
                    $item = $by_key[$key];
                    if ( empty($item['found']) || ! array_key_exists('value',$item) || null === $item['value'] || '' === (string)$item['value'] ) {
                        $this->append_error( $job, 'Gemini could not determine a reliable value; web fallback is available.', $key );
                        continue;
                    }
                    $field = $this->find_batch_field( $batch_fields, $key );
                    if ( ! $field ) { continue; }
                    $answer = array( 'success'=>true, 'value'=>$item['value'], 'raw'=>isset($item['raw'])?$item['raw']:wp_json_encode($item), 'method'=>'gemini_worker', 'confidence'=>isset($item['confidence'])?$item['confidence']:0, 'learned_rules'=>isset($item['applied_rules'])?$item['applied_rules']:array() );
                    $research = array( 'queries'=>isset($item['queries'])?(array)$item['queries']:array(), 'results'=>isset($item['sources'])?(array)$item['sources']:array() );
                    $final = $runner->finalize_field( $post_id, $field, $research, $answer );
                    $this->mark_ai_field_done( $job, $key, $final, $item['value'], 'Gemini Worker' );
                }
            }
        }
        // If Gemini Worker was actually attempted, do NOT silently fall back to the
        // legacy web-first pipeline. A transport/provider failure must be visible to
        // the user rather than masquerading as a web research run.
        if ( $attempted ) { $handled = true; }
        return array( 'handled' => $handled, 'attempted' => $attempted );
    }

    private function find_batch_field( $fields, $key ) {
        foreach ( (array)$fields as $field ) { $fk = !empty($field['key'])?$field['key']:(!empty($field['name'])?$field['name']:''); if ( sanitize_key($fk) === sanitize_key($key) ) return $field; }
        return null;
    }

    private function mark_ai_field_done( $job, $key, $result, $value, $message ) {
        $queue = TL_AI_VM_Research_Queue::instance();
        $meta = $queue->get( $job['id'] )['metadata'];
        if ( ! isset($meta['stats']) || ! is_array($meta['stats']) ) $meta['stats']=array('created'=>0,'auto_written'=>0,'review'=>0,'ignored'=>0);
        if ( ! empty($result['success']) ) { $meta['stats']['created'] = absint($meta['stats']['created']) + 1; $meta['last_result']=$result; }
        foreach ( (array)$meta['active_fields'] as $i=>$af ) { if ( (isset($af['field_key']) && sanitize_key($af['field_key'])===sanitize_key($key)) || (isset($af['label']) && sanitize_key($af['label']) === sanitize_key($key)) ) { $meta['active_fields'][$i]['status']='done'; $meta['active_fields'][$i]['stage']='complete'; $meta['active_fields'][$i]['message']=$message; $meta['active_fields'][$i]['value']=$value; } }
        $queue->update($job['id'],array('metadata'=>$meta));
    }

    private function append_error( $job, $error, $field = '' ) {
        $meta = $job['metadata'];
        if ( ! isset( $meta['errors'] ) || ! is_array( $meta['errors'] ) ) { $meta['errors'] = array(); }
        $meta['errors'][] = array( 'field' => sanitize_text_field( $field ), 'error' => sanitize_text_field( $error ), 'time' => current_time( 'mysql' ) );
        if ( count( $meta['errors'] ) > 50 ) { $meta['errors'] = array_slice( $meta['errors'], -50 ); }
        TL_AI_VM_Research_Queue::instance()->update( $job['id'], array( 'metadata' => $meta ) );
    }

    private function fail_response( $job, $error ) {
        TL_AI_VM_Research_Queue::instance()->fail( $job['id'], $error );
        return $this->status_response( TL_AI_VM_Research_Queue::instance()->get( $job['id'] ) );
    }

    private function status_response( $job ) {
        if ( ! $job ) { return array( 'success' => false, 'error' => 'Research job not found.' ); }
        $meta = isset( $job['metadata'] ) && is_array( $job['metadata'] ) ? $job['metadata'] : array();
        return array(
            'success' => true,
            'job_id' => $job['id'],
            'status' => $job['status'],
            'progress' => isset( $meta['progress'] ) ? absint( $meta['progress'] ) : 0,
            'stage' => isset( $meta['stage'] ) ? $meta['stage'] : '',
            'message' => isset( $meta['message'] ) ? sanitize_text_field( $meta['message'] ) : '',
            'current' => isset( $meta['current_index'] ) ? absint( $meta['current_index'] ) : 0,
            'total' => isset( $meta['total'] ) ? absint( $meta['total'] ) : 0,
            'batch_start' => isset( $meta['batch_start'] ) ? absint( $meta['batch_start'] ) : 0,
            'batch_end' => isset( $meta['batch_end'] ) ? absint( $meta['batch_end'] ) : 0,
            'active_fields' => isset( $meta['active_fields'] ) && is_array( $meta['active_fields'] ) ? $meta['active_fields'] : array(),
            'batch_queries' => isset( $meta['batch_queries'] ) && is_array( $meta['batch_queries'] ) ? $meta['batch_queries'] : array(),
            'batch_sources' => isset( $meta['batch_sources'] ) && is_array( $meta['batch_sources'] ) ? array_map( function( $src ) { return array( 'title' => isset($src['title']) ? $src['title'] : '', 'url' => isset($src['url']) ? $src['url'] : '', 'domain' => isset($src['domain']) ? $src['domain'] : '' ); }, array_slice( $meta['batch_sources'], 0, 8 ) ) : array(),
            'stats' => isset( $meta['stats'] ) && is_array( $meta['stats'] ) ? $meta['stats'] : array(),
            'errors' => isset( $meta['errors'] ) && is_array( $meta['errors'] ) ? array_slice( $meta['errors'], -5 ) : array(),
            'last_result' => isset( $meta['last_result'] ) && is_array( $meta['last_result'] ) ? $meta['last_result'] : array(),
            'results' => isset( $meta['results'] ) && is_array( $meta['results'] ) ? array_slice( $meta['results'], -100 ) : array(),
        );
    }

    private function compact_result( $result ) {
        $value = isset( $result['normalized_value'] ) ? $result['normalized_value'] : null;
        if ( is_array( $value ) ) { $value = array_values( $value ); }
        $confidence = 0;
        if ( isset( $result['confidence']['percentage'] ) && is_numeric( $result['confidence']['percentage'] ) ) { $confidence = (int) $result['confidence']['percentage']; }
        elseif ( isset( $result['confidence']['score'] ) && is_numeric( $result['confidence']['score'] ) ) { $confidence = (int) round( (float) $result['confidence']['score'] * 100 ); }
        return array(
            'id' => isset( $result['id'] ) ? sanitize_text_field( $result['id'] ) : '',
            'field_key' => isset( $result['field']['key'] ) ? sanitize_text_field( $result['field']['key'] ) : '',
            'field_name' => isset( $result['field']['name'] ) ? sanitize_text_field( $result['field']['name'] ) : '',
            'field_label' => isset( $result['field']['label'] ) ? sanitize_text_field( $result['field']['label'] ) : '',
            'value' => $value,
            'confidence' => $confidence,
            'decision' => isset( $result['decision'] ) ? sanitize_key( $result['decision'] ) : 'review',
            'status' => isset( $result['status'] ) ? sanitize_key( $result['status'] ) : 'researched',
            'sources' => isset( $result['sources'] ) && is_array( $result['sources'] ) ? array_slice( $result['sources'], 0, 5 ) : array(),
            'method' => isset( $result['metadata']['method'] ) ? sanitize_key( $result['metadata']['method'] ) : '',
        );
    }

    private function schedule_cron( $job_id, $delay = 30 ) {
        if ( wp_next_scheduled( 'tl_ai_vm_async_research_cron', array( $job_id ) ) ) { return; }
        wp_schedule_single_event( time() + max( 10, absint( $delay ) ), 'tl_ai_vm_async_research_cron', array( $job_id ) );
    }
}
