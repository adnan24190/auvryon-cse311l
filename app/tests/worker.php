<?php
// Child process used by concurrency tests. Usage: php worker.php <action> <json-args> <start-microtime>
require __DIR__ . '/../lib/boot.php';
[$_, $action, $json, $start] = $argv; $a = json_decode($json, true);
while (microtime(true) < (float)$start) usleep(200);
try {
  switch ($action) {
    case 'send': $r = Pay::send($a); break;
    case 'cancel': Pay::cancelHold($a['uid'], $a['txn']); $r = ['ok' => true]; break;
    case 'process': $r = ['released' => Pay::processHolds()]; break;
    case 'checkout': $r = Pay::payCheckout($a['uid'], $a['ref'], $a['idem']); break;
    case 'release': $r = Escrow::close($a['uid'], $a['ms'], $a['mode']); break;
    case 'invoice': $r = Invoices::pay($a['uid'], $a['token'], cents($a['amount']), $a['idem']); break;
    case 'offline': $r = Offline::sync($a['uid']); break;
    case 'refund': $r = Pay::refund($a['uid'], $a['txn'], 'race'); break;
    case 'rules': $r = Rules::runDue(); break;
    case 'vote': $r = ['v' => Approvals::vote($a['uid'], $a['req'], 'approve')]; break;
    default: $r = ['error' => 'unknown'];
  }
  echo json_encode(['ok' => true] + (array)$r);
} catch (Throwable $e) { echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'cls' => get_class($e)]); }
