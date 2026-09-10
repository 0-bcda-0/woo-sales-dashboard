<?php
// Lightweight regression harness for the pure aggregation surface. Run inside a WP/WC test environment.
defined('ABSPATH') || define('ABSPATH', __DIR__ . '/');
require_once dirname(__DIR__) . '/includes/class-dashboard-service.php';

function wsd_assert($condition, string $message): void { if (! $condition) throw new RuntimeException($message); }
function wp_timezone(){ return new DateTimeZone('Europe/Zagreb'); }
function wp_date($format){ return (new DateTimeImmutable('2026-09-10 12:00:00', wp_timezone()))->format($format); }
function wc_get_product($id){ return null; }

final class WSD_Test_Date { public function __construct(private string $date){} public function setTimezone($tz){ return new DateTimeImmutable($this->date, $tz); } }
final class WSD_Test_Item {
    public function __construct(private int $id, private string $name, private float $qty, private float $total, private float $tax=0){}
    public function get_quantity(){return $this->qty;} public function get_product(){return null;} public function get_product_id(){return $this->id;} public function get_name(){return $this->name;} public function get_total(){return $this->total;} public function get_total_tax(){return $this->tax;}
}
final class WSD_Test_Order {
    public function __construct(private string $status, private string $date, private float $total, private float $shipping, private array $items=[], private float $refunded=0, private array $refQty=[], private array $refLine=[], private float $refShipping=0){}
    public function get_status(){return $this->status;} public function get_date_created(){return new WSD_Test_Date($this->date);} public function get_total(){return $this->total;} public function get_total_refunded(){return $this->refunded;} public function get_shipping_total(){return $this->shipping;} public function get_shipping_tax(){return 0;} public function get_total_shipping_refunded(){return $this->refShipping;} public function get_items($type){return $this->items;} public function get_qty_refunded_for_item($id){return $this->refQty[$id]??0;} public function get_total_refunded_for_item($id,$incTax=true){return $this->refLine[$id]??0;}
}

$service = new WSD_Dashboard_Service();
$orders = [
    new WSD_Test_Order('processing','2026-09-03 10:00:00',45,5,[11=>new WSD_Test_Item(11,'Shampoo',3,40,0)]),
    new WSD_Test_Order('completed','2026-09-04 10:00:00',25,5,[12=>new WSD_Test_Item(12,'Gel',2,20,0)],10,[12=>-1],[12=>-10]),
    new WSD_Test_Order('cancelled','2026-09-04 11:00:00',50,5),
];
$a=$service->aggregate_month('2026-09',$orders);
wsd_assert($a['totals']['orders']===2,'successful order count');
wsd_assert(abs($a['totals']['sales']-60)<0.001,'net sales');
wsd_assert(abs($a['totals']['items']-4)<0.001,'net items');
wsd_assert($a['secondary']['negative']===1,'negative count');
wsd_assert(abs($a['totals']['aov']-30)<0.001,'AOV');
wsd_assert(abs($a['totals']['itemsPerOrder']-2)<0.001,'items/order');
$prev=$service->aggregate_month('2026-08',[]);
$c=$service->comparison($a,$prev,'2026-09');
wsd_assert($c['sales']['percent']===null,'zero comparison is neutral');
echo "PASS dashboard aggregation\n";
