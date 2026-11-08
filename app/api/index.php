<?php
declare(strict_types=1);
/** Auvryon JSON API v1. Bearer-token auth (for a future mobile app). Documented in docs/API.md. */
require __DIR__ . '/../lib/boot.php';
header('Content-Type: application/json; charset=utf-8'); header('X-Content-Type-Options: nosniff');
function out(int $code, array $body): never { http_response_code($code); echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
$method = $_SERVER['REQUEST_METHOD']; $r = trim((string)($_GET['r'] ?? ''), '/'); $in = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
try {
  if ($r === 'auth/login' && $method === 'POST') {
    $usr = Auth::login((string)($in['email'] ?? ''), (string)($in['password'] ?? '')); out(200, ['token' => Auth::issueToken((int)$usr['id']), 'expires_in' => 86400, 'user' => ['id' => (int)$usr['id'], 'name' => $usr['name'], 'role' => $usr['role']]]);
  }
  $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''); if ($h === '' && function_exists('getallheaders')) { foreach (getallheaders() as $k => $v) if (strtolower($k) === 'authorization') $h = $v; }
  if (!preg_match('/^Bearer\s+([a-f0-9]{64})$/', $h, $m) || !($u = Auth::userFromToken($m[1]))) out(401, ['error' => 'Missing or invalid bearer token.']);
  $uid = (int)$u['id'];
  if (!rate_limit("api:$uid", 120, 60)) out(429, ['error' => 'Rate limit exceeded (120 requests/minute).']);
  Pay::processHolds();
  switch ("$method $r") {
    case 'GET me': out(200, ['id' => $uid, 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role']]);
    case 'GET wallets': out(200, ['wallets' => array_map(fn($c) => ['currency' => $c, 'balance' => Ledger::wallet($uid, $c)['balance'], 'frozen' => (bool)Ledger::wallet($uid, $c)['frozen']], ['BDT', 'USD']), 'note' => 'Simulated balances']);
    case 'GET transactions': $f = array_intersect_key($_GET, array_flip(['q', 'type', 'status', 'dir', 'from', 'to', 'cur'])); out(200, ['items' => array_map(fn($t) => ['ref' => $t['ref'], 'type' => $t['type'], 'status' => $t['status'], 'amount' => $t['amount'], 'fee' => $t['fee'], 'currency' => $t['currency'], 'net_effect' => $t['delta'], 'note' => $t['note'], 'created_at' => $t['created_at']], Activity::fetch($uid, $f, min(100, max(1, (int)($_GET['limit'] ?? 25))), max(0, (int)($_GET['offset'] ?? 0))))]);
    case 'GET ledger': $c = ($_GET['currency'] ?? 'BDT') === 'USD' ? 'USD' : 'BDT'; $w = Ledger::wallet($uid, $c); $es = rows("SELECT e.id, t.ref, e.phase, e.direction, e.amount, e.created_at FROM ledger_entries e JOIN transactions t ON t.id=e.txn_id WHERE e.account_id=? ORDER BY e.id DESC LIMIT 100", [$w['account_id']]); out(200, ['balance' => $w['balance'], 'sum_of_entries' => Ledger::balanceFromEntries((int)$w['account_id']), 'entries' => $es]);
    case 'GET safe-to-spend': $s = Cash::safe($uid); out(200, ['balance' => dec($s['balance_c']), 'bills_30d' => dec($s['bills_c']), 'savings_plan' => dec($s['savings_c']), 'buffer' => dec($s['buffer_c']), 'safe_to_spend' => dec($s['safe_c']), 'safe_if_income_arrives' => dec($s['safe_with_income_c']), 'per_day' => dec($s['daily_c']), 'explanation' => 'balance - bills - savings plan - buffer; income excluded from the conservative figure']);
    case 'GET forecast': $f = Cash::forecast($uid); out(200, ['assumptions' => $f['assumptions'], 'shortfall' => $f['shortfall'] ? ['date' => $f['shortfall']['date'], 'balance' => dec($f['shortfall']['balance_c'])] : null, 'series' => array_map(fn($p) => ['date' => $p['date'], 'balance' => dec($p['balance_c'])], $f['series'])]);
    case 'POST transfers/preview': $p = Pay::preview($uid, (string)($in['to'] ?? ''), (string)($in['amount'] ?? ''), ($in['currency'] ?? 'BDT') === 'USD' ? 'USD' : 'BDT'); out(200, ['recipient' => $p['to']['name'], 'amount' => dec($p['amount_c']), 'fee' => dec($p['fee_c']), 'total' => dec($p['total_c']), 'will_hold' => $p['hold'], 'hold_reason' => $p['hold_reason'], 'cancel_window_seconds' => $p['window'], 'risk' => $p['risk']]);
    case 'POST transfers':
      $key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''; if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $key)) out(400, ['error' => 'Idempotency-Key header (8-64 chars) is required.']);
      Auth::verifyPin($uid, (string)($in['pin'] ?? '')); $cur = ($in['currency'] ?? 'BDT') === 'USD' ? 'USD' : 'BDT'; $p = Pay::preview($uid, (string)($in['to'] ?? ''), (string)($in['amount'] ?? ''), $cur);
      if ($p['risk']['level'] === 'high') Auth::verifyPassword($uid, (string)($in['password'] ?? ''));
      $res = Pay::send(['from_user' => $uid, 'to_user' => (int)$p['to']['id'], 'currency' => $cur, 'amount_c' => $p['amount_c'], 'type' => 'transfer', 'category' => 'transfer', 'note' => (string)($in['note'] ?? ''), 'idem_key' => $key, 'actor' => $uid, 'allow_hold' => true]);
      out(!empty($res['duplicate']) ? 200 : 201, ['ref' => $res['ref'], 'status' => $res['status'], 'duplicate' => !empty($res['duplicate'])]);
    case 'POST transfers/cancel': $t = row('SELECT id FROM transactions WHERE ref=?', [(string)($in['ref'] ?? '')]); if (!$t) out(404, ['error' => 'Transfer not found.']); Pay::cancelHold($uid, (int)$t['id']); out(200, ['status' => 'cancelled']);
    case 'POST requests': out(201, ['id' => Pay::request($uid, (string)($in['to'] ?? ''), (string)($in['amount'] ?? ''), 'BDT', (string)($in['note'] ?? ''))]);
    case 'GET groups': out(200, ['groups' => rows('SELECT g.id, g.name, g.group_type FROM expense_groups g JOIN group_members m ON m.group_id=g.id WHERE m.user_id=?', [$uid])]);
    case 'GET group-settlements': $g = (int)($_GET['group_id'] ?? 0); if (!Groups::isMember($g, $uid)) out(403, ['error' => 'Not a member.']); $o = Groups::optimize(Groups::net($g)); out(200, ['method' => $o['method'], 'transfers' => array_map(fn($t) => ['from' => $t['from'], 'to' => $t['to'], 'amount' => dec($t['amount_c'])], $o['transfers'])]);
    case 'GET exchange-rate': $x = Fx::rate(); out(200, ['pair' => 'USD/BDT', 'rate' => $x['rate'], 'as_of' => $x['as_of'], 'source' => $x['source'], 'simulated' => true]);
    case 'GET reconciliation': if ($u['role'] !== 'admin') out(403, ['error' => 'Admin only.']); out(200, ['checks' => array_map(fn($c) => ['invariant' => $c[0], 'passed' => $c[1], 'detail' => $c[2]], Ledger::reconcile())]);
    default: out(404, ['error' => 'Unknown endpoint.']);
  }
} catch (AppError $e) { out(422, ['error' => $e->getMessage()]); }
catch (Throwable $e) { error_log('API: ' . $e->getMessage()); out(500, ['error' => 'Server error. No money was moved.']); }
