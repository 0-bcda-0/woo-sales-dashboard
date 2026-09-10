<?php
define('ABSPATH', __DIR__ . '/');
function wp_timezone(){return new DateTimeZone('Europe/Zagreb');}
function wp_date($format){return (new DateTimeImmutable('2026-09-10 12:00:00',wp_timezone()))->format($format);}
function wc_get_product($id){return null;}
function get_user_by($field,$id){return null;}
class WSD_Settings_Store{public function get_bundle_skus():array{return ['BUNDLE-1'];}}
require_once dirname(__DIR__).'/includes/class-commission-service.php';
require_once dirname(__DIR__).'/includes/class-snapshot-service.php';
require_once dirname(__DIR__).'/includes/class-dashboard-service.php';
function a2($c,$m){if(!$c){fwrite(STDERR,"FAIL: $m\n");exit(1);}}
final class D{public function __construct(private string $d){}public function setTimezone($tz){return new DateTimeImmutable($this->d,$tz);}}
final class U2{public function __construct(public array $roles){} }
final class P2{public function __construct(private string $sku){}public function get_sku(){return $this->sku;}public function is_type($t){return false;}public function get_parent_id(){return 0;}}
final class I2{public array $meta=[];public function __construct(private int $id,private string $name,private string $sku,private float $qty,private float $total,private float $tax=0){}public function get_quantity(){return $this->qty;}public function get_product(){return new P2($this->sku);}public function get_product_id(){return $this->id;}public function get_variation_id(){return 0;}public function get_name(){return $this->name;}public function get_total(){return $this->total;}public function get_total_tax(){return $this->tax;}public function get_meta($k,$s=true){return $this->meta[$k]??'';}public function update_meta_data($k,$v){$this->meta[$k]=$v;}public function save(){}}
final class O2{public array $meta=[];public function __construct(private string $status,private string $date,private float $total,private float $shipping,private array $items,private ?U2 $user=null,private array $refunds=[]){ } public function get_status(){return $this->status;}public function get_date_created(){return new D($this->date);}public function get_total(){return $this->total;}public function get_total_refunded(){return 0;}public function get_shipping_total(){return $this->shipping;}public function get_shipping_tax(){return 0;}public function get_total_shipping_refunded(){return 0;}public function get_items($t){return $this->items;}public function get_qty_refunded_for_item($id){return 0;}public function get_total_refunded_for_item($id,$tax=true){return $this->refunds[$id]??0;}public function get_meta($k,$s=true){return $this->meta[$k]??'';}public function get_user(){return $this->user;}public function get_user_id(){return $this->user?1:0;}public function get_order_number(){return '1001';}public function get_formatted_billing_full_name(){return $this->user?'VIP Buyer':'Guest';}public function update_meta_data($k,$v){$this->meta[$k]=$v;}public function save(){} }
$std=new I2(1,'Standard','STD-1',1,140);
$bundle=new I2(2,'Bundle','BUNDLE-1',1,100);
$o=new O2('processing','2026-09-03 10:00:00',245,5,[1=>$std,2=>$bundle]);
$vipItem=new I2(3,'Bundle but VIP','BUNDLE-1',1,200);
$vipOrder=new O2('completed','2026-09-04 10:00:00',205,5,[3=>$vipItem],new U2(['nishman_vip']));
$service=new WSD_Dashboard_Service(new WSD_Commission_Service(),new WSD_Snapshot_Service(new WSD_Settings_Store()));
$r=$service->aggregate_month('2026-09',[$o,$vipOrder]);
a2(abs($r['commissionSource']['standardSales']-140)<.001,'standard bucket');
a2(abs($r['commissionSource']['bundleSales']-100)<.001,'bundle bucket');
a2(abs($r['commissionSource']['vipSales']-200)<.001,'vip bucket');
a2(abs($r['commissionSource']['productSales']-440)<.001,'product sales excludes shipping');
a2(count($r['specialSales']['bundle'])===1,'bundle special row');
a2(count($r['specialSales']['vip'])===1,'vip special row');
a2(abs($r['commission']['commissionToPay']-120)<.001,'commission total');
$sum=0;foreach($r['daily'] as $d)$sum+=$d['commissionToPay'];a2(abs($sum-$r['commission']['commissionToPay'])<.001,'daily sum');
echo "PASS dashboard v2\n";
