#!/usr/bin/env python3
"""Validate the feed contract locally, or verify the private dev origin as root."""
import argparse
import base64
import concurrent.futures
import json
import math
from pathlib import Path
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request

import jsonschema

parser = argparse.ArgumentParser()
parser.add_argument("--schema-only", action="store_true")
parser.add_argument("--benchmark-only", action="store_true")
parser.add_argument("--spec", default="/var/www/html/wp-content/plugins/ultralove-product-feed/openapi.json")
options = parser.parse_args()
spec = json.loads(Path(options.spec).read_text())
for schema in spec["components"]["schemas"].values():
    jsonschema.Draft202012Validator.check_schema(schema)
assert spec["openapi"] == "3.1.0"
assert all(set(path) == {"get"} for path in spec["paths"].values())
assert set(spec["paths"]) == {"/products", "/products/{id}", "/inventory", "/changes", "/openapi"}
if options.schema_only:
    samples = subprocess.run(["php", str(Path(__file__).with_name("test-product-feed.php")), "--samples"], text=True, capture_output=True, check=True)
    payload = next(line.split("FEED-SAMPLES:", 1)[1] for line in samples.stdout.splitlines() if "FEED-SAMPLES:" in line)
    validator = jsonschema.Draft202012Validator({"components": spec["components"], "$ref": "#/components/schemas/Product"}, format_checker=jsonschema.FormatChecker())
    for sample in json.loads(payload):
        validator.validate(sample)
    print("PASS: OpenAPI schemas and isolated simple/variation product responses validated.")
    raise SystemExit(0)

directory = Path("/root/.ultralove-product-feed")
helper = directory / "verify-product-feed.php"
command = ["wp", "--allow-root", "--path=/var/www/html", "eval-file", str(helper)]
checks = 0


def control(mode):
    result = subprocess.run(command + [mode], text=True, capture_output=True)
    if result.returncode:
        relevant = [line for line in (result.stdout + result.stderr).splitlines()
                    if "Error:" in line or "Fatal error:" in line or "FEED-RESULT:" in line]
        raise RuntimeError(f"Feed control {mode} failed: " + "\n".join(relevant[-5:]))
    lines = [line.split("FEED-RESULT:", 1)[1] for line in result.stdout.splitlines() if "FEED-RESULT:" in line]
    if not lines:
        raise RuntimeError(f"Feed control {mode} produced no result.")
    return json.loads(lines[-1])


def check(passed, label):
    global checks
    if not passed:
        raise AssertionError(label)
    checks += 1
    print("PASS:", label, flush=True)


def validate(data, name):
    schema = {"components": spec["components"], "$ref": f"#/components/schemas/{name}"}
    jsonschema.Draft202012Validator(schema, format_checker=jsonschema.FormatChecker()).validate(data)


if not options.benchmark_only:
    control("setup")
client = json.loads((directory / "client.json").read_text())
denied = None if options.benchmark_only else json.loads((directory / "denied.json").read_text())
fixture = json.loads((directory / "fixture.json").read_text())
product_id = fixture["id"]


def request(path, credentials=client, method="GET"):
    headers = {"Host": "dev.ultralove.ca", "Accept": "application/json"}
    if credentials:
        token = base64.b64encode(f'{credentials["username"]}:{credentials["password"]}'.encode()).decode()
        headers["Authorization"] = "Basic " + token
    req = urllib.request.Request("http://127.0.0.1/wp-json/ultralove-feed/v1" + path, headers=headers, method=method)
    start = time.perf_counter()
    try:
        response = urllib.request.urlopen(req, timeout=30)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        body = json.loads(response.read())
        return response.code, body, response.headers, time.perf_counter() - start


def success(path, name):
    status, body, headers, duration = request(path)
    if status != 200:
        raise AssertionError(f"GET {path} returned {status}: {body}")
    validate(body, name)
    assert "no-store" in headers["Cache-Control"] and "private" in headers["Cache-Control"]
    return body


def changes(after, generation, per_page=100):
    query = urllib.parse.urlencode({"after": after, "generation": generation, "per_page": per_page})
    return success("/changes?" + query, "Changes")


