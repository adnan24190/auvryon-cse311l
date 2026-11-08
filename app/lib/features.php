<?php
declare(strict_types=1);

final class Groups {
  static function isMember(int $gid, int $uid): bool { return (bool)val('SELECT 1 FROM group_members WHERE group_id=? AND user_id=?', [$gid, $uid]); }
  static function members(int $gid): array { return rows('SELECT u.id, u.name FROM group_members m JOIN users u ON u.id=m.user_id WHERE m.group_id=? ORDER BY u.name', [$gid]); }

  /** @return array<int,int> user_id => share in cents; always sums exactly to $amountC */
  static function computeSplit(string $type, int $amountC, array $participants, array $input): array {
    $participants = array_values(array_unique(array_map('intval', $participants)));
    if (!$participants) throw new AppError('Select at least one participant.');
    $out = [];
    if ($type === 'equal') {
      $n = count($participants); $base = intdiv($amountC, $n); $rem = $amountC % $n;
      foreach ($participants as $i => $u) $out[$u] = $base + ($i < $rem ? 1 : 0);
    } elseif ($type === 'custom') {
      foreach ($participants as $u) $out[$u] = cents($input[$u] ?? '0');
      if (array_sum($out) !== $amountC) throw new AppError('Custom amounts must add up to exactly ' . dec($amountC) . ' (currently ' . dec(array_sum($out)) . ').');
    } elseif ($type === 'percent') {
      $pct = []; foreach ($participants as $u) { $p = (string)($input[$u] ?? '0'); if (!is_numeric($p) || (float)$p < 0) throw new AppError('Invalid percentage.'); $pct[$u] = (float)$p; }
      if (abs(array_sum($pct) - 100) > 0.001) throw new AppError('Percentages must add up to 100% (currently ' . rtrim(rtrim(number_format(array_sum($pct), 3), '0'), '.') . '%).');
      $frac = []; $sum = 0;
      foreach ($pct as $u => $p) { $exact = $amountC * $p / 100; $out[$u] = (int)floor($exact + 1e-9); $frac[$u] = $exact - $out[$u]; $sum += $out[$u]; }
      arsort($frac); $left = $amountC - $sum;
      foreach (array_keys($frac) as $u) { if ($left <= 0) break; $out[$u]++; $left--; }
    } else throw new AppError('Unknown split type.');
    return $out;
  }
  static function addExpense(int $uid, int $gid, array $d): int {
    if (!self::isMember($gid, $uid)) throw new AppError('You are not a member of this group.');
    $amountC = cents($d['amount'] ?? ''); if ($amountC <= 0) throw new AppError('Amount must be greater than zero.');
    $desc = trim((string)($d['description'] ?? '')); if ($desc === '') throw new AppError('Add a description.');
    $paidBy = (int)($d['paid_by'] ?? $uid);
    $mem = array_column(self::members($gid), 'id');
    if (!in_array($paidBy, array_map('intval', $mem), true)) throw new AppError('Payer must be a group member.');
    $parts = array_map('intval', $d['participants'] ?? []);
    foreach ($parts as $p) if (!in_array($p, array_map('intval', $mem), true)) throw new AppError('Participants must be group members.');
    $type = (string)($d['split_type'] ?? 'equal');
    $split = self::computeSplit($type, $amountC, $parts, $type === 'percent' ? ($d['percent'] ?? []) : ($d['custom'] ?? []));
    return tx(function () use ($gid, $paidBy, $desc, $d, $amountC, $type, $split, $uid) {
      q('INSERT INTO group_expenses (group_id,paid_by,description,category,amount,split_type,expense_date) VALUES (?,?,?,?,?,?,?)',
        [$gid, $paidBy, mb_substr($desc, 0, 150), mb_substr((string)($d['category'] ?? 'general'), 0, 30) ?: 'general', dec($amountC), $type, preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($d['date'] ?? '')) ? $d['date'] : date('Y-m-d')]);
      $eid = (int)db()->lastInsertId();
      foreach ($split as $u => $c) q('INSERT INTO expense_splits (expense_id,user_id,share_amount,share_percent) VALUES (?,?,?,?)', [$eid, $u, dec($c), $type === 'percent' ? (float)$d['percent'][$u] : null]);
      audit($uid, 'group_expense', 'group_expense', $eid, dec($amountC));
      foreach ($split as $u => $c) if ($u !== $paidBy) notify($u, 'transaction', 'New group expense', $desc . ': your share is ' . money(dec($c)));
      return $eid;
    });
  }
  /** net position per member in cents: >0 is owed money, <0 owes money */
  static function net(int $gid): array {
    $net = []; foreach (self::members($gid) as $m) $net[(int)$m['id']] = 0;
    foreach (rows('SELECT paid_by u, SUM(amount) s FROM group_expenses WHERE group_id=? GROUP BY paid_by', [$gid]) as $r) $net[(int)$r['u']] = ($net[(int)$r['u']] ?? 0) + cents($r['s']);
    foreach (rows('SELECT s.user_id u, SUM(s.share_amount) s FROM expense_splits s JOIN group_expenses e ON e.id=s.expense_id WHERE e.group_id=? GROUP BY s.user_id', [$gid]) as $r) $net[(int)$r['u']] = ($net[(int)$r['u']] ?? 0) - cents($r['s']);
    foreach (rows('SELECT from_user f, to_user t, SUM(amount) s FROM group_settlements WHERE group_id=? GROUP BY from_user,to_user', [$gid]) as $r) {
      $net[(int)$r['f']] = ($net[(int)$r['f']] ?? 0) + cents($r['s']); $net[(int)$r['t']] = ($net[(int)$r['t']] ?? 0) - cents($r['s']);
    }
    return $net;
  }
  /**
   * Debt simplification. Minimising the number of transfers is NP-hard in general (equivalent to partitioning members
   * into the maximum number of zero-sum subsets). For <= 12 members with a non-zero balance we solve it EXACTLY with a
   * bitmask DP (transfers = members - zero-sum groups). Above that we fall back to a greedy matcher, which is only
   * guaranteed to need at most (members - 1) transfers.
   */
  static function optimize(array $net): array {
    $nz = []; foreach ($net as $u => $c) if ($c !== 0) $nz[] = [$u, $c];
    $n = count($nz); if ($n === 0) return ['transfers' => [], 'method' => 'none', 'bound' => 0];
    $groups = [];
    if ($n <= 12) {
      $full = (1 << $n) - 1; $sum = array_fill(0, $full + 1, 0); $dp = array_fill(0, $full + 1, 0);
      for ($m = 1; $m <= $full; $m++) {
        $low = $m & -$m; $i = (int)log($low, 2); $sum[$m] = $sum[$m ^ $low] + $nz[$i][1];
        $best = 0; for ($j = 0; $j < $n; $j++) if ($m & (1 << $j)) $best = max($best, $dp[$m ^ (1 << $j)]);
        $dp[$m] = $best + ($sum[$m] === 0 ? 1 : 0);
      }
      $mask = $full; $order = [];
      while ($mask) {
        $want = $dp[$mask] - ($sum[$mask] === 0 ? 1 : 0);
        for ($j = 0; $j < $n; $j++) if (($mask & (1 << $j)) && $dp[$mask ^ (1 << $j)] === $want) { $order[] = $j; $mask ^= (1 << $j); break; }
      }
      $order = array_reverse($order); $cur = []; $run = 0;
      foreach ($order as $j) { $cur[] = $nz[$j]; $run += $nz[$j][1]; if ($run === 0) { $groups[] = $cur; $cur = []; } }
      $method = 'exact (bitmask DP, minimum number of transfers)';
    } else { $groups = [$nz]; $method = 'greedy (at most members-1 transfers; not proven minimal)'; }
    $t = [];
    foreach ($groups as $g) {
      $cr = []; $db = [];
      foreach ($g as [$u, $c]) { if ($c > 0) $cr[] = [$u, $c]; else $db[] = [$u, -$c]; }
      while ($cr && $db) {
        usort($cr, fn($a, $b) => $b[1] <=> $a[1]); usort($db, fn($a, $b) => $b[1] <=> $a[1]);
        $amt = min($cr[0][1], $db[0][1]); $t[] = ['from' => $db[0][0], 'to' => $cr[0][0], 'amount_c' => $amt];
        $cr[0][1] -= $amt; $db[0][1] -= $amt;
        if ($cr[0][1] === 0) array_shift($cr); if ($db[0][1] === 0) array_shift($db);
      }
    }
    return ['transfers' => $t, 'method' => $method, 'bound' => $n - 1];
  }
  static function settle(int $uid, int $gid, int $to, int $amountC, string $idem): array {
    if (!self::isMember($gid, $uid) || !self::isMember($gid, $to)) throw new AppError('Both people must be group members.');
    return tx(function () use ($uid, $gid, $to, $amountC, $idem) {
      q('SELECT id FROM expense_groups WHERE id=? FOR UPDATE', [$gid]);
      $net = self::net($gid);
      if (($net[$uid] ?? 0) >= 0) throw new AppError('You do not owe anything in this group.');
      if ($amountC > -$net[$uid] || $amountC > ($net[$to] ?? 0)) throw new AppError('Amount is more than is owed to that member.');
      $res = Pay::send(['from_user' => $uid, 'to_user' => $to, 'currency' => 'BDT', 'amount_c' => $amountC, 'type' => 'settlement', 'fee_mode' => 'none', 'category' => 'groups',
        'note' => 'Group settlement: ' . (string)val('SELECT name FROM expense_groups WHERE id=?', [$gid]), 'idem_key' => $idem, 'actor' => $uid, 'ref_table' => 'expense_groups', 'ref_id' => $gid]);
      if ($res['status'] === 'completed' && empty($res['duplicate'])) q('INSERT INTO group_settlements (group_id,from_user,to_user,amount,txn_id) VALUES (?,?,?,?,?)', [$gid, $uid, $to, dec($amountC), $res['txn_id']]);
      return $res;
    });
  }
}

