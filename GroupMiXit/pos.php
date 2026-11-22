
<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_once __DIR__ . '/includes/InventoryDSA.php';

$pageTitle = 'Point of Sale';
$error = '';

// LOAD AVAILABLE PRODUCTS
$products = $pdo->query("
    SELECT id, name, sku, unit, selling_price, stock_qty
    FROM products
    WHERE active = 1
      AND stock_qty > 0
      AND (
          expiration_date IS NULL
          OR expiration_date >= CURDATE()
      )
    ORDER BY name
")->fetchAll();


// PROCESS CHECKOUT
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $cart = $_POST['cart'] ?? [];
    $cash = round((float)($_POST['cash_received'] ?? 0), 2);
    $discount = round((float)($_POST['discount'] ?? 0), 2);

    try {
        if (!is_array($cart) || !$cart) {
            throw new RuntimeException(
                'Add at least one product to the cart.'
            );
        }

        $pdo->beginTransaction();

        $items = [];
        $subtotal = 0;

        // B5: POSTFIX EVALUATION
        $postfixProfit = new PostfixProfitEvaluator();
        $transactionGrossProfit = 0.0;

        $findProduct = $pdo->prepare("
            SELECT
                id,
                name,
                cost_price,
                selling_price,
                stock_qty,
                DATEDIFF(
                    expiration_date,
                    CURDATE()
                ) AS days_left
            FROM products
            WHERE id = ?
              AND active = 1
            FOR UPDATE
        ");

        foreach ($cart as $productId => $rawQuantity) {
            $quantity = (float)$rawQuantity;

            if ($quantity <= 0) {
                continue;
            }

            $findProduct->execute([(int)$productId]);
            $product = $findProduct->fetch();

            if (!$product) {
                throw new RuntimeException(
                    'A selected product is no longer available.'
                );
            }

            if (
                $product['days_left'] !== null
                && (int)$product['days_left'] < 0
            ) {
                throw new RuntimeException(
                    $product['name']
                    . ' has expired and cannot be sold.'
                );
            }

            if ($quantity > (float)$product['stock_qty']) {
                throw new RuntimeException(
                    'Not enough stock for ' . $product['name'] . '.'
                );
            }

            $lineTotal = round(
                $quantity * (float)$product['selling_price'],
                2
            );

            $subtotal += $lineTotal;

            // POSTFIX PROFIT CALCULATION
            $transactionGrossProfit += $postfixProfit->lineProfit(
                (float)$product['selling_price'],
                (float)$product['cost_price'],
                $quantity
            );

            $items[] = [
                'product' => $product,
                'quantity' => $quantity,
                'subtotal' => $lineTotal,
            ];
        }

        if (!$items) {
            throw new RuntimeException(
                'Add at least one valid product.'
            );
        }

        $subtotal = round($subtotal, 2);

        if ($discount < 0 || $discount > $subtotal) {
            throw new RuntimeException(
                'Discount must be between ₱0 and the subtotal.'
            );
        }

        $total = round($subtotal - $discount, 2);

        if ($cash < $total) {
            throw new RuntimeException(
                'Cash received must cover the total amount.'
            );
        }

        // GENERATE TRANSACTION NUMBER
        $transactionNo = 'SS' . date('ymdHis')
            . str_pad(
                (string)random_int(0, 999),
                3,
                '0',
                STR_PAD_LEFT
            );

        // SAVE SALE
        $saveSale = $pdo->prepare("
            INSERT INTO sales (
                transaction_no,
                cashier_id,
                subtotal,
                discount,
                total_amount,
                cash_received,
                change_amount,
                status
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, 'completed')
        ");

        $saveSale->execute([
            $transactionNo,
            $_SESSION['user_id'],
            $subtotal,
            $discount,
            $total,
            $cash,
            round($cash - $total, 2),
        ]);

        $saleId = (int)$pdo->lastInsertId();

        // SAVE PURCHASED ITEMS
        $saveItem = $pdo->prepare("
            INSERT INTO sale_items (
                sale_id,
                product_id,
                product_name,
                quantity,
                unit_price,
                unit_cost,
                subtotal
            )
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        // DEDUCT PRODUCT STOCK
        $deductStock = $pdo->prepare("
            UPDATE products
            SET stock_qty = stock_qty - ?
            WHERE id = ?
        ");

        // RECORD INVENTORY MOVEMENTS
        $saveMovement = $pdo->prepare("
            INSERT INTO inventory_movements (
                product_id,
                user_id,
                movement_type,
                quantity_change,
                reference_type,
                reference_id,
                notes
            )
            VALUES (
                ?, ?, 'sale', ?, 'sale', ?, 'POS sale'
            )
        ");

        foreach ($items as $item) {
            $product = $item['product'];
            $quantity = $item['quantity'];

            $saveItem->execute([
                $saleId,
                $product['id'],
                $product['name'],
                $quantity,
                $product['selling_price'],
                $product['cost_price'],
                $item['subtotal'],
            ]);

            $deductStock->execute([
                $quantity,
                $product['id']
            ]);

            $saveMovement->execute([
                $product['id'],
                $_SESSION['user_id'],
                -$quantity,
                $saleId,
            ]);
        }

        // RECORD COMPLETED SALE
        // Postfix gross profit is already evaluated above.
        log_action(
            $pdo,
            'create',
            'sale',
            $saleId,
            $transactionNo
        );

        $pdo->commit();

        flash(
            'success',
            'Sale completed successfully! Transaction: '
            . $transactionNo
        );

        header('Location: pos.php?receipt=' . $saleId);
        exit;

    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if ($ex instanceof PDOException) {
            error_log(
                'POS checkout failed: ' . $ex->getMessage()
            );

            $error = 'Sale could not be saved. Please retry.';
        } else {
            $error = $ex->getMessage();
        }
    }
}


$receipt = null;
$receiptItems = [];

if (isset($_GET['receipt'])) {
    $receiptId = filter_var(
        $_GET['receipt'],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );

    if ($receiptId !== false) {
        $receiptQuery = $pdo->prepare("
            SELECT
                s.*,
                u.name AS cashier
            FROM sales s
            JOIN users u
                ON u.id = s.cashier_id
            WHERE s.id = ?
              AND (
                  s.cashier_id = ?
                  OR ? = 'admin'
              )
            LIMIT 1
        ");

        $receiptQuery->execute([
            $receiptId,
            (int)$_SESSION['user_id'],
            (string)($_SESSION['role'] ?? '')
        ]);

        $receipt = $receiptQuery->fetch();

        if ($receipt) {
            $itemsQuery = $pdo->prepare("
                SELECT *
                FROM sale_items
                WHERE sale_id = ?
                ORDER BY id
            ");

            $itemsQuery->execute([$receiptId]);

            $receiptItems = $itemsQuery->fetchAll();
        }
    }
}


require __DIR__ . '/includes/header.php';
?>

<?php if ($receipt): ?>

<section class="panel receipt-panel" id="posReceipt">

    <div class="receipt-head">
        <div class="brand-mark">T</div>

        <h2>Tajon Sari-Sari Store</h2>
        <p>SALES RECEIPT</p>

        <span class="badge <?= $receipt['status'] === 'completed' ? 'success' : 'danger' ?>">
            <?= e(strtoupper($receipt['status'])) ?>
        </span>
    </div>

    <div class="receipt-meta">

        <span>
            Transaction
            <strong>
                <?= e($receipt['transaction_no']) ?>
            </strong>
        </span>

        <span>
            Date
            <strong>
                <?= date(
                    'M j, Y g:i A',
                    strtotime($receipt['sold_at'])
                ) ?>
            </strong>
        </span>

        <span>
            Processed by
            <strong>
                <?= e($receipt['cashier']) ?>
            </strong>
        </span>

    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Qty</th>
                    <th>Price</th>
                    <th class="align-right">Amount</th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($receiptItems as $item): ?>

                    <tr>
                        <td>
                            <?= e($item['product_name']) ?>
                        </td>

                        <td>
                            <?= (float)$item['quantity'] ?>
                        </td>

                        <td>
                            <?= money($item['unit_price']) ?>
                        </td>

                        <td class="align-right">
                            <?= money($item['subtotal']) ?>
                        </td>
                    </tr>

                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- PAYMENT SUMMARY -->
    <div class="receipt-totals">

        <div>
            <span>Subtotal</span>
            <strong>
                <?= money($receipt['subtotal']) ?>
            </strong>
        </div>

        <div>
            <span>Discount</span>
            <strong>
                <?= money($receipt['discount']) ?>
            </strong>
        </div>

        <div>
            <span>Total Amount</span>
            <strong>
                <?= money($receipt['total_amount']) ?>
            </strong>
        </div>

        <div>
            <span>Cash Received</span>
            <strong>
                <?= money($receipt['cash_received']) ?>
            </strong>
        </div>

        <div>
            <span>Change</span>
            <strong>
                <?= money($receipt['change_amount']) ?>
            </strong>
        </div>

    </div>

    <p class="center muted">
        Thank you for shopping at Tajon Sari-Sari Store!
    </p>

    <div class="receipt-actions">

        <button
            type="button"
            class="btn primary"
            onclick="window.print()"
        >
            Print Receipt
        </button>

        <a href="pos.php" class="btn secondary">
            New Sale
        </a>

    </div>

</section>

<style>
@media print {
    body * {
        visibility: hidden !important;
    }

    #posReceipt,
    #posReceipt * {
        visibility: visible !important;
    }

    #posReceipt {
        display: block !important;
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        max-width: none;
        margin: 0;
        padding: 15px;
        border: none;
        box-shadow: none;
    }

    #posReceipt .receipt-actions {
        display: none !important;
    }
}
</style>

<?php endif; ?>


<div class="pos-layout">

    <!-- PRODUCT SELECTION -->
    <section class="panel pos-products">

        <div class="panel-heading">
            <div>
                <h3>Choose products</h3>
                <p>Tap a product to add it to the cart.</p>
            </div>

            <span class="subtle-tag">
                <?= count($products) ?> available
            </span>
        </div>

        <input
            id="productSearch"
            class="search-wide"
            placeholder="Search products by name or SKU…"
        >

        <div class="pos-product-grid" id="posProductGrid">

            <?php foreach ($products as $p): ?>

                <button
                    type="button"
                    class="pos-product"
                    data-id="<?= (int)$p['id'] ?>"
                    data-name="<?= e($p['name']) ?>"
                    data-sku="<?= e($p['sku'] ?? '') ?>"
                    data-price="<?= e((string)$p['selling_price']) ?>"
                    data-stock="<?= e((string)$p['stock_qty']) ?>"
                    data-unit="<?= e($p['unit']) ?>"
                >

                    <span class="pos-product-icon">
                        <?= e(strtoupper(substr($p['name'], 0, 1))) ?>
                    </span>

                    <strong>
                        <?= e($p['name']) ?>
                    </strong>

                    <small>
                        <?= money($p['selling_price']) ?>
                        /
                        <?= e($p['unit']) ?>
                    </small>

                    <span class="stock-hint">
                        <?= e((string)$p['stock_qty']) ?>
                        <?= e($p['unit']) ?>
                        available
                    </span>

                </button>

            <?php endforeach; ?>

        </div>

        <p id="noProducts" class="muted hidden">
            No matching products found.
        </p>

    </section>

    <section class="panel cart-panel">

        <div class="panel-heading">

            <div>
                <h3>Current sale</h3>
                <p>Review items before checkout.</p>
            </div>

            <div>

                <button
                    type="button"
                    id="undoCart"
                    class="btn secondary small"
                    disabled
                    title="Undo the latest cart change (Ctrl+Z)"
                >
                    ↶ Undo
                </button>

                <button
                    type="button"
                    id="clearCart"
                    class="btn ghost small"
                >
                    Clear
                </button>

            </div>

        </div>

        <?php if (!empty($error)): ?>
            <div class="alert error">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="post" id="checkoutForm">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e(csrf_token()) ?>"
            >

            <div id="cartItems" class="cart-items">

                <div class="empty-state compact">
                    <div>＋</div>

                    <strong>
                        Your cart is empty
                    </strong>

                    <p>
                        Select products to start a sale.
                    </p>
                </div>

            </div>

            <div id="hiddenCart"></div>

            <div class="cart-summary">

                <div>
                    <span>Subtotal</span>

                    <strong id="subtotalDisplay">
                        ₱0.00
                    </strong>
                </div>


                <div class="discount-line">

                    <label for="discount">
                        Discount (₱)
                    </label>

                    <input
                        id="discount"
                        name="discount"
                        type="number"
                        min="0"
                        step="0.01"
                        value="0"
                    >

                </div>

                <div class="total-line">

                    <span>Total due</span>

                    <strong id="totalDisplay">
                        ₱0.00
                    </strong>

                </div>

                
                <label for="cashReceived">
                    Cash received (₱)
                </label>

                <input
                    id="cashReceived"
                    name="cash_received"
                    type="number"
                    min="0"
                    step="0.01"
                    required
                    placeholder="Enter amount received"
                >

                
                <div class="change-line">

                    <span>Change</span>

                    <strong id="changeDisplay">
                        ₱0.00
                    </strong>

                </div>

                <button
                    class="btn primary full checkout-btn"
                    type="submit"
                >
                    Complete sale
                    <span>→</span>
                </button>

                <p class="form-help center">
                    Stock is deducted only after the sale
                    is saved successfully.
                </p>

            </div>

        </form>

    </section>

</div>

<script src="assets/js/pos.js"></script>

<?php require __DIR__ . '/includes/footer.php'; ?>
