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
final class O2{public array $meta=[];public function __construct(private int $id,private string $status,private string $date,private float $total,private float $shipping,private array $items,private ?U2 $user=null,private array $refunds=[]){ }public function get_id(){return $this->id;}public function get_status(){return $this->status;}public function get_date_created(){return new D($this->date);}public function get_total(){return $this->total;}public function get_total_refunded(){return 0;}public function get_shipping_total(){return $this->shipping;}public function get_shipping_tax(){return 0;}public function get_total_shipping_refunded(){return 0;}public function get_items($t){return $this->items;}public function get_qty_refunded_for_item($id){return 0;}public function get_total_refunded_for_item($id,$tax=true){return $this->refunds[$id]??0;}public function get_meta($k,$s=true){return $this->meta[$k]??'';}public function get_user(){return $this->user;}public function get_user_id(){return $this->user?1:0;}public function get_order_number(){return (string)$this->id;}public function get_formatted_billing_full_name(){return $this->user?'VIP Buyer':'Guest';}public function update_meta_data($k,$v){$this->meta[$k]=$v;}public function save(){} }
$service=new WSD_Dashboard_Service(new WSD_Commission_Service(),new WSD_Snapshot_Service(new WSD_Settings_Store()));

$vipItem=new I2(3,'VIP product','STD-1',1,200);
$vipOrder=new O2(1001,'completed','2026-09-04 10:00:00',205,5,[3=>$vipItem],new U2(['nishman_vip']));
$vipOrder->meta[WSD_Snapshot_Service::FORCE_STANDARD_ORDER_META]='1';
$r=$service->aggregate_month('2026-09',[$vipOrder]);
a2(abs($r['commissionSource']['standardSales']-200)<.001,'VIP override moves entire order to Standard');
a2(abs($r['commissionSource']['vipSales'])<.001,'VIP override removes VIP commission bucket');
a2(count($r['specialSales']['vip'])===1,'overridden VIP remains auditable');
a2($r['specialSales']['vip'][0]['orderId']===1001,'VIP audit row contains orderId');
a2($r['specialSales']['vip'][0]['forceStandard']===true,'VIP audit row reports override state');

$bundle=new I2(2,'Bundle','BUNDLE-1',1,100);
$bundle->meta[WSD_Snapshot_Service::FORCE_STANDARD_ITEM_META]='1';
$normal=new I2(1,'Normal','STD-1',1,140);
$order=new O2(1002,'processing','2026-09-05 10:00:00',245,5,[11=>$normal,22=>$bundle]);
$r=$service->aggregate_month('2026-09',[$order]);
a2(abs($r['commissionSource']['standardSales']-240)<.001,'Bundle override moves only item to Standard');
a2(abs($r['commissionSource']['bundleSales'])<.001,'Bundle override removes bundle commission bucket');
a2(count($r['specialSales']['bundle'])===1,'overridden Bundle remains auditable');
a2($r['specialSales']['bundle'][0]['orderId']===1002,'Bundle audit row contains orderId');
a2($r['specialSales']['bundle'][0]['itemId']===22,'Bundle audit row contains itemId');
a2($r['specialSales']['bundle'][0]['forceStandard']===true,'Bundle audit row reports override state');

echo "PASS dashboard v2 hotfix overrides\n";