final class Ious {
  static function create(int $lender, string $ident, string $amount, string $due, string $note): int {
    $b = Pay::findUser($ident, $lender); $c = cents($amount);
    if ($c <= 0) throw new AppError('Amount must be greater than zero.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) throw new AppError('Choose a due date.');
    q('INSERT INTO ious (lender_id,borrower_id,amount,note,due_date) VALUES (?,?,?,?,?)', [$lender, $b['id'], dec($c), mb_substr($note, 0, 200), $due]);
    $id = (int)db()->lastInsertId();
    notify((int)$b['id'], 'reminder', 'New IOU recorded', 'You owe ' . money(dec($c)) . ' due ' . $due);
    return $id;
  }
  static function paid(int $id): int { return cents((string)val('SELECT COALESCE(SUM(amount),0) FROM iou_repayments WHERE iou_id=?', [$id])); }
  static function repay(int $uid, int $id, int $amountC, string $idem): array {
    return tx(function () use ($uid, $id, $amountC, $idem) {
      $i = row('SELECT * FROM ious WHERE id=? FOR UPDATE', [$id]);
      if (!$i || (int)$i['borrower_id'] !== $uid) throw new AppError('IOU not found.');
      if (in_array($i['status'], ['settled', 'cancelled'], true)) throw new AppError('This IOU is already ' . $i['status'] . '.');
      $left = cents($i['amount']) - self::paid($id);
      if ($amountC <= 0 || $amountC > $left) throw new AppError('Repayment must be between 0.01 and ' . dec($left) . '.');
      $res = Pay::send(['from_user' => $uid, 'to_user' => (int)$i['lender_id'], 'currency' => 'BDT', 'amount_c' => $amountC, 'type' => 'iou_repayment', 'fee_mode' => 'none', 'category' => 'transfer',
        'note' => 'IOU repayment' . ($i['note'] ? ': ' . $i['note'] : ''), 'idem_key' => $idem, 'actor' => $uid, 'ref_table' => 'ious', 'ref_id' => $id]);
      if ($res['status'] === 'completed' && empty($res['duplicate'])) {
        q('INSERT INTO iou_repayments (iou_id,amount,txn_id) VALUES (?,?,?)', [$id, dec($amountC), $res['txn_id']]);
        q('UPDATE ious SET status=? WHERE id=?', [$amountC === $left ? 'settled' : 'partial', $id]);
      }
      return $res;
    });
  }
  static function remind(int $lender, int $id): void {
    $i = row('SELECT * FROM ious WHERE id=? AND lender_id=?', [$id, $lender]);
    if (!$i || in_array($i['status'], ['settled', 'cancelled'], true)) throw new AppError('Nothing to remind.');
    notify((int)$i['borrower_id'], 'reminder', 'IOU reminder', 'Outstanding ' . money(dec(cents($i['amount']) - self::paid($id))) . ' due ' . $i['due_date']);
  }
}

final class Escrow {
  static function create(int $buyer, string $sellerIdent, string $title, string $cur, array $ms): int {
    $s = Pay::findUser($sellerIdent, $buyer);
    if (trim($title) === '') throw new AppError('Add a title.');
    $ms = array_values(array_filter($ms, fn($m) => trim((string)($m['title'] ?? '')) !== '' && trim((string)($m['amount'] ?? '')) !== ''));
    if (!$ms) throw new AppError('Add at least one milestone.');
    return tx(function () use ($buyer, $s, $title, $cur, $ms) {
      q('INSERT INTO escrows (ref,buyer_id,seller_id,title,currency) VALUES (?,?,?,?,?)', [new_ref('ES', 12), $buyer, $s['id'], mb_substr($title, 0, 150), $cur]);
      $id = (int)db()->lastInsertId();
      foreach ($ms as $i => $m) { $c = cents($m['amount']); if ($c <= 0) throw new AppError('Milestone amounts must be positive.');
        q('INSERT INTO escrow_milestones (escrow_id,seq,title,amount,due_date) VALUES (?,?,?,?,?)', [$id, $i + 1, mb_substr(trim($m['title']), 0, 150), dec($c), preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($m['due'] ?? '')) ? $m['due'] : null]); }
      timeline('escrow', $id, $buyer, 'created', count($ms) . ' milestone(s)');
      notify((int)$s['id'], 'transaction', 'New escrow contract', $title);
      return $id;
    });
  }
  private static function ms(int $id, string $for): array {
    $m = row('SELECT m.*, e.buyer_id, e.seller_id, e.currency, e.id AS eid, e.status AS estatus FROM escrow_milestones m JOIN escrows e ON e.id=m.escrow_id WHERE m.id=? FOR UPDATE', [$id]);
    if (!$m) throw new AppError('Milestone not found.');
    return $m;
  }
  private static function closeIfDone(int $eid): void {
    $open = (int)val("SELECT COUNT(*) FROM escrow_milestones WHERE escrow_id=? AND status NOT IN ('released','refunded')", [$eid]);
    if ($open === 0) q("UPDATE escrows SET status='completed' WHERE id=?", [$eid]);
  }
  static function fund(int $uid, int $msId, string $idem): array {
    return tx(function () use ($uid, $msId, $idem) {
      $m = self::ms($msId, 'fund');
      if ((int)$m['buyer_id'] !== $uid) throw new AppError('Only the buyer can fund a milestone.');
      if ($m['status'] !== 'pending') throw new AppError('This milestone is already ' . $m['status'] . '.');
      $cur = $m['currency']; $c = cents($m['amount']);
      $bw = Ledger::wallet($uid, $cur); $sw = Ledger::wallet((int)$m['seller_id'], $cur);
      if ((int)$bw['frozen']) throw new AppError('Your wallet is frozen.');
      [$id, $ref] = Ledger::newTxn(['type' => 'escrow_fund', 'status' => 'completed', 'from_wallet' => $bw['id'], 'to_wallet' => $sw['id'], 'amount_c' => $c, 'currency' => $cur, 'category' => 'escrow', 'note' => 'Escrow funding (held, not yet released): ' . $m['title'], 'created_by' => $uid, 'ref_table' => 'escrow_milestones', 'ref_id' => $msId]);
      Ledger::post($id, 'post', [[(int)$bw['account_id'], 'D', $c, $cur], [Ledger::sys('escrow', $cur), 'C', $c, $cur]]);
      q("UPDATE escrow_milestones SET status='funded', funded_txn_id=? WHERE id=?", [$id, $msId]);
      timeline('escrow', (int)$m['eid'], $uid, 'funded', $m['title']);
      notify((int)$m['seller_id'], 'transaction', 'Milestone funded', $m['title'] . ' - ' . money($m['amount'], $cur) . ' is held in escrow.');
      return ['txn_id' => $id, 'ref' => $ref];
    });
  }
  static function submit(int $uid, int $msId): void {
    tx(function () use ($uid, $msId) {
      $m = self::ms($msId, 'submit');
      if ((int)$m['seller_id'] !== $uid) throw new AppError('Only the seller can submit work.');
      if ($m['status'] !== 'funded') throw new AppError('Milestone must be funded first.');
      q("UPDATE escrow_milestones SET status='submitted' WHERE id=?", [$msId]);
      timeline('escrow', (int)$m['eid'], $uid, 'submitted', $m['title']);
      notify((int)$m['buyer_id'], 'transaction', 'Work submitted', $m['title'] . ' is ready for your review.');
    });
  }
  /** Close a funded milestone: 'release' pays the seller (minus fee), 'refund' returns funds to the buyer. */
  static function close(int $actor, int $msId, string $mode, bool $asAdmin = false): array {
    return tx(function () use ($actor, $msId, $mode, $asAdmin) {
      $m = self::ms($msId, $mode);
      if (!$asAdmin) {
        if ($mode === 'release' && (int)$m['buyer_id'] !== $actor) throw new AppError('Only the buyer can release funds.');
        if ($mode === 'refund' && (int)$m['seller_id'] !== $actor) throw new AppError('Only the seller can refund voluntarily. Buyers should open a dispute.');
        if ($m['status'] === 'disputed') throw new AppError('This milestone is under dispute. An administrator will decide.');
      }
      if (!in_array($m['status'], ['funded', 'submitted', 'disputed'], true)) throw new AppError('Funds are not held for this milestone (' . $m['status'] . ').');
      $cur = $m['currency']; $c = cents($m['amount']);
      $bw = Ledger::wallet((int)$m['buyer_id'], $cur); $sw = Ledger::wallet((int)$m['seller_id'], $cur);
      $fee = $mode === 'release' ? Pay::merchantFee($c) : 0;
      [$id, $ref] = Ledger::newTxn(['type' => $mode === 'release' ? 'escrow_release' : 'escrow_refund', 'status' => 'completed', 'from_wallet' => $mode === 'release' ? $bw['id'] : $sw['id'], 'to_wallet' => $mode === 'release' ? $sw['id'] : $bw['id'],
        'amount_c' => $c, 'fee_c' => $fee, 'currency' => $cur, 'category' => 'escrow', 'note' => ucfirst($mode) . ' of escrow milestone: ' . $m['title'], 'created_by' => $actor, 'ref_table' => 'escrow_milestones', 'ref_id' => $msId]);
      $e = [[Ledger::sys('escrow', $cur), 'D', $c, $cur]];
      if ($mode === 'release') { $e[] = [(int)$sw['account_id'], 'C', $c - $fee, $cur]; if ($fee > 0) $e[] = [Ledger::sys('fee', $cur), 'C', $fee, $cur]; }
      else $e[] = [(int)$bw['account_id'], 'C', $c, $cur];
      Ledger::post($id, 'post', $e);
      q('UPDATE escrow_milestones SET status=?, closing_txn_id=? WHERE id=?', [$mode === 'release' ? 'released' : 'refunded', $id, $msId]);
      q("UPDATE escrows SET status='open' WHERE id=? AND status='disputed'", [$m['eid']]);
      self::closeIfDone((int)$m['eid']);
      timeline('escrow', (int)$m['eid'], $actor, $mode === 'release' ? 'released' : 'refunded', $m['title']);
      notify((int)($mode === 'release' ? $m['seller_id'] : $m['buyer_id']), 'transaction', $mode === 'release' ? 'Escrow released' : 'Escrow refunded', $m['title']);
      audit($actor, 'escrow_' . $mode, 'escrow_milestone', $msId);
      return ['txn_id' => $id, 'ref' => $ref];
    });
  }
}

