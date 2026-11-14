<?php
declare(strict_types=1);
require dirname(__DIR__) . "/php/api.php";

error_reporting(E_ALL);
set_error_handler(fn($level, $message, $file, $line) => throw new ErrorException($message, 0, $level, $file, $line));
$mysql = getenv("AMIR_TEST_MYSQL") === "true";
if ($mysql && !preg_match('/^amir_test_[a-z0-9_]+$/', getenv("DB_NAME") ?: "")) {
    throw new RuntimeException("Use a disposable amir_test_* database.");
}
putenv("DB_DRIVER=" . ($mysql ? "mysql" : "sqlite"));
putenv("SQLITE_PATH=:memory:");
putenv("AMIR_ALLOW_BOOTSTRAP=true");
$cfg = read_config();
$cfg["merchant"] = "test-fixture";
$cfg["origin"] = "http://localhost";
$db = new DB($cfg);
$shop = new Shop($db, $cfg);
$shop->init();
$api = new API($shop);

$checks = 0;
function check(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException("FAIL: " . $label);
    }
    $checks++;
    echo "PASS: $label\n";
}
function rejects(int $status, callable $fn): bool
{
    try {
        $fn();
    } catch (HttpError $e) {
        return $e->status === $status;
    }
    return false;
}
function actor(Shop $shop, string $role, string $phone): array
{
    $user = $shop->register(["name" => "Workflow " . $role, "phone" => $phone, "password" => "Test-workflow-Long-8723"], $role);
    return $shop->newSession($user["id"]);
}

$owner = actor($shop, "admin", "09111110001");
$manager = actor($shop, "manager", "09111110002");
$buyer = actor($shop, "customer", "09111110003");
$outsider = actor($shop, "customer", "09111110004");
$call = fn($method, $route, $body, $session, $query = []) => $api->handle($method, $route, $body, $query, $session);
$call("PUT", "admin/settings", ["transfer_enabled" => true, "transfer_instructions" => "Workflow-only bank instructions"], $owner);
check(!isset($call("GET", "bootstrap", [], $buyer)["store"]["transfer_instructions"]), "Bank instructions scoped to the order");
$p = $db->get("SELECT id,stock FROM products WHERE active=1 AND stock>=4 ORDER BY id LIMIT 1");
$pid = $p["id"];
$available = fn() => (int) $db->get("SELECT stock FROM products WHERE id=?", [$pid])["stock"];
$base = $available();
$d = [
    "items" => [["kind" => "product", "id" => $pid, "qty" => 2]], "customer_name" => "Workflow Buyer",
    "mobile" => "09111110003", "province" => "Kerman", "city" => "Kerman",
    "address" => "Workflow fixture delivery address", "postal_code" => "7612345678", "payment_method" => "transfer",
];
$bundle = $call("POST", "admin/bundles", ["title" => "Workflow bundle", "discount_bps" => 1000,
    "items" => [["product_id" => $pid, "qty" => 1]], "active" => true], $manager);
$quote = $shop->quote(["items" => [$d["items"][0], ["id" => $bundle["id"], "kind" => "bundle", "qty" => 2]]]);
check($quote["required_stock"][$pid] === 4 && array_sum(array_column($quote["expanded_items"], "line_total")) === $quote["subtotal"],
    "Standalone and bundle quantities share one stock pool and reconcile");
