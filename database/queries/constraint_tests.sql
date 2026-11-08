-- Constraint demonstration for the in-class review: every statement below is REJECTED by MySQL.
-- Expected errors were captured from MySQL 8.0.46 against the delivered data (nothing is changed).

-- Duplicate e-mail rejected by UNIQUE (users.email)
-- Expected: ERROR 1062 (23000) at line 1: Duplicate entry 'tahsin@auvryon.test' for key 'users.email'
INSERT INTO users (name,email,password_hash,pin_hash,role_id) VALUES ('Duplicate','tahsin@auvryon.test','x','x',1);

-- Foreign key: requester must exist (payment_requests.requester_id -> users.id)
-- Expected: ERROR 1452 (23000) at line 1: Cannot add or update a child row: a foreign key constraint fails (`auvryon_db`.`payment_requests`, CONSTRAINT `fk_pr_req` FOREIGN KEY (`requester_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT)
INSERT INTO payment_requests (requester_id,payer_id,amount,currency,note) VALUES (99999,1,10,'BDT','ghost user');

-- CHECK: a wallet balance can never be negative (ledger_accounts.chk_nonneg)
-- Expected: ERROR 3819 (HY000) at line 1: Check constraint 'chk_nonneg' is violated.
UPDATE ledger_accounts SET balance = -1 WHERE code = 'W-1-BDT';

-- CHECK: a transaction amount must be greater than zero (transactions.chk_amount_pos)
-- Expected: ERROR 3819 (HY000) at line 1: Check constraint 'chk_amount_pos' is violated.
INSERT INTO transactions (ref,type,status,amount,currency,created_by) VALUES ('CHK000000001','transfer','completed',0,'BDT',1);

-- CHECK: ledger entry amounts must be positive (ledger_entries.chk_entry_pos)
-- Expected: ERROR 3819 (HY000) at line 1: Check constraint 'chk_entry_pos' is violated.
INSERT INTO ledger_entries (txn_id,phase,account_id,direction,amount,currency) VALUES (1,'post',1,'D',-5,'BDT');

-- NOT NULL: a user must have a name
-- Expected: ERROR 1364 (HY000) at line 1: Field 'name' doesn't have a default value
INSERT INTO users (email,password_hash,pin_hash,role_id) VALUES ('noname@example.com','x','x',1);

-- ENUM: only defined transaction states are accepted
-- Expected: ERROR 1265 (01000) at line 1: Data truncated for column 'status' at row 1
UPDATE transactions SET status = 'weird' WHERE id = 1;

-- RESTRICT: a user with wallets and history cannot be deleted
-- Expected: ERROR 1451 (23000) at line 1: Cannot delete or update a parent row: a foreign key constraint fails (`auvryon_db`.`approval_policies`, CONSTRAINT `fk_ap_u` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT)
DELETE FROM users WHERE id = 1;

-- UNIQUE refund_of: a transaction cannot be refunded twice
-- Expected: ERROR 1062 (23000) at line 1: Duplicate entry '16' for key 'transactions.refund_of'
INSERT INTO transactions (ref,type,status,amount,currency,created_by,refund_of) SELECT 'DUPREF000001','refund','completed',1,'BDT',1,refund_of FROM transactions WHERE refund_of IS NOT NULL LIMIT 1;

-- UNIQUE (user_id, idem_key, action): the same payment request cannot be stored twice
-- Expected: ERROR 1062 (23000) at line 1: Duplicate entry '1-APR4-transfer' for key 'idempotency_keys.uq_idem'
INSERT INTO idempotency_keys (user_id,idem_key,action) SELECT user_id,idem_key,action FROM idempotency_keys LIMIT 1;

-- UNIQUE (request_id, approver_id): one vote per approver
-- Expected: ERROR 1062 (23000) at line 1: Duplicate entry '1-3' for key 'approval_votes.uq_vote'
INSERT INTO approval_votes (request_id,approver_id,decision) SELECT request_id,approver_id,'approve' FROM approval_votes LIMIT 1;
