<?php
declare(strict_types=1);
define('APP_ROOT', dirname(__DIR__));
class AppError extends RuntimeException {}
class InsufficientFunds extends AppError {}

function cfg(?string $k = null) {
  static $c = null;
  if ($c === null) {
    $c = require APP_ROOT . '/config.php';
    if (getenv('AUVRYON_DB')) $c['db']['name'] = getenv('AUVRYON_DB');
  }
  return $k === null ? $c : ($c[$k] ?? null);
}
date_default_timezone_set(cfg('timezone') ?: 'Asia/Dhaka');

function db(): PDO {
  static $pdo = null;
  if ($pdo === null) {
    $d = cfg('db');
    $pdo = new PDO("mysql:host={$d['host']};port={$d['port']};dbname={$d['name']};charset={$d['charset']}", $d['user'], $d['pass'], [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET time_zone = '" . date('P') . "'");
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
  }
  return $pdo;
}
function q(string $sql, array $p = []): PDOStatement { $s = db()->prepare($sql); $s->execute($p); return $s; }
function row(string $sql, array $p = []): ?array { $r = q($sql, $p)->fetch(); return $r ?: null; }
function rows(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }
function val(string $sql, array $p = []) { $r = q($sql, $p)->fetchColumn(); return $r === false ? null : $r; }
function tx(callable $fn) {
  static $n = 0;
  $db = db();
  if ($db->inTransaction()) {            // nested: use a savepoint so a failed inner step leaves no partial rows
    $sp = 'sp' . (++$n);
    $db->exec("SAVEPOINT $sp");
    try { $r = $fn(); $db->exec("RELEASE SAVEPOINT $sp"); return $r; }
    catch (Throwable $e) { $db->exec("ROLLBACK TO SAVEPOINT $sp"); throw $e; }
  }
  for ($attempt = 1; ; $attempt++) {
    $db->beginTransaction();
    try { $r = $fn(); $db->commit(); return $r; }
    catch (Throwable $e) {
      if ($db->inTransaction()) $db->rollBack();
      $deadlock = $e instanceof PDOException && in_array((string)($e->errorInfo[1] ?? ''), ['1213', '1205'], true);
      if ($deadlock && $attempt < 4) { usleep(random_int(20000, 80000) * $attempt); continue; }   // safe retry: nothing was committed
      throw $e;
    }
  }
}

// ---- money (integer minor units; never floats for ledger math) ----
function cents($v): int {
  $s = trim((string)$v);
  if (!preg_match('/^-?\d{1,13}(\.\d{1,2})?$/', $s)) throw new AppError('Invalid amount. Use a number with at most 2 decimals.');
  return (int)round(((float)$s) * 100);
}
function dec(int $c): string { return number_format($c / 100, 2, '.', ''); }
function money($v, string $cur = 'BDT'): string {
  $n = number_format((float)$v, 2, '.', ',');
  return ($cur === 'USD' ? '$' : '৳') . $n;
}
function money_plain($v, string $cur = 'BDT'): string { return $cur . ' ' . number_format((float)$v, 2, '.', ','); }

// ---- security helpers ----
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function ip(): string { return $_SERVER['REMOTE_ADDR'] ?? 'cli'; }
function start_session(): void {
  if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') return;
  session_name('auvryon');
  session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'path' => '/']);
  session_start();
}
function csrf_token(): string {
  if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
  return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check(): void {
  $t = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
  if (!is_string($t) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $t)) throw new AppError('Security check failed (CSRF). Please reload the page and try again.');
}
function rate_limit(string $bucket, int $max, int $windowSec): bool {
  $bucket = substr($bucket, 0, 120);
  return tx(function () use ($bucket, $max, $windowSec) {
    $r = row('SELECT hits, window_start FROM rate_limits WHERE bucket=? FOR UPDATE', [$bucket]);
    if (!$r || strtotime($r['window_start']) <= time() - $windowSec) {
      q('INSERT INTO rate_limits (bucket,hits,window_start) VALUES (?,1,NOW()) ON DUPLICATE KEY UPDATE hits=1, window_start=NOW()', [$bucket]);
      return true;
    }
    if ((int)$r['hits'] >= $max) return false;
    q('UPDATE rate_limits SET hits=hits+1 WHERE bucket=?', [$bucket]);
    return true;
  });
}
function audit(?int $uid, string $action, string $entity = '', ?int $eid = null, string $details = ''): void {
  q('INSERT INTO audit_logs (user_id,action,entity,entity_id,ip_address,details) VALUES (?,?,?,?,?,?)', [$uid, $action, $entity, $eid, ip(), mb_substr($details, 0, 400)]);
}
function notify(int $uid, string $cat, string $title, string $body = ''): void {
  $map = ['login' => 'notify_login', 'transaction' => 'notify_transaction', 'reminder' => 'notify_reminder'];
  if (isset($map[$cat])) {
    $on = val("SELECT {$map[$cat]} FROM user_settings WHERE user_id=?", [$uid]);
    if ($on !== null && (int)$on === 0) return;
  }
  q('INSERT INTO notifications (user_id,category,title,body) VALUES (?,?,?,?)', [$uid, $cat, mb_substr($title, 0, 150), mb_substr($body, 0, 400)]);
}
function timeline(string $type, int $id, ?int $actor, string $event, string $note = ''): void {
  q('INSERT INTO timeline_events (entity_type,entity_id,actor_id,event,note) VALUES (?,?,?,?,?)', [$type, $id, $actor, $event, mb_substr($note, 0, 255)]);
}
function setting(string $k, string $default = '0'): string {
  if (!isset($GLOBALS['_settings_cache'])) $GLOBALS['_settings_cache'] = array_column(rows('SELECT skey, svalue FROM settings'), 'svalue', 'skey');
  return $GLOBALS['_settings_cache'][$k] ?? $default;
}
function setting_reset(): void { unset($GLOBALS['_settings_cache']); }
function setting_set(string $k, string $v): void { q('UPDATE settings SET svalue=? WHERE skey=?', [$v, $k]); setting_reset(); }
function new_ref(string $prefix, int $len): string { return substr($prefix . strtoupper(bin2hex(random_bytes(8))), 0, $len); }
function valid_pin(string $p): bool { return (bool)preg_match('/^\d{4,6}$/', $p); }
