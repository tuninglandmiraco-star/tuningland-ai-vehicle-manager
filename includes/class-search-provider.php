<?php
/**
 * Search Provider
 *
 * Provider-agnostic web discovery with no-key HTML fallbacks.
 * Order: Google -> DuckDuckGo -> Bing. A custom provider may
 * override everything through tl_ai_vm_search_results.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TL_AI_VM_Search_Provider {
    private static $instance = null;
    public static function instance() { if ( null === self::$instance ) { self::$instance = new self(); } return self::$instance; }
    private function __construct() {}

    public function search( $query, $options = array() ) {
        $query = $this->clean_query( $query );
        if ( ! $query ) { return array( 'success'=>false, 'error'=>'Search query is empty.', 'results'=>array() ); }
        $options = wp_parse_args( $options, array(
            'limit'=>10, 'language'=>'', 'country'=>'', 'domain'=>'',
            'engine'=>'auto', 'google'=>true, 'duckduckgo'=>true, 'bing'=>true,
            'timeout'=>5, 'fallback_bing'=>true,
        ) );
        // Short-lived query cache: repeated fields/vehicles should not hit Google again.
        $cache_key = 'tlavm_s_' . md5( $query . '|' . wp_json_encode( $options ) );
        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) && isset( $cached['results'] ) ) {
            return $cached;
        }
        $custom = apply_filters( 'tl_ai_vm_search_results', null, $query, $options );
        if ( is_array( $custom ) ) { return array( 'success'=>true, 'query'=>$query, 'total'=>count($custom), 'results'=>$this->normalize_results($custom,$options) ); }
        $limit=max(1,min(20,absint($options['limit'])));
        $engines=array();
        $engine=sanitize_key($options['engine']);
        $configured_order=get_option('tl_ai_vm_search_engine_order',array('google','duckduckgo','bing'));
        if('auto'===$engine && is_array($configured_order) && $configured_order){ $engines=array_values(array_filter(array_map('sanitize_key',$configured_order))); }
        if('auto'!==$engine){ $engines=array($engine); }
        if(empty($engines)){ $engines=array('google','duckduckgo','bing'); }
        $engines=array_values(array_unique(array_filter($engines)));
        if(empty($engines)){ $engines=array('google','duckduckgo','bing'); }
        $search_query=$this->add_site_filters($query,$options);
        foreach($engines as $e){
            $results=$this->request_engine($e,$search_query,$limit,$options);
            if($results){ $r=array('success'=>true,'query'=>$query,'engine'=>$e,'total'=>count($results),'results'=>$this->normalize_results($results,$options)); set_transient($cache_key,$r,15*MINUTE_IN_SECONDS); return $r; }
        }
        if ( class_exists('TL_AI_VM_Logger') ) { TL_AI_VM_Logger::instance()->warning('All configured search engines returned no parsable results.','search',array('query'=>$query,'search_query'=>$search_query)); }
        $r=array('success'=>true,'query'=>$query,'engine'=>'none','total'=>0,'results'=>array()); set_transient($cache_key,$r,5*MINUTE_IN_SECONDS); return $r;
    }

    public function search_many( $queries, $options=array() ) {
        if(!is_array($queries)) return array();
        $queries=array_values(array_unique(array_filter(array_map(array($this,'clean_query'),$queries))));
        if(empty($queries)) return array();
        $options=wp_parse_args($options,array('limit'=>8,'engine'=>'auto','timeout'=>5));
        $custom=apply_filters('tl_ai_vm_search_batch_results',null,$queries,$options);
        if(is_array($custom)) return $this->remove_duplicates($custom);
        // Run the independent web searches concurrently when cURL multi is available.
        if(function_exists('curl_multi_init') && count($queries)>1){
            $batch_size = class_exists('TL_AI_VM_Field_Intelligence') ? TL_AI_VM_Field_Intelligence::instance()->get_settings()['parallel_searches'] : 5;
            $out=array();
            foreach(array_chunk($queries,max(1,min(8,absint($batch_size)))) as $batch){
                $parallel=$this->parallel_search($batch,$options);
                if(!empty($parallel)) $out=array_merge($out,$parallel);
            }
            if(!empty($out)) return $this->remove_duplicates($out);
        }
        $out=array();
        foreach($queries as $q){ $r=$this->search($q,$options); foreach((array)($r['results']??array()) as $item) $out[]=$item; }
        return $this->remove_duplicates($out);
    }

    private function parallel_search($queries,$options){
        $engine=sanitize_key($options['engine']);
        $configured=get_option('tl_ai_vm_search_engine_order',array('google','duckduckgo','bing'));
        if('auto'===$engine){ $engine=isset($configured[0])?sanitize_key($configured[0]):'google'; }
        if(!in_array($engine,array('google','duckduckgo','bing'),true)) $engine='google';
        $limit=max(1,min(20,absint($options['limit'])));
        $mh=curl_multi_init(); $handles=array(); $urls=array();
        foreach($queries as $i=>$query){
            $search_query=$this->add_site_filters($query,$options); $q=rawurlencode($search_query);
            if('google'===$engine) $url='https://www.google.com/search?hl=en&num='.$limit.'&q='.$q;
            elseif('duckduckgo'===$engine) $url='https://html.duckduckgo.com/html/?q='.$q;
            else $url='https://www.bing.com/search?q='.$q.'&count='.$limit;
            $ch=curl_init(); curl_setopt_array($ch,array(CURLOPT_URL=>$url,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>2,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>max(3,min(8,absint($options['timeout']))),CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_USERAGENT=>'Mozilla/5.0 (compatible; TuninglandAI/7.6; +'.home_url('/').')',CURLOPT_HTTPHEADER=>array('Accept: text/html,application/xhtml+xml','Accept-Language: en-US,en;q=0.8,fa;q=0.6')));
            curl_multi_add_handle($mh,$ch); $handles[(int)$ch]=$ch; $urls[(int)$ch]=array('query'=>$query,'engine'=>$engine);
        }
        do { $status=curl_multi_exec($mh,$running); if($running) curl_multi_select($mh,0.2); } while($running && $status===CURLM_OK);
        $out=array();
        foreach($handles as $id=>$ch){$html=curl_multi_getcontent($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$meta=$urls[$id];if($code>=200&&$code<300&&$html){if('google'===$engine)$items=$this->parse_google($html,$limit);elseif('duckduckgo'===$engine)$items=$this->parse_duckduckgo($html,$limit);else $items=$this->parse_bing($html,$limit);foreach((array)$items as $item){$item['query']=$meta['query'];$out[]=$item;}}curl_multi_remove_handle($mh,$ch);curl_close($ch);}
        curl_multi_close($mh);
        return $out;
    }

    public function search_multiple( $queries, $options=array() ) {
        $options=wp_parse_args($options,array('limit'=>10));
        $results=$this->search_many($queries,$options);
        return array('success'=>true,'queries'=>array_values(array_filter(array_map('sanitize_text_field',(array)$queries))),'total'=>count($results),'results'=>array_slice($results,0,max(1,min(100,absint($options['limit'])))));
    }

    private function request_engine($engine,$query,$limit,$options){
        $q=rawurlencode($query);
        if('google'===$engine){ $url='https://www.google.com/search?hl=en&num='.min(20,$limit).'&q='.$q; }
        elseif('duckduckgo'===$engine){ $url='https://html.duckduckgo.com/html/?q='.$q; }
        else { $url='https://www.bing.com/search?q='.$q.'&count='.min(20,$limit); }
        $response=wp_safe_remote_get($url,array('timeout'=>max(3,min(8,absint($options['timeout']))),'redirection'=>2,'sslverify'=>true,'headers'=>array('User-Agent'=>'Mozilla/5.0 (compatible; TuninglandAI/7.0; +'.home_url('/').')','Accept'=>'text/html,application/xhtml+xml','Accept-Language'=>'en-US,en;q=0.8,fa;q=0.6')));
        if(is_wp_error($response)) return array();
        $code=(int)wp_remote_retrieve_response_code($response); $html=wp_remote_retrieve_body($response);
        if($code<200||$code>=300||!$html) return array();
        if('google'===$engine) return $this->parse_google($html,$limit);
        if('duckduckgo'===$engine) return $this->parse_duckduckgo($html,$limit);
        return $this->parse_bing($html,$limit);
    }

    private function add_site_filters($query,$options){
        $domains=array();
        if(!empty($options['domain'])) $domains=is_array($options['domain'])?$options['domain']:preg_split('/[,\s]+/',(string)$options['domain']);
        $sites=array();
        foreach(array_slice((array)$domains,0,8) as $d){ $d=preg_replace('/^https?:\/\//i','',trim((string)$d)); $d=preg_replace('/\/.*$/','',$d); $d=preg_replace('/^www\./i','',$d); if($d) $sites[]='site:'.$d; }
        return $sites ? trim($query).' ('.implode(' OR ',$sites).')' : trim($query);
    }

    private function parse_google($html,$limit){
        if(!class_exists('DOMDocument')) return array();
        $dom=new DOMDocument(); libxml_use_internal_errors(true); @$dom->loadHTML($html); libxml_clear_errors(); $xp=new DOMXPath($dom); $out=array();
        $nodes=$xp->query("//a[.//h3]");
        if(!$nodes) return array();
        foreach($nodes as $a){ if(count($out)>=$limit) break; $href=$this->unwrap_search_url($a->getAttribute('href')); if(!$href||!preg_match('#^https?://#i',$href)||$this->is_search_host($href)) continue; $h3=$xp->query('.//h3',$a); $title=$h3&&$h3->length?trim($h3->item(0)->textContent):trim($a->textContent); if(!$title) continue; $snippet=''; $parent=$a->parentNode; for($i=0;$i<4&&$parent;$i++,$parent=$parent->parentNode){ $txt=trim(preg_replace('/\s+/u',' ',$parent->textContent)); if(mb_strlen($txt,'UTF-8')>strlen($title)+40){$snippet=mb_substr($txt,0,500,'UTF-8');break;} } $out[]=array('title'=>$title,'url'=>$href,'snippet'=>$snippet,'position'=>count($out)+1,'source_type'=>'google'); }
        return $out;
    }

    private function parse_duckduckgo($html,$limit){
        if(!class_exists('DOMDocument')) return array(); $dom=new DOMDocument(); libxml_use_internal_errors(true); @$dom->loadHTML($html); libxml_clear_errors(); $xp=new DOMXPath($dom); $out=array(); $nodes=$xp->query("//a[contains(@class,'result__a')]");
        if(!$nodes) return array(); foreach($nodes as $a){ if(count($out)>=$limit) break; $url=$this->unwrap_search_url($a->getAttribute('href')); if(!$url||$this->is_search_host($url)) continue; $title=trim($a->textContent); $snippet=''; $p=$a->parentNode&&$a->parentNode->parentNode?$a->parentNode->parentNode:null; if($p){$sn=$xp->query(".//*[contains(@class,'result__snippet')]",$p); if($sn&&$sn->length)$snippet=trim($sn->item(0)->textContent);} $out[]=array('title'=>$title,'url'=>$url,'snippet'=>$snippet,'position'=>count($out)+1,'source_type'=>'duckduckgo');} return $out;
    }

    private function parse_bing($html,$limit){
        if(!class_exists('DOMDocument')) return array(); $dom=new DOMDocument(); libxml_use_internal_errors(true); @$dom->loadHTML($html); libxml_clear_errors(); $xp=new DOMXPath($dom); $out=array(); $nodes=$xp->query("//li[contains(@class,'b_algo')]//h2/a"); if(!$nodes)return array(); foreach($nodes as $a){if(count($out)>=$limit)break;$url=esc_url_raw($a->getAttribute('href'));if(!$url||$this->is_search_host($url))continue;$item=$a->parentNode&&$a->parentNode->parentNode?$a->parentNode->parentNode:null;$sn='';if($item){$n=$xp->query(".//*[contains(@class,'b_caption')]//p",$item);if($n&&$n->length)$sn=trim($n->item(0)->textContent);} $out[]=array('title'=>trim($a->textContent),'url'=>$url,'snippet'=>$sn,'position'=>count($out)+1,'source_type'=>'bing');} return $out; }

    private function is_search_host($url){ $h=wp_parse_url($url,PHP_URL_HOST); if(!$h)return true; $h=strtolower(preg_replace('/^www\./','',$h)); return in_array($h,array('google.com','google.az','google.co.uk','google.de','bing.com','duckduckgo.com'),true); }
    private function unwrap_search_url($url){ $url=html_entity_decode((string)$url,ENT_QUOTES,'UTF-8'); if(0===strpos($url,'//'))$url='https:'.$url; $p=wp_parse_url($url); if(is_array($p)&&!empty($p['query'])){parse_str($p['query'],$q);foreach(array('url','q','uddg') as $k)if(!empty($q[$k])&&0===strpos(rawurldecode($q[$k]),'http'))return esc_url_raw(rawurldecode($q[$k]));} return esc_url_raw($url); }

    private function normalize_results($results,$options){$out=array();foreach((array)$results as $r){if(!is_array($r)||empty($r['url']))continue;$url=esc_url_raw($r['url']);if(!$url)continue;$out[]=array('title'=>isset($r['title'])?sanitize_text_field($r['title']):'','url'=>$url,'domain'=>$this->get_domain($url),'snippet'=>isset($r['snippet'])?wp_strip_all_tags($r['snippet']):'','position'=>isset($r['position'])?absint($r['position']):0,'source_type'=>isset($r['source_type'])?sanitize_key($r['source_type']):'','published_at'=>isset($r['published_at'])?sanitize_text_field($r['published_at']):'');} return array_slice($out,0,max(1,absint($options['limit']??10)));}
    private function remove_duplicates($results){$out=array();$seen=array();foreach((array)$results as $r){$u=$this->normalize_url($r['url']??'');if(!$u||isset($seen[$u]))continue;$seen[$u]=1;$out[]=$r;}return $out;}
    private function get_domain($url){$h=wp_parse_url($url,PHP_URL_HOST);return $h?strtolower(preg_replace('/^www\./','',$h)):'';}
    private function normalize_url($url){return strtolower(rtrim(preg_replace('/#.*$/','',esc_url_raw($url)),'/'));}
    private function clean_query($q){$q=html_entity_decode(wp_strip_all_tags((string)$q),ENT_QUOTES,'UTF-8');return trim(preg_replace('/\s+/u',' ',$q));}
}
