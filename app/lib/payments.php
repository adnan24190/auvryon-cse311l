<?php
declare(strict_types=1);
class DupKey extends Exception {}
class DupExpense extends AppError { public $alertTxn; public $uid; }

final class Pay {
  const OUT_TYPES = "'transfer','request_payment','merchant_payment','settlement','iou_repayment','invoice_payment','bill_payment','token_payment','offline_payment','rule_transfer'";

  static function fee(string $type, int $amountC, string $cur): int {
    if (!in_array($type, ['transfer', 'request_payment'], true)) return 0;
    $bps = (int)round((float)setting('fee_percent', '0.5') * 100);
    return max(cents(setting("fee_min_$cur", '1.00')), intdiv($amountC * $bps + 9999, 10000));
  }
  static function merchantFee(int $amountC): int {
    $bps = (int)round((float)setting('merchant_fee_percent', '1') * 100);
    return intdiv($amountC * $bps + 9999, 10000);
  }
  static function findUser(string $ident, int $exceptId): array {
    $ident = trim($ident);
    $u = row('SELECT id, name, email, phone, status FROM users WHERE (email=? OR phone=?) AND id<>? LIMIT 1', [strtolower($ident), $ident, $exceptId]);
    if (!$u || $u['status'] !== 'active') throw new AppError('Recipient not found. Check the email or phone number.');
    return $u;
  }
  static function isFirstTime(int $fromWalletId, int $toWalletId): bool {
    return !val("SELECT 1 FROM transactions WHERE from_wallet_id=? AND to_wallet_id=? AND status IN ('completed','held') AND type IN ('transfer','request_payment') LIMIT 1", [$fromWalletId, $toWalletId]);
  }

  /** Explainable rule-based risk assessment. Never claims to detect fraud - it flags patterns for confirmation. */
  static function risk(int $fromUser, string $cur, int $toUser, int $amountC, bool $deviceNew): array {
    $w = Ledger::wallet($fromUser, $cur); $tw = Ledger::wallet($toUser, $cur);
    $flags = []; $score = ['low' => 0, 'medium' => 0, 'high' => 0];
    $add = function (string $sev, string $code, string $msg) use (&$flags, &$score) { $flags[] = ['severity' => $sev, 'code' => $code, 'message' => $msg]; $score[$sev]++; };
    if (self::isFirstTime((int)$w['id'], (int)$tw['id'])) $add('medium', 'new_recipient', 'You have not paid this recipient before.');
    if ($amountC >= cents(setting("high_value_$cur"))) $add('medium', 'high_value', 'This is a high-value payment.');
    $avg = row("SELECT COUNT(*) n, AVG(amount) a FROM (SELECT amount FROM transactions WHERE from_wallet_id=? AND status IN ('completed','held') AND type IN (" . self::OUT_TYPES . ") ORDER BY id DESC LIMIT 20) x", [$w['id']]);
    if ((int)$avg['n'] >= 5 && $amountC > 300 * (float)$avg['a']) $add('medium', 'unusual_amount', 'This is more than 3x your recent average payment.');
    $vel = (int)val("SELECT COUNT(*) FROM transactions WHERE from_wallet_id=? AND created_at>=DATE_SUB(NOW(), INTERVAL 10 MINUTE) AND status<>'failed' AND type IN ('transfer','request_payment')", [$w['id']]);
    if ($vel >= 4) $add('high', 'velocity', "$vel transfers in the last 10 minutes.");
    if ($deviceNew && $amountC >= cents(setting("high_value_$cur"))) $add('high', 'new_device', 'High-value payment from a device first seen less than 30 minutes ago.');
    if (cents($w['balance']) > 0 && $amountC >= 0.8 * cents($w['balance'])) $add('low', 'drains_balance', 'This uses 80% or more of your balance.');
    $level = $score['high'] > 0 || $score['medium'] >= 2 ? 'high' : ($score['medium'] > 0 ? 'medium' : ($score['low'] > 0 ? 'low' : 'none'));
    return ['level' => $level, 'flags' => $flags];
  }

  /** Describes what send() would do, without changing anything (used by the confirmation screen). */
  static function preview(int $fromUser, string $ident, string $amount, string $cur, string $type = 'transfer', bool $deviceNew = false): array {
    $to = self::findUser($ident, $fromUser);
    $amountC = cents($amount);
    if ($amountC <= 0) throw new AppError('Amount must be greater than zero.');
    $w = Ledger::wallet($fromUser, $cur); $tw = Ledger::wallet((int)$to['id'], $cur);
    $fee = self::fee($type, $amountC, $cur);
    $first = self::isFirstTime((int)$w['id'], (int)$tw['id']);
    $high = $amountC >= cents(setting("high_value_$cur"));
    $hold = $type === 'transfer' && ($first || $high);
    return ['to' => $to, 'amount_c' => $amountC, 'fee_c' => $fee, 'total_c' => $amountC + $fee, 'currency' => $cur, 'balance_c' => cents($w['balance']),
            'hold' => $hold, 'hold_reason' => $first ? 'first-time recipient' : ($high ? 'high-value payment' : ''),
            'window' => (int)setting('cancel_window_seconds', '300'), 'risk' => self::risk($fromUser, $cur, (int)$to['id'], $amountC, $deviceNew)];
  }

