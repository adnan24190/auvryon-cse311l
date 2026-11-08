-- Reference data required for the app to run (roles, system ledger accounts, settings, demo FX rates)
INSERT INTO roles (id,name) VALUES (1,'user'),(2,'merchant'),(3,'admin');

INSERT INTO ledger_accounts (code,kind,currency,name) VALUES
('SYS-MINT-BDT','mint','BDT','Simulated funding source (BDT)'),
('SYS-MINT-USD','mint','USD','Simulated funding source (USD)'),
('SYS-HOLD-BDT','hold','BDT','Held transfers (BDT)'),
('SYS-HOLD-USD','hold','USD','Held transfers (USD)'),
('SYS-ESCROW-BDT','escrow','BDT','Escrow pool (BDT)'),
('SYS-ESCROW-USD','escrow','USD','Escrow pool (USD)'),
('SYS-FEE-BDT','fee','BDT','Fee revenue (BDT)'),
('SYS-FEE-USD','fee','USD','Fee revenue (USD)'),
('SYS-FX-BDT','fx','BDT','FX conversion pool (BDT)'),
('SYS-FX-USD','fx','USD','FX conversion pool (USD)'),
('SYS-EXT-BDT','external','BDT','Simulated external payees (bills) (BDT)'),
('SYS-EXT-USD','external','USD','Simulated external payees (bills) (USD)');

INSERT INTO settings (skey,svalue,description) VALUES
('fee_percent','0.50','Transfer fee percent charged to the sender'),
('fee_min_BDT','1.00','Minimum transfer fee (BDT)'),
('fee_min_USD','0.05','Minimum transfer fee (USD)'),
('merchant_fee_percent','1.00','Merchant fee percent deducted from the merchant payout'),
('fx_spread_percent','0.50','Conversion fee percent (simulated)'),
('per_txn_limit_BDT','50000.00','Per-transaction limit (BDT)'),
('per_txn_limit_USD','500.00','Per-transaction limit (USD)'),
('daily_limit_BDT','100000.00','Daily outgoing limit (BDT)'),
('daily_limit_USD','1000.00','Daily outgoing limit (USD)'),
('high_value_BDT','10000.00','High-value threshold that triggers a cancellation window (BDT)'),
('high_value_USD','100.00','High-value threshold that triggers a cancellation window (USD)'),
('cancel_window_seconds','300','Cancellation window for first-time or high-value recipients (seconds)'),
('safety_buffer_BDT','500.00','Safety buffer kept out of safe-to-spend (BDT)'),
('offline_token_ttl_seconds','900','Lifetime of a simulated offline QR token (seconds)'),
('transfer_rate_limit','10','Max transfers per user per minute');

INSERT INTO exchange_rates (base_currency,quote_currency,rate,as_of,source) VALUES
('USD','BDT',120.000000,'2026-10-01','SIMULATED demonstration rate (not a market rate)');
