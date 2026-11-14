<?php
declare(strict_types=1);
require_once __DIR__ . "/core.php";
require_once __DIR__ . "/admin.php";
require_once __DIR__ . "/payment.php";
require_once __DIR__ . "/health.php";
final class API
{
    public Shop $s;
    public DB $db;
    public Admin $admin;
    public Payment $payment;
    public function __construct(Shop $s)
    {
        $this->s = $s;
        $this->db = $s->db;
        $this->admin = new Admin($s);
        $this->payment = new Payment($s);
    }
    public function handle(
        string $method,
        string $route,
        array $b,
        array $q,
        array $session,
        array $ctx = [],
    ) {
        $s = $this->s;
        $db = $this->db;
        $route = trim($route, "/");
        $parts = explode("/", $route);
        $rotate = $ctx["rotate"] ?? fn($id) => [];
        $ip = $ctx["ip"] ?? "local";
        if ($method === "GET" && $route === "home") {
            return [
                "products" => $s->products(["limit" => 8]),
                "articles" => $this->handle(
                    "GET",
                    "articles",
                    [],
                    [],
                    $session,
                ),
                "bundles" => $this->handle("GET", "bundles", [], [], $session),
            ];
        }
        if ($method === "GET" && $route === "bootstrap") {
            $store = $s->setting("store");
            return [
                ...($q["home"] ?? "") === "1"
                    ? ["home" => $this->handle("GET", "home", [], [], $session)]
                    : [],
                "store" => array_diff_key($store, ["transfer_instructions" => true]),
                "categories" => $db->all(
                    "SELECT id,title,slug FROM categories ORDER BY title",
                ),
                "brands" => $db->all(
                    "SELECT id,title,slug FROM brands ORDER BY title",
                ),
                "vehicles" => $db->all(
                    "SELECT id,title,slug,meta_json FROM vehicles ORDER BY title",
                ),
                "services" => $db->all(
                    "SELECT id,title,price_toman,meta_json FROM services WHERE active=1",
                ),
                "payment" => [
                    "online" => (bool) $s->cfg["merchant"],
                    "cod" => (bool) $store["cod_enabled"],
                    "transfer" => (bool) $store["transfer_enabled"],
                ],
                "user" => $session["user"],
                "checkout_scope" => hash(
                    "sha256",
                    $session["user"]["id"] ?? $session["id"],
                ),
                "csrf" => $session["csrf_token"],
                "setup_required" => !$db->get(
                    "SELECT id FROM users WHERE role='admin'",
                ),
            ];
        }
        if ($method === "POST" && $route === "setup") {
            $s->rate("setup:" . $ip, 5, 60);
            need(
                strlen($s->cfg["setup_token"]) >= 32 &&
                    hash_equals(
                        hash("sha256", $s->cfg["setup_token"]),
                        hash("sha256", (string) ($b["setup_token"] ?? "")),
                    ),
                "کلید نصب معتبر نیست.",
                403,
            );
            $u = $db->tx(function () use ($s, $b, $db) {
                $db->lock("SELECT id FROM settings WHERE id=?", [
                    "schema_version",
                ]);
                need(
                    !$db->get("SELECT id FROM users WHERE role='admin'"),
                    "مدیر اصلی قبلاً ساخته شده است.",
                    409,
                );
                return $s->register($b, "admin");
            });
            return array_merge(["user" => $u], $rotate($u["id"]));
        }
        if ($method === "POST" && $route === "auth/register") {
            $s->rate("register:" . $ip, 5, 60);
            $u = $s->register($b);
            return array_merge(["user" => $u], $rotate($u["id"]));
        }
        if ($method === "POST" && $route === "auth/login") {
            $u = $s->login($b, $ip);
            return array_merge(["user" => $u], $rotate($u["id"]));
        }
        if ($method === "POST" && $route === "auth/logout") {
            $db->run("DELETE FROM sessions WHERE id=?", [$session["id"]]);
            if (isset($ctx["clear_cookie"])) {
                $ctx["clear_cookie"]();
            }
            return ["message" => "از حساب خارج شدید."];
        }
        if ($route === "auth/profile" && $method === "GET") {
            $u = $s->user($session);
            return [
                "user" => $u,
                "addresses" => $db->all(
                    "SELECT * FROM addresses WHERE user_id=?",
                    [$u["id"]],
                ),
            ];
        }
        if ($route === "auth/profile" && $method === "PUT") {
            $u = $s->user($session);
            $db->run("UPDATE users SET name=?,updated_at=? WHERE id=?", [
                txt($b["name"] ?? "", 2, 120),
                dt(),
                $u["id"],
            ]);
            return [
                "user" => public_user(
                    $db->get("SELECT * FROM users WHERE id=?", [$u["id"]]),
                ),
            ];
        }
        if ($route === "auth/password" && $method === "POST") {
            $u = $s->user($session);
            $s->rate("password:" . $u["id"], 5, 60);
            need(
                pw_verify(
                    $b["current_password"] ?? "",
                    $db->get("SELECT password_hash FROM users WHERE id=?", [
                        $u["id"],
                    ])["password_hash"],
                ),
                "رمز فعلی نادرست است.",
            );
            $ph = pw_hash($b["password"] ?? "");
            $db->tx(function () use ($db, $u, $ph, $s) {
                $db->run(
                    "UPDATE users SET password_hash=?,updated_at=? WHERE id=?",
                    [$ph, dt(), $u["id"]],
                );
                $db->run("DELETE FROM sessions WHERE user_id=?", [$u["id"]]);
                $s->audit($u, "user.password_changed", $u["id"]);
            });
            return array_merge(["user" => $u], $rotate($u["id"]));
        }
        if ($method === "POST" && $route === "auth/addresses") {
            $u = $s->user($session);
            $a = $s->address(
                array_merge($b, [
                    "customer_name" => $b["name"] ?? "",
                    "mobile" => $b["phone"] ?? "",
                ]),
            );
            $id = uid("addr-");
            $db->run(
                "INSERT INTO addresses(id,user_id,name,phone,province,city,address,postal_code) VALUES(?,?,?,?,?,?,?,?)",
                [
                    $id,
                    $u["id"],
                    $a["customer_name"],
                    $a["mobile"],
                    $a["province"],
                    $a["city"],
                    $a["address"],
                    $a["postal_code"],
                ],
            );
            return ["id" => $id];
        }
        if (
            $method === "DELETE" &&
            str_starts_with($route, "auth/addresses/") &&
            count($parts) === 3
        ) {
            $u = $s->user($session);
            need(
                $db->run("DELETE FROM addresses WHERE id=? AND user_id=?", [
                    $parts[2],
                    $u["id"],
                ]),
                "آدرس پیدا نشد.",
                404,
            );
            return ["message" => "آدرس حذف شد."];
        }
        if ($method === "GET" && $route === "products") {
            return $s->products($q);
        }
        if (
            $method === "GET" &&
            $parts[0] === "products" &&
            count($parts) === 2
        ) {
            return $s->product(rawurldecode($parts[1]));
        }
        if ($method === "GET" && $route === "bundles") {
            $rows = $db->all(
                "SELECT * FROM bundles WHERE active=1 ORDER BY title",
            );
            foreach ($rows as &$r) {
                $r["items"] = $db->all(
                    "SELECT product_id,qty FROM bundle_items WHERE bundle_id=?",
                    [$r["id"]],
                );
                try {
                    $quote = $s->quote([
                        "items" => [
                            ["kind" => "bundle", "id" => $r["id"], "qty" => 1],
                        ],
                    ]);
                    $r["price"] = $quote["subtotal"];
                    $r["available"] = true;
                } catch (HttpError $e) {
                    $r["price"] = null;
                    $r["available"] = false;
                }
            }
            return $rows;
        }
        if ($method === "POST" && $route === "cart/quote") {
            $s->rate("quote:" . ($session["user"]["id"] ?? $session["id"]), 120, 1);
            return $s->quote($b);
        }
        if ($method === "POST" && $route === "orders") {
            $s->rate("order-ip:" . $ip, 30, 60);
            $s->rate("order:" . ($session["user"]["id"] ?? $session["id"]), 30, 60);
            return $s->createOrder(
                $b,
                $session,
                (string) ($ctx["idempotency_key"] ?? ""),
            );
        }
        if ($method === "GET" && $route === "orders") {
            $u = $s->user($session);
            return $db->all(
                "SELECT id,tracking_code,status,payment_method,payment_status,total_amount,created_at FROM orders WHERE user_id=? ORDER BY created_at DESC",
                [$u["id"]],
            );
        }
        if ($method === "POST" && $route === "orders/recover") {
            $key = txt($b["key"] ?? "", 16, 100);
            need(preg_match('/^[a-zA-Z0-9_-]{16,100}$/D', $key), "شناسه عملیات معتبر نیست.");
            $owner = $session["user"]["id"] ?? null;
            $old = $db->get("SELECT order_id FROM idempotency_keys WHERE id=?", [
                ($owner ?? $session["id"]) . ":" . $key,
            ]);
            if (!$old && $owner) {
                $old = $db->get(
                    "SELECT k.order_id FROM orders o JOIN idempotency_keys k ON k.order_id=o.id
                     WHERE o.user_id=? AND SUBSTR(k.id,1,64)=o.guest_session_id AND SUBSTR(k.id,66)=?",
                    [$owner, $key],
                );
            }
            return $old ? $s->order($old["order_id"], $session) : ["found" => false];
        }
        if ($method === "POST" && $parts[0] === "orders" && count($parts) === 3) {
            if ($parts[2] === "cancel") {
                return $s->cancelByCustomer($parts[1], $session);
            }
            if ($parts[2] === "transfer-receipt") {
                $s->rate("transfer-receipt:" . ($session["user"]["id"] ?? $session["id"]), 12, 60);
                return $s->submitTransferReceipt($parts[1], $b, $session);
            }
        }
        if (
            $method === "GET" &&
            $parts[0] === "orders" &&
            count($parts) === 2
        ) {
            $admin = in_array(
                $session["user"]["role"] ?? "",
                ["admin", "manager"],
                true,
            );
            if ($admin) {
                $s->admin($session);
            }
            return $s->order($parts[1], $session, $admin);
        }
        if ($method === "POST" && $route === "payment/recheck") {
            $s->rate("payment-check:" . ($session["user"]["id"] ?? $session["id"]), 10, 15);
            $isAdmin = in_array($session["user"]["role"] ?? "", ["admin", "manager"], true);
            return $this->payment->recheck(txt($b["order_id"] ?? "", 1, 64), $session, $isAdmin);
        }
        if ($method === "POST" && $route === "payment/request") {
            $s->rate("payment:" . ($session["user"]["id"] ?? $session["id"]), 10, 15);
            return $this->payment->request(
                txt($b["order_id"] ?? "", 1, 64),
                $session,
            );
        }
        if ($method === "GET" && $route === "reviews") {
            need(!empty($q["product_id"]), "محصول را مشخص کنید.");
            return $db->all(
                "SELECT r.id,r.rating,r.text,r.verified,r.created_at,u.name FROM reviews r JOIN users u ON u.id=r.user_id WHERE r.product_id=? AND r.status='approved' ORDER BY r.created_at DESC",
                [$q["product_id"]],
            );
        }
        if ($method === "POST" && $route === "reviews") {
            $u = $s->user($session);
            $s->rate("review:" . $u["id"], 6, 60);
            $s->product((string) ($b["product_id"] ?? ""));
            $verified = (bool) $db->get(
                "SELECT oi.id FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.user_id=? AND oi.product_id=? AND o.status='delivered'",
                [$u["id"], $b["product_id"]],
            );
            need(
                !$db->get(
                    "SELECT id FROM reviews WHERE user_id=? AND product_id=?",
                    [$u["id"], $b["product_id"]],
                ),
                "دیدگاه قبلاً ثبت شده است.",
                409,
            );
            $id = uid("review-");
            $db->run(
                "INSERT INTO reviews(id,product_id,user_id,rating,text,status,verified,created_at) VALUES(?,?,?,?,?,?,?,?)",
                [
                    $id,
                    $b["product_id"],
                    $u["id"],
                    integer_value($b["rating"] ?? null, 1, 5),
                    txt($b["text"] ?? "", 10, 3000),
                    "pending",
                    (int) $verified,
                    dt(),
                ],
            );
            return [
                "id" => $id,
                "message" => "دیدگاه پس از بررسی منتشر می‌شود.",
            ];
        }
        if ($method === "POST" && $route === "contact") {
            $s->rate("contact:" . $ip, 5, 60);
            $phone = mobile($b["phone"] ?? "");
            need(preg_match('/^09\d{9}$/', $phone), "شماره تماس معتبر نیست.");
            $email = trim($b["email"] ?? "");
            need(
                !$email || filter_var($email, FILTER_VALIDATE_EMAIL),
                "ایمیل معتبر نیست.",
            );
            $id = uid("contact-");
            $db->run(
                "INSERT INTO contacts(id,name,phone,email,subject,message,status,created_at) VALUES(?,?,?,?,?,?,?,?)",
                [
                    $id,
                    txt($b["name"] ?? "", 2, 120),
                    $phone,
                    txt($email, 0, 190),
                    txt($b["subject"] ?? "", 2, 120),
                    txt($b["message"] ?? "", 10, 5000),
                    "new",
                    dt(),
                ],
            );
            return [
                "id" => $id,
                "message" => "پیام شما در پنل پشتیبانی ثبت شد.",
            ];
        }
        if ($method === "POST" && $route === "warranty/lookup") {
            $s->rate("warranty:" . $ip, 10, 15);
            $serial = strtoupper(txt($b["serial"] ?? "", 6, 100));
            $w = $db->get(
                "SELECT w.serial,w.starts_at,w.expires_at,w.status,p.title FROM warranties w JOIN products p ON p.id=w.product_id WHERE w.serial=?",
                [$serial],
            );
            return $w
                ? array_merge(["found" => true], $w, [
                    "valid" =>
                        $w["status"] === "active" &&
                        calendar_stamp($w["starts_at"]) <= dt() &&
                        calendar_stamp($w["expires_at"], true) >= dt(),
                ])
                : [
                    "found" => false,
                    "message" => "رکوردی با این شماره سریال پیدا نشد.",
                ];
        }
        if ($method === "GET" && $route === "articles") {
            return $db->all(
                "SELECT id,slug,title,summary,category,created_at FROM articles WHERE published=1 ORDER BY created_at DESC",
            );
        }
        if (
            $method === "GET" &&
            $parts[0] === "articles" &&
            count($parts) === 2
        ) {
            $slug = rawurldecode($parts[1]);
            $a = $db->get(
                "SELECT * FROM articles WHERE (id=? OR slug=?) AND published=1",
                [$slug, $slug],
            );
            need($a, "مقاله پیدا نشد.", 404);
            return $a;
        }
        if ($method === "POST" && $route === "configurator") {
            $s->rate("configurator:" . $ip, 60, 1);
            $budget = integer_value($b["budget"] ?? null, 1);
            $vehicle = txt($b["vehicle_id"] ?? "", 1, 64);
            need(
                $db->get("SELECT id FROM vehicles WHERE id=?", [$vehicle]),
                "خودرو را انتخاب کنید.",
            );
            $candidates = $db->all(
                "SELECT p.*,pv.verified,pv.notes FROM products p JOIN product_vehicles pv ON pv.product_id=p.id WHERE p.active=1 AND p.stock>0 AND pv.vehicle_id=? AND p.price_toman<=? ORDER BY pv.verified DESC,p.price_toman",
                [$vehicle, $budget],
            );
            $cats =
                ($b["profile"] ?? "") === "spl"
                    ? ["cat-subwoofers", "cat-amps", "cat-speakers"]
                    : (($b["profile"] ?? "") === "sq"
                        ? ["cat-speakers", "cat-amps"]
                        : ["cat-speakers"]);
            $items = [];
            $sum = 0;
            foreach ($cats as $cat) {
                foreach ($candidates as $p) {
                    if (
                        $p["category_id"] === $cat &&
                        $sum + (int) $p["price_toman"] <= $budget
                    ) {
                        $sum += (int) $p["price_toman"];
                        $items[] = array_merge($s->publicProduct($p), [
                            "fit_verified" => (bool) $p["verified"],
                        ]);
                        break;
                    }
                }
            }
            return [
                "items" => $items,
                "total_amount" => $sum,
                "budget" => $budget,
                "complete" => count($items) === count($cats),
                "requires_installation_review" => true,
                "note" =>
                    "پیش از خرید، عمق نصب، امپدانس و توان مجموعه باید توسط نصاب تأیید شود.",
            ];
        }
        if ($parts[0] === "admin") {
            $s->admin($session);
            $kind = $parts[1] ?? "";
            $key = $parts[2] ?? null;
            if ($method === "GET" && $route === "admin/overview") {
                return $this->admin->overview($session);
            }
            if ($method === "GET" && $route === "admin/health") {
                return health_report($s);
            }
            if ($method === "GET" && $route === "admin/finance") {
                return $this->admin->finance($session, $q);
            }
            if ($route === "admin/settings" && $method === "GET") {
                $s->admin($session, true);
                return $s->setting("store");
            }
            if ($route === "admin/settings" && $method === "PUT") {
                return $this->admin->settings($b, $session);
            }
            if ($method === "GET" && $kind === "orders" && $key && count($parts) === 3) {
                return $s->order($key, $session, true);
            }
            if ($method === "PUT" && $kind === "orders" && $key && count($parts) === 4) {
                return match ($parts[3]) {
                    "address" => $s->updateOrderAddress($key, $b, $session),
                    "shipment" => $s->updateShipment($key, $b, $session),
                    default => throw new HttpError("مسیر درخواست پیدا نشد.", 404),
                };
            }
            if ($method === "POST" && $kind === "orders" && $key && count($parts) === 4) {
                return match ($parts[3]) {
                    "status" => $s->transition($key, txt($b["status"] ?? "", 2, 32), $session, $b),
                    "record-payment" => $s->recordPayment($key, $b, $session),
                    "resolve-payment" => $this->payment->resolveUnknown($key, $b, $session),
                    "refund" => $s->refund($key, $b, $session),
                    "return" => $s->receiveReturn($key, $b, $session),
                    default => throw new HttpError("مسیر درخواست پیدا نشد.", 404),
                };
            }
            if ($method === "POST" && $kind === "users" && $key && $route === "admin/users/" . $key . "/revoke-sessions") {
                return $this->admin->revokeSessions($key, $session);
            }
            if ($method === "GET" && $kind === "products" && $key) {
                return $s->product($key, true);
            }
            if ($method === "POST" && $route === "admin/stock") {
                return $this->admin->stock($b, $session);
            }
            if ($method === "GET" && count($parts) === 2) {
                return $this->admin->list($kind, $session, $q);
            }
            if (
                in_array($method, ["POST", "PUT"], true) &&
                count($parts) <= 3
            ) {
                return $this->admin->save($kind, $b, $session, $key);
            }
        }
        throw new HttpError("مسیر درخواست پیدا نشد.", 404);
    }
}
