<?php
// Apache public DocumentRoot entry. Also apply headers without mod_headers.
ini_set('display_errors', '0');
require_once dirname(__DIR__) . '/php/core.php';
$pageConfig = read_config();
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' https: data:; font-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'");
if ($pageConfig['secure']) header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH) ?: "/";
if (
    str_starts_with($path, "/api/") ||
    in_array(
        $path,
        ["/payment/callback", "/healthz", "/sitemap.xml", "/robots.txt"],
        true,
    )
) {
    require dirname(__DIR__) . "/php/http.php";
    run_http();
    return;
}
$routes = [
    "/",
    "/category",
    "/product",
    "/cart",
    "/configurator",
    "/admin",
    "/profile",
    "/login",
    "/register",
    "/about",
    "/contact",
    "/blog",
    "/warranty",
    "/setup",
];
$known =
    in_array(rtrim($path, "/") ?: "/", $routes, true) ||
    preg_match('#^/(product|category|order|article|invoice)/[^/]+/?$#', $path);
if (!$known) {
    http_response_code(404);
}
header("Content-Type: text/html; charset=utf-8");
header("Cache-Control: no-cache");
$html = file_get_contents(__DIR__ . ($known ? "/index.html" : "/404.html"));
if (preg_match('#^/product/([^/]+)$#', $path, $match)) {
    try {
        require_once dirname(__DIR__) . "/php/core.php";
        $cfg = read_config();
        $shop = new Shop(new DB($cfg), $cfg);
        $shop->init();
        $p = $shop->product(rawurldecode($match[1]));
        $esc = fn($s) => htmlspecialchars(
            $s,
            ENT_QUOTES | ENT_SUBSTITUTE,
            "UTF-8",
        );
        $title = $esc($p["title"] . " | امیر سیستم");
        $description = $esc(
            mb_substr($p["description"] ?: $p["title"], 0, 170),
        );
        $html = preg_replace_callback(
            "#<title>.*?</title>#s",
            fn() => "<title>" . $title . "</title>",
            $html,
        );
        $html = preg_replace_callback(
            '#<meta\s+name="description"\s+content="[^"]*"\s*/?>#',
            fn() => '<meta name="description" content="' . $description . '">',
            $html,
        );
    } catch (Throwable $e) {
        if ($e instanceof HttpError && $e->status === 404) {
            http_response_code(404);
            $html = file_get_contents(__DIR__ . "/404.html");
        }
    }
}
if (
    preg_match(
        '#^/(admin|profile|cart|order|invoice|setup|login|register)(/|$)#',
        $path,
    )
) {
    $html = str_replace(
        "</head>",
        '<meta name="robots" content="noindex,nofollow"></head>',
        $html,
    );
}
echo $html;
