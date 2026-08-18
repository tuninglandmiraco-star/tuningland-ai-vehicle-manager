<?php
/**
 * AI Client
 *
 * Provider chain:
 * Gemini -> DeepSeek -> OpenAI.
 * Providers are optional and independently configurable.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TL_AI_VM_AI_Client {
    private static $instance = null;

    const OPTION_ENABLED = 'tl_ai_vm_ai_enabled';
    const OPTION_PROVIDER_ORDER = 'tl_ai_vm_ai_provider_order';
    const OPTION_PROVIDER_ENABLED = 'tl_ai_vm_ai_provider_enabled';

    const GEMINI_KEY = 'tl_ai_vm_gemini_api_key';
    const GEMINI_WORKER_URL = 'tl_ai_vm_gemini_worker_url';
    const GEMINI_MODEL = 'tl_ai_vm_gemini_model';
    const DEEPSEEK_KEY = 'tl_ai_vm_deepseek_api_key';
    const DEEPSEEK_MODEL = 'tl_ai_vm_deepseek_model';
    const OPENAI_KEY = 'tl_ai_vm_openai_api_key';
    const OPENAI_MODEL = 'tl_ai_vm_openai_model';

    public static function instance() {
        if ( null === self::$instance ) { self::$instance = new self(); }
        return self::$instance;
    }
    private function __construct() {}

    public function get_api_key() { return trim( (string) get_option( self::OPENAI_KEY, '' ) ); }
    public function set_api_key( $key ) { return update_option( self::OPENAI_KEY, trim( (string) $key ), false ); }
    public function get_model() { return $this->get_provider_model( 'openai' ); }
    public function set_model( $model ) { return update_option( self::OPENAI_MODEL, sanitize_text_field( $model ), false ); }

    public function is_configured() {
        foreach ( $this->get_provider_order() as $provider ) {
            if ( $this->provider_configured( $provider ) ) { return true; }
        }
        return false;
    }

    public function get_provider_enabled( $provider ) {
        $provider = sanitize_key( $provider );
        $saved = get_option( self::OPTION_PROVIDER_ENABLED, array(
            'gemini_worker' => 1,
            'gemini' => 0,
            'deepseek' => 0,
            'openai' => 0,
        ) );
        return ! empty( $saved[ $provider ] );
    }

    public function set_provider_enabled( $provider, $enabled ) {
        $provider = sanitize_key( $provider );
        $allowed = array( 'gemini_worker', 'gemini', 'deepseek', 'openai' );
        if ( ! in_array( $provider, $allowed, true ) ) { return false; }
        $saved = get_option( self::OPTION_PROVIDER_ENABLED, array() );
        if ( ! is_array( $saved ) ) { $saved = array(); }
        $saved[ $provider ] = $enabled ? 1 : 0;
        return update_option( self::OPTION_PROVIDER_ENABLED, $saved, false );
    }

    public function get_enabled_providers_in_order() {
        $out = array();
        foreach ( $this->get_provider_order() as $provider ) {
            if ( $this->get_provider_enabled( $provider ) ) { $out[] = $provider; }
        }
        return $out;
    }

    public function get_provider_order() {
        $saved = get_option( self::OPTION_PROVIDER_ORDER, array( 'gemini_worker', 'gemini', 'deepseek', 'openai' ) );
        if ( ! is_array( $saved ) ) { $saved = array( 'gemini', 'deepseek', 'openai' ); }
        $allowed = array( 'gemini_worker', 'gemini', 'deepseek', 'openai' );
        $out = array_values( array_unique( array_intersect( array_map( 'sanitize_key', $saved ), $allowed ) ) );
        foreach ( $allowed as $p ) { if ( ! in_array( $p, $out, true ) ) { $out[] = $p; } }
        return $out;
    }

    public function set_provider_order( $order ) {
        if ( ! is_array( $order ) ) { return false; }
        $allowed = array( 'gemini_worker', 'gemini', 'deepseek', 'openai' );
        $out = array_values( array_unique( array_intersect( array_map( 'sanitize_key', $order ), $allowed ) ) );
        foreach ( $allowed as $p ) { if ( ! in_array( $p, $out, true ) ) { $out[] = $p; } }
        return update_option( self::OPTION_PROVIDER_ORDER, $out, false );
    }

    public function get_provider_api_key( $provider ) {
        $map = array( 'gemini' => self::GEMINI_KEY, 'deepseek' => self::DEEPSEEK_KEY, 'openai' => self::OPENAI_KEY );
        $provider = sanitize_key( $provider );
        return isset( $map[ $provider ] ) ? trim( (string) get_option( $map[ $provider ], '' ) ) : '';
    }

    public function set_provider_api_key( $provider, $key ) {
        $map = array( 'gemini' => self::GEMINI_KEY, 'deepseek' => self::DEEPSEEK_KEY, 'openai' => self::OPENAI_KEY );
        $provider = sanitize_key( $provider );
        return isset( $map[ $provider ] ) ? update_option( $map[ $provider ], trim( (string) $key ), false ) : false;
    }

    public function get_provider_model( $provider ) {
        $defaults = array( 'gemini_worker' => 'gemini-3.6-flash', 'gemini' => 'gemini-3.6-flash', 'deepseek' => 'deepseek-chat', 'openai' => 'gpt-5.6' );
        $map = array( 'gemini' => self::GEMINI_MODEL, 'gemini_worker' => self::GEMINI_MODEL, 'deepseek' => self::DEEPSEEK_MODEL, 'openai' => self::OPENAI_MODEL );
        $provider = sanitize_key( $provider );
        $model = isset( $map[ $provider ] ) ? trim( (string) get_option( $map[ $provider ], $defaults[ $provider ] ) ) : '';
        return $model ? $model : ( isset( $defaults[ $provider ] ) ? $defaults[ $provider] : '' );
    }

    public function set_provider_model( $provider, $model ) {
        $map = array( 'gemini' => self::GEMINI_MODEL, 'gemini_worker' => self::GEMINI_MODEL, 'deepseek' => self::DEEPSEEK_MODEL, 'openai' => self::OPENAI_MODEL );
        $provider = sanitize_key( $provider );
        return isset( $map[ $provider ] ) ? update_option( $map[ $provider ], sanitize_text_field( $model ), false ) : false;
    }

    public function provider_configured( $provider ) {
        $provider = sanitize_key( $provider );
        if ( 'gemini_worker' === $provider ) { return '' !== trim( (string) get_option( self::GEMINI_WORKER_URL, '' ) ); }
        return '' !== $this->get_provider_api_key( $provider );
    }

    public function get_gemini_worker_url() { return esc_url_raw( (string) get_option( self::GEMINI_WORKER_URL, '' ) ); }
    public function set_gemini_worker_url( $url ) { return update_option( self::GEMINI_WORKER_URL, esc_url_raw( trim( (string) $url ) ), false ); }

    public function test_connection( $provider = '' ) {
        $provider = $provider ? sanitize_key( $provider ) : $this->first_configured_provider();
        if ( ! $provider ) { return array( 'success' => false, 'error' => 'No AI provider is configured.' ); }
        $options = array( 'provider' => $provider, 'max_output_tokens' => 20, 'timeout' => 25, 'force' => true );
        if ( 'gemini_worker' === $provider ) { $options['health'] = true; }
        return $this->request( 'Reply with the single word OK.', 'You are a connection test. Reply only with OK.', $options );
    }

    public function request_json( $input, $instructions = '', $options = array() ) {
        $result = $this->request( $input, $instructions, $options );
        if ( empty( $result['success'] ) ) { return $result; }
        $text = trim( (string) $result['text'] );
        $text = preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', $text );
        $json = json_decode( trim( $text ), true );
        if ( null === $json && JSON_ERROR_NONE !== json_last_error() ) {
            return array( 'success' => false, 'error' => 'AI returned invalid JSON.', 'text' => $text, 'provider' => $result['provider'] );
        }
        $result['json'] = $json;
        return $result;
    }

    /** Request multiple field answers from the primary AI provider in one call. */
    public function request_batch_json( $vehicle, $fields, $options = array() ) {
        $provider = ! empty( $options['provider'] ) ? sanitize_key( $options['provider'] ) : 'gemini_worker';
        if ( ! $this->get_provider_enabled( $provider ) && empty( $options['force'] ) ) {
            return array( 'success' => false, 'error' => $provider . ' is disabled.' );
        }
        if ( ! $this->provider_configured( $provider ) ) {
            return array( 'success' => false, 'error' => $provider . ' is not configured.' );
        }
        if ( 'gemini_worker' !== $provider ) {
            return array( 'success' => false, 'error' => 'Batch research currently requires Gemini via Cloudflare Worker.' );
        }
        $worker = $this->get_gemini_worker_url();
        $payload = array(
            'action' => 'research_batch',
            'model' => $this->get_provider_model( 'gemini_worker' ),
            'vehicle' => is_array( $vehicle ) ? $vehicle : array(),
            'fields' => is_array( $fields ) ? array_values( $fields ) : array(),
            'max_output_tokens' => absint( isset( $options['max_output_tokens'] ) ? $options['max_output_tokens'] : 5000 ),
        );
        $response = wp_remote_post( $worker, array(
            'timeout' => max( 20, absint( isset( $options['timeout'] ) ? $options['timeout'] : 55 ) ),
            'headers' => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
            'body' => wp_json_encode( $payload ),
        ) );
        if ( is_wp_error( $response ) ) { return array( 'success' => false, 'error' => $response->get_error_message() ); }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        if ( $code < 200 || $code >= 300 || ! is_array( $data ) || empty( $data['success'] ) ) {
            return array( 'success' => false, 'error' => is_array( $data ) && isset( $data['error'] ) ? (string) $data['error'] : 'Gemini Worker batch request failed.', 'http_code' => $code, 'response' => $data );
        }
        $items = isset( $data['results'] ) && is_array( $data['results'] ) ? $data['results'] : array();
        return array( 'success' => true, 'provider' => 'gemini_worker', 'http_code' => $code, 'results' => $items, 'response' => $data, 'text' => wp_json_encode( $items ) );
    }

    public function request( $input, $instructions = '', $options = array() ) {
        // Provider switches are independent. The legacy master switch is no longer
        // required for requests; an enabled+configured provider is sufficient.

        $options = wp_parse_args( $options, array( 'timeout' => 45, 'temperature' => 0, 'max_output_tokens' => 800, 'provider' => '' ) );
        $order = $options['provider'] ? array( sanitize_key( $options['provider'] ) ) : $this->get_enabled_providers_in_order();
        $errors = array();

        foreach ( $order as $provider ) {
            if ( empty( $options['provider'] ) && ! $this->get_provider_enabled( $provider ) ) { continue; }
            if ( ! $this->provider_configured( $provider ) ) {
                $errors[] = $provider . ': not configured';
                continue;
            }
            $result = $this->request_provider( $provider, $input, $instructions, $options );
            if ( ! empty( $result['success'] ) && '' !== trim( (string) $result['text'] ) ) {
                $result['provider'] = $provider;
                return $result;
            }
            $errors[] = $provider . ': ' . ( isset( $result['error'] ) ? $result['error'] : 'request failed' );
        }

        $this->log_error( implode( ' | ', $errors ), array( 'stage' => 'provider_chain' ) );
        return array( 'success' => false, 'error' => 'All configured AI providers failed or are unavailable. ' . implode( ' | ', $errors ) );
    }

    private function request_provider( $provider, $input, $instructions, $options ) {
        $provider = sanitize_key( $provider );
        if ( 'gemini_worker' === $provider ) {
            $worker = $this->get_gemini_worker_url();
            if ( ! $worker ) { return array( 'success'=>false, 'error'=>'Gemini Cloudflare Worker URL is not configured.' ); }
            $payload = array(
                'action' => ! empty( $options['health'] ) ? 'health' : 'research',
                'model' => $this->get_provider_model('gemini_worker'),
                'input' => (string) $input,
                'instructions' => (string) $instructions,
                'question' => (string) $input,
                'temperature' => (float) $options['temperature'],
                'max_output_tokens' => absint($options['max_output_tokens']),
            );
            $response = wp_remote_post( $worker, array( 'timeout'=>max(10,absint($options['timeout'])), 'headers'=>array('Content-Type'=>'application/json','Accept'=>'application/json'), 'body'=>wp_json_encode($payload) ) );
            if ( is_wp_error($response) ) return array('success'=>false,'error'=>$response->get_error_message());
            $code=(int)wp_remote_retrieve_response_code($response); $body=wp_remote_retrieve_body($response); $data=json_decode($body,true);
            if($code<200||$code>=300) return array('success'=>false,'error'=>is_array($data)&&isset($data['error'])?(string)$data['error']:'Gemini Worker request failed.');
            $text=''; if(is_array($data)){ if(isset($data['text']))$text=(string)$data['text']; elseif(isset($data['output_text']))$text=(string)$data['output_text']; elseif(isset($data['response']['text']))$text=(string)$data['response']['text']; elseif(isset($data['candidates'][0]['content']['parts'])) foreach((array)$data['candidates'][0]['content']['parts'] as $part) if(isset($part['text']))$text.=$part['text']; }
            if(!$text && isset($data['result'])) $text = wp_json_encode($data['result']);
            if(!$text && isset($data['results'])) $text = wp_json_encode($data['results']);
            if(!$text && is_string($body)) $text=trim($body);
            if ( ! empty($data['success']) && false === strpos( $text, '"success":false' ) ) {
                return array('success'=>true,'text'=>$text,'response'=>$data,'http_code'=>$code);
            }
            return array('success'=>false,'text'=>$text,'response'=>$data,'http_code'=>$code,'error'=>isset($data['error'])?(string)$data['error']:'Worker returned an unsuccessful result.');
        }

        if ( 'gemini' === $provider ) {
            $key = $this->get_provider_api_key( 'gemini' );
            $model = $this->get_provider_model( 'gemini' );
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent?key=' . rawurlencode( $key );
            $parts = array();
            if ( $instructions ) { $parts[] = 'SYSTEM INSTRUCTIONS: ' . $instructions; }
            $parts[] = (string) $input;
            $payload = array( 'contents' => array( array( 'parts' => array( array( 'text' => implode( "\n\n", $parts ) ) ) ) ), 'generationConfig' => array( 'temperature' => (float) $options['temperature'], 'maxOutputTokens' => absint( $options['max_output_tokens'] ) ) );
            $response = wp_remote_post( $url, array( 'timeout' => max( 10, absint( $options['timeout'] ) ), 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $payload ) ) );
            if ( is_wp_error( $response ) ) { return array( 'success'=>false, 'error'=>$response->get_error_message() ); }
            $code = (int) wp_remote_retrieve_response_code( $response ); $data = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( $code < 200 || $code >= 300 ) { return array( 'success'=>false, 'error'=>isset($data['error']['message'])?$data['error']['message']:'Gemini request failed.' ); }
            $text = '';
            foreach ( (array) ( $data['candidates'][0]['content']['parts'] ?? array() ) as $part ) { if ( isset( $part['text'] ) ) { $text .= $part['text']; } }
            return array( 'success'=>true, 'text'=>$text, 'response'=>$data, 'http_code'=>$code );
        }

        $key = $this->get_provider_api_key( $provider );
        $model = $this->get_provider_model( $provider );
        $endpoint = 'deepseek' === $provider ? 'https://api.deepseek.com/chat/completions' : 'https://api.openai.com/v1/chat/completions';
        $messages = array();
        if ( $instructions ) { $messages[] = array( 'role'=>'system', 'content'=>$instructions ); }
        $messages[] = array( 'role'=>'user', 'content'=>(string)$input );
        $payload = array( 'model'=>$model, 'messages'=>$messages, 'temperature'=>(float)$options['temperature'], 'max_tokens'=>absint($options['max_output_tokens']) );
        $response = wp_remote_post( $endpoint, array( 'timeout'=>max(10,absint($options['timeout'])), 'headers'=>array('Authorization'=>'Bearer '.$key,'Content-Type'=>'application/json'), 'body'=>wp_json_encode($payload) ) );
        if ( is_wp_error($response) ) { return array('success'=>false,'error'=>$response->get_error_message()); }
        $code=(int)wp_remote_retrieve_response_code($response); $data=json_decode(wp_remote_retrieve_body($response),true);
        if($code<200||$code>=300){return array('success'=>false,'error'=>isset($data['error']['message'])?$data['error']['message']:(ucfirst($provider).' request failed.') );}
        $text=isset($data['choices'][0]['message']['content'])?(string)$data['choices'][0]['message']['content']:'';
        return array('success'=>true,'text'=>$text,'response'=>$data,'http_code'=>$code);
    }

    private function first_configured_provider() {
        foreach ( $this->get_provider_order() as $p ) { if ( $this->provider_configured($p) ) { return $p; } }
        return '';
    }

    private function log_error( $message, $context = array() ) {
        if ( class_exists( 'TL_AI_VM_Logger' ) ) { TL_AI_VM_Logger::instance()->error( $message, 'ai', $context ); }
    }
}
