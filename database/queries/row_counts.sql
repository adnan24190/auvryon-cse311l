-- Exact row count of every table (every table has at least 3 rows)
SELECT table_name_ AS `table`, rows_ AS `rows` FROM (
  SELECT 'api_tokens' AS table_name_, COUNT(*) AS rows_ FROM `api_tokens`
UNION ALL
  SELECT 'approval_policies' AS table_name_, COUNT(*) AS rows_ FROM `approval_policies`
UNION ALL
  SELECT 'approval_policy_approvers' AS table_name_, COUNT(*) AS rows_ FROM `approval_policy_approvers`
UNION ALL
  SELECT 'approval_requests' AS table_name_, COUNT(*) AS rows_ FROM `approval_requests`
UNION ALL
  SELECT 'approval_votes' AS table_name_, COUNT(*) AS rows_ FROM `approval_votes`
UNION ALL
  SELECT 'audit_logs' AS table_name_, COUNT(*) AS rows_ FROM `audit_logs`
UNION ALL
  SELECT 'budgets' AS table_name_, COUNT(*) AS rows_ FROM `budgets`
UNION ALL
  SELECT 'checkout_sessions' AS table_name_, COUNT(*) AS rows_ FROM `checkout_sessions`
UNION ALL
  SELECT 'dependent_restrictions' AS table_name_, COUNT(*) AS rows_ FROM `dependent_restrictions`
UNION ALL
  SELECT 'dependents' AS table_name_, COUNT(*) AS rows_ FROM `dependents`
UNION ALL
  SELECT 'devices' AS table_name_, COUNT(*) AS rows_ FROM `devices`
UNION ALL
  SELECT 'dispute_evidence' AS table_name_, COUNT(*) AS rows_ FROM `dispute_evidence`
UNION ALL
  SELECT 'disputes' AS table_name_, COUNT(*) AS rows_ FROM `disputes`
UNION ALL
  SELECT 'escrow_milestones' AS table_name_, COUNT(*) AS rows_ FROM `escrow_milestones`
UNION ALL
  SELECT 'escrows' AS table_name_, COUNT(*) AS rows_ FROM `escrows`
UNION ALL
  SELECT 'exchange_rates' AS table_name_, COUNT(*) AS rows_ FROM `exchange_rates`
UNION ALL
  SELECT 'expense_groups' AS table_name_, COUNT(*) AS rows_ FROM `expense_groups`
UNION ALL
  SELECT 'expense_splits' AS table_name_, COUNT(*) AS rows_ FROM `expense_splits`
UNION ALL
  SELECT 'group_expenses' AS table_name_, COUNT(*) AS rows_ FROM `group_expenses`
UNION ALL
  SELECT 'group_members' AS table_name_, COUNT(*) AS rows_ FROM `group_members`
UNION ALL
  SELECT 'group_settlements' AS table_name_, COUNT(*) AS rows_ FROM `group_settlements`
UNION ALL
  SELECT 'idempotency_keys' AS table_name_, COUNT(*) AS rows_ FROM `idempotency_keys`
UNION ALL
  SELECT 'invoice_items' AS table_name_, COUNT(*) AS rows_ FROM `invoice_items`
UNION ALL
  SELECT 'invoice_payments' AS table_name_, COUNT(*) AS rows_ FROM `invoice_payments`
UNION ALL
  SELECT 'invoices' AS table_name_, COUNT(*) AS rows_ FROM `invoices`
UNION ALL
  SELECT 'iou_repayments' AS table_name_, COUNT(*) AS rows_ FROM `iou_repayments`
UNION ALL
  SELECT 'ious' AS table_name_, COUNT(*) AS rows_ FROM `ious`
UNION ALL
  SELECT 'ledger_accounts' AS table_name_, COUNT(*) AS rows_ FROM `ledger_accounts`
UNION ALL
  SELECT 'ledger_entries' AS table_name_, COUNT(*) AS rows_ FROM `ledger_entries`
UNION ALL
  SELECT 'merchants' AS table_name_, COUNT(*) AS rows_ FROM `merchants`
UNION ALL
  SELECT 'money_rules' AS table_name_, COUNT(*) AS rows_ FROM `money_rules`
UNION ALL
  SELECT 'notifications' AS table_name_, COUNT(*) AS rows_ FROM `notifications`
UNION ALL
  SELECT 'offline_queue' AS table_name_, COUNT(*) AS rows_ FROM `offline_queue`
UNION ALL
  SELECT 'offline_tokens' AS table_name_, COUNT(*) AS rows_ FROM `offline_tokens`
UNION ALL
  SELECT 'payment_requests' AS table_name_, COUNT(*) AS rows_ FROM `payment_requests`
UNION ALL
  SELECT 'pockets' AS table_name_, COUNT(*) AS rows_ FROM `pockets`
UNION ALL
  SELECT 'rate_limits' AS table_name_, COUNT(*) AS rows_ FROM `rate_limits`
UNION ALL
  SELECT 'recurring_items' AS table_name_, COUNT(*) AS rows_ FROM `recurring_items`
UNION ALL
  SELECT 'reminder_log' AS table_name_, COUNT(*) AS rows_ FROM `reminder_log`
UNION ALL
  SELECT 'risk_alerts' AS table_name_, COUNT(*) AS rows_ FROM `risk_alerts`
UNION ALL
  SELECT 'roles' AS table_name_, COUNT(*) AS rows_ FROM `roles`
UNION ALL
  SELECT 'rule_runs' AS table_name_, COUNT(*) AS rows_ FROM `rule_runs`
UNION ALL
  SELECT 'settings' AS table_name_, COUNT(*) AS rows_ FROM `settings`
UNION ALL
  SELECT 'timeline_events' AS table_name_, COUNT(*) AS rows_ FROM `timeline_events`
UNION ALL
  SELECT 'token_charges' AS table_name_, COUNT(*) AS rows_ FROM `token_charges`
UNION ALL
  SELECT 'transactions' AS table_name_, COUNT(*) AS rows_ FROM `transactions`
UNION ALL
  SELECT 'transfer_holds' AS table_name_, COUNT(*) AS rows_ FROM `transfer_holds`
UNION ALL
  SELECT 'user_settings' AS table_name_, COUNT(*) AS rows_ FROM `user_settings`
UNION ALL
  SELECT 'users' AS table_name_, COUNT(*) AS rows_ FROM `users`
UNION ALL
  SELECT 'virtual_tokens' AS table_name_, COUNT(*) AS rows_ FROM `virtual_tokens`
UNION ALL
  SELECT 'wallets' AS table_name_, COUNT(*) AS rows_ FROM `wallets`
) x ORDER BY rows_ DESC, table_name_;