  static function topup(int $uid, string $cur, int $amountC, string $note = 'Simulated deposit', string $category = 'income'): array {
    if ($amountC <= 0 || $amountC > cents('1000000')) throw new AppError('Top-up must be between 0.01 and 1,000,000.');
    $res = tx(function () use ($uid, $cur, $amountC, $note, $category) {
      $w = Ledger::wallet($uid, $cur);
      [$id, $ref] = Ledger::newTxn(['type' => 'topup', 'status' => 'completed', 'to_wallet' => $w['id'], 'amount_c' => $amountC, 'currency' => $cur, 'category' => $category, 'note' => $note, 'created_by' => $uid]);
      Ledger::post($id, 'post', [[Ledger::sys('mint', $cur), 'D', $amountC, $cur], [(int)$w['account_id'], 'C', $amountC, $cur]]);
      audit($uid, 'topup', 'transaction', $id, dec($amountC) . " $cur (simulated)");
      return ['txn_id' => $id, 'ref' => $ref, 'status' => 'completed'];
    });
    Rules::afterTxn($res['txn_id']);
    return $res;
  }

  /** The single entry point for wallet-to-wallet value movement. */
  static function send(array $o): array {
    $actor = (int)$o['actor']; $key = $o['idem_key'] ?? null;
    try {
      $res = tx(function () use ($o, $actor, $key) {
        if ($key) {
          try { q('INSERT INTO idempotency_keys (user_id,idem_key,action) VALUES (?,?,?)', [$actor, $key, $o['type']]); }
          catch (PDOException $e) { if ($e->getCode() === '23000') throw new DupKey(); throw $e; }
        }
        return self::sendInner($o, $actor, $key);
      });
    } catch (DupKey $d) {
      $t = row('SELECT t.id, t.ref, t.status FROM idempotency_keys k LEFT JOIN transactions t ON t.id=k.txn_id WHERE k.user_id=? AND k.idem_key=? AND k.action=?', [$actor, $key, $o['type']]);
      return ['txn_id' => $t['id'] ?? null, 'ref' => $t['ref'] ?? null, 'status' => $t['status'] ?? 'pending', 'duplicate' => true];
    }
    if (!empty($res['txn_id']) && $res['status'] === 'completed') Rules::afterTxn((int)$res['txn_id']);
    return $res;
  }