final class Disputes {
  static function open(int $uid, ?int $txnId, ?int $msId, string $reason): int {
    $reason = trim($reason); if (mb_strlen($reason) < 5) throw new AppError('Please describe the problem (at least 5 characters).');
    return tx(function () use ($uid, $txnId, $msId, $reason) {
      if ($msId) {
        $m = row('SELECT m.*, e.buyer_id, e.seller_id, e.id eid FROM escrow_milestones m JOIN escrows e ON e.id=m.escrow_id WHERE m.id=? FOR UPDATE', [$msId]);
        if (!$m || !in_array($uid, [(int)$m['buyer_id'], (int)$m['seller_id']], true)) throw new AppError('Milestone not found.');
        if (!in_array($m['status'], ['funded', 'submitted'], true)) throw new AppError('Only funded milestones can be disputed.');
        q("UPDATE escrow_milestones SET status='disputed' WHERE id=?", [$msId]); q("UPDATE escrows SET status='disputed' WHERE id=?", [$m['eid']]);
      } else {
        $t = row('SELECT t.*, f.user_id fu, r.user_id tu FROM transactions t LEFT JOIN wallets f ON f.id=t.from_wallet_id LEFT JOIN wallets r ON r.id=t.to_wallet_id WHERE t.id=?', [$txnId]);
        if (!$t || !in_array($uid, [(int)$t['fu'], (int)$t['tu']], true)) throw new AppError('Transaction not found.');
        if (val("SELECT 1 FROM disputes WHERE txn_id=? AND status IN ('open','under_review')", [$txnId])) throw new AppError('A dispute is already open for this transaction.');
      }
      q('INSERT INTO disputes (ref,raised_by,txn_id,milestone_id,reason) VALUES (?,?,?,?,?)', [new_ref('DP', 10), $uid, $txnId ?: null, $msId ?: null, mb_substr($reason, 0, 255)]);
      $id = (int)db()->lastInsertId();
      timeline('dispute', $id, $uid, 'opened', $reason);
      foreach (rows("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.name='admin'") as $a) notify((int)$a['id'], 'system', 'New dispute', $reason);
      audit($uid, 'dispute_opened', 'dispute', $id);
      return $id;
    });
  }
  static function evidence(int $uid, int $id, string $note): void {
    $d = row('SELECT d.*, e.buyer_id, e.seller_id, t.from_wallet_id, t.to_wallet_id FROM disputes d LEFT JOIN escrow_milestones m ON m.id=d.milestone_id LEFT JOIN escrows e ON e.id=m.escrow_id LEFT JOIN transactions t ON t.id=d.txn_id WHERE d.id=?', [$id]);
    if (!$d || !self::canSee($uid, $d)) throw new AppError('Dispute not found.');
    if (!in_array($d['status'], ['open', 'under_review'], true)) throw new AppError('This dispute is closed.');
    if (mb_strlen(trim($note)) < 3) throw new AppError('Write a short evidence note.');
    q('INSERT INTO dispute_evidence (dispute_id,user_id,note) VALUES (?,?,?)', [$id, $uid, mb_substr(trim($note), 0, 400)]);
    timeline('dispute', $id, $uid, 'evidence_added', mb_substr(trim($note), 0, 120));
  }
  static function canSee(int $uid, array $d): bool {
    if ((int)$d['raised_by'] === $uid) return true;
    foreach (['buyer_id', 'seller_id'] as $k) if (!empty($d[$k]) && (int)$d[$k] === $uid) return true;
    foreach (['from_wallet_id', 'to_wallet_id'] as $k) if (!empty($d[$k]) && (int)val('SELECT user_id FROM wallets WHERE id=?', [$d[$k]]) === $uid) return true;
    return false;
  }
  static function decide(int $admin, int $id, string $resolution, string $note): void {
    if (!in_array($resolution, ['refund', 'release', 'reject', 'review'], true)) throw new AppError('Invalid decision.');
    tx(function () use ($admin, $id, $resolution, $note) {
      $d = row('SELECT * FROM disputes WHERE id=? FOR UPDATE', [$id]);
      if (!$d || !in_array($d['status'], ['open', 'under_review'], true)) throw new AppError('This dispute is already closed.');
      if ($resolution === 'review') { q("UPDATE disputes SET status='under_review', admin_id=? WHERE id=?", [$admin, $id]); timeline('dispute', $id, $admin, 'under_review', $note); return; }
      if ($d['milestone_id']) {
        if ($resolution === 'refund') Escrow::close($admin, (int)$d['milestone_id'], 'refund', true);
        elseif ($resolution === 'release') Escrow::close($admin, (int)$d['milestone_id'], 'release', true);
        else { q("UPDATE escrow_milestones SET status='submitted' WHERE id=? AND status='disputed'", [$d['milestone_id']]); q("UPDATE escrows SET status='open' WHERE id=(SELECT escrow_id FROM escrow_milestones WHERE id=?)", [$d['milestone_id']]); }
      } else {
        if ($resolution === 'refund') Pay::refund($admin, (int)$d['txn_id'], 'dispute ' . $d['ref']);
        elseif ($resolution === 'release') throw new AppError('Release only applies to escrow disputes.');
      }
      q('UPDATE disputes SET status=?, resolution=?, admin_id=?, decision_note=?, resolved_at=NOW() WHERE id=?', [$resolution === 'reject' ? 'rejected' : 'resolved', $resolution === 'reject' ? 'none' : $resolution, $admin, mb_substr($note, 0, 255), $id]);
      timeline('dispute', $id, $admin, 'decision_' . $resolution, $note);
      notify((int)$d['raised_by'], 'system', 'Dispute ' . $d['ref'] . ' decided', ucfirst($resolution) . ($note ? ': ' . $note : ''));
      audit($admin, 'dispute_' . $resolution, 'dispute', $id, $note);
    });
  }
}

