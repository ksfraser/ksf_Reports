-- Reports Module Database Schema

-- Report Definitions Table
CREATE TABLE IF NOT EXISTS `report_definitions` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(100) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `category` VARCHAR(50) NOT NULL,
  `default_parameters` JSON,
  `required_permissions` JSON,
  `timeout_seconds` INT(11) DEFAULT 300,
  `allow_export` TINYINT(1) DEFAULT 1,
  `export_formats` JSON,
  `allow_scheduling` TINYINT(1) DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Report History Table
CREATE TABLE IF NOT EXISTS `report_history` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `report_code` VARCHAR(100) NOT NULL,
  `report_name` VARCHAR(255) NOT NULL,
  `parameters` JSON,
  `data` LONGTEXT NOT NULL,
  `columns` JSON,
  `total_rows` INT(11) DEFAULT 0,
  `page` INT(11) DEFAULT 1,
  `per_page` INT(11) DEFAULT 100,
  `summary` JSON,
  `user_id` INT(11) DEFAULT NULL,
  `execution_time` DECIMAL(10, 4) DEFAULT 0.0000,
  `generated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `report_code` (`report_code`),
  KEY `user_id` (`user_id`),
  KEY `generated_at` (`generated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Scheduled Reports Table
CREATE TABLE IF NOT EXISTS `scheduled_reports` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `report_code` VARCHAR(100) NOT NULL,
  `schedule` VARCHAR(100) NOT NULL,
  `parameters` JSON,
  `delivery_method` VARCHAR(50) NOT NULL DEFAULT 'email',
  `recipients` JSON,
  `is_active` TINYINT(1) DEFAULT 1,
  `user_id` INT(11) DEFAULT NULL,
  `last_run_at` DATETIME DEFAULT NULL,
  `next_run_at` DATETIME DEFAULT NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `report_code` (`report_code`),
  KEY `is_active` (`is_active`),
  KEY `next_run_at` (`next_run_at`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default report definitions

-- Sales Reports
INSERT INTO `report_definitions` (`code`, `name`, `description`, `category`, `default_parameters`, `required_permissions`, `allow_export`, `export_formats`, `allow_scheduling`, `created_at`) VALUES
('SALES_ORDER_STATUS', 'Sales Order Status Report', 'Comprehensive status report of all sales orders with filtering options', 'sales', 
 '{"date_from": {"type": "date", "required": true, "label": "From Date"}, "date_to": {"type": "date", "required": true, "label": "To Date"}, "status": {"type": "array", "required": false, "label": "Order Status", "options": ["pending", "confirmed", "shipped", "delivered", "cancelled"]}, "customer_id": {"type": "int", "required": false, "label": "Customer ID"}}',
 '["sales.view_orders"]', 1, '["pdf", "excel", "csv"]', 1, NOW()),

('SALES_ANALYSIS', 'Sales Analysis Report', 'Detailed analysis of sales performance by product, customer, and period', 'sales',
 '{"date_from": {"type": "date", "required": true}, "date_to": {"type": "date", "required": true}, "group_by": {"type": "string", "required": false, "options": ["product", "customer", "category", "sales_person"]}}',
 '["sales.view_reports"]', 1, '["pdf", "excel", "csv"]', 1, NOW()),

('AGED_DEBTORS', 'Aged Debtors Report', 'Customer accounts receivable aging analysis', 'sales',
 '{"as_of_date": {"type": "date", "required": true}, "customer_id": {"type": "int", "required": false}, "currency": {"type": "string", "required": false}}',
 '["sales.view_debtors"]', 1, '["pdf", "excel", "csv"]', 1, NOW()),

('CUSTOMER_TRANSACTIONS', 'Customer Transactions Report', 'Detailed transaction history for customers', 'sales',
 '{"customer_id": {"type": "int", "required": true}, "date_from": {"type": "date", "required": true}, "date_to": {"type": "date", "required": true}}',
 '["sales.view_transactions"]', 1, '["pdf", "excel", "csv"]', 1, NOW());

-- Purchasing Reports
INSERT INTO `report_definitions` (`code`, `name`, `description`, `category`, `default_parameters`, `required_permissions`, `allow_export`, `export_formats`, `allow_scheduling`, `created_at`) VALUES
('PURCHASE_ORDER_STATUS', 'Purchase Order Status Report', 'Status and details of all purchase orders', 'purchasing',
 '{"date_from": {"type": "date", "required": true}, "date_to": {"type": "date", "required": true}, "status": {"type": "array", "required": false}, "supplier_id": {"type": "int", "required": false}}',
 '["purchasing.view_orders"]', 1, '["pdf", "excel", "csv"]', 1, NOW()),

('SUPPLIER_TRANSACTIONS', 'Supplier Transactions Report', 'Complete transaction history with suppliers', 'purchasing',
 '{"supplier_id": {"type": "int", "required": true}, "date_from": {"type": "date", "required": true}, "date_to": {"type": "date", "required": true}}',
 '["purchasing.view_transactions"]', 1, '["pdf", "excel", "csv"]', 1, NOW()),

('AGED_SUPPLIERS', 'Aged Suppliers Report', 'Supplier accounts payable aging analysis', 'purchasing',
 '{"as_of_date": {"type": "date", "required": true}, "supplier_id": {"type": "int", "required": false}, "currency": {"type": "string", "required": false}}',
 '["purchasing.view_payables"]', 1, '["pdf", "excel", "csv"]', 1, NOW()),

('SUPPLIER_PERFORMANCE', 'Supplier Performance Report', 'Analysis of supplier performance metrics', 'purchasing',
 '{"date_from": {"type": "date", "required": true}, "date_to": {"type": "date", "required": true}, "supplier_id": {"type": "int", "required": false}}',
 '["purchasing.view_reports"]', 1, '["pdf", "excel", "csv"]', 1, NOW());

-- Inventory Reports
INSERT INTO `report_definitions` (`code`, `name`, `description`, `category`, `default_parameters`, `required_permissions`, `allow_export`, `export_formats`, `allow_scheduling`, `created_at`) VALUES
('STOCK_STATUS', 'Stock Status Report', 'Current stock levels and status for all items', 'inventory',
 '{"location_id": {"type": "int", "required": false}, "category": {"type": "string", "required": false}, "show_zero_stock": {"type": "boolean", "required": false, "default": false}}',
 '["inventory.view_stock"]', 1, '["pdf", "excel", "csv"]', 1, NOW()),

('STOCK_VALUATION', 'Stock Valuation Report', 'Inventory valuation by location and category', 'inventory',
 '{"as_of_date": {"type": "date", "required": true}, "location_id": {"type": "int", "required": false}, "valuation_method": {"type": "string", "required": false, "options": ["fifo", "average", "standard"]}}',
 '["inventory.view_valuation"]', 1, '["pdf", "excel", "csv"]', 1, NOW()),

('STOCK_MOVEMENTS', 'Stock Movements Report', 'Detailed inventory movement transactions', 'inventory',
 '{"date_from": {"type": "date", "required": true}, "date_to": {"type": "date", "required": true}, "item_code": {"type": "string", "required": false}, "location_id": {"type": "int", "required": false}}',
 '["inventory.view_movements"]', 1, '["pdf", "excel", "csv"]', 1, NOW()),

('REORDER_LEVEL', 'Reorder Level Report', 'Items at or below reorder levels', 'inventory',
 '{"location_id": {"type": "int", "required": false}, "category": {"type": "string", "required": false}}',
 '["inventory.view_stock"]', 1, '["pdf", "excel", "csv"]', 1, NOW()),

('BOM_LISTING', 'Bill of Materials Listing', 'Complete BOM structure for manufactured items', 'inventory',
 '{"item_code": {"type": "string", "required": false}, "show_costing": {"type": "boolean", "required": false, "default": true}}',
 '["inventory.view_bom"]', 1, '["pdf", "excel", "csv"]', 0, NOW());

-- Financial Reports
INSERT INTO `report_definitions` (`code`, `name`, `description`, `category`, `default_parameters`, `required_permissions`, `allow_export`, `export_formats`, `allow_scheduling`, `created_at`) VALUES
('TRIAL_BALANCE', 'Trial Balance', 'Trial balance report showing all account balances', 'financial',
 '{"date_from": {"type": "date", "required": true}, "date_to": {"type": "date", "required": true}, "dimension": {"type": "int", "required": false}}',
 '["gl.view_reports"]', 1, '["pdf", "excel", "csv"]', 1, NOW()),

('BALANCE_SHEET', 'Balance Sheet', 'Statement of financial position', 'financial',
 '{"as_of_date": {"type": "date", "required": true}, "dimension": {"type": "int", "required": false}, "compare_period": {"type": "boolean", "required": false}}',
 '["gl.view_reports"]', 1, '["pdf", "excel", "csv"]', 1, NOW()),

('PROFIT_LOSS', 'Profit & Loss Statement', 'Income statement showing revenue and expenses', 'financial',
 '{"date_from": {"type": "date", "required": true}, "date_to": {"type": "date", "required": true}, "dimension": {"type": "int", "required": false}}',
 '["gl.view_reports"]', 1, '["pdf", "excel", "csv"]', 1, NOW()),

('CASH_FLOW', 'Cash Flow Statement', 'Statement of cash flows by operating, investing, financing activities', 'financial',
 '{"date_from": {"type": "date", "required": true}, "date_to": {"type": "date", "required": true}}',
 '["gl.view_reports"]', 1, '["pdf", "excel", "csv"]', 1, NOW()),

('GL_INQUIRY', 'General Ledger Inquiry', 'Detailed GL account transactions', 'financial',
 '{"account_code": {"type": "string", "required": true}, "date_from": {"type": "date", "required": true}, "date_to": {"type": "date", "required": true}, "dimension": {"type": "int", "required": false}}',
 '["gl.view_transactions"]', 1, '["pdf", "excel", "csv"]', 1, NOW());

-- Manufacturing Reports
INSERT INTO `report_definitions` (`code`, `name`, `description`, `category`, `default_parameters`, `required_permissions`, `allow_export`, `export_formats`, `allow_scheduling`, `created_at`) VALUES
('WORK_ORDER_STATUS', 'Work Order Status Report', 'Status and details of manufacturing work orders', 'manufacturing',
 '{"date_from": {"type": "date", "required": true}, "date_to": {"type": "date", "required": true}, "status": {"type": "array", "required": false}, "item_code": {"type": "string", "required": false}}',
 '["manufacturing.view_orders"]', 1, '["pdf", "excel", "csv"]', 1, NOW()),

('PRODUCTION_COSTING', 'Production Costing Report', 'Manufacturing cost analysis by work order', 'manufacturing',
 '{"date_from": {"type": "date", "required": true}, "date_to": {"type": "date", "required": true}, "item_code": {"type": "string", "required": false}}',
 '["manufacturing.view_costing"]', 1, '["pdf", "excel", "csv"]', 1, NOW()),

('MATERIAL_REQUIREMENTS', 'Material Requirements Report', 'MRP analysis showing material requirements', 'manufacturing',
 '{"planning_horizon_days": {"type": "int", "required": true, "default": 90}, "item_code": {"type": "string", "required": false}}',
 '["manufacturing.view_mrp"]', 1, '["pdf", "excel", "csv"]', 1, NOW()),

('CAPACITY_PLANNING', 'Capacity Planning Report', 'Production capacity analysis by work center', 'manufacturing',
 '{"date_from": {"type": "date", "required": true}, "date_to": {"type": "date", "required": true}, "work_center_id": {"type": "int", "required": false}}',
 '["manufacturing.view_capacity"]', 1, '["pdf", "excel", "csv"]', 1, NOW());
