-- Auvryon schema (MySQL/MariaDB, InnoDB, utf8mb4). Import into database auvryon_db.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE roles (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  name VARCHAR(20) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  phone VARCHAR(20) NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  pin_hash VARCHAR(255) NOT NULL,
  role_id TINYINT UNSIGNED NOT NULL,
  status ENUM('active','suspended') NOT NULL DEFAULT 'active',
  failed_logins INT NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE user_settings (
  user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
  language ENUM('en','bn') NOT NULL DEFAULT 'en',
  hide_balance TINYINT(1) NOT NULL DEFAULT 0,
  notify_login TINYINT(1) NOT NULL DEFAULT 1,
  notify_transaction TINYINT(1) NOT NULL DEFAULT 1,
  notify_reminder TINYINT(1) NOT NULL DEFAULT 1,
  sim_biometric TINYINT(1) NOT NULL DEFAULT 0,
  CONSTRAINT fk_us_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE devices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  device_hash CHAR(64) NOT NULL,
  label VARCHAR(150) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  trusted TINYINT(1) NOT NULL DEFAULT 0,
  revoked TINYINT(1) NOT NULL DEFAULT 0,
  first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_device (user_id, device_hash),
  CONSTRAINT fk_dev_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE api_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  revoked TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tok_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE rate_limits (
  bucket VARCHAR(120) NOT NULL PRIMARY KEY,
  hits INT NOT NULL DEFAULT 0,
  window_start DATETIME NOT NULL
) ENGINE=InnoDB;

CREATE TABLE settings (
  skey VARCHAR(60) NOT NULL PRIMARY KEY,
  svalue VARCHAR(255) NOT NULL,
  description VARCHAR(200) NOT NULL DEFAULT ''
) ENGINE=InnoDB;

CREATE TABLE exchange_rates (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  base_currency CHAR(3) NOT NULL,
  quote_currency CHAR(3) NOT NULL,
  rate DECIMAL(18,6) NOT NULL,
  as_of DATE NOT NULL,
  source VARCHAR(80) NOT NULL DEFAULT 'SIMULATED demonstration rate',
  UNIQUE KEY uq_rate (base_currency, quote_currency, as_of)
) ENGINE=InnoDB;

CREATE TABLE ledger_accounts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL UNIQUE,
  kind ENUM('wallet','pocket','hold','escrow','fee','mint','fx','external') NOT NULL,
  currency CHAR(3) NOT NULL,
  name VARCHAR(100) NOT NULL,
  balance DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  CONSTRAINT chk_nonneg CHECK (balance >= 0 OR kind IN ('mint','fx'))
) ENGINE=InnoDB;

CREATE TABLE wallets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  currency CHAR(3) NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL UNIQUE,
  frozen TINYINT(1) NOT NULL DEFAULT 0,
  frozen_by ENUM('none','user','admin') NOT NULL DEFAULT 'none',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_wallet_user_cur (user_id, currency),
  CONSTRAINT fk_w_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_w_acc FOREIGN KEY (account_id) REFERENCES ledger_accounts(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE transactions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ref CHAR(14) NOT NULL UNIQUE,
  type ENUM('topup','transfer','request_payment','merchant_payment','settlement','iou_repayment','invoice_payment','bill_payment','refund','escrow_fund','escrow_release','escrow_refund','conversion','pocket_deposit','pocket_withdraw','rule_transfer','round_up','offline_payment','token_payment') NOT NULL,
  status ENUM('pending','held','completed','cancelled','failed','refunded','disputed') NOT NULL,
  from_wallet_id BIGINT UNSIGNED NULL,
  to_wallet_id BIGINT UNSIGNED NULL,
  amount DECIMAL(18,2) NOT NULL,
  fee DECIMAL(18,2) NOT NULL DEFAULT 0.00,
  currency CHAR(3) NOT NULL,
  category VARCHAR(30) NOT NULL DEFAULT 'general',
  note VARCHAR(255) NOT NULL DEFAULT '',
  created_by BIGINT UNSIGNED NOT NULL,
  refund_of BIGINT UNSIGNED NULL UNIQUE,
  ref_table VARCHAR(30) NULL,
  ref_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  CONSTRAINT chk_amount_pos CHECK (amount > 0),
  INDEX ix_tx_from (from_wallet_id, created_at),
  INDEX ix_tx_to (to_wallet_id, created_at),
  INDEX ix_tx_type_status (type, status),
  CONSTRAINT fk_tx_from FOREIGN KEY (from_wallet_id) REFERENCES wallets(id) ON DELETE RESTRICT,
  CONSTRAINT fk_tx_to FOREIGN KEY (to_wallet_id) REFERENCES wallets(id) ON DELETE RESTRICT,
  CONSTRAINT fk_tx_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_tx_refund FOREIGN KEY (refund_of) REFERENCES transactions(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE ledger_entries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  txn_id BIGINT UNSIGNED NOT NULL,
  phase VARCHAR(20) NOT NULL DEFAULT 'post',
  account_id BIGINT UNSIGNED NOT NULL,
  direction ENUM('D','C') NOT NULL,
  amount DECIMAL(18,2) NOT NULL,
  currency CHAR(3) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_entry_pos CHECK (amount > 0),
  INDEX ix_le_acc (account_id, id),
  INDEX ix_le_txn (txn_id, phase),
  CONSTRAINT fk_le_tx FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_le_acc FOREIGN KEY (account_id) REFERENCES ledger_accounts(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE idempotency_keys (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  idem_key VARCHAR(64) NOT NULL,
  action VARCHAR(40) NOT NULL,
  txn_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_idem (user_id, idem_key, action),
  CONSTRAINT fk_idem_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE transfer_holds (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  txn_id BIGINT UNSIGNED NOT NULL UNIQUE,
  release_at DATETIME NOT NULL,
  status ENUM('held','released','cancelled') NOT NULL DEFAULT 'held',
  reason VARCHAR(120) NOT NULL,
  resolved_at DATETIME NULL,
  INDEX ix_hold_status (status, release_at),
  CONSTRAINT fk_hold_tx FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE payment_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  requester_id BIGINT UNSIGNED NOT NULL,
  payer_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(18,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'BDT',
  note VARCHAR(255) NOT NULL DEFAULT '',
  status ENUM('pending','paid','declined','cancelled') NOT NULL DEFAULT 'pending',
  txn_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_req_pos CHECK (amount > 0),
  CONSTRAINT fk_pr_req FOREIGN KEY (requester_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_pr_payer FOREIGN KEY (payer_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_pr_tx FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE risk_alerts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  txn_id BIGINT UNSIGNED NULL,
  alert_type VARCHAR(40) NOT NULL,
  severity ENUM('low','medium','high') NOT NULL,
  message VARCHAR(255) NOT NULL,
  status ENUM('open','reviewed') NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ra_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  category ENUM('login','transaction','reminder','security','system') NOT NULL,
  title VARCHAR(150) NOT NULL,
  body VARCHAR(400) NOT NULL DEFAULT '',
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX ix_n_user (user_id, is_read),
  CONSTRAINT fk_n_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(60) NOT NULL,
  entity VARCHAR(40) NOT NULL DEFAULT '',
  entity_id BIGINT UNSIGNED NULL,
  ip_address VARCHAR(45) NOT NULL DEFAULT '',
  details VARCHAR(400) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX ix_a_user (user_id, created_at),
  INDEX ix_a_action (action)
) ENGINE=InnoDB;

CREATE TABLE timeline_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  entity_type ENUM('escrow','dispute','invoice','transfer','approval') NOT NULL,
  entity_id BIGINT UNSIGNED NOT NULL,
  actor_id BIGINT UNSIGNED NULL,
  event VARCHAR(60) NOT NULL,
  note VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX ix_te (entity_type, entity_id)
) ENGINE=InnoDB;

CREATE TABLE merchants (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL UNIQUE,
  business_name VARCHAR(120) NOT NULL,
  category VARCHAR(30) NOT NULL DEFAULT 'shopping',
  status ENUM('active','suspended') NOT NULL DEFAULT 'active',
  CONSTRAINT fk_m_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE checkout_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  merchant_id BIGINT UNSIGNED NOT NULL,
  ref CHAR(16) NOT NULL UNIQUE,
  amount DECIMAL(18,2) NOT NULL,
  note VARCHAR(200) NOT NULL DEFAULT '',
  status ENUM('open','paid','expired','cancelled') NOT NULL DEFAULT 'open',
  expires_at DATETIME NOT NULL,
  paid_txn_id BIGINT UNSIGNED NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cs_m FOREIGN KEY (merchant_id) REFERENCES merchants(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cs_tx FOREIGN KEY (paid_txn_id) REFERENCES transactions(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE expense_groups (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  group_type ENUM('rent','trip','restaurant','groceries','subscription','other') NOT NULL DEFAULT 'other',
  created_by BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_g_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE group_members (
  group_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (group_id, user_id),
  CONSTRAINT fk_gm_g FOREIGN KEY (group_id) REFERENCES expense_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_gm_u FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE group_expenses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  group_id BIGINT UNSIGNED NOT NULL,
  paid_by BIGINT UNSIGNED NOT NULL,
  description VARCHAR(150) NOT NULL,
  category VARCHAR(30) NOT NULL DEFAULT 'general',
  amount DECIMAL(18,2) NOT NULL,
  split_type ENUM('equal','custom','percent') NOT NULL,
  expense_date DATE NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_ge_pos CHECK (amount > 0),
  CONSTRAINT fk_ge_g FOREIGN KEY (group_id) REFERENCES expense_groups(id) ON DELETE CASCADE,
  CONSTRAINT fk_ge_u FOREIGN KEY (paid_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE expense_splits (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  expense_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  share_amount DECIMAL(18,2) NOT NULL,
  share_percent DECIMAL(6,3) NULL,
  UNIQUE KEY uq_split (expense_id, user_id),
  CONSTRAINT fk_es_e FOREIGN KEY (expense_id) REFERENCES group_expenses(id) ON DELETE CASCADE,
  CONSTRAINT fk_es_u FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE group_settlements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  group_id BIGINT UNSIGNED NOT NULL,
  from_user BIGINT UNSIGNED NOT NULL,
  to_user BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(18,2) NOT NULL,
  txn_id BIGINT UNSIGNED NOT NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_gs_g FOREIGN KEY (group_id) REFERENCES expense_groups(id) ON DELETE RESTRICT,
  CONSTRAINT fk_gs_f FOREIGN KEY (from_user) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_gs_t FOREIGN KEY (to_user) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_gs_tx FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE budgets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  group_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  category VARCHAR(30) NOT NULL,
  monthly_limit DECIMAL(18,2) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_budget (user_id, group_id, category),
  CONSTRAINT fk_b_u FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE ious (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  lender_id BIGINT UNSIGNED NOT NULL,
  borrower_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(18,2) NOT NULL,
  note VARCHAR(200) NOT NULL DEFAULT '',
  due_date DATE NOT NULL,
  status ENUM('open','partial','settled','cancelled') NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_iou_pos CHECK (amount > 0),
  CONSTRAINT fk_iou_l FOREIGN KEY (lender_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_iou_b FOREIGN KEY (borrower_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE iou_repayments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  iou_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(18,2) NOT NULL,
  txn_id BIGINT UNSIGNED NOT NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ir_i FOREIGN KEY (iou_id) REFERENCES ious(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ir_t FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE recurring_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  kind ENUM('income','bill','subscription','rent') NOT NULL,
  name VARCHAR(100) NOT NULL,
  merchant VARCHAR(100) NOT NULL DEFAULT '',
  amount DECIMAL(18,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'BDT',
  frequency ENUM('weekly','monthly','yearly') NOT NULL DEFAULT 'monthly',
  next_due DATE NOT NULL,
  category VARCHAR(30) NOT NULL DEFAULT 'bills',
  status ENUM('active','paused','cancelled') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT chk_ri_pos CHECK (amount > 0),
  INDEX ix_ri (user_id, status, next_due),
  CONSTRAINT fk_ri_u FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE reminder_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  ref_table VARCHAR(30) NOT NULL,
  ref_id BIGINT UNSIGNED NOT NULL,
  due_date DATE NOT NULL,
  sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rem (ref_table, ref_id, due_date),
  CONSTRAINT fk_rl_u FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE virtual_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  wallet_id BIGINT UNSIGNED NOT NULL,
  merchant_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  token_last4 CHAR(4) NOT NULL,
  per_charge_limit DECIMAL(18,2) NOT NULL,
  monthly_limit DECIMAL(18,2) NOT NULL,
  status ENUM('active','paused','cancelled') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_vt_u FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_vt_w FOREIGN KEY (wallet_id) REFERENCES wallets(id) ON DELETE RESTRICT,
  CONSTRAINT fk_vt_m FOREIGN KEY (merchant_id) REFERENCES merchants(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE token_charges (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  token_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(18,2) NOT NULL,
  status ENUM('approved','declined') NOT NULL,
  reason VARCHAR(120) NOT NULL DEFAULT '',
  txn_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tc_t FOREIGN KEY (token_id) REFERENCES virtual_tokens(id) ON DELETE RESTRICT,
  CONSTRAINT fk_tc_tx FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE pockets (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL,
  pocket_kind ENUM('education','travel','emergency','monthly_expense','custom') NOT NULL,
  target_amount DECIMAL(18,2) NOT NULL,
  target_date DATE NULL,
  account_id BIGINT UNSIGNED NOT NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_p_u FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_p_a FOREIGN KEY (account_id) REFERENCES ledger_accounts(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE money_rules (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(100) NOT NULL,
  rule_type ENUM('scheduled_transfer','income_allocation','round_up','low_balance_alert') NOT NULL,
  target_user_id BIGINT UNSIGNED NULL,
  target_pocket_id BIGINT UNSIGNED NULL,
  amount DECIMAL(18,2) NULL,
  percent DECIMAL(5,2) NULL,
  round_to INT NULL,
  threshold DECIMAL(18,2) NULL,
  frequency ENUM('daily','weekly','monthly') NULL,
  next_run DATE NULL,
  status ENUM('active','paused','failed') NOT NULL DEFAULT 'active',
  fail_count INT NOT NULL DEFAULT 0,
  last_message VARCHAR(200) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_mr_u FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_mr_tu FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_mr_tp FOREIGN KEY (target_pocket_id) REFERENCES pockets(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE rule_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  rule_id BIGINT UNSIGNED NOT NULL,
  run_key VARCHAR(40) NOT NULL,
  status ENUM('success','failed','skipped') NOT NULL,
  message VARCHAR(200) NOT NULL DEFAULT '',
  txn_id BIGINT UNSIGNED NULL,
  ran_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_run (rule_id, run_key),
  CONSTRAINT fk_rr_r FOREIGN KEY (rule_id) REFERENCES money_rules(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE dependents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  guardian_id BIGINT UNSIGNED NOT NULL,
  dependent_id BIGINT UNSIGNED NOT NULL,
  per_txn_limit DECIMAL(18,2) NOT NULL,
  daily_limit DECIMAL(18,2) NOT NULL,
  status ENUM('active','paused') NOT NULL DEFAULT 'active',
  UNIQUE KEY uq_dep (guardian_id, dependent_id),
  CONSTRAINT fk_d_g FOREIGN KEY (guardian_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_d_d FOREIGN KEY (dependent_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE dependent_restrictions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  dependent_row_id BIGINT UNSIGNED NOT NULL,
  restriction_type ENUM('category','merchant') NOT NULL,
  value VARCHAR(80) NOT NULL,
  CONSTRAINT fk_dr_d FOREIGN KEY (dependent_row_id) REFERENCES dependents(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE approval_policies (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  wallet_id BIGINT UNSIGNED NOT NULL UNIQUE,
  min_amount DECIMAL(18,2) NOT NULL,
  required_approvals INT NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  CONSTRAINT fk_ap_w FOREIGN KEY (wallet_id) REFERENCES wallets(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ap_u FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE approval_policy_approvers (
  policy_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (policy_id, user_id),
  CONSTRAINT fk_apa_p FOREIGN KEY (policy_id) REFERENCES approval_policies(id) ON DELETE CASCADE,
  CONSTRAINT fk_apa_u FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE approval_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  wallet_id BIGINT UNSIGNED NOT NULL,
  requested_by BIGINT UNSIGNED NOT NULL,
  to_wallet_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(18,2) NOT NULL,
  category VARCHAR(30) NOT NULL DEFAULT 'general',
  note VARCHAR(255) NOT NULL DEFAULT '',
  status ENUM('pending','executed','rejected','failed') NOT NULL DEFAULT 'pending',
  txn_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_arq_w FOREIGN KEY (wallet_id) REFERENCES wallets(id) ON DELETE RESTRICT,
  CONSTRAINT fk_arq_tw FOREIGN KEY (to_wallet_id) REFERENCES wallets(id) ON DELETE RESTRICT,
  CONSTRAINT fk_arq_u FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_arq_tx FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE approval_votes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  request_id BIGINT UNSIGNED NOT NULL,
  approver_id BIGINT UNSIGNED NOT NULL,
  decision ENUM('approve','reject') NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_vote (request_id, approver_id),
  CONSTRAINT fk_av_r FOREIGN KEY (request_id) REFERENCES approval_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_av_u FOREIGN KEY (approver_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE escrows (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ref CHAR(12) NOT NULL UNIQUE,
  buyer_id BIGINT UNSIGNED NOT NULL,
  seller_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(150) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'BDT',
  status ENUM('open','completed','disputed','cancelled') NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_e_b FOREIGN KEY (buyer_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_e_s FOREIGN KEY (seller_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE escrow_milestones (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  escrow_id BIGINT UNSIGNED NOT NULL,
  seq INT NOT NULL,
  title VARCHAR(150) NOT NULL,
  amount DECIMAL(18,2) NOT NULL,
  due_date DATE NULL,
  status ENUM('pending','funded','submitted','released','refunded','disputed') NOT NULL DEFAULT 'pending',
  funded_txn_id BIGINT UNSIGNED NULL UNIQUE,
  closing_txn_id BIGINT UNSIGNED NULL UNIQUE,
  UNIQUE KEY uq_ms (escrow_id, seq),
  CONSTRAINT chk_ms_pos CHECK (amount > 0),
  CONSTRAINT fk_ms_e FOREIGN KEY (escrow_id) REFERENCES escrows(id) ON DELETE CASCADE,
  CONSTRAINT fk_ms_f FOREIGN KEY (funded_txn_id) REFERENCES transactions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ms_c FOREIGN KEY (closing_txn_id) REFERENCES transactions(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE disputes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ref CHAR(10) NOT NULL UNIQUE,
  raised_by BIGINT UNSIGNED NOT NULL,
  txn_id BIGINT UNSIGNED NULL,
  milestone_id BIGINT UNSIGNED NULL,
  reason VARCHAR(255) NOT NULL,
  status ENUM('open','under_review','resolved','rejected') NOT NULL DEFAULT 'open',
  resolution ENUM('none','refund','release') NOT NULL DEFAULT 'none',
  admin_id BIGINT UNSIGNED NULL,
  decision_note VARCHAR(255) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  CONSTRAINT fk_di_u FOREIGN KEY (raised_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_di_t FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_di_m FOREIGN KEY (milestone_id) REFERENCES escrow_milestones(id) ON DELETE RESTRICT,
  CONSTRAINT fk_di_a FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE dispute_evidence (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  dispute_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  note VARCHAR(400) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_dev_d FOREIGN KEY (dispute_id) REFERENCES disputes(id) ON DELETE CASCADE,
  CONSTRAINT fk_dev_u FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE invoices (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  number VARCHAR(20) NOT NULL UNIQUE,
  issuer_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NULL,
  customer_name VARCHAR(100) NOT NULL,
  title VARCHAR(150) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'BDT',
  due_date DATE NOT NULL,
  status ENUM('draft','sent','partial','paid','overdue','cancelled') NOT NULL DEFAULT 'sent',
  link_token CHAR(32) NOT NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_inv_i FOREIGN KEY (issuer_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_inv_c FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE invoice_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  invoice_id BIGINT UNSIGNED NOT NULL,
  description VARCHAR(150) NOT NULL,
  quantity DECIMAL(10,2) NOT NULL,
  unit_price DECIMAL(18,2) NOT NULL,
  CONSTRAINT fk_ii_i FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE invoice_payments (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  invoice_id BIGINT UNSIGNED NOT NULL,
  txn_id BIGINT UNSIGNED NOT NULL UNIQUE,
  payer_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(18,2) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ip_i FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ip_t FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ip_u FOREIGN KEY (payer_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE offline_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  token_id CHAR(32) NOT NULL UNIQUE,
  user_id BIGINT UNSIGNED NOT NULL,
  payee_user_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(18,2) NOT NULL,
  expires_at DATETIME NOT NULL,
  status ENUM('issued','settled','rejected','expired') NOT NULL DEFAULT 'issued',
  txn_id BIGINT UNSIGNED NULL UNIQUE,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ot_u FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ot_p FOREIGN KEY (payee_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ot_t FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE offline_queue (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  merchant_user_id BIGINT UNSIGNED NOT NULL,
  token_text TEXT NOT NULL,
  status ENUM('queued','settled','rejected') NOT NULL DEFAULT 'queued',
  message VARCHAR(200) NOT NULL DEFAULT 'Queued - NOT settled',
  txn_id BIGINT UNSIGNED NULL,
  queued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  synced_at DATETIME NULL,
  CONSTRAINT fk_oq_u FOREIGN KEY (merchant_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_oq_t FOREIGN KEY (txn_id) REFERENCES transactions(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS=1;
