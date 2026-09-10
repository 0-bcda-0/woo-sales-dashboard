<?php

defined('ABSPATH') || define('ABSPATH', __DIR__ . '/');
$GLOBALS['wsd_options'] = [];
function get_option($k, $d = false){ return $GLOBALS['wsd_options'][$k] ?? $d; }
function update_option($k, $v, $autoload = null){ $GLOBALS['wsd_options'][$k] = $v; return true; }
function sanitize_text_field($v){ return trim(strip_tags((string)$v)); }
function is_email($v){ return filter_var($v, FILTER_VALIDATE_EMAIL) ? $v : false; }
function wc_get_product_id_by_sku($sku){ $map = ['bundle-1'=>101,'var-1'=>202]; return $map[strtolower($sku)] ?? 0; }
final class WSD_Test_Product { public function __construct(private string $sku){} public function get_sku(){ return $this->sku; } }
function wc_get_product($id){ return $id === 101 ? new WSD_Test_Product('BUNDLE-1') : ($id === 202 ? new WSD_Test_Product('VAR-1') : null); }
require_once dirname(__DIR__) . '/includes/class-settings-store.php';
function wsd_settings_assert($c, string $m): void { if (! $c) throw new RuntimeException($m); }
$s = new WSD_Settings_Store();
wsd_settings_assert($s->get_month('2026-09')['marketing'] === 0.0, 'default marketing');
$s->save_costs('2026-09', 125.5, 20.0);
$m = $s->get_month('2026-09');
wsd_settings_assert($m['marketing'] === 125.5, 'marketing');
wsd_settings_assert($m['other_costs'] === 20.0, 'other costs');
$r0 = $s->classification_revision();
$skus = $s->save_bundle_skus([' BUNDLE-1 ', 'bundle-1', 'VAR-1']);
wsd_settings_assert($skus === ['BUNDLE-1','VAR-1'], 'SKU normalize');
wsd_settings_assert($s->classification_revision() === $r0 + 1, 'classification revision');
$r1 = $s->classification_revision();
$s->save_report_email('boss@example.com');
wsd_settings_assert($s->classification_revision() === $r1, 'email does not affect revision');
$s->mark_sent('2026-09', 'boss@example.com', '2026-10-03T10:42:00+02:00');
wsd_settings_assert($s->get_month('2026-09')['last_sent_to'] === 'boss@example.com', 'send audit');
$thrown = false; try { $s->save_bundle_skus(['NOPE']); } catch (InvalidArgumentException $e) { $thrown = true; }
wsd_settings_assert($thrown, 'invalid SKU rejected');
$thrown = false; try { $s->save_report_email('bad'); } catch (InvalidArgumentException $e) { $thrown = true; }
wsd_settings_assert($thrown, 'invalid email rejected');
$thrown = false; try { $s->save_costs('bad',1,1); } catch (InvalidArgumentException $e) { $thrown = true; }
wsd_settings_assert($thrown, 'invalid month rejected');
echo "PASS settings store\n";
