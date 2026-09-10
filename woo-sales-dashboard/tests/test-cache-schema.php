<?php
define('ABSPATH', __DIR__ . '/');
$GLOBALS['transient'] = [
    'month' => '2026-09',
    '_classificationRevision' => 1,
    'specialSales' => ['vip' => [['orderNumber' => '123', 'revenue' => 100]]],
];
function wp_date($f){ return '2026-09'; }
function get_transient($k){ return $GLOBALS['transient']; }
function set_transient($k,$v,$ttl){ return true; }
function delete_transient($k){ return true; }
function add_action(...$args){}
require_once dirname(__DIR__) . '/includes/class-cache.php';
$c = new WSD_Cache();
$result = $c->get('2026-09', 1);
if ($result !== false) {
    fwrite(STDERR, "FAIL: legacy V2 cache payload should be rejected after schema bump\n");
    exit(1);
}
echo "PASS cache schema\n";
