<?php
declare(strict_types=1);

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit(1);
}

require __DIR__ . "/core.php";
try {
    $config = read_config();
    $shop = new Shop(new DB($config), $config);
    $shop->init();
    $shop->expire();
    $pruned = $shop->pruneExpiredState();
    printf("Expired sessions removed: %d; expired rate limits removed: %d\n", $pruned["sessions"], $pruned["rate_limits"]);
} catch (Throwable $error) {
    error_log("Amir System maintenance failed: " . get_class($error));
    exit(1);
}
