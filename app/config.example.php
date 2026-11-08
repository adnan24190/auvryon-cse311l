<?php
// Copy to config.php and adjust. XAMPP defaults shown (user root, empty password).
return [
  'db' => ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'auvryon_db', 'user' => 'root', 'pass' => '', 'charset' => 'utf8mb4'],
  // Used to sign simulated offline QR tokens. Change it for any non-demo use.
  'app_secret' => 'change-this-long-random-secret-before-sharing-the-project',
  'debug' => false,
  'timezone' => 'Asia/Dhaka',
];
