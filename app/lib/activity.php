<?php
declare(strict_types=1);
/** Unified activity: one query over every transaction type; "delta" is read from the ledger so it is always the true effect on the user's wallets. */
final class Activity {
  static function accountIds(int $uid): array { return array_map('intval', array_column(rows('SELECT account_id FROM wallets WHERE user_id=?', [$uid]), 'account_id')); }
  static function build(int $uid, array $f): array {
    $acc = self::accountIds($uid); $in = implode(',', $acc); $w = []; $p = [];
    $w[] = "(t.from_wallet_id IN (SELECT id FROM wallets WHERE user_id=$uid) OR t.to_wallet_id IN (SELECT id FROM wallets WHERE user_id=$uid) OR EXISTS (SELECT 1 FROM ledger_entries x WHERE x.txn_id=t.id AND x.account_id IN ($in)))";
    if (($f['type'] ?? '') !== '') { $w[] = 't.type=?'; $p[] = $f['type']; }
    if (($f['status'] ?? '') !== '') { $w[] = 't.status=?'; $p[] = $f['status']; }
    if (in_array($f['cur'] ?? '', ['BDT', 'USD'], true)) { $w[] = 't.currency=?'; $p[] = $f['cur']; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['from'] ?? '')) { $w[] = 't.created_at>=?'; $p[] = $f['from'] . ' 00:00:00'; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['to'] ?? '')) { $w[] = 't.created_at<=?'; $p[] = $f['to'] . ' 23:59:59'; }
    if (trim($f['q'] ?? '') !== '') { $w[] = '(t.note LIKE ? OR t.ref LIKE ? OR fu.name LIKE ? OR tu.name LIKE ? OR t.category LIKE ?)'; $like = '%' . trim($f['q']) . '%'; array_push($p, $like, $like, $like, $like, $like); }
    $having = ''; if (($f['dir'] ?? '') === 'in') $having = 'HAVING delta > 0'; elseif (($f['dir'] ?? '') === 'out') $having = 'HAVING delta < 0';
    $sql = "SELECT t.*, fu.name AS from_name, tu.name AS to_name, (SELECT COALESCE(SUM(CASE e.direction WHEN 'C' THEN e.amount ELSE -e.amount END),0) FROM ledger_entries e WHERE e.txn_id=t.id AND e.account_id IN ($in) AND e.currency=t.currency) AS delta
            FROM transactions t LEFT JOIN wallets fw ON fw.id=t.from_wallet_id LEFT JOIN users fu ON fu.id=fw.user_id LEFT JOIN wallets tw ON tw.id=t.to_wallet_id LEFT JOIN users tu ON tu.id=tw.user_id
            WHERE " . implode(' AND ', $w) . " $having ORDER BY t.id DESC";
    return [$sql, $p];
  }
  static function fetch(int $uid, array $f, int $limit = 25, int $offset = 0): array { [$sql, $p] = self::build($uid, $f); return rows($sql . ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset, $p); }
  static function count(int $uid, array $f): int { [$sql, $p] = self::build($uid, $f); return (int)val("SELECT COUNT(*) FROM ($sql) z", $p); }
  static function counterparty(array $t, int $uid): string {
    $mineFrom = $t['from_name'] !== null && (int)val('SELECT user_id FROM wallets WHERE id=?', [$t['from_wallet_id']]) === $uid;
    if ($t['type'] === 'topup') return 'Simulated deposit'; if (in_array($t['type'], ['bill_payment'], true)) return 'Bill payee (simulated)';
    if (in_array($t['type'], ['pocket_deposit', 'pocket_withdraw', 'round_up'], true)) return 'My pocket'; if ($t['type'] === 'conversion') return 'My wallets';
    return (string)($mineFrom ? ($t['to_name'] ?? '-') : ($t['from_name'] ?? '-'));
  }
  private static function csvCell($v): string { $s = (string)$v; if ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false) $s = "'" . $s; return '"' . str_replace('"', '""', $s) . '"'; }
  static function csv(int $uid, array $f): string {
    $out = "\xEF\xBB\xBF" . implode(',', ['Date', 'Reference', 'Type', 'Status', 'Counterparty', 'Currency', 'Amount', 'Fee', 'Net effect on my wallet', 'Category', 'Note']) . "\r\n";
    foreach (self::fetch($uid, $f, 5000, 0) as $t) $out .= implode(',', array_map([self::class, 'csvCell'], [$t['created_at'], $t['ref'], $t['type'], $t['status'], self::counterparty($t, $uid), $t['currency'], $t['amount'], $t['fee'], $t['delta'], $t['category'], $t['note']])) . "\r\n";
    return $out;
  }
  static function pdf(int $uid, array $f, string $name): string {
    $pdf = new MiniPdf('Auvryon statement'); $pdf->line('Auvryon - Account statement', 16, true); $pdf->line("Account holder: $name", 10); $pdf->line('Generated ' . date('d M Y, H:i') . ' (all balances are simulated demonstration money)', 9, false, MiniPdf::M, 6);
    foreach (['BDT', 'USD'] as $c) $pdf->line("$c wallet balance: " . money_plain(Ledger::wallet($uid, $c)['balance'], $c), 10, true, MiniPdf::M, 2);
    $pdf->rule(); $pdf->row([['Date', 48, 'l'], ['Reference', 118, 'l'], ['Type', 205, 'l'], ['Status', 285, 'l'], ['Counterparty', 340, 'l'], ['Net effect', 547, 'r']], 8.5, true); $pdf->rule();
    $net = ['BDT' => 0, 'USD' => 0];
    foreach (self::fetch($uid, $f, 2000, 0) as $t) { $net[$t['currency']] += cents($t['delta']); $pdf->row([[date('d M y H:i', strtotime($t['created_at'])), 48, 'l'], [$t['ref'], 118, 'l'], [type_label($t['type']), 205, 'l'], [$t['status'], 285, 'l'], [mb_substr(self::counterparty($t, $uid), 0, 26), 340, 'l'], [($t['delta'] >= 0 ? '+' : '') . number_format((float)$t['delta'], 2) . ' ' . $t['currency'], 547, 'r']]); }
    $pdf->rule(); foreach ($net as $c => $v) $pdf->line("Net movement $c in this statement: " . ($v >= 0 ? '+' : '') . dec($v) . " $c", 9, true);
    return $pdf->output();
  }
  static function receiptPdf(array $t, array $entries): string {
    $pdf = new MiniPdf('Auvryon receipt ' . $t['ref']); $pdf->line('Auvryon - Payment receipt', 16, true); $pdf->line('SIMULATED transaction: no real money moved', 9, false, MiniPdf::M, 8);
    foreach ([['Reference', $t['ref']], ['Date', $t['created_at']], ['Type', type_label($t['type'])], ['Status', $t['status']], ['From', $t['from_name'] ?? '-'], ['To', $t['to_name'] ?? '-'], ['Amount', money_plain($t['amount'], $t['currency'])], ['Fee', money_plain($t['fee'], $t['currency'])], ['Note', $t['note']]] as [$k, $v]) $pdf->row([[$k, 48, 'l'], [(string)$v, 150, 'l']], 10);
    $pdf->rule(); $pdf->line('Ledger entries (double-entry: debits = credits)', 11, true); $pdf->row([['Phase', 48, 'l'], ['Account', 110, 'l'], ['Debit', 470, 'r'], ['Credit', 547, 'r']], 8.5, true);
    foreach ($entries as $e) $pdf->row([[$e['phase'], 48, 'l'], [$e['name'], 110, 'l'], [$e['direction'] === 'D' ? number_format((float)$e['amount'], 2) : '', 470, 'r'], [$e['direction'] === 'C' ? number_format((float)$e['amount'], 2) : '', 547, 'r']]);
    return $pdf->output();
  }
}
