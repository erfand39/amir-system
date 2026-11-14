<?php
declare(strict_types=1);
final class Admin
{
    public Shop $shop;
    public DB $db;
    public function __construct(Shop $shop)
    {
        $this->shop = $shop;
        $this->db = $shop->db;
    }
    public function overview(array $s): array
    {
        $this->shop->admin($s);
        $out = [];
        foreach (
            [
                "products" => "SELECT COUNT(*) n FROM products",
                "orders" => "SELECT COUNT(*) n FROM orders",
                "ready_to_pack" => "SELECT COUNT(*) n FROM orders WHERE status IN ('confirmed','processing') AND (payment_status='paid' OR payment_method='cod') AND stock_released=0",
                "transfer_review" => "SELECT COUNT(*) n FROM order_transfer_receipts t JOIN orders o ON o.id=t.order_id WHERE o.payment_status='unpaid' AND o.status IN ('pending_payment','confirmed','processing')",
                "payment_review" => "SELECT COUNT(*) n FROM orders WHERE status='payment_review'",
                "unknown_payments" => "SELECT COUNT(*) n FROM orders o WHERE o.payment_status='unpaid' AND EXISTS (
                    SELECT 1 FROM payments p WHERE p.order_id=o.id AND p.authority IS NULL
                    AND (p.status='unknown' OR (p.status='requesting' AND p.updated_at<=?)))",
                "returns_expected" => "SELECT COUNT(*) n FROM orders WHERE status='refunded' AND stock_released=0",
                "low_stock" =>
                    "SELECT COUNT(*) n FROM products WHERE active=1 AND stock<3",
                "paid_amount" =>
                    "SELECT COALESCE(SUM(total_amount),0) n FROM orders WHERE payment_status='paid'",
                "new_messages" =>
                    "SELECT COUNT(*) n FROM contacts WHERE status='new'",
                "pending_reviews" =>
                    "SELECT COUNT(*) n FROM reviews WHERE status='pending'",
            ]
            as $key => $sql
        ) {
            $out[$key] = (int) $this->db->get($sql, $key === "unknown_payments" ? [later(-120)] : [])["n"];
        }
        $out["engine"] = $this->db->driver;
        return $out;
    }
    public function list(string $kind, array $s, array $q = []): array
    {
        $this->shop->admin($s);
        if ($kind === "products") {
            $result = $this->shop->products($q, true);
            $ids = array_column($result["items"], "id");
            if ($ids) {
                $slots = implode(",", array_fill(0, count($ids), "?"));
                $reserved = array_column($this->db->all(
                    "SELECT i.product_id,SUM(i.qty) qty FROM order_items i JOIN orders o ON o.id=i.order_id
                     WHERE i.product_id IN ($slots) AND o.stock_released=0 AND o.status IN ('pending_payment','confirmed','processing')
                     GROUP BY i.product_id", $ids,
                ), "qty", "product_id");
                foreach ($result["items"] as &$product) {
                    $product["reserved"] = (int) ($reserved[$product["id"]] ?? 0);
                }
            }
            return $result;
        }
        $allowed = [
            "categories" => "title",
            "brands" => "title",
            "vehicles" => "title",
            "bundles" => "title",
            "coupons" => "code",
            "services" => "title",
            "contacts" => "created_at DESC",
            "reviews" => "created_at DESC",
            "warranties" => "starts_at DESC",
            "articles" => "created_at DESC",
            "audit_logs" => "created_at DESC",
            "ledger_entries" => "created_at DESC",
            "payments" => "created_at DESC",
        ];
        if ($kind === "users") {
            $this->shop->admin($s, true);
        }
        if (in_array($kind, ["orders", "users", "stock_movements"], true)) {
            return $this->operationalList($kind, $q);
        }
        need(isset($allowed[$kind]), "بخش پیدا نشد.", 404);
        $rows = $this->db->all(
            "SELECT * FROM " .
                $kind .
                " ORDER BY " .
                $allowed[$kind] .
                " LIMIT 500",
        );
        foreach ($rows as &$r) {
            unset($r["quote_json"], $r["guest_session_id"]);
            if (isset($r["meta_json"])) {
                $r["meta"] = decode_json($r["meta_json"]);
                unset($r["meta_json"]);
            }
            if ($kind === "bundles") {
                $r["items"] = $this->db->all(
                    "SELECT product_id,qty FROM bundle_items WHERE bundle_id=?",
                    [$r["id"]],
                );
            }
            if ($kind === "ledger_entries") {
                $r["lines"] = $this->db->all(
                    "SELECT account,debit,credit FROM ledger_lines WHERE entry_id=?",
                    [$r["id"]],
                );
            }
        }
        return $rows;
    }
    public function finance(array $session, array $query): array
    {
        $this->shop->admin($session, true);
        $zone = new DateTimeZone("Asia/Tehran");
        $utc = new DateTimeZone("UTC");
        $today = new DateTimeImmutable("today", $zone);
        $days = [];
        foreach (["from" => $today->modify("-29 days"), "to" => $today] as $key => $default) {
            $value = $query[$key] ?? $default->format("Y-m-d");
            need(is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value), "قالب تاریخ معتبر نیست.");
            calendar_stamp($value);
            $days[$key] = new DateTimeImmutable($value, $zone);
        }
        need($days["from"] <= $days["to"] && $days["from"]->diff($days["to"])->days <= 365,
            "بازهٔ گزارش باید حداکثر ۳۶۶ روز باشد.");
        $rows = $this->db->all(
            "SELECT CASE WHEN p.method='refund' THEN o.payment_method ELSE p.method END AS method,
                SUM(CASE WHEN p.status='paid' THEN p.amount ELSE 0 END) AS received,
                SUM(CASE WHEN p.status='refunded' THEN p.amount ELSE 0 END) AS refunded,
                SUM(CASE WHEN p.status='paid' THEN 1 ELSE 0 END) AS payment_count,
                SUM(CASE WHEN p.status='refunded' THEN 1 ELSE 0 END) AS refund_count
             FROM payments p JOIN orders o ON o.id=p.order_id
             WHERE p.status IN ('paid','refunded') AND p.updated_at>=? AND p.updated_at<?
             GROUP BY CASE WHEN p.method='refund' THEN o.payment_method ELSE p.method END ORDER BY method",
            [
                $days["from"]->setTimezone($utc)->format("Y-m-d H:i:s"),
                $days["to"]->modify("+1 day")->setTimezone($utc)->format("Y-m-d H:i:s"),
            ],
        );
        foreach ($rows as &$row) {
            foreach (["received", "refunded", "payment_count", "refund_count"] as $field) {
                $row[$field] = (int) $row[$field];
            }
            $row["net"] = $row["received"] - $row["refunded"];
        }
        return ["from" => $days["from"]->format("Y-m-d"), "to" => $days["to"]->format("Y-m-d"), "items" => $rows];
    }
    private function operationalList(string $kind, array $q): array
    {
        $page = integer_value($q["page"] ?? 1, 1, 1000000);
        $limit = 30;
        $filters = [];
        $args = [];
        $search = en(txt($q["q"] ?? "", 0, 120));
        $status = txt($q["status"] ?? "", 0, 40);
        $pattern = "%" . strtr($search, ["!" => "!!", "%" => "!%", "_" => "!_"]) . "%";
        switch ($kind) {
            case "orders":
                $from = "orders o LEFT JOIN order_shipments sh ON sh.order_id=o.id
                    LEFT JOIN order_transfer_receipts tr ON tr.order_id=o.id";
                $select = "o.id,o.tracking_code,o.customer_name,o.mobile,o.status,o.payment_method,o.payment_status,o.total_amount,o.created_at,
                    sh.tracking_code shipment_tracking,tr.reference transfer_reference";
                $sort = "o.created_at DESC,o.id DESC";
                if ($search !== "") {
                    $filters[] = "(o.tracking_code LIKE ? ESCAPE '!' OR o.mobile LIKE ? ESCAPE '!' OR o.customer_name LIKE ? ESCAPE '!' OR sh.tracking_code LIKE ? ESCAPE '!')";
                    $args = [$pattern, $pattern, $pattern, $pattern];
                }
                if ($status === "transfer_review") {
                    $filters[] = "tr.order_id IS NOT NULL AND o.payment_status='unpaid' AND o.status IN ('pending_payment','confirmed','processing')";
                } elseif ($status === "ready_to_pack") {
                    $filters[] = "o.status IN ('confirmed','processing') AND (o.payment_status='paid' OR o.payment_method='cod') AND o.stock_released=0";
                } elseif ($status === "unknown_payments") {
                    $filters[] = "o.payment_status='unpaid' AND EXISTS (SELECT 1 FROM payments p
                        WHERE p.order_id=o.id AND p.authority IS NULL
                        AND (p.status='unknown' OR (p.status='requesting' AND p.updated_at<=?)))";
                    $args[] = later(-120);
                } elseif ($status === "return_expected") {
                    $filters[] = "o.status='refunded' AND o.stock_released=0";
                } elseif ($status !== "") {
                    $filters[] = "o.status=?";
                    $args[] = $status;
                }
                if (!empty($q["user_id"])) {
                    $filters[] = "o.user_id=?";
                    $args[] = txt($q["user_id"], 1, 64);
                }
                break;
            case "users":
                $from = "users u";
                $select = "u.id,u.name,u.phone,u.role,u.active,u.created_at,(SELECT COUNT(*) FROM orders o WHERE o.user_id=u.id) order_count";
                $sort = "u.created_at DESC,u.id DESC";
                if ($search !== "") {
                    $filters[] = "(u.phone LIKE ? ESCAPE '!' OR u.name LIKE ? ESCAPE '!')";
                    $args = [$pattern, $pattern];
                }
                if ($status !== "") {
                    need(in_array($status, ["admin", "manager", "customer", "inactive"], true), "فیلتر کاربران معتبر نیست.");
                    $filters[] = $status === "inactive" ? "u.active=0" : "u.role=?";
                    if ($status !== "inactive") {
                        $args[] = $status;
                    }
                }
                break;
            case "stock_movements":
                $from = "stock_movements m JOIN products p ON p.id=m.product_id
                    LEFT JOIN users u ON u.id=m.actor_id LEFT JOIN orders o ON o.id=m.order_id
                    LEFT JOIN stock_receipt_details d ON d.event_key=m.event_key";
                $select = "m.id,m.product_id,m.delta,m.balance,m.reason,m.created_at,m.order_id,
                    p.title product_title,u.name actor,o.tracking_code order_tracking,d.notes";
                $sort = "m.created_at DESC,m.id DESC";
                if ($search !== "") {
                    $filters[] = "(p.title LIKE ? ESCAPE '!' OR p.id LIKE ? ESCAPE '!' OR o.tracking_code LIKE ? ESCAPE '!')";
                    $args = [$pattern, $pattern, $pattern];
                }
                if ($status !== "") {
                    $filters[] = "m.reason=?";
                    $args[] = $status;
                }
                break;
            default:
                throw new HttpError("بخش پیدا نشد.", 404);
        }
        $where = $filters ? " WHERE " . implode(" AND ", $filters) : "";
        $total = (int) $this->db->get("SELECT COUNT(*) n FROM $from$where", $args)["n"];
        $pages = max(1, (int) ceil($total / $limit));
        $page = min($page, $pages);
        $offset = ($page - 1) * $limit;
        $items = $this->db->all("SELECT $select FROM $from$where ORDER BY $sort LIMIT $limit OFFSET $offset", $args);
        return ["items" => $items, "page" => $page, "pages" => $pages, "total" => $total];
    }
    public function revokeSessions(string $id, array $s): array
    {
        $actor = $this->shop->admin($s, true);
        need($actor["id"] !== $id, "برای پایان نشست خود از خروج یا تغییر رمز استفاده کنید.", 409);
        return $this->db->tx(function () use ($id, $actor) {
            need($this->db->lock("SELECT id FROM users WHERE id=?", [$id]), "کاربر پیدا نشد.", 404);
            $count = $this->db->run("DELETE FROM sessions WHERE user_id=?", [$id]);
            if ($count) {
                $this->shop->audit($actor, "user.sessions_revoked", $id, ["count" => $count]);
            }
            return ["revoked" => $count];
        });
    }
    public function productData(array $d, ?array $old): array
    {
        $images = $d["images"] ?? decode_json($old["images_json"] ?? "[]");
        need(
            is_array($images) && count($images) <= 12,
            "حداکثر ۱۲ تصویر مجاز است.",
        );
        foreach ($images as $url) {
            safe_url($url);
        }
        $specs = $d["specs"] ?? decode_json($old["specs_json"] ?? "{}");
        need(
            is_array($specs) && strlen(encode_json($specs)) < 15000,
            "مشخصات محصول معتبر نیست.",
        );
        $p = [
            "id" => $old["id"] ?? uid("prod-"),
            "slug" => txt(
                $d["slug"] ?? ($old["slug"] ?? uid("product-")),
                1,
                180,
            ),
            "title" => txt($d["title"] ?? ($old["title"] ?? ""), 2, 255),
            "description" => txt(
                $d["description"] ?? ($old["description"] ?? ""),
                0,
                20000,
            ),
            "category_id" => txt(
                $d["category_id"] ?? ($old["category_id"] ?? ""),
                1,
                64,
            ),
            "brand_id" => txt(
                $d["brand_id"] ?? ($old["brand_id"] ?? ""),
                1,
                64,
            ),
            "price_toman" => integer_value(
                $d["price_toman"] ?? ($old["price_toman"] ?? null),
            ),
            "stock" => $old
                ? (int) $old["stock"]
                : integer_value($d["stock"] ?? 0, 0, 1000000),
            "pricing_mode" =>
                $d["pricing_mode"] ?? ($old["pricing_mode"] ?? "fixed"),
            "price_usd" =>
                (float) ($d["price_usd"] ?? ($old["price_usd"] ?? 0)),
            "discount_usd" =>
                (float) ($d["discount_usd"] ?? ($old["discount_usd"] ?? 0)),
            "active" =>
                $d["active"] ??
                (isset($old["active"]) ? (bool) $old["active"] : true),
            "featured" => $d["featured"] ?? (bool) ($old["featured"] ?? false),
            "warranty_months" => isset($d["warranty_months"])
                ? integer_value($d["warranty_months"], 0, 120)
                : $old["warranty_months"] ?? null,
            "images" => $images,
            "specs" => $specs,
            "tags" => $d["tags"] ?? decode_json($old["tags_json"] ?? "[]"),
        ];
        need(
            in_array($p["pricing_mode"], ["fixed", "usd"], true) &&
                is_finite($p["price_usd"]) &&
                $p["price_usd"] >= 0 &&
                $p["price_usd"] <= 1e9 &&
                is_finite($p["discount_usd"]) &&
                $p["discount_usd"] >= 0 &&
                $p["discount_usd"] <= $p["price_usd"],
            "قیمت دلاری معتبر نیست.",
        );
        if ($p["pricing_mode"] === "usd") {
            $p["price_toman"] = (int) round(
                ($p["price_usd"] - $p["discount_usd"]) *
                    $this->shop->setting("store")["currency_rate"],
            );
        }
        return $p;
    }
    public function save(
        string $kind,
        array $d,
        array $s,
        ?string $key = null,
    ): array {
        $actor = $this->shop->admin(
            $s,
            in_array(
                $kind,
                ["users", "coupons", "warranties", "services"],
                true,
            ),
        );
        return $this->db->tx(function () use ($kind, $d, $s, $key, $actor) {
            $allowed = [
                "products",
                "categories",
                "brands",
                "vehicles",
                "bundles",
                "coupons",
                "articles",
                "services",
                "warranties",
                "reviews",
                "contacts",
                "users",
            ];
            need(
                in_array($kind, $allowed, true),
                "عملیات پشتیبانی نمی‌شود.",
                404,
            );
            if ($kind === "products") {
                $this->db->lock("SELECT id FROM settings WHERE id=?", ["store"]);
            }
            if ($key) {
                need(
                    $this->db->get("SELECT id FROM " . $kind . " WHERE id=?", [
                        $key,
                    ]),
                    "رکورد پیدا نشد.",
                    404,
                );
            }
            $id = $key ?? uid(substr($kind, 0, 3) . "-");
            if ($kind === "products") {
                $old = $key
                    ? $this->db->lock("SELECT * FROM products WHERE id=?", [
                        $key,
                    ])
                    : null;
                if ($old) {
                    need(
                        (int) ($d["version"] ?? 0) === (int) $old["version"],
                        "این محصول تغییر کرده است؛ اطلاعات را تازه کنید.",
                        409,
                    );
                }
                $p = $this->productData($d, $old);
                $id = $p["id"];
                if ($old) {
                    $this->db->run(
                        "UPDATE products SET title=?,slug=?,description=?,category_id=?,brand_id=?,price_toman=?,price_usd=?,discount_usd=?,pricing_mode=?,active=?,featured=?,warranty_months=?,specs_json=?,images_json=?,tags_json=?,version=version+1,updated_at=? WHERE id=?",
                        [
                            $p["title"],
                            $p["slug"],
                            $p["description"],
                            $p["category_id"],
                            $p["brand_id"],
                            $p["price_toman"],
                            $p["price_usd"],
                            $p["discount_usd"],
                            $p["pricing_mode"],
                            (int) $p["active"],
                            (int) $p["featured"],
                            $p["warranty_months"],
                            encode_json((object) $p["specs"]),
                            encode_json($p["images"]),
                            encode_json($p["tags"]),
                            dt(),
                            $id,
                        ],
                    );
                } else {
                    $this->shop->insertProduct($p);
                    if ($p["stock"]) {
                        $this->shop->movement(
                            $id,
                            $p["stock"],
                            $p["stock"],
                            "initial",
                            "initial:" . $id,
                            null,
                            $actor,
                        );
                    }
                }
                if (isset($d["vehicle_fits"])) {
                    need(
                        is_array($d["vehicle_fits"]) &&
                            count($d["vehicle_fits"]) <= 100,
                    );
                    $this->db->run(
                        "DELETE FROM product_vehicles WHERE product_id=?",
                        [$id],
                    );
                    foreach ($d["vehicle_fits"] as $fit) {
                        $this->db->run(
                            "INSERT INTO product_vehicles(id,product_id,vehicle_id,verified,notes) VALUES(?,?,?,?,?)",
                            [
                                $id . ":" . $fit["vehicle_id"],
                                $id,
                                $fit["vehicle_id"],
                                !empty($fit["verified"]) ? 1 : 0,
                                txt($fit["notes"] ?? "", 0, 1000),
                            ],
                        );
                    }
                }
                $this->shop->audit(
                    $actor,
                    $old ? "product.updated" : "product.created",
                    $id,
                );
                return $this->shop->product($id, true);
            }
            if (in_array($kind, ["categories", "brands", "vehicles"], true)) {
                $slug = txt($d["slug"] ?? "", 1, 180);
                need(
                    !preg_match("#[\s/?<>\#]#", $slug),
                    "نشانی کوتاه معتبر نیست.",
                );
                $v = [
                    txt($d["title"] ?? "", 2, 160),
                    $slug,
                    encode_json($d["meta"] ?? new stdClass()),
                ];
                if ($key) {
                    $this->db->run(
                        "UPDATE " .
                            $kind .
                            " SET title=?,slug=?,meta_json=? WHERE id=?",
                        [...$v, $id],
                    );
                } else {
                    $this->db->run(
                        "INSERT INTO " .
                            $kind .
                            "(title,slug,meta_json,id) VALUES(?,?,?,?)",
                        [...$v, $id],
                    );
                }
            } elseif ($kind === "bundles") {
                $items = $d["items"] ?? null;
                need(
                    is_array($items) && count($items) <= 20,
                    "قطعات پکیج معتبر نیست.",
                );
                need(
                    empty($d["active"]) || count($items) > 0,
                    "قطعات پکیج را مشخص کنید.",
                );
                $v = [
                    txt($d["title"] ?? "", 2, 255),
                    txt($d["slug"] ?? $id, 1, 180),
                    txt($d["description"] ?? "", 0, 10000),
                    integer_value($d["discount_bps"] ?? 0, 0, 5000),
                    !empty($d["active"]) ? 1 : 0,
                    encode_json($d["meta"] ?? new stdClass()),
                ];
                if ($key) {
                    $this->db->run(
                        "UPDATE bundles SET title=?,slug=?,description=?,discount_bps=?,active=?,meta_json=? WHERE id=?",
                        [...$v, $id],
                    );
                } else {
                    $this->db->run(
                        "INSERT INTO bundles(title,slug,description,discount_bps,active,meta_json,id) VALUES(?,?,?,?,?,?,?)",
                        [...$v, $id],
                    );
                }
                $this->db->run("DELETE FROM bundle_items WHERE bundle_id=?", [
                    $id,
                ]);
                foreach ($items as $part) {
                    $this->db->run(
                        "INSERT INTO bundle_items(id,bundle_id,product_id,qty) VALUES(?,?,?,?)",
                        [
                            $id . ":" . $part["product_id"],
                            $id,
                            $part["product_id"],
                            integer_value($part["qty"] ?? null, 1, 99),
                        ],
                    );
                }
            } elseif ($kind === "coupons") {
                $code = strtoupper(txt($d["code"] ?? "", 3, 64));
                need(preg_match('/^[A-Z0-9_-]+$/', $code));
                need(in_array($d["type"] ?? "", ["percentage", "fixed"], true));
                $old = $key
                    ? $this->db->lock("SELECT * FROM coupons WHERE id=?", [
                        $key,
                    ])
                    : null;
                $limit = integer_value($d["usage_limit"] ?? 100, 1, 100000000);
                need(
                    !$old || $limit >= (int) $old["used_count"],
                    "سقف مصرف کمتر از مصرف فعلی است.",
                );
                $starts = !empty($d["starts_at"])
                    ? calendar_stamp($d["starts_at"])
                    : null;
                $expires = !empty($d["expires_at"])
                    ? calendar_stamp($d["expires_at"], true)
                    : null;
                need(
                    !$starts || !$expires || $starts < $expires,
                    "پایان اعتبار باید بعد از شروع آن باشد.",
                );
                $v = [
                    $code,
                    txt($d["title"] ?? "", 2, 160),
                    $d["type"],
                    integer_value(
                        $d["value"] ?? null,
                        0,
                        $d["type"] === "percentage" ? 100 : 1000000000000,
                    ),
                    integer_value($d["min_purchase"] ?? 0),
                    isset($d["max_discount"]) && $d["max_discount"] !== ""
                        ? integer_value($d["max_discount"])
                        : null,
                    $limit,
                    !empty($d["active"]) ? 1 : 0,
                    $starts,
                    $expires,
                ];
                if ($key) {
                    $this->db->run(
                        "UPDATE coupons SET code=?,title=?,type=?,value=?,min_purchase=?,max_discount=?,usage_limit=?,active=?,starts_at=?,expires_at=? WHERE id=?",
                        [...$v, $id],
                    );
                } else {
                    $this->db->run(
                        "INSERT INTO coupons(code,title,type,value,min_purchase,max_discount,usage_limit,active,starts_at,expires_at,id,used_count) VALUES(?,?,?,?,?,?,?,?,?,?,?,0)",
                        [...$v, $id],
                    );
                }
            } elseif ($kind === "articles") {
                $v = [
                    txt($d["title"] ?? "", 3, 255),
                    txt($d["slug"] ?? $id, 1, 180),
                    txt($d["summary"] ?? "", 0, 1000),
                    txt($d["body"] ?? "", 10, 50000),
                    txt($d["category"] ?? "راهنما", 1, 80),
                    !empty($d["published"]) ? 1 : 0,
                ];
                if ($key) {
                    $this->db->run(
                        "UPDATE articles SET title=?,slug=?,summary=?,body=?,category=?,published=? WHERE id=?",
                        [...$v, $id],
                    );
                } else {
                    $this->db->run(
                        "INSERT INTO articles(title,slug,summary,body,category,published,id,created_at) VALUES(?,?,?,?,?,?,?,?)",
                        [...$v, $id, dt()],
                    );
                }
            } elseif ($kind === "services") {
                $v = [
                    txt($d["title"] ?? "", 2, 160),
                    integer_value($d["price_toman"] ?? null),
                    !empty($d["active"]) ? 1 : 0,
                    encode_json($d["meta"] ?? new stdClass()),
                ];
                if ($key) {
                    $this->db->run(
                        "UPDATE services SET title=?,price_toman=?,active=?,meta_json=? WHERE id=?",
                        [...$v, $id],
                    );
                } else {
                    $this->db->run(
                        "INSERT INTO services(title,price_toman,active,meta_json,id) VALUES(?,?,?,?,?)",
                        [...$v, $id],
                    );
                }
            } elseif ($kind === "warranties") {
                need(
                    calendar_stamp($d["starts_at"] ?? null) <
                        calendar_stamp($d["expires_at"] ?? null, true),
                    "تاریخ‌ها معتبر نیست.",
                );
                $v = [
                    strtoupper(txt($d["serial"] ?? "", 6, 100)),
                    $d["product_id"],
                    $d["order_id"] ?? "" ?: null,
                    $d["starts_at"],
                    $d["expires_at"],
                    in_array($d["status"] ?? "", ["active", "void"], true)
                        ? $d["status"]
                        : "active",
                ];
                if ($key) {
                    $this->db->run(
                        "UPDATE warranties SET serial=?,product_id=?,order_id=?,starts_at=?,expires_at=?,status=? WHERE id=?",
                        [...$v, $id],
                    );
                } else {
                    $this->db->run(
                        "INSERT INTO warranties(serial,product_id,order_id,starts_at,expires_at,status,id) VALUES(?,?,?,?,?,?,?)",
                        [...$v, $id],
                    );
                }
            } elseif ($kind === "reviews") {
                need(
                    $key &&
                        in_array(
                            $d["status"] ?? "",
                            ["approved", "rejected"],
                            true,
                        ),
                );
                $this->db->run("UPDATE reviews SET status=? WHERE id=?", [
                    $d["status"],
                    $id,
                ]);
            } elseif ($kind === "contacts") {
                need(
                    $key &&
                        in_array(
                            $d["status"] ?? "",
                            ["new", "read", "answered"],
                            true,
                        ),
                );
                $this->db->run("UPDATE contacts SET status=? WHERE id=?", [
                    $d["status"],
                    $id,
                ]);
            } elseif ($kind === "users") {
                $this->db->lock("SELECT id FROM settings WHERE id=?", [
                    "schema_version",
                ]);
                need($key, "ساخت حساب از مسیر ثبت‌نام است.");
                $old = $this->db->lock("SELECT * FROM users WHERE id=?", [$id]);
                need($old, "کاربر پیدا نشد.", 404);
                $role = $d["role"] ?? $old["role"];
                need(in_array($role, ["admin", "manager", "customer"], true));
                need(!array_key_exists("active", $d) || is_bool($d["active"]), "وضعیت حساب معتبر نیست.");
                $active = isset($d["active"])
                    ? (int) (bool) $d["active"]
                    : (int) $old["active"];
                if (
                    $old["role"] === "admin" && (int) $old["active"] === 1 &&
                    (!$active || $role !== "admin")
                ) {
                    need(
                        (int) $this->db->get(
                            "SELECT COUNT(*) n FROM users WHERE role='admin' AND active=1",
                        )["n"] > 1,
                        "آخرین مدیر اصلی قابل غیرفعال‌کردن نیست.",
                    );
                }
                need($actor["id"] !== $id || ($role === "admin" && $active),
                    "برای تغییر دسترسی حساب مدیر فعلی از یک مدیر اصلی دیگر استفاده کنید.", 409);
                $this->db->run(
                    "UPDATE users SET name=?,role=?,active=?,updated_at=? WHERE id=?",
                    [
                        txt($d["name"] ?? $old["name"], 2, 120),
                        $role,
                        $active,
                        dt(),
                        $id,
                    ],
                );
                if (
                    $role !== $old["role"] ||
                    $active !== (int) $old["active"]
                ) {
                    $this->db->run("DELETE FROM sessions WHERE user_id=?", [$id]);
                    $this->shop->audit($actor, "user.access_changed", $id, [
                        "old_role" => $old["role"], "role" => $role, "old_active" => (int) $old["active"], "active" => $active,
                    ]);
                }
            }
            $this->shop->audit($actor, $kind . ".saved", $id);
            return ["id" => $id];
        });
    }
    public function stock(array $d, array $s): array
    {
        $actor = $this->shop->admin($s);
        $amount = integer_value($d["quantity"] ?? null, 0, 1000000);
        $type = $d["type"] ?? "";
        need(in_array($type, ["in", "out", "adjust"], true));
        need($type === "adjust" || $amount > 0);
        $key = txt($d["event_key"] ?? "", 16, 100);
        need(preg_match('/^[A-Za-z0-9_-]{16,100}$/D', $key), "شناسه رسید معتبر نیست.");
        $event = "manual:" . $key;
        $productId = txt($d["product_id"] ?? "", 1, 64);
        $notes = txt($d["notes"] ?? "", 0, 500);
        return $this->db->tx(function () use (
            $d,
            $actor,
            $amount,
            $type,
            $event,
            $productId,
            $notes,
        ) {
            $p = $this->db->lock("SELECT * FROM products WHERE id=?", [$productId]);
            need($p, "کالا پیدا نشد.", 404);
            $prev = $this->db->get(
                "SELECT m.*,d.notes FROM stock_movements m LEFT JOIN stock_receipt_details d ON d.event_key=m.event_key WHERE m.event_key=?",
                [$event],
            );
            if ($prev) {
                need(
                    $prev["product_id"] === $p["id"] &&
                        $prev["reason"] === "manual_" . $type &&
                        ($prev["notes"] === null || $prev["notes"] === $notes) &&
                        ($type === "adjust"
                            ? (int) $prev["balance"] === $amount
                            : (int) $prev["delta"] ===
                                ($type === "in" ? $amount : -$amount)),
                    "شناسه عملیات برای رسید دیگری استفاده شده است.",
                    409,
                );
                return ["stock" => (int) $prev["balance"]];
            }
            if ($type === "adjust") {
                need(integer_value($d["expected_stock"] ?? null, 0, 2147483647) === (int) $p["stock"],
                    "موجودی از زمان بازکردن رسید تغییر کرده است. مقدار فعلی را دوباره بررسی کنید.", 409);
            }
            $balance =
                $type === "adjust"
                    ? $amount
                    : (int) $p["stock"] + ($type === "in" ? $amount : -$amount);
            need($balance >= 0, "خروج بیشتر از موجودی قابل فروش است.", 409);
            need($balance <= 2147483647, "موجودی از سقف قابل ثبت بیشتر است.", 409);
            $this->db->run(
                "UPDATE products SET stock=?,version=version+1,updated_at=? WHERE id=?",
                [$balance, dt(), $p["id"]],
            );
            $this->shop->movement(
                $p["id"],
                $balance - (int) $p["stock"],
                $balance,
                "manual_" . $type,
                $event,
                null,
                $actor,
            );
            $this->db->run("INSERT INTO stock_receipt_details(event_key,notes) VALUES(?,?)", [$event, $notes]);
            $this->shop->audit($actor, "stock.adjusted", $p["id"], [
                "from" => $p["stock"],
                "to" => $balance,
                "event_key" => $event,
            ]);
            return ["stock" => $balance];
        });
    }
    public function settings(array $d, array $s): array
    {
        $actor = $this->shop->admin($s, true);
        return $this->db->tx(function () use ($d, $actor) {
            $row = $this->db->lock("SELECT value FROM settings WHERE id=?", ["store"]);
            $old = decode_json($row["value"]);
            $out = $old;
            foreach (
                ["name", "tagline", "phone", "email", "address", "hours", "about"]
                as $k
            ) {
                if (isset($d[$k])) {
                    $out[$k] = txt($d[$k], 0, $k === "about" ? 5000 : 500);
                }
            }
            foreach (
                ["shipping_fee", "free_shipping_from", "installation_free_from"]
                as $k
            ) {
                if (isset($d[$k])) {
                    $out[$k] = integer_value($d[$k]);
                }
            }
            foreach (["cod_enabled", "transfer_enabled"] as $k) {
                if (isset($d[$k])) {
                    $out[$k] = (bool) $d[$k];
                }
            }
            if (isset($d["cod_reservation_hours"])) {
                $out["cod_reservation_hours"] = integer_value($d["cod_reservation_hours"], 1, 168);
            }
            if (isset($d["transfer_instructions"])) {
                $out["transfer_instructions"] = txt($d["transfer_instructions"], 0, 1000);
            }
            if ($out["transfer_enabled"] && (isset($d["transfer_enabled"]) || isset($d["transfer_instructions"]))) {
                need(!empty($out["transfer_instructions"]), "برای فعال‌سازی واریز بانکی، راهنمای واریز را ثبت کنید.");
            }
            if (isset($d["currency_rate"])) {
                $out["currency_rate"] = integer_value(
                    $d["currency_rate"],
                    1000,
                    100000000,
                );
            }
            foreach (["latitude", "longitude"] as $k) {
                if (array_key_exists($k, $d)) {
                    need(
                        $d[$k] === null ||
                            (is_numeric($d[$k]) &&
                                abs((float) $d[$k]) <=
                                    ($k === "latitude" ? 90 : 180)),
                        "مختصات معتبر نیست.",
                    );
                    $out[$k] = $d[$k] === null ? null : (float) $d[$k];
                }
            }
            $this->shop->settingSave("store", $out);
            if ($out["currency_rate"] !== $old["currency_rate"]) {
                foreach (
                    $this->db->all(
                        "SELECT * FROM products WHERE pricing_mode='usd' ORDER BY id",
                    )
                    as $p
                ) {
                    $this->db->run(
                        "UPDATE products SET price_toman=?,version=version+1,updated_at=? WHERE id=?",
                        [
                            (int) round(
                                ((float) $p["price_usd"] -
                                    (float) $p["discount_usd"]) *
                                    $out["currency_rate"],
                            ),
                            dt(),
                            $p["id"],
                        ],
                    );
                }
            }
            $this->shop->audit($actor, "settings.updated", "store", [
                "keys" => array_keys($d),
            ]);
            return $out;
        });
    }
}