  private static function sendInner(array $o, int $actor, ?string $key): array {
    $cur = $o['currency']; $amountC = (int)$o['amount_c']; $type = $o['type'];
    if ($amountC <= 0) throw new AppError('Amount must be greater than zero.');
    $from = Ledger::wallet((int)$o['from_user'], $cur); $to = Ledger::wallet((int)$o['to_user'], $cur);
    if ($from['id'] === $to['id']) throw new AppError('You cannot pay yourself.');
    $ids = [(int)$from['id'], (int)$to['id']]; sort($ids);
    $locked = rows('SELECT id, frozen, frozen_by FROM wallets WHERE id IN (?,?) ORDER BY id FOR UPDATE', $ids);
    $fr = array_column($locked, 'frozen', 'id');
    if ((int)$fr[(int)$from['id']] === 1) throw new AppError('This wallet is frozen. Unfreeze it in Security before sending money.');
    if ((int)$fr[(int)$to['id']] === 1) throw new AppError('The recipient wallet cannot receive payments right now.');
    $mode = $o['fee_mode'] ?? 'sender';
    $fee = $mode === 'none' ? 0 : ($mode === 'receiver' ? self::merchantFee($amountC) : self::fee($type, $amountC, $cur));
    $senderPays = $amountC + ($mode === 'sender' ? $fee : 0);
    $category = $o['category'] ?? 'general';
    if (empty($o['skip_limits'])) {
      if ($amountC > cents(setting("per_txn_limit_$cur"))) throw new AppError('Amount exceeds the per-transaction limit of ' . money(setting("per_txn_limit_$cur"), $cur) . '.');
      $today = cents((string)val("SELECT COALESCE(SUM(amount+fee),0) FROM transactions WHERE from_wallet_id=? AND status IN ('completed','held') AND type IN (" . self::OUT_TYPES . ") AND created_at>=CURDATE()", [$from['id']]));
      if ($today + $senderPays > cents(setting("daily_limit_$cur"))) throw new AppError('This would exceed your daily limit of ' . money(setting("daily_limit_$cur"), $cur) . '.');
    }
    // Guardian controls for dependent wallets
    $dep = row('SELECT * FROM dependents WHERE dependent_id=?', [$from['user_id']]);
    if ($dep && $cur === 'BDT' && empty($o['skip_limits'])) {
      if ($dep['status'] !== 'active') throw new AppError('Spending is paused by your guardian.');
      if ($amountC > cents($dep['per_txn_limit'])) throw new AppError('Over your guardian limit of ' . money($dep['per_txn_limit']) . ' per payment.');
      $dt = cents((string)val("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE from_wallet_id=? AND status IN ('completed','held') AND type IN (" . self::OUT_TYPES . ") AND created_at>=CURDATE()", [$from['id']]));
      if ($dt + $amountC > cents($dep['daily_limit'])) throw new AppError('Over your guardian daily limit of ' . money($dep['daily_limit']) . '.');
      foreach (rows('SELECT * FROM dependent_restrictions WHERE dependent_row_id=?', [$dep['id']]) as $r) {
        if ($r['restriction_type'] === 'category' && strcasecmp($r['value'], $category) === 0) throw new AppError('Payments in the "' . $category . '" category are blocked by your guardian.');
        if ($r['restriction_type'] === 'merchant' && !empty($o['merchant_name']) && strcasecmp($r['value'], $o['merchant_name']) === 0) throw new AppError('This merchant is blocked by your guardian.');
      }
    }
    // Multi-approver authorization
    $pol = row('SELECT * FROM approval_policies WHERE wallet_id=?', [$from['id']]);
    if ($pol && empty($o['approved']) && $amountC >= cents($pol['min_amount']) && !in_array($type, ['topup', 'conversion'], true)) {
      q('INSERT INTO approval_requests (wallet_id,requested_by,to_wallet_id,amount,category,note) VALUES (?,?,?,?,?,?)', [$from['id'], $actor, $to['id'], dec($amountC), $category, mb_substr((string)($o['note'] ?? ''), 0, 255)]);
      $rid = (int)db()->lastInsertId();
      timeline('approval', $rid, $actor, 'requested', money(dec($amountC), $cur) . ' needs ' . $pol['required_approvals'] . ' approval(s)');
      foreach (rows('SELECT user_id FROM approval_policy_approvers WHERE policy_id=?', [$pol['id']]) as $a) notify((int)$a['user_id'], 'security', 'Approval needed', money(dec($amountC), $cur) . ' payment is waiting for your decision.');
      if ($key) q('DELETE FROM idempotency_keys WHERE user_id=? AND idem_key=? AND action=?', [$actor, $key, $type]);
      return ['txn_id' => null, 'ref' => null, 'status' => 'approval_pending', 'approval_id' => $rid];
    }
    // Wrong-transfer protection: hold first-time or high-value recipients
    $hold = !empty($o['allow_hold']) && $type === 'transfer' && (self::isFirstTime((int)$from['id'], (int)$to['id']) || $amountC >= cents(setting("high_value_$cur")));
    $reason = $hold ? (self::isFirstTime((int)$from['id'], (int)$to['id']) ? 'first-time recipient' : 'high-value payment') : '';
    [$id, $ref] = Ledger::newTxn(['type' => $type, 'status' => $hold ? 'held' : 'completed', 'from_wallet' => $from['id'], 'to_wallet' => $to['id'], 'amount_c' => $amountC, 'fee_c' => $fee,
      'currency' => $cur, 'category' => $category, 'note' => $o['note'] ?? '', 'created_by' => $actor, 'ref_table' => $o['ref_table'] ?? null, 'ref_id' => $o['ref_id'] ?? null]);
    $feeAcc = Ledger::sys('fee', $cur);
    if ($hold) {
      Ledger::post($id, 'hold', [[(int)$from['account_id'], 'D', $senderPays, $cur], [Ledger::sys('hold', $cur), 'C', $senderPays, $cur]]);
      $win = (int)setting('cancel_window_seconds', '300');
      q('INSERT INTO transfer_holds (txn_id,release_at,reason) VALUES (?,?,?)', [$id, date('Y-m-d H:i:s', time() + $win), $reason]);
      timeline('transfer', $id, $actor, 'held', "Cancellable for {$win}s ($reason)");
    } else {
      $e = [[(int)$from['account_id'], 'D', $senderPays, $cur], [(int)$to['account_id'], 'C', $amountC - ($mode === 'receiver' ? $fee : 0), $cur]];
      if ($fee > 0) $e[] = [$feeAcc, 'C', $fee, $cur];
      Ledger::post($id, 'post', $e);
    }
    if ($key) q('UPDATE idempotency_keys SET txn_id=? WHERE user_id=? AND idem_key=? AND action=?', [$id, $actor, $key, $type]);
    audit($actor, $hold ? 'transfer_held' : $type, 'transaction', $id, dec($amountC) . " $cur");
    $fn = fn(int $uid) => (string)val('SELECT name FROM users WHERE id=?', [$uid]);
    notify((int)$from['user_id'], 'transaction', $hold ? 'Transfer on hold' : 'Payment sent', money(dec($amountC), $cur) . ' to ' . $fn((int)$to['user_id']) . ($hold ? ' - cancel within the window if this was a mistake.' : ''));
    if (!$hold) notify((int)$to['user_id'], 'transaction', 'Payment received', money(dec($amountC), $cur) . ' from ' . $fn((int)$from['user_id']));
    return ['txn_id' => $id, 'ref' => $ref, 'status' => $hold ? 'held' : 'completed', 'fee_c' => $fee, 'hold' => $hold];
  }

