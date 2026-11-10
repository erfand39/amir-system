"""Exercise public checkout, concurrent stock reservation, and guest order recovery."""
import concurrent.futures
import http.cookiejar
import json
import os
import pathlib
import signal
import socket
import subprocess
import tempfile
import threading
import time
import urllib.error
import urllib.request

root = pathlib.Path(__file__).resolve().parents[1]
php = json.loads(os.environ.get("AMIR_TEST_PHP_COMMAND", '["php"]'))
mysql = os.environ.get("AMIR_TEST_MYSQL") == "true"
if mysql:
    assert os.environ.get("DB_NAME", "").startswith("amir_test_"), "Use a disposable database"
with tempfile.TemporaryDirectory(prefix="amir-workflow-") as temp:
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        port = sock.getsockname()[1]
    origin = f"http://127.0.0.1:{port}"
    env = dict(os.environ, DB_DRIVER="mysql" if mysql else "sqlite",
               SQLITE_PATH=temp + "/shop.sqlite", AMIR_ALLOW_BOOTSTRAP="true",
               NODE_ENV="test", COOKIE_SECURE="false", APP_URL=origin,
               ZARINPAL_MERCHANT_ID="", SETUP_TOKEN="", PHP_CLI_SERVER_WORKERS="4")
    init = r'''require 'php/api.php';
$cfg=read_config();$s=new Shop(new DB($cfg),$cfg);$s->init();
$u=$s->register(['name'=>'HTTP Owner','phone'=>'09110000001','password'=>'Workflow-HTTP-Password'], 'admin');
$session=$s->newSession($u['id']);$admin=new Admin($s);
foreach ([1,2] as $qty) {
    $p=$admin->save('products',['title'=>'HTTP Test Product '.$qty, 'slug'=>'http-qa-'.$qty,
        'category_id'=>'cat-speakers','brand_id'=>'brand-pioneer','price_toman'=>1000000,
        'stock'=>$qty,'active'=>true],$session);
    echo $p['id'].PHP_EOL;
}
'''
    pids = subprocess.check_output(php + ["-r", init], cwd=root, env=env, text=True).splitlines()
    assert len(pids) == 2, pids
    with open(temp + "/server.log", "w+") as log:
        proc = subprocess.Popen(php + ["-S", f"127.0.0.1:{port}", "php/router.php"], cwd=root,
                                env=env, stdout=log, stderr=log, start_new_session=True)

        def client():
            return urllib.request.build_opener(
                urllib.request.ProxyHandler({}), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

        def call(opener, path, data=None, csrf="", key="", cookie=""):
            headers = {"Accept": "application/json"}
            if data is not None:
                headers.update({"Content-Type": "application/json", "X-CSRF-Token": csrf})
            if key:
                headers["Idempotency-Key"] = key
            if cookie:
                headers["Cookie"] = cookie
            request = urllib.request.Request(origin + "/api/" + path, headers=headers,
                                             data=None if data is None else json.dumps(data).encode())
            try:
                res = opener.open(request, timeout=10)
            except urllib.error.HTTPError as error:
                res = error
            with res:
                return res.code, json.load(res), res.headers

        def result(response):
            status, payload, _ = response
            assert status == 200, (status, payload)
            return payload["data"]

        def payload(pid):
            return {"items": [{"id": pid, "qty": 1}], "customer_name": "Guest Checkout", "mobile": "09110000002",
                    "province": "Kerman", "city": "Kerman", "address": "Guest checkout test address",
                    "postal_code": "7612345678", "payment_method": "cod"}

        def race(tasks):
            barrier = threading.Barrier(len(tasks))

            def submit(args):
                barrier.wait()
                return call(*args)

            with concurrent.futures.ThreadPoolExecutor(max_workers=len(tasks)) as pool:
                return list(pool.map(submit, tasks))

        try:
            for _ in range(80):
                try:
                    with socket.create_connection(("127.0.0.1", port), timeout=.1):
                        break
                except OSError:
                    time.sleep(.05)
            a, b = client(), client()
            acsrf = result(call(a, "bootstrap"))["csrf"]
            bcsrf = result(call(b, "bootstrap"))["csrf"]
            one, two = race([(a, "orders", payload(pids[0]), acsrf, "race-last-piece-alpha"),
                             (b, "orders", payload(pids[0]), bcsrf, "race-last-piece-bravo")])
            assert sorted([one[0], two[0]]) == [200, 409], (one[:2], two[:2])
            assert result(call(a, "products/" + pids[0]))["stock"] == 0
            print("PASS: two HTTP checkouts competing for the last unit reserve it only once")

            guest = client()
            code, boot, headers = call(guest, "bootstrap")
            assert code == 200
            cookie = headers["Set-Cookie"].split(";", 1)[0]
            csrf = boot["data"]["csrf"]
            key = "workflow-guest-recovery-01"
            first, second = race([(client(), "orders", payload(pids[1]), csrf, key, cookie),
                                  (client(), "orders", payload(pids[1]), csrf, key, cookie)])
            order = result(first)
            assert result(second)["id"] == order["id"]
            assert result(call(guest, "products/" + pids[1]))["stock"] == 1
            assert result(call(guest, "orders/recover", {"key": key}, csrf))["id"] == order["id"]
            print("PASS: parallel duplicate HTTP submissions return one order and reserve one unit")

            result(call(guest, "auth/register", {"name": "Guest Checkout", "phone": "09110000002",
                                                "password": "Workflow-Buyer-Password"}, csrf))
            account = result(call(guest, "bootstrap"))
            assert account["user"] and account["csrf"] != csrf
            assert result(call(guest, "orders"))[0]["id"] == order["id"]
            assert result(call(guest, "orders/recover", {"key": key}, account["csrf"]))["id"] == order["id"]
            assert call(b, "orders/" + order["id"])[0] == 403
            assert call(client(), "orders/" + order["id"], cookie=cookie)[0] == 403
            print("PASS: same-browser registration claims guest order, recovers the key, and invalidates guest access")

            admin = client()
            csrf = result(call(admin, "bootstrap"))["csrf"]
            result(call(admin, "auth/login", {"phone": "09110000001", "password": "Workflow-HTTP-Password"}, csrf))
            assert result(call(admin, "admin/health"))["integrity_ok"]
            print("PASS: reconciled stock and order records after concurrent HTTP requests")
        except BaseException:
            log.seek(0)
            print(log.read())
            raise
        finally:
            os.killpg(proc.pid, signal.SIGTERM)
            proc.wait(timeout=5)
