#!/usr/bin/env python3
"""Verify the actual shared-response function over HTTP, with negotiated compression."""
import gzip
import http.client
import json
from pathlib import Path
import socket
import subprocess
import tempfile
import time

root = Path(__file__).resolve().parents[1]
source = (root / "api/operations.mysql.php").read_text()
start = source.index("function operations_respond(")
end = source.index("function operations_key_allowed", start)
response_function = source[start:end]
expected = {"ok": True, "revision": 7, "values": {"tt30prod": json.dumps([{"id": 1, "name": "Rice Å", "kg": 120}] * 2000, ensure_ascii=False)}, "restrictedKeys": ["tt34ghati"]}
with tempfile.TemporaryDirectory(prefix="tt-response-") as folder:
    fixture_json = json.dumps(expected, ensure_ascii=False)
    Path(folder, "index.php").write_text("<?php\nrequire '" + str(root/"inventory_reconciliation.php") + "';\n" + response_function + "\noperations_respond(json_decode(file_get_contents(__DIR__.'/payload.json'),true));\n")
    Path(folder, "payload.json").write_text(fixture_json)
    with socket.socket() as sock:
        sock.bind(("127.0.0.1", 0))
        port = sock.getsockname()[1]
    process = subprocess.Popen(["php", "-S", f"127.0.0.1:{port}", "-t", folder], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        deadline = time.monotonic() + 10
        while True:
            try:
                with socket.create_connection(("127.0.0.1", port), timeout=.2):
                    break
            except OSError:
                if time.monotonic() > deadline:
                    raise RuntimeError("PHP fixture did not start")
                time.sleep(.05)
        def request(accept):
            connection = http.client.HTTPConnection("127.0.0.1", port, timeout=10)
            connection.request("GET", "/", headers={"Accept-Encoding": accept})
            response = connection.getresponse()
            headers = dict(response.getheaders())
            body = response.read()
            assert response.status == 200
            connection.close()
            return headers, body
        headers, plain = request("identity")
        assert "Content-Encoding" not in headers
        assert json.loads(plain) == expected
        for accept in ["gzip", "br, gzip, deflate", "gzip; q=0.5"]:
            headers, body = request(accept)
            assert headers.get("Content-Encoding") == "gzip", (accept, headers)
            assert "Accept-Encoding" in headers.get("Vary", "")
            assert len(body) < len(plain) / 4
            assert gzip.decompress(body) == plain
        for accept in ["gzip;q=0", "br", "deflate"]:
            headers, body = request(accept)
            assert "Content-Encoding" not in headers, (accept, headers)
            assert body == plain
        print("PASS shared JSON delivery: identity, gzip, quality negotiation, unchanged Unicode and operational values.")
    finally:
        process.terminate()
        process.wait(timeout=10)