  /** Records risk alerts for the confirmed payment (called after a successful confirm). */
  static function recordRisk(int $uid, ?int $txnId, array $risk): void {
    foreach ($risk['flags'] as $f) if ($f['severity'] !== 'low') q('INSERT INTO risk_alerts (user_id,txn_id,alert_type,severity,message) VALUES (?,?,?,?,?)', [$uid, $txnId, $f['code'], $f['severity'], $f['message']]);
  }

  /** User cancels a held transfer inside the window. Safe against double clicks and concurrent calls. */
  static function cancelHold(int $uid, int $txnId): void {
    tx(function () use ($uid, $txnId) {
      $h = row('SELECT h.*, t.from_wallet_id, t.amount, t.fee, t.currency, t.to_wallet_id FROM transfer_holds h JOIN transactions t ON t.id=h.txn_id WHERE h.txn_id=? FOR UPDATE', [$txnId]);
      if (!$h) throw new AppError('Transfer not found.');
      $from = Ledger::walletById((int)$h['from_wallet_id']);
      if ((int)$from['user_id'] !== $uid) throw new AppError('Only the sender can cancel this transfer.');
      if ($h['status'] === 'cancelled') throw new AppError('This transfer was already cancelled. Nothing was refunded twice.');
      if ($h['status'] === 'released') throw new AppError('The cancellation window has closed and the transfer was released.');
      if (strtotime($h['release_at']) <= time()) throw new AppError('The cancellation window has closed.');
      $tot = cents($h['amount']) + cents($h['fee']); $cur = $h['currency'];
      Ledger::post($txnId, 'cancel', [[Ledger::sys('hold', $cur), 'D', $tot, $cur], [(int)$from['account_id'], 'C', $tot, $cur]]);
      q("UPDATE transfer_holds SET status='cancelled', resolved_at=NOW() WHERE id=?", [$h['id']]);
      q("UPDATE transactions SET status='cancelled' WHERE id=?", [$txnId]);
      timeline('transfer', $txnId, $uid, 'cancelled', 'Funds returned to sender in full, including fee');
      audit($uid, 'transfer_cancelled', 'transaction', $txnId);
      notify($uid, 'transaction', 'Transfer cancelled', money(dec($tot), $cur) . ' returned to your wallet.');
    });
  }
  /** Releases every hold whose window has elapsed. Idempotent; safe to run from page loads and cron. */
  static function processHolds(): int {
    $n = 0;
    foreach (rows("SELECT txn_id FROM transfer_holds WHERE status='held' AND release_at<=NOW() ORDER BY id LIMIT 50") as $r) {
      $n += tx(function () use ($r) {
        $h = row("SELECT h.*, t.from_wallet_id, t.to_wallet_id, t.amount, t.fee, t.currency FROM transfer_holds h JOIN transactions t ON t.id=h.txn_id WHERE h.txn_id=? FOR UPDATE", [$r['txn_id']]);
        if (!$h || $h['status'] !== 'held') return 0;
        $to = Ledger::walletById((int)$h['to_wallet_id']); $cur = $h['currency'];
        $amt = cents($h['amount']); $fee = cents($h['fee']);
        $e = [[Ledger::sys('hold', $cur), 'D', $amt + $fee, $cur], [(int)$to['account_id'], 'C', $amt, $cur]];
        if ($fee > 0) $e[] = [Ledger::sys('fee', $cur), 'C', $fee, $cur];
        Ledger::post((int)$h['txn_id'], 'release', $e);
        q("UPDATE transfer_holds SET status='released', resolved_at=NOW() WHERE id=?", [$h['id']]);
        q("UPDATE transactions SET status='completed', completed_at=NOW() WHERE id=?", [$h['txn_id']]);
        timeline('transfer', (int)$h['txn_id'], null, 'released', 'Cancellation window ended');
        notify((int)$to['user_id'], 'transaction', 'Payment received', money($h['amount'], $cur) . ' is now available.');
        return 1;
      });
    }
    return $n;
  }

