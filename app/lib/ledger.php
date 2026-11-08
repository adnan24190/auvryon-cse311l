<?php
declare(strict_types=1);
/**
 * Double-entry ledger. Every posting must balance (debits = credits) per currency.
 * All ledger accounts are liability-style: balance = credits - debits.
 *   wallet/pocket/hold/escrow/fee/external: must never go negative (DB CHECK + code check)
 *   mint/fx: may go negative (they represent the simulated outside world / conversion pool)
 */
final class Ledger {
  static function sys(string $kind, string $cur): int {
    static $c = [];
    $k = "$kind-$cur";
    if (!isset($c[$k])) {
      $c[$k] = (int)val('SELECT id FROM ledger_accounts WHERE kind=? AND currency=? AND code LIKE "SYS-%"', [$kind, $cur]);
      if (!$c[$k]) throw new AppError("System ledger account missing: $k");
    }
    return $c[$k];
  }
  static function newAccount(string $code, string $kind, string $cur, string $name): int {
    q('INSERT INTO ledger_accounts (code,kind,currency,name) VALUES (?,?,?,?)', [$code, $kind, $cur, $name]);
    return (int)db()->lastInsertId();
  }
  static function newTxn(array $f): array {
    $ref = new_ref('AV', 14);
    q('INSERT INTO transactions (ref,type,status,from_wallet_id,to_wallet_id,amount,fee,currency,category,note,created_by,refund_of,ref_table,ref_id,completed_at)
       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
      $ref, $f['type'], $f['status'], $f['from_wallet'] ?? null, $f['to_wallet'] ?? null, dec((int)$f['amount_c']), dec((int)($f['fee_c'] ?? 0)),
      $f['currency'], $f['category'] ?? 'general', mb_substr((string)($f['note'] ?? ''), 0, 255), $f['created_by'], $f['refund_of'] ?? null,
      $f['ref_table'] ?? null, $f['ref_id'] ?? null, $f['status'] === 'completed' ? date('Y-m-d H:i:s') : null]);
    return [(int)db()->lastInsertId(), $ref];
  }
  /** entries: list of [account_id, 'D'|'C', cents, currency] */
  static function post(int $txnId, string $phase, array $entries): void {
    if (!db()->inTransaction()) throw new LogicException('Ledger::post requires an open DB transaction');
    $sum = [];
    $delta = [];
    foreach ($entries as [$acc, $dir, $amt, $cur]) {
      if ($amt <= 0) continue;
      $sum[$cur] = ($sum[$cur] ?? 0) + ($dir === 'D' ? $amt : -$amt);
      $delta[$acc] = ($delta[$acc] ?? 0) + ($dir === 'C' ? $amt : -$amt);
    }
    foreach ($sum as $cur => $s) if ($s !== 0) throw new LogicException("Unbalanced ledger posting ($cur off by $s)");
    $ids = array_keys($delta);
    sort($ids);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $locked = rows("SELECT id, kind, balance, name FROM ledger_accounts WHERE id IN ($in) ORDER BY id FOR UPDATE", $ids);
    foreach ($locked as $a) {
      $new = cents($a['balance']) + $delta[(int)$a['id']];
      if ($new < 0 && !in_array($a['kind'], ['mint', 'fx'], true)) throw new InsufficientFunds('Insufficient balance.');
    }
    foreach ($entries as [$acc, $dir, $amt, $cur]) {
      if ($amt <= 0) continue;
      q('INSERT INTO ledger_entries (txn_id,phase,account_id,direction,amount,currency) VALUES (?,?,?,?,?,?)', [$txnId, $phase, $acc, $dir, dec($amt), $cur]);
    }
    foreach ($delta as $acc => $d) q('UPDATE ledger_accounts SET balance = balance + ? WHERE id=?', [dec($d), $acc]);
  }
  static function wallet(int $userId, string $cur): array {
    $w = row('SELECT w.*, a.balance FROM wallets w JOIN ledger_accounts a ON a.id=w.account_id WHERE w.user_id=? AND w.currency=?', [$userId, $cur]);
    if (!$w) throw new AppError("Wallet ($cur) not found.");
    return $w;
  }
  static function walletById(int $id): array {
    $w = row('SELECT w.*, a.balance, u.name AS owner FROM wallets w JOIN ledger_accounts a ON a.id=w.account_id JOIN users u ON u.id=w.user_id WHERE w.id=?', [$id]);
    if (!$w) throw new AppError('Wallet not found.');
    return $w;
  }
  static function createWallet(int $userId, string $cur): int {
    $acc = self::newAccount("W-$userId-$cur", 'wallet', $cur, "Wallet user $userId $cur");
    q('INSERT INTO wallets (user_id,currency,account_id) VALUES (?,?,?)', [$userId, $cur, $acc]);
    return (int)db()->lastInsertId();
  }
  static function balanceFromEntries(int $accId): string {
    return (string)val("SELECT COALESCE(SUM(CASE direction WHEN 'C' THEN amount ELSE -amount END),0) FROM ledger_entries WHERE account_id=?", [$accId]);
  }
  /** Reconciliation: returns list of invariant checks with pass/fail and detail. */
  static function reconcile(): array {
    $out = [];
    $bad = rows("SELECT txn_id, phase, currency, SUM(CASE direction WHEN 'D' THEN amount ELSE -amount END) AS diff FROM ledger_entries GROUP BY txn_id, phase, currency HAVING diff <> 0");
    $out[] = ['I1 Every posting balances (debits = credits per txn/phase/currency)', !$bad, $bad ? count($bad) . ' unbalanced postings' : 'all postings balanced'];
    $bad = rows("SELECT a.code, a.balance, COALESCE(s.b,0) AS entries FROM ledger_accounts a LEFT JOIN (SELECT account_id, SUM(CASE direction WHEN 'C' THEN amount ELSE -amount END) b FROM ledger_entries GROUP BY account_id) s ON s.account_id=a.id WHERE a.balance <> COALESCE(s.b,0)");
    $out[] = ['I2 Cached account balance = sum of ledger entries', !$bad, $bad ? 'mismatch: ' . $bad[0]['code'] : 'all accounts agree'];
    $v = val("SELECT COUNT(*) FROM ledger_accounts WHERE balance < 0 AND kind NOT IN ('mint','fx')");
    $out[] = ['I3 No negative balance on wallet/pocket/hold/escrow/fee accounts', (int)$v === 0, "$v negative accounts"];
    $bad = rows("SELECT currency, SUM(balance) s FROM ledger_accounts GROUP BY currency HAVING s <> 0");
    $out[] = ['I4 Sum of all account balances = 0 per currency', !$bad, $bad ? 'non-zero: ' . $bad[0]['currency'] : 'zero in every currency'];
    foreach (['BDT', 'USD'] as $c) {
      $hold = (string)val("SELECT balance FROM ledger_accounts WHERE kind='hold' AND currency=? AND code LIKE 'SYS-%'", [$c]);
      $exp = (string)val("SELECT COALESCE(SUM(t.amount+t.fee),0) FROM transfer_holds h JOIN transactions t ON t.id=h.txn_id WHERE h.status='held' AND t.currency=?", [$c]);
      $out[] = ["I5 Hold account ($c) = sum of active held transfers", bccomp_s($hold, $exp), "ledger $hold vs holds $exp"];
      $esc = (string)val("SELECT balance FROM ledger_accounts WHERE kind='escrow' AND currency=? AND code LIKE 'SYS-%'", [$c]);
      $exp = (string)val("SELECT COALESCE(SUM(m.amount),0) FROM escrow_milestones m JOIN escrows e ON e.id=m.escrow_id WHERE m.status IN ('funded','submitted','disputed') AND e.currency=?", [$c]);
      $out[] = ["I6 Escrow account ($c) = sum of funded unreleased milestones", bccomp_s($esc, $exp), "ledger $esc vs milestones $exp"];
    }
    $v = val("SELECT COUNT(*) FROM transactions t WHERE t.status IN ('completed','refunded','disputed') AND NOT EXISTS (SELECT 1 FROM ledger_entries e WHERE e.txn_id=t.id)");
    $out[] = ['I7 Every settled transaction has ledger entries', (int)$v === 0, "$v without entries"];
    $v = val("SELECT COUNT(*) FROM (SELECT refund_of FROM transactions WHERE refund_of IS NOT NULL GROUP BY refund_of HAVING COUNT(*)>1) x");
    $out[] = ['I8 No transaction is refunded twice', (int)$v === 0, "$v duplicates"];
    return $out;
  }
}
function bccomp_s(string $a, string $b): bool { return cents($a) === cents($b); }
