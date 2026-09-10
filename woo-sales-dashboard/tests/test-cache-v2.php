<?php
define('ABSPATH', __DIR__.'/');
$GLOBALS['t']=[];
function wp_date($f){return '2026-09';}
function get_transient($k){return $GLOBALS['t'][$k]??false;}
function set_transient($k,$v,$ttl){$GLOBALS['t'][$k]=$v;return true;}
function delete_transient($k){unset($GLOBALS['t'][$k]);return true;}
function add_action(...$args){}
require_once dirname(__DIR__).'/includes/class-cache.php';
function ac($c,$m){if(!$c){fwrite(STDERR,"FAIL: $m\n");exit(1);}}
$c=new WSD_Cache();
$c->set('2026-09',['x'=>1],2); ac($c->get('2026-09',2)['x']===1,'same revision hit'); ac($c->get('2026-09',3)===false,'revision mismatch miss'); ac($c->key('2026-09')==='wsd_v2_2026_09','stable one key');
echo "PASS cache v2\n";