  // ---- payment requests ----
  static function request(int $uid, string $ident, string $amount, string $cur, string $note): int {
    $payer = self::findUser($ident, $uid); $c = cents($amount);
    if ($c <= 0) throw new AppError('Amount must be greater than zero.');
    q('INSERT INTO payment_requests (requester_id,payer_id,amount,currency,note) VALUES (?,?,?,?,?)', [$uid, $payer['id'], dec($c), $cur, mb_substr($note, 0, 255)]);
    $id = (int)db()->lastInsertId();
    notify((int)$payer['id'], 'transaction', 'Payment request', (string)val('SELECT name FROM users WHERE id=?', [$uid]) . ' requested ' . money(dec($c), $cur));
    audit($uid, 'request_created', 'payment_request', $id);
    return $id;
  }
  static function payRequest(int $uid, int $reqId, string $idem): array {
    return tx(function () use ($uid, $reqId, $idem) {
      $r = row('SELECT * FROM payment_requests WHERE id=? FOR UPDATE', [$reqId]);
      if (!$r || (int)$r['payer_id'] !== $uid) throw new AppError('Request not found.');
      if ($r['status'] !== 'pending') throw new AppError('This request is already ' . $r['status'] . '.');
      $res = self::send(['from_user' => $uid, 'to_user' => (int)$r['requester_id'], 'currency' => $r['currency'], 'amount_c' => cents($r['amount']), 'type' => 'request_payment',
        'category' => 'transfer', 'note' => $r['note'], 'idem_key' => $idem, 'actor' => $uid, 'ref_table' => 'payment_requests', 'ref_id' => $reqId]);
      if ($res['status'] === 'approval_pending') return $res;
      q("UPDATE payment_requests SET status='paid', txn_id=? WHERE id=?", [$res['txn_id'], $reqId]);
      return $res;
    });
  }
  static function closeRequest(int $uid, int $reqId, string $to): void {
    $n = q("UPDATE payment_requests SET status=? WHERE id=? AND status='pending' AND " . ($to === 'declined' ? 'payer_id' : 'requester_id') . '=?', [$to, $reqId, $uid])->rowCount();
    if (!$n) throw new AppError('Request cannot be changed.');
  }

  // ---- merchant QR checkout ----
  static function createCheckout(int $merchantId, string $amount, string $note): array {
    $c = cents($amount); if ($c <= 0) throw new AppError('Amount must be greater than zero.');
    $ref = strtolower(bin2hex(random_bytes(8)));
    q('INSERT INTO checkout_sessions (merchant_id,ref,amount,note,expires_at) VALUES (?,?,?,?,?)', [$merchantId, $ref, dec($c), mb_substr($note, 0, 200), date('Y-m-d H:i:s', time() + 900)]);
    return ['id' => (int)db()->lastInsertId(), 'ref' => $ref];
  }
  static function payCheckout(int $uid, string $ref, string $idem): array {
    return tx(function () use ($uid, $ref, $idem) {
      $s = row('SELECT s.*, m.user_id AS m_user, m.business_name, m.category FROM checkout_sessions s JOIN merchants m ON m.id=s.merchant_id WHERE s.ref=? FOR UPDATE', [$ref]);
      if (!$s) throw new AppError('Checkout not found.');
      if ($s['status'] === 'paid') throw new AppError('This checkout was already paid.');
      if ($s['status'] !== 'open' || strtotime($s['expires_at']) < time()) { q("UPDATE checkout_sessions SET status='expired' WHERE id=? AND status='open'", [$s['id']]); throw new AppError('This checkout has expired. Ask the merchant for a new QR code.'); }
      if ((int)$s['m_user'] === $uid) throw new AppError('You cannot pay your own checkout.');
      $res = self::send(['from_user' => $uid, 'to_user' => (int)$s['m_user'], 'currency' => 'BDT', 'amount_c' => cents($s['amount']), 'type' => 'merchant_payment', 'fee_mode' => 'receiver',
        'category' => $s['category'], 'merchant_name' => $s['business_name'], 'note' => 'Checkout ' . $s['business_name'] . ($s['note'] ? ': ' . $s['note'] : ''), 'idem_key' => $idem, 'actor' => $uid, 'ref_table' => 'checkout_sessions', 'ref_id' => (int)$s['id']]);
      if ($res['status'] === 'completed') q("UPDATE checkout_sessions SET status='paid', paid_txn_id=? WHERE id=?", [$res['txn_id'], $s['id']]);
      return $res;
    });
  }

