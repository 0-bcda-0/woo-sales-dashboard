<?php
$root=dirname(__DIR__);
defined('ABSPATH') || define('ABSPATH',$root.'/');
function plugin_dir_path($f){return dirname($f).'/';}
function plugin_dir_url($f){return 'https://example.test/wp-content/plugins/woo-sales-dashboard/';}
function add_action(...$args){}
require $root.'/woo-sales-dashboard.php';
if (!defined('WSD_VERSION') || WSD_VERSION!=='2.0.0' || !class_exists('WSD_Plugin')) {fwrite(STDERR,"FAIL bootstrap\n");exit(1);} echo "PASS smoke\n";
