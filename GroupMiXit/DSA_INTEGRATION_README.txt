TindahanHub — integrated Stack + five algorithms

1. BACK UP your current website and MySQL database before installing.
2. Copy the updated GroupMiXit folder to C:\xampp\htdocs\GroupMiXit (or your existing folder).
3. Keep your existing config/database.local.php. It is deliberately NOT included in this package.
4. Open phpMyAdmin, select your existing sarisari_db database, go to SQL and execute database/dsa_migration.sql ONCE. It creates only the dsa_adjustment_stack table. Existing records remain unchanged.
5. Open http://localhost/GroupMiXit/products.php and find the DSA Inventory Tools panel.
6. Choose Linear Search, Merge Sort, List Traversal, Summation, or Expiration-Date Checking and click Run Algorithm. All read live product records.
7. For Stack: click Adjust on a product, save a stock adjustment, then click Undo Last Adjustment (POP). Only admin can undo. Undo is disabled when there is no stack entry. The stack is persistent in MySQL.
8. Undo is intentionally blocked when any newer stock movement exists for the same product. This prevents overwriting later sales/purchases/edits. Old adjustments made before this integration are not undoable.
9. Product editing, sales and purchases remain otherwise unchanged. This integration does not add a standalone DSA navigation tab; the tools are in Products & Inventory.
10. Validate all features locally, especially login, add/edit products, adjust/undo, POS and purchasing, before sharing or deploying.

Algorithms: Linear Search by ID/name/SKU; stable Merge Sort by quantity or expiry; List Traversal for low stock; Summation of stock_qty*cost_price; Expiration Check for dates <= 7 days from today.

SECURITY: No real database credentials are included. Set your own config/database.local.php on each machine.