  // ---- refunds (admin dispute decision on a completed transaction) ----
  static function refund(int $adminId, int $txnId, string $note): array {
    return tx(function () use ($adminId, $txnId, $note) {
      $t = row('SELECT * FROM transactions WHERE id=? FOR UPDATE', [$txnId]);
      if (!$t) throw new AppError('Transaction not found.');
      if (val('SELECT id FROM transactions WHERE refund_of=?', [$txnId])) throw new AppError('This transaction was already refunded.');
      if (!in_array($t['status'], ['completed', 'disputed'], true)) throw new AppError('Only completed or disputed transactions can be refunded.');
      if (!in_array($t['type'], ['transfer', 'request_payment', 'merchant_payment', 'invoice_payment', 'settlement', 'iou_repayment', 'token_payment', 'offline_payment', 'rule_transfer'], true)) throw new AppError('This transaction type cannot be refunded here.');
      if (val('SELECT id FROM transactions WHERE refund_of=?', [$txnId])) throw new AppError('This transaction was already refunded.');
      $from = Ledger::walletById((int)$t['from_wallet_id']); $to = Ledger::walletById((int)$t['to_wallet_id']); $cur = $t['currency'];
      $amt = cents($t['amount']); $fee = cents($t['fee']);
      $recvFee = $t['type'] === 'merchant_payment' ? $fee : 0; $sendFee = $t['type'] === 'merchant_payment' ? 0 : $fee;
      [$id, $ref] = Ledger::newTxn(['type' => 'refund', 'status' => 'completed', 'from_wallet' => $to['id'], 'to_wallet' => $from['id'], 'amount_c' => $amt, 'fee_c' => 0, 'currency' => $cur, 'category' => 'refund',
        'note' => 'Refund of ' . $t['ref'] . ($note ? ': ' . $note : ''), 'created_by' => $adminId, 'refund_of' => $txnId]);
      $e = [[(int)$to['account_id'], 'D', $amt - $recvFee, $cur], [(int)$from['account_id'], 'C', $amt + $sendFee, $cur]];
      if ($fee > 0) $e[] = [Ledger::sys('fee', $cur), 'D', $fee, $cur];
      Ledger::post($id, 'post', $e);
      q("UPDATE transactions SET status='refunded' WHERE id=?", [$txnId]);
      audit($adminId, 'refund', 'transaction', $txnId, "refund txn $ref");
      notify((int)$from['user_id'], 'transaction', 'Refund received', money(dec($amt + $sendFee), $cur) . ' returned for ' . $t['ref']);
      notify((int)$to['user_id'], 'transaction', 'Payment refunded', money(dec($amt - $recvFee), $cur) . ' debited for ' . $t['ref']);
      return ['txn_id' => $id, 'ref' => $ref];
    });
  }

  /** Pay a recurring bill/subscription (simulated external payee). Flags duplicate expenses. */
  static function payBill(int $uid, int $itemId, string $idem, bool $override = false): array {
    try { return self::payBillTx($uid, $itemId, $idem, $override); }
    catch (DupExpense $d) {   // the alert is written AFTER the rollback so it persists
      q('INSERT INTO risk_alerts (user_id,txn_id,alert_type,severity,message) VALUES (?,?,?,?,?)', [$uid, $d->alertTxn, 'duplicate_expense', 'medium', mb_substr($d->getMessage(), 0, 255)]);
      throw new AppError($d->getMessage());
    }
  }
  private static function payBillTx(int $uid, int $itemId, string $idem, bool $override): array {
    return tx(function () use ($uid, $itemId, $idem, $override) {
      $it = row("SELECT * FROM recurring_items WHERE id=? AND user_id=? AND status='active' FOR UPDATE", [$itemId, $uid]);
      if (!$it || $it['kind'] === 'income') throw new AppError('Bill not found.');
      $w = Ledger::wallet($uid, $it['currency']); $c = cents($it['amount']);
      $dup = row("SELECT id, created_at FROM transactions WHERE from_wallet_id=? AND type='bill_payment' AND amount=? AND note LIKE ? AND status='completed' AND created_at>=DATE_SUB(NOW(), INTERVAL 5 DAY) LIMIT 1", [$w['id'], $it['amount'], '%' . $it['name'] . '%']);
      if ($dup && !$override) {
        $e = new DupExpense('Possible duplicate: you already paid "' . $it['name'] . '" for the same amount in the last 5 days. Tick "pay anyway" to continue.');
        $e->alertTxn = (int)$dup['id']; throw $e;
      }
      try { q('INSERT INTO idempotency_keys (user_id,idem_key,action) VALUES (?,?,?)', [$uid, $idem, 'bill_payment']); }
      catch (PDOException $e) { if ($e->getCode() === '23000') throw new AppError('This payment was already submitted.'); throw $e; }
      [$id, $ref] = Ledger::newTxn(['type' => 'bill_payment', 'status' => 'completed', 'from_wallet' => $w['id'], 'amount_c' => $c, 'currency' => $it['currency'], 'category' => $it['category'], 'note' => ucfirst($it['kind']) . ': ' . $it['name'], 'created_by' => $uid, 'ref_table' => 'recurring_items', 'ref_id' => $itemId]);
      Ledger::post($id, 'post', [[(int)$w['account_id'], 'D', $c, $it['currency']], [Ledger::sys('external', $it['currency']), 'C', $c, $it['currency']]]);
      q('UPDATE idempotency_keys SET txn_id=? WHERE user_id=? AND idem_key=? AND action=?', [$id, $uid, $idem, 'bill_payment']);
      $next = new DateTime($it['next_due']);
      $next->modify(['weekly' => '+1 week', 'monthly' => '+1 month', 'yearly' => '+1 year'][$it['frequency']]);
      q('UPDATE recurring_items SET next_due=? WHERE id=?', [$next->format('Y-m-d'), $itemId]);
      audit($uid, 'bill_paid', 'recurring_item', $itemId);
      return ['txn_id' => $id, 'ref' => $ref, 'status' => 'completed'];
    });
  }
}

