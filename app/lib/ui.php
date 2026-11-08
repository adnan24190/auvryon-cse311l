<?php
declare(strict_types=1);

function icon(string $n): string {
  static $p = [
    'home' => 'M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z M9 22V12h6v10', 'send' => 'M22 2L11 13 M22 2l-7 20-4-9-9-4 20-7z',
    'wallet' => 'M20 12V8H6a2 2 0 0 1 0-4h12v4 M4 6v12a2 2 0 0 0 2 2h14v-4 M18 12a2 2 0 0 0 0 4h4v-4z', 'activity' => 'M22 12h-4l-3 9L9 3l-3 9H2',
    'users' => 'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2 M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8 M23 21v-2a4 4 0 0 0-3-3.87 M16 3.13a4 4 0 0 1 0 7.75',
    'calendar' => 'M3 4h18v18H3z M16 2v4 M8 2v4 M3 10h18', 'shield' => 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z',
    'target' => 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20 M12 18a6 6 0 1 0 0-12 6 6 0 0 0 0 12 M12 14a2 2 0 1 0 0-4 2 2 0 0 0 0 4',
    'store' => 'M3 9l1-5h16l1 5 M3 9a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0 M5 12v9h14v-9', 'file' => 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z M14 2v6h6 M8 13h8 M8 17h8',
    'scale' => 'M12 3v18 M5 21h14 M4 7h16 M4 7l-2 7a3 3 0 0 0 4 0z M20 7l-2 7a3 3 0 0 0 4 0z', 'bell' => 'M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9 M13.7 21a2 2 0 0 1-3.4 0',
    'settings' => 'M4 21v-7 M4 10V3 M12 21v-9 M12 8V3 M20 21v-5 M20 12V3 M1 14h6 M9 8h6 M17 16h6', 'qr' => 'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h3v3h-3z M20 14v3 M14 20h3 M20 20h1',
    'lock' => 'M5 11h14v10H5z M8 11V7a4 4 0 0 1 8 0v4', 'trend' => 'M23 6l-9.5 9.5-5-5L1 18 M17 6h6v6', 'plus' => 'M12 5v14 M5 12h14',
    'repeat' => 'M17 1l4 4-4 4 M3 11V9a4 4 0 0 1 4-4h14 M7 23l-4-4 4-4 M21 13v2a4 4 0 0 1-4 4H3', 'card' => 'M1 4h22v16H1z M1 10h22', 'logout' => 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4 M16 17l5-5-5-5 M21 12H9',
    'globe' => 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20 M2 12h20 M12 2a15 15 0 0 1 0 20 M12 2a15 15 0 0 0 0 20', 'inbox' => 'M22 12h-6l-2 3h-4l-2-3H2 M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z',
    'menu' => 'M3 6h18 M3 12h18 M3 18h18', 'more' => 'M5 12h.01 M12 12h.01 M19 12h.01', 'check' => 'M20 6L9 17l-5-5', 'alert' => 'M12 9v4 M12 17h.01 M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z',
    'receipt' => 'M4 2v20l3-2 3 2 3-2 3 2 3-2V2z M8 7h8 M8 11h8', 'user' => 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2 M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8', 'clock' => 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20 M12 6v6l4 2',
  ];
  return '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="' . ($p[$n] ?? $p['more']) . '"/></svg>';
}
function badge(string $s): string { return '<span class="badge b-' . e($s) . '">' . e(status_label($s)) . '</span>'; }
function flash(string $type, string $msg): void { $_SESSION['flash'][] = [$type, $msg]; }
function flashes(): string { $o = ''; foreach ($_SESSION['flash'] ?? [] as [$t, $m]) $o .= '<div class="alert ' . e($t) . '" role="' . ($t === 'err' ? 'alert' : 'status') . '">' . e($m) . '</div>'; unset($_SESSION['flash']); return $o; }
function redirect(string $to): never { header('Location: ' . $to); exit; }
function url(string $p, array $q = []): string { return 'index.php?' . http_build_query(['p' => $p] + $q); }
function self_url(): string { return 'index.php?' . http_build_query($_GET); }
/** POST handler with CSRF, PRG and safe error handling. Return a string to flash a success message. */
function handle(string $action, callable $fn, ?string $to = null): void {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_POST['_a'] ?? '') !== $action) return;
  try { csrf_check(); $m = $fn(); if (is_string($m) && $m !== '') flash('ok', $m); }
  catch (AppError $e) { flash('err', $e->getMessage()); }
  catch (Throwable $e) { error_log('Auvryon: ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine()); flash('err', cfg('debug') ? $e->getMessage() : 'Something went wrong. No money was moved. Please try again.'); }
  redirect($to ?? self_url());
}
function form_start(string $action, string $extra = '', bool $get = false): string {
  return '<form method="post" ' . $extra . ' data-once>' . csrf_field() . '<input type="hidden" name="_a" value="' . e($action) . '"><input type="hidden" name="idem" value="' . e(bin2hex(random_bytes(12))) . '">';
}
function idem_key(): string { $k = (string)($_POST['idem'] ?? ''); if (!preg_match('/^[a-f0-9]{16,64}$/', $k)) throw new AppError('Form expired. Please reload and try again.'); return $k; }
function field(string $label, string $name, string $type = 'text', string $value = '', string $attrs = '', string $hint = ''): string {
  $id = 'f_' . preg_replace('/\W/', '_', $name);
  return '<div class="field"><label for="' . $id . '">' . e(t($label)) . '</label><input id="' . $id . '" name="' . e($name) . '" type="' . e($type) . '" value="' . e($value) . '" ' . $attrs . '>' . ($hint ? '<div class="hint">' . e($hint) . '</div>' : '') . '</div>';
}
function select(string $label, string $name, array $opts, string $sel = '', string $attrs = ''): string {
  $id = 'f_' . preg_replace('/\W/', '_', $name); $o = '';
  foreach ($opts as $v => $l) $o .= '<option value="' . e((string)$v) . '"' . ((string)$v === $sel ? ' selected' : '') . '>' . e(t((string)$l)) . '</option>';
  return '<div class="field"><label for="' . $id . '">' . e(t($label)) . '</label><select id="' . $id . '" name="' . e($name) . '" ' . $attrs . '>' . $o . '</select></div>';
}
function btn(string $label, string $cls = '', string $type = 'submit', string $attrs = ''): string { return '<button type="' . $type . '" class="btn ' . $cls . '" ' . $attrs . '>' . e(t($label)) . '</button>'; }
function pin_field(): string { return field('Transaction PIN', 'pin', 'password', '', 'inputmode="numeric" pattern="\d{4,6}" maxlength="6" autocomplete="off" required', 'Your 4-6 digit PIN. Demo PIN: 1234'); }
function card(string $title, string $body, string $actions = '', string $cls = ''): string {
  return '<section class="card ' . $cls . '">' . ($title !== '' ? '<div class="card-h"><h2>' . e(t($title)) . '</h2><div>' . $actions . '</div></div>' : '') . $body . '</section>';
}
function page_head(string $title, string $sub = '', string $actions = ''): string { return '<div class="page-h"><div><h1>' . e(t($title)) . '</h1>' . ($sub ? '<p>' . e(t($sub)) . '</p>' : '') . '</div><div>' . $actions . '</div></div>'; }
function empty_state(string $msg, string $ic = 'inbox'): string { return '<div class="empty">' . str_replace('class="ico"', '', icon($ic)) . '<div>' . e(t($msg)) . '</div></div>'; }
/** headers: ['Label', ['Amount','num']]; rows: arrays of ALREADY-ESCAPED html */
function tbl(array $headers, array $rows, string $empty = 'Nothing here yet'): string {
  if (!$rows) return empty_state($empty);
  $h = ''; $nums = [];
  foreach ($headers as $i => $x) { $lab = is_array($x) ? $x[0] : $x; $num = is_array($x) && ($x[1] ?? '') === 'num'; $nums[$i] = $num; $h .= '<th' . ($num ? ' class="num"' : '') . '>' . e(t($lab)) . '</th>'; }
  $b = ''; foreach ($rows as $r) { $b .= '<tr>'; foreach (array_values($r) as $i => $c) $b .= '<td' . (!empty($nums[$i]) ? ' class="num"' : '') . '>' . $c . '</td>'; $b .= '</tr>'; }
  return '<div class="table-wrap"><table><thead><tr>' . $h . '</tr></thead><tbody>' . $b . '</tbody></table></div>';
}
function progress(float $pct, string $cls = ''): string { $pct = max(0, min(100, $pct)); return '<div class="progress ' . $cls . '" role="progressbar" aria-valuenow="' . round($pct) . '" aria-valuemin="0" aria-valuemax="100"><i style="width:' . $pct . '%"></i></div>'; }
function mny($v, string $cur = 'BDT'): string { return e(money($v, $cur)); }
function mbal($v, string $cur = 'BDT'): string { $u = Auth::user(); return ($u && (int)$u['hide_balance'] === 1) ? '<span title="Hidden by your privacy setting">' . ($cur === 'USD' ? '$' : '৳') . ' ••••••</span>' : mny($v, $cur); }
function mask_email(string $e): string { [$a, $b] = array_pad(explode('@', $e, 2), 2, ''); return substr($a, 0, 2) . str_repeat('•', max(2, strlen($a) - 2)) . '@' . $b; }
function uname(?int $id): string { return $id ? (string)val('SELECT name FROM users WHERE id=?', [$id]) : '-'; }
function ref_link(string $ref): string { return '<a class="mono" href="' . e(url('receipt', ['ref' => $ref])) . '">' . e($ref) . '</a>'; }
function type_label(string $t): string { return ucfirst(str_replace('_', ' ', $t)); }

// ---------------- SVG charts (server-rendered, no external library) ----------------
function chart_line(array $series): string {
  $w = 640; $h = 220; $pl = 56; $pr = 12; $pt = 12; $pb = 26; $vals = array_column($series, 'balance_c'); $min = min(0, min($vals)); $max = max(1, max($vals));
  $sx = fn($i) => $pl + ($w - $pl - $pr) * $i / max(1, count($series) - 1); $sy = fn($v) => $pt + ($h - $pt - $pb) * (1 - ($v - $min) / max(1, $max - $min));
  $pts = []; foreach ($series as $i => $p) $pts[] = round($sx($i), 1) . ',' . round($sy($p['balance_c']), 1);
  $zero = round($sy(0), 1); $o = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="30-day projected balance">';
  for ($g = 0; $g <= 3; $g++) { $v = $min + ($max - $min) * $g / 3; $y = round($sy($v), 1); $o .= '<line x1="' . $pl . '" x2="' . ($w - $pr) . '" y1="' . $y . '" y2="' . $y . '" stroke="#E7E2D6"/><text x="' . ($pl - 6) . '" y="' . ($y + 4) . '" text-anchor="end">' . number_format($v / 100 / 1000, 1) . 'k</text>'; }
  $o .= '<line x1="' . $pl . '" x2="' . ($w - $pr) . '" y1="' . $zero . '" y2="' . $zero . '" stroke="#B42318" stroke-dasharray="4 4"/>';
  $o .= '<polygon points="' . $pl . ',' . $zero . ' ' . implode(' ', $pts) . ' ' . round($sx(count($series) - 1), 1) . ',' . $zero . '" fill="#0B7A68" fill-opacity=".12"/><polyline points="' . implode(' ', $pts) . '" fill="none" stroke="#0B7A68" stroke-width="2.4" stroke-linejoin="round"/>';
  foreach ($series as $i => $p) if ($p['balance_c'] < 0) { $o .= '<circle cx="' . round($sx($i), 1) . '" cy="' . round($sy($p['balance_c']), 1) . '" r="3" fill="#B42318"/>'; }
  foreach ([0, (int)(count($series) / 2), count($series) - 1] as $i) $o .= '<text x="' . round($sx($i), 1) . '" y="' . ($h - 6) . '" text-anchor="' . ($i === 0 ? 'start' : ($i === count($series) - 1 ? 'end' : 'middle')) . '">' . date('d M', strtotime($series[$i]['date'])) . '</text>';
  return '<div class="chart">' . $o . '</svg></div>';
}
function chart_donut(array $slices): string {
  $tot = array_sum(array_column($slices, 1)); if ($tot <= 0) return empty_state('No spending in the last 30 days.', 'activity');
  $r = 52; $c = 2 * M_PI * $r; $off = 0; $o = '<svg viewBox="0 0 140 140" role="img" aria-label="Spending by category"><g transform="rotate(-90 70 70)"><circle cx="70" cy="70" r="' . $r . '" fill="none" stroke="#EFEBDF" stroke-width="20"/>'; $leg = '';
  foreach ($slices as $i => [$l, $v]) { $len = $c * $v / $tot; $col = 'var(--c' . (($i % 6) + 1) . ')'; $o .= '<circle cx="70" cy="70" r="' . $r . '" fill="none" stroke="' . $col . '" stroke-width="20" stroke-dasharray="' . round($len, 2) . ' ' . round($c - $len, 2) . '" stroke-dashoffset="' . round(-$off, 2) . '"/>'; $off += $len; $leg .= '<span><i style="background:' . $col . '"></i>' . e(ucfirst($l)) . ' ' . round(100 * $v / $tot) . '%</span>'; }
  return '<div class="chart" style="max-width:260px;margin:0 auto">' . $o . '</g><text x="70" y="68" text-anchor="middle" style="font-weight:700;font-size:11px;fill:#18212F">' . e(money($tot, 'BDT')) . '</text><text x="70" y="82" text-anchor="middle">30 days</text></svg></div><div class="legend">' . $leg . '</div>';
}
function chart_bars(array $labels, array $a, array $b): string {
  $w = 640; $h = 200; $pl = 44; $pb = 24; $max = max(1, max(array_merge($a, $b))); $n = count($labels); $bw = ($w - $pl) / $n; $o = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="Money in and out by month">';
  foreach ($labels as $i => $l) { $x = $pl + $i * $bw; foreach ([[$a[$i], '#0B7A68', 0], [$b[$i], '#B8862B', 1]] as [$v, $c, $k]) { $bh = ($h - $pb - 10) * $v / $max; $o .= '<rect x="' . round($x + $bw * .15 + $k * $bw * .33, 1) . '" y="' . round($h - $pb - $bh, 1) . '" width="' . round($bw * .3, 1) . '" height="' . round($bh, 1) . '" rx="3" fill="' . $c . '"><title>' . e(money($v / 100, 'BDT')) . '</title></rect>'; } $o .= '<text x="' . round($x + $bw / 2, 1) . '" y="' . ($h - 6) . '" text-anchor="middle">' . e($l) . '</text>'; }
  return '<div class="chart">' . $o . '</svg></div><div class="legend"><span><i style="background:#0B7A68"></i>' . e(t('Money in')) . '</span><span><i style="background:#B8862B"></i>' . e(t('Money out')) . '</span></div>';
}

// ---------------- layout ----------------
function nav_items(array $u): array {
  $all = [
    'Overview' => [['dashboard', 'Dashboard', 'home']],
    'Money' => [['wallet', 'Wallet', 'wallet'], ['send', 'Send money', 'send'], ['requests', 'Requests', 'inbox'], ['activity', 'Activity', 'activity']],
    'Plan' => [['safe', 'Safe to spend', 'trend'], ['recurring', 'Recurring', 'calendar'], ['rules', 'Money rules', 'repeat'], ['pockets', 'Pockets', 'target']],
    'Together' => [['groups', 'Groups', 'users'], ['iou', 'IOU tracker', 'receipt']],
    'Business' => [['merchant', 'Merchant', 'store'], ['invoices', 'Invoices', 'file'], ['escrow', 'Escrow', 'scale'], ['disputes', 'Disputes', 'alert']],
    'Protection' => [['tokens', 'Virtual tokens', 'card'], ['offline', 'Offline QR', 'qr'], ['family', 'Family & guardians', 'shield'], ['security', 'Security', 'lock']],
    'Account' => [['notifications', 'Notifications', 'bell'], ['settings', 'Settings', 'settings']],
  ];
  if ($u['role'] !== 'merchant') $all['Business'] = array_values(array_filter($all['Business'], fn($x) => $x[0] !== 'merchant'));
  if ($u['role'] === 'admin') $all['Account'][] = ['admin', 'Admin', 'shield'];
  return $all;
}
function layout(string $title, string $content, ?array $u, string $active): string {
  $lang = $GLOBALS['LANG'] ?? 'en'; $nav = ''; 
  foreach (nav_items($u) as $g => $items) { $nav .= '<div class="nav-group">' . e(t($g)) . '</div>'; foreach ($items as [$k, $l, $ic]) $nav .= '<a href="' . e(url($k)) . '"' . ($k === $active ? ' aria-current="page"' : '') . '>' . icon($ic) . '<span>' . e(t($l)) . '</span></a>'; }
  $unread = (int)val('SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0', [$u['id']]);
  $bn = ''; foreach ([['dashboard', 'Dashboard', 'home'], ['send', 'Send money', 'send'], ['activity', 'Activity', 'activity'], ['safe', 'Safe to spend', 'trend']] as [$k, $l, $ic]) $bn .= '<a href="' . e(url($k)) . '"' . ($k === $active ? ' aria-current="page"' : '') . '>' . icon($ic) . '<span>' . e(t(explode(' ', $l)[0] === 'Safe' ? 'Plan' : $l)) . '</span></a>';
  $bn .= '<a href="#" onclick="document.body.classList.add(\'drawer\');return false">' . icon('more') . '<span>' . e($lang === 'bn' ? 'আরও' : 'More') . '</span></a>';
  $other = $lang === 'bn' ? ['en', 'English'] : ['bn', 'বাংলা'];
  return '<!doctype html><html lang="' . $lang . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($title) . ' - Auvryon</title><link rel="stylesheet" href="assets/css/auvryon.css"><link rel="icon" href="data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 32 32%27%3E%3Crect width=%2732%27 height=%2732%27 rx=%278%27 fill=%27%230B7A68%27/%3E%3Cpath d=%27M8 24L16 7l8 17M11 19h10%27 stroke=%27%23F3D58E%27 stroke-width=%273%27 fill=%27none%27/%3E%3C/svg%3E"></head><body>'
   . '<a class="skip" href="#main">Skip to content</a><div class="app"><aside class="sidebar" aria-label="Main navigation"><div class="brand"><span class="mark"><svg viewBox="0 0 24 24"><path d="M5 20L12 4l7 16M8 15h8"/></svg></span>Auvryon</div><nav class="nav">' . $nav . '</nav></aside>'
   . '<div class="main"><div class="sim-banner" role="note">' . e(t('This is a simulation: no real money moves.')) . '</div><header class="topbar"><button class="btn secondary sm menu-btn" id="menu-btn" aria-label="Menu" aria-expanded="false" type="button">' . icon('menu') . '</button><div class="grow"></div>'
   . '<a class="chip" href="' . e(url('notifications')) . '">' . icon('bell') . ($unread ? $unread : '') . '</a><a class="chip" href="' . e(self_url() . '&lang=' . $other[0]) . '">' . icon('globe') . e($other[1]) . '</a><span class="chip hide-sm">' . icon('user') . e($u['name']) . '</span><a class="chip" href="' . e(url('logout')) . '">' . icon('logout') . '<span class="hide-sm">' . e(t('Sign out')) . '</span></a></header>'
   . '<main id="main" class="content">' . flashes() . $content . '</main></div></div><nav class="bottom-nav" aria-label="Quick navigation">' . $bn . '</nav><script src="assets/js/qrcode.js"></script><script src="assets/js/app.js"></script></body></html>';
}
function public_layout(string $title, string $content): string {
  $lang = $GLOBALS['LANG'] ?? 'en'; $other = $lang === 'bn' ? ['en', 'English'] : ['bn', 'বাংলা'];
  return '<!doctype html><html lang="' . $lang . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e($title) . ' - Auvryon</title><link rel="stylesheet" href="assets/css/auvryon.css"></head><body><div class="auth-wrap"><div class="auth-art"><div class="brand"><span class="mark"><svg viewBox="0 0 24 24"><path d="M5 20L12 4l7 16M8 15h8"/></svg></span>Auvryon</div><div><h1>Money that explains itself.</h1><ul style="padding-left:18px"><li>Undo a wrong transfer inside a cancellation window</li><li>Split group costs and settle with the fewest transfers</li><li>See exactly why your balance is what it is</li><li>Escrow, invoices and recurring-payment protection</li></ul></div><small style="color:#9FB0C3">A student project for NSU CSE311L. Simulated wallets only: no real funds, no bank deposits.</small></div><div class="auth-form"><div style="width:100%;max-width:440px"><p style="text-align:right"><a href="' . e(self_url() . '&lang=' . $other[0]) . '">' . e($other[1]) . '</a></p>' . flashes() . $content . '</div></div></div></body></html>';
}
