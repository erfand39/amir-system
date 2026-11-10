<?php
declare(strict_types=1);
require dirname(__DIR__) . "/php/api.php";
error_reporting(E_ALL);
set_error_handler(function ($level, $message, $file, $line) {
    throw new ErrorException($message, 0, $level, $file, $line);
});
if (getenv('AMIR_TEST_MYSQL') === 'true') {
    if (!preg_match('/^amir_test_[a-z0-9_]+$/', getenv('DB_NAME') ?: '')) {
        throw new RuntimeException('Tests require a disposable amir_test_* database.');
    }
    putenv('DB_DRIVER=mysql');
} else {
    putenv('DB_DRIVER=sqlite');
}
putenv('AMIR_ALLOW_BOOTSTRAP=true');
putenv("SQLITE_PATH=:memory:");
$cfg = read_config();
$cfg["driver"] = getenv("DB_DRIVER");
$cfg["merchant"] = "";
$cfg["origin"] = "http://localhost";
$db = new DB($cfg);
$s = new Shop($db, $cfg);
$s->init();
$api = new API($s);
$homeSession = $s->newSession();
$home = $api->handle("GET", "bootstrap", [], ["home" => "1"], $homeSession);
if (
    count($home["home"]["products"]["items"]) !== 8 ||
    !is_array($home["home"]["articles"]) ||
    $home["user"] !== null
) {
    throw new RuntimeException("Combined public home contract failed");
}
$passed = 0;
function check($condition, string $name): void
{
    global $passed;
    if (!$condition) {
        throw new RuntimeException("FAIL: " . $name);
    }
    $passed++;
    echo "PASS: " . $name . "\n";
}
function denied(callable $fn, int $status = 400): void
{
    try {
        $fn();
    } catch (HttpError $e) {
        check($e->status === $status, "expected HTTP " . $status);
        return;
    }
    throw new RuntimeException("Expected rejection");
}
check(
    (int) $db->get("SELECT COUNT(*) n FROM products")["n"] === 128,
    "128 catalog products seeded",
);
check(count(($db->driver === "sqlite" ? $db->all("PRAGMA foreign_key_check") : [])) === 0, "foreign keys valid");
$health = health_report($s);
check(
    $health["integrity_ok"] === true && count($health["checks"]) === 10,
    "shared integrity checks pass on clean database",
);
check(
    $s->session("admin-session-fake") === null,
    "forged admin token rejected",
);
$u = $s->register(
    [
        "name" => "مدیر تست",
        "phone" => "09123456789",
        "password" => "Contract-only-long-password",
    ],
    "admin",
);
$session = $s->newSession($u["id"]);
$admin = new Admin($s);
$p = $db->get("SELECT * FROM products LIMIT 1");
$db->run(
    "UPDATE products SET price_toman=1000000,stock=5,active=1 WHERE id=?",
    [$p["id"]],
);
$body = [
    "items" => [["id" => $p["id"], "qty" => 2]],
    "customer_name" => "خریدار آزمایشی",
    "mobile" => "09123456789",
    "province" => "کرمان",
    "city" => "کرمان",
    "address" => "نشانی آزمایشی نرم افزار فروشگاه",
    "postal_code" => "7611111111",
    "payment_method" => "cod",
    "total_amount" => 1,
];
$o = $s->createOrder($body, $session, "php-order-key-00001");
check($o["total_amount"] === 2150000, "server priced order");
check(
    $s->createOrder($body, $session, "php-order-key-00001")["id"] === $o["id"],
    "durable idempotency",
);
check(
    (int) $db->get("SELECT stock FROM products WHERE id=?", [$p["id"]])[
        "stock"
    ] === 3,
    "actual stock reserved",
);
check(
    (int) $db->get("SELECT COUNT(*) n FROM ledger_entries")["n"] === 0,
    "unpaid orders have no revenue",
);
$bad = $body;
$bad["items"][0]["qty"] = 99;
denied(fn() => $s->createOrder($bad, $session, "php-rejected-00001"), 409);
check(
    (int) $db->get("SELECT COUNT(*) n FROM orders")["n"] === 1,
    "rejected order rolls back",
);
$s->transition($o["id"], "cancelled", $session);
$s->transition($o["id"], "cancelled", $session);
check(
    (int) $db->get("SELECT stock FROM products WHERE id=?", [$p["id"]])[
        "stock"
    ] === 5,
    "cancel releases once",
);
denied(fn() => $s->transition($o["id"], "confirmed", $session), 409);
$receipt = [
    "product_id" => $p["id"],
    "type" => "adjust",
    "expected_stock" => 5,
    "quantity" => 3,
    "event_key" => "php-stock-key-0001",
];
$admin->stock($receipt, $session);
$admin->stock($receipt, $session);
check(
    (int) $db->get("SELECT stock FROM products WHERE id=?", [$p["id"]])[
        "stock"
    ] === 3,
    "stock adjust target idempotent",
);
$receipt["quantity"] = 4;
denied(fn() => $admin->stock($receipt, $session), 409);
$o = $s->createOrder($body, $session, "php-paid-order-0001");
$s->recordPayment($o["id"], ["reference" => "PHP-BANK-0001"], $session);
$s->recordPayment($o["id"], ["reference" => "PHP-BANK-0001"], $session);
$sum = $db->get("SELECT SUM(debit) debit,SUM(credit) credit FROM ledger_lines");
check(
    (int) $sum["debit"] === 2150000 &&
        (int) $sum["debit"] === (int) $sum["credit"],
    "receipt balanced once",
);
$s->refund(
    $o["id"],
    ["reference" => "PHP-REFUND-0001", "confirmed" => true, "restock" => true],
    $session,
);
$s->refund(
    $o["id"],
    ["reference" => "PHP-REFUND-0001", "confirmed" => true, "restock" => true],
    $session,
);
check(
    (int) $db->get("SELECT stock FROM products WHERE id=?", [$p["id"]])[
        "stock"
    ] === 3,
    "refund restock once",
);
check(
    (int) $db->get("SELECT COUNT(*) n FROM ledger_entries")["n"] === 2,
    "one receipt and one refund journal",
);
$anon = $s->newSession();
denied(fn() => $api->handle("GET", "admin/users", [], [], $anon), 401);
denied(fn() => $s->order($o["id"], $anon), 403);
denied(fn() => $api->handle("GET", "unknown", [], [], $anon), 404);
denied(fn() => $api->handle("GET", "users", [], [], $anon), 404);
$contact = $api->handle(
    "POST",
    "contact",
    [
        "name" => "مشتری تست",
        "phone" => "09123456789",
        "email" => "",
        "subject" => "بررسی فنی",
        "message" => "پیام آزمایشی برای پشتیبانی",
    ],
    [],
    $anon,
);
check(
    (bool) $db->get("SELECT id FROM contacts WHERE id=?", [$contact["id"]]),
    "contact persists",
);
$w = $api->handle(
    "POST",
    "warranty/lookup",
    ["serial" => "UNKNOWN-0001"],
    [],
    $anon,
);
check($w["found"] === false, "unknown warranty has no fake validation");
$admin->save(
    "coupons",
    [
        "code" => "PHPTEST",
        "title" => "کد تست",
        "type" => "percentage",
        "value" => 10,
        "min_purchase" => 0,
        "usage_limit" => 1,
        "active" => true,
    ],
    $session,
);
$q = $s->quote([
    "items" => [["id" => $p["id"], "qty" => 1]],
    "coupon" => "PHPTEST",
]);
check($q["coupon_amount"] === 100000, "coupon actual calculation");
$b = $admin->save(
    "bundles",
    [
        "title" => "پکیج تست",
        "slug" => "php-bundle",
        "items" => [["product_id" => $p["id"], "qty" => 1]],
        "active" => true,
        "discount_bps" => 1000,
    ],
    $session,
);
check(
    $s->quote([
        "items" => [["id" => $b["id"], "kind" => "bundle", "qty" => 1]],
    ])["subtotal"] === 900000,
    "bundle components and discount",
);
$s->cfg["merchant"] = "fixture-only";
$online = $body;
$online["items"][0]["qty"] = 1;
$online["payment_method"] = "online";
$o = $s->createOrder($online, $session, "php-online-key-0001");
$payment = new Payment(
    $s,
    fn($action, $data) => $action === "request"
        ? ["code" => 100, "authority" => "PHPFIXTUREAUTH0001"]
        : ["code" => 100, "ref_id" => "PHP-PROVIDER-0001"],
);
denied(fn() => $payment->verify("PHPFIXTUREAUTH0001", "OK"), 404);
$payment->request($o["id"], $session);
$payment->verify("PHPFIXTUREAUTH0001", "OK");
$payment->verify("PHPFIXTUREAUTH0001", "OK");
check(
    $s->order($o["id"], $session)["payment_status"] === "paid",
    "provider verified payment only",
);
check(
    count(($db->driver === "sqlite" ? $db->all("PRAGMA foreign_key_check") : [])) === 0,
    "final foreign keys valid",
);
echo "PHP contract checks passed: $passed\n";
