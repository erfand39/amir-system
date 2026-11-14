<?php
declare(strict_types=1);
final class Payment
{
    public Shop $shop;
    public DB $db;
    private $transport;
    public function __construct(Shop $s, ?callable $transport = null)
    {
        $this->shop = $s;
        $this->db = $s->db;
        $this->transport = $transport;
    }
    private function call(string $action, array $data): array
    {
        if ($this->transport) {
            return ($this->transport)($action, $data);
        }
        need(extension_loaded("curl"), "افزونه cURL روی سرور فعال نیست.", 503);
        $url =
            "https://" .
            ($this->shop->cfg["sandbox"] ? "sandbox" : "api") .
            ".zarinpal.com/pg/v4/payment/" .
            $action .
            ".json";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
            CURLOPT_POSTFIELDS => encode_json($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $result = decode_json($raw);
        $errorCode = $result["errors"]["code"] ?? null;
        if ($action === "request" && $status >= 200 && $status < 500 &&
            !in_array($status, [408, 425, 429], true) && is_numeric($errorCode) && (int) $errorCode < 0) {
            return ["code" => (int) $errorCode];
        }
        need(
            $status >= 200 &&
                $status < 300 &&
                isset($result["data"]) &&
                is_array($result["data"]),
            "پاسخ درگاه نامشخص است؛ پیش از تلاش مجدد وضعیت سفارش را بررسی کنید.",
            502,
        );
        return $result["data"];
    }
    public function request(string $oid, array $s): array
    {
        need(
            $this->shop->cfg["merchant"] && $this->shop->cfg["origin"],
            "درگاه و آدرس اصلی فروشگاه تنظیم نشده‌اند.",
            409,
        );
        $o = $this->shop->order($oid, $s);
        need(
            $o["payment_method"] === "online" &&
                $o["status"] === "pending_payment" &&
                $o["payment_status"] === "unpaid",
            "این سفارش قابل پرداخت نیست.",
            409,
        );
        $p = $this->db->tx(function () use ($oid, $o) {
            $current = $this->db->lock("SELECT * FROM orders WHERE id=?", [$oid]);
            need($current && $current["status"] === "pending_payment" && $current["payment_status"] === "unpaid" && !$current["stock_released"] && $current["expires_at"] > dt(), "مهلت پرداخت سفارش تمام شده یا وضعیت آن تغییر کرده است.", 409);
            $old = $this->db->get(
                "SELECT * FROM payments WHERE order_id=? AND status IN ('requesting','pending','unknown','paid') ORDER BY created_at DESC",
                [$oid],
            );
            if ($old) {
                return array_merge($old, ["reused" => true]);
            }
            $id = uid("pay-");
            $this->db->run(
                "INSERT INTO payments(id,order_id,amount,status,method,created_at,updated_at) VALUES(?,?,?,?,?,?,?)",
                [
                    $id,
                    $oid,
                    $o["total_amount"],
                    "requesting",
                    "online",
                    dt(),
                    dt(),
                ],
            );
            return ["id" => $id];
        });
        if (!empty($p["reused"])) {
            need(
                !empty($p["authority"]) && $p["status"] === "pending",
                "نتیجه درخواست قبلی مشخص نیست؛ درخواست جدید ایجاد نشد.",
                409,
            );
            return ["url" => $this->start($p["authority"])];
        }
        try {
            $d = $this->call("request", [
                "merchant_id" => $this->shop->cfg["merchant"],
                "amount" => $o["total_amount"] * 10,
                "currency" => "IRR",
                "callback_url" =>
                    $this->shop->cfg["origin"] . "/payment/callback",
                "description" => "سفارش " . $o["tracking_code"],
                "metadata" => ["mobile" => $o["mobile"]],
            ]);
            if (isset($d["code"]) && is_numeric($d["code"]) && (int) $d["code"] < 0) {
                $this->db->run("UPDATE payments SET status='failed',updated_at=? WHERE id=?", [dt(), $p["id"]]);
                throw new HttpError("درگاه درخواست را نپذیرفت. می‌توانید دوباره تلاش کنید.", 409);
            }
            need(
                (int) ($d["code"] ?? 0) === 100 &&
                    is_string($d["authority"] ?? null) &&
                    preg_match('/^[A-Za-z0-9_-]{10,100}$/D', $d["authority"]),
                "درگاه درخواست را نپذیرفت.",
                502,
            );
            $active = $this->db->tx(function () use ($oid, $p, $d) {
                $current = $this->db->lock("SELECT status,payment_status,stock_released,expires_at FROM orders WHERE id=?", [$oid]);
                $updated = $this->db->run(
                    "UPDATE payments SET authority=?,status='pending',updated_at=? WHERE id=? AND status='requesting' AND authority IS NULL",
                    [$d["authority"], dt(), $p["id"]],
                );
                return $updated && $current && $current["status"] === "pending_payment" && $current["payment_status"] === "unpaid" &&
                    !$current["stock_released"] && $current["expires_at"] > dt();
            });
            need($active, "سفارش در زمان اتصال به درگاه بسته شد.", 409);
            return ["url" => $this->start($d["authority"])];
        } catch (Throwable $e) {
            $this->db->run(
                "UPDATE payments SET status='unknown',updated_at=? WHERE id=? AND status='requesting'",
                [dt(), $p["id"]],
            );
            throw $e;
        }
    }
    public function recheck(string $oid, array $s, bool $admin = false): array
    {
        $o = $this->shop->order($oid, $s, $admin);
        if ($o["payment_method"] === "online" && $o["payment_status"] === "paid") {
            return ["order_id" => $oid, "paid" => true, "requires_review" => $o["status"] === "payment_review"];
        }
        need($o["can_recheck_online"], "پرداخت آنلاین قابل استعلام پیدا نشد.", 409);
        $p = $this->db->get(
            "SELECT authority FROM payments WHERE order_id=? AND status IN ('pending','paid') AND authority IS NOT NULL ORDER BY created_at DESC LIMIT 1",
            [$oid],
        );
        need($p, "پرداخت آنلاین قابل استعلام پیدا نشد.", 409);
        return $this->verify($p["authority"], "OK");
    }
    public function resolveUnknown(string $oid, array $data, array $session): array
    {
        $actor = $this->shop->admin($session, true);
        need(($data["confirmed"] ?? false) === true, "تطبیق با پنل پذیرنده را تأیید کنید.");
        $attempt = txt($data["attempt_id"] ?? "", 1, 64);
        $authority = txt($data["authority"] ?? "", 0, 100);
        need($authority === "" || preg_match('/^[A-Za-z0-9_-]{10,100}$/D', $authority), "شناسه درگاه معتبر نیست.");
        return $this->db->tx(function () use ($oid, $attempt, $authority, $actor, $session) {
            $order = $this->db->lock("SELECT * FROM orders WHERE id=?", [$oid]);
            need($order && $order["payment_method"] === "online" && $order["payment_status"] === "unpaid",
                "این سفارش پرداخت نامشخص قابل رسیدگی ندارد.", 409);
            $payment = $this->db->lock("SELECT * FROM payments WHERE id=? AND order_id=? AND method='online'", [$attempt, $oid]);
            need($payment, "درخواست پرداخت پیدا نشد.", 404);
            if ($payment["status"] === "pending" && $authority !== "" && $payment["authority"] === $authority) {
                return $this->shop->order($oid, $session, true);
            }
            if ($payment["status"] === "cancelled" && $authority === "") {
                return $this->shop->order($oid, $session, true);
            }
            need(!$payment["authority"] && ($payment["status"] === "unknown" ||
                ($payment["status"] === "requesting" && $payment["updated_at"] <= later(-120))),
                "درخواست هنوز در حال پردازش است یا قبلاً تعیین تکلیف شده است.", 409);
            if ($authority === "") {
                need(in_array($order["status"], ["cancelled", "expired"], true),
                    "برای بستن درخواست بدون شناسه درگاه، سفارش باید بسته شده باشد.", 409);
            }
            $this->db->run("UPDATE payments SET authority=?,status=?,updated_at=? WHERE id=?",
                [$authority === "" ? null : $authority, $authority === "" ? "cancelled" : "pending", dt(), $attempt]);
            $this->shop->audit($actor, "payment.unknown_reviewed", $oid, [
                "attempt_id" => $attempt,
                "outcome" => $authority === "" ? "closed" : "authority_restored",
            ]);
            return $this->shop->order($oid, $session, true);
        });
    }
    private function start(string $authority): string
    {
        return "https://" .
            ($this->shop->cfg["sandbox"] ? "sandbox" : "www") .
            ".zarinpal.com/pg/StartPay/" .
            rawurlencode($authority);
    }
    public function verify(string $authority, string $status): array
    {
        need($this->shop->cfg["merchant"], "درگاه فعال نیست.", 409);
        need(
            preg_match('/^[A-Za-z0-9_-]{10,100}$/', $authority),
            "شناسه پرداخت معتبر نیست.",
        );
        $p = $this->db->get("SELECT * FROM payments WHERE authority=?", [
            $authority,
        ]);
        need($p, "درخواست پرداخت پیدا نشد.", 404);
        if ($p["status"] === "paid") {
            return ["order_id" => $p["order_id"], "paid" => true];
        }
        if ($status !== "OK") {
            return ["order_id" => $p["order_id"], "paid" => false];
        }
        $result = $this->call("verify", [
            "merchant_id" => $this->shop->cfg["merchant"],
            "amount" => (int) $p["amount"] * 10,
            "authority" => $authority,
        ]);
        need(
            in_array((int) ($result["code"] ?? 0), [100, 101], true) &&
                !empty($result["ref_id"]),
            "پرداخت توسط درگاه تأیید نشد.",
            409,
        );
        return $this->db->tx(function () use ($p, $result) {
            $o = $this->db->lock("SELECT * FROM orders WHERE id=?", [
                $p["order_id"],
            ]);
            $pay = $this->db->lock("SELECT * FROM payments WHERE id=?", [
                $p["id"],
            ]);
            if ($pay["status"] === "paid") {
                return ["order_id" => $o["id"], "paid" => true];
            }
            need(
                (int) $pay["amount"] === (int) $o["total_amount"],
                "مبلغ پرداخت با سفارش همخوانی ندارد.",
                409,
            );
            need($o["payment_method"] === "online" && $o["payment_status"] === "unpaid" &&
                in_array($o["status"], ["pending_payment", "cancelled", "expired"], true),
                "وضعیت پرداخت سفارش قبلاً تغییر کرده است.", 409);
            if (!$o["stock_released"] && $o["expires_at"] !== null && $o["expires_at"] <= dt()) {
                $this->shop->release($o, "order_expired", null);
                $o["stock_released"] = 1;
            }
            $late = (bool) $o["stock_released"];
            $this->db->run(
                "UPDATE payments SET status='paid',reference=?,updated_at=? WHERE id=?",
                [(string) $result["ref_id"], dt(), $p["id"]],
            );
            $this->db->run(
                "UPDATE orders SET payment_status='paid',status=?,updated_at=? WHERE id=?",
                [$late ? "payment_review" : "confirmed", dt(), $o["id"]],
            );
            $this->shop->ledger(
                $o["id"],
                "paid:" . $o["id"],
                "دریافت تأییدشده از درگاه",
                (int) $o["total_amount"],
            );
            $this->shop->audit(null, "payment.verified", $o["id"], [
                "reference" => (string) $result["ref_id"],
                "late" => $late,
            ]);
            return [
                "order_id" => $o["id"],
                "paid" => true,
                "requires_review" => $late,
            ];
        });
    }
}
