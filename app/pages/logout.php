<?php
if (!empty($_SESSION['uid'])) audit((int)$_SESSION['uid'], 'logout', 'user', (int)$_SESSION['uid']);
$lang = $_SESSION['lang'] ?? 'en'; $_SESSION = []; session_regenerate_id(true); $_SESSION['lang'] = $lang;
redirect(url('login'));
