<?php
// Seeds labelled DEMO data through the real services (so every ledger posting is balanced) and fills ALL 51 tables.
// Usage: php bin/seed.php   (database must already contain schema.sql + base_data.sql)
require __DIR__ . '/../lib/boot.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
if ((int)val('SELECT COUNT(*) FROM users') > 0) { echo "Database already has users - aborting.\n"; exit(1); }
setting_set('cancel_window_seconds', '0');                      // history: first-time transfers release immediately
$U = [];
$mk = function (string $key, string $name, string $role = 'user', string $biz = '') use (&$U) {
  $U[$key] = Auth::register(['name' => $name, 'email' => "$key@auvryon.test", 'phone' => '+88017' . str_pad((string)(count($U) + 1), 8, '0', STR_PAD_LEFT), 'password' => 'Demo@1234', 'pin' => '1234', 'role' => $role, 'business' => $biz, 'language' => $key === 'uchas' ? 'bn' : 'en']);
};
$mk('tahsin', 'Tahsin Rafid'); $mk('uchas', 'Ashraful Kabir Uchas'); $mk('tafsir', 'Khandaker Md.Tafsir Hossain'); $mk('adnan', 'Adnan Shafi');
$mk('merchant', 'Aurum Cafe Owner', 'merchant', 'Aurum Cafe'); $mk('freelancer', 'Rafi Freelancer'); $mk('student', 'Demo Student (dependent)'); $mk('admin', 'Auvryon Admin');
$mk('bookshop', 'Dhaka Books Owner', 'merchant', 'Dhaka Books'); $mk('arcade', 'Pixel Arcade Owner', 'merchant', 'Pixel Arcade'); $mk('junior', 'Demo Junior (dependent)');
q("UPDATE users SET role_id=(SELECT id FROM roles WHERE name='admin') WHERE email='admin@auvryon.test'"); $ADM = $U['admin'];
q("UPDATE merchants SET category='education' WHERE business_name='Dhaka Books'"); q("UPDATE merchants SET category='gaming' WHERE business_name='Pixel Arcade'"); q("UPDATE merchants SET category='food' WHERE business_name='Aurum Cafe'");
$mid = fn($k) => (int)val('SELECT id FROM merchants WHERE user_id=?', [$U[$k]]);
$em = fn($k) => "$k@auvryon.test";
foreach (['tahsin' => 120000, 'uchas' => 25000, 'tafsir' => 40000, 'adnan' => 30000, 'merchant' => 5000, 'freelancer' => 2000, 'student' => 3000, 'bookshop' => 1500, 'arcade' => 800, 'junior' => 1500] as $k => $v) Pay::topup($U[$k], 'BDT', cents($v), 'Simulated deposit (demo)');
Pay::topup($U['tahsin'], 'USD', cents(500), 'Simulated USD deposit (demo)'); Pay::topup($U['freelancer'], 'USD', cents(300), 'Simulated USD deposit (demo)'); Pay::topup($U['uchas'], 'USD', cents(120), 'Simulated USD deposit (demo)');
$send = fn($f, $t, $a, $note, $cat = 'transfer', $hold = true) => Pay::send(['from_user' => $U[$f], 'to_user' => $U[$t], 'currency' => 'BDT', 'amount_c' => cents($a), 'type' => 'transfer', 'category' => $cat, 'note' => $note, 'idem_key' => 'seed-' . md5($f . $t . $a . $note), 'actor' => $U[$f], 'allow_hold' => $hold]);
$send('tahsin', 'uchas', '1200', 'Lunch share', 'food'); $send('uchas', 'tahsin', '300', 'Book money'); $send('adnan', 'tahsin', '850', 'Concert ticket', 'entertainment'); $send('tahsin', 'tafsir', '2500', 'Project print costs'); $send('tafsir', 'adnan', '700', 'Bus fare', 'transport'); $send('uchas', 'adnan', '450', 'Tea and snacks', 'food');
$tConcert = (int)val("SELECT id FROM transactions WHERE note='Concert ticket'"); $tBook = (int)val("SELECT id FROM transactions WHERE note='Book money'");
Pay::processHolds();
// ---- merchant QR checkout: paid x4, open, cancelled, expired
$co = function ($m, $a, $note, $payer = null) use ($mid, $U) { $c = Pay::createCheckout($mid($m), $a, $note); if ($payer) Pay::payCheckout($U[$payer], $c['ref'], 'seed-co-' . $c['ref']); return $c; };
$co('merchant', '450', 'Two coffees and cake', 'tahsin'); $co('merchant', '280', 'Lunch set', 'uchas'); $co('bookshop', '620', 'Database textbook', 'tahsin'); $co('merchant', '150', 'Iced latte x2', 'adnan');
$co('merchant', '95', 'Walk-in order (open)'); $c = $co('bookshop', '40', 'Notebook (cancelled)'); q("UPDATE checkout_sessions SET status='cancelled' WHERE ref=?", [$c['ref']]);
$c = $co('arcade', '60', 'Game tokens (expired)'); q("UPDATE checkout_sessions SET status='expired', expires_at=DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE ref=?", [$c['ref']]);
// ---- payment requests: pending, paid, declined, cancelled
Pay::request($U['adnan'], $em('tahsin'), '600', 'BDT', 'Groceries split');
$rid = Pay::request($U['tahsin'], $em('uchas'), '350', 'BDT', 'Shared taxi'); Pay::payRequest($U['uchas'], $rid, 'seed-req-pay-1');
$rid = Pay::request($U['uchas'], $em('adnan'), '900', 'BDT', 'Old tuition notes'); Pay::closeRequest($U['adnan'], $rid, 'declined');
$rid = Pay::request($U['tafsir'], $em('tahsin'), '1500', 'BDT', 'Printer cartridge'); Pay::closeRequest($U['tafsir'], $rid, 'cancelled');
// ---- group finance: three groups
$grp = function ($name, $type, $owner, $members) use ($U) { q('INSERT INTO expense_groups (name,group_type,created_by) VALUES (?,?,?)', [$name, $type, $U[$owner]]); $g = (int)db()->lastInsertId(); foreach ($members as $m) q('INSERT INTO group_members (group_id,user_id) VALUES (?,?)', [$g, $U[$m]]); return $g; };
$gid = $grp("Cox's Bazar Trip", 'trip', 'tahsin', ['tahsin', 'uchas', 'tafsir', 'adnan']); $all = array_map(fn($k) => $U[$k], ['tahsin', 'uchas', 'tafsir', 'adnan']);
Groups::addExpense($U['tahsin'], $gid, ['amount' => '12000', 'description' => 'Hotel (2 nights)', 'category' => 'travel', 'paid_by' => $U['tahsin'], 'split_type' => 'equal', 'participants' => $all]);
Groups::addExpense($U['uchas'], $gid, ['amount' => '4800', 'description' => 'Seafood dinner', 'category' => 'food', 'paid_by' => $U['uchas'], 'split_type' => 'custom', 'participants' => $all, 'custom' => [$U['tahsin'] => '1000', $U['uchas'] => '1800', $U['tafsir'] => '1000', $U['adnan'] => '1000']]);
Groups::addExpense($U['tafsir'], $gid, ['amount' => '3000', 'description' => 'Car rental', 'category' => 'travel', 'paid_by' => $U['tafsir'], 'split_type' => 'percent', 'participants' => $all, 'percent' => [$U['tahsin'] => '25', $U['uchas'] => '25', $U['tafsir'] => '30', $U['adnan'] => '20']]);
Groups::settle($U['adnan'], $gid, $U['tahsin'], cents('1000'), 'seed-settle-1');
$g2 = $grp('Hostel Rent', 'rent', 'uchas', ['uchas', 'tahsin', 'adnan']); $m3 = [$U['uchas'], $U['tahsin'], $U['adnan']];
Groups::addExpense($U['uchas'], $g2, ['amount' => '18000', 'description' => 'October room rent', 'category' => 'rent', 'paid_by' => $U['uchas'], 'split_type' => 'equal', 'participants' => $m3]);
Groups::addExpense($U['tahsin'], $g2, ['amount' => '2400', 'description' => 'Internet and electricity', 'category' => 'bills', 'paid_by' => $U['tahsin'], 'split_type' => 'equal', 'participants' => $m3]);
$g3 = $grp('Weekly Groceries', 'groceries', 'tafsir', ['tafsir', 'tahsin']); $m2 = [$U['tafsir'], $U['tahsin']];
Groups::addExpense($U['tafsir'], $g3, ['amount' => '3150', 'description' => 'Rice, lentils, vegetables', 'category' => 'groceries', 'paid_by' => $U['tafsir'], 'split_type' => 'equal', 'participants' => $m2]);
Groups::addExpense($U['tahsin'], $g3, ['amount' => '1850', 'description' => 'Eggs, milk, bread', 'category' => 'groceries', 'paid_by' => $U['tahsin'], 'split_type' => 'custom', 'participants' => $m2, 'custom' => [$U['tafsir'] => '900', $U['tahsin'] => '950']]);
Groups::addExpense($U['tahsin'], $gid, ['amount' => '2200', 'description' => 'Boat ticket', 'category' => 'travel', 'paid_by' => $U['tahsin'], 'split_type' => 'equal', 'participants' => $all]);
foreach ([$g2, $g3] as $g) { $o = Groups::optimize(Groups::net($g)); foreach ($o['transfers'] as $i => $tr) { if ($i > 0) break; $from = array_search($tr['from'], $U); Groups::settle($tr['from'], $g, $tr['to'], $tr['amount_c'], "seed-settle-$g-$i"); } }
q('INSERT INTO budgets (user_id,group_id,category,monthly_limit) VALUES (?,?,?,?),(?,?,?,?),(?,?,?,?)', [$U['tahsin'], $gid, 'travel', '20000', $U['uchas'], $g2, 'rent', '20000', $U['tafsir'], $g3, 'groceries', '6000']);
// ---- IOUs: open, partial, settled, cancelled + repayments
$iou = Ious::create($U['tahsin'], $em('uchas'), '2000', date('Y-m-d', strtotime('+6 days')), 'Textbook money'); Ious::repay($U['uchas'], $iou, cents('500'), 'seed-iou-1'); Ious::repay($U['uchas'], $iou, cents('300'), 'seed-iou-1b');
Ious::create($U['adnan'], $em('tahsin'), '1500', date('Y-m-d', strtotime('+2 days')), 'Dinner last week');
$i3 = Ious::create($U['tafsir'], $em('adnan'), '3000', date('Y-m-d', strtotime('-3 days')), 'Laptop repair (overdue)'); Ious::repay($U['adnan'], $i3, cents('1000'), 'seed-iou-3');
$i4 = Ious::create($U['uchas'], $em('tafsir'), '400', date('Y-m-d', strtotime('+1 day')), 'Canteen'); Ious::repay($U['tafsir'], $i4, cents('400'), 'seed-iou-4');
$i5 = Ious::create($U['uchas'], $em('student'), '200', date('Y-m-d', strtotime('+10 days')), 'Pen and notebook'); q("UPDATE ious SET status='cancelled' WHERE id=?", [$i5]);
// ---- recurring items (tahsin + uchas)
$ins = function ($k, $kind, $n, $m, $a, $f, $d, $cat) use ($U) { q('INSERT INTO recurring_items (user_id,kind,name,merchant,amount,frequency,next_due,category) VALUES (?,?,?,?,?,?,?,?)', [$U[$k], $kind, $n, $m, $a, $f, date('Y-m-d', strtotime($d)), $cat]); return (int)db()->lastInsertId(); };
$ins('tahsin', 'income', 'Monthly stipend', 'Family', '35000', 'monthly', '+9 days', 'income'); $ins('tahsin', 'rent', 'Hostel rent', 'Landlord', '12000', 'monthly', '+4 days', 'rent'); $ins('tahsin', 'bill', 'Internet', 'Link3', '1200', 'monthly', '+6 days', 'bills');
$nf = $ins('tahsin', 'subscription', 'Netflix', 'Netflix', '800', 'monthly', '+2 days', 'entertainment'); $ins('tahsin', 'subscription', 'Spotify', 'Spotify', '300', 'monthly', '+11 days', 'entertainment'); $ins('tahsin', 'bill', 'Electricity', 'DESCO', '2300', 'monthly', '+13 days', 'bills');
$ins('tahsin', 'subscription', 'Gym membership', 'FitZone', '1500', 'monthly', '+1 days', 'health'); $ins('tahsin', 'bill', 'Mobile data', 'Grameenphone', '499', 'weekly', '+3 days', 'bills'); $ins('uchas', 'rent', 'Room rent share', 'Landlord', '6000', 'monthly', '+5 days', 'rent'); $ins('uchas', 'income', 'Tuition income', 'Students', '9000', 'monthly', '+12 days', 'income');
q("UPDATE recurring_items SET status='paused' WHERE name='Spotify'");
Pay::payBill($U['tahsin'], $nf, 'seed-bill-nf'); try { q('UPDATE recurring_items SET next_due=CURDATE() WHERE id=?', [$nf]); Pay::payBill($U['tahsin'], $nf, 'seed-bill-nf2'); } catch (AppError $e) { /* duplicate expense flagged and blocked: creates a risk alert */ } q('UPDATE recurring_items SET next_due=? WHERE id=?', [date('Y-m-d', strtotime('+2 days')), $nf]);
q('INSERT INTO budgets (user_id,group_id,category,monthly_limit) VALUES (?,0,?,?),(?,0,?,?),(?,0,?,?),(?,0,?,?)', [$U['tahsin'], 'shopping', '5000', $U['tahsin'], 'transfer', '6000', $U['tahsin'], 'food', '4000', $U['uchas'], 'food', '3500']);
// ---- pockets and money rules
$pk = []; foreach ([['Education fund', 'education', '50000'], ['Travel', 'travel', '30000'], ['Emergency', 'emergency', '40000'], ['Monthly expenses', 'monthly_expense', '15000']] as [$n, $k, $t]) $pk[$k] = Pockets::create($U['tahsin'], $n, $k, $t, date('Y-m-d', strtotime('+120 days')));
$upk = Pockets::create($U['uchas'], 'Laptop fund', 'custom', '60000', date('Y-m-d', strtotime('+200 days'))); Pockets::move($U['uchas'], $upk, cents('4000'), 'in');
Pockets::move($U['tahsin'], $pk['education'], cents('8000'), 'in'); Pockets::move($U['tahsin'], $pk['emergency'], cents('5000'), 'in'); Pockets::move($U['tahsin'], $pk['travel'], cents('1500'), 'in'); Pockets::move($U['tahsin'], $pk['emergency'], cents('500'), 'out');
$r1 = Rules::create($U['tahsin'], ['rule_type' => 'round_up', 'name' => 'Round up to Travel', 'pocket' => $pk['travel'], 'round_to' => 50]);
$r2 = Rules::create($U['tahsin'], ['rule_type' => 'income_allocation', 'name' => '10% of income to Emergency', 'pocket' => $pk['emergency'], 'percent' => 10]);
$r3 = Rules::create($U['tahsin'], ['rule_type' => 'low_balance_alert', 'name' => 'Low balance alert', 'threshold' => '3000']);
$r4 = Rules::create($U['tahsin'], ['rule_type' => 'scheduled_transfer', 'name' => 'Weekly pocket money to Uchas', 'target' => $em('uchas'), 'amount' => '500', 'frequency' => 'weekly', 'next_run' => date('Y-m-d', strtotime('+3 days'))]);
$r5 = Rules::create($U['tahsin'], ['rule_type' => 'scheduled_transfer', 'name' => 'Daily tea fund to Adnan', 'target' => $em('adnan'), 'amount' => '50', 'frequency' => 'daily', 'next_run' => date('Y-m-d')]);
$r6 = Rules::create($U['student'], ['rule_type' => 'scheduled_transfer', 'name' => 'Monthly pocket money (will fail)', 'target' => $em('uchas'), 'amount' => '5000', 'frequency' => 'monthly', 'next_run' => date('Y-m-d')]);
Rules::create($U['uchas'], ['rule_type' => 'round_up', 'name' => 'Round up to Laptop fund', 'pocket' => $upk, 'round_to' => 10]); q("UPDATE money_rules SET status='paused' WHERE name='Round up to Laptop fund'");
Pay::topup($U['tahsin'], 'BDT', cents('5000'), 'Freelance payout (simulated)'); $co('bookshop', '123', 'Stationery', 'tahsin'); $co('merchant', '200', 'Cake slice', 'tahsin');
Rules::runDue(); Rules::runDue(); Rules::runDue();                                  // r5 succeeds once; r6 fails 3 times, then is marked failed
// ---- virtual tokens: active, active, cancelled, paused
$tokA = Tokens::create($U['tahsin'], $mid('merchant'), '800', '2000'); Tokens::charge($U['merchant'], $tokA, cents('350'), 'seed-token-1'); Tokens::charge($U['merchant'], $tokA, cents('900'), 'seed-token-2'); Tokens::charge($U['merchant'], $tokA, cents('120'), 'seed-token-3');
$tokB = Tokens::create($U['tahsin'], $mid('bookshop'), '600', '1500'); Tokens::charge($U['bookshop'], $tokB, cents('450'), 'seed-token-4'); Tokens::charge($U['bookshop'], $tokB, cents('600'), 'seed-token-5'); Tokens::charge($U['bookshop'], $tokB, cents('700'), 'seed-token-6');
$tokC = Tokens::create($U['uchas'], $mid('arcade'), '300', '600'); Tokens::charge($U['arcade'], $tokC, cents('250'), 'seed-token-7'); $tc = (int)val('SELECT id FROM virtual_tokens WHERE user_id=? AND merchant_id=?', [$U['uchas'], $mid('arcade')]);
Tokens::setStatus($U['uchas'], $tc, 'paused'); Tokens::charge($U['arcade'], $tokC, cents('100'), 'seed-token-8'); Tokens::setStatus($U['uchas'], $tc, 'cancelled'); Tokens::charge($U['arcade'], $tokC, cents('100'), 'seed-token-9');
$tokD = Tokens::create($U['uchas'], $mid('merchant'), '400', '1200'); Tokens::charge($U['merchant'], $tokD, cents('180'), 'seed-token-10'); Tokens::setStatus($U['uchas'], (int)val('SELECT MAX(id) FROM virtual_tokens'), 'paused');
// ---- guardian controls and multi-approver authorization
foreach (['tafsir', 'tahsin'] as $g) q('INSERT INTO dependents (guardian_id,dependent_id,per_txn_limit,daily_limit) VALUES (?,?,?,?)', [$U[$g], $U['student'], '2000', '3000']);
q('INSERT INTO dependents (guardian_id,dependent_id,per_txn_limit,daily_limit) VALUES (?,?,?,?)', [$U['tafsir'], $U['junior'], '500', '800']);
$dTaf = (int)val('SELECT id FROM dependents WHERE guardian_id=? AND dependent_id=?', [$U['tafsir'], $U['student']]); $dJun = (int)val('SELECT id FROM dependents WHERE dependent_id=?', [$U['junior']]);
q("INSERT INTO dependent_restrictions (dependent_row_id,restriction_type,value) VALUES (?,'category','gaming'),(?,'merchant','Pixel Arcade'),(?,'category','entertainment')", [$dTaf, $dJun, $dJun]);
$pol = function ($owner, $min, $need, $approvers, $by) use ($U) { $w = Ledger::wallet($U[$owner], 'BDT'); q('INSERT INTO approval_policies (wallet_id,min_amount,required_approvals,created_by) VALUES (?,?,?,?)', [$w['id'], $min, $need, $U[$by]]); $p = (int)db()->lastInsertId(); foreach ($approvers as $a) q('INSERT INTO approval_policy_approvers (policy_id,user_id) VALUES (?,?)', [$p, $U[$a]]); };
$pol('student', '1000', 2, ['tafsir', 'tahsin'], 'tafsir'); $pol('tahsin', '20000', 2, ['tafsir', 'adnan'], 'tahsin'); $pol('merchant', '3000', 1, ['tafsir'], 'merchant');
$ap = fn($from, $to, $a, $note, $cat = 'education') => Pay::send(['from_user' => $U[$from], 'to_user' => $U[$to], 'currency' => 'BDT', 'amount_c' => cents($a), 'type' => 'transfer', 'category' => $cat, 'note' => $note, 'idem_key' => 'seed-ap-' . md5($from . $to . $a . $note), 'actor' => $U[$from], 'allow_hold' => false]);
$rA = $ap('student', 'uchas', '1500', 'Course fee share')['approval_id']; Approvals::vote($U['tafsir'], $rA, 'approve');                    // pending (1 of 2)
$rC = $ap('student', 'adnan', '2000', 'Gift for a friend', 'shopping')['approval_id']; Approvals::vote($U['tafsir'], $rC, 'reject');                       // rejected
$rB = $ap('student', 'uchas', '1200', 'Workshop ticket')['approval_id']; Approvals::vote($U['tafsir'], $rB, 'approve'); Approvals::vote($U['tahsin'], $rB, 'approve');   // executed
try { $ap('student', 'adnan', '300', 'Console purchase', 'gaming'); } catch (AppError $e) { /* blocked by the guardian category rule */ }
$rD = $ap('tahsin', 'tafsir', '20000', 'Semester tuition transfer')['approval_id']; Approvals::vote($U['tafsir'], $rD, 'approve'); Approvals::vote($U['adnan'], $rD, 'approve');   // executed
$ap('tahsin', 'uchas', '30000', 'Laptop purchase advance', 'shopping');                                                                       // pending, no votes yet
// ---- escrow: three contracts
$e1 = Escrow::create($U['tahsin'], $em('freelancer'), 'Website redesign', 'BDT', [['title' => 'Wireframes', 'amount' => '4000', 'due' => date('Y-m-d', strtotime('-5 days'))], ['title' => 'Visual design', 'amount' => '6000', 'due' => date('Y-m-d', strtotime('+7 days'))], ['title' => 'Launch', 'amount' => '5000', 'due' => date('Y-m-d', strtotime('+21 days'))]]);
$ms = rows('SELECT id FROM escrow_milestones WHERE escrow_id=? ORDER BY seq', [$e1]);
Escrow::fund($U['tahsin'], (int)$ms[0]['id'], 'seed-es-1'); Escrow::submit($U['freelancer'], (int)$ms[0]['id']); Escrow::close($U['tahsin'], (int)$ms[0]['id'], 'release'); Escrow::fund($U['tahsin'], (int)$ms[1]['id'], 'seed-es-2'); Escrow::submit($U['freelancer'], (int)$ms[1]['id']);
$e2 = Escrow::create($U['adnan'], $em('freelancer'), 'Logo pack', 'BDT', [['title' => 'Three concepts', 'amount' => '3000']]); $m2 = (int)val('SELECT id FROM escrow_milestones WHERE escrow_id=?', [$e2]); Escrow::fund($U['adnan'], $m2, 'seed-es-3');
$e3 = Escrow::create($U['uchas'], $em('freelancer'), 'Thesis proofreading', 'BDT', [['title' => 'Draft proofreading', 'amount' => '800'], ['title' => 'Final proofreading', 'amount' => '600']]); $m3s = rows('SELECT id FROM escrow_milestones WHERE escrow_id=? ORDER BY seq', [$e3]);
Escrow::fund($U['uchas'], (int)$m3s[0]['id'], 'seed-es-4'); Escrow::submit($U['freelancer'], (int)$m3s[0]['id']); Escrow::close($U['uchas'], (int)$m3s[0]['id'], 'release'); Escrow::fund($U['uchas'], (int)$m3s[1]['id'], 'seed-es-5'); Escrow::close($U['freelancer'], (int)$m3s[1]['id'], 'refund');
// ---- invoices: partial, sent, paid, overdue, cancelled
$inv = Invoices::create($U['freelancer'], ['title' => 'Mobile app UI kit', 'customer' => $em('tahsin'), 'due' => date('Y-m-d', strtotime('+10 days')), 'items' => [['description' => 'UI kit', 'qty' => '1', 'price' => '12000'], ['description' => 'Icon set', 'qty' => '3', 'price' => '1000']]]);
$tok = fn($i) => (string)val('SELECT link_token FROM invoices WHERE id=?', [$i]); Invoices::pay($U['tahsin'], $tok($inv), cents('5000'), 'seed-inv-1');
Invoices::create($U['merchant'], ['title' => 'Catering order #12', 'customer_name' => 'Walk-in customer', 'due' => date('Y-m-d', strtotime('+5 days')), 'items' => [['description' => 'Coffee boxes', 'qty' => '4', 'price' => '1500']]]);
$inv3 = Invoices::create($U['bookshop'], ['title' => 'Textbook bundle', 'customer' => $em('tahsin'), 'due' => date('Y-m-d', strtotime('+8 days')), 'items' => [['description' => 'Algorithms', 'qty' => '1', 'price' => '1500'], ['description' => 'Networks', 'qty' => '1', 'price' => '1000']]]); Invoices::pay($U['tahsin'], $tok($inv3), cents('1000'), 'seed-inv-3a'); Invoices::pay($U['tahsin'], $tok($inv3), cents('1500'), 'seed-inv-3b');
Invoices::create($U['freelancer'], ['title' => 'Poster design', 'customer' => $em('uchas'), 'due' => date('Y-m-d', strtotime('-6 days')), 'items' => [['description' => 'A2 poster', 'qty' => '1', 'price' => '1800']]]);
$inv5 = Invoices::create($U['merchant'], ['title' => 'Event catering (cancelled)', 'customer' => $em('adnan'), 'due' => date('Y-m-d', strtotime('+15 days')), 'items' => [['description' => 'Snack platters', 'qty' => '10', 'price' => '350']]]); q("UPDATE invoices SET status='cancelled' WHERE id=?", [$inv5]);
Invoices::markOverdue(); Invoices::remind($U['freelancer'], $inv);
// ---- multi-currency (simulated rate)
Fx::convert($U['tahsin'], 'BDT', cents('6000'), 'seed-fx-1'); Fx::convert($U['uchas'], 'USD', cents('20'), 'seed-fx-2'); Fx::convert($U['freelancer'], 'USD', cents('50'), 'seed-fx-3');
q("INSERT INTO exchange_rates (base_currency,quote_currency,rate,as_of,source) VALUES ('USD','BDT',118.900000,'2026-09-01','SIMULATED demonstration rate (history)'),('USD','BDT',119.400000,'2026-09-15','SIMULATED demonstration rate (history)')");
// ---- offline QR (simulated): settled, replay-rejected, conflict-rejected, expired-rejected, queued, issued
$off = fn($payer, $amt) => Offline::issue($U[$payer], $em('merchant'), $amt)['token'];
$t1 = $off('tahsin', '200'); Offline::queue($U['merchant'], $t1); $t2 = $off('uchas', '90'); Offline::queue($U['merchant'], $t2); Offline::sync($U['merchant']);
Offline::queue($U['merchant'], $t1); Offline::sync($U['merchant']);                                                                             // replay -> rejected + risk alert
$t3 = $off('freelancer', '1500'); Pay::send(['from_user' => $U['freelancer'], 'to_user' => $U['tahsin'], 'currency' => 'BDT', 'amount_c' => cents('1000'), 'type' => 'settlement', 'fee_mode' => 'none', 'category' => 'transfer', 'note' => 'Loan repayment', 'idem_key' => 'seed-off-spend', 'actor' => $U['freelancer'], 'allow_hold' => false]); Offline::queue($U['merchant'], $t3); Offline::sync($U['merchant']);   // conflict
$t4 = $off('adnan', '60'); q("UPDATE offline_tokens SET expires_at=DATE_SUB(NOW(), INTERVAL 1 HOUR) WHERE user_id=? AND status='issued' ORDER BY id DESC LIMIT 1", [$U['adnan']]); Offline::queue($U['merchant'], $t4); Offline::sync($U['merchant']);   // expired
$t5 = $off('tahsin', '25'); Offline::queue($U['merchant'], $t5);                                                                                // queued, NOT synced
$off('uchas', '150');                                                                                                                           // issued, not yet presented
// ---- disputes: open (escrow), under_review, resolved (refund), rejected
$d1 = Disputes::open($U['adnan'], null, $m2, 'Concepts do not match the brief we agreed.'); Disputes::evidence($U['adnan'], $d1, 'Brief PDF dated last week asked for a monochrome mark.'); Disputes::evidence($U['freelancer'], $d1, 'Three concepts were delivered on time; colour was never ruled out in chat.');
$tCof = (int)val("SELECT id FROM transactions WHERE type='merchant_payment' ORDER BY id LIMIT 1"); $d2 = Disputes::open($U['tahsin'], $tCof, null, 'Charged for two coffees but received one.'); Disputes::evidence($U['tahsin'], $d2, 'Receipt photo shows one cup on the tray.'); Disputes::decide($ADM, $d2, 'review', 'Asked the merchant for the till record.');
$d3 = Disputes::open($U['adnan'], $tConcert, null, 'Concert was cancelled, I need the ticket money back.'); Disputes::evidence($U['adnan'], $d3, 'Organiser announcement screenshot attached to the group chat.'); Disputes::decide($ADM, $d3, 'refund', 'Event cancellation confirmed.');
$d4 = Disputes::open($U['uchas'], $tBook, null, 'I think I sent this by mistake.'); Disputes::evidence($U['uchas'], $d4, 'Recipient is a regular contact, amount matches the book I owed.'); Disputes::decide($ADM, $d4, 'reject', 'Known recipient; payment was intentional.');
// ---- wrong-transfer protection with a LONG window so the data shows live and cancelled holds at review time
$risk = function ($f, $t, $a) use ($U, $em) { return Pay::preview($U[$f], $em($t), $a, 'BDT', 'transfer', false)['risk']; };
$rk = $risk('tahsin', 'tafsir', '11000'); $r = $send('tahsin', 'tafsir', '11000', 'Semester fee advance', 'education'); Pay::processHolds(); Pay::recordRisk($U['tahsin'], $r['txn_id'], $rk);
setting_set('cancel_window_seconds', '2592000');
$rk = $risk('tahsin', 'freelancer', '3500'); $r = $send('tahsin', 'freelancer', '3500', 'Advance for design work'); Pay::recordRisk($U['tahsin'], $r['txn_id'], $rk);
$rk = $risk('tahsin', 'junior', '300'); $r = $send('tahsin', 'junior', '300', 'Pocket money (wrong person)'); Pay::recordRisk($U['tahsin'], $r['txn_id'], $rk); Pay::cancelHold($U['tahsin'], $r['txn_id']);
setting_set('cancel_window_seconds', '300');
// ---- sessions, tokens, audit, reminders
$dev = function ($k, $label, $ip, $trusted, $revoked, $days) use ($U) { q('INSERT INTO devices (user_id,device_hash,label,ip_address,trusted,revoked,first_seen,last_seen) VALUES (?,?,?,?,?,?,DATE_SUB(NOW(), INTERVAL ? DAY),DATE_SUB(NOW(), INTERVAL ? DAY))', [$U[$k], hash('sha256', $k . $label), $label, $ip, $trusted, $revoked, $days + 20, $days]); };
$dev('tahsin', 'Chrome 130 on Windows 11 (laptop)', '103.112.45.10', 1, 0, 0); $dev('tahsin', 'Auvryon app on Android 15 (phone)', '103.112.45.11', 1, 0, 1); $dev('tahsin', 'Firefox on Ubuntu (unknown cafe wifi)', '45.64.12.77', 0, 0, 3); $dev('tahsin', 'Safari on iPad (revoked)', '103.112.45.12', 0, 1, 9);
$dev('uchas', 'Edge on Windows 10', '103.112.46.20', 1, 0, 0); $dev('uchas', 'Chrome on Android 14', '103.112.46.21', 1, 0, 2); $dev('adnan', 'Chrome on macOS', '103.112.47.30', 1, 0, 0); $dev('admin', 'Chrome on Windows 11 (admin console)', '10.0.0.5', 1, 0, 0);
foreach (['tahsin', 'uchas', 'admin'] as $k) Auth::issueToken($U[$k]); q('UPDATE api_tokens SET revoked=1 WHERE id=(SELECT x.id FROM (SELECT MIN(id) id FROM api_tokens) x)');
foreach (['tahsin', 'uchas', 'tafsir', 'adnan', 'merchant', 'freelancer', 'admin'] as $k) audit($U[$k], 'login', 'user', $U[$k], 'device sign-in (demo)'); audit($U['tahsin'], 'pin_failed', 'user', $U['tahsin'], 'wrong PIN entered once'); audit($U['uchas'], 'password_changed', 'user', $U['uchas']); audit($ADM, 'setting_changed', 'settings', null, 'cancel_window_seconds=300');
rate_limit('login:127.0.0.1:stranger@example.com', 8, 600); rate_limit('login:127.0.0.1:stranger@example.com', 8, 600); rate_limit('xfer:' . $U['tahsin'], 10, 60); rate_limit('api:' . $U['uchas'], 120, 60);
Rules::reminders(); q("UPDATE risk_alerts SET status='reviewed' WHERE id=(SELECT x.id FROM (SELECT MIN(id) id FROM risk_alerts) x)"); q("UPDATE notifications SET is_read=1 WHERE id % 3 = 0");
// ---- spread history over the last 4 weeks so reports, charts and date filters have something to show
$n = (int)val('SELECT COUNT(*) FROM transactions');
q("UPDATE transactions SET created_at = DATE_ADD(DATE_SUB(NOW(), INTERVAL 28 DAY), INTERVAL FLOOR((id-1) * 28*24*60 * 0.97 / ?) MINUTE)", [$n]);
q("UPDATE transactions SET completed_at = created_at WHERE status IN ('completed','refunded','disputed')"); q('UPDATE ledger_entries e JOIN transactions t ON t.id=e.txn_id SET e.created_at=t.created_at');
q("UPDATE group_expenses SET expense_date = DATE_SUB(CURDATE(), INTERVAL (id % 9) DAY)");
echo "Seed complete. Demo password: Demo@1234  PIN: 1234\n";
foreach (Ledger::reconcile() as [$nme, $ok, $d]) echo ($ok ? 'PASS ' : 'FAIL ') . "$nme - $d\n";
