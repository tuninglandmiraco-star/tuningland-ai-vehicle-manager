<?php
/**
 * Web-first research provider.
 *
 * AI is optional. This provider is deterministic-first:
 * 1. configured priority sources
 * 2. Google-indexed source queries
 * 3. direct page fetching
 * 4. field-aware extraction / ACF choice matching
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class TL_AI_VM_Web_First_Research_Provider {

    public function research_field( $vehicle, $field, $sources = array() ) {
        $queries = $this->build_queries( $vehicle, $field, $sources );
        $candidates = array();

        foreach ( $queries as $query ) {
            $results = $this->search_web( $query );
            foreach ( $results as $result ) {
                $candidates[] = $result;
            }
        }

        $candidates = $this->unique_results( $candidates );
        $extracted = $this->extract_from_results( $candidates, $field );

        return array(
            'success'    => ! empty( $extracted['value'] ),
            'value'      => isset( $extracted['value'] ) ? $extracted['value'] : null,
            'confidence' => isset( $extracted['confidence'] ) ? $extracted['confidence'] : 0,
            'sources'    => isset( $extracted['sources'] ) ? $extracted['sources'] : array(),
            'evidence'   => isset( $extracted['evidence'] ) ? $extracted['evidence'] : array(),
            'queries'    => $queries,
            'provider'   => 'web-first',
        );
    }

    private function build_queries( $vehicle, $field, $sources ) {
        $name = isset($vehicle['title']) ? $vehicle['title'] : '';
        $brand = isset($vehicle['brand']) ? $vehicle['brand'] : '';
        $field_name = isset($field['name']) ? $field['name'] : '';
        $label = isset($field['label']) ? $field['label'] : '';
        $terms = array_unique(array_filter(array($field_name, $label)));

        $queries = array();
        foreach ((array)$sources as $source) {
            $domain = is_array($source) && isset($source['domain']) ? $source['domain'] : '';
            if ($domain) {
                foreach ($terms as $term) {
                    $queries[] = 'site:' . $domain . ' "' . $name . '" "' . $term . '"';
                }
            }
        }
        foreach ($terms as $term) {
            $queries[] = '"' . $name . '" "' . $term . '"';
        }
        if ($brand && $name) {
            foreach ($terms as $term) {
                $queries[] = '"' . $brand . '" "' . $name . '" "' . $term . '"';
            }
        }
        return array_values(array_unique($queries));
    }

    private function search_web( $query ) {
        $url = 'https://www.google.com/search?' . http_build_query(array(
            'q' => $query,
            'num' => 5,
            'hl' => 'en',
        ));
        $response = wp_safe_remote_get($url, array(
            'timeout' => 8,
            'redirection' => 3,
            'user-agent' => 'Tuningland-AI-Vehicle-Manager/7.3',
        ));
        if (is_wp_error($response)) return array();
        $body = wp_remote_retrieve_body($response);
        if (!$body) return array();

        $results=array();
        if (preg_match_all('/<a[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>/is', $body, $m)) {
            foreach ($m[1] as $i=>$href) {
                $text=trim(wp_strip_all_tags(html_entity_decode($m[2][$i])));
                if (!$text) continue;
                $href=html_entity_decode($href);
                if (strpos($href,'/url?q=')!==false) {
                    $href=wp_parse_url($href,PHP_URL_QUERY);
                    parse_str($href,$qv);
                    $href=isset($qv['q'])?$qv['q']:'';
                }
                if (preg_match('#^https?://#i',$href)) {
                    $results[]=array('url'=>$href,'title'=>$text,'snippet'=>$text);
                }
            }
        }
        return array_slice($results,0,5);
    }

    private function unique_results($results) {
        $seen=array(); $out=array();
        foreach ($results as $r) {
            if (empty($r['url']) || isset($seen[$r['url']])) continue;
            $seen[$r['url']]=true; $out[]=$r;
        }
        return $out;
    }

    private function extract_from_results($results,$field) {
        $choices=isset($field['choices']) && is_array($field['choices']) ? $field['choices'] : array();
        $type=isset($field['type'])?$field['type']:'';
        $label=strtolower((string)(isset($field['label'])?$field['label']:''));
        $name=strtolower((string)(isset($field['name'])?$field['name']:''));
        $sources=array(); $evidence=array();

        foreach ($results as $r) {
            $text=$r['title'].' '.(isset($r['snippet'])?$r['snippet']:'');
            if (!empty($choices)) {
                foreach ($choices as $key=>$choice) {
                    $candidate=is_array($choice)?(isset($choice['label'])?$choice['label']:$key):$key;
                    if (is_string($candidate) && $candidate!=='' && stripos($text,$candidate)!==false) {
                        $sources[]=$r['url']; $evidence[]=$text;
                        return array('value'=>$key,'confidence'=>92,'sources'=>$sources,'evidence'=>$evidence);
                    }
                }
            }

            if (preg_match('/(wheelbase|wheel\s*base|تیر|فاصله\s*محوری)/i',$label.' '.$name)) {
                if (preg_match('/\b([0-9]{3,4})\s*(?:mm|millimet(?:er|re)s?)\b/i',$text,$m)) {
                    $sources[]=$r['url']; $evidence[]=$text;
                    return array('value'=>(float)$m[1],'confidence'=>90,'sources'=>$sources,'evidence'=>$evidence);
                }
            }

            if ($type==='number' && preg_match('/\b(-?[0-9]+(?:\.[0-9]+)?)\b/',$text,$m)) {
                $sources[]=$r['url']; $evidence[]=$text;
                return array('value'=>$m[1],'confidence'=>70,'sources'=>$sources,'evidence'=>$evidence);
            }
        }
        return array('value'=>null,'confidence'=>0,'sources'=>array(),'evidence'=>array());
    }
}
