<?php
if (Auth::user()) redirect(url('dashboard'));
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  try { csrf_check(); Auth::register($_POST + ['language' => $GLOBALS['LANG']]); $usr = Auth::login((string)$_POST['email'], (string)$_POST['password']); Auth::openSession($usr); flash('ok', 'Account created. Your simulated wallets are empty: add demo funds from Wallet.'); redirect(url('dashboard')); }
  catch (AppError $e) { flash('err', $e->getMessage()); redirect(url('register')); }
}
echo public_layout(t('Create account'), '<div class="card"><h1>' . e(t('Create account')) . '</h1><p class="muted">Free, local and simulated. No real funds are involved.</p>' . form_start('register') .
  field('Full name', 'name', 'text', '', 'required maxlength="100" autocomplete="name"') . field('Email', 'email', 'email', '', 'required autocomplete="email"') . field('Phone', 'phone', 'tel', '', 'placeholder="+8801XXXXXXXXX" autocomplete="tel"', 'Optional. Lets people find you by phone number.') .
  field('Password', 'password', 'password', '', 'required minlength="8" autocomplete="new-password"', 'At least 8 characters with a letter and a number.') . field('Transaction PIN', 'pin', 'password', '', 'required inputmode="numeric" pattern="\d{4,6}" maxlength="6" autocomplete="off"', '4-6 digits. Required to confirm payments.') .
  select('Account type', 'role', ['user' => 'Personal', 'merchant' => 'Merchant'], 'user') . field('Business name', 'business', 'text', '', 'maxlength="120"', 'Merchants only.') . btn('Create account') . '</form><p style="margin-top:16px"><a href="' . e(url('login')) . '">' . e(t('Sign in')) . '</a></p></div>');
