<?php
// Test-only: creates sample products and 400 days of orders. Run with: wp eval-file tests/seed-store.php
// Seed products + 400 days of orders, then sync Analytics lookup tables.
use Automattic\WooCommerce\Admin\API\Reports\Orders\Stats\DataStore as OrdersStats;
use Automattic\WooCommerce\Admin\API\Reports\Products\DataStore as ProductsStore;
mt_srand(42);
function mk_simple($name,$sku,$stock){ $p=new WC_Product_Simple(); $p->set_name($name); $p->set_sku($sku); $p->set_regular_price('10');
  if($stock!==null){ $p->set_manage_stock(true); $p->set_stock_quantity($stock);} $p->save(); return $p; }
$a = mk_simple('Steady Mug','MUG-1',30);
$b = mk_simple('Seasonal Scarf','SCARF-1',200);
$c = mk_simple('Untracked Sticker','STK-1',null);
$d = mk_simple('New Candle','CNDL-1',5);
$v = new WC_Product_Variable(); $v->set_name('Tee'); $v->set_sku('TEE'); $v->save();
$attr = new WC_Product_Attribute(); $attr->set_name('Size'); $attr->set_options(['S','L']); $attr->set_visible(true); $attr->set_variation(true); $v->set_attributes([$attr]); $v->save();
$vars=[];
foreach(['S'=>3,'L'=>50] as $size=>$st){ $x=new WC_Product_Variation(); $x->set_parent_id($v->get_id()); $x->set_attributes(['size'=>$size]); $x->set_sku('TEE-'.$size); $x->set_regular_price('20'); $x->set_manage_stock(true); $x->set_stock_quantity($st); $x->save(); $vars[$size]=$x; }
$tz = wp_timezone(); $today = new DateTimeImmutable('today', $tz);
$ids=[];
for($i=400;$i>=1;$i--){
  $day = $today->modify("-$i days"); $doy=(int)$day->format('z'); $dow=(int)$day->format('w');
  $lines=[];
  $lines[]=[$a, mt_rand(1,3) + ($dow==6?2:0)];
  $season = max(0, round(4*cos(2*M_PI*($doy-10)/365)+ mt_rand(-1,1)));
  if($season>0) $lines[]=[$b,$season];
  if(mt_rand(0,1)) $lines[]=[$c,1];
  if($i<=14) $lines[]=[$d, mt_rand(0,2)];
  if(mt_rand(0,2)) $lines[]=[$vars['S'],1];
  if(mt_rand(0,3)==0) $lines[]=[$vars['L'],1];
  $o = wc_create_order(['status'=>'completed']);
  foreach($lines as [$p,$q]) if($q>0) $o->add_product($p,$q);
  $o->set_date_created($day->setTime(12,0)->getTimestamp()); $o->set_date_paid($day->setTime(12,0)->getTimestamp()); $o->set_date_completed($day->setTime(12,0)->getTimestamp());
  $o->calculate_totals(false); $o->save(); $ids[]=$o->get_id();
}
// one refunded-day: refund 2 mugs from the last order
// one cancelled order that must not count
$x = wc_create_order(['status'=>'cancelled']); $x->add_product($a,50); $x->set_date_created($today->modify('-2 days')->setTime(9,0)->getTimestamp()); $x->calculate_totals(false); $x->save(); $ids[]=$x->get_id();
foreach($ids as $id){ OrdersStats::sync_order($id); ProductsStore::sync_order_products($id); }
// stock levels got reduced? wc_create_order doesn't reduce stock; reset explicitly
foreach([[$a,30],[$b,200],[$d,5],[$vars['S'],3],[$vars['L'],50]] as [$p,$s]) wc_update_product_stock($p,$s,'set');
echo "orders: ".count($ids)."\n";
