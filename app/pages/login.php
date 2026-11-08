<?php
if (Auth::user()) redirect(url('dashboard'));
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  try { csrf_check(); $usr = Auth::login((string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? '')); $to = (string)($_SESSION['after_login'] ?? ''); Auth::openSession($usr);
    redirect(preg_match('~^/?[\w./-]*index\.php\?p=[a-z_]+[\w=&%.-]*$~', $to) ? $to : url('dashboard'));
  } catch (AppError $e) { flash('err', $e->getMessage()); redirect(url('login')); }
}
$demo = '';
foreach ([['tahsin', 'Consumer with full demo data'], ['uchas', 'Consumer (Bangla preferred)'], ['merchant', 'Merchant: Aurum Cafe'], ['freelancer', 'Freelancer (escrow/invoices)'], ['student', 'Dependent wallet (guardian controls)'], ['admin', 'Administrator']] as [$k, $d]) $demo .= '<tr><td class="mono">' . e($k) . '@auvryon.test</td><td>' . e($d) . '</td></tr>';
echo public_layout(t('Sign in'), '<div class="card"><h1>' . e(t('Welcome back')) . '</h1><p class="muted">Sign in to your Auvryon wallet.</p>' . form_start('login') .
  field('Email', 'email', 'email', '', 'autocomplete="username" required autofocus') . field('Password', 'password', 'password', '', 'autocomplete="current-password" required') . btn('Sign in') . '</form>' .
  '<p style="margin-top:16px">New here? <a href="' . e(url('register')) . '">' . e(t('Create account')) . '</a></p><div class="demo"><strong>' . e(t('Demo accounts')) . ' (DEMO DATA)</strong><br>Password <span class="mono">Demo@1234</span> &middot; PIN <span class="mono">1234</span><table style="margin-top:6px;font-size:.82rem"><tbody>' . $demo . '</tbody></table></div></div>');
