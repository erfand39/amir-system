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
$cfg["merchant"] = "";
$db = new DB($cfg);
$shop = new Shop($db, $cfg);
$shop->init();
$api = new API($shop);
$passed = 0;
function accepted(bool $value, string $label): void
{
    global $passed;
    if (!$value) {
        throw new RuntimeException("FAIL: " . $label);
    }
    $passed++;
    echo "PASS: " . $label . "\n";
}
function rejected(callable $fn, string $label): void
{
    try {
        $fn();
    } catch (HttpError $e) {
        accepted($e->status === 400, $label);
        return;
    }
    throw new RuntimeException("Expected rejection: " . $label);
}
$adminUser = $shop->register(
    [
        "name" => "مدیر آزمون پذیرش",
        "phone" => "09123456789",
        "password" => "PHP-acceptance-fixture-7829",
    ],
    "admin",
);
$buyerUser = $shop->register([
    "name" => "مشتری آزمون پذیرش",
    "phone" => "09123456780",
    "password" => "PHP-buyer-fixture-7829",
]);
$admin = $shop->newSession($adminUser["id"]);
$buyer = $shop->newSession($buyerUser["id"]);
$adminCall = fn($route, $body, $method = "POST") => $api->handle(
    $method,
    "admin/" . $route,
    $body,
    [],
    $admin,
);
$buyerCall = fn(
    $route,
    $body = [],
    $method = "GET",
    $query = [],
    $ctx = [],
) => $api->handle($method, $route, $body, $query, $buyer, $ctx);
$cat = $adminCall("categories", [
    "title" => "دسته آزمون",
    "slug" => "php-accept-category",
]);
$brand = $adminCall("brands", [
    "title" => "برند آزمون",
    "slug" => "php-accept-brand",
]);
$product = $adminCall("products", [
    "title" => "کالای آزمون پذیرش",
    "slug" => "php-accept-product",
    "category_id" => $cat["id"],
    "brand_id" => $brand["id"],
    "price_toman" => 1000000,
    "stock" => 5,
    "active" => true,
    "images" => ["/images/placeholder.svg"],
]);
$list = $buyerCall("products", [], "GET", [
    "cat" => "php-accept-category",
    "brand" => "php-accept-brand",
]);
accepted(
    count($list["items"]) === 1 && $list["items"][0]["id"] === $product["id"],
    "custom category and brand slugs resolve admin-created product",
);
$stock = [
    "product_id" => $product["id"],
    "type" => "adjust",
    "expected_stock" => 5,
    "quantity" => 7,
    "event_key" => "php-accept-stock-001",
];
$adminCall("stock", $stock);
$adminCall("stock", $stock);
accepted(
    $buyerCall("products/" . $product["id"])["stock"] === 7,
    "stock adjustment repeat does not duplicate",
);
$service = $adminCall("services", [
    "title" => "خدمت آزمون",
    "price_toman" => 250000,
    "active" => true,
]);
$adminCall(
    "settings",
    [
        "shipping_fee" => 100000,
        "free_shipping_from" => 3000000,
        "installation_free_from" => 10000000,
    ],
    "PUT",
);
$coupon = $adminCall("coupons", [
    "code" => "PHPACCEPT",
    "title" => "تخفیف آزمون",
    "type" => "percentage",
    "value" => 10,
    "usage_limit" => 5,
    "active" => true,
]);
$address = [
    "name" => "تحویل گیرنده آزمایشی",
    "phone" => "09123456780",
    "province" => "کرمان",
    "city" => "کرمان",
    "address" => "نشانی ساختگی آزمون پذیرش نرم افزار",
    "postal_code" => "7611111111",
];
$buyerCall("auth/addresses", $address, "POST");
accepted(
    count($buyerCall("auth/profile")["addresses"]) === 1,
    "customer address persists",
);
$body = [
    "items" => [["id" => $product["id"], "qty" => 2]],
    "coupon" => "PHPACCEPT",
    "service_id" => $service["id"],
    "customer_name" => $address["name"],
    "mobile" => $address["phone"],
    "province" => $address["province"],
    "city" => $address["city"],
    "address" => $address["address"],
    "postal_code" => $address["postal_code"],
    "payment_method" => "cod",
];
$quote = $buyerCall("cart/quote", $body, "POST");
accepted(
    $quote["total_amount"] === 2150000,
    "coupon service and shipping total reconcile",
);
$body["expected_total"] = $quote["total_amount"];
$order = $buyerCall(
    "orders",
    $body,
    "POST",
    [],
    ["idempotency_key" => "php-accept-order-001"],
);
$again = $buyerCall(
    "orders",
    $body,
    "POST",
    [],
    ["idempotency_key" => "php-accept-order-001"],
);
accepted(
    $again["id"] === $order["id"] &&
        $buyerCall("products/" . $product["id"])["stock"] === 5,
    "order repeat reserves inventory once",
);
$adminCall("orders/" . $order["id"] . "/record-payment", [
    "reference" => "PHP-ACCEPT-RECEIPT-001",
]);
$adminCall("orders/" . $order["id"] . "/record-payment", [
    "reference" => "PHP-ACCEPT-RECEIPT-001",
]);
accepted(
    $buyerCall("orders/" . $order["id"])["payment_status"] === "paid",
    "manual receipt visible to owner",
);
foreach (["processing", "shipped", "delivered"] as $status) {
    $adminCall("orders/" . $order["id"] . "/status", ["status" => $status, "carrier" => "پست", "tracking_code" => "TEST-POST-001"]);
}
$review = $buyerCall(
    "reviews",
    [
        "product_id" => $product["id"],
        "rating" => 5,
        "text" => "دیدگاه ساختگی برای آزمون پذیرش نرم افزار",
    ],
    "POST",
);
accepted(
    count(
        $buyerCall("reviews", [], "GET", ["product_id" => $product["id"]]),
    ) === 0,
    "review waits for moderation",
);
$adminCall("reviews/" . $review["id"], ["status" => "approved"], "PUT");
$reviews = $buyerCall("reviews", [], "GET", ["product_id" => $product["id"]]);
accepted(
    count($reviews) === 1 && (int) $reviews[0]["verified"] === 1,
    "published review belongs to delivered buyer",
);
$adminCall("warranties", [
    "serial" => "PHP-ACCEPT-SERIAL-001",
    "product_id" => $product["id"],
    "order_id" => $order["id"],
    "starts_at" => "2020-01-01",
    "expires_at" => gmdate("Y-m-d"),
    "status" => "active",
]);
accepted(
    $buyerCall(
        "warranty/lookup",
        ["serial" => "php-accept-serial-001"],
        "POST",
    )["valid"],
    "warranty stays valid through final day",
);
$adminCall("warranties", [
    "serial" => "PHP-FUTURE-001",
    "product_id" => $product["id"],
    "starts_at" => "2090-01-01",
    "expires_at" => "2091-01-01",
    "status" => "active",
]);
accepted(
    !$buyerCall("warranty/lookup", ["serial" => "PHP-FUTURE-001"], "POST")[
        "valid"
    ],
    "future warranty is not active early",
);
rejected(
    fn() => $adminCall("coupons", [
        "code" => "PHPBADDATE",
        "title" => "تاریخ نامعتبر",
        "type" => "fixed",
        "value" => 100,
        "active" => true,
        "starts_at" => "2026-02-31",
    ]),
    "impossible coupon day rejected",
);
rejected(
    fn() => $adminCall("warranties", [
        "serial" => "PHP-BAD-DATE-001",
        "product_id" => $product["id"],
        "starts_at" => "2026-02-31",
        "expires_at" => "2027-01-01",
    ]),
    "impossible warranty day rejected",
);
rejected(
    fn() => calendar_stamp("2025-02-29"),
    "non-leap February date rejected",
);
accepted(
    calendar_stamp("2024-02-29") === "2024-02-29 00:00:00",
    "leap February date preserved",
);
$lastDay = $adminCall("coupons", [
    "code" => "PHPDAY",
    "title" => "روز پایانی",
    "type" => "fixed",
    "value" => 100,
    "active" => true,
    "expires_at" => gmdate("Y-m-d"),
]);
$db->run("UPDATE coupons SET expires_at=? WHERE id=?", [
    gmdate("Y-m-d"),
    $lastDay["id"],
]);
accepted(
    $buyerCall(
        "cart/quote",
        [
            "items" => [["id" => $product["id"], "qty" => 1]],
            "coupon" => "PHPDAY",
        ],
        "POST",
    )["coupon_amount"] === 100,
    "legacy date-only coupon valid through final day",
);
$contact = $buyerCall(
    "contact",
    [
        "name" => "آزمون تماس",
        "phone" => "09123456780",
        "email" => "",
        "subject" => "پیگیری آزمایشی",
        "message" => "پیام ساختگی برای آزمون پذیرش نرم افزار",
    ],
    "POST",
);
$adminCall("contacts/" . $contact["id"], ["status" => "answered"], "PUT");
accepted(
    $db->get("SELECT status FROM contacts WHERE id=?", [$contact["id"]])[
        "status"
    ] === "answered",
    "support message handled",
);
$refund = [
    "reference" => "PHP-ACCEPT-REFUND-001",
    "confirmed" => true,
    "restock" => true,
];
$adminCall("orders/" . $order["id"] . "/refund", $refund);
$adminCall("orders/" . $order["id"] . "/refund", $refund);
accepted(
    $buyerCall("products/" . $product["id"])["stock"] === 7 &&
        $buyerCall("orders/" . $order["id"])["payment_status"] === "refunded",
    "refund repeat restores inventory once",
);
accepted(
    (int) $db->get("SELECT used_count FROM coupons WHERE id=?", [
        $coupon["id"],
    ])["used_count"] === 0,
    "coupon capacity restored",
);
accepted(
    count($adminCall("ledger_entries", [], "GET")) === 2,
    "one receipt and one refund journal",
);
accepted(
    $adminCall("health", [], "GET")["integrity_ok"],
    "final inventory orders and journals reconcile",
);
accepted(
    count(($db->driver === "sqlite" ? $db->all("PRAGMA foreign_key_check") : [])) === 0,
    "FK enforcement completed",
);
echo "PHP acceptance checks passed: $passed\n";
