<?php

/**
 * Plugin Name: Tuningland AI Vehicle Manager
 * Plugin URI: https://www.tuningland.ir/
 * Description: Dynamic vehicle database manager with ACF schema discovery, AI semantic analysis, web research and controlled data workflows.
 * Version: 7.10.4
 * Author: Tuningland
 * Author URI: https://www.tuningland.ir/
 * Text Domain: tuningland-ai-vehicle-manager
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! defined( 'TL_AI_VM_VERSION' ) ) { define( 'TL_AI_VM_VERSION', '7.10.4' ); }
if ( ! defined( 'TL_AI_VM_FILE' ) ) { define( 'TL_AI_VM_FILE', __FILE__ ); }
if ( ! defined( 'TL_AI_VM_PATH' ) ) { define( 'TL_AI_VM_PATH', plugin_dir_path( __FILE__ ) ); }
if ( ! defined( 'TL_AI_VM_DIR' ) ) { define( 'TL_AI_VM_DIR', TL_AI_VM_PATH ); }
if ( ! defined( 'TL_AI_VM_URL' ) ) { define( 'TL_AI_VM_URL', plugin_dir_url( __FILE__ ) ); }

// 7.10.4 migration: retire stale Gemini 2.x setting values so the Worker uses Gemini 3.6 Flash.
add_action( 'plugins_loaded', function() {
    $model = trim( (string) get_option( 'tl_ai_vm_gemini_model', '' ) );
    if ( $model === '' || preg_match( '/^gemini-2\./i', $model ) ) {
        update_option( 'tl_ai_vm_gemini_model', 'gemini-3.6-flash', false );
    }
}, 5 );

function tl_ai_vm_load_files() {
    $files = array(
        // Core / discovery.
        'includes/class-logger.php',
        'includes/class-vehicle-cpt.php',
        'includes/class-acf-scanner.php',
        'includes/class-field-schema.php',
        'includes/class-field-intelligence.php',
        'includes/class-learning-memory.php',

        // AI.
        'includes/class-ai-client.php',
        'includes/class-ai-prompt-builder.php',
        'includes/class-ai-field-analyzer.php',

        // Research infrastructure.
        'includes/class-search-query-builder.php',
        'includes/class-search-provider.php',
        'includes/class-page-fetcher.php',
        'includes/class-data-extractor.php',
        'includes/class-data-validator.php',
        'includes/class-source-manager.php',
        'includes/class-source-collector.php',
        'includes/class-research-storage.php',
        'includes/class-research-validator.php',
        'includes/class-field-mapper.php',
        'includes/class-vehicle-filter.php',
        'includes/class-vehicle-researcher.php',
        'includes/class-vehicle-research-runner.php',
        'includes/class-web-research-engine.php',
        'includes/class-web-first-research-provider.php',
        'includes/class-research-queue.php',
        'includes/class-async-research.php',

        // Pipeline decision / approval.
        'includes/class-ai-confidence.php',
        'includes/class-ai-decision.php',
        'includes/class-research-approval.php',
        'includes/class-research-result.php',

        // Queue / writing.
        'includes/class-ai-queue.php',
        'includes/class-vehicle-data-writer.php',
        'includes/class-ai-acf-writer.php',
        'includes/class-bulk-processor.php',
        'includes/class-ai-queue-worker.php',

        // Admin.
        'includes/class-admin.php',
    );

    foreach ( $files as $file ) {
        $path = TL_AI_VM_PATH . $file;
        if ( file_exists( $path ) ) { require_once $path; }
    }
}

tl_ai_vm_load_files();

final class Tuningland_AI_Vehicle_Manager {
    private static $instance = null;
    public static function instance() {
        if ( null === self::$instance ) { self::$instance = new self(); }
        return self::$instance;
    }
    private function __construct() {
        add_action( 'plugins_loaded', array( $this, 'init' ), 20 );
    }
    public function init() {
        static $initialized = false;
        if ( $initialized ) { return; }
        $initialized = true;

        $classes = array(
            'TL_AI_VM_Logger',
            'TL_AI_VM_Vehicle_CPT',
            'TL_AI_VM_ACF_Scanner',
            'TL_AI_VM_Field_Schema',
            'TL_AI_VM_Field_Intelligence',
            'TL_AI_VM_Learning_Memory',
            'TL_AI_VM_AI_Client',
            'TL_AI_VM_Prompt_Builder',
            'TL_AI_VM_AI_Field_Analyzer',
            'TL_AI_VM_Search_Query_Builder',
            'TL_AI_VM_Search_Provider',
            'TL_AI_VM_Page_Fetcher',
            'TL_AI_VM_Data_Extractor',
            'TL_AI_VM_Data_Validator',
            'TL_AI_VM_Source_Manager',
            'TL_AI_VM_Source_Collector',
            'TL_AI_VM_Research_Storage',
            'TL_AI_VM_Research_Validator',
            'TL_AI_VM_Field_Mapper',
            'TL_AI_VM_Vehicle_Filter',
            'TL_AI_VM_Vehicle_Researcher',
            'TL_AI_VM_Vehicle_Research_Runner',
            'TL_AI_VM_Web_Research_Engine',
            'TL_AI_VM_Research_Queue',
            'TL_AI_VM_Async_Research',
            'TL_AI_VM_Confidence',
            'TL_AI_VM_Decision',
            'TL_AI_VM_Research_Approval',
            'TL_AI_VM_Research_Result',
            'TL_AI_VM_AI_Queue',
            'TL_AI_VM_Vehicle_Data_Writer',
            'TL_AI_VM_ACF_Writer',
            'TL_AI_VM_Bulk_Processor',
            'TL_AI_VM_Queue_Worker',
        );
        foreach ( $classes as $class ) {
            if ( class_exists( $class ) && method_exists( $class, 'instance' ) ) { $class::instance(); }
        }
        if ( is_admin() && class_exists( 'TL_AI_VM_Admin' ) ) { TL_AI_VM_Admin::instance(); }
        do_action( 'tl_ai_vm_loaded' );
    }
}

function tl_ai_vehicle_manager() { return Tuningland_AI_Vehicle_Manager::instance(); }
tl_ai_vehicle_manager();

function tl_ai_vm_activate() {
    if ( false === get_option( 'tl_ai_vm_vehicle_cpt', false ) ) { add_option( 'tl_ai_vm_vehicle_cpt', '' ); }
    if ( false === get_option( 'tl_ai_vm_logs', false ) ) { add_option( 'tl_ai_vm_logs', array() ); }
    if ( false === get_option( 'tl_ai_vm_queue', false ) ) { add_option( 'tl_ai_vm_queue', array() ); }
    if ( false === get_option( 'tl_ai_vm_openai_model', false ) ) { add_option( 'tl_ai_vm_openai_model', 'gpt-5.6' ); }
    if ( false === get_option( 'tl_ai_vm_ai_enabled', false ) ) { add_option( 'tl_ai_vm_ai_enabled', 0 ); }
    if ( false === get_option( 'tl_ai_vm_search_provider', false ) ) { add_option( 'tl_ai_vm_search_provider', 'free' ); }
    if ( false === get_option( 'tl_ai_vm_google_search_enabled', false ) ) { add_option( 'tl_ai_vm_google_search_enabled', 1 ); }
    if ( false === get_option( 'tl_ai_vm_search_engine_order', false ) ) { add_option( 'tl_ai_vm_search_engine_order', array( 'google', 'duckduckgo', 'bing' ) ); }
    if ( false === get_option( 'tl_ai_vm_auto_threshold', false ) ) { add_option( 'tl_ai_vm_auto_threshold', 90 ); }
    if ( false === get_option( 'tl_ai_vm_source_profiles', false ) ) { add_option( 'tl_ai_vm_source_profiles', array( 'global' => array(), 'brands' => array(), 'vehicles' => array() ) ); }
    if ( class_exists( 'TL_AI_VM_Learning_Memory' ) ) { TL_AI_VM_Learning_Memory::instance(); }
    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'tl_ai_vm_activate' );

function tl_ai_vm_deactivate() { flush_rewrite_rules(); }
register_deactivation_hook( __FILE__, 'tl_ai_vm_deactivate' );
