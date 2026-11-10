<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli' || !preg_match('/^amir_test_[a-z0-9_]+$/',getenv('DB_NAME') ?: '')) {
    throw new RuntimeException('Use a disposable amir_test_* database.');
}
require dirname(__DIR__).'/php/api.php';
$db=new DB(read_config()); $shop=new Shop($db,read_config());
$shop->init();
function ok($condition,$message) { if (!$condition) throw new RuntimeException($message); echo "PASS: $message\n"; }
ok((int)$db->get('SELECT COUNT(*) n FROM products')['n']===128,'imported catalog');
ok((int)$db->get('SELECT COUNT(*) n FROM product_vehicles')['n']===542,'vehicle links');
ok((int)$db->get('SELECT COUNT(*) n FROM users')['n']===0,'no default admin');
ok(health_report($shop)['integrity_ok'],'imported data reconciles');
$product=$db->get('SELECT id FROM products LIMIT 1')['id'];
foreach ([
 ['UPDATE products SET stock=-1 WHERE id=?',[$product],'negative stock rejected'],
 ['UPDATE products SET category_id=? WHERE id=?',['missing-cat',$product],'invalid category FK rejected'],
 ['UPDATE products SET specs_json=? WHERE id=?',['{bad json',$product],'invalid JSON rejected'],
 ['UPDATE products SET price_toman=-1 WHERE id=?',[$product],'negative price rejected'],
] as [$sql,$args,$label]) {
 $db->pdo->beginTransaction(); $failed=false;
 try {$db->run($sql,$args);} catch(PDOException $e) {$failed=true;}
 $db->pdo->rollBack(); ok($failed,$label);
}
