<?php
declare(strict_types=1);
// All dates are UTC; display conversion belongs to the client.
date_default_timezone_set("UTC");
final class HttpError extends RuntimeException
{
    public int $status;
    public function __construct(string $message, int $status = 400)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}
function need(
    $condition,
    string $message = "اطلاعات واردشده معتبر نیست.",
    int $status = 400,
): void {
    if (!$condition) {
        throw new HttpError($message, $status);
    }
}
function uid(string $prefix = ""): string
{
    return $prefix . bin2hex(random_bytes(16));
}
function dt(): string
{
    return gmdate("Y-m-d H:i:s");
}
function later(int $seconds): string
{
    return gmdate("Y-m-d H:i:s", time() + $seconds);
}
function en($s): string
{
    need(is_scalar($s) || $s === null, "قالب اطلاعات واردشده معتبر نیست.");
    return strtr(
        (string) ($s ?? ""),
        array_combine(
            preg_split("//u", "۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩", -1, PREG_SPLIT_NO_EMPTY),
            str_split("01234567890123456789"),
        ),
    );
}
function mobile($s): string
{
    return preg_replace(
        "/^(\+98|0098)/",
        "0",
        preg_replace("/[\s()\-]/", "", en($s)),
    );
}
function txt($s, int $min = 0, int $max = 500): string
{
    need(is_string($s), "متن واردشده معتبر نیست.");
    $s = trim($s);
    $len = preg_match_all("/./us", $s);
    need($len >= $min && $len <= $max, "طول متن واردشده معتبر نیست.");
    return $s;
}
function calendar_stamp($value, bool $endOfDay = false): string
{
    need(
        is_string($value) &&
            preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $value),
        "تاریخ میلادی معتبر با قالب YYYY-MM-DD یا YYYY-MM-DD HH:mm:ss وارد کنید.",
    );
    $stamp =
        strlen($value) === 10
            ? $value . ($endOfDay ? " 23:59:59" : " 00:00:00")
            : $value;
    $parsed = DateTimeImmutable::createFromFormat(
        "!Y-m-d H:i:s",
        $stamp,
        new DateTimeZone("UTC"),
    );
    need(
        $parsed && $parsed->format("Y-m-d H:i:s") === $stamp,
        "روز، ماه یا ساعت تاریخ واردشده معتبر نیست.",
    );
    return $stamp;
}
function integer_value($v, int $min = 0, int $max = 1000000000000): int
{
    need(is_int($v) || is_float($v) || (is_string($v) && trim($v) !== ""), "مقدار عددی معتبر نیست.");
    $s = en($v);
    need(
        is_numeric($s) &&
            is_finite((float) $s) &&
            (float) $s === floor((float) $s) &&
            (float) $s >= $min &&
            (float) $s <= $max,
        "مقدار عددی خارج از محدوده مجاز است.",
    );
    return (int) $s;
}
function decode_json($s, $fallback = [])
{
    try {
        return json_decode((string) $s, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        return $fallback;
    }
}
function encode_json($v): string
{
    return json_encode(
        $v,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    );
}
function pw_hash($password): string
{
    need(
        is_string($password) &&
            mb_strlen($password, "UTF-8") >= 10 &&
            mb_strlen($password, "UTF-8") <= 200,
        "رمز عبور باید حداقل ۱۰ نویسه باشد.",
    );
    $salt = bin2hex(random_bytes(16));
    return 'pbkdf2_sha256$600000$' .
        $salt .
        '$' .
        hash_pbkdf2("sha256", $password, $salt, 600000, 64);
}
function pw_verify($password, $stored): bool
{
    if (!is_string($password) || strlen($password) > 800) {
        return false;
    }
    $p = explode('$', (string) $stored);
    if (
        count($p) !== 4 ||
        $p[0] !== "pbkdf2_sha256" ||
        (int) $p[1] < 100000 ||
        (int) $p[1] > 1000000
    ) {
        return false;
    }
    return hash_equals(
        $p[3],
        hash_pbkdf2("sha256", $password, $p[2], (int) $p[1], 64),
    );
}
function public_user($u)
{
    return $u
        ? array_intersect_key($u, array_flip(["id", "name", "phone", "role"]))
        : null;
}
function safe_url($v): string
{
    need(is_string($v), "آدرس تصویر معتبر نیست.");
    $s = $v;
    need(
        (preg_match('#^/images/[a-zA-Z0-9/_.-]+$#', $s) &&
            !str_contains($s, "..")) ||
            (str_starts_with($s, "https://") &&
                filter_var($s, FILTER_VALIDATE_URL) &&
                !preg_match("/[\\s<>\"'\\\\]/", $s) &&
                !parse_url($s, PHP_URL_USER) && !parse_url($s, PHP_URL_PASS)),
        "آدرس تصویر معتبر نیست.",
    );
    return $s;
}
function read_config(): array
{
    $root = dirname(__DIR__);
    if (is_file($root . "/.env")) {
        foreach (file($root . "/.env", FILE_IGNORE_NEW_LINES) as $line) {
            if (
                preg_match(
                    '/^\s*([A-Z][A-Z0-9_]*)\s*=\s*(.*)\s*$/',
                    $line,
                    $m,
                ) &&
                getenv($m[1]) === false
            ) {
                putenv($m[1] . "=" . trim($m[2], "\"'"));
            }
        }
    }
    return [
        "root" => $root,
        "driver" => getenv("DB_DRIVER") ?: "mysql",
        "origin" => rtrim(getenv("APP_URL") ?: "", "/"),
        "merchant" => getenv("ZARINPAL_MERCHANT_ID") ?: "",
        "sandbox" => getenv("ZARINPAL_SANDBOX") === "true",
        "setup_token" => getenv("SETUP_TOKEN") ?: "",
        "session_hours" => (int) (getenv("SESSION_HOURS") ?: 24),
        "idle_minutes" => (int) (getenv("SESSION_IDLE_MINUTES") ?: 60),
        "secure" =>
            getenv("COOKIE_SECURE") === "true" ||
            getenv("NODE_ENV") === "production",
    ];
}
final class DB
{
    public PDO $pdo;
    public string $driver;
    private int $depth = 0;
    public function __construct(array $config)
    {
        $this->driver = $config["driver"];
        need(
            in_array($this->driver, ["sqlite", "mysql"], true),
            "DB_DRIVER must be sqlite or mysql",
            500,
        );
        $dsn =
            $this->driver === "sqlite"
                ? "sqlite:" .
                    (getenv("SQLITE_PATH") ?:
                        $config["root"] . "/data/shop.sqlite")
                : "mysql:host=" .
                    (getenv("DB_HOST") ?: "127.0.0.1") .
                    ";port=" .
                    (getenv("DB_PORT") ?: "3306") .
                    ";dbname=" .
                    getenv("DB_NAME") .
                    ";charset=utf8mb4";
        $this->pdo = new PDO(
            $dsn,
            getenv("DB_USER") ?: null,
            getenv("DB_PASSWORD") ?: null,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ] +
                ($this->driver === "mysql" && getenv("DB_SSL_CA")
                    ? [
                        PDO::MYSQL_ATTR_SSL_CA => getenv("DB_SSL_CA"),
                        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
                    ]
                    : []),
        );
        if ($this->driver === "sqlite") {
            $this->pdo->exec(
                "PRAGMA foreign_keys=ON; PRAGMA busy_timeout=10000; PRAGMA journal_mode=WAL;",
            );
        } else {
            $this->pdo->exec("SET SESSION time_zone = '+00:00'");
            $this->pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        }
    }
    public function all(string $sql, array $args = []): array
    {
        $q = $this->pdo->prepare($sql);
        $q->execute($args);
        return $q->fetchAll();
    }
    public function get(string $sql, array $args = [])
    {
        $q = $this->pdo->prepare($sql);
        $q->execute($args);
        return $q->fetch() ?: null;
    }
    public function run(string $sql, array $args = []): int
    {
        $q = $this->pdo->prepare($sql);
        $q->execute($args);
        return $q->rowCount();
    }
    public function lock(string $sql, array $args = [])
    {
        return $this->get(
            $sql . ($this->driver === "mysql" ? " FOR UPDATE" : ""),
            $args,
        );
    }
    public function tx(callable $fn)
    {
        if ($this->depth) {
            return $fn();
        }
        for ($attempt = 1; ; $attempt++) {
            $this->depth = 1;
            $started = false;
            try {
                if ($this->driver === "sqlite") {
                    $this->pdo->exec("BEGIN IMMEDIATE");
                } else {
                    $this->pdo->beginTransaction();
                }
                $started = true;
                $result = $fn();
                if ($this->driver === "sqlite") {
                    $this->pdo->exec("COMMIT");
                } else {
                    $this->pdo->commit();
                }
                return $result;
            } catch (Throwable $e) {
                if ($started && $this->driver === "sqlite") {
                    $this->pdo->exec("ROLLBACK");
                } elseif ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $retryable = $this->driver === "mysql" && $e instanceof PDOException &&
                    in_array((int) ($e->errorInfo[1] ?? 0), [1205, 1213], true);
                if (!$retryable || $attempt === 3) {
                    throw $e;
                }
                usleep(random_int(10000, 40000) * $attempt);
            } finally {
                $this->depth = 0;
            }
        }
    }
    public function migrate(string $root): void
    {
        foreach (
            preg_split(
                '/;\s*(?:\n|$)/',
                file_get_contents(
                    $root . "/database/schema." . $this->driver . ".sql",
                ),
            )
            as $statement
        ) {
            if (trim($statement)) {
                $this->pdo->exec($statement);
            }
        }
    }
}
final class Shop
{
    public DB $db;
    public array $cfg;
    public function __construct(DB $db, array $cfg)
    {
        $this->db = $db;
        $this->cfg = $cfg;
    }
    public function setting(string $id, $fallback = [])
    {
        $r = $this->db->get("SELECT value FROM settings WHERE id=?", [$id]);
        return $r ? decode_json($r["value"], $fallback) : $fallback;
    }
    public function settingSave(string $id, $value): void
    {
        if ($this->db->get("SELECT id FROM settings WHERE id=?", [$id])) {
            $this->db->run("UPDATE settings SET value=? WHERE id=?", [
                encode_json($value),
                $id,
            ]);
        } else {
            $this->db->run("INSERT INTO settings(id,value) VALUES(?,?)", [
                $id,
                encode_json($value),
            ]);
        }
    }
    public function audit(
        $actor,
        string $action,
        string $entity,
        array $details = [],
    ): void {
        $this->db->run(
            "INSERT INTO audit_logs(id,actor_id,action,entity_id,details_json,created_at) VALUES(?,?,?,?,?,?)",
            [
                uid("audit-"),
                $actor["id"] ?? null,
                $action,
                $entity,
                encode_json($details),
                dt(),
            ],
        );
    }
    public function init(): void
    {
        // Bootstrap is explicit and CLI-only. Normal requests require imported SQL.
        $bootstrap = PHP_SAPI === 'cli' && getenv('AMIR_ALLOW_BOOTSTRAP') === 'true';
        try {
            $exists = $this->db->get("SELECT value FROM settings WHERE id=?", ["schema_version"]);
        } catch (PDOException $e) {
            $missing = ($this->db->driver === 'mysql' && (int)($e->errorInfo[1] ?? 0) === 1146)
                || ($this->db->driver === 'sqlite' && str_contains($e->getMessage(), 'no such table'));
            if (!$bootstrap || !$missing) {
                throw new HttpError('دیتابیس آماده نیست؛ فایل‌های نصب را طبق راهنما وارد کنید.', 503);
            }
            $this->db->migrate($this->cfg['root']);
            $exists = null;
        }
        if ($exists) {
            need((int)decode_json($exists['value']) === 3, 'نسخه دیتابیس با برنامه سازگار نیست.', 503);
            return;
        }
        need($bootstrap, 'نصب دیتابیس کامل نشده است؛ فایل کاتالوگ اولیه را وارد کنید.', 503);
        $this->db->tx(function () {
            if (
                $this->db->get("SELECT id FROM settings WHERE id=?", [
                    "schema_version",
                ])
            ) {
                return;
            }
            $seed = decode_json(
                file_get_contents(
                    $this->cfg["root"] . "/database/catalog-seed.json",
                ),
            );
            foreach (["categories", "brands", "vehicles"] as $table) {
                foreach ($seed[$table] as $x) {
                    $this->db->run(
                        "INSERT INTO $table(id,title,slug,meta_json) VALUES(?,?,?,?)",
                        [
                            $x["id"],
                            $x["title"] ?? $x["name"],
                            $x["slug"] ?? $x["id"],
                            encode_json(["image" => $x["image"] ?? ""]),
                        ],
                    );
                }
            }
            foreach ($seed["products"] as $p) {
                $this->insertProduct($p);
                if ($p["stock"]) {
                    $this->movement(
                        $p["id"],
                        $p["stock"],
                        $p["stock"],
                        "initial",
                        "initial:" . $p["id"],
                    );
                }
            }
            foreach ($seed["product_vehicles"] ?? [] as $fit) {
                $this->db->run(
                    "INSERT INTO product_vehicles(id,product_id,vehicle_id,verified,notes) VALUES(?,?,?,?,?)",
                    [
                        $fit["product_id"] . ":" . $fit["vehicle_id"],
                        $fit["product_id"],
                        $fit["vehicle_id"],
                        0,
                        $fit["notes"],
                    ],
                );
            }
            foreach ($seed["bundles"] as $b) {
                $this->db->run(
                    "INSERT INTO bundles(id,slug,title,description,discount_bps,active,meta_json) VALUES(?,?,?,?,?,?,?)",
                    [
                        $b["id"],
                        $b["slug"],
                        $b["title"],
                        $b["description"],
                        $b["discount_bps"],
                        0,
                        encode_json([
                            "legacy_contents" => $b["legacy_contents"],
                        ]),
                    ],
                );
            }
            foreach ($seed["installation_services"] as $s) {
                $this->db->run(
                    "INSERT INTO services(id,title,price_toman,active,meta_json) VALUES(?,?,?,?,?)",
                    [
                        $s["id"],
                        $s["title"],
                        $s["price_toman"],
                        1,
                        encode_json([
                            "description" => $s["description"],
                            "duration" => $s["duration"],
                        ]),
                    ],
                );
            }
            $this->settingSave("store", [
                "name" => "امیر سیستم",
                "tagline" => "صدای بهتر، انتخاب دقیق‌تر",
                "phone" => "",
                "email" => "",
                "address" => "",
                "hours" => "",
                "latitude" => null,
                "longitude" => null,
                "about" =>
                    "فروشگاه تخصصی تجهیزات صوتی خودرو؛ مشخصات و شرایط هر کالا را پیش از خرید بررسی کنید.",
                "shipping_fee" => 150000,
                "free_shipping_from" => 3000000,
                "installation_free_from" => 10000000,
                "cod_enabled" => true,
                "transfer_enabled" => false,
                "currency_rate" => 188000,
            ]);
            $this->settingSave("database_pack_version", "1.1.0");
            $this->settingSave("schema_version", 3);
            $articles = [
                [
                    "راهنمای انتخاب سیستم صوتی خودرو",
                    "راهنمای خرید",
                    "انتخاب را از ابعاد محل نصب، بودجه و انتظار خود شروع کنید.",
                    "توان پیوسته، امپدانس، حساسیت و تعداد کانال‌ها باید در کنار یکدیگر بررسی شوند. برای نصب و تنظیم از فرد متخصص کمک بگیرید.",
                ],
                [
                    "پیش از نصب به چه نکاتی توجه کنیم؟",
                    "نصب و نگهداری",
                    "سیم‌کشی، فیوز و تهویه مهم‌اند.",
                    "کابل و فیوز باید با مصرف تجهیزات هماهنگ باشند. مسیر کابل را از اجزای متحرک و نقاط داغ دور نگه دارید.",
                ],
                [
                    "تفاوت انتخاب کیفیتی و قدرتی",
                    "شناخت تجهیزات",
                    "انتخاب به فضای خودرو و سلیقه شما بستگی دارد.",
                    "پیش از خرید، محل نصب، کراس‌اور و مشخصات واقعی تجهیزات را بررسی کنید.",
                ],
            ];
            foreach ($articles as $i => $a) {
                $this->db->run(
                    "INSERT INTO articles(id,slug,title,category,summary,body,published,created_at) VALUES(?,?,?,?,?,?,?,?)",
                    [
                        "article-" . ($i + 1),
                        "guide-" . ($i + 1),
                        $a[0],
                        $a[1],
                        $a[2],
                        $a[3],
                        1,
                        dt(),
                    ],
                );
            }
        });
    }
    public function rate(string $key, int $limit = 8, int $minutes = 15): void
    {
        $this->db->tx(function () use ($key, $limit, $minutes) {
            $k = hash("sha256", $key);
            $r = $this->db->lock("SELECT * FROM rate_limits WHERE id=?", [$k]);
            if (!$r || $r["window_end"] < dt()) {
                if ($r) {
                    $this->db->run("DELETE FROM rate_limits WHERE id=?", [$k]);
                }
                $this->db->run(
                    "INSERT INTO rate_limits(id,hits,window_end) VALUES(?,?,?)",
                    [$k, 1, later($minutes * 60)],
                );
            } else {
                need(
                    (int) $r["hits"] < $limit,
                    "تعداد تلاش‌ها بیش از حد مجاز است. کمی بعد دوباره تلاش کنید.",
                    429,
                );
                $this->db->run(
                    "UPDATE rate_limits SET hits=hits+1 WHERE id=?",
                    [$k],
                );
            }
        });
    }
    public function session($token)
    {
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $s = $this->db->get("SELECT * FROM sessions WHERE id=?", [
            hash("sha256", $token),
        ]);
        if (!$s) {
            return null;
        }
        if (
            $s["expires_at"] < dt() ||
            $s["last_seen"] < later(-$this->cfg["idle_minutes"] * 60)
        ) {
            $this->db->run("DELETE FROM sessions WHERE id=?", [$s["id"]]);
            return null;
        }
        $u = $s["user_id"]
            ? $this->db->get("SELECT * FROM users WHERE id=? AND active=1", [
                $s["user_id"],
            ])
            : null;
        if ($s["user_id"] && !$u) {
            return null;
        }
        $this->db->run("UPDATE sessions SET last_seen=? WHERE id=?", [
            dt(),
            $s["id"],
        ]);
        $s["user"] = public_user($u);
        return $s;
    }
    public function newSession($userId = null): array
    {
        $token = uid() . uid();
        $s = [
            "id" => hash("sha256", $token),
            "user_id" => $userId,
            "csrf_token" => uid() . uid(),
            "expires_at" => later($this->cfg["session_hours"] * 3600),
            "last_seen" => dt(),
            "created_at" => dt(),
        ];
        $this->db->run(
            "INSERT INTO sessions(id,user_id,csrf_token,expires_at,last_seen,created_at) VALUES(?,?,?,?,?,?)",
            array_values($s),
        );
        $s["token"] = $token;
        $s["user"] = $userId
            ? public_user(
                $this->db->get("SELECT * FROM users WHERE id=?", [$userId]),
            )
            : null;
        return $s;
    }
    public function user($s): array
    {
        need(!empty($s["user"]), "برای ادامه وارد حساب شوید.", 401);
        return $s["user"];
    }
    public function admin($s, bool $owner = false): array
    {
        $u = $this->user($s);
        need(
            $owner
                ? $u["role"] === "admin"
                : in_array($u["role"], ["admin", "manager"], true),
            "اجازه دسترسی ندارید.",
            403,
        );
        return $u;
    }
    public function register(array $d, string $role = "customer"): array
    {
        $name = txt($d["name"] ?? "", 2, 120);
        $phone = mobile($d["phone"] ?? "");
        need(
            preg_match('/^09\d{9}$/', $phone),
            "شماره همراه را به‌صورت ۱۱ رقمی وارد کنید.",
        );
        $password = pw_hash($d["password"] ?? null);
        return $this->db->tx(function () use ($name, $phone, $password, $role) {
            need(
                !$this->db->get("SELECT id FROM users WHERE phone=?", [$phone]),
                "این شماره همراه قبلاً ثبت شده است.",
                409,
            );
            $id = uid("usr-");
            $this->db->run(
                "INSERT INTO users(id,name,phone,password_hash,role,active,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)",
                [$id, $name, $phone, $password, $role, 1, dt(), dt()],
            );
            return public_user(
                $this->db->get("SELECT * FROM users WHERE id=?", [$id]),
            );
        });
    }
    public function login(array $d, string $ip): array
    {
        $m = mobile($d["phone"] ?? "");
        $this->rate("login-ip:" . $ip, 25, 15);
        $this->rate("login:" . $ip . ":" . $m);
        $u = $this->db->get("SELECT * FROM users WHERE phone=? AND active=1", [
            $m,
        ]);
        $valid = pw_verify(
            $d["password"] ?? null,
            $u["password_hash"] ??
                'pbkdf2_sha256$600000$unmatched$' . str_repeat("0", 64),
        );
        need($u && $valid, "شماره همراه یا رمز عبور نادرست است.", 401);
        return public_user($u);
    }
    public function insertProduct(array $p): void
    {
        $this->db->run(
            "INSERT INTO products(id,slug,title,description,category_id,brand_id,price_toman,price_usd,discount_usd,pricing_mode,stock,featured,active,warranty_months,specs_json,images_json,tags_json,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
            [
                $p["id"],
                $p["slug"],
                $p["title"],
                $p["description"] ?? "",
                $p["category_id"],
                $p["brand_id"],
                $p["price_toman"],
                $p["price_usd"] ?? 0,
                $p["discount_usd"] ?? 0,
                $p["pricing_mode"] ?? "fixed",
                $p["stock"] ?? 0,
                empty($p["featured"]) ? 0 : 1,
                isset($p["active"]) && !$p["active"] ? 0 : 1,
                $p["warranty_months"] ?? null,
                encode_json($p["specs"] ?? []),
                encode_json($p["images"] ?? []),
                encode_json($p["tags"] ?? []),
                dt(),
                dt(),
            ],
        );
    }
    public function publicProduct(array $p): array
    {
        $p["price"] = (int) $p["price_toman"];
        $p["stock"] = (int) $p["stock"];
        $p["inventory"] = $p["stock"];
        $p["discount"] = 0;
        $p["featured"] = (bool) $p["featured"];
        $p["active"] = (bool) $p["active"];
        $p["specs"] = decode_json($p["specs_json"]);
        $p["images"] = decode_json($p["images_json"]);
        $p["image"] = $p["images"][0] ?? "/images/placeholder.svg";
        $p["tags"] = decode_json($p["tags_json"]);
        $p["rating"] = (float) ($p["rating"] ?? 0);
        $p["reviews_count"] = (int) ($p["reviews_count"] ?? 0);
        $p["version"] = (int) $p["version"];
        unset($p["specs_json"], $p["images_json"], $p["tags_json"]);
        return $p;
    }
    public function product(string $key, bool $admin = false): array
    {
        $p = $this->db->get(
            "SELECT p.*,c.title category_name,b.title brand_name,(SELECT AVG(rating) FROM reviews WHERE product_id=p.id AND status='approved') rating,(SELECT COUNT(*) FROM reviews WHERE product_id=p.id AND status='approved') reviews_count FROM products p JOIN categories c ON c.id=p.category_id JOIN brands b ON b.id=p.brand_id WHERE (p.id=? OR p.slug=?) " .
                ($admin ? "" : "AND p.active=1"),
            [$key, $key],
        );
        need($p, "محصول پیدا نشد.", 404);
        $out = $this->publicProduct($p);
        if ($admin) {
            $out["vehicle_fits"] = $this->db->all(
                "SELECT vehicle_id,verified,notes FROM product_vehicles WHERE product_id=?",
                [$p["id"]],
            );
        }
        return $out;
    }
    public function products(array $q = [], bool $admin = false): array
    {
        $rows = $this->db->all(
            "SELECT p.*,c.title category_name,b.title brand_name,(SELECT AVG(rating) FROM reviews WHERE product_id=p.id AND status='approved') rating,(SELECT COUNT(*) FROM reviews WHERE product_id=p.id AND status='approved') reviews_count FROM products p JOIN categories c ON c.id=p.category_id JOIN brands b ON b.id=p.brand_id " .
                ($admin ? "" : "WHERE p.active=1"),
        );
        $rows = array_map([$this, "publicProduct"], $rows);
        $norm = fn($s) => strtolower(
            str_replace(["ي", "ك"], ["ی", "ک"], en($s)),
        );
        $fit = [];
        $selected = [];
        foreach (
            [["cat", "categories", "cat-"], ["brand", "brands", "brand-"]]
            as [$param, $table, $prefix]
        ) {
            if (!empty($q[$param])) {
                $values = explode(",", (string) $q[$param]);
                $selected[$param] = [];
                foreach ($this->db->all("SELECT id,slug FROM $table") as $row) {
                    if (
                        in_array($row["id"], $values, true) ||
                        in_array($row["slug"], $values, true) ||
                        in_array(
                            $row["id"],
                            array_map(fn($v) => $prefix . $v, $values),
                            true,
                        )
                    ) {
                        $selected[$param][] = $row["id"];
                    }
                }
            }
        }
        if (!empty($q["vehicle"])) {
            $fit = array_column(
                $this->db->all(
                    "SELECT product_id FROM product_vehicles WHERE vehicle_id=?",
                    [$q["vehicle"]],
                ),
                "product_id",
            );
        }
        $stockFilter = $admin ? ($q["stock"] ?? "") : "";
        $search = txt($q["q"] ?? "", 0, 180);
        $rows = array_values(
            array_filter($rows, function ($p) use ($q, $norm, $fit, $selected, $stockFilter, $search) {
                $matchStock = match ($stockFilter) {
                    "in" => $p["stock"] > 0,
                    "out" => $p["stock"] === 0,
                    "low" => $p["active"] && $p["stock"] < 3,
                    default => true,
                };
                if (!$matchStock) {
                    return false;
                }
                if (
                    $search !== "" &&
                    !str_contains(
                        $norm(
                            $p["title"] .
                                " " .
                                $p["description"] .
                                " " .
                                $p["brand_name"],
                        ),
                        $norm($search),
                    )
                ) {
                    return false;
                }
                foreach (
                    [
                        ["cat", "category_id", "cat-"],
                        ["brand", "brand_id", "brand-"],
                    ]
                    as [$key, $field, $prefix]
                ) {
                    if (
                        isset($selected[$key]) &&
                        !in_array($p[$field], $selected[$key], true)
                    ) {
                        return false;
                    }
                }
                if (($q["instock"] ?? "") === "1" && $p["stock"] < 1) {
                    return false;
                }
                if (
                    !empty($q["min"]) &&
                    $p["price"] < integer_value($q["min"])
                ) {
                    return false;
                }
                if (
                    !empty($q["max"]) &&
                    $p["price"] > integer_value($q["max"])
                ) {
                    return false;
                }
                if (!empty($q["vehicle"]) && !in_array($p["id"], $fit, true)) {
                    return false;
                }
                foreach (
                    [["size", "سایز"], ["ohm", "امپدانس"]]
                    as [$key, $spec]
                ) {
                    if (!empty($q[$key])) {
                        $ok = false;
                        foreach (explode(",", $q[$key]) as $v) {
                            if (
                                str_contains(
                                    $norm($p["specs"][$spec] ?? ""),
                                    $norm($v),
                                )
                            ) {
                                $ok = true;
                            }
                        }
                        if (!$ok) {
                            return false;
                        }
                    }
                }
                if (!empty($q["rms"])) {
                    $range = explode("-", $q["rms"]);
                    preg_match(
                        "/[\d.]+/",
                        $norm($p["specs"]["توان اسمی (RMS)"] ?? ""),
                        $m,
                    );
                    if (
                        !$m ||
                        (float) $m[0] < (float) $range[0] ||
                        (float) $m[0] > (float) ($range[1] ?? 100000)
                    ) {
                        return false;
                    }
                }
                return true;
            }),
        );
        $sort = $q["sort"] ?? "newest";
        usort($rows, function ($a, $b) use ($sort) {
            if ($sort === "price_asc") {
                return $a["price"] <=> $b["price"];
            }
            if ($sort === "price_desc") {
                return $b["price"] <=> $a["price"];
            }
            if ($sort === "rating") {
                return $b["rating"] <=> $a["rating"];
            }
            return strcmp($b["created_at"], $a["created_at"]) ?:
                strcmp($a["id"], $b["id"]);
        });
        $total = count($rows);
        $limit = min(60, max(1, (int) ($q["limit"] ?? 12)));
        $page = max(
            1,
            min((int) ceil($total / $limit) ?: 1, (int) ($q["page"] ?? 1)),
        );
        return [
            "items" => array_slice($rows, ($page - 1) * $limit, $limit),
            "total" => $total,
            "page" => $page,
            "limit" => $limit,
            "pages" => (int) ceil($total / $limit),
        ];
    }
    public function quote(array $d, bool $locked = false): array
    {
        $items = $d["items"] ?? null;
        need(
            is_array($items) && count($items) > 0 && count($items) <= 60,
            "سبد خرید خالی یا نامعتبر است.",
        );
        foreach ($items as $item) {
            need(is_array($item) && !array_is_list($item) && is_string($item["id"] ?? null) &&
                in_array($item["kind"] ?? "product", ["product", "bundle"], true), "اقلام سبد معتبر نیستند.");
        }
        usort(
            $items,
            fn($a, $b) => strcmp(
                (string) ($a["id"] ?? ""),
                (string) ($b["id"] ?? ""),
            ),
        );
        $required = [];
        $display = [];
        $lines = [];
        $subtotal = 0;
        $products = [];
        $bundles = [];
        foreach ($items as $item) {
            $qty = integer_value(
                $item["qty"] ?? ($item["quantity"] ?? null),
                1,
                99,
            );
            $key = txt($item["id"] ?? "", 1, 64);
            if (($item["kind"] ?? "") === "bundle") {
                $b = $bundles[$key] ??= $locked
                    ? $this->db->lock(
                        "SELECT * FROM bundles WHERE id=? AND active=1",
                        [$key],
                    )
                    : $this->db->get(
                        "SELECT * FROM bundles WHERE id=? AND active=1",
                        [$key],
                    );
                need($b, "این پکیج برای خرید فعال نیست.", 409);
                $parts = $this->db->all(
                    "SELECT * FROM bundle_items WHERE bundle_id=? ORDER BY product_id" .
                        ($locked && $this->db->driver === "mysql" ? " FOR UPDATE" : ""),
                    [$key],
                );
                need(count($parts) > 0, "قطعات پکیج هنوز مشخص نشده‌اند.", 409);
                $raw = 0;
                $partLines = [];
                foreach ($parts as $c) {
                    $p = $products[$c["product_id"]] ??= $this->product($c["product_id"]);
                    need($p["price"] > 0, "قیمت یکی از قطعات پکیج مشخص نشده است.", 409);
                    $n = (int) $c["qty"] * $qty;
                    $raw += $p["price"] * $n;
                    $partLines[] = [
                        "id" => $p["id"],
                        "title" => $p["title"],
                        "qty" => $n,
                        "unit_price" => $p["price"],
                        "line_total" => $p["price"] * $n,
                    ];
                    $required[$p["id"]] = ($required[$p["id"]] ?? 0) + $n;
                }
                need($raw > 0, "قیمت قطعات پکیج مشخص نشده است.", 409);
                need($raw <= 1000000000000, "مبلغ پکیج از سقف سفارش بیشتر است.");
                $discountFactor = 10000 - (int) $b["discount_bps"];
                $total = intdiv($raw * $discountFactor, 10000);
                $cumulative = 0;
                $allocated = 0;
                foreach ($partLines as $line) {
                    $cumulative += $line["line_total"];
                    $nextAllocation = intdiv($cumulative * $discountFactor, 10000);
                    $line["line_total"] = $nextAllocation - $allocated;
                    $allocated = $nextAllocation;
                    $lines[] = $line;
                }
                $display[] = [
                    "id" => $key,
                    "kind" => "bundle",
                    "title" => $b["title"],
                    "qty" => $qty,
                    "unit_price" => (int) floor($total / $qty),
                    "line_total" => $total,
                ];
                $subtotal += $total;
            } else {
                $p = $products[$key] ??= $this->product($key);
                need($p["price"] > 0, "قیمت کالا مشخص نشده است.", 409);
                $required[$p["id"]] = ($required[$p["id"]] ?? 0) + $qty;
                $line = [
                    "id" => $p["id"],
                    "title" => $p["title"],
                    "qty" => $qty,
                    "unit_price" => $p["price"],
                    "line_total" => $p["price"] * $qty,
                ];
                $lines[] = $line;
                $display[] = array_merge($line, [
                    "kind" => "product",
                    "image" => $p["image"],
                ]);
                $subtotal += $line["line_total"];
            }
        }
        $prices = array_column($products, "price", "id");
        need($subtotal <= 1000000000000, "مبلغ کالاها از سقف سفارش بیشتر است.");
        ksort($required);
        foreach ($required as $pid => $qty) {
            $p = $locked
                ? $this->db->lock("SELECT * FROM products WHERE id=?", [$pid])
                : $this->db->get("SELECT * FROM products WHERE id=?", [$pid]);
            need(
                $p && (int) $p["active"] === 1 && (int) $p["stock"] >= $qty,
                "موجودی کالا کافی نیست.",
                409,
            );
            need(
                (int) $p["price_toman"] === $prices[$pid],
                "قیمت کالا تغییر کرده است؛ سبد را بررسی کنید.",
                409,
            );
        }
        $coupon = null;
        $discount = 0;
        if (!empty($d["coupon"])) {
            $code = strtoupper(txt($d["coupon"], 1, 64));
            $coupon = $locked
                ? $this->db->lock("SELECT * FROM coupons WHERE code=?", [$code])
                : $this->db->get("SELECT * FROM coupons WHERE code=?", [$code]);
            need(
                $coupon && (int) $coupon["active"] === 1,
                "کد تخفیف معتبر یا فعال نیست.",
            );
            need(
                (!$coupon["starts_at"] ||
                    calendar_stamp($coupon["starts_at"]) <= dt()) &&
                    (!$coupon["expires_at"] ||
                        calendar_stamp($coupon["expires_at"], true) >= dt()),
                "این کد در این زمان معتبر نیست.",
            );
            need(
                (int) $coupon["used_count"] < (int) $coupon["usage_limit"],
                "ظرفیت استفاده از کد تخفیف تکمیل شده است.",
            );
            need(
                $subtotal >= (int) $coupon["min_purchase"],
                "حداقل مبلغ این کد تخفیف رعایت نشده است.",
            );
            $discount =
                $coupon["type"] === "percentage"
                    ? intdiv($subtotal * (int) $coupon["value"], 100)
                    : (int) $coupon["value"];
            if ($coupon["max_discount"] !== null) {
                $discount = min($discount, (int) $coupon["max_discount"]);
            }
            $discount = min($subtotal, $discount);
        }
        $settings = $this->setting("store");
        $shipping =
            $subtotal >= (int) $settings["free_shipping_from"]
                ? 0
                : (int) $settings["shipping_fee"];
        $service = null;
        $serviceAmount = 0;
        if (!empty($d["service_id"])) {
            $serviceId = txt($d["service_id"], 1, 64);
            $sql = "SELECT * FROM services WHERE id=? AND active=1";
            $service = $locked ? $this->db->lock($sql, [$serviceId]) : $this->db->get($sql, [$serviceId]);
            need($service, "خدمت انتخاب‌شده معتبر نیست.");
            $serviceAmount =
                $subtotal >= (int) $settings["installation_free_from"]
                    ? 0
                    : (int) $service["price_toman"];
        }
        $total = $subtotal - $discount + $shipping + $serviceAmount;
        need($total > 0 && $total <= 1000000000000, "مبلغ سفارش معتبر نیست.");
        return [
            "items" => $display,
            "expanded_items" => $lines,
            "required_stock" => $required,
            "subtotal" => $subtotal,
            "coupon_amount" => $discount,
            "shipping_amount" => $shipping,
            "service_amount" => $serviceAmount,
            "total_amount" => $total,
            "coupon_id" => $coupon["id"] ?? null,
            "coupon_code" => $coupon["code"] ?? null,
            "service" => $service
                ? [
                    "id" => $service["id"],
                    "title" => $service["title"],
                    "amount" => $serviceAmount,
                ]
                : null,
            "currency" => "تومان",
        ];
    }
    public function address(array $d): array
    {
        $m = mobile($d["mobile"] ?? ($d["phone"] ?? ""));
        need(preg_match('/^09\d{9}$/', $m), "شماره همراه معتبر نیست.");
        $postal = en($d["postal_code"] ?? "");
        need(preg_match('/^\d{10}$/', $postal), "کد پستی باید ۱۰ رقم باشد.");
        return [
            "customer_name" => txt(
                $d["customer_name"] ?? ($d["name"] ?? ""),
                2,
                120,
            ),
            "mobile" => $m,
            "province" => txt($d["province"] ?? "", 2, 80),
            "city" => txt($d["city"] ?? "", 2, 80),
            "address" => txt($d["address"] ?? "", 10, 1000),
            "postal_code" => $postal,
        ];
    }
    private function requireOrderAccess(?array $o, array $s): void
    {
        need($o, "سفارش پیدا نشد.", 404);
        need(
            ($o["user_id"] !== null && $o["user_id"] === ($s["user"]["id"] ?? null)) ||
                ($o["user_id"] === null && $o["guest_session_id"] === $s["id"]),
            "اجازه دسترسی به این سفارش را ندارید.",
            403,
        );
    }
    public function order(string $oid, array $s, bool $admin = false): array
    {
        $o = $this->db->get("SELECT * FROM orders WHERE id=?", [$oid]);
        if ($admin) {
            $this->admin($s);
            need($o, "سفارش پیدا نشد.", 404);
        } else {
            $this->requireOrderAccess($o, $s);
        }
        $o["can_cancel"] = $o["payment_status"] === "unpaid" &&
            in_array($o["status"], ["pending_payment", "confirmed"], true);
        $o["items"] = $this->db->all(
            "SELECT * FROM order_items WHERE order_id=? ORDER BY LENGTH(id),id",
            [$oid],
        );
        $o["quote"] = decode_json($o["quote_json"]);
        $o["shipment"] = $this->db->get("SELECT * FROM order_shipments WHERE order_id=?", [$oid]);
        $o["transfer_receipt"] = $this->db->get("SELECT reference,submitted_at FROM order_transfer_receipts WHERE order_id=?", [$oid]);
        $o["can_cancel"] = $o["can_cancel"] && !$o["transfer_receipt"];
        $o["can_recheck_online"] = $o["payment_method"] === "online" && $o["payment_status"] === "unpaid" &&
            (bool) $this->db->get("SELECT id FROM payments WHERE order_id=? AND status='pending' AND authority IS NOT NULL LIMIT 1", [$oid]);
        unset($o["quote_json"], $o["guest_session_id"]);
        if ($admin) {
            $o["next_statuses"] = $this->nextOrderStatuses($o);
            $o["events"] = $this->db->all(
                "SELECT a.action,a.details_json,a.created_at,u.name actor FROM audit_logs a LEFT JOIN users u ON u.id=a.actor_id WHERE a.entity_id=? ORDER BY a.created_at,a.id",
                [$oid],
            );
        }
        foreach (
            [
                "subtotal",
                "coupon_amount",
                "shipping_amount",
                "service_amount",
                "total_amount",
            ]
            as $key
        ) {
            $o[$key] = (int) $o[$key];
        }
        return $o;
    }
    public function movement(
        string $pid,
        int $delta,
        int $balance,
        string $reason,
        string $event,
        ?string $oid = null,
        $actor = null,
    ): void {
        $this->db->run(
            "INSERT INTO stock_movements(id,product_id,order_id,delta,balance,reason,event_key,actor_id,created_at) VALUES(?,?,?,?,?,?,?,?,?)",
            [
                uid("stock-"),
                $pid,
                $oid,
                $delta,
                $balance,
                $reason,
                $event,
                $actor["id"] ?? null,
                dt(),
            ],
        );
    }
    public function createOrder(array $d, array $s, string $key): array
    {
        need(
            preg_match('/^[a-zA-Z0-9_-]{16,100}$/', $key),
            "شناسه عملیات معتبر نیست.",
        );
        $scope = ($s["user"]["id"] ?? $s["id"]) . ":" . $key;
        $requestHash = hash("sha256", encode_json($d));
        $previous = function () use ($scope, $requestHash, $s) {
            $p = $this->db->get("SELECT * FROM idempotency_keys WHERE id=?", [
                $scope,
            ]);
            if ($p) {
                need(
                    hash_equals($p["request_hash"], $requestHash),
                    "این شناسه قبلاً برای سفارش دیگری استفاده شده است.",
                    409,
                );
                return $this->order($p["order_id"], $s);
            }
            return null;
        };
        if ($old = $previous()) {
            return $old;
        }
        try {
            return $this->db->tx(function () use (
                $d,
                $s,
                $scope,
                $requestHash,
                $previous,
            ) {
                if (empty($s["user"])) {
                    need($this->db->lock("SELECT id FROM sessions WHERE id=?", [$s["id"]]),
                        "نشست شما تغییر کرده است؛ صفحه را تازه کنید.", 419);
                }
                if ($old = $previous()) {
                    return $old;
                }
                $a = $this->address($d);
                $method = $d["payment_method"] ?? "";
                need(
                    in_array($method, ["online", "cod", "transfer"], true),
                    "روش پرداخت معتبر نیست.",
                );
                $settings = $this->setting("store");
                need(
                    $method !== "online" || $this->cfg["merchant"],
                    "پرداخت آنلاین هنوز فعال نشده است.",
                    409,
                );
                need(
                    $method !== "cod" || $settings["cod_enabled"],
                    "پرداخت در محل فعال نیست.",
                );
                need(
                    $method !== "transfer" || $settings["transfer_enabled"],
                    "واریز بانکی فعال نیست.",
                );
                $q = $this->quote($d, true);
                if ($method === "transfer") {
                    $q["transfer_instructions"] = $settings["transfer_instructions"] ?? "";
                }
                if (isset($d["expected_total"])) {
                    need(
                        integer_value($d["expected_total"]) ===
                            $q["total_amount"],
                        "مبلغ سفارش تغییر کرده است؛ سبد را دوباره بررسی کنید.",
                        409,
                    );
                }
                $oid = uid("ord-");
                $tracking = strtoupper(uid("AS-"));
                $this->db->run(
                    "INSERT INTO orders(id,tracking_code,user_id,guest_session_id,customer_name,mobile,province,city,address,postal_code,status,payment_method,payment_status,subtotal,coupon_amount,shipping_amount,service_amount,total_amount,coupon_id,stock_released,quote_json,created_at,updated_at,expires_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                    [
                        $oid,
                        $tracking,
                        $s["user"]["id"] ?? null,
                        $s["id"],
                        $a["customer_name"],
                        $a["mobile"],
                        $a["province"],
                        $a["city"],
                        $a["address"],
                        $a["postal_code"],
                        $method === "cod" ? "confirmed" : "pending_payment",
                        $method,
                        "unpaid",
                        $q["subtotal"],
                        $q["coupon_amount"],
                        $q["shipping_amount"],
                        $q["service_amount"],
                        $q["total_amount"],
                        $q["coupon_id"],
                        0,
                        encode_json($q),
                        dt(),
                        dt(),
                        match ($method) { "online" => later(1800), "transfer" => later(86400), default => null },
                    ],
                );
                foreach ($q["expanded_items"] as $i => $line) {
                    $this->db->run(
                        "INSERT INTO order_items(id,order_id,product_id,title,qty,unit_price,line_total) VALUES(?,?,?,?,?,?,?)",
                        [
                            $oid . "-" . $i,
                            $oid,
                            $line["id"],
                            $line["title"],
                            $line["qty"],
                            $line["unit_price"],
                            $line["line_total"],
                        ],
                    );
                }
                foreach ($q["required_stock"] as $pid => $qty) {
                    $p = $this->db->lock(
                        "SELECT stock FROM products WHERE id=?",
                        [$pid],
                    );
                    $balance = (int) $p["stock"] - $qty;
                    need($balance >= 0, "موجودی تغییر کرده است.", 409);
                    $this->db->run(
                        "UPDATE products SET stock=?,version=version+1,updated_at=? WHERE id=?",
                        [$balance, dt(), $pid],
                    );
                    $this->movement(
                        $pid,
                        -$qty,
                        $balance,
                        "order_reserved",
                        $oid . ":reserve:" . $pid,
                        $oid,
                        $s["user"] ?? null,
                    );
                }
                if ($q["coupon_id"]) {
                    $this->db->run(
                        "UPDATE coupons SET used_count=used_count+1 WHERE id=?",
                        [$q["coupon_id"]],
                    );
                }
                $this->db->run(
                    "INSERT INTO idempotency_keys(id,request_hash,order_id,created_at) VALUES(?,?,?,?)",
                    [$scope, $requestHash, $oid, dt()],
                );
                $this->audit($s["user"] ?? null, "order.created", $oid, [
                    "method" => $method,
                    "total" => $q["total_amount"],
                ]);
                return $this->order($oid, $s);
            });
        } catch (Throwable $e) {
            if ($old = $previous()) {
                return $old;
            }
            throw $e;
        }
    }
    public function release(array $o, string $reason, $actor): void
    {
        if ($o["stock_released"]) {
            return;
        }
        $items = $this->db->all(
            "SELECT product_id,SUM(qty) qty FROM order_items WHERE order_id=? GROUP BY product_id ORDER BY product_id",
            [$o["id"]],
        );
        foreach ($items as $i) {
            $p = $this->db->lock("SELECT stock FROM products WHERE id=?", [
                $i["product_id"],
            ]);
            $balance = (int) $p["stock"] + (int) $i["qty"];
            $this->db->run(
                "UPDATE products SET stock=?,version=version+1,updated_at=? WHERE id=?",
                [$balance, dt(), $i["product_id"]],
            );
            $this->movement(
                $i["product_id"],
                (int) $i["qty"],
                $balance,
                $reason,
                $o["id"] . ":release:" . $i["product_id"],
                $o["id"],
                $actor,
            );
        }
        if ($o["coupon_id"]) {
            $this->db->run(
                "UPDATE coupons SET used_count=CASE WHEN used_count>0 THEN used_count-1 ELSE 0 END WHERE id=?",
                [$o["coupon_id"]],
            );
        }
        $this->db->run("UPDATE orders SET stock_released=1 WHERE id=?", [
            $o["id"],
        ]);
    }
    public function nextOrderStatuses(array $o): array
    {
        $unpaid = $o["payment_status"] === "unpaid";
        $canFulfill = !$o["stock_released"] &&
            ($o["payment_status"] === "paid" || ($unpaid && $o["payment_method"] === "cod"));
        $next = match ($o["status"]) {
            "confirmed" => $canFulfill ? ["processing"] : [],
            "processing" => $canFulfill ? ["shipped"] : [],
            "shipped" => $o["payment_status"] === "paid" ? ["delivered"] : [],
            default => [],
        };
        if ($unpaid && in_array($o["status"], ["pending_payment", "confirmed", "processing"], true)) {
            $next[] = "cancelled";
        }
        return $next;
    }
    private function cancelLockedOrder(array $o, string $status, $actor): void
    {
        $this->release($o, $status === "expired" ? "order_expired" : "order_cancelled", $actor);
        $this->db->run("UPDATE orders SET status=?,updated_at=? WHERE id=?", [$status, dt(), $o["id"]]);
        $this->audit($actor, "order.status", $o["id"], ["from" => $o["status"], "to" => $status]);
    }
    public function transition(string $oid, string $next, array $s, array $d = []): array
    {
        $actor = $this->admin($s);
        return $this->db->tx(function () use ($oid, $next, $s, $actor, $d) {
            $o = $this->db->lock("SELECT * FROM orders WHERE id=?", [$oid]);
            need($o, "سفارش پیدا نشد.", 404);
            if ($o["status"] === $next) {
                if ($next === "shipped") {
                    $ship = $this->db->get("SELECT carrier,tracking_code FROM order_shipments WHERE order_id=?", [$oid]);
                    [$carrier, $tracking] = $this->shipmentData($d);
                    need($ship && $ship["carrier"] === $carrier && $ship["tracking_code"] === $tracking,
                        "اطلاعات ارسال قبلاً ثبت شده است.", 409);
                }
                return $this->order($oid, $s, true);
            }
            need(in_array($next, $this->nextOrderStatuses($o), true),
                "این تغییر وضعیت با مرحله سفارش یا وضعیت پرداخت سازگار نیست.", 409);
            if ($next === "cancelled") {
                $this->cancelLockedOrder($o, $next, $actor);
                return $this->order($oid, $s, true);
            }
            $now = dt();
            if ($next === "shipped") {
                [$carrier, $tracking] = $this->shipmentData($d);
                $this->db->run(
                    "INSERT INTO order_shipments(order_id,carrier,tracking_code,shipped_at) VALUES(?,?,?,?)",
                    [$oid, $carrier, $tracking, $now],
                );
            }
            if ($next === "delivered") {
                $this->db->run("UPDATE order_shipments SET delivered_at=? WHERE order_id=?", [$now, $oid]);
            }
            $this->db->run("UPDATE orders SET status=?,updated_at=? WHERE id=?", [$next, $now, $oid]);
            $this->audit($actor, "order.status", $oid, ["from" => $o["status"], "to" => $next]);
            return $this->order($oid, $s, true);
        });
    }
    private function shipmentData(array $d): array
    {
        $carrier = txt($d["carrier"] ?? "", 2, 120);
        $tracking = en(txt($d["tracking_code"] ?? "", 3, 100));
        need(preg_match('/^[A-Za-z0-9][A-Za-z0-9 _-]{2,99}$/D', $tracking), "کد رهگیری ارسال معتبر نیست.");
        return [$carrier, $tracking];
    }
    public function updateShipment(string $oid, array $d, array $s): array
    {
        $actor = $this->admin($s);
        [$carrier, $tracking] = $this->shipmentData($d);
        return $this->db->tx(function () use ($oid, $carrier, $tracking, $s, $actor) {
            $o = $this->db->lock("SELECT * FROM orders WHERE id=?", [$oid]);
            need($o, "سفارش پیدا نشد.", 404);
            need($o["status"] === "shipped", "اطلاعات رهگیری فقط پیش از ثبت تحویل قابل اصلاح است.", 409);
            $ship = $this->db->get("SELECT * FROM order_shipments WHERE order_id=?", [$oid]);
            if (!$ship) {
                $shippedAt = null;
                foreach ($this->db->all("SELECT details_json,created_at FROM audit_logs WHERE entity_id=? AND action='order.status' ORDER BY created_at DESC", [$oid]) as $event) {
                    if ((decode_json($event["details_json"])["to"] ?? null) === "shipped") {
                        $shippedAt = $event["created_at"];
                        break;
                    }
                }
                $this->db->run("INSERT INTO order_shipments(order_id,carrier,tracking_code,shipped_at) VALUES(?,?,?,?)",
                    [$oid, $carrier, $tracking, $shippedAt]);
            } elseif ($ship["carrier"] === $carrier && $ship["tracking_code"] === $tracking) {
                return $this->order($oid, $s, true);
            } else {
                $this->db->run("UPDATE order_shipments SET carrier=?,tracking_code=? WHERE order_id=?", [$carrier, $tracking, $oid]);
            }
            $this->db->run("UPDATE orders SET updated_at=? WHERE id=?", [dt(), $oid]);
            $this->audit($actor, "order.shipment_updated", $oid);
            return $this->order($oid, $s, true);
        });
    }
    public function updateOrderAddress(string $oid, array $d, array $s): array
    {
        $actor = $this->admin($s);
        $a = $this->address($d);
        return $this->db->tx(function () use ($oid, $a, $s, $actor) {
            $o = $this->db->lock("SELECT * FROM orders WHERE id=?", [$oid]);
            need($o, "سفارش پیدا نشد.", 404);
            need(in_array($o["status"], ["pending_payment", "confirmed", "processing"], true),
                "نشانی سفارش فقط پیش از ارسال قابل اصلاح است.", 409);
            if (array_diff_assoc($a, $o)) {
                $this->db->run(
                    "UPDATE orders SET customer_name=?,mobile=?,province=?,city=?,address=?,postal_code=?,updated_at=? WHERE id=?",
                    [...array_values($a), dt(), $oid],
                );
                $this->audit($actor, "order.address_updated", $oid);
            }
            return $this->order($oid, $s, true);
        });
    }
    public function cancelByCustomer(string $oid, array $s): array
    {
        return $this->db->tx(function () use ($oid, $s) {
            $o = $this->db->lock("SELECT * FROM orders WHERE id=?", [$oid]);
            $this->requireOrderAccess($o, $s);
            if ($o["status"] !== "cancelled") {
                need($o["payment_status"] === "unpaid" &&
                    in_array($o["status"], ["pending_payment", "confirmed"], true) &&
                    !$this->db->get("SELECT order_id FROM order_transfer_receipts WHERE order_id=?", [$oid]),
                    "در این مرحله برای لغو با فروشگاه تماس بگیرید.", 409);
                $this->cancelLockedOrder($o, "cancelled", $s["user"] ?? null);
            }
            return $this->order($oid, $s);
        });
    }
    public function submitTransferReceipt(string $oid, array $d, array $s): array
    {
        $reference = en(txt($d["reference"] ?? "", 5, 100));
        return $this->db->tx(function () use ($oid, $reference, $s) {
            $o = $this->db->lock("SELECT * FROM orders WHERE id=?", [$oid]);
            $this->requireOrderAccess($o, $s);
            $old = $this->db->get("SELECT reference FROM order_transfer_receipts WHERE order_id=?", [$oid]);
            if ($old && $old["reference"] === $reference) {
                return $this->order($oid, $s);
            }
            need($o["payment_method"] === "transfer" && $o["payment_status"] === "unpaid" &&
                in_array($o["status"], ["pending_payment", "confirmed"], true) &&
                ($o["expires_at"] === null || $o["expires_at"] > dt()), "مهلت ثبت رسید این سفارش پایان یافته یا وضعیت آن تغییر کرده است.", 409);
            if ($old) {
                $this->db->run("UPDATE order_transfer_receipts SET reference=?,submitted_at=? WHERE order_id=?", [$reference, dt(), $oid]);
            } else {
                $this->db->run("INSERT INTO order_transfer_receipts(order_id,reference,submitted_at) VALUES(?,?,?)", [$oid, $reference, dt()]);
                $this->db->run("UPDATE orders SET expires_at=?,updated_at=? WHERE id=?", [later(86400), dt(), $oid]);
            }
            $this->audit($s["user"] ?? null, "payment.transfer_submitted", $oid);
            return $this->order($oid, $s);
        });
    }
    public function expire(): void
    {
        $rows = $this->db->all(
            "SELECT id FROM orders WHERE status IN ('pending_payment','confirmed') AND payment_status='unpaid' AND expires_at<=? ORDER BY expires_at,id LIMIT 50",
            [dt()],
        );
        foreach ($rows as $row) {
            $this->db->tx(function () use ($row) {
                $o = $this->db->lock("SELECT * FROM orders WHERE id=?", [$row["id"]]);
                if ($o && in_array($o["status"], ["pending_payment", "confirmed"], true) &&
                    $o["payment_status"] === "unpaid" && $o["expires_at"] !== null && $o["expires_at"] <= dt()) {
                    $this->cancelLockedOrder($o, "expired", null);
                }
            });
        }
    }
    public function ledger(
        string $oid,
        string $event,
        string $description,
        int $amount,
        bool $refund = false,
    ): void {
        need($amount > 0);
        if (
            $this->db->get("SELECT id FROM ledger_entries WHERE event_key=?", [
                $event,
            ])
        ) {
            return;
        }
        $id = uid("ledger-");
        $this->db->run(
            "INSERT INTO ledger_entries(id,order_id,event_key,description,created_at) VALUES(?,?,?,?,?)",
            [$id, $oid, $event, $description, dt()],
        );
        $this->db->run(
            "INSERT INTO ledger_lines(id,entry_id,account,debit,credit) VALUES(?,?,?,?,?)",
            [uid("line-"), $id, $refund ? "sales_returns" : "cash", $amount, 0],
        );
        $this->db->run(
            "INSERT INTO ledger_lines(id,entry_id,account,debit,credit) VALUES(?,?,?,?,?)",
            [uid("line-"), $id, $refund ? "cash" : "sales", 0, $amount],
        );
        $s = $this->db->get(
            "SELECT SUM(debit) debit,SUM(credit) credit FROM ledger_lines WHERE entry_id=?",
            [$id],
        );
        need(
            (int) $s["debit"] === (int) $s["credit"],
            "سند مالی نامتوازن است.",
            500,
        );
    }
    public function recordPayment(string $oid, array $d, array $s): array
    {
        $actor = $this->admin($s, true);
        $ref = en(txt($d["reference"] ?? "", 5, 100));
        return $this->db->tx(function () use ($oid, $ref, $s, $actor) {
            $o = $this->db->lock("SELECT * FROM orders WHERE id=?", [$oid]);
            need($o, "سفارش پیدا نشد.", 404);
            need(
                $o["payment_method"] !== "online",
                "پرداخت آنلاین فقط با تأیید درگاه ثبت می‌شود.",
            );
            need(
                $o["payment_method"] === "transfer" || !in_array($o["status"], ["cancelled", "expired"], true),
                "سفارش بسته شده است.",
                409,
            );
            if ($o["payment_status"] === "paid") {
                $prev = $this->db->get(
                    "SELECT reference FROM payments WHERE order_id=? AND status='paid'",
                    [$oid],
                );
                need(
                    $prev && $prev["reference"] === $ref,
                    "پرداخت با مرجع دیگری ثبت شده است.",
                    409,
                );
                return $this->order($oid, $s, true);
            }
            need($o["payment_status"] === "unpaid", "سفارش قابل دریافت مجدد نیست.", 409);
            if (!$o["stock_released"] && $o["expires_at"] !== null && $o["expires_at"] <= dt()) {
                $this->cancelLockedOrder($o, "expired", null);
                $o["stock_released"] = 1;
            }
            $next = $o["stock_released"] ? "payment_review" :
                ($o["status"] === "pending_payment" ? "confirmed" : $o["status"]);
            need(
                !$this->db->get("SELECT id FROM payments WHERE reference=?", [
                    $ref,
                ]),
                "مرجع پرداخت قبلاً ثبت شده است.",
                409,
            );
            $this->db->run(
                "INSERT INTO payments(id,order_id,reference,amount,status,method,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)",
                [
                    uid("pay-"),
                    $oid,
                    $ref,
                    $o["total_amount"],
                    "paid",
                    $o["payment_method"],
                    dt(),
                    dt(),
                ],
            );
            $this->db->run(
                "UPDATE orders SET payment_status='paid',status=?,updated_at=? WHERE id=?",
                [$next, dt(), $oid],
            );
            $this->ledger(
                $oid,
                "paid:" . $oid,
                "دریافت تأییدشده سفارش",
                (int) $o["total_amount"],
            );
            $this->audit($actor, "payment.manual", $oid, [
                "reference" => $ref,
                "amount" => $o["total_amount"],
                "late" => $next === "payment_review",
            ]);
            return $this->order($oid, $s, true);
        });
    }
    public function refund(string $oid, array $d, array $s): array
    {
        $actor = $this->admin($s, true);
        $ref = en(txt($d["reference"] ?? "", 5, 100));
        need(
            ($d["confirmed"] ?? false) === true,
            "برگشت فقط پس از بازپرداخت واقعی قابل ثبت است.",
        );
        return $this->db->tx(function () use ($oid, $d, $s, $actor, $ref) {
            $o = $this->db->lock("SELECT * FROM orders WHERE id=?", [$oid]);
            need($o, "سفارش پیدا نشد.", 404);
            if ($o["payment_status"] === "refunded") {
                $prior = $this->db->get(
                    "SELECT reference FROM payments WHERE order_id=? AND method='refund'",
                    [$oid],
                );
                need(
                    $prior && $prior["reference"] === $ref,
                    "برگشت با مرجع دیگری قبلاً ثبت شده است.",
                    409,
                );
                return $this->order($oid, $s, true);
            }
            need(
                $o["payment_status"] === "paid",
                "فقط وجه دریافت‌شده قابل برگشت است.",
                409,
            );
            need(
                !$this->db->get("SELECT id FROM payments WHERE reference=?", [
                    $ref,
                ]),
                "مرجع تکراری است.",
                409,
            );
            $this->db->run(
                "INSERT INTO payments(id,order_id,reference,amount,status,method,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)",
                [
                    uid("refund-"),
                    $oid,
                    $ref,
                    $o["total_amount"],
                    "refunded",
                    "refund",
                    dt(),
                    dt(),
                ],
            );
            $restock = !$o["stock_released"] &&
                (!in_array($o["status"], ["shipped", "delivered"], true) || ($d["restock"] ?? false) === true);
            if ($restock) {
                $this->release($o, "refund_returned", $actor);
            }
            $this->db->run(
                "UPDATE orders SET payment_status='refunded',status='refunded',updated_at=? WHERE id=?",
                [dt(), $oid],
            );
            $this->ledger(
                $oid,
                "refund:" . $oid,
                "برگشت وجه تأییدشده",
                (int) $o["total_amount"],
                true,
            );
            $this->audit($actor, "payment.refunded", $oid, [
                "reference" => $ref,
                "restock" => $restock,
            ]);
            return $this->order($oid, $s, true);
        });
    }
    public function receiveReturn(string $oid, array $d, array $s): array
    {
        $actor = $this->admin($s);
        need(($d["confirmed"] ?? false) === true, "دریافت و بررسی همه اقلام را تأیید کنید.");
        return $this->db->tx(function () use ($oid, $s, $actor) {
            $o = $this->db->lock("SELECT * FROM orders WHERE id=?", [$oid]);
            need($o, "سفارش پیدا نشد.", 404);
            need($o["status"] === "refunded", "ثبت مرجوعی برای این سفارش مجاز نیست.", 409);
            if (!$o["stock_released"]) {
                $this->release($o, "refund_returned", $actor);
                $this->db->run("UPDATE orders SET updated_at=? WHERE id=?", [dt(), $oid]);
                $this->audit($actor, "order.return_received", $oid);
            }
            return $this->order($oid, $s, true);
        });
    }

}