final class Approvals {
  static function vote(int $uid, int $reqId, string $decision): string {
    $out = tx(function () use ($uid, $reqId, $decision) {
      $r = row('SELECT * FROM approval_requests WHERE id=? FOR UPDATE', [$reqId]);
      if (!$r || $r['status'] !== 'pending') throw new AppError('This request is no longer pending.');
      $pol = row('SELECT * FROM approval_policies WHERE wallet_id=?', [$r['wallet_id']]);
      if (!$pol || !val('SELECT 1 FROM approval_policy_approvers WHERE policy_id=? AND user_id=?', [$pol['id'], $uid])) throw new AppError('You are not an approver for this wallet.');
      if ((int)$r['requested_by'] === $uid && $pol['required_approvals'] > 1) throw new AppError('You cannot approve your own request.');
      try { q('INSERT INTO approval_votes (request_id,approver_id,decision) VALUES (?,?,?)', [$reqId, $uid, $decision]); }
      catch (PDOException $e) { throw new AppError('You have already voted on this request.'); }
      timeline('approval', $reqId, $uid, $decision);
      if ($decision === 'reject') { q("UPDATE approval_requests SET status='rejected' WHERE id=?", [$reqId]); notify((int)$r['requested_by'], 'security', 'Payment rejected', 'An approver rejected your payment request.'); return 'rejected'; }
      $n = (int)val("SELECT COUNT(*) FROM approval_votes WHERE request_id=? AND decision='approve'", [$reqId]);
      if ($n < (int)$pol['required_approvals']) return "waiting ($n/{$pol['required_approvals']})";
      $from = Ledger::walletById((int)$r['wallet_id']); $to = Ledger::walletById((int)$r['to_wallet_id']);
      try {
        $res = Pay::send(['from_user' => (int)$from['user_id'], 'to_user' => (int)$to['user_id'], 'currency' => $from['currency'], 'amount_c' => cents($r['amount']), 'type' => 'transfer', 'category' => $r['category'],
          'note' => $r['note'], 'idem_key' => 'APR' . $reqId, 'actor' => (int)$r['requested_by'], 'approved' => true, 'allow_hold' => false]);
        q("UPDATE approval_requests SET status='executed', txn_id=? WHERE id=?", [$res['txn_id'], $reqId]);
        timeline('approval', $reqId, null, 'executed', 'Required approvals reached');
        return 'executed';
      } catch (AppError $e) {
        q("UPDATE approval_requests SET status='failed' WHERE id=?", [$reqId]);
        notify((int)$r['requested_by'], 'security', 'Approved payment failed', $e->getMessage());
        return 'failed: ' . $e->getMessage();
      }
    });
    return $out;
  }
}

