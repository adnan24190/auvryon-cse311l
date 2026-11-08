<?php
declare(strict_types=1);
final class Auth {
  static function register(array $d): int {
    $name = trim((string)($d['name'] ?? '')); $email = strtolower(trim((string)($d['email'] ?? '')));
    $phone = trim((string)($d['phone'] ?? '')); $pw = (string)($d['password'] ?? ''); $pin = (string)($d['pin'] ?? '');
    $role = in_array($d['role'] ?? 'user', ['user', 'merchant'], true) ? ($d['role'] ?? 'user') : 'user';
    if (mb_strlen($name) < 2 || mb_strlen($name) > 100) throw new AppError('Please enter your full name.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new AppError('Please enter a valid email address.');
    if ($phone !== '' && !preg_match('/^\+?\d{8,15}$/', $phone)) throw new AppError('Phone must be 8-15 digits.');
    if (strlen($pw) < 8 || !preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) throw new AppError('Password needs at least 8 characters with a letter and a number.');
    if (!valid_pin($pin)) throw new AppError('Transaction PIN must be 4 to 6 digits.');
    if (!rate_limit('register:' . ip(), 20, 3600)) throw new AppError('Too many registrations from this address. Try again later.');
    return tx(function () use ($name, $email, $phone, $pw, $pin, $role, $d) {
      if (val('SELECT id FROM users WHERE email=?', [$email])) throw new AppError('That email is already registered.');
      if ($phone !== '' && val('SELECT id FROM users WHERE phone=?', [$phone])) throw new AppError('That phone number is already registered.');
      $rid = (int)val('SELECT id FROM roles WHERE name=?', [$role]);
      q('INSERT INTO users (name,email,phone,password_hash,pin_hash,role_id) VALUES (?,?,?,?,?,?)',
        [$name, $email, $phone === '' ? null : $phone, password_hash($pw, PASSWORD_DEFAULT), password_hash($pin, PASSWORD_DEFAULT), $rid]);
      $uid = (int)db()->lastInsertId();
      q('INSERT INTO user_settings (user_id, language) VALUES (?,?)', [$uid, ($d['language'] ?? 'en') === 'bn' ? 'bn' : 'en']);
      Ledger::createWallet($uid, 'BDT'); Ledger::createWallet($uid, 'USD');
      if ($role === 'merchant') q('INSERT INTO merchants (user_id,business_name,category) VALUES (?,?,?)', [$uid, trim((string)($d['business'] ?? '')) ?: $name, 'shopping']);
      audit($uid, 'register', 'user', $uid, $role);
      notify($uid, 'system', 'Welcome to Auvryon', 'Your demo wallets are ready. All balances are simulated.');
      return $uid;
    });
  }

  /** returns user row on success; throws AppError otherwise */
  static function login(string $email, string $pw): array {
    $email = strtolower(trim($email));
    if (!rate_limit('login:' . ip() . ':' . $email, 8, 600)) throw new AppError('Too many sign-in attempts. Please wait a few minutes.');
    $u = row('SELECT u.*, r.name AS role FROM users u JOIN roles r ON r.id=u.role_id WHERE u.email=?', [$email]);
    if (!$u) { password_verify($pw, '$2y$10$abcdefghijklmnopqrstuuMZSLZqkZ4qFq7Yz8b0m0m0m0m0m0m0m'); throw new AppError('Incorrect email or password.'); }
    if ($u['locked_until'] && strtotime($u['locked_until']) > time()) throw new AppError('This account is temporarily locked. Try again later.');
    if (!password_verify($pw, $u['password_hash'])) {
      $f = (int)$u['failed_logins'] + 1;
      q('UPDATE users SET failed_logins=?, locked_until=? WHERE id=?', [$f, $f >= 5 ? date('Y-m-d H:i:s', time() + 600) : null, $u['id']]);
      audit((int)$u['id'], 'login_failed', 'user', (int)$u['id']);
      throw new AppError('Incorrect email or password.');
    }
    if ($u['status'] !== 'active') throw new AppError('This account is suspended. Contact support.');
    q('UPDATE users SET failed_logins=0, locked_until=NULL WHERE id=?', [$u['id']]);
    q('DELETE FROM rate_limits WHERE bucket=?', [substr('login:' . ip() . ':' . $email, 0, 120)]);   // only failures count toward the limit
    return $u;
  }

  static function openSession(array $u): void {
    $cookie = $_COOKIE['av_device'] ?? '';
    if (!preg_match('/^[a-f0-9]{32}$/', $cookie)) {
      $cookie = bin2hex(random_bytes(16));
      setcookie('av_device', $cookie, ['expires' => time() + 86400 * 365, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    }
    $hash = hash('sha256', $cookie);
    $dev = row('SELECT * FROM devices WHERE user_id=? AND device_hash=?', [$u['id'], $hash]);
    if (!$dev) {
      $label = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown device', 0, 150);
      q('INSERT INTO devices (user_id,device_hash,label,ip_address) VALUES (?,?,?,?)', [$u['id'], $hash, $label, ip()]);
      $devId = (int)db()->lastInsertId();
      $others = (int)val('SELECT COUNT(*) FROM devices WHERE user_id=? AND id<>?', [$u['id'], $devId]);
      if ($others > 0) notify((int)$u['id'], 'login', 'New device sign-in', 'A new device signed in from IP ' . ip() . '. If this was not you, revoke it in Security and change your password.');
      $_SESSION['new_device'] = true;
    } else {
      if ((int)$dev['revoked'] === 1) q('UPDATE devices SET revoked=0, trusted=0 WHERE id=?', [$dev['id']]);
      q('UPDATE devices SET last_seen=NOW(), ip_address=? WHERE id=?', [ip(), $dev['id']]);
      $devId = (int)$dev['id'];
      $_SESSION['new_device'] = false;
    }
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id']; $_SESSION['device_id'] = $devId; $_SESSION['csrf'] = bin2hex(random_bytes(24));
    audit((int)$u['id'], 'login', 'user', (int)$u['id'], 'device ' . $devId);
  }

  static function user(): ?array {
    static $u = false;
    if ($u !== false) return $u;
    $u = null;
    if (empty($_SESSION['uid'])) return null;
    $r = row('SELECT u.*, r.name AS role, s.language, s.hide_balance, s.notify_login, s.notify_transaction, s.notify_reminder, s.sim_biometric
              FROM users u JOIN roles r ON r.id=u.role_id JOIN user_settings s ON s.user_id=u.id WHERE u.id=?', [$_SESSION['uid']]);
    $dev = row('SELECT * FROM devices WHERE id=? AND user_id=?', [$_SESSION['device_id'] ?? 0, $_SESSION['uid']]);
    if (!$r || $r['status'] !== 'active' || !$dev || (int)$dev['revoked'] === 1) { $_SESSION = []; return null; }
    $r['device_new'] = strtotime($dev['first_seen']) > time() - 1800 && (int)$dev['trusted'] === 0;
    return $u = $r;
  }
  static function verifyPin(int $uid, string $pin): void {
    if (!rate_limit("pin:$uid", 5, 600)) throw new AppError('Too many incorrect PIN attempts. Please wait 10 minutes.');
    $h = (string)val('SELECT pin_hash FROM users WHERE id=?', [$uid]);
    if (!valid_pin($pin) || !password_verify($pin, $h)) { audit($uid, 'pin_failed', 'user', $uid); throw new AppError('Incorrect transaction PIN.'); }
    q('DELETE FROM rate_limits WHERE bucket=?', ["pin:$uid"]);
  }
  static function verifyPassword(int $uid, string $pw): void {
    if (!rate_limit("pw:$uid", 5, 600)) throw new AppError('Too many attempts. Please wait 10 minutes.');
    $h = (string)val('SELECT password_hash FROM users WHERE id=?', [$uid]);
    if (!password_verify($pw, $h)) throw new AppError('Incorrect password.');
  }
  // --- API bearer tokens (for a future mobile client) ---
  static function issueToken(int $uid): string {
    $t = bin2hex(random_bytes(32));
    q('INSERT INTO api_tokens (user_id,token_hash,expires_at) VALUES (?,?,?)', [$uid, hash('sha256', $t), date('Y-m-d H:i:s', time() + 86400)]);
    return $t;
  }
  static function userFromToken(string $t): ?array {
    return row('SELECT u.*, r.name AS role FROM api_tokens k JOIN users u ON u.id=k.user_id JOIN roles r ON r.id=u.role_id
                WHERE k.token_hash=? AND k.revoked=0 AND k.expires_at>NOW() AND u.status="active"', [hash('sha256', $t)]);
  }
}