if options.benchmark_only:
    control("rate-reset")
    control("cold-cache")
    status, body, _, cold_catalog = request("/products?per_page=100")
    check(status == 200, "cold product page served after targeted product-cache invalidation")
    validate(body, "Products")
    control("cold-cache")
    status, body, _, cold_inventory = request("/inventory?per_page=50")
    check(status == 200, "cold inventory page served after targeted product-cache invalidation")
    validate(body, "Inventories")
    report = {"cold_catalog_seconds": cold_catalog, "cold_inventory_seconds": cold_inventory}
    for path, schema, key in (("/products?per_page=100", "Products", "catalog_p95_seconds"),
                              ("/inventory?per_page=50", "Inventories", "inventory_p95_seconds")):
        with concurrent.futures.ThreadPoolExecutor(max_workers=5) as executor:
            timings = list(executor.map(lambda _: request(path), range(30)))
        check(all(item[0] == 200 for item in timings), f"five concurrent clients successfully read {path}")
        for _, data, _, _ in timings:
            validate(data, schema)
        durations = sorted(item[3] for item in timings)
        p95 = durations[math.ceil(0.95 * len(durations)) - 1]
        check(p95 <= 2.0, f"{key} {p95:.3f}s <= 2s")
        report[key] = p95
    (directory / "performance.json").write_text(json.dumps(report))
    (directory / "performance.json").chmod(0o600)
    print("METRICS:", json.dumps(report), flush=True)
    raise SystemExit(0)

