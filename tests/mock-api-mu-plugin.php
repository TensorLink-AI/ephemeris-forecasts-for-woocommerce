<?php
// TEST ONLY. Fakes the Ephemeris API so the plugin can be tried without spending credits. Never install on a live site.
// Test-only: fake Ephemeris API. Mode via option ephfc_mock_mode (ok|401|402|500).
add_filter('pre_http_request', function($pre, $args, $url){
  if (strpos($url, 'ephemeris.cascade.industries') === false) return $pre;
  $log = get_option('ephfc_mock_log', []); $log[] = ['url'=>$url,'headers'=>$args['headers'],'body'=>$args['body']]; update_option('ephfc_mock_log', $log, false);
  $mode = get_option('ephfc_mock_mode', 'ok');
  if ($mode !== 'ok') return ['response'=>['code'=>(int)$mode,'message'=>'x'],'body'=>json_encode(['error'=>'mock '.$mode]),'headers'=>[],'cookies'=>[]];
  $b = json_decode($args['body'], true); $out=[];
  foreach ($b['series'] as $s) { $v=array_slice($s['values'],-28); $m=array_sum($v)/max(1,count($v));
    $q=[]; foreach($b['quantiles'] as $qq){ $f = $qq<0.5 ? 0.5 : ($qq>0.5 ? 1.5 : 1.0); $q[(string)$qq]=array_fill(0,$b['horizon'],round($m*$f,4)); }
    $out[]=['model'=>'mock','quantiles'=>$q]; }
  return ['response'=>['code'=>200,'message'=>'OK'],'body'=>json_encode(['forecasts'=>$out,'meta'=>['billing'=>['settled_mc'=>1000*count($out)]]]),'headers'=>[],'cookies'=>[]];
}, 10, 3);
add_filter('ephfc_items', function($items){ $n=(int)get_option('ephfc_mock_multiply',1); $out=[]; for($i=0;$i<$n;$i++) $out=array_merge($out,$items); return $out; });
