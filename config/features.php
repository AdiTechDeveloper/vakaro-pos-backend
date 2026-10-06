<?php

return [
    // ===== ALWAYS visible =====
    'dashboard'   => ['label' => 'Dashboard',   'group' => 'General', 'always' => true],
    'pos'         => ['label' => 'POS',         'group' => 'Sales',   'always' => true],
    'sales_bills' => ['label' => 'Sales Bills', 'group' => 'Sales',   'always' => true],
    'categories'  => ['label' => 'Categories',  'group' => 'Catalog', 'always' => true],
    'brands'      => ['label' => 'Brands',      'group' => 'Catalog', 'always' => true],

    // Catalog
    'products'  => ['label' => 'Products (incl. Print Barcode)', 'group' => 'Catalog'],
    'gst_rates' => ['label' => 'GST Rates', 'group' => 'Catalog'],

    // Purchasing
    'suppliers'        => ['label' => 'Suppliers', 'group' => 'Purchasing'],
    'purchase_bills'   => ['label' => 'Purchase Bills', 'group' => 'Purchasing'],
    'purchase_returns' => ['label' => 'Purchase Return Bills', 'group' => 'Purchasing'],

    // Sales
    'sales_returns'    => ['label' => 'Sales Returns', 'group' => 'Sales'],
    'advance_payments' => ['label' => 'Advanced Payment', 'group' => 'Sales'],
    'customers'        => ['label' => 'Customers', 'group' => 'Sales'],
    'price_override'   => ['label' => 'Price Override (incl. Summary)', 'group' => 'Sales'],

    // Admin
    'branch_management' => ['label' => 'Branch Management', 'group' => 'Admin'],
    'staff_management'  => ['label' => 'Cashiers / Staff', 'group' => 'Admin'],
    'pos_terminals'     => ['label' => 'POS Terminal / Device Management', 'group' => 'Admin'],

    // Reports
    'stock_alerts'      => ['label' => 'Expired Products & Stock Expiry', 'group' => 'Reports'],
    'reports_stock'     => ['label' => 'Stock Summary', 'group' => 'Reports'],
    'reports_sales'     => ['label' => 'Sales Analytics & Sales Report', 'group' => 'Reports'],
    'reports_purchase'  => ['label' => 'Purchase Summary & Report', 'group' => 'Reports'],
    'reports_financial' => ['label' => 'Financial Report', 'group' => 'Reports'],
    'reports_gst'       => ['label' => 'GST Reports (Output, GSTR-3B, GSTR1)', 'group' => 'Reports'],
    'reports_shift'     => ['label' => 'Shift History Report', 'group' => 'Reports'],
    'reports_suplier'   =>['label' => 'Suplier Tracking Report','group' => 'Reports']
];