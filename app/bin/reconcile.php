<?php
// Prints every ledger invariant (same checks as Admin > Overview). Exit code 1 if any fails.
require __DIR__ . '/../lib/boot.php';
$bad = 0; foreach (Ledger::reconcile() as [$n, $ok, $d]) { echo ($ok ? 'PASS ' : 'FAIL ') . "$n - $d\n"; if (!$ok) $bad++; }
exit($bad ? 1 : 0);
