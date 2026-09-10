<?php
// Minimal RED smoke test: production bootstrap does not exist yet.
$plugin = dirname(__DIR__) . '/woo-sales-dashboard.php';
if (! file_exists($plugin)) {
    fwrite(STDERR, "FAIL: plugin bootstrap missing\n");
    exit(1);
}
require $plugin;
if (! defined('WSD_VERSION') || ! class_exists('WSD_Plugin')) {
    fwrite(STDERR, "FAIL: expected WSD bootstrap symbols\n");
    exit(1);
}
echo "PASS\n";
