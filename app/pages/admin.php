<?php
$uid = (int)$u['id']; $tab = in_array($_GET['tab'] ?? '', ['overview', 'users', 'disputes', 'risk', 'audit', 'settings'], true) ? $_GET['tab'] : 'overview';
handle('adm_user', function () use ($uid) { $id = (int)$_POST['id']; if ($id === $uid) throw new AppError('You cannot change your own status.'); $act = $_POST['act'];
  if ($act === 'suspend' || $act === 'activate') { q('UPDATE users SET status=? WHERE id=?', [$act === 'suspend' ? 'suspended' : 'active', $id]); }
  elseif ($act === 'freeze' || $act === 'unfreeze') { q('UPDATE wallets SET frozen=?, frozen_by=? WHERE user_id=?', [$act === 'freeze' ? 1 : 0, $act === 'freeze' ? 'admin' : 'none', $id]); }
  else throw new AppError('Unknown action.'); audit($uid, 'admin_' . $act, 'user', $id); notify($id, 'security', 'Account update', 'An administrator changed your account status: ' . $act . '.'); return 'Done.'; });
handle('adm_settings', function () use ($uid) { $changed = 0; foreach (rows('SELECT skey FROM settings') as $s) { $k = $s['skey']; if (!isset($_POST['s'][$k])) continue; $v = trim((string)$_POST['s'][$k]); if (!is_numeric($v) || (float)$v < 0) throw new AppError("Invalid value for $k."); if (str_contains($k, 'percent') && (float)$v > 10) throw new AppError("$k must be 10 or less."); if (setting($k) !== $v) { setting_set($k, $v); $changed++; audit($uid, 'setting_changed', 'settings', null, "$k=$v"); } } return "$changed setting(s) updated."; });
handle('adm_maint', function () { $r = run_maintenance(); return 'Maintenance ran: ' . json_encode($r); });
handle('adm_alert', function () { q("UPDATE risk_alerts SET status='reviewed' WHERE id=?", [(int)$_POST['id']]); return 'Reviewed.'; });
$tabs = '<div class="tabs">'; foreach (['overview' => 'Overview and reconciliation', 'users' => 'Users', 'disputes' => 'Disputes', 'risk' => 'Risk alerts', 'audit' => 'Audit log', 'settings' => 'Limits and fees'] as $k => $l) $tabs .= '<a href="' . e(url('admin', ['tab' => $k])) . '"' . ($tab === $k ? ' aria-current="page"' : '') . '>' . e($l) . '</a>'; $tabs .= '</div>';
echo page_head('Admin', 'Platform oversight. Every action here is written to the audit log.', form_start('adm_maint', 'class="inline"') . btn('Run maintenance', 'secondary sm') . '</form>') . $tabs;
if ($tab === 'overview') {
  $rec = Ledger::reconcile(); $rr = []; $allok = true; foreach ($rec as [$n, $ok, $d]) { $allok = $allok && $ok; $rr[] = [e($n), badge($ok ? 'ok' : 'failed'), e($d)]; }
  $sys = rows("SELECT code, name, currency, balance FROM ledger_accounts WHERE code LIKE 'SYS-%' ORDER BY kind, currency"); $sr = []; foreach ($sys as $s) $sr[] = [e($s['name']), '<span class="mono">' . e($s['code']) . '</span>', mny($s['balance'], $s['currency'])];
  $stats = row("SELECT (SELECT COUNT(*) FROM users) users, (SELECT COUNT(*) FROM transactions) txns, (SELECT COUNT(*) FROM disputes WHERE status IN ('open','under_review')) disputes, (SELECT COUNT(*) FROM transfer_holds WHERE status='held') holds");
  echo '<div class="grid g4" style="margin-bottom:16px">' . card('Users', '<div class="stat sm">' . (int)$stats['users'] . '</div>') . card('Transactions', '<div class="stat sm">' . (int)$stats['txns'] . '</div>') . card('Open disputes', '<div class="stat sm">' . (int)$stats['disputes'] . '</div>') . card('Active holds', '<div class="stat sm">' . (int)$stats['holds'] . '</div>') . '</div>' .
   '<div class="alert ' . ($allok ? 'ok' : 'err') . '">' . icon($allok ? 'check' : 'alert') . '<div><strong>' . ($allok ? 'All ledger invariants hold.' : 'Ledger invariant violation detected!') . '</strong> Computed live from ledger_entries just now.</div></div>' . card('Ledger reconciliation', tbl(['Invariant', 'Result', 'Detail'], $rr)) . '<div style="height:16px"></div>' . card('System ledger accounts', tbl(['Account', 'Code', ['Balance', 'num']], $sr));
} elseif ($tab === 'users') {
  $q = trim((string)($_GET['q'] ?? '')); $us = rows('SELECT u.*, r.name role, (SELECT COALESCE(SUM(a.balance),0) FROM wallets w JOIN ledger_accounts a ON a.id=w.account_id WHERE w.user_id=u.id AND w.currency="BDT") bdt, (SELECT MAX(frozen) FROM wallets WHERE user_id=u.id) fz FROM users u JOIN roles r ON r.id=u.role_id WHERE u.name LIKE ? OR u.email LIKE ? ORDER BY u.id LIMIT 100', ["%$q%", "%$q%"]); $rows = [];
  foreach ($us as $x) { $b = ''; if ((int)$x['id'] !== $uid) { foreach ([[$x['status'] === 'active' ? 'suspend' : 'activate', $x['status'] === 'active' ? 'Suspend' : 'Activate'], [$x['fz'] ? 'unfreeze' : 'freeze', $x['fz'] ? 'Unfreeze wallets' : 'Freeze wallets']] as [$a, $l]) $b .= form_start('adm_user', 'class="inline"') . '<input type="hidden" name="id" value="' . (int)$x['id'] . '"><input type="hidden" name="act" value="' . $a . '">' . btn($l, 'secondary sm') . '</form> '; }
    $rows[] = [e($x['name']) . '<div class="hint">' . e($x['email']) . '</div>', e($x['role']), mny($x['bdt']), badge($x['status']) . ($x['fz'] ? ' ' . badge('suspended') . ' frozen' : ''), $b]; }
  echo card('', '<form method="get" class="row" style="margin-bottom:12px"><input type="hidden" name="p" value="admin"><input type="hidden" name="tab" value="users">' . field('Search', 'q', 'search', $q) . '<div class="fixed field"><label>&nbsp;</label><button class="btn secondary">Search</button></div></form>' . tbl(['User', 'Role', ['BDT balance', 'num'], 'Status', 'Actions'], $rows));
} elseif ($tab === 'disputes') {
  $ds = rows('SELECT d.*, u.name FROM disputes d JOIN users u ON u.id=d.raised_by ORDER BY FIELD(d.status,"open","under_review","resolved","rejected"), d.id DESC LIMIT 60'); $rows = [];
  foreach ($ds as $d) $rows[] = ['<a href="' . e(url('disputes', ['id' => $d['id']])) . '"><strong>' . e($d['ref']) . '</strong></a>', e(mb_substr($d['reason'], 0, 80)), e($d['name']), e(fdate($d['created_at'], false)), badge($d['status'])];
  echo card('Dispute queue', tbl(['Ref', 'Reason', 'Raised by', 'Date', 'Status'], $rows, 'No disputes.'));
} elseif ($tab === 'risk') {
  $al = rows('SELECT a.*, u.name FROM risk_alerts a JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 60'); $rows = [];
  foreach ($al as $a) $rows[] = [e(fdate($a['created_at'])), e($a['name']), e($a['alert_type']), badge($a['severity']), e($a['message']), $a['status'] === 'open' ? form_start('adm_alert', 'class="inline"') . '<input type="hidden" name="id" value="' . (int)$a['id'] . '">' . btn('Reviewed', 'secondary sm') . '</form>' : badge('completed')];
  echo card('Risk alerts', tbl(['When', 'User', 'Type', 'Level', 'Message', ''], $rows, 'No alerts.'));
} elseif ($tab === 'audit') {
  $au = rows('SELECT a.*, u.name FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 100'); $rows = [];
  foreach ($au as $a) $rows[] = [e(fdate($a['created_at'])), e($a['name'] ?? 'system'), e($a['action']), e($a['entity'] . ($a['entity_id'] ? ' #' . $a['entity_id'] : '')), '<span class="hint">' . e($a['details']) . '</span>', '<span class="mono">' . e($a['ip_address']) . '</span>'];
  echo card('Latest 100 audit entries', tbl(['When', 'Who', 'Action', 'Entity', 'Details', 'IP'], $rows));
} else {
  $ss = rows('SELECT * FROM settings ORDER BY skey'); $f = ''; foreach ($ss as $s) $f .= field($s['description'], 's[' . $s['skey'] . ']', 'text', $s['svalue'], 'inputmode="decimal" required', $s['skey']);
  echo card('Limits, fees and windows', '<p class="muted">Changes apply immediately to new payments. Percent values are capped at 10.</p>' . form_start('adm_settings') . '<div class="grid g2">' . $f . '</div>' . btn('Save settings') . '</form>');
}
