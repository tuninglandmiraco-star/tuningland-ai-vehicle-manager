<?php
/**
 * AI Field Analyzer
 *
 * Dynamically analyzes the discovered ACF schema. No vehicle fields are hard-coded.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TL_AI_VM_AI_Field_Analyzer {
    private static $instance = null;
    private $option_prefix = 'tl_ai_vm_field_analysis_';

    public static function instance() {
        if ( null === self::$instance ) { self::$instance = new self(); }
        return self::$instance;
    }
    private function __construct() {}

    public function analyze_vehicle( $vehicle_id, $options = array() ) {
        $vehicle_id = absint( $vehicle_id );
        if ( ! $vehicle_id ) { return array( 'success' => false, 'error' => 'Invalid vehicle ID.' ); }
        $post = get_post( $vehicle_id );
        if ( ! $post ) { return array( 'success' => false, 'error' => 'Vehicle post was not found.' ); }
        return $this->analyze( $vehicle_id, $options );
    }

    public function analyze( $vehicle_id = 0, $options = array() ) {
        $vehicle_id = absint( $vehicle_id );
        $post_type = '';
        if ( $vehicle_id ) { $post = get_post( $vehicle_id ); if ( $post ) { $post_type = $post->post_type; } }
        if ( ! $post_type ) { $post_type = sanitize_key( get_option( 'tl_ai_vm_vehicle_cpt', '' ) ); }
        if ( ! $post_type ) { return array( 'success' => false, 'error' => 'Vehicle CPT is not selected.' ); }

        $schema = TL_AI_VM_Field_Schema::instance()->build( $post_type );
        if ( empty( $schema['groups'] ) ) { return array( 'success' => false, 'error' => 'No ACF fields were found.' ); }
        $fields = TL_AI_VM_Field_Schema::instance()->get_fields( $post_type );
        if ( empty( $fields ) ) { return array( 'success' => false, 'error' => 'No fields were found.' ); }

        $results = array();
        $client = class_exists( 'TL_AI_VM_AI_Client' ) ? TL_AI_VM_AI_Client::instance() : null;
        $auto_ai = $client && $client->is_configured();

        foreach ( $fields as $field ) {
            $analysis = $this->local_analysis( $field );
            if ( $auto_ai && ! empty( $options['use_ai'] ) ) {
                $ai = $this->analyze_field_with_ai( $field, $post_type );
                if ( ! empty( $ai['success'] ) && is_array( $ai['json'] ) ) { $analysis = array_merge( $analysis, $ai['json'] ); }
            }
            $analysis['status'] = 'analyzed';
            $analysis['mode'] = isset( $analysis['mode'] ) ? $analysis['mode'] : 'auto';
            $results[ $field['key'] ? $field['key'] : $field['name'] ] = $analysis;
        }

        update_option( $this->option_prefix . $post_type, array( 'version' => '1.0.0', 'updated_at' => current_time( 'mysql' ), 'fields' => $results ), false );
        return array( 'success' => true, 'post_type' => $post_type, 'vehicle_id' => $vehicle_id, 'total' => count( $results ), 'fields' => $results );
    }

    public function get_analysis( $post_type ) {
        $post_type = sanitize_key( $post_type );
        $data = get_option( $this->option_prefix . $post_type, array() );
        return is_array( $data ) ? $data : array();
    }

    public function get_researchable_fields( $post_type ) {
        $fields = TL_AI_VM_Field_Schema::instance()->get_fields( $post_type );
        $analysis = $this->get_analysis( $post_type );
        $output = array();
        foreach ( $fields as $field ) {
            $key = ! empty( $field['key'] ) ? $field['key'] : $field['name'];
            $ai = isset( $analysis['fields'][ $key ] ) ? $analysis['fields'][ $key ] : $this->local_analysis( $field );
            $internal_asset = class_exists( 'TL_AI_VM_Field_Intelligence' ) && TL_AI_VM_Field_Intelligence::instance()->is_internal( $field );
            if ( ! empty( $ai['researchable'] ) || $internal_asset || in_array( $field['type'], array( 'text','textarea','number','select','url','date_picker','range','image','gallery','file' ), true ) ) {
                $field['ai'] = $ai; $field['semantic_name'] = isset( $ai['semantic_name'] ) ? $ai['semantic_name'] : $field['name']; $field['semantic_description'] = isset( $ai['meaning'] ) ? $ai['meaning'] : ''; $output[] = $field;
            }
        }
        return $output;
    }

    private function local_analysis( $field ) {
        $label = isset( $field['label'] ) ? trim( (string) $field['label'] ) : '';
        $name  = isset( $field['name'] ) ? trim( (string) $field['name'] ) : '';
        $text  = strtolower( $label . ' ' . str_replace( '_', ' ', $name ) );
        $unit = null;
        foreach ( array( 'kw','hp','ps','cc','cm','mm','kg','l','litre','liter','inch','in' ) as $candidate ) { if ( preg_match( '/\b' . preg_quote( $candidate, '/' ) . '\b/i', $text ) ) { $unit = $candidate; break; } }
        return array( 'semantic_name' => $name, 'meaning' => $label, 'search_terms' => array_values( array_unique( array_filter( preg_split( '/\s+/', $label . ' ' . str_replace( '_', ' ', $name ) ) ) ) ), 'expected_data_type' => $this->map_type( isset( $field['type'] ) ? $field['type'] : '' ), 'unit' => $unit, 'confidence' => 0.35, 'status' => 'analyzed', 'mode' => 'auto', 'researchable' => true );
    }

    private function map_type( $type ) {
        $map = array( 'number'=>'number', 'range'=>'number', 'true_false'=>'boolean', 'date_picker'=>'date', 'date_time_picker'=>'datetime', 'image'=>'media', 'file'=>'media', 'url'=>'url', 'email'=>'email', 'select'=>'enum', 'checkbox'=>'enum', 'radio'=>'enum' );
        return isset( $map[ $type ] ) ? $map[ $type ] : 'string';
    }

    private function analyze_field_with_ai( $field, $post_type ) {
        $prompt = "Analyze this WordPress ACF field semantically. Do not invent facts. Return JSON only with keys: semantic_name, meaning, search_terms (array), expected_data_type, unit, confidence (0..1), researchable (boolean), mode. CPT: " . $post_type . "\nFIELD:\n" . wp_json_encode( $field, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        return TL_AI_VM_AI_Client::instance()->request_json( $prompt, 'You are a schema analyst. Return valid JSON only.', array( 'temperature' => 0 ) );
    }
}