$o = $shop->createOrder($d, $buyer, "test-workflow-transfer-001");
$id = $o["id"];
$route = "admin/orders/" . $id;
check($o["status"] === "pending_payment" && $o["expires_at"] > dt() && $available() === $base - 2, "Transfer starts unpaid with a bounded reservation");
check($shop->createOrder($d, $buyer, "test-workflow-transfer-001")["id"] === $id, "Order replay returns the same order");
check(rejects(403, fn() => $call("GET", "orders/" . $id, [], $outsider)), "Order details require ownership");
check(rejects(409, fn() => $call("POST", $route . "/status", ["status" => "processing"], $manager)), "Unpaid transfer cannot move to processing");
$receipt = $call("POST", "orders/" . $id . "/transfer-receipt", ["reference" => "۷۷۱۲۳۴"], $buyer);
$again = $call("POST", "orders/" . $id . "/transfer-receipt", ["reference" => "771234"], $buyer);
check($receipt["transfer_receipt"]["reference"] === "771234" && $receipt["expires_at"] === $again["expires_at"], "Repeated bank reference is normalized and leaves the deadline unchanged");
check(rejects(409, fn() => $call("POST", "orders/" . $id . "/cancel", [], $buyer)), "Customer cannot cancel a transfer submitted for review");
check(rejects(403, fn() => $call("POST", $route . "/record-payment", ["reference" => "771234"], $manager)), "Manager cannot record funds");
$orders = $call("GET", "admin/orders", [], $manager, ["status" => "transfer_review", "q" => "۰۹۱۱۱۱۱۰۰۰۳"]);
check($orders["total"] === 1 && $orders["items"][0]["id"] === $id, "Admin filters by bank review and normalized phone");
$paid = $call("POST", $route . "/record-payment", ["reference" => "771234"], $owner);
check($paid["status"] === "confirmed" && $paid["payment_status"] === "paid", "Verified transfer unlocks fulfillment");
$changed = $call("PUT", $route . "/address", array_merge($d, ["address" => "Updated Workflow delivery address"]), $manager);
check($changed["address"] !== $d["address"], "Admin corrects delivery address before dispatch");
$call("POST", $route . "/status", ["status" => "processing"], $manager);
check(rejects(400, fn() => $call("POST", $route . "/status", ["status" => "shipped"], $manager)), "Shipment requires carrier and tracking");
$shipping = ["status" => "shipped", "carrier" => "Post", "tracking_code" => "POST-99100"];
$call("POST", $route . "/status", $shipping, $manager);
$call("POST", $route . "/status", $shipping, $manager);
$call("PUT", $route . "/shipment", ["carrier" => "Post", "tracking_code" => "POST-99101"], $manager);
check(rejects(409, fn() => $call("PUT", $route . "/address", $d, $manager)), "Address is locked once dispatched");
$call("POST", $route . "/status", ["status" => "delivered"], $manager);
$shown = $shop->order($id, $buyer);
check($shown["shipment"]["tracking_code"] === "POST-99101" && $shown["shipment"]["delivered_at"], "Customer sees corrected tracking and delivery time");
$call("POST", $route . "/refund", ["reference" => "BANK-REFUND-001", "confirmed" => true], $owner);
check($available() === $base - 2, "Refund after dispatch does not create sellable stock");
$call("POST", $route . "/return", ["confirmed" => true], $manager);
$call("POST", $route . "/return", ["confirmed" => true], $manager);
check($available() === $base, "Physical return after refund restocks exactly once");

$d["payment_method"] = "cod";
$call("PUT", "admin/settings", ["cod_reservation_hours" => 8], $owner);
$expiring = $shop->createOrder($d, $buyer, "workflow-cod-expiration-01");
$expiringPath = "admin/orders/" . $expiring["id"];
check(abs(strtotime($expiring["expires_at"] . " UTC") - time() - 28800) < 5, "New cash order uses the configured reservation");
$db->run("UPDATE orders SET expires_at=? WHERE id=?", [later(-1), $expiring["id"]]);
check(rejects(409, fn() => $call("POST", $expiringPath . "/status", ["status" => "processing"], $manager)) &&
    rejects(409, fn() => $call("POST", $expiringPath . "/record-payment", ["reference" => "COD-EXPIRED-001"], $owner)), "Expired reservation cannot be packed or paid before the next cron run");
$shop->expire();
$shop->expire();
check($shop->order($expiring["id"], $buyer)["status"] === "expired" && $available() === $base, "Cash reservation expiry releases stock exactly once");
$cod = $shop->createOrder($d, $buyer, "test-workflow-cod-000001");
$codPath = "admin/orders/" . $cod["id"];
$call("POST", $codPath . "/status", ["status" => "processing"], $manager);
check($shop->order($cod["id"], $buyer)["expires_at"] === null, "Packing clears the cash reservation deadline");
$call("POST", $codPath . "/status", array_merge($shipping, ["tracking_code" => "POST-99102"]), $manager);
check(rejects(409, fn() => $call("POST", $codPath . "/status", ["status" => "delivered"], $manager)), "Cash on delivery requires collection before delivery");
$call("POST", $codPath . "/record-payment", ["reference" => "COD-RECEIPT-002"], $owner);
$call("POST", $codPath . "/status", ["status" => "delivered"], $manager);
check($shop->order($cod["id"], $buyer)["status"] === "delivered", "Collected cash order can be delivered");
$shipment = $shop->order($cod["id"], $buyer)["shipment"];
$db->run("DELETE FROM order_shipments WHERE order_id=?", [$cod["id"]]);
$db->run("UPDATE orders SET status='shipped' WHERE id=?", [$cod["id"]]);
check(rejects(409, fn() => $call("POST", $codPath . "/status", ["status" => "delivered"], $manager)) &&
    !health_report($shop)["integrity_ok"], "Missing historical shipment blocks delivery and fails the health check");
