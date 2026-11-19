<?php
define('ABSPATH', '/tmp/');
function wp_json_encode($v){ return json_encode($v); }
require __DIR__ . '/../includes/class-ephfc-math.php';
$fail = 0;
function check($name, $cond){ global $fail; echo ($cond ? "PASS " : "FAIL ") . $name . "\n"; if(!$cond) $fail++; }

check('qkey 0.9', EPHFC_Math::qkey(0.9) === '0.9');
check('qkey "0.50"', EPHFC_Math::qkey('0.50') === '0.5');
check('qkey 0.95', EPHFC_Math::qkey(0.95) === '0.95');

$s = EPHFC_Math::dense_series(['2026-11-03'=>2,'2026-11-01'=>5,'2026-11-04'=>-1], '2026-11-06');
check('dense length', count($s) === 6);
check('dense values', $s === [5.0,0.0,2.0,0.0,0.0,0.0]);
check('dense empty', EPHFC_Math::dense_series([], '2026-11-06') === []);

$resp = ['forecasts'=>[['quantiles'=>['0.1'=>[0,0],'0.5'=>[1,2],'0.9'=>[3,4]]]], 'meta'=>[]];
$p = EPHFC_Math::parse_forecasts($resp, 1);
check('parse ok', $p !== null && $p[0]['0.9'] === [3.0,4.0]);
check('parse count mismatch', EPHFC_Math::parse_forecasts($resp, 2) === null);
check('parse no median', EPHFC_Math::parse_forecasts(['forecasts'=>[['quantiles'=>['0.9'=>[1]]]]], 1) === null);
check('parse garbage', EPHFC_Math::parse_forecasts('x', 1) === null);
check('parse float keys', EPHFC_Math::parse_forecasts(['forecasts'=>[['quantiles'=>['0.50'=>[1]]]]],1)[0]['0.5'] === [1.0]);

$fc = ['0.5'=>array_fill(0,21,2.0), '0.9'=>array_fill(0,21,3.0)];
$r = EPHFC_Math::suggestion($fc, 21, '0.9', 10);
check('expected 42', $r['expected'] == 42.0);
check('cover 63', $r['cover'] == 63.0);
check('reorder 53', $r['reorder_qty'] === 53);
check('days cover 5', $r['days_of_cover'] == 5.0);
$r = EPHFC_Math::suggestion($fc, 21, '0.9', 100);
check('no reorder when stocked', $r['reorder_qty'] === 0);
$r = EPHFC_Math::suggestion($fc, 21, '0.9', null);
check('untracked stock -> 0, null cover', $r['reorder_qty'] === 0 && $r['days_of_cover'] === null);
$r = EPHFC_Math::suggestion($fc, 21, '0.9', -5);
check('backorders raise order', $r['reorder_qty'] === 68);
$r = EPHFC_Math::suggestion(['0.5'=>[-1,-2],'0.9'=>[0.5,-1]], 2, '0.9', 0);
check('negative forecasts clamp', $r['expected'] == 0 && $r['cover'] == 0.5 && $r['reorder_qty'] === 1 && $r['days_of_cover'] === null);
$r = EPHFC_Math::suggestion(['0.5'=>array_fill(0,3,0.1),'0.9'=>array_fill(0,3,0.1)], 3, '0.9', 0);
check('float noise ceil', $r['reorder_qty'] === 1);
$r = EPHFC_Math::suggestion(['0.5'=>[4,4,4,4]], 3, '0.9', 0);
check('window slices + missing q falls back to median', $r['expected']==12.0 && $r['cover']==12.0 && $r['reorder_qty']===12);
check('idem key stable', EPHFC_Math::idempotency_key(['a'=>1]) === EPHFC_Math::idempotency_key(['a'=>1]));
exit($fail ? 1 : 0);
