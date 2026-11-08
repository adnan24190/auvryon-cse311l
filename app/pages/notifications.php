<?php
$uid = (int)$u['id'];
handle('read_all', function () use ($uid) { q('UPDATE notifications SET is_read=1 WHERE user_id=?', [$uid]); return 'All marked as read.'; });
$ns = rows('SELECT * FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 60', [$uid]); $o = '';
foreach ($ns as $n) $o .= '<div style="padding:12px 0;border-top:1px solid var(--line)"><div class="row" style="align-items:center"><div><strong>' . e($n['title']) . '</strong> ' . ($n['is_read'] ? '' : '<span class="badge b-info">new</span>') . '<div>' . e($n['body']) . '</div></div><div class="fixed hint">' . e(fdate($n['created_at'])) . ' &middot; ' . e($n['category']) . '</div></div></div>';
echo page_head('Notifications', 'Logins, payments, reminders and security events. Adjust what you receive in Settings.', form_start('read_all', 'class="inline"') . btn('Mark read', 'secondary sm') . '</form>') . card('', $o ?: empty_state('No notifications.', 'bell'));
q('UPDATE notifications SET is_read=1 WHERE user_id=? AND id IN (' . implode(',', array_map('intval', array_column($ns, 'id')) ?: [0]) . ')', [$uid]);
