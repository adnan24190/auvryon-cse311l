-- Auvryon - queries for the 5-minute in-class walkthrough (run in MySQL Workbench or the mysql client)
USE auvryon_db;

-- 1. Every table and how many sample rows it holds (all 51 tables are filled)
SELECT table_name, table_rows FROM information_schema.tables WHERE table_schema = 'auvryon_db' ORDER BY table_rows DESC;   -- approximate; exact counts: sql/row_counts.sql

-- 2. Relationships in action: who paid whom (transactions -> wallets -> users, twice)
SELECT t.ref, t.type, fu.name AS payer, tu.name AS payee, t.amount, t.fee, t.status
FROM transactions t
JOIN wallets fw ON fw.id = t.from_wallet_id JOIN users fu ON fu.id = fw.user_id
JOIN wallets tw ON tw.id = t.to_wallet_id   JOIN users tu ON tu.id = tw.user_id
WHERE t.type IN ('transfer','merchant_payment','settlement') ORDER BY t.id DESC LIMIT 8;

-- 3. Double-entry ledger for one cancelled transfer: hold phase then cancel phase, debits = credits
SELECT t.ref, e.phase, a.code AS ledger_account, e.direction, e.amount
FROM ledger_entries e JOIN ledger_accounts a ON a.id = e.account_id JOIN transactions t ON t.id = e.txn_id
WHERE t.status = 'cancelled' ORDER BY e.id;

-- 4. Trial balance: total debits = total credits in each currency
SELECT currency, SUM(CASE direction WHEN 'D' THEN amount END) AS total_debits, SUM(CASE direction WHEN 'C' THEN amount END) AS total_credits FROM ledger_entries GROUP BY currency;

-- 5. Wallet balance equals the sum of its ledger entries (cached balance vs source of truth)
SELECT u.name, w.currency, a.balance AS wallet_balance, SUM(CASE e.direction WHEN 'C' THEN e.amount ELSE -e.amount END) AS sum_of_entries
FROM wallets w JOIN users u ON u.id = w.user_id JOIN ledger_accounts a ON a.id = w.account_id JOIN ledger_entries e ON e.account_id = a.id
WHERE u.email IN ('tahsin@auvryon.test','uchas@auvryon.test') GROUP BY w.id, u.name, w.currency, a.balance;

-- 6. Escrow: contract -> milestones -> status (one-to-many, weak entity)
SELECT e.title, b.name AS buyer, s.name AS seller, m.seq, m.title AS milestone, m.amount, m.status
FROM escrows e JOIN users b ON b.id = e.buyer_id JOIN users s ON s.id = e.seller_id JOIN escrow_milestones m ON m.escrow_id = e.id ORDER BY e.id, m.seq;

-- 7. Group finance: members, expenses and split type (many-to-many through group_members / expense_splits)
SELECT g.name AS grp, ge.description, ge.split_type, ge.amount, u.name AS member, es.share_amount
FROM expense_groups g JOIN group_expenses ge ON ge.group_id = g.id JOIN expense_splits es ON es.expense_id = ge.id JOIN users u ON u.id = es.user_id
WHERE g.name = 'Hostel Rent' ORDER BY ge.id, u.name;

-- 8. Aggregation: spending by category for one user
SELECT t.category, COUNT(*) AS payments, SUM(t.amount + t.fee) AS total FROM transactions t JOIN wallets w ON w.id = t.from_wallet_id JOIN users u ON u.id = w.user_id
WHERE u.email = 'tahsin@auvryon.test' AND t.status IN ('completed','held') AND t.type NOT IN ('topup','conversion','pocket_deposit','pocket_withdraw') GROUP BY t.category ORDER BY total DESC;