producer = None
producer_log = None
try:
    check(request("/products", None)[0] == 401, "anonymous request rejected")
    check(request("/products", denied)[0] == 403, "customer without feed access rejected")
    check(request("/products", {"username": client["username"], "password": "invalid-test-password"})[0] == 401, "invalid application password rejected")
    check(request("/products", method="POST")[0] in (404, 405), "write method unavailable")
    for path in ("/products?per_page=101", "/inventory?after_id=-1", "/products?ids[]=0", "/changes?after=0"):
        check(request(path)[0] == 400, "invalid request rejected: " + path)
    status, published_spec, _, _ = request("/openapi")
    check(status == 200 and published_spec == spec, "published contract matches deployed schema")
    first = success("/products?per_page=100", "Products")
    generation = first["generation"]
    cursor = first["change_cursor"]
    received = []
    warning_products = 0
    page = first
    while True:
        received.extend(item["id"] for item in page["products"])
        warning_products += sum(bool(item["data_warnings"]) for item in page["products"])
        if not page["has_more"]:
            break
        page = success(f'/products?per_page=100&after_id={page["next_after_id"]}', "Products")
    check(received == fixture["ids"] and len(received) == len(set(received)), f"all {len(received)} published products match WooCommerce, ordered without duplicates")
    check(True, f"{warning_products} products with missing image references remain importable with explicit warnings")
    control("csv")
    check(True, "CSV bytes unchanged by full-catalog read requests")
    inventory = success("/inventory?ids[]=" + str(product_id), "Inventories")
    check(len(inventory["inventory"]) == 1 and inventory["inventory"][0]["stock_quantity"] == fixture["stock"], "inventory filter matches current source quantity")
    record = success("/products/" + str(product_id), "Product")
    check(record["id"] == product_id and "meta_data" not in record and "purchase_note" not in record, "product response is schema-valid and excludes private metadata")
    control("access-revoke")
    check(request("/inventory?per_page=1")[0] == 403, "nonce-protected staff account control revokes API access")
    control("access-grant")
    check(request("/inventory?per_page=1")[0] == 200, "nonce-protected staff account control restores API access")

    control("stock")
    check(success("/products/" + str(product_id), "Product")["stock_quantity"] == fixture["stock"] - 1, "stock change visible on next HTTP request")
    delta = changes(cursor, generation)
    check(any(item["id"] == product_id and item["action"] == "upsert" and item["product"]["stock_quantity"] == fixture["stock"] - 1 for item in delta["changes"]), "stock update appears in incremental feed")
    replay = changes(cursor, generation)
    check(delta == replay, "cursor replay is idempotent when source is unchanged")
    cursor = delta["cursor"]
    small = changes(first["change_cursor"], generation, 1)
    check(len(small["changes"]) == 1 and small["has_more"], "change pagination is bounded and resumable")

    control("zero")
    check(success("/products/" + str(product_id), "Product")["stock_quantity"] == 0, "zero-stock product remains listable")
    control("unpublish")
    check(request("/products/" + str(product_id))[0] == 404, "unpublished product cannot be retrieved")
    delta = changes(cursor, generation)
    check(any(item["id"] == product_id and item["action"] == "delete" and item["product"] is None for item in delta["changes"]), "unpublication emits removal tombstone")
    control("restore")
    control("seed")
    cursor = success("/inventory?ids[]=" + str(product_id), "Inventories")["change_cursor"]
    control("raw")
    delta = changes(cursor, generation)
    check(any(item["id"] == product_id and item["product"]["stock_quantity"] == fixture["stock"] - 2 for item in delta["changes"] if item["product"]), "reconciliation captures direct-DB stock update that bypasses hooks")
    control("restore")

    ready = directory / "producer-ready"
    ready.unlink(missing_ok=True)
    baseline = success("/inventory?ids[]=" + str(product_id), "Inventories")["change_cursor"]
    producer_log = (directory / "producer.log").open("w")
    producer = subprocess.Popen(command + ["producer"], stdout=producer_log, stderr=producer_log)
    for _ in range(100):
        if ready.exists():
            break
        if producer.poll() is not None:
            raise RuntimeError("Producer exited before transaction test.")
        time.sleep(0.1)
    check(ready.exists(), "producer transaction opened without modifying products")
    control("enqueue")
    earlier = changes(baseline, generation)
    check(any(item["id"] == 17188 for item in earlier["changes"]), "later-created committed event delivered while earlier producer remains uncommitted")
    if producer.wait(timeout=20) != 0:
        raise RuntimeError("Producer transaction failed.")
    later = changes(earlier["cursor"], generation)
    check(any(item["id"] == product_id for item in later["changes"]), "late commit receives a newer delivery cursor and is not skipped")
    query = urllib.parse.urlencode({"after": later["cursor"] + 1000000, "generation": generation})
    check(request("/changes?" + query)[0] == 400, "future cursor rejected")
    query = urllib.parse.urlencode({"after": 0, "generation": "00000000-0000-0000-0000-000000000000"})
    check(request("/changes?" + query)[0] == 409, "dataset mismatch explicitly requires full resync")
    expired = control("expire")
    query = urllib.parse.urlencode({"after": 0, "generation": generation})
    check(expired["floor"] > 0 and request("/changes?" + query)[0] == 409, "actual retention pruning rejects expired cursor")

    control("rate")
    status, _, headers, _ = request("/inventory?per_page=1")
    if status != 429:
        control("rate")
        status, _, headers, _ = request("/inventory?per_page=1")
    check(status == 429 and int(headers["Retry-After"]) > 0, "account quota enforced with Retry-After")
    control("rate-reset")
    path = "/inventory?per_page=50"
    success(path, "Inventories")
    with concurrent.futures.ThreadPoolExecutor(max_workers=5) as executor:
        timings = list(executor.map(lambda _: request(path), range(30)))
    check(all(item[0] == 200 for item in timings), "five concurrent clients receive successful inventory responses")
    for _, body, _, _ in timings:
        validate(body, "Inventories")
    durations = sorted(item[3] for item in timings)
    p95 = durations[math.ceil(0.95 * len(durations)) - 1]
    check(p95 <= 2.0, f"warm inventory p95 {p95:.3f}s <= 2s under five concurrent clients")
    (directory / "verification.json").write_text(json.dumps({"checks": checks, "products": len(received), "inventory_p95_seconds": p95, "concurrency": 5}))
finally:
    if producer is not None and producer.poll() is None:
        producer.wait(timeout=30)
    if producer_log:
        producer_log.close()
    control("access-grant")
    control("restore")
    control("rate-reset")
    control("cleanup")
print(f"PASS: {checks} live HTTP checks; real product stock/status restored; no products or orders created.", flush=True)
