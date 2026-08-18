<?php
/**
 * Field Intelligence
 *
 * Field/group specific research routing plus internal asset resolution.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TL_AI_VM_Field_Intelligence {
    private static $instance = null;
    const OPTION_NAME = 'tl_ai_vm_field_intelligence';

    public static function instance() {
        if ( null === self::$instance ) { self::$instance = new self(); }
        return self::$instance;
    }
    private function __construct() {}

    public function defaults() {
        return array(
            'enabled' => 1,
            'parallel_searches' => 5,
            'parallel_pages' => 5,
            'default_domains' => array(),
            'groups' => array(),
            'fields' => array(),
        );
    }

    public function get_settings() {
        $saved = get_option( self::OPTION_NAME, array() );
        if ( ! is_array( $saved ) ) { $saved = array(); }
        $s = $this->defaults();
        foreach ( array( 'enabled', 'parallel_searches', 'parallel_pages' ) as $k ) {
            if ( isset( $saved[ $k ] ) ) { $s[ $k ] = absint( $saved[ $k ] ); }
        }
        $s['parallel_searches'] = max( 1, min( 8, $s['parallel_searches'] ) );
        $s['parallel_pages'] = max( 1, min( 8, $s['parallel_pages'] ) );
        $s['default_domains'] = $this->normalize_domains( isset( $saved['default_domains'] ) ? $saved['default_domains'] : array() );
        $s['groups'] = $this->normalize_rules( isset( $saved['groups'] ) ? $saved['groups'] : array() );
        $s['fields'] = $this->normalize_rules( isset( $saved['fields'] ) ? $saved['fields'] : array() );
        return $s;
    }

    public function save_settings( $settings ) {
        if ( ! is_array( $settings ) ) { return false; }
        $s = $this->defaults();
        $s['enabled'] = empty( $settings['enabled'] ) ? 0 : 1;
        $s['parallel_searches'] = max( 1, min( 8, absint( isset( $settings['parallel_searches'] ) ? $settings['parallel_searches'] : 5 ) ) );
        $s['parallel_pages'] = max( 1, min( 8, absint( isset( $settings['parallel_pages'] ) ? $settings['parallel_pages'] : 5 ) ) );
        $s['default_domains'] = $this->normalize_domains( isset( $settings['default_domains'] ) ? $settings['default_domains'] : array() );
        $s['groups'] = $this->normalize_rules( isset( $settings['groups'] ) ? $settings['groups'] : array() );
        $s['fields'] = $this->normalize_rules( isset( $settings['fields'] ) ? $settings['fields'] : array() );
        return update_option( self::OPTION_NAME, $s, false ) || wp_json_encode( $this->get_settings() ) === wp_json_encode( $s );
    }

    public function get_rule( $field_key = '', $group_key = '' ) {
        $s = $this->get_settings();
        $rule = array( 'mode' => 'web', 'domains' => array(), 'blocked_domains' => array(), 'internal' => false );
        if ( $group_key && isset( $s['groups'][ sanitize_key( $group_key ) ] ) ) { $rule = array_replace_recursive( $rule, $s['groups'][ sanitize_key( $group_key ) ] ); }
        if ( $field_key && isset( $s['fields'][ sanitize_key( $field_key ) ] ) ) { $rule = array_replace_recursive( $rule, $s['fields'][ sanitize_key( $field_key ) ] ); }
        $rule['domains'] = $this->normalize_domains( $rule['domains'] );
        $rule['blocked_domains'] = $this->normalize_domains( $rule['blocked_domains'] );
        return $rule;
    }

    public function get_domains( $field_key = '', $group_key = '' ) {
        $s = $this->get_settings();
        $rule = $this->get_rule( $field_key, $group_key );
        $domains = $rule['domains'];
        if ( empty( $domains ) ) { $domains = $s['default_domains']; }
        return $domains;
    }

    public function is_internal( $field ) {
        if ( ! is_array( $field ) ) { return false; }
        $key = isset( $field['key'] ) ? sanitize_key( $field['key'] ) : '';
        $group = isset( $field['group_key'] ) ? sanitize_key( $field['group_key'] ) : '';
        $rule = $this->get_rule( $key, $group );
        $type = isset( $field['type'] ) ? sanitize_key( $field['type'] ) : '';
        if ( ! in_array( $type, array( 'image', 'gallery', 'file' ), true ) ) { return false; }
        if ( ! empty( $rule['internal'] ) || 'internal' === $rule['mode'] || ! empty( $rule['internal_images'] ) ) { return true; }
        return $this->is_vehicle_images_group( $group );
    }

    private function is_vehicle_images_group( $group_key ) {
        if ( ! $group_key || ! function_exists( 'acf_get_field_group' ) ) { return false; }
        $group = acf_get_field_group( $group_key );
        if ( ! is_array( $group ) ) { return false; }
        $title = isset( $group['title'] ) ? $this->norm( $group['title'] ) : '';
        return ( false !== strpos( $title, $this->norm( 'تصاویر خودرو' ) ) || false !== strpos( $title, 'vehicle images' ) || false !== strpos( $title, 'car images' ) );
    }

    /** Resolve an image/gallery from the site's existing media before web search. */
    public function resolve_internal_asset( $post_id, $field ) {
        $post_id = absint( $post_id );
        if ( ! $post_id || ! is_array( $field ) ) { return array( 'success' => false, 'error' => 'Invalid asset request.' ); }
        $title = trim( wp_strip_all_tags( get_the_title( $post_id ) ) );
        if ( ! $title ) { return array( 'success' => false, 'error' => 'Vehicle title is empty.' ); }
        $type = isset($field['type']) ? sanitize_key($field['type']) : '';
        $label = isset($field['label']) ? (string)$field['label'] : '';
        $name  = isset($field['name']) ? (string)$field['name'] : '';
        $semantic = $this->norm( $label . ' ' . $name );
        $is_asset_field = (bool) preg_match( '/(banner|header|hero|thumbnail|image|images|gallery|photo|picture|عکس|تصویر|بنر|هدر)/iu', $semantic );

        // 1) Existing ACF value wins.
        $existing = function_exists( 'get_field' ) && ! empty( $field['key'] ) ? get_field( $field['key'], $post_id, false ) : null;
        if ( $existing ) { return array( 'success'=>true, 'value'=>$existing, 'method'=>'existing_acf', 'confidence'=>1.0 ); }

        // 2) Deterministic WooCommerce c-cat lookup. No web search is used here.
        $term = $this->find_vehicle_ccat_term( $title );
        if ( $term ) {
            // The existing vehicle category page contains the real slider/banner.
            // Read its data-bg directly before considering any media fallback.
            $html_asset = $this->resolve_category_page_slider_asset( $term, $field, $type, $label . ' ' . $name );
            if ( $html_asset ) {
                $asset_value = $html_asset['value'];
                $asset_url = isset($html_asset['url']) ? $html_asset['url'] : (is_string($asset_value) ? $asset_value : '');
                if ( $type === 'image' && $asset_url && function_exists('attachment_url_to_postid') ) {
                    $aid = attachment_url_to_postid( $asset_url );
                    if ( $aid ) { $asset_value = $aid; }
                }
                return array('success'=>true,'value'=>$asset_value,'method'=>'woocommerce_c_cat_html','confidence'=>1.0,'term_id'=>(int)$term->term_id,'term_name'=>$term->name,'term_url'=>get_term_link($term),'url'=>$asset_url);
            }
            $value = $this->resolve_category_image_value( $term, $field, $type, $label . ' ' . $name );
            if ( $value ) {
                $url = is_numeric($value) ? wp_get_attachment_url(absint($value)) : '';
                return array('success'=>true,'value'=>$value,'method'=>'woocommerce_c_cat','confidence'=>1.0,'term_id'=>(int)$term->term_id,'term_name'=>$term->name,'term_url'=>get_term_link($term),'url'=>$url);
            }
        }

        // 3) Conservative internal media fallback only for image fields.
        if ( in_array($type,array('image','gallery','file'),true) || $is_asset_field ) {
            $queries = array( $title, $title . ' banner', $title . ' بنر' );
            $found = array();
            foreach ( $queries as $q ) {
                $items = get_posts(array('post_type'=>'attachment','post_status'=>'inherit','post_mime_type'=>'image','posts_per_page'=>10,'s'=>$q,'orderby'=>'relevance','order'=>'DESC'));
                foreach((array)$items as $item){$found[$item->ID]=$item;}
            }
            $norm_title=$this->norm($title); $best=0; $best_score=0;
            foreach($found as $item){$hay=$this->norm($item->post_title.' '.$item->post_excerpt.' '.$item->post_content.' '.wp_basename(get_attached_file($item->ID)));$score=0;if(false!==strpos($hay,$norm_title))$score+=75;if(preg_match('/(banner|بنر|hero|header)/iu',$hay))$score+=20;if((int)$item->post_parent===$post_id)$score+=30;if($score>$best_score){$best_score=$score;$best=$item->ID;}}
            if($best){return array('success'=>true,'value'=>$best,'method'=>'internal_media_library','confidence'=>min(0.95,0.60+($best_score/220)),'attachment_id'=>$best,'url'=>wp_get_attachment_url($best));}
        }
        return array( 'success' => false, 'error' => 'No matching c-cat/internal asset was found.' );
    }

    private function find_vehicle_ccat_term( $title ) {
        if ( ! taxonomy_exists('product_cat') ) { return null; }
        $parent = get_term_by('slug','c-cat','product_cat');
        $terms = $parent ? get_terms(array('taxonomy'=>'product_cat','hide_empty'=>false,'child_of'=>(int)$parent->term_id,'number'=>1000)) : get_terms(array('taxonomy'=>'product_cat','hide_empty'=>false,'number'=>1000));
        if ( is_wp_error($terms) || empty($terms) ) { return null; }
        $needle=$this->norm($title); $best=null; $score=0;
        foreach($terms as $term){$hay=$this->norm($term->name.' '.$term->slug);$s=0;if($hay===$needle)$s=100;if(false!==strpos($hay,$needle))$s=90;else{$parts=preg_split('/\s+/u',$needle,-1,PREG_SPLIT_NO_EMPTY);$hits=0;foreach($parts as $part)if(mb_strlen($part,'UTF-8')>1&&false!==strpos($hay,$part))$hits++;if($parts&&$hits===count($parts))$s=80+min(10,$hits);}if($s>$score){$score=$s;$best=$term;}}
        return ($score>=80) ? $best : null;
    }

    private function resolve_category_page_slider_asset( $term, $field, $type, $semantic ) {
        $url = get_term_link( $term );
        if ( is_wp_error( $url ) || ! $url ) { return null; }
        $response = wp_remote_get( $url, array( 'timeout' => 8, 'redirection' => 3, 'headers' => array( 'User-Agent' => 'Tuningland-AI-Vehicle-Manager/7.10.3' ) ) );
        if ( is_wp_error( $response ) ) { return null; }
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 400 ) { return null; }
        $html = wp_remote_retrieve_body( $response );
        if ( ! $html ) { return null; }
        preg_match_all( '/<div[^>]+class=["\'][^"\']*bdt-ps-slide-img[^"\']*["\'][^>]*>/iu', $html, $matches, PREG_PATTERN_ORDER );
        $urls = array();
        foreach ( (array)$matches[0] as $tag ) {
            if ( preg_match( '/(?:data-bg|data-src)=["\']([^"\']+)["\']/iu', $tag, $m ) ) { $urls[] = html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ); }
            elseif ( preg_match( '/background-image\s*:\s*url\((?:&quot;|["\']?)([^)"\'&]+)(?:&quot;|["\']?)\)/iu', $tag, $m ) ) { $urls[] = html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ); }
        }
        // Fallback: capture the exact structure even if the class order changes.
        if ( empty($urls) && preg_match_all( '/bdt-ps-slide-img[^>]{0,1200}(?:data-bg|background-image)=[^>]{0,500}/iu', $html, $mm ) ) {
            foreach ( $mm[0] as $chunk ) { if ( preg_match('/(?:data-bg|data-src)=["\']([^"\']+)["\']/iu',$chunk,$m) ) $urls[] = html_entity_decode($m[1],ENT_QUOTES,'UTF-8'); }
        }
        $urls = array_values(array_unique(array_filter($urls)));
        if ( empty($urls) ) { return null; }
        $semantic_n = $this->norm($semantic);
        $is_gallery = $type === 'gallery' || preg_match('/(gallery|images|image|عکس|تصویر)/iu',$semantic);
        if ( $is_gallery ) {
            return array( 'value' => $urls, 'url' => $urls[0] );
        }
        return array( 'value' => esc_url_raw($urls[0]), 'url' => esc_url_raw($urls[0]) );
    }

    private function resolve_category_image_value( $term, $field, $type, $semantic ) {
        $semantic_n=$this->norm($semantic);
        $term_id=(int)$term->term_id;
        // Prefer ACF values attached to the WooCommerce category term.
        if(function_exists('get_fields')){
            $acf=get_fields('term_'.$term_id);
            if(is_array($acf)){
                foreach($acf as $k=>$v){
                    if(!$v) continue;
                    $kn=$this->norm($k); $match=false;
                    if(preg_match('/(banner|header|hero|cover|بنر|هدر)/iu',$semantic)) $match=preg_match('/(banner|header|hero|cover|بنر|هدر)/iu',$kn);
                    elseif(preg_match('/(gallery|images|image|عکس|تصویر)/iu',$semantic)) $match=preg_match('/(gallery|images|image|عکس|تصویر)/iu',$kn);
                    if($match) return $this->normalize_acf_asset($v,$type);
                }
            }
        }
        // WooCommerce category thumbnail is the canonical fallback for banner/header.
        if(preg_match('/(banner|header|hero|cover|بنر|هدر)/iu',$semantic) || $type==='image'){
            $thumb=(int)get_term_meta($term_id,'thumbnail_id',true);
            if($thumb) return $thumb;
        }
        return null;
    }

    private function normalize_acf_asset($value,$type){
        if(is_array($value)){
            if($type==='gallery') return $value;
            if(isset($value['ID'])) return absint($value['ID']);
            if(isset($value['id'])) return absint($value['id']);
            if(isset($value['url'])) return esc_url_raw($value['url']);
        }
        return is_numeric($value) ? absint($value) : esc_url_raw((string)$value);
    }

    private function normalize_rules( $rules ) {
        $out = array();
        if ( ! is_array( $rules ) ) { return $out; }
        foreach ( $rules as $key => $rule ) {
            $key = sanitize_key( $key );
            if ( ! $key || ! is_array( $rule ) ) { continue; }
            $out[ $key ] = array(
                'mode' => in_array( isset( $rule['mode'] ) ? sanitize_key( $rule['mode'] ) : 'web', array( 'web', 'internal', 'disabled' ), true ) ? sanitize_key( $rule['mode'] ) : 'web',
                'domains' => $this->normalize_domains( isset( $rule['domains'] ) ? $rule['domains'] : array() ),
                'blocked_domains' => $this->normalize_domains( isset( $rule['blocked_domains'] ) ? $rule['blocked_domains'] : array() ),
                'internal' => ! empty( $rule['internal'] ),
                'internal_images' => ! empty( $rule['internal_images'] ),
            );
        }
        return $out;
    }
    private function normalize_domains( $domains ) {
        if ( ! is_array( $domains ) ) { $domains = preg_split( '/[,\r\n]+/', (string) $domains ); }
        $out = array();
        foreach ( $domains as $d ) { $d = trim( strtolower( (string) $d ) ); $d = preg_replace( '#^https?://#i', '', $d ); $d = preg_replace( '#/.*$#', '', $d ); $d = preg_replace( '#^www\.#i', '', $d ); if ( $d && preg_match( '/^[a-z0-9.-]+\.[a-z]{2,}$/i', $d ) ) { $out[] = $d; } }
        return array_values( array_unique( $out ) );
    }
    private function norm( $text ) {
        $text = strtolower( remove_accents( (string) $text ) );
        $text = preg_replace( '/[^a-z0-9\x{0600}-\x{06ff}]+/u', ' ', $text );
        return trim( preg_replace( '/\s+/u', ' ', $text ) );
    }
}
