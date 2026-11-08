-- Auvryon useful queries (run in phpMyAdmin > SQL, database auvryon_db). Amounts are DECIMAL(18,2).

-- Q1. Trial balance: debits must equal credits in every currency (double-entry invariant I1/I4)
SELECT currency, SUM(CASE direction WHEN 'D' THEN amount END) AS total_debits, SUM(CASE direction WHEN 'C' THEN amount END) AS total_credits FROM ledger_entries GROUP BY currency;

-- Q2. Every wallet: cached balance vs the sum of its ledger entries (invariant I2)
SELECT u.name, w.currency, a.balance AS cached_balance, COALESCE(SUM(CASE e.direction WHEN 'C' THEN e.amount ELSE -e.amount END),0) AS ledger_sum
FROM wallets w JOIN users u ON u.id=w.user_id JOIN ledger_accounts a ON a.id=w.account_id LEFT JOIN ledger_entries e ON e.account_id=a.id
GROUP BY w.id, u.name, w.currency, a.balance ORDER BY u.name, w.currency;

-- Q3. Balance explainer for one wallet: every ledger line with a running balance (window function; MariaDB 10.2+)
SELECT e.id, t.ref, t.type, e.phase, e.direction, e.amount, SUM(CASE e.direction WHEN 'C' THEN e.amount ELSE -e.amount END) OVER (ORDER BY e.id) AS running_balance
FROM ledger_entries e JOIN transactions t ON t.id=e.txn_id WHERE e.account_id=(SELECT account_id FROM wallets WHERE user_id=(SELECT id FROM users WHERE email='tahsin@auvryon.test') AND currency='BDT') ORDER BY e.id;

-- Q4. Fee revenue by transaction type (join + aggregation)
SELECT t.type, COUNT(*) AS txns, SUM(t.fee) AS fees FROM transactions t WHERE t.fee > 0 GROUP BY t.type ORDER BY fees DESC;

-- Q5. Spending by category for a user (reporting query behind the dashboard donut)
SELECT t.category, COUNT(*) AS payments, SUM(t.amount + t.fee) AS total FROM transactions t JOIN wallets w ON w.id=t.from_wallet_id JOIN users u ON u.id=w.user_id
WHERE u.email='tahsin@auvryon.test' AND t.status IN ('completed','held') AND t.type IN ('transfer','request_payment','merchant_payment','settlement','iou_repayment','invoice_payment','bill_payment','token_payment','offline_payment','rule_transfer') GROUP BY t.category ORDER BY total DESC;

-- Q6. Group net position per member (paid - owed + settlements out - settlements in)
SELECT u.name, COALESCE(p.paid,0) AS paid, COALESCE(s.owed,0) AS share_owed, COALESCE(so.sent,0) AS settled_out, COALESCE(si.recv,0) AS settled_in,
       COALESCE(p.paid,0) - COALESCE(s.owed,0) + COALESCE(so.sent,0) - COALESCE(si.recv,0) AS net
FROM group_members m JOIN users u ON u.id=m.user_id
LEFT JOIN (SELECT paid_by uid, SUM(amount) paid FROM group_expenses WHERE group_id=1 GROUP BY paid_by) p ON p.uid=u.id
LEFT JOIN (SELECT s.user_id uid, SUM(s.share_amount) owed FROM expense_splits s JOIN group_expenses e ON e.id=s.expense_id WHERE e.group_id=1 GROUP BY s.user_id) s ON s.uid=u.id
LEFT JOIN (SELECT from_user uid, SUM(amount) sent FROM group_settlements WHERE group_id=1 GROUP BY from_user) so ON so.uid=u.id
LEFT JOIN (SELECT to_user uid, SUM(amount) recv FROM group_settlements WHERE group_id=1 GROUP BY to_user) si ON si.uid=u.id WHERE m.group_id=1 ORDER BY net DESC;

-- Q7. Invoice reconciliation: total, paid and outstanding per invoice
SELECT i.number, i.status, (SELECT ROUND(SUM(quantity*unit_price),2) FROM invoice_items WHERE invoice_id=i.id) AS total,
       COALESCE((SELECT SUM(amount) FROM invoice_payments WHERE invoice_id=i.id),0) AS paid FROM invoices i;

-- Q8. Escrow: money held per status and the escrow ledger account (invariant I6)
SELECT m.status, COUNT(*) AS milestones, SUM(m.amount) AS amount FROM escrow_milestones m GROUP BY m.status;
SELECT code, balance FROM ledger_accounts WHERE kind='escrow';

-- Q9. Transfers currently in a cancellation window (invariant I5 source)
SELECT t.ref, t.amount, t.fee, h.release_at, h.reason FROM transfer_holds h JOIN transactions t ON t.id=h.txn_id WHERE h.status='held' ORDER BY h.release_at;

-- Q10. Outstanding IOUs with repayments and days to due date
SELECT l.name AS lender, b.name AS borrower, i.amount, COALESCE(SUM(r.amount),0) AS repaid, i.amount - COALESCE(SUM(r.amount),0) AS left_to_pay, DATEDIFF(i.due_date, CURDATE()) AS days_to_due, i.status
FROM ious i JOIN users l ON l.id=i.lender_id JOIN users b ON b.id=i.borrower_id LEFT JOIN iou_repayments r ON r.iou_id=i.id GROUP BY i.id;

-- Q11. Rule execution log with outcome (idempotency evidence: one row per rule and run_key)
SELECT r.name, l.run_key, l.status, l.message FROM rule_runs l JOIN money_rules r ON r.id=l.rule_id ORDER BY l.id;

-- Q12. Duplicate-refund guard: transactions refunded more than once (must return zero rows)
SELECT refund_of, COUNT(*) FROM transactions WHERE refund_of IS NOT NULL GROUP BY refund_of HAVING COUNT(*) > 1;
