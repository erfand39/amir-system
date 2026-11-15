<?php
declare(strict_types=1);
require dirname(__DIR__) . "/php/api.php";
error_reporting(E_ALL);
set_error_handler(
    fn($level, $message, $file, $line) => throw new ErrorException(
        $message,
        0,
        $level,
        $file,
        $line,
    ),
);
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
$db = new DB($cfg);
$s = new Shop($db, $cfg);
$s->init();
$passed = 0;
function verify_health(bool $condition, string $label): void
{
    global $passed;
    if (!$condition) {
        throw new RuntimeException("FAIL: " . $label);
    }
    $passed++;
    echo "PASS: " . $label . "\n";
}
$report = health_report($s);
verify_health($report["integrity_ok"], "fresh data reconciles");
verify_health(
    count($report["checks"]) === 12,
    "twelve shared integrity queries execute",
);
verify_health(
    $report["deployment_checks"][0]["status"] === ($db->driver === "mysql" ? "pass" : "warn"),
    "database driver is correctly reported",
);
$u = $s->register(
    [
        "name" => "مدیر تست سلامت",
        "phone" => "09123456789",
        "password" => "Health-test-only-9327",
    ],
    "admin",
);
$session = $s->newSession($u["id"]);
$p = $db->get("SELECT * FROM products WHERE active=1 AND stock>=2 LIMIT 1");
$body = [
    "items" => [["id" => $p["id"], "qty" => 1]],
    "customer_name" => "مشتری تست سلامت",
    "mobile" => "09123456789",
    "province" => "کرمان",
    "city" => "کرمان",
    "address" => "نشانی ساختگی برای تست سلامت داده",
    "postal_code" => "7611111111",
    "payment_method" => "cod",
];
$o = $s->createOrder($body, $session, "php-health-order-0001");
$s->recordPayment(
    $o["id"],
    ["reference" => "PHP-HEALTH-RECEIPT-0001"],
    $session,
);
verify_health(
    health_report($s)["integrity_ok"],
    "paid receipt and stock reconcile",
);
$s->refund(
    $o["id"],
    [
        "reference" => "PHP-HEALTH-REFUND-0001",
        "confirmed" => true,
        "restock" => true,
    ],
    $session,
);
verify_health(
    health_report($s)["integrity_ok"],
    "refund and returned stock reconcile",
);
$entries = $db->all("SELECT id FROM ledger_entries ORDER BY id");
$db->run("UPDATE ledger_lines SET debit=debit+1 WHERE entry_id=? AND debit>0", [
    $entries[0]["id"],
]);
$db->run(
    "UPDATE ledger_lines SET credit=credit+1 WHERE entry_id=? AND credit>0",
    [$entries[1]["id"]],
);
$totals = $db->get("SELECT SUM(debit) d,SUM(credit) c FROM ledger_lines");
verify_health(
    (int) $totals["d"] === (int) $totals["c"],
    "corrupted fixture still globally balanced",
);
verify_health(
    !health_report($s)["ledger_balanced"],
    "individual journal imbalance detected",
);
$db->run("UPDATE products SET stock=stock+1 WHERE id=?", [$p["id"]]);
$report = health_report($s);
$stock = array_values(
    array_filter($report["checks"], fn($c) => $c["id"] === "stock_totals"),
)[0];
verify_health($stock["count"] === 1, "unrecorded stock change detected");
echo "PHP health checks passed: $passed\n";