final class Invoices {
  static function create(int $issuer, array $d): int {
    $items = []; foreach ($d['items'] ?? [] as $it) { if (trim((string)($it['description'] ?? '')) === '') continue; $q = (float)($it['qty'] ?? 1); $p = cents($it['price'] ?? '0'); if ($q <= 0 || $p <= 0) throw new AppError('Quantity and price must be positive.'); $items[] = [mb_substr(trim($it['description']), 0, 150), $q, $p]; }
    if (!$items) throw new AppError('Add at least one line item.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($d['due'] ?? ''))) throw new AppError('Choose a due date.');
    $cust = null; $cname = trim((string)($d['customer_name'] ?? ''));
    if (trim((string)($d['customer'] ?? '')) !== '') { $c = Pay::findUser($d['customer'], $issuer); $cust = (int)$c['id']; $cname = $cname ?: $c['name']; }
    if ($cname === '') throw new AppError('Enter a customer name or an Auvryon email.');
    $cur = ($d['currency'] ?? 'BDT') === 'USD' ? 'USD' : 'BDT';
    return tx(function () use ($issuer, $items, $cust, $cname, $d, $cur) {
      q('INSERT INTO invoices (number,issuer_id,customer_id,customer_name,title,currency,due_date,link_token) VALUES (?,?,?,?,?,?,?,?)',
        [new_ref('INV-', 12), $issuer, $cust, $cname, mb_substr(trim((string)($d['title'] ?? 'Invoice')), 0, 150) ?: 'Invoice', $cur, $d['due'], bin2hex(random_bytes(16))]);
      $id = (int)db()->lastInsertId();
      foreach ($items as [$desc, $qty, $p]) q('INSERT INTO invoice_items (invoice_id,description,quantity,unit_price) VALUES (?,?,?,?)', [$id, $desc, $qty, dec($p)]);
      timeline('invoice', $id, $issuer, 'created');
      if ($cust) notify($cust, 'transaction', 'New invoice', (string)val('SELECT title FROM invoices WHERE id=?', [$id]));
      return $id;
    });
  }
  static function totals(int $id): array {
    $t = cents((string)val('SELECT COALESCE(SUM(ROUND(quantity*unit_price,2)),0) FROM invoice_items WHERE invoice_id=?', [$id]));
    $p = cents((string)val('SELECT COALESCE(SUM(amount),0) FROM invoice_payments WHERE invoice_id=?', [$id]));
    return ['total_c' => $t, 'paid_c' => $p, 'due_c' => max(0, $t - $p)];
  }
  static function pay(int $uid, string $token, int $amountC, string $idem): array {
    return tx(function () use ($uid, $token, $amountC, $idem) {
      $i = row('SELECT * FROM invoices WHERE link_token=? FOR UPDATE', [$token]);
      if (!$i) throw new AppError('Invoice not found.');
      if (in_array($i['status'], ['paid', 'cancelled', 'draft'], true)) throw new AppError('This invoice is ' . $i['status'] . ' and cannot be paid.');
      if ((int)$i['issuer_id'] === $uid) throw new AppError('You cannot pay your own invoice.');
      $t = self::totals((int)$i['id']);
      if ($amountC <= 0 || $amountC > $t['due_c']) throw new AppError('Enter an amount between 0.01 and ' . dec($t['due_c']) . '.');
      $res = Pay::send(['from_user' => $uid, 'to_user' => (int)$i['issuer_id'], 'currency' => $i['currency'], 'amount_c' => $amountC, 'type' => 'invoice_payment', 'fee_mode' => 'receiver', 'category' => 'invoices',
        'note' => 'Invoice ' . $i['number'] . ' - ' . $i['title'], 'idem_key' => $idem, 'actor' => $uid, 'ref_table' => 'invoices', 'ref_id' => (int)$i['id']]);
      if ($res['status'] === 'completed' && empty($res['duplicate'])) {
        q('INSERT INTO invoice_payments (invoice_id,txn_id,payer_id,amount) VALUES (?,?,?,?)', [$i['id'], $res['txn_id'], $uid, dec($amountC)]);
        $left = $t['due_c'] - $amountC;
        q('UPDATE invoices SET status=? WHERE id=?', [$left === 0 ? 'paid' : 'partial', $i['id']]);
        timeline('invoice', (int)$i['id'], $uid, $left === 0 ? 'paid_in_full' : 'partial_payment', money(dec($amountC), $i['currency']) . ' reconciled automatically');
        notify((int)$i['issuer_id'], 'transaction', 'Invoice payment', $i['number'] . ': ' . money(dec($amountC), $i['currency']) . ($left === 0 ? ' (paid in full)' : ' (partial)'));
      }
      return $res;
    });
  }
  static function markOverdue(): void { q("UPDATE invoices SET status='overdue' WHERE status IN ('sent','partial') AND due_date < CURDATE()"); }
  static function remind(int $issuer, int $id): void {
    $i = row('SELECT * FROM invoices WHERE id=? AND issuer_id=?', [$id, $issuer]);
    if (!$i || in_array($i['status'], ['paid', 'cancelled'], true)) throw new AppError('Nothing to remind.');
    if ($i['customer_id']) notify((int)$i['customer_id'], 'reminder', 'Invoice reminder', $i['number'] . ' is due ' . $i['due_date']);
    timeline('invoice', $id, $issuer, 'reminder_sent');
  }
}

final class Cash {
  static function occurrences(array $it, DateTime $from, DateTime $to): array {
    $d = new DateTime($it['next_due']); $out = [];
    $step = ['weekly' => '+1 week', 'monthly' => '+1 month', 'yearly' => '+1 year'][$it['frequency']];
    for ($i = 0; $i < 200 && $d <= $to; $i++) { if ($d >= $from) $out[] = $d->format('Y-m-d'); $d->modify($step); }
    return $out;
  }
  static function recentOutflowC(int $uid, int $days = 30): int {
    $w = Ledger::wallet($uid, 'BDT');
    return cents((string)val("SELECT COALESCE(SUM(amount+fee),0) FROM transactions WHERE from_wallet_id=? AND status IN ('completed','held') AND type IN ('transfer','request_payment','merchant_payment','token_payment','offline_payment','invoice_payment') AND created_at>=DATE_SUB(NOW(), INTERVAL ? DAY)", [$w['id'], $days]));
  }
  /** Explainable safe-to-spend (BDT wallet). Every component is returned so the UI can show the arithmetic. */
  static function safe(int $uid, int $horizon = 30): array {
    $w = Ledger::wallet($uid, 'BDT'); $bal = cents($w['balance']);
    $from = new DateTime('today'); $to = (clone $from)->modify("+$horizon days");
    $bills = []; $income = []; $billsC = 0; $incC = 0;
    foreach (rows("SELECT * FROM recurring_items WHERE user_id=? AND status='active' AND currency='BDT'", [$uid]) as $it) {
      foreach (self::occurrences($it, $from, $to) as $d) {
        $c = cents($it['amount']);
        if ($it['kind'] === 'income') { $income[] = ['name' => $it['name'], 'date' => $d, 'amount_c' => $c]; $incC += $c; }
        else { $bills[] = ['name' => $it['name'], 'date' => $d, 'amount_c' => $c]; $billsC += $c; }
      }
    }
    $savings = []; $savC = 0;
    foreach (rows('SELECT p.*, a.balance FROM pockets p JOIN ledger_accounts a ON a.id=p.account_id WHERE p.user_id=? AND p.target_date IS NOT NULL', [$uid]) as $p) {
      $rem = cents($p['target_amount']) - cents($p['balance']); if ($rem <= 0) continue;
      $days = max(1, (int)((strtotime($p['target_date']) - time()) / 86400)); $months = max(1.0, $days / 30);
      $need = (int)ceil($rem / $months); $savings[] = ['name' => $p['name'], 'amount_c' => $need]; $savC += $need;
    }
    $buffer = cents(setting('safety_buffer_BDT', '500'));
    $safe = $bal - $billsC - $savC - $buffer;                       // conservative: income not counted
    $safeWith = $safe + $incC;                                      // optimistic: counts known income due in the horizon
    return ['balance_c' => $bal, 'bills' => $bills, 'bills_c' => $billsC, 'income' => $income, 'income_c' => $incC, 'savings' => $savings, 'savings_c' => $savC,
            'buffer_c' => $buffer, 'safe_c' => $safe, 'safe_with_income_c' => $safeWith, 'daily_c' => $safe > 0 ? intdiv($safe, $horizon) : 0, 'horizon' => $horizon];
  }
  /** 30-day projection. Rule-based: known recurring items + average recent discretionary spending (stated assumption). */
  static function forecast(int $uid, int $days = 30): array {
    $w = Ledger::wallet($uid, 'BDT'); $bal = cents($w['balance']);
    $avgDaily = intdiv(self::recentOutflowC($uid, 30), 30);
    $from = new DateTime('tomorrow'); $to = new DateTime("today +$days days");
    $ev = [];
    foreach (rows("SELECT * FROM recurring_items WHERE user_id=? AND status='active' AND currency='BDT'", [$uid]) as $it)
      foreach (self::occurrences($it, new DateTime('today'), $to) as $d) $ev[$d][] = [$it['name'], $it['kind'] === 'income' ? cents($it['amount']) : -cents($it['amount'])];
    $series = []; $short = null; $b = $bal;
    for ($i = 0; $i <= $days; $i++) {
      $d = (new DateTime('today'))->modify("+$i days")->format('Y-m-d');
      if ($i > 0) $b -= $avgDaily;
      foreach ($ev[$d] ?? [] as [$n, $c]) $b += $c;
      $series[] = ['date' => $d, 'balance_c' => $b];
      if ($b < 0 && $short === null) $short = ['date' => $d, 'balance_c' => $b];
    }
    return ['series' => $series, 'avg_daily_c' => $avgDaily, 'start_c' => $bal, 'end_c' => $b, 'shortfall' => $short, 'min_c' => min(array_column($series, 'balance_c')),
      'assumptions' => ['Starting balance is your current BDT wallet balance.', 'Active income and bill items are applied on their due dates.', 'Other spending continues at your average of the last 30 days (' . money(dec($avgDaily)) . ' per day).', 'This is a rule-based estimate, not a prediction: real spending will differ.']];
  }
  static function spendByCategory(int $uid, int $days = 30): array {
    $w = Ledger::wallet($uid, 'BDT');
    return rows("SELECT category, SUM(amount+fee) total FROM transactions WHERE from_wallet_id=? AND status IN ('completed','held') AND type IN (" . Pay::OUT_TYPES . ") AND created_at>=DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY category ORDER BY total DESC", [$w['id'], $days]);
  }
  static function budgetStatus(int $uid): array {
    $w = Ledger::wallet($uid, 'BDT'); $out = [];
    foreach (rows('SELECT * FROM budgets WHERE user_id=? ORDER BY group_id, category', [$uid]) as $b) {
      $spent = $b['group_id'] > 0
        ? (string)val("SELECT COALESCE(SUM(amount),0) FROM group_expenses WHERE group_id=? AND category=? AND expense_date>=DATE_FORMAT(CURDATE(),'%Y-%m-01')", [$b['group_id'], $b['category']])
        : (string)val("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE from_wallet_id=? AND category=? AND status IN ('completed','held') AND type IN (" . Pay::OUT_TYPES . ") AND created_at>=DATE_FORMAT(CURDATE(),'%Y-%m-01')", [$w['id'], $b['category']]);
      $out[] = $b + ['spent' => $spent, 'group' => $b['group_id'] > 0 ? (string)val('SELECT name FROM expense_groups WHERE id=?', [$b['group_id']]) : null];
    }
    return $out;
  }
  static function insights(int $uid): array {
    $i = []; $s = self::safe($uid); $f = self::forecast($uid);
    if ($f['shortfall']) $i[] = ['warn', 'Projected shortfall on ' . $f['shortfall']['date'] . ' (' . money(dec($f['shortfall']['balance_c'])) . '). Consider moving a bill, pausing a subscription, or reducing spending.'];
    if ($s['safe_c'] < 0) $i[] = ['warn', 'Known bills and savings plans exceed your balance by ' . money(dec(-$s['safe_c'])) . ' over the next 30 days.'];
    foreach (self::budgetStatus($uid) as $b) if ((float)$b['monthly_limit'] > 0 && (float)$b['spent'] / (float)$b['monthly_limit'] >= 0.8) $i[] = [(float)$b['spent'] >= (float)$b['monthly_limit'] ? 'warn' : 'info', ucfirst($b['category']) . ' budget is at ' . round(100 * (float)$b['spent'] / (float)$b['monthly_limit']) . '% (' . money($b['spent']) . ' of ' . money($b['monthly_limit']) . ').'];
    $subs = (int)val("SELECT COUNT(*) FROM recurring_items WHERE user_id=? AND kind='subscription' AND status='active'", [$uid]);
    if ($subs >= 3) $i[] = ['info', "You have $subs active subscriptions. Review them in Recurring to cancel any you no longer use."];
    if (!$i) $i[] = ['ok', 'No warnings. Your known commitments are covered for the next 30 days.'];
    return $i;
  }
}

final class Tokens {
  static function create(int $uid, int $merchantId, string $perCharge, string $monthly): string {
    $m = row("SELECT * FROM merchants WHERE id=? AND status='active'", [$merchantId]); if (!$m) throw new AppError('Choose a merchant.');
    $pc = cents($perCharge); $mo = cents($monthly); if ($pc <= 0 || $mo < $pc) throw new AppError('Limits must be positive and the monthly limit must cover at least one charge.');
    $plain = 'avt_' . bin2hex(random_bytes(12));
    q('INSERT INTO virtual_tokens (user_id,wallet_id,merchant_id,token_hash,token_last4,per_charge_limit,monthly_limit) VALUES (?,?,?,?,?,?,?)', [$uid, Ledger::wallet($uid, 'BDT')['id'], $merchantId, hash('sha256', $plain), substr($plain, -4), dec($pc), dec($mo)]);
    audit($uid, 'token_created', 'virtual_token', (int)db()->lastInsertId());
    return $plain;
  }
  static function setStatus(int $uid, int $id, string $to): void {
    $cur = val('SELECT status FROM virtual_tokens WHERE id=? AND user_id=?', [$id, $uid]);
    if (!$cur || $cur === 'cancelled') throw new AppError('Token cannot be changed.');
    q('UPDATE virtual_tokens SET status=? WHERE id=?', [$to, $id]); audit($uid, 'token_' . $to, 'virtual_token', $id);
  }
  /** Simulated merchant-initiated charge against a virtual token (this is how ghost charges are blocked). */
  static function charge(int $merchantUid, string $plain, int $amountC, string $idem): array {
    $tk = row('SELECT t.*, m.user_id m_user, m.business_name, m.category FROM virtual_tokens t JOIN merchants m ON m.id=t.merchant_id WHERE t.token_hash=?', [hash('sha256', $plain)]);
    if (!$tk) throw new AppError('Unknown token.');
    if ((int)$tk['m_user'] !== $merchantUid) throw new AppError('This token belongs to a different merchant.');
    try {
      return tx(function () use ($tk, $amountC, $idem) {
        $t = row('SELECT * FROM virtual_tokens WHERE id=? FOR UPDATE', [$tk['id']]);
        if ($t['status'] !== 'active') throw new AppError('Token is ' . $t['status'] . '.');
        if ($amountC > cents($t['per_charge_limit'])) throw new AppError('Charge exceeds the per-charge limit of ' . money($t['per_charge_limit']) . '.');
        $used = cents((string)val("SELECT COALESCE(SUM(amount),0) FROM token_charges WHERE token_id=? AND status='approved' AND created_at>=DATE_FORMAT(CURDATE(),'%Y-%m-01')", [$t['id']]));
        if ($used + $amountC > cents($t['monthly_limit'])) throw new AppError('Charge exceeds the monthly limit of ' . money($t['monthly_limit']) . '.');
        $res = Pay::send(['from_user' => (int)$t['user_id'], 'to_user' => (int)$tk['m_user'], 'currency' => 'BDT', 'amount_c' => $amountC, 'type' => 'token_payment', 'fee_mode' => 'receiver', 'category' => $tk['category'],
          'merchant_name' => $tk['business_name'], 'note' => 'Virtual token ...' . $t['token_last4'] . ' charge by ' . $tk['business_name'], 'idem_key' => $idem, 'actor' => (int)$t['user_id']]);
        if ($res['status'] === 'approval_pending') throw new AppError('This wallet requires approval for payments of this size.');
        q("INSERT INTO token_charges (token_id,amount,status,txn_id) VALUES (?,?,'approved',?)", [$t['id'], dec($amountC), $res['txn_id']]);
        return ['status' => 'approved'] + $res;
      });
    } catch (AppError $e) {
      q("INSERT INTO token_charges (token_id,amount,status,reason) VALUES (?,?,'declined',?)", [$tk['id'], dec($amountC), mb_substr($e->getMessage(), 0, 120)]);
      q('INSERT INTO risk_alerts (user_id,alert_type,severity,message) VALUES (?,?,?,?)', [$tk['user_id'], 'ghost_charge_blocked', 'medium', $tk['business_name'] . ' charge of ' . dec($amountC) . ' declined: ' . $e->getMessage()]);
      notify((int)$tk['user_id'], 'security', 'Charge blocked', $tk['business_name'] . ' tried to charge ' . money(dec($amountC)) . ' on a token that refused it.');
      return ['status' => 'declined', 'reason' => $e->getMessage()];
    }
  }
}

final class Offline {
  private static function key(): string { return hash('sha256', (string)cfg('app_secret'), true); }
  static function encode(array $p): string {
    $iv = random_bytes(12); $tag = '';
    $c = openssl_encrypt(json_encode($p), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
    return rtrim(strtr(base64_encode($iv . $tag . $c), '+/', '-_'), '=');
  }
  static function decode(string $t): ?array {
    $b = base64_decode(strtr(trim($t), '-_', '+/'), true); if ($b === false || strlen($b) < 29) return null;
    $p = openssl_decrypt(substr($b, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($b, 0, 12), substr($b, 12, 16));
    $j = $p === false ? null : json_decode($p, true);
    return is_array($j) && isset($j['t'], $j['u'], $j['p'], $j['a']) ? $j : null;
  }
  /** Payer prepares a one-time token. Nothing moves yet: the payment is only settled when the merchant syncs. */
  static function issue(int $uid, string $payeeIdent, string $amount): array {
    $payee = Pay::findUser($payeeIdent, $uid); $c = cents($amount);
    if ($c <= 0 || $c > cents(setting('per_txn_limit_BDT'))) throw new AppError('Amount is outside the allowed range.');
    return tx(function () use ($uid, $payee, $c) {
      $w = Ledger::wallet($uid, 'BDT');
      q('SELECT id FROM wallets WHERE id=? FOR UPDATE', [$w['id']]);
      if ((int)$w['frozen']) throw new AppError('Wallet is frozen.');
      $out = cents((string)val("SELECT COALESCE(SUM(amount),0) FROM offline_tokens WHERE user_id=? AND status='issued' AND expires_at>NOW()", [$uid]));
      if ($out + $c > cents((string)val('SELECT balance FROM ledger_accounts WHERE id=?', [$w['account_id']]))) throw new AppError('Outstanding offline tokens plus this amount would exceed your balance.');
      $tid = bin2hex(random_bytes(16)); $exp = time() + (int)setting('offline_token_ttl_seconds', '900');
      q('INSERT INTO offline_tokens (token_id,user_id,payee_user_id,amount,expires_at) VALUES (?,?,?,?,?)', [$tid, $uid, $payee['id'], dec($c), date('Y-m-d H:i:s', $exp)]);
      return ['token' => self::encode(['t' => $tid, 'u' => $uid, 'p' => (int)$payee['id'], 'a' => dec($c), 'e' => $exp]), 'expires' => date('Y-m-d H:i:s', $exp), 'payee' => $payee['name']];
    });
  }
  static function queue(int $merchantUid, string $token): int {
    $p = self::decode($token); if (!$p) throw new AppError('Not a valid Auvryon offline token.');
    if ((int)$p['p'] !== $merchantUid) throw new AppError('This token was issued for a different payee.');
    q('INSERT INTO offline_queue (merchant_user_id,token_text) VALUES (?,?)', [$merchantUid, trim($token)]);
    return (int)db()->lastInsertId();
  }
  static function sync(int $merchantUid): array {
    $res = ['settled' => 0, 'rejected' => 0];
    foreach (rows("SELECT * FROM offline_queue WHERE merchant_user_id=? AND status='queued' ORDER BY id", [$merchantUid]) as $qrow) {
      $r = tx(function () use ($qrow) {                      // lock the queue row so parallel syncs cannot both act on it
        $cur = row('SELECT status FROM offline_queue WHERE id=? FOR UPDATE', [$qrow['id']]);
        if (!$cur || $cur['status'] !== 'queued') return null;
        [$st, $msg, $txn] = self::settleOne($qrow);
        q('UPDATE offline_queue SET status=?, message=?, txn_id=?, synced_at=NOW() WHERE id=?', [$st, $msg, $txn, $qrow['id']]);
        return $st;
      });
      if ($r) $res[$r]++;
    }
    return $res;
  }
  private static function settleOne(array $qrow): array {
    $p = self::decode($qrow['token_text']);
    if (!$p) return ['rejected', 'Rejected: token is invalid or has been tampered with', null];
    try {
      return tx(function () use ($p, $qrow) {
        $t = row('SELECT * FROM offline_tokens WHERE token_id=? FOR UPDATE', [$p['t']]);
        if (!$t || (int)$t['user_id'] !== (int)$p['u'] || (int)$t['payee_user_id'] !== (int)$qrow['merchant_user_id'] || cents($t['amount']) !== cents($p['a'])) return ['rejected', 'Rejected: token does not match any issued payment', null];
        if ($t['status'] !== 'issued') {
          q('INSERT INTO risk_alerts (user_id,alert_type,severity,message) VALUES (?,?,?,?)', [$t['user_id'], 'offline_replay', 'high', 'A previously used offline token was presented again and was refused.']);
          return ['rejected', 'Rejected: replay/duplicate - this one-time token is already ' . $t['status'], null];
        }
        if (strtotime($qrow['queued_at']) > strtotime($t['expires_at'])) { q("UPDATE offline_tokens SET status='expired' WHERE id=?", [$t['id']]); return ['rejected', 'Rejected: token had expired when it was scanned', null]; }
        try {
          $r = Pay::send(['from_user' => (int)$t['user_id'], 'to_user' => (int)$qrow['merchant_user_id'], 'currency' => 'BDT', 'amount_c' => cents($t['amount']), 'type' => 'offline_payment', 'fee_mode' => 'receiver',
            'category' => 'shopping', 'note' => 'Offline QR payment (synced)', 'idem_key' => 'OFF' . $t['token_id'], 'actor' => (int)$t['user_id']]);
        } catch (AppError $e) {
          q("UPDATE offline_tokens SET status='rejected' WHERE id=?", [$t['id']]);
          return ['rejected', 'Conflict: ' . $e->getMessage() . ' (nothing was charged)', null];
        }
        if ($r['status'] !== 'completed') { q("UPDATE offline_tokens SET status='rejected' WHERE id=?", [$t['id']]); return ['rejected', 'Conflict: payment needs approval, so it was not settled', null]; }
        q("UPDATE offline_tokens SET status='settled', txn_id=? WHERE id=?", [$r['txn_id'], $t['id']]);
        return ['settled', 'Settled after sync', $r['txn_id']];
      });
    } catch (Throwable $e) { return ['rejected', 'Rejected: ' . $e->getMessage(), null]; }
  }
}

final class Rules {
  static function create(int $uid, array $d): int {
    $type = $d['rule_type'] ?? ''; $name = trim((string)($d['name'] ?? '')) ?: ucfirst(str_replace('_', ' ', $type));
    $f = ['target_user_id' => null, 'target_pocket_id' => null, 'amount' => null, 'percent' => null, 'round_to' => null, 'threshold' => null, 'frequency' => null, 'next_run' => null];
    if ($type === 'scheduled_transfer') {
      $f['target_user_id'] = (int)Pay::findUser((string)($d['target'] ?? ''), $uid)['id']; $f['amount'] = dec(cents($d['amount'] ?? '0')); if (cents($f['amount']) <= 0) throw new AppError('Amount must be positive.');
      $f['frequency'] = in_array($d['frequency'] ?? '', ['daily', 'weekly', 'monthly'], true) ? $d['frequency'] : 'monthly';
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($d['next_run'] ?? ''))) throw new AppError('Choose the first run date.'); $f['next_run'] = $d['next_run'];
    } elseif ($type === 'income_allocation') {
      $f['target_pocket_id'] = self::pocket($uid, (int)($d['pocket'] ?? 0)); $p = (float)($d['percent'] ?? 0); if ($p <= 0 || $p > 100) throw new AppError('Percent must be between 1 and 100.'); $f['percent'] = $p;
    } elseif ($type === 'round_up') {
      $f['target_pocket_id'] = self::pocket($uid, (int)($d['pocket'] ?? 0)); $r = (int)($d['round_to'] ?? 10); if (!in_array($r, [5, 10, 50, 100], true)) throw new AppError('Round to 5, 10, 50 or 100.'); $f['round_to'] = $r;
    } elseif ($type === 'low_balance_alert') {
      $f['threshold'] = dec(cents($d['threshold'] ?? '0')); if (cents($f['threshold']) <= 0) throw new AppError('Threshold must be positive.');
    } else throw new AppError('Choose a rule type.');
    q('INSERT INTO money_rules (user_id,name,rule_type,target_user_id,target_pocket_id,amount,percent,round_to,threshold,frequency,next_run) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
      [$uid, mb_substr($name, 0, 100), $type, $f['target_user_id'], $f['target_pocket_id'], $f['amount'], $f['percent'], $f['round_to'], $f['threshold'], $f['frequency'], $f['next_run']]);
    return (int)db()->lastInsertId();
  }
  private static function pocket(int $uid, int $pid): int { if (!val('SELECT id FROM pockets WHERE id=? AND user_id=?', [$pid, $uid])) throw new AppError('Choose a pocket.'); return $pid; }
  private static function log(int $rid, string $key, string $status, string $msg, ?int $txn = null): bool {
    $n = q('INSERT IGNORE INTO rule_runs (rule_id,run_key,status,message,txn_id) VALUES (?,?,?,?,?)', [$rid, $key, $status, mb_substr($msg, 0, 200), $txn])->rowCount();
    if ($n) q('UPDATE money_rules SET last_message=? WHERE id=?', [mb_substr($msg, 0, 200), $rid]);
    return $n > 0;
  }
  /** Event rules (income allocation, round-up, low-balance alert). Idempotent per (rule, run_key). */
  static function afterTxn(int $txnId): void {
    try {
      $t = row('SELECT t.*, fw.user_id fu, tw.user_id tu FROM transactions t LEFT JOIN wallets fw ON fw.id=t.from_wallet_id LEFT JOIN wallets tw ON tw.id=t.to_wallet_id WHERE t.id=?', [$txnId]);
      if (!$t || $t['status'] !== 'completed' || $t['currency'] !== 'BDT') return;
      $inbound = ['topup', 'transfer', 'request_payment', 'invoice_payment', 'escrow_release', 'merchant_payment', 'token_payment', 'offline_payment'];
      if ($t['tu'] && in_array($t['type'], $inbound, true))
        foreach (rows("SELECT * FROM money_rules WHERE user_id=? AND rule_type='income_allocation' AND status='active'", [$t['tu']]) as $r) {
          $c = (int)floor(cents($t['amount']) * (float)$r['percent'] / 100); if ($c <= 0) continue;
          try { tx(function () use ($r, $txnId, $c) {
            if (!self::log((int)$r['id'], "txn:$txnId", 'success', 'Allocated ' . dec($c) . " ({$r['percent']}%) to pocket")) return;
            $m = Pockets::move((int)$r['user_id'], (int)$r['target_pocket_id'], $c, 'in', 'pocket_deposit');
            q('UPDATE rule_runs SET txn_id=? WHERE rule_id=? AND run_key=?', [$m['txn_id'], $r['id'], "txn:$txnId"]);
          }); } catch (Throwable $e) { self::log((int)$r['id'], "txn:$txnId", 'failed', $e->getMessage()); }
        }
      if ($t['fu'] && in_array($t['type'], ['merchant_payment', 'token_payment', 'offline_payment', 'bill_payment'], true)) {
        foreach (rows("SELECT * FROM money_rules WHERE user_id=? AND rule_type='round_up' AND status='active'", [$t['fu']]) as $r) {
          $amt = cents($t['amount']); $step = (int)$r['round_to'] * 100; $diff = (int)(ceil($amt / $step) * $step) - $amt; if ($diff <= 0) { self::log((int)$r['id'], "txn:$txnId", 'skipped', 'Already a round amount'); continue; }
          try { tx(function () use ($r, $txnId, $diff) {
            if (!self::log((int)$r['id'], "txn:$txnId", 'success', 'Rounded up ' . dec($diff) . ' to pocket')) return;
            $m = Pockets::move((int)$r['user_id'], (int)$r['target_pocket_id'], $diff, 'in', 'round_up');
            q('UPDATE rule_runs SET txn_id=? WHERE rule_id=? AND run_key=?', [$m['txn_id'], $r['id'], "txn:$txnId"]);
          }); } catch (Throwable $e) { self::log((int)$r['id'], "txn:$txnId", 'skipped', 'Skipped: ' . $e->getMessage()); }
        }
      }
      if ($t['fu']) {
        $bal = Ledger::wallet((int)$t['fu'], 'BDT')['balance'];
        foreach (rows("SELECT * FROM money_rules WHERE user_id=? AND rule_type='low_balance_alert' AND status='active'", [$t['fu']]) as $r)
          if ((float)$bal < (float)$r['threshold'] && self::log((int)$r['id'], date('Y-m-d') . ':low', 'success', 'Alert sent: balance ' . $bal)) notify((int)$t['fu'], 'security', 'Low balance', 'Your BDT balance is ' . money($bal) . ', below your alert level of ' . money($r['threshold']) . '.');
      }
    } catch (Throwable $e) { error_log('Rules::afterTxn ' . $e->getMessage()); }
  }
  /** Scheduled transfers. run_key = scheduled date, so running twice for the same date never pays twice. */
  static function runDue(?int $onlyUser = null): array {
    $out = ['ok' => 0, 'failed' => 0, 'skipped' => 0];
    $sql = "SELECT * FROM money_rules WHERE rule_type='scheduled_transfer' AND status='active' AND next_run<=CURDATE()" . ($onlyUser ? ' AND user_id=' . (int)$onlyUser : '');
    foreach (rows($sql) as $r) {
      $key = $r['next_run'];
      if (val('SELECT 1 FROM rule_runs WHERE rule_id=? AND run_key=?', [$r['id'], $key])) { $out['skipped']++; continue; }
      try {
        $did = tx(function () use ($r, $key) {
          $cur = row("SELECT next_run, status FROM money_rules WHERE id=? FOR UPDATE", [$r['id']]);      // serialises parallel runners
          if (!$cur || $cur['status'] !== 'active' || $cur['next_run'] !== $key || val('SELECT 1 FROM rule_runs WHERE rule_id=? AND run_key=?', [$r['id'], $key])) return false;
          $res = Pay::send(['from_user' => (int)$r['user_id'], 'to_user' => (int)$r['target_user_id'], 'currency' => 'BDT', 'amount_c' => cents($r['amount']), 'type' => 'rule_transfer', 'fee_mode' => 'none',
            'category' => 'transfer', 'note' => 'Rule: ' . $r['name'], 'idem_key' => 'RULE' . $r['id'] . '-' . $key, 'actor' => (int)$r['user_id']]);
          if ($res['status'] === 'approval_pending') throw new AppError('Payment needs approval; not executed automatically.');
          q('INSERT INTO rule_runs (rule_id,run_key,status,message,txn_id) VALUES (?,?,?,?,?)', [$r['id'], $key, 'success', 'Transferred ' . $r['amount'], $res['txn_id']]);
          $d = new DateTime($r['next_run']); $d->modify(['daily' => '+1 day', 'weekly' => '+1 week', 'monthly' => '+1 month'][$r['frequency']]);
          q("UPDATE money_rules SET next_run=?, fail_count=0, last_message=? WHERE id=?", [$d->format('Y-m-d'), 'Last run OK ' . $key, $r['id']]);
          return true;
        });
        $out[$did ? 'ok' : 'skipped']++;
      } catch (Throwable $e) {
        $f = (int)$r['fail_count'] + 1;
        q('INSERT IGNORE INTO rule_runs (rule_id,run_key,status,message) VALUES (?,?,?,?)', [$r['id'], $key . '#f' . $f, 'failed', mb_substr($e->getMessage(), 0, 200)]);
        q('UPDATE money_rules SET fail_count=?, last_message=?, status=? WHERE id=?', [$f, 'Failed: ' . mb_substr($e->getMessage(), 0, 150), $f >= 3 ? 'failed' : 'active', $r['id']]);
        notify((int)$r['user_id'], 'security', 'Money rule failed', $r['name'] . ': ' . $e->getMessage());
        $out['failed']++;
      }
    }
    return $out;
  }
  static function reminders(): int {
    $n = 0;
    foreach (rows("SELECT * FROM recurring_items WHERE status='active' AND kind<>'income' AND next_due<=DATE_ADD(CURDATE(), INTERVAL 3 DAY)") as $r)
      if (q('INSERT IGNORE INTO reminder_log (user_id,ref_table,ref_id,due_date) VALUES (?,?,?,?)', [$r['user_id'], 'recurring_items', $r['id'], $r['next_due']])->rowCount()) { notify((int)$r['user_id'], 'reminder', ucfirst($r['kind']) . ' due ' . $r['next_due'], $r['name'] . ': ' . money($r['amount'], $r['currency'])); $n++; }
    foreach (rows("SELECT * FROM ious WHERE status IN ('open','partial') AND due_date<=DATE_ADD(CURDATE(), INTERVAL 2 DAY)") as $r)
      if (q('INSERT IGNORE INTO reminder_log (user_id,ref_table,ref_id,due_date) VALUES (?,?,?,?)', [$r['borrower_id'], 'ious', $r['id'], $r['due_date']])->rowCount()) { notify((int)$r['borrower_id'], 'reminder', 'IOU due ' . $r['due_date'], 'You owe ' . money($r['amount'])); $n++; }
    foreach (rows("SELECT * FROM invoices WHERE status IN ('sent','partial','overdue') AND customer_id IS NOT NULL AND due_date<=DATE_ADD(CURDATE(), INTERVAL 2 DAY)") as $r)
      if (q('INSERT IGNORE INTO reminder_log (user_id,ref_table,ref_id,due_date) VALUES (?,?,?,?)', [$r['customer_id'], 'invoices', $r['id'], $r['due_date']])->rowCount()) { notify((int)$r['customer_id'], 'reminder', 'Invoice ' . $r['number'] . ' due ' . $r['due_date'], $r['title']); $n++; }
    return $n;
  }
}

function run_maintenance(): array {
  return ['holds_released' => Pay::processHolds(), 'rules' => Rules::runDue(), 'reminders' => Rules::reminders(), 'overdue' => (function () { Invoices::markOverdue(); return 'ok'; })()];
}
