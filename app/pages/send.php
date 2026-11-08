<?php
$uid = (int)$u['id']; $title = 'Send money'; $prev = null; $in = ['to' => $_REQUEST['to'] ?? '', 'amount' => $_REQUEST['amount'] ?? '', 'cur' => ($_REQUEST['cur'] ?? 'BDT') === 'USD' ? 'USD' : 'BDT', 'note' => $_REQUEST['note'] ?? ''];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  try {
    csrf_check(); $act = $_POST['_a'] ?? '';
    if ($act === 'send_preview') { $prev = Pay::preview($uid, $in['to'], $in['amount'], $in['cur'], 'transfer', (bool)$u['device_new']); }
    elseif ($act === 'send_confirm') {
      $prev = Pay::preview($uid, $in['to'], $in['amount'], $in['cur'], 'transfer', (bool)$u['device_new']); $key = idem_key();
      $bio = ($_POST['bio'] ?? '') === '1' && (int)$u['sim_biometric'] === 1 && $prev['amount_c'] < cents(setting('high_value_' . $in['cur'])) && in_array($prev['risk']['level'], ['none', 'low'], true);
      if (!$bio) Auth::verifyPin($uid, (string)($_POST['pin'] ?? ''));
      if ($prev['risk']['level'] === 'high') Auth::verifyPassword($uid, (string)($_POST['password'] ?? ''));
      if (!rate_limit("xfer:$uid", (int)setting('transfer_rate_limit', '10'), 60)) throw new AppError('You are sending too fast. Wait a minute and try again.');
      $r = Pay::send(['from_user' => $uid, 'to_user' => (int)$prev['to']['id'], 'currency' => $in['cur'], 'amount_c' => $prev['amount_c'], 'type' => 'transfer', 'category' => 'transfer', 'note' => mb_substr((string)$in['note'], 0, 255), 'idem_key' => $key, 'actor' => $uid, 'allow_hold' => true]);
      if (empty($r['duplicate'])) Pay::recordRisk($uid, $r['txn_id'] ?? null, $prev['risk']);
      if ($r['status'] === 'approval_pending') { flash('warn', 'This payment needs approval from your designated approvers before any money moves.'); redirect(url('family')); }
      flash('ok', $r['status'] === 'held' ? 'Transfer is on hold. You can cancel it until the countdown ends.' : (!empty($r['duplicate']) ? 'This payment was already submitted. No second charge was made.' : 'Payment sent.')); redirect(url('receipt', ['ref' => $r['ref']]));
    }
  } catch (AppError $e) { flash('err', $e->getMessage()); $prev = null; }
}
if ($prev) {
  $p = $prev; $c = $in['cur']; $after = $p['balance_c'] - $p['total_c']; $rk = '';
  foreach ($p['risk']['flags'] as $f) $rk .= '<li>' . badge($f['severity']) . ' ' . e($f['message']) . '</li>';
  $body = '<dl class="kv"><dt>Recipient</dt><dd>' . e($p['to']['name']) . '<div class="hint">' . e(mask_email($p['to']['email'])) . '</div></dd><dt>Amount</dt><dd>' . mny(dec($p['amount_c']), $c) . '</dd><dt>Fee (' . e(setting('fee_percent')) . '%, min ' . e(money(setting("fee_min_$c"), $c)) . ')</dt><dd>' . mny(dec($p['fee_c']), $c) . '</dd></dl><div class="total-row"><span>Final payable amount</span><span>' . mny(dec($p['total_c']), $c) . '</span></div><p class="hint">Balance after payment: ' . mny(dec($after), $c) . '</p>';
  if ($p['hold']) $body .= '<div class="alert warn">' . icon('clock') . '<div><strong>Wrong-transfer protection applies.</strong> Because this is a ' . e($p['hold_reason']) . ', the money is held for ' . floor($p['window'] / 60) . ' min ' . ($p['window'] % 60) . ' s. You can cancel for a full refund (including the fee) until the countdown ends. After that it is released to the recipient and cannot be cancelled.</div></div>';
  else $body .= '<div class="alert info">This payment is immediate and cannot be cancelled. Disputes can be opened from the receipt.</div>';
  if ($rk) $body .= '<div class="alert warn" style="display:block"><strong>Risk check: ' . e(ucfirst($p['risk']['level'])) . '</strong><ul style="margin:6px 0 0;padding-left:18px;list-style:none">' . $rk . '</ul><div class="hint">These are rule-based warnings, not accusations. Review the recipient carefully.</div></div>';
  if ($p['balance_c'] < $p['total_c']) $body .= '<div class="alert err">Your balance is lower than the final amount. The payment will be refused.</div>';
  $hid = ''; foreach (['to', 'amount', 'cur', 'note'] as $k) $hid .= '<input type="hidden" name="' . $k . '" value="' . e((string)$in[$k]) . '">';
  $form = form_start('send_confirm') . $hid . pin_field() . ($p['risk']['level'] === 'high' ? field('Password', 'password', 'password', '', 'required autocomplete="current-password"', 'Extra confirmation required because this payment looks risky.') : '') .
    '<div class="row">' . btn('Confirm and send') . '<a class="btn secondary" href="' . e(url('send', $in)) . '">' . e(t('Back')) . '</a></div>' .
    ((int)$u['sim_biometric'] === 1 && $p['amount_c'] < cents(setting("high_value_$c")) && in_array($p['risk']['level'], ['none', 'low'], true) ? '<hr><button class="btn secondary" name="bio" value="1" type="submit" formnovalidate>Confirm with simulated biometric</button><p class="hint">SIMULATION ONLY: this is a button, not real fingerprint or face verification, and it is limited to low-risk, below-high-value payments.</p>' : '') . '</form>';
  echo page_head('Review payment', 'Check every detail. Nothing has been sent yet.') . '<div class="grid g2">' . card('Payment summary', $body) . card('Confirm', $form) . '</div>'; return;
}
$wb = Ledger::wallet($uid, $in['cur']);
echo page_head('Send money', 'Pay anyone with an Auvryon account by email or phone.') . '<div class="grid g-main">' . card('Payment details', form_start('send_preview') . field('Recipient', 'to', 'text', (string)$in['to'], 'required placeholder="email or phone" autocomplete="off"') . '<div class="row">' . field('Amount', 'amount', 'text', (string)$in['amount'], 'required inputmode="decimal" placeholder="0.00"') . select('Currency', 'cur', ['BDT' => 'BDT (৳)', 'USD' => 'USD ($)'], $in['cur']) . '</div>' . field('Note', 'note', 'text', (string)$in['note'], 'maxlength="255"') . btn('Review payment') . '</form>') .
  card('Your limits', '<dl class="kv"><dt>Available</dt><dd>' . mbal($wb['balance'], $in['cur']) . '</dd><dt>Per transaction</dt><dd>' . mny(setting('per_txn_limit_' . $in['cur']), $in['cur']) . '</dd><dt>Per day</dt><dd>' . mny(setting('daily_limit_' . $in['cur']), $in['cur']) . '</dd><dt>Fee</dt><dd>' . e(setting('fee_percent')) . '% (min ' . e(money(setting('fee_min_' . $in['cur']), $in['cur'])) . ')</dd><dt>Cancellation window</dt><dd>' . floor((int)setting('cancel_window_seconds') / 60) . ' min for first-time or high-value recipients</dd></dl>', '', 'flat') . '</div>';
