<?php
declare(strict_types=1);
require_once __DIR__ . "/core.php";
function health_report(Shop $shop): array
{
    return $shop->db->tx(function () use ($shop) {
        $db = $shop->db;
        $definitions = json_decode(
            file_get_contents(
                $shop->cfg["root"] . "/database/health-checks.json",
            ),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $checks = [];
        foreach ($definitions as $check) {
            $sql = $check["sql"];
            if ($db->driver === "sqlite") {
                $sql = str_replace(
                    ["CONCAT('paid:',o.id)", "CONCAT('refund:',o.id)", "CONCAT('item-return:',r.id)", "CONCAT('item-refund:',r.id)"],
                    ["('paid:' || o.id)", "('refund:' || o.id)", "('item-return:' || r.id)", "('item-refund:' || r.id)"],
                    $sql,
                );
            }
            $count = (int) $db->get($sql)["n"];
            $checks[] = [
                "id" => $check["id"],
                "label" => $check["label"],
                "status" => $count ? "fail" : "pass",
                "count" => $count,
            ];
        }
        $store = $shop->setting("store");
        $admin = (bool) $db->get(
            "SELECT id FROM users WHERE role='admin' AND active=1 LIMIT 1",
        );
        $cfg = $shop->cfg;
        $deployment = [
            ["database", "دیتابیس سرویس روی MySQL", $db->driver === "mysql"],
            [
                "https",
                "آدرس اصلی HTTPS",
                str_starts_with($cfg["origin"], "https://"),
            ],
            ["secure_cookie", "کوکی امن فعال", $cfg["secure"]],
            [
                "production",
                "حالت تولید فعال",
                getenv("NODE_ENV") === "production",
            ],
            ["admin", "مدیر اصلی فعال ساخته شده", $admin],
            [
                "setup_closed",
                "کلید نصب پس از ساخت مدیر حذف شده",
                $admin && !$cfg["setup_token"],
            ],
            [
                "business_details",
                "تلفن و نشانی فروشگاه تکمیل شده",
                (bool) trim($store["phone"] ?? "") &&
                (bool) trim($store["address"] ?? ""),
            ],
        ];
        $integrity = !array_filter($checks, fn($c) => $c["status"] !== "pass");
        return [
            "database" => $db->driver,
            "schema_version" => $shop->setting("schema_version"),
            "ledger_balanced" => $checks[0]["status"] === "pass",
            "integrity_ok" => $integrity,
            "online_payment_configured" => (bool) $cfg["merchant"],
            "checks" => $checks,
            "deployment_checks" => array_map(
                fn($c) => [
                    "id" => $c[0],
                    "label" => $c[1],
                    "status" => $c[2] ? "pass" : "warn",
                ],
                $deployment,
            ),
            "pending_payment_reviews" => (int) $db->get(
                "SELECT COUNT(*) n FROM orders WHERE status='payment_review'",
            )["n"],
            "unknown_payments" => (int) $db->get(
                "SELECT COUNT(*) n FROM payments WHERE status IN ('requesting','unknown')",
            )["n"],
            "checked_at" => gmdate("Y-m-d\TH:i:s\Z"),
            "notice" =>
                "این گزارش جای آزمون درگاه واقعی، تنظیمات وب‌سرور و تأیید اطلاعات تجاری را نمی‌گیرد.",
        ];
    });
}
