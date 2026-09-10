<?php
define('ABSPATH',__DIR__.'/');
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function wp_strip_all_tags($v){return strip_tags($v);}
require_once dirname(__DIR__).'/includes/class-report-service.php';
function ar($c,$m){if(!$c){fwrite(STDERR,"FAIL: $m\n");exit(1);}}
$r=new WSD_Report_Service();
$month=['month'=>'2026-09','generatedAt'=>'2026-10-01T10:00:00+02:00','currency'=>['code'=>'EUR','symbol'=>'€'],'totals'=>['orders'=>12,'items'=>34,'aov'=>50,'shipping'=>20],'commission'=>['productSales'=>1000,'standardSales'=>700,'vipSales'=>100,'bundleSales'=>200,'standardVpc'=>500,'standardCommission'=>300,'vipCommission'=>20,'bundleCommission'=>40,'commissionToPay'=>360,'marketing'=>50,'otherCosts'=>10,'netEarnings'=>300],'daily'=>[['date'=>'2026-09-01','sales'=>100,'commissionToPay'=>40]],'previousDaily'=>[['date'=>'2026-08-01','sales'=>90,'commissionToPay'=>35]],'specialSales'=>['vip'=>[['date'=>'2026-09-02','orderNumber'=>'1','customer'=>'<script>x</script> Ana','revenue'=>100]],'bundle'=>[['date'=>'2026-09-03','orderNumber'=>'2','product'=>'Wax & Gel','sku'=>'B-1','revenue'=>200]]],'reportAudit'=>['last_sent_at'=>'','last_sent_to'=>'']];
$p=$r->build_payload('2026-09',$month); ar($p['commission']['commissionToPay']===360,'parity'); ar($p['specialSales']===$month['specialSales'],'special parity');
$html=$r->render_html($p,'email'); ar(strpos($html,'<script>x</script>')===false,'escaped script'); ar(strpos($html,'&lt;script&gt;x&lt;/script&gt;')!==false,'escaped content visible'); ar(stripos($html,'display:flex')===false,'email no flex'); ar(stripos($html,'display:grid')===false,'email no grid'); ar(stripos($html,'http://')===false && stripos($html,'https://')===false,'email no remote'); ar(strpos($html,'Daily Commission to Pay')!==false,'daily commission comparison');
echo "PASS report service\n";
