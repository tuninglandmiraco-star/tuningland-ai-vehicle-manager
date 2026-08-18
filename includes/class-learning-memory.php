<?php
/**
 * Lightweight file-based learning memory.
 * Stores approved/rejected research knowledge outside wp_options.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TL_AI_VM_Learning_Memory {
    private static $instance = null;
    const VERSION = '1.2.0';

    public static function instance() { if ( null === self::$instance ) { self::$instance = new self(); } return self::$instance; }
    private function __construct() { $this->ensure_storage(); }

    public function path() { return trailingslashit( WP_CONTENT_DIR ) . 'tuningland-ai-vm-data/learning/'; }
    public function remember_approval( $research, $approval = array() ) {
        if ( ! is_array( $research ) ) { return false; }
        $vehicle = isset( $research['vehicle']['post_id'] ) ? absint( $research['vehicle']['post_id'] ) : 0;
        $field = isset( $research['field']['key'] ) ? sanitize_key( $research['field']['key'] ) : '';
        if ( ! $vehicle || ! $field ) { return false; }
        $record = array(
            'version' => self::VERSION, 'timestamp' => current_time( 'c', true ), 'event' => 'approved',
            'vehicle_id' => $vehicle, 'vehicle_title' => isset( $research['vehicle']['title'] ) ? sanitize_text_field( $research['vehicle']['title'] ) : '',
            'field_key' => $field, 'field_name' => isset( $research['field']['name'] ) ? sanitize_key( $research['field']['name'] ) : '',
            'field_label' => isset( $research['field']['label'] ) ? sanitize_text_field( $research['field']['label'] ) : '',
            'value' => isset( $research['normalized_value'] ) ? $research['normalized_value'] : null,
            'sources' => $this->compact_sources( isset( $research['sources'] ) ? $research['sources'] : array() ),
            'confidence' => isset( $research['confidence']['percentage'] ) ? absint( $research['confidence']['percentage'] ) : 0,
            'method' => isset( $research['metadata']['method'] ) ? sanitize_key( $research['metadata']['method'] ) : '',
        );
        return $this->append( 'vehicle-' . $vehicle . '-' . $field . '.jsonl', $record ) && $this->append( 'global.jsonl', $record );
    }
    /**
     * Store a human-written field rule. The admin can write natural language;
     * common normalization rules are also recognized automatically.
     */
    public function remember_rule( $research, $note = '', $source = '' ) {
        if ( ! is_array( $research ) ) { return false; }
        $vehicle = absint( $research['vehicle']['post_id'] ?? 0 );
        $field = sanitize_key( $research['field']['key'] ?? '' );
        if ( ! $field || ! trim( $note ) ) { return false; }
        $rule = $this->infer_rule( $field, $note );
        $record = array(
            'version' => self::VERSION,
            'timestamp' => current_time( 'c', true ),
            'event' => 'rule',
            'vehicle_id' => $vehicle,
            'field_key' => $field,
            'field_name' => sanitize_key( $research['field']['name'] ?? '' ),
            'field_label' => sanitize_text_field( $research['field']['label'] ?? '' ),
            'note' => sanitize_textarea_field( $note ),
            'rule' => $rule,
            'preferred_source' => esc_url_raw( $source ),
            'scope' => 'field_global',
            'rule_fingerprint' => md5( $field . '|' . $rule['type'] . '|' . mb_strtolower( trim( $note ), 'UTF-8' ) ),
        );
        $file = $vehicle ? 'vehicle-' . $vehicle . '-' . $field . '.jsonl' : 'field-' . $field . '.jsonl';
        return $this->append( $file, $record ) && $this->append( 'global.jsonl', $record );
    }

    /**
     * Return the latest active rules for a field across all vehicles.
     *
     * Learning is field-scoped, not vehicle-scoped: a human correction made
     * on one vehicle must influence the same ACF field on future vehicles.
     */
    public function rules( $field_key, $limit = 50 ) {
        $field_key = is_array( $field_key ) ? sanitize_key( $field_key['key'] ?? '' ) : sanitize_key( $field_key );
        if ( ! $field_key ) { return array(); }
        $rows = array();
        $files = glob( $this->path() . 'vehicle-*-' . $field_key . '.jsonl' );
        $global = $this->path() . 'global.jsonl';
        if ( is_file( $global ) ) { $files[] = $global; }
        foreach ( array_unique( (array) $files ) as $file ) {
            foreach ( (array) $this->read_tail( $file, 300 ) as $row ) {
                if ( ! isset( $row['event'] ) || 'rule' !== $row['event'] ) { continue; }
                if ( isset( $row['field_key'] ) && sanitize_key( $row['field_key'] ) !== $field_key ) { continue; }
                $rows[] = $row;
            }
        }
        usort( $rows, function( $a, $b ) { return strcmp( (string)( $b['timestamp'] ?? '' ), (string)( $a['timestamp'] ?? '' ) ); } );
        $out = array(); $seen = array();
        foreach ( $rows as $row ) {
            $fp = ! empty( $row['rule_fingerprint'] ) ? $row['rule_fingerprint'] : md5( $field_key . '|' . ( $row['rule']['type'] ?? 'natural_language' ) . '|' . mb_strtolower( trim( (string)( $row['note'] ?? '' ) ), 'UTF-8' ) );
            if ( isset( $seen[$fp] ) ) { continue; }
            $seen[$fp] = true; $out[] = $row;
            if ( count($out) >= max(1,min(100,absint($limit))) ) break;
        }
        return $out;
    }

    /** Resolve rules using key, field name and label so learning survives field-key differences. */
    public function rules_for_field( $field, $limit = 50 ) {
        $field = is_array($field) ? $field : array('key'=>(string)$field);
        $key = sanitize_key($field['key'] ?? '');
        $name = sanitize_key($field['name'] ?? '');
        $label = mb_strtolower(trim((string)($field['label'] ?? '')), 'UTF-8');
        $rows = array();
        $global = $this->path().'global.jsonl';
        $files = array($global);
        if($key){$files=array_merge($files,(array)glob($this->path().'vehicle-*-'.$key.'.jsonl'));}
        foreach(array_unique($files) as $file){
            if(!is_file($file)) continue;
            foreach((array)$this->read_tail($file,500) as $row){
                if(($row['event']??'')!=='rule') continue;
                $rk=sanitize_key($row['field_key']??''); $rn=sanitize_key($row['field_name']??''); $rl=mb_strtolower(trim((string)($row['field_label']??'')),'UTF-8');
                if(($key && $rk===$key)||($name && $rn===$name)||($label && $rl===$label)) $rows[]=$row;
            }
        }
        usort($rows,function($a,$b){return strcmp((string)($b['timestamp']??''),(string)($a['timestamp']??''));});
        $out=[];$seen=[];foreach($rows as $row){$fp=$row['rule_fingerprint']??md5(json_encode($row['rule']??array()).'|'.($row['note']??''));if(isset($seen[$fp]))continue;$seen[$fp]=1;$out[]=$row;if(count($out)>=max(1,min(100,absint($limit))))break;}return $out;
    }

    /** Apply learned deterministic formatting rules before validation/writing. */
    public function apply_rules( $field_key, $value ) {
        $field = is_array( $field_key ) ? $field_key : array( 'key' => $field_key );
        $rules = $this->rules_for_field( $field, 100 );
        if ( ! $rules ) { return array( 'value' => $value, 'applied' => array() ); }

        $out = $value;
        $applied = array();

        foreach ( $rules as $row ) {
            $kind = isset( $row['rule']['type'] ) ? $row['rule']['type'] : '';
            $note = isset( $row['note'] ) ? (string) $row['note'] : '';

            // Older learning records may have been stored as natural_language.
            // Re-infer them at runtime so existing knowledge is not lost after
            // the rule engine is upgraded.
            if ( 'natural_language' === $kind && $note ) {
                $inferred = $this->infer_rule( $field['key'] ?? '', $note );
                if ( ! empty( $inferred['type'] ) && 'natural_language' !== $inferred['type'] ) {
                    $kind = $inferred['type'];
                }
            }

            if ( 'volume_unit_l' === $kind && is_scalar( $out ) ) {
                $before = trim( (string) $out );
                $normalized = $before;
                // Normalize common written units to a single canonical L.
                $normalized = preg_replace( '/\s*(?:litres?|liters?|liter|litre|لیتر|لیتره|لیترها|\bl\b|ℓ)\s*$/iu', '', $normalized );
                $normalized = trim( $normalized );
                if ( '' !== $normalized && preg_match( '/\d(?:[\d\.,]*)?\s*$/u', $normalized ) ) {
                    $normalized = preg_replace( '/\s+$/u', '', $normalized ) . ' L';
                }
                if ( $normalized !== $before ) {
                    $out = $normalized;
                    $applied[] = $note ? $note : 'Normalize volume units to L.';
                }
            } elseif ( 'sae_dash' === $kind && is_scalar( $out ) ) {
                $before = trim( (string) $out );
                $normalized = preg_replace( '/\b(\d{1,2})\s*[Ww]\s*-?\s*(\d{2,3})\b/u', '$1W-$2', $before );
                if ( $normalized !== $before ) {
                    $out = $normalized;
                    $applied[] = $note ? $note : 'Normalize SAE viscosity as numberW-number.';
                }
            }
        }

        return array( 'value' => $out, 'applied' => $applied );
    }

    private function infer_rule( $field, $note ) {
        $n = mb_strtolower( preg_replace( '/\s+/u', ' ', (string) $note ), 'UTF-8' );

        // Volume/unit normalization. Be deliberately permissive because the
        // admin is expected to write natural Persian/English instructions,
        // not machine syntax.
        $mentions_volume = (bool) preg_match(
            '/(?:حجم|ظرفیت|مقدار|volume|capacity|oil|روغن|مایع|coolant|ترمز|گیربکس)/iu',
            $n
        );
        $mentions_l = (bool) preg_match(
            '/(?:\bL\b|\bl\b|لیتر|liter|litre|liters|litres|ℓ)/iu',
            $n
        );
        $volume_instruction = (bool) preg_match(
            '/(?:همیشه|فقط|به\s*جاش|به\s*جای|بنویس|بنویسه|باشد|استفاده|نمایش|ذخیره|تبدیل|واحد|unit|normalize)/iu',
            $n
        );

        if ( $mentions_volume && $mentions_l && $volume_instruction ) {
            return array(
                'type' => 'volume_unit_l',
                'description' => 'Normalize volume values to uppercase L.',
            );
        }

        // SAE formatting: 0W30 -> 0W-30, 5W30 -> 5W-30, etc.
        $mentions_sae = (bool) preg_match(
            '/(?:گرانروی|viscosity|sae|روغن|w\s*[-]?\s*\d{2,3}|دبلیو)/iu',
            $n
        );
        $mentions_dash = (bool) preg_match(
            '/(?:خط|تیره|فاصله|\-)/iu',
            $n
        );
        $has_example = (bool) preg_match(
            '/\b(?:\d{1,2}\s*[Ww]\s*\d{2,3})\b/iu',
            $n
        );

        if ( $mentions_sae && ( $mentions_dash || $has_example ) ) {
            return array(
                'type' => 'sae_dash',
                'description' => 'Normalize SAE viscosity as numberW-number.',
            );
        }

        return array(
            'type' => 'natural_language',
            'description' => sanitize_textarea_field( $note ),
        );
    }

    public function remember_correction( $research, $value, $note = '', $source = '' ) {
        if ( ! is_array( $research ) ) return false;
        $vehicle=absint($research['vehicle']['post_id']??0); $field=sanitize_key($research['field']['key']??'');
        if(!$vehicle||!$field)return false;
        $record=array('version'=>self::VERSION,'timestamp'=>current_time('c',true),'event'=>'corrected','vehicle_id'=>$vehicle,'field_key'=>$field,'field_name'=>sanitize_key($research['field']['name']??''),'field_label'=>sanitize_text_field($research['field']['label']??''),'value'=>$value,'note'=>sanitize_textarea_field($note),'preferred_source'=>esc_url_raw($source),'sources'=>$this->compact_sources($research['sources']??array()));
        $ok = $this->append('vehicle-'.$vehicle.'-'.$field.'.jsonl',$record)&&$this->append('global.jsonl',$record);
        // A correction with a human explanation is itself a learning event.
        if ( $ok && trim( (string) $note ) ) {
            $this->remember_rule( $research, $note, $source );
        }
        return $ok;
    }
    public function learned_sources( $field_key, $limit = 5 ) {
        $field_key=sanitize_key($field_key); if(!$field_key)return array(); $files=glob($this->path().'vehicle-*-'.$field_key.'.jsonl'); $global=$this->path().'global.jsonl'; if(is_file($global))$files[]=$global; $domains=array();
        foreach((array)$files as $file){foreach((array)$this->read_tail($file,20) as $row){$urls=array();if(!empty($row['preferred_source']))$urls[]=$row['preferred_source'];foreach((array)($row['sources']??array()) as $src){if(!empty($src['url']))$urls[]=$src['url'];}foreach($urls as $url){$host=wp_parse_url($url,PHP_URL_HOST);if($host)$domains[]=preg_replace('/^www\./i','',$host);}}}
        return array_slice(array_values(array_unique($domains)),0,max(1,min(10,absint($limit))));
    }

    public function remember_rejection( $research, $note = '' ) {
        if ( ! is_array( $research ) ) { return false; }
        $record = array(
            'version'=>self::VERSION, 'timestamp'=>current_time('c',true), 'event'=>'rejected',
            'vehicle_id'=>isset($research['vehicle']['post_id'])?absint($research['vehicle']['post_id']):0,
            'field_key'=>isset($research['field']['key'])?sanitize_key($research['field']['key']):'',
            'value'=>isset($research['normalized_value'])?$research['normalized_value']:null,
            'note'=>sanitize_textarea_field($note),
            'sources'=>$this->compact_sources(isset($research['sources'])?$research['sources']:array()),
        );
        return $this->append( 'global.jsonl', $record );
    }
    public function find( $vehicle_id, $field_key, $limit = 5 ) {
        $file = $this->path() . 'vehicle-' . absint($vehicle_id) . '-' . sanitize_key($field_key) . '.jsonl';
        return $this->read_tail( $file, $limit );
    }
    public function stats() {
        $files = glob( $this->path() . '*.jsonl' );
        $bytes = 0; foreach ( (array) $files as $f ) { if ( is_file($f) ) { $bytes += filesize($f); } }
        return array('files'=>count((array)$files),'bytes'=>$bytes,'path'=>$this->path());
    }
    private function ensure_storage() {
        $dir = $this->path();
        if ( ! is_dir($dir) ) { wp_mkdir_p($dir); }
        if ( is_dir($dir) ) {
            if ( ! file_exists($dir . '.htaccess') ) { @file_put_contents($dir . '.htaccess', "Deny from all\n"); }
            if ( ! file_exists($dir . 'index.php') ) { @file_put_contents($dir . 'index.php', "<?php\n// Silence is golden.\n"); }
        }
    }
    private function append( $file, $record ) {
        $dir = $this->path(); if ( ! is_dir($dir) ) { $this->ensure_storage(); }
        $path = $dir . sanitize_file_name($file);
        $line = wp_json_encode($record, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if ( false === $line ) { return false; }
        return false !== @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
    }
    private function read_tail( $file, $limit ) {
        if ( ! file_exists($file) ) { return array(); }
        $lines = @file($file, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES); if ( ! is_array($lines) ) { return array(); }
        $lines = array_slice($lines, -max(1,min(20,absint($limit)))); $out=array();
        foreach($lines as $line){$v=json_decode($line,true);if(is_array($v))$out[]=$v;} return array_reverse($out);
    }
    private function compact_sources( $sources ) { $out=array(); foreach((array)$sources as $s){if(!is_array($s)||empty($s['url']))continue;$out[]=array('url'=>esc_url_raw($s['url']),'domain'=>isset($s['domain'])?sanitize_text_field($s['domain']):'','title'=>isset($s['title'])?sanitize_text_field($s['title']):'');} return array_slice($out,0,8); }
}