$db->run("UPDATE orders SET status='delivered' WHERE id=?", [$cod["id"]]);
$history = array_intersect_key($shipment, array_flip(["carrier", "tracking_code", "delivered_at"]));
$historyFixed = $call("PUT", $codPath . "/shipment", $history, $manager);
check($historyFixed["shipment"]["delivered_at"] === $shipment["delivered_at"] &&
    $call("PUT", $codPath . "/shipment", $history, $manager)["shipment"] === $historyFixed["shipment"] &&
    health_report($shop)["integrity_ok"], "Recorded historical delivery is reconciled without changing the order status");

$stock = ["product_id" => $pid, "type" => "in", "quantity" => 2, "notes" => "Delivery note 501", "event_key" => "workflow-stock-key-0001"];
$stockResult = $call("POST", "admin/stock", $stock, $manager);
check($stockResult === $call("POST", "admin/stock", $stock, $manager) && $available() === $base, "Stock receipt retries preserve the balance");
check(rejects(409, fn() => $call("POST", "admin/stock", array_merge($stock, ["notes" => "Different receipt"]), $manager)), "Changed stock receipt with the same key is rejected");
$movements = $call("GET", "admin/stock_movements", [], $manager, ["q" => $pid, "status" => "manual_in"]);
check($movements["items"][0]["notes"] === "Delivery note 501", "Inventory journal carries notes and operator");
$adjust = ["product_id" => $pid, "type" => "adjust", "quantity" => 1, "expected_stock" => $base - 2, "event_key" => "workflow-stock-key-0002"];
check(rejects(409, fn() => $call("POST", "admin/stock", $adjust, $manager)) && $available() === $base, "Stale absolute counts cannot overwrite changes");

$cancel = $shop->createOrder($d, $buyer, "test-workflow-cancel-001");
$filtered = $call("GET", "admin/products", [], $manager, ["q" => $shop->product($pid)["title"]]);
$filteredProduct = array_values(array_filter($filtered["items"], fn($row) => $row["id"] === $pid))[0];
check($filteredProduct["reserved"] === 2 && $filteredProduct["stock"] === $base - 2, "Admin separates sellable and reserved quantities");
$editable = $shop->product($pid, true);
$metadata = $call("PUT", "admin/products/" . $pid, [
    "version" => $editable["version"], "sku" => "shop-۰۰۱", "shelf" => "Shelf A", "reorder_level" => $base + 1,
], $manager);
$low = $call("GET", "admin/products", [], $manager, ["q" => "SHOP-001", "stock" => "low"]);
check($metadata["sku"] === "SHOP-001" && $low["items"][0]["id"] === $pid &&
    !isset($shop->product($pid)["shelf"]), "Inventory metadata is searchable and stays private");
$shop->cancelByCustomer($cancel["id"], $buyer);
$shop->cancelByCustomer($cancel["id"], $buyer);
check($available() === $base, "Customer cancellation releases only once");

$d["payment_method"] = "transfer";
$preShip = $shop->createOrder($d, $buyer, "test-workflow-preship-001");
$shop->recordPayment($preShip["id"], ["reference" => "PRESHIP-001"], $owner);
$shop->refund($preShip["id"], ["reference" => "PRESHIP-REFUND-001", "confirmed" => true], $owner);
check($available() === $base, "Refund before dispatch automatically releases the reservation");

$late = $shop->createOrder($d, $buyer, "test-workflow-late-tr-001");
$db->run("UPDATE orders SET expires_at=? WHERE id=?", [later(-1), $late["id"]]);
$shop->expire();
$late = $shop->recordPayment($late["id"], ["reference" => "LATE-BANK-001"], $owner);
check($late["status"] === "payment_review" && $available() === $base, "Late bank transfer is recorded without reserving sold inventory");
$shop->refund($late["id"], ["reference" => "LATE-BANK-REFUND-001", "confirmed" => true], $owner);

$d["payment_method"] = "online";
$online = $shop->createOrder($d, $buyer, "test-workflow-online-001");
$payment = new Payment($shop, function ($action, $data) use ($online) {
    if ($data["amount"] !== $online["total_amount"] * 10) throw new RuntimeException("Expected IRR amount");
    return $action === "request"
        ? ["code" => 100, "authority" => "ONLINE-AUTH-WORKFLOW1"]
        : ["code" => 101, "ref_id" => "ONLINE-REF-WORKFLOW1"];
});
$declined = new Payment($shop, fn($action, $data) => ["code" => -9]);
check(rejects(409, fn() => $declined->request($online["id"], $buyer)) &&
    $db->get("SELECT status FROM payments WHERE order_id=?", [$online["id"]])["status"] === "failed",
    "Definitive gateway refusal can be retried");
$payment->request($online["id"], $buyer);
$db->run("UPDATE orders SET expires_at=? WHERE id=?", [later(-1), $online["id"]]);
$checked = $payment->recheck($online["id"], $buyer);
$payment->verify("ONLINE-AUTH-WORKFLOW1", "OK");
check($checked["requires_review"] && $available() === $base && $shop->order($online["id"], $buyer)["status"] === "payment_review", "Late gateway verification and replay are reconciled once");

