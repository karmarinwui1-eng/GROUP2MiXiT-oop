<?php
declare(strict_types=1);
interface InventoryAlgorithm { public function run(array $items, string $argument = ''): array; }
abstract class CatalogAlgorithm implements InventoryAlgorithm {
    protected function key(array $row, string $field): string { return (string)($row[$field] ?? ''); }
    protected function compare(array $a, array $b, string $field): int {
        if ($field === 'id') return ((int)$a['id']) <=> ((int)$b['id']);
        if ($field === 'expiration_date') return strcmp($this->key($a,$field) ?: '9999-12-31', $this->key($b,$field) ?: '9999-12-31');
        return strcasecmp($this->key($a,$field), $this->key($b,$field));
    }
}
final class ExpirationSelectionSort extends CatalogAlgorithm {
    public function run(array $items, string $argument = ''): array {
        $n=count($items);
        for ($i=0; $i<$n-1; $i++) {
            $min=$i;
            for ($j=$i+1; $j<$n; $j++) if ($this->compare($items[$j],$items[$min],'expiration_date')<0) $min=$j;
            if ($min!==$i) { $temp=$items[$i];$items[$i]=$items[$min];$items[$min]=$temp; }
        }
        return $items;
    }
}
final class ProductMergeSort extends CatalogAlgorithm {
    public function run(array $items, string $argument = ''): array {
        $field=$argument==='id' ? 'id' : 'name';
        return $this->sort($items,$field);
    }
    private function sort(array $items,string $field): array {
        $n=count($items);if($n<2)return $items;
        $mid=intdiv($n,2);
        $left=$this->sort(array_slice($items,0,$mid),$field);
        $right=$this->sort(array_slice($items,$mid),$field);
        $out=[];$i=0;$j=0;
        while($i<count($left)&&$j<count($right)){
            if($this->compare($left[$i],$right[$j],$field)<=0)$out[]=$left[$i++];
            else $out[]=$right[$j++];
        }
        while($i<count($left))$out[]=$left[$i++];
        while($j<count($right))$out[]=$right[$j++];
        return $out;
    }
}
final class ProductLinearSearch extends CatalogAlgorithm {
    public function run(array $items, string $argument = ''): array {
        $needle=trim($argument);if($needle==='')return $items;
        $matches=[];
        foreach($items as $row){
            if((string)$row['id']===$needle || stripos((string)$row['name'],$needle)!==false || stripos((string)($row['sku']??''),$needle)!==false){$matches[]=$row;}
        }
        return $matches;
    }
}
final class CatalogAlgorithmService {
    public static function execute(InventoryAlgorithm $algorithm,array $items,string $argument=''): array {
        return $algorithm->run($items,$argument);
    }
}

final class LinkedStackNode {
    public mixed $value;
    public ?LinkedStackNode $next;

    public function __construct(
        mixed $value,
        ?LinkedStackNode $next = null
    ) {
        $this->value = $value;
        $this->next = $next;
    }
}
final class LinkedNodeStack {
    private ?LinkedStackNode $top=null;
    public function push(mixed $value): void { $this->top=new LinkedStackNode($value,$this->top); }
    public function pop(): mixed {
        if($this->top===null)throw new UnderflowException('Stack is empty');
        $value=$this->top->value;$this->top=$this->top->next;return $value;
    }
    public function isEmpty(): bool {return $this->top===null;}
    public function peek(): mixed {return $this->top?->value;}
    /** Visit each linked node without changing the stack. */
    public function traverse(callable $visitor): void {
        for($node=$this->top;$node!==null;$node=$node->next)$visitor($node->value);
    }
}
final class PostfixProfitEvaluator {
    public function evaluate(array $tokens): float {
        $stack=new LinkedNodeStack();
        foreach($tokens as $token){
            if(is_numeric($token)){ $stack->push((float)$token);continue; }
            if(!in_array($token,['+','-','*','/'],true))throw new InvalidArgumentException('Unknown operator');
            $right=$stack->pop();$left=$stack->pop();
            $value=match($token){'+'=>$left+$right,'-'=>$left-$right,'*'=>$left*$right,'/'=>$right!=0 ? $left/$right : throw new DivisionByZeroError()};
            $stack->push($value);
        }
        $answer=$stack->pop();
        if(!$stack->isEmpty())throw new InvalidArgumentException('Invalid postfix expression');
        return round($answer,2);
    }
    public function lineProfit(float $selling,float $cost,float $qty): float {
        return $this->evaluate([$selling,$cost,'-',$qty,'*']);
    }
}
final class LinkedProfitReport {
    public function total(array $saleLines,string $from,string $to): float {
        $stack=new LinkedNodeStack();$evaluator=new PostfixProfitEvaluator();
        foreach($saleLines as $line)$stack->push($line);
        $total=0.0;
        $stack->traverse(static function(array $line)use(&$total,$evaluator,$from,$to):void {
            $day=substr((string)$line['sold_at'],0,10);
            if($day>=$from && $day<=$to){
                $total+=$evaluator->lineProfit((float)$line['unit_price'],(float)$line['unit_cost'],(float)$line['quantity']);
            }
        });
        return round($total,2);
    }
}
final class InventoryDSA {
    public static function linearSearch(array $products,string $needle):array {return (new ProductLinearSearch())->run($products,$needle);}
    public static function mergeSort(array $items,string $field='name'):array {return (new ProductMergeSort())->run($items,$field);}
    public static function selectionSort(array $items):array {return (new ExpirationSelectionSort())->run($items);}
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
