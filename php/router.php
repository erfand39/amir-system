<?php
// PHP's development server only. Production uses Apache/Nginx.
$root = dirname(__DIR__);
$path = rawurldecode(parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH) ?: "/");
if (
    preg_match(
        '#^/(assets|images)/[a-zA-Z0-9/_.-]+\.(css|js|ttf|woff2|svg|png|jpg|jpeg|webp)$#',
        $path,
    ) ||
    in_array(
        $path,
        ["/manifest.json", "/sw.js", "/offline.html", "/404.html"],
        true,
    )
) {
    $file = realpath($root . "/public" . $path);
    if (
        $file &&
        str_starts_with(
            $file,
            realpath($root . "/public") . DIRECTORY_SEPARATOR,
        ) &&
        is_file($file)
    ) {
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        $types = [
            "css" => "text/css",
            "js" => "text/javascript",
            "json" => "application/json",
            "svg" => "image/svg+xml",
            "png" => "image/png",
            "jpg" => "image/jpeg",
            "jpeg" => "image/jpeg",
            "ttf" => "font/ttf",
        ];
        header("Content-Type: " . ($types[$ext] ?? "application/octet-stream"));
        header("X-Content-Type-Options: nosniff");
        readfile($file);
        return;
    }
}
require $root . "/public/index.php";