$unknown = $shop->createOrder($d, $buyer, "test-workflow-unknown-online");
$route = "admin/orders/" . $unknown["id"] . "/resolve-payment";
$timeout = new Payment($shop, fn() => throw new HttpError("Provider timeout", 502));
check(rejects(502, fn() => $timeout->request($unknown["id"], $buyer)) &&
    rejects(409, fn() => $payment->request($unknown["id"], $buyer)), "Unknown gateway attempt blocks duplicate requests");
$queue = $call("GET", "admin/orders", [], $owner, ["status" => "unknown_payments"]);
$attempt = $shop->order($unknown["id"], $owner, true)["unresolved_payments"][0]["id"];
check($queue["total"] === 1 && $queue["items"][0]["id"] === $unknown["id"] &&
    $shop->order($unknown["id"], $buyer)["payment_uncertain"] &&
    empty($shop->order($unknown["id"], $buyer)["unresolved_payments"]), "Customer sees the payment state without the admin review record");
$resolution = ["attempt_id" => $attempt, "confirmed" => true, "authority" => "RECOVERED-AUTH-1234"];
check(rejects(403, fn() => $call("POST", $route, $resolution, $manager)) &&
    rejects(409, fn() => $call("POST", $route, ["attempt_id" => $attempt, "confirmed" => true], $owner)), "Only the owner can review and active orders cannot be closed without an authority");
$recovered = $call("POST", $route, $resolution, $owner);
check($recovered["can_recheck_online"] && $recovered["unresolved_payments"] === [] &&
    $call("POST", $route, $resolution, $owner)["can_recheck_online"], "Restored authority enables recheck and is idempotent");
$provider = new Payment($shop, fn() => ["code" => 100, "ref_id" => "RECOVERED-REF-1234"]);
check($provider->recheck($unknown["id"], $owner, true)["paid"], "Recovered payment is recorded only after gateway verification");
$shop->refund($unknown["id"], ["reference" => "RECOVERED-REFUND-002", "confirmed" => true], $owner);
$finance = $call("GET", "admin/finance", [], $owner);
$byMethod = array_column($finance["items"], null, "method");
check($byMethod["online"]["received"] === $online["total_amount"] + $unknown["total_amount"] &&
    $byMethod["online"]["refunded"] === $unknown["total_amount"] &&
    $byMethod["online"]["net"] === $online["total_amount"], "Finance report reconciles receipts and refunds by original method");
check(rejects(403, fn() => $call("GET", "admin/finance", [], $manager)) &&
    rejects(400, fn() => $call("GET", "admin/finance", [], $owner, ["from" => "2025-02-29"])), "Finance report is owner-only and validates its date range");

check(rejects(403, fn() => $call("GET", "admin/users", [], $manager)), "User administration is owner-only");
$found = $call("GET", "admin/users", [], $owner, ["q" => "۰۹۱۱۱۱۱۰۰۰۳"]);
check($found["total"] === 1 && $found["items"][0]["order_count"] >= 5, "Owner can locate users and count their orders");
$call("POST", "admin/users/" . $outsider["user"]["id"] . "/revoke-sessions", [], $owner);
check($shop->session($outsider["token"]) === null, "Revoked session cannot be reused");
$call("PUT", "admin/users/" . $manager["user"]["id"], ["active" => false], $owner);
check($shop->session($manager["token"]) === null, "Disabled manager loses the session");
check(rejects(400, fn() => $call("PUT", "admin/users/" . $owner["user"]["id"], ["active" => false], $owner)), "Last active owner is protected");
$stale = $shop->newSession();
$db->run("UPDATE sessions SET last_seen=? WHERE id=?", [later(-7200), $stale["id"]]);
$db->run("INSERT INTO rate_limits(id,hits,window_end) VALUES(?,?,?)", ["stale-fixture", 1, later(-60)]);
$db->run("INSERT INTO rate_limits(id,hits,window_end) VALUES(?,?,?)", ["active-fixture", 1, later(60)]);
$cleaned = $shop->pruneExpiredState();
check($cleaned["sessions"] >= 1 && $cleaned["rate_limits"] >= 1 &&
    !$db->get("SELECT id FROM sessions WHERE id=?", [$stale["id"]]) &&
    $db->get("SELECT id FROM rate_limits WHERE id='active-fixture'") &&
    $shop->session($owner["token"]), "Maintenance prunes old state and preserves active sessions and counters");
check(health_report($shop)["integrity_ok"], "Inventory, payments, shipments and journals reconcile at the end");
echo "Workflow checks passed: $checks\n";