final class Pockets {
  static function create(int $uid, string $name, string $kind, string $target, ?string $date): int {
    if (!in_array($kind, ['education', 'travel', 'emergency', 'monthly_expense', 'custom'], true)) throw new AppError('Invalid pocket type.');
    $t = cents($target); if ($t <= 0) throw new AppError('Target must be greater than zero.');
    return tx(function () use ($uid, $name, $kind, $t, $date) {
      $acc = Ledger::newAccount('P-' . $uid . '-' . bin2hex(random_bytes(4)), 'pocket', 'BDT', "Pocket $name");
      q('INSERT INTO pockets (user_id,name,pocket_kind,target_amount,target_date,account_id) VALUES (?,?,?,?,?,?)', [$uid, mb_substr(trim($name), 0, 80) ?: 'Pocket', $kind, dec($t), $date ?: null, $acc]);
      return (int)db()->lastInsertId();
    });
  }
  static function move(int $uid, int $pocketId, int $amountC, string $dir, string $type = null, ?string $idem = null): array {
    if ($amountC <= 0) throw new AppError('Amount must be greater than zero.');
    return tx(function () use ($uid, $pocketId, $amountC, $dir, $type, $idem) {
      $p = row('SELECT * FROM pockets WHERE id=? AND user_id=?', [$pocketId, $uid]);
      if (!$p) throw new AppError('Pocket not found.');
      $w = Ledger::wallet($uid, 'BDT');
      if ($dir === 'in' && (int)$w['frozen'] === 1) throw new AppError('Wallet is frozen.');
      $type = $type ?: ($dir === 'in' ? 'pocket_deposit' : 'pocket_withdraw');
      [$id, $ref] = Ledger::newTxn(['type' => $type, 'status' => 'completed', 'from_wallet' => $dir === 'in' ? $w['id'] : null, 'to_wallet' => $dir === 'in' ? null : $w['id'], 'amount_c' => $amountC, 'currency' => 'BDT', 'category' => 'savings',
        'note' => ($dir === 'in' ? 'To pocket: ' : 'From pocket: ') . $p['name'] . ' (simulated pocket, not a bank deposit)', 'created_by' => $uid, 'ref_table' => 'pockets', 'ref_id' => $pocketId]);
      $e = $dir === 'in' ? [[(int)$w['account_id'], 'D', $amountC, 'BDT'], [(int)$p['account_id'], 'C', $amountC, 'BDT']] : [[(int)$p['account_id'], 'D', $amountC, 'BDT'], [(int)$w['account_id'], 'C', $amountC, 'BDT']];
      Ledger::post($id, 'post', $e);
      return ['txn_id' => $id, 'ref' => $ref];
    });
  }
}

final class Fx {
  static function rate(): array {
    $r = row("SELECT * FROM exchange_rates WHERE base_currency='USD' AND quote_currency='BDT' ORDER BY as_of DESC LIMIT 1");
    if (!$r) throw new AppError('No demonstration exchange rate configured.');
    return $r;
  }
  /** returns gross/fee/net in destination minor units. rate = BDT per 1 USD (SIMULATED). */
  static function quote(string $from, int $amountC): array {
    $r = self::rate(); $rate = (float)$r['rate'];
    $gross = $from === 'BDT' ? (int)floor($amountC / $rate) : (int)floor($amountC * $rate);
    $bps = (int)round((float)setting('fx_spread_percent', '0.5') * 100);
    $fee = intdiv($gross * $bps + 9999, 10000);
    return ['rate' => $rate, 'as_of' => $r['as_of'], 'source' => $r['source'], 'gross_c' => $gross, 'fee_c' => $fee, 'net_c' => $gross - $fee];
  }
  static function convert(int $uid, string $from, int $amountC, string $idem): array {
    if (!in_array($from, ['BDT', 'USD'], true)) throw new AppError('Unsupported currency.');
    $to = $from === 'BDT' ? 'USD' : 'BDT';
    return tx(function () use ($uid, $from, $to, $amountC, $idem) {
      $q = self::quote($from, $amountC);
      if ($q['net_c'] <= 0) throw new AppError('Amount is too small to convert.');
      try { q('INSERT INTO idempotency_keys (user_id,idem_key,action) VALUES (?,?,?)', [$uid, $idem, 'conversion']); }
      catch (PDOException $e) { if ($e->getCode() === '23000') throw new AppError('This conversion was already submitted.'); throw $e; }
      $wf = Ledger::wallet($uid, $from); $wt = Ledger::wallet($uid, $to);
      if ((int)$wf['frozen'] || (int)$wt['frozen']) throw new AppError('Wallet is frozen.');
      $note = sprintf('SIMULATED conversion %s -> %s at %s BDT/USD (demo rate dated %s), fee %s %s', $from, $to, number_format($q['rate'], 6), $q['as_of'], dec($q['fee_c']), $to);
      [$id, $ref] = Ledger::newTxn(['type' => 'conversion', 'status' => 'completed', 'from_wallet' => $wf['id'], 'to_wallet' => $wt['id'], 'amount_c' => $amountC, 'currency' => $from, 'category' => 'conversion', 'note' => $note, 'created_by' => $uid]);
      Ledger::post($id, 'post', [
        [(int)$wf['account_id'], 'D', $amountC, $from], [Ledger::sys('fx', $from), 'C', $amountC, $from],
        [Ledger::sys('fx', $to), 'D', $q['gross_c'], $to], [(int)$wt['account_id'], 'C', $q['net_c'], $to], [Ledger::sys('fee', $to), 'C', $q['fee_c'], $to]]);
      q('UPDATE idempotency_keys SET txn_id=? WHERE user_id=? AND idem_key=? AND action=?', [$id, $uid, $idem, 'conversion']);
      audit($uid, 'conversion', 'transaction', $id, $note);
      return ['txn_id' => $id, 'ref' => $ref, 'quote' => $q];
    });
  }
}
