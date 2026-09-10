<?php

defined('ABSPATH') || define('ABSPATH', __DIR__ . '/');
class WSD_Settings_Store { public function __construct(private array $skus=['BUNDLE-1']){} public function get_bundle_skus(): array{return $this->skus;} }
final class WSD_Test_User { public function __construct(public array $roles){} }
final class WSD_Test_Snapshot_Product { public function __construct(private string $sku){} public function get_sku(){return $this->sku;} }
final class WSD_Test_Snapshot_Item { public array $meta=[]; public int $saves=0; public function __construct(private ?WSD_Test_Snapshot_Product $p){} public function get_meta($k,$single=true){return $this->meta[$k]??'';} public function update_meta_data($k,$v){$this->meta[$k]=$v;} public function save(){++$this->saves;} public function get_product(){return $this->p;} public function get_variation_id(){return 0;} public function get_product_id(){return 0;} }
final class WSD_Test_Snapshot_Order { public array $meta=[]; public int $saves=0; public function __construct(private ?WSD_Test_User $u, private array $items=[]){} public function get_meta($k,$single=true){return $this->meta[$k]??'';} public function update_meta_data($k,$v){$this->meta[$k]=$v;} public function save(){++$this->saves;} public function get_user(){return $this->u;} public function get_user_id(){return $this->u?1:0;} public function get_items($t='line_item'){return $this->items;} }
function get_user_by($field,$id){return null;}
function wc_get_product($id){return null;}
require_once dirname(__DIR__).'/includes/class-snapshot-service.php';
function wsd_snapshot_assert($c,string $m):void{if(!$c)throw new RuntimeException($m);}
$s=new WSD_Snapshot_Service(new WSD_Settings_Store());
$o=new WSD_Test_Snapshot_Order(new WSD_Test_User([])); $o->meta[WSD_Snapshot_Service::VIP_META]='1'; wsd_snapshot_assert($s->is_vip_order($o)===true,'vip 1 stable');
$o2=new WSD_Test_Snapshot_Order(new WSD_Test_User(['nishman_vip'])); $o2->meta[WSD_Snapshot_Service::VIP_META]='0'; wsd_snapshot_assert($s->is_vip_order($o2)===false,'vip 0 stable');
$o3=new WSD_Test_Snapshot_Order(new WSD_Test_User(['nishman_vip'])); wsd_snapshot_assert($s->is_vip_order($o3)===true,'vip fallback');
$o4=new WSD_Test_Snapshot_Order(null); wsd_snapshot_assert($s->is_vip_order($o4)===false,'guest false');
$i=new WSD_Test_Snapshot_Item(new WSD_Test_Snapshot_Product('BUNDLE-1')); $i->meta[WSD_Snapshot_Service::BUNDLE_META]='0'; wsd_snapshot_assert($s->is_bundle_item($o3,$i)===false,'bundle snapshot 0');
$i2=new WSD_Test_Snapshot_Item(new WSD_Test_Snapshot_Product('BUNDLE-1')); wsd_snapshot_assert($s->is_bundle_item($o3,$i2)===true,'bundle fallback');
$i3=new WSD_Test_Snapshot_Item(new WSD_Test_Snapshot_Product('OTHER')); wsd_snapshot_assert($s->is_bundle_item($o3,$i3)===false,'bundle fallback false');
$o5=new WSD_Test_Snapshot_Order(new WSD_Test_User(['nishman_vip']),[$i2,$i3]); $s->snapshot_order($o5); wsd_snapshot_assert($o5->meta[WSD_Snapshot_Service::VIP_META]==='1','snapshot vip'); wsd_snapshot_assert($i2->meta[WSD_Snapshot_Service::BUNDLE_META]==='1','snapshot bundle'); wsd_snapshot_assert($i3->meta[WSD_Snapshot_Service::BUNDLE_META]==='0','snapshot nonbundle');
$before=$o5->saves; $s->snapshot_order($o5); wsd_snapshot_assert($o5->saves===$before,'no overwrite save');
echo "PASS snapshot service\n";
