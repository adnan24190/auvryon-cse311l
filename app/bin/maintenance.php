<?php
// Optional cron / Windows Task Scheduler job (every minute). The web app also runs these lazily on page loads.
//   C:\xampp\php\php.exe C:\xampp\htdocs\auvryon\bin\maintenance.php
require __DIR__ . '/../lib/boot.php';
echo json_encode(run_maintenance()) . "\n";
