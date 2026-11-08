<?php
$uid = (int)$u['id']; $kind = (string)($_GET['kind'] ?? ''); $raw = true;
$f = ['q' => (string)($_GET['q'] ?? ''), 'type' => (string)($_GET['type'] ?? ''), 'status' => (string)($_GET['status'] ?? ''), 'dir' => (string)($_GET['dir'] ?? ''), 'from' => (string)($_GET['from'] ?? ''), 'to' => (string)($_GET['to'] ?? ''), 'cur' => (string)($_GET['cur'] ?? '')];
if ($kind === 'csv') { audit($uid, 'export_csv', 'user', $uid); header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="auvryon-statement-' . date('Ymd') . '.csv"'); echo Activity::csv($uid, $f); exit; }
if ($kind === 'pdf') { audit($uid, 'export_pdf', 'user', $uid); header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="auvryon-statement-' . date('Ymd') . '.pdf"'); echo Activity::pdf($uid, $f, $u['name']); exit; }
if ($kind === 'receipt') {
  $ref = preg_replace('/[^A-Z0-9]/', '', (string)($_GET['ref'] ?? ''));
  $t = row('SELECT t.*, fu.name AS from_name, tu.name AS to_name, fu.id fid, tu.id tid FROM transactions t LEFT JOIN wallets fw ON fw.id=t.from_wallet_id LEFT JOIN users fu ON fu.id=fw.user_id LEFT JOIN wallets tw ON tw.id=t.to_wallet_id LEFT JOIN users tu ON tu.id=tw.user_id WHERE t.ref=?', [$ref]);
  $acc = Activity::accountIds($uid);
  if (!$t || ((int)$t['fid'] !== $uid && (int)$t['tid'] !== $uid && $u['role'] !== 'admin' && !val('SELECT 1 FROM ledger_entries WHERE txn_id=? AND account_id IN (' . implode(',', $acc) . ')', [$t['id']]))) { http_response_code(404); echo 'Not found'; exit; }
  $es = rows('SELECT e.phase, e.direction, e.amount, a.name FROM ledger_entries e JOIN ledger_accounts a ON a.id=e.account_id WHERE e.txn_id=? ORDER BY e.id', [$t['id']]);
  header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="receipt-' . $ref . '.pdf"'); echo Activity::receiptPdf($t, $es); exit;
}
http_response_code(400); echo 'Unknown export';
