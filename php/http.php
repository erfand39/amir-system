<?php
declare(strict_types=1);
require_once __DIR__ . "/api.php";
function run_http(): void
{
    $requestId = uid("req-");
    header("X-Request-Id: " . $requestId);
    header("X-Content-Type-Options: nosniff");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("X-Frame-Options: DENY");
    header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
    header(
        "Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' https: data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'",
    );
    header("Cache-Control: no-store");
    try {
        $cfg = read_config();
        need(
            getenv("NODE_ENV") !== "production" ||
                str_starts_with($cfg["origin"], "https://"),
            "Production requires an HTTPS APP_URL.",
            503,
        );
        if ($cfg["secure"]) {
            header(
                "Strict-Transport-Security: max-age=31536000; includeSubDomains",
            );
        }
        $db = new DB($cfg);
        $shop = new Shop($db, $cfg);
        $shop->init();
        $shop->rate("http-ip:" . ($_SERVER["REMOTE_ADDR"] ?? "unknown"), 600, 1);
        if (!$cfg["maintenance_cron"]) {
            $shop->expire();
        }
        $api = new API($shop);
        $path = parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH) ?: "/";
        $method = $_SERVER["REQUEST_METHOD"] ?? "GET";
        need(in_array($method, ["GET", "HEAD", "POST", "PUT", "DELETE"], true), "روش درخواست مجاز نیست.", 405);
        need(!str_contains($path, "//") && !str_contains($path, "\\") && !preg_match('/[\x00-\x1f\x7f]/', $path), "نشانی درخواست معتبر نیست.", 400);
        if (str_starts_with($path, "/api/")) {
            need(rawurldecode($path) === $path || preg_match('#^/api/(products|articles)/[^/]+$#', $path), "از نشانی استاندارد درخواست استفاده کنید.", 400);
        }
        if ($path === "/robots.txt") {
            header("Content-Type: text/plain; charset=utf-8");
            echo "User-agent: *\nAllow: /\nDisallow: /api/\nDisallow: /admin\nDisallow: /setup\nDisallow: /profile\nDisallow: /cart\nDisallow: /order/\nDisallow: /invoice/\nDisallow: /login\nDisallow: /register\n";
            if ($cfg["origin"]) {
                echo "Sitemap: " . $cfg["origin"] . "/sitemap.xml\n";
            }
            return;
        }
        if ($path === "/sitemap.xml") {
            header("Content-Type: application/xml; charset=utf-8");
            $paths = [
                "/",
                "/category",
                "/configurator",
                "/about",
                "/contact",
                "/blog",
                "/warranty",
            ];
            foreach (
                $db->all("SELECT slug FROM products WHERE active=1")
                as $p
            ) {
                $paths[] = "/product/" . rawurlencode($p["slug"]);
            }
            foreach (
                $db->all("SELECT slug FROM articles WHERE published=1")
                as $a
            ) {
                $paths[] = "/article/" . rawurlencode($a["slug"]);
            }
            echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
            foreach ($paths as $p) {
                echo "<url><loc>" .
                    htmlspecialchars(
                        $cfg["origin"] . $p,
                        ENT_XML1 | ENT_QUOTES,
                        "UTF-8",
                    ) .
                    "</loc></url>";
            }
            echo "</urlset>";
            return;
        }
        if ($path === "/healthz") {
            $result = ["ok" => true];
        } elseif ($path === "/payment/callback") {
            need($method === "GET", "روش درخواست مجاز نیست.", 405);
            $result = $api->payment->verify(
                (string) ($_GET["Authority"] ?? ""),
                (string) ($_GET["Status"] ?? ""),
            );
            header(
                "Location: /order/" .
                    rawurlencode($result["order_id"]) .
                    "?payment=" .
                    ($result["paid"] ? "verified" : "unverified"),
                true,
                303,
            );
            return;
        } else {
            need(
                str_starts_with($path, "/api/"),
                "مسیر درخواست پیدا نشد.",
                404,
            );
            $session = $shop->session($_COOKIE["as_session"] ?? "");
            $setCookie = function (string $token) use ($cfg) {
                setcookie("as_session", $token, [
                    "expires" => time() + $cfg["session_hours"] * 3600,
                    "path" => "/",
                    "secure" => $cfg["secure"],
                    "httponly" => true,
                    "samesite" => "Lax",
                ]);
            };
            if (!$session) {
                $session = $shop->newSession();
                $setCookie($session["token"]);
            }
            $body = [];
            if (!in_array($method, ["GET", "HEAD"], true)) {
                $origin = $_SERVER["HTTP_ORIGIN"] ?? "";
                $expected = $cfg["origin"];
                $originHost = parse_url($origin, PHP_URL_HOST);
                $originPort = parse_url($origin, PHP_URL_PORT);
                $host = $originHost . ($originPort ? ":" . $originPort : "");
                need(
                    !$origin ||
                        ($expected
                            ? $origin === $expected
                            : $host === ($_SERVER["HTTP_HOST"] ?? "")),
                    "مبدأ درخواست معتبر نیست.",
                    403,
                );
                need(
                    hash_equals(
                        $session["csrf_token"],
                        $_SERVER["HTTP_X_CSRF_TOKEN"] ?? "",
                    ),
                    "نشست شما تغییر کرده است؛ صفحه را تازه کنید.",
                    419,
                );
                need(
                    str_starts_with(
                        $_SERVER["CONTENT_TYPE"] ?? "",
                        "application/json",
                    ),
                    "نوع درخواست باید JSON باشد.",
                    415,
                );
                need(
                    (int) ($_SERVER["CONTENT_LENGTH"] ?? 0) <= 262144,
                    "حجم اطلاعات بیش از حد مجاز است.",
                    413,
                );
                $raw = file_get_contents("php://input", false, null, 0, 262145);
                need(
                    strlen($raw) <= 262144,
                    "حجم اطلاعات بیش از حد مجاز است.",
                    413,
                );
                try {
                    $object = json_decode(
                        $raw ?: "{}",
                        false,
                        512,
                        JSON_THROW_ON_ERROR,
                    );
                    need(is_object($object), "داده درخواست معتبر نیست.");
                    $body = json_decode(
                        $raw ?: "{}",
                        true,
                        512,
                        JSON_THROW_ON_ERROR,
                    );
                } catch (JsonException $e) {
                    throw new HttpError("قالب داده معتبر نیست.");
                }
            }
            $rotate = function (string $userId) use (
                $db,
                $shop,
                $session,
                $setCookie,
            ) {
                $next = $db->tx(function () use (
                    $db,
                    $shop,
                    $session,
                    $userId,
                ) {
                    $prior = $db->lock("SELECT user_id FROM sessions WHERE id=?", [$session["id"]]);
                    $next = $shop->newSession($userId);
                    if ($prior && $prior["user_id"] === null) {
                        $db->run(
                            "UPDATE orders SET user_id=? WHERE guest_session_id=? AND user_id IS NULL",
                            [$userId, $session["id"]],
                        );
                    }
                    $db->run("DELETE FROM sessions WHERE id=?", [
                        $session["id"],
                    ]);
                    return $next;
                });
                $setCookie($next["token"]);
                return ["csrf" => $next["csrf_token"]];
            };
            $result = $api->handle(
                $method,
                substr($path, 5),
                $body,
                $_GET,
                $session,
                [
                    "ip" => $_SERVER["REMOTE_ADDR"] ?? "unknown",
                    "idempotency_key" => $_SERVER["HTTP_IDEMPOTENCY_KEY"] ?? "",
                    "rotate" => $rotate,
                    "clear_cookie" => function () use ($cfg) {
                        setcookie("as_session", "", [
                            "expires" => 1,
                            "path" => "/",
                            "secure" => $cfg["secure"],
                            "httponly" => true,
                            "samesite" => "Lax",
                        ]);
                    },
                ],
            );
        }
        header("Content-Type: application/json; charset=utf-8");
        echo encode_json([
            "status" => "success",
            "data" => $result,
            "request_id" => $requestId,
        ]);
    } catch (Throwable $e) {
        $status =
            $e instanceof HttpError
                ? $e->status
                : (preg_match(
                    "/constraint|duplicate|unique/i",
                    $e->getMessage(),
                )
                    ? 409
                    : 500);
        http_response_code($status);
        if ($status === 429) { header("Retry-After: 60"); }
        header("Content-Type: application/json; charset=utf-8");
        if ($status === 500) {
            error_log("[" . $requestId . "] " . $e);
        }
        echo encode_json([
            "status" => "error",
            "message" =>
                $e instanceof HttpError
                    ? $e->getMessage()
                    : ($status === 409
                        ? "این اطلاعات قبلاً ثبت شده یا با رکورد دیگری در ارتباط است."
                        : "خطای موقت رخ داد؛ کد پیگیری را به پشتیبانی بدهید."),
            "request_id" => $requestId,
        ]);
    }
}
