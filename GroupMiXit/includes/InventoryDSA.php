<?php
declare(strict_types=1);
/** Algorithms operate on PHP arrays fetched from the existing products table. */
final class InventoryDSA {
    public static function linearSearch(array $products, string $needle): array {
        $matches = [];
        foreach ($products as $product) {
            if (stripos((string)$product['name'], $needle) !== false || stripos((string)($product['sku'] ?? ''), $needle) !== false || (string)$product['id'] === $needle) {
                $matches[] = $product;
            }
        }
        return $matches;
    }
    public static function mergeSort(array $items, string $field = 'stock_qty'): array {
        $n = count($items);
        if ($n < 2) return $items;
        $mid = intdiv($n, 2);
        $left = self::mergeSort(array_slice($items, 0, $mid), $field);
        $right = self::mergeSort(array_slice($items, $mid), $field);
        $out = []; $i = 0; $j = 0;
        while ($i < count($left) && $j < count($right)) {
            $a = $left[$i][$field] ?? null;
            $b = $right[$j][$field] ?? null;
            $cmp = $field === 'expiration_date' ? strcmp((string)($a ?? '9999-12-31'), (string)($b ?? '9999-12-31')) : ((float)$a <=> (float)$b);
            if ($cmp <= 0) $out[] = $left[$i++]; else $out[] = $right[$j++];
        }
        while ($i < count($left)) $out[] = $left[$i++];
        while ($j < count($right)) $out[] = $right[$j++];
        return $out;
    }
    public static function lowStockTraversal(array $products): array {
        $low = [];
        foreach ($products as $product) if ((float)$product['stock_qty'] <= (float)$product['min_stock']) $low[] = $product;
        return $low;
    }
    public static function inventoryValueSum(array $products): float {
        $total = 0.0;
        foreach ($products as $product) $total += (float)$product['stock_qty'] * (float)$product['cost_price'];
        return round($total, 2);
    }
    public static function expirationCheck(array $products, int $days = 7): array {
        $result = [];
        $today = new DateTimeImmutable('today');
        $deadline = $today->modify('+' . $days . ' days');
        foreach ($products as $product) {
            if ((float)$product['stock_qty'] <= 0 || empty($product['expiration_date'])) continue;
            $expiry = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$product['expiration_date']);
            if ($expiry && $expiry <= $deadline) $result[] = $product;
        }
        return $result;
    }
}
/** Explicit LIFO stack operations; MySQL table persists entries between requests. */
final class InventoryAdjustmentStack {
    public function __construct(private PDO $pdo) {}
    public function push(int $userId, int $movementId, int $productId, float $delta): void {
        $q = $this->pdo->prepare('INSERT INTO dsa_adjustment_stack(user_id,movement_id,product_id,quantity_change) VALUES(?,?,?,?)');
        $q->execute([$userId, $movementId, $productId, $delta]);
    }
    public function peek(int $userId): ?array {
        $q = $this->pdo->prepare('SELECT s.*,p.name product_name FROM dsa_adjustment_stack s JOIN products p ON p.id=s.product_id WHERE s.user_id=? AND s.undone_at IS NULL ORDER BY s.id DESC LIMIT 1');
        $q->execute([$userId]);
        return $q->fetch() ?: null;
    }
    public function popAndUndo(int $userId): string {
        $this->pdo->beginTransaction();
        try {
            $q = $this->pdo->prepare('SELECT * FROM dsa_adjustment_stack WHERE user_id=? AND undone_at IS NULL ORDER BY id DESC LIMIT 1 FOR UPDATE');
            $q->execute([$userId]);
            $top = $q->fetch();
            if (!$top) throw new RuntimeException('No manual stock adjustment to undo.');
            $q = $this->pdo->prepare('SELECT id,name,stock_qty,active FROM products WHERE id=? FOR UPDATE');
            $q->execute([(int)$top['product_id']]);
            $product = $q->fetch();
            if (!$product || !(int)$product['active']) throw new RuntimeException('Cannot undo an archived or missing product.');
            // Do not undo across a later sale, purchase, edit or other stock movement.
            $q = $this->pdo->prepare('SELECT COUNT(*) FROM inventory_movements WHERE product_id=? AND id>?');
            $q->execute([(int)$top['product_id'], (int)$top['movement_id']]);
            if ((int)$q->fetchColumn() !== 0) throw new RuntimeException('Cannot undo: this product has newer stock movements. Review its history first.');
            $reverse = -(float)$top['quantity_change'];
            if ((float)$product['stock_qty'] + $reverse < -0.00001) throw new RuntimeException('Cannot undo because stock would become negative.');
            $this->pdo->prepare('UPDATE products SET stock_qty=stock_qty+? WHERE id=?')->execute([$reverse, (int)$top['product_id']]);
            $this->pdo->prepare("INSERT INTO inventory_movements(product_id,user_id,movement_type,quantity_change,notes) VALUES(?,?,'adjustment',?,'Undo last manual stock adjustment (Stack LIFO)')")->execute([(int)$top['product_id'], $userId, $reverse]);
            $this->pdo->prepare('UPDATE dsa_adjustment_stack SET undone_at=NOW() WHERE id=?')->execute([(int)$top['id']]);
            $this->pdo->commit();
            return (string)$product['name'];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }
}
