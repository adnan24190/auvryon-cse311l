<?php
declare(strict_types=1);
require __DIR__ . '/lib/boot.php';
require __DIR__ . '/lib/ui.php';
start_session();
header('X-Frame-Options: DENY'); header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; script-src 'self' 'unsafe-inline'; frame-ancestors 'none'");
$GLOBALS['LANG'] = in_array($_SESSION['lang'] ?? 'en', ['en', 'bn'], true) ? ($_SESSION['lang'] ?? 'en') : 'en';
if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'bn'], true)) {
  $_SESSION['lang'] = $_GET['lang']; $GLOBALS['LANG'] = $_GET['lang'];
  if (!empty($_SESSION['uid'])) q('UPDATE user_settings SET language=? WHERE user_id=?', [$_GET['lang'], $_SESSION['uid']]);
  $qs = $_GET; unset($qs['lang']); redirect('index.php' . ($qs ? '?' . http_build_query($qs) : ''));
}
$page_key = preg_replace('/[^a-z_]/', '', (string)($_GET['p'] ?? 'dashboard'));
$public = ['login', 'register', 'logout'];
$known = ['dashboard', 'wallet', 'send', 'requests', 'activity', 'receipt', 'export', 'safe', 'recurring', 'rules', 'pockets', 'groups', 'iou', 'merchant', 'invoices', 'pay', 'checkout', 'escrow', 'disputes', 'tokens', 'offline', 'family', 'security', 'notifications', 'settings', 'admin'];
try {
  if (in_array($page_key, $public, true)) {
    ob_start(); require __DIR__ . "/pages/$page_key.php"; $html = ob_get_clean(); echo $html; exit;
  }
  $u = Auth::user();
  if (!$u) { $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? ''; redirect(url('login')); }
  if (!in_array($page_key, $known, true)) { flash('err', 'That page does not exist.'); redirect(url('dashboard')); }
  if ($page_key === 'admin' && $u['role'] !== 'admin') { flash('err', 'You do not have permission to open the admin area.'); audit((int)$u['id'], 'forbidden', 'page', null, 'admin'); redirect(url('dashboard')); }
  if ($page_key === 'merchant' && $u['role'] !== 'merchant') { flash('err', 'This area is for merchant accounts.'); redirect(url('dashboard')); }
  try { run_maintenance_light(); } catch (Throwable $e) { error_log('maintenance: ' . $e->getMessage()); }
  $title = ucfirst($page_key); $content = ''; $raw = false; $__user = $u;   // pages may reuse $p / $u as loop variables, so the shell keeps its own copies
  ob_start(); require __DIR__ . "/pages/$page_key.php"; $content = ob_get_clean();
  if ($raw) exit;
  echo layout($title, $content, $__user, $page_key);
} catch (Throwable $e) {
  error_log('Auvryon fatal: ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
  if (ob_get_level()) ob_end_clean();
  http_response_code(500);
  echo '<!doctype html><meta charset="utf-8"><title>Error</title><link rel="stylesheet" href="assets/css/auvryon.css"><div class="content"><div class="card"><h1>Something went wrong</h1><p>No money was moved. Please go back and try again.</p><a class="btn" href="index.php">Back to dashboard</a>' . (cfg('debug') ? '<pre>' . e($e->getMessage()) . '</pre>' : '') . '</div></div>';
}
function run_maintenance_light(): void {   // cheap, idempotent: releases elapsed holds and runs due rules for this user
  static $done = false; if ($done) return; $done = true;
  Pay::processHolds(); Rules::runDue((int)$_SESSION['uid']); Invoices::markOverdue();
}
