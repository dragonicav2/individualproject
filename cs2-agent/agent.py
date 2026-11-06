#!/usr/bin/env python3
"""Single-match CS2 server agent for a trusted private LAN prototype."""
import json
import os
import re
import signal
import subprocess
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.request import Request, urlopen

TOKEN = os.environ["CS2_AGENT_TOKEN"]
CS2_DIR = Path(os.environ["CS2_DIR"]).resolve()
HOST = os.environ["CS2_SERVER_HOST"]
CALLBACK_BASE = os.environ.get("CS2_CALLBACK_BASE", "").rstrip("/")
CALLBACK_TOKEN = os.environ.get("CS2_CALLBACK_TOKEN", TOKEN)
STREAM_BASE = os.environ.get("CS2_STREAM_BASE", "").rstrip("/")
GSLT = os.environ.get("CS2_GSLT", "")
PORT = int(os.environ.get("CS2_AGENT_PORT", "8787"))
GAME_PORT = int(os.environ.get("CS2_GAME_PORT", "27015"))
ALLOWED_MAPS = {"de_ancient", "de_anubis", "de_dust2", "de_inferno", "de_mirage", "de_nuke", "de_overpass", "de_vertigo"}
lock = threading.Lock()
active = None


def json_response(handler, code, payload):
    data = json.dumps(payload).encode()
    handler.send_response(code)
    handler.send_header("Content-Type", "application/json")
    handler.send_header("Content-Length", str(len(data)))
    handler.end_headers()
    handler.wfile.write(data)


def upload_demo(match_id, started_at):
    if not CALLBACK_BASE:
        return False
    candidates = [path for path in (CS2_DIR / "game" / "csgo").rglob("*.dem") if path.stat().st_mtime >= started_at - 2]
    if not candidates:
        return False
    latest = max(candidates, key=lambda path: path.stat().st_mtime)
    boundary = "----cs2agent" + str(int(time.time() * 1000))
    body = bytearray()
    body.extend(f"--{boundary}\r\nContent-Disposition: form-data; name=\"demo\"; filename=\"{latest.name}\"\r\nContent-Type: application/octet-stream\r\n\r\n".encode())
    body.extend(latest.read_bytes())
    body.extend(f"\r\n--{boundary}--\r\n".encode())
    request = Request(f"{CALLBACK_BASE}/api/v1/cs2/matches/{match_id}/demo", data=bytes(body), method="POST", headers={"Authorization": f"Bearer {CALLBACK_TOKEN}", "Content-Type": f"multipart/form-data; boundary={boundary}"})
    try:
        with urlopen(request, timeout=90) as response:
            return 200 <= response.status < 300
    except Exception as exc:
        print(f"demo upload failed: {exc}", flush=True)
        return False


class Handler(BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):
        print(fmt % args, flush=True)

    def do_POST(self):
        global active
        if self.headers.get("Authorization", "") != f"Bearer {TOKEN}":
            return json_response(self, 401, {"error": "unauthorized"})
        if self.path == "/v1/servers":
            length = int(self.headers.get("Content-Length", "0"))
            data = json.loads(self.rfile.read(length) or b"{}")
            match_id = str(data.get("external_match_id", ""))
            map_name = str(data.get("map", ""))
            if not match_id.isdigit() or map_name not in ALLOWED_MAPS:
                return json_response(self, 422, {"error": "invalid match id or map"})
            with lock:
                if active and active["process"].poll() is None:
                    if active["id"] == match_id:
                        return json_response(self, 200, active["response"])
                    return json_response(self, 409, {"error": "single server is already running a match"})
                log_path = CS2_DIR / "cs2-agent.log"
                log = log_path.open("ab")
                command = [str(CS2_DIR / "game/bin/linuxsteamrt64/cs2"), "-dedicated", "-console", "-usercon", "-ip", "0.0.0.0", "-port", str(GAME_PORT), "+sv_lan", "1", "+game_type", "0", "+game_mode", "1", "+mapgroup", "mg_active", "+map", map_name, "+tv_enable", "1", "+tv_autorecord", "1", "+tv_delay", "30", "+maxplayers_override", "12"]
                if GSLT:
                    command += ["+sv_setsteamaccount", GSLT]
                process = subprocess.Popen(command, cwd=CS2_DIR, stdin=subprocess.DEVNULL, stdout=log, stderr=subprocess.STDOUT, start_new_session=True)
                response = {"server_id": match_id, "host": HOST, "port": GAME_PORT, "status": "provisioning", "stream_url": f"{STREAM_BASE}/{match_id}/" if STREAM_BASE else None}
                active = {"id": match_id, "process": process, "response": response, "log": log, "started_at": time.time()}
            time.sleep(2)
            if process.poll() is not None:
                with lock:
                    active["log"].close()
                    active = None
                return json_response(self, 503, {"error": "CS2 process exited during startup; inspect cs2-agent.log"})
            return json_response(self, 201, response)
        match = re.fullmatch(r"/v1/servers/(\d+)/stop", self.path)
        if match:
            match_id = match.group(1)
            with lock:
                if not active or active["id"] != match_id:
                    return json_response(self, 404, {"error": "match server not found"})
                process = active["process"]
                started_at = active["started_at"]
                process.send_signal(signal.SIGTERM)
            try:
                process.wait(timeout=30)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait(timeout=10)
            with lock:
                active["log"].close()
                active = None
            uploaded = upload_demo(match_id, started_at)
            return json_response(self, 200, {"status": "stopped", "demo_received": uploaded})
        return json_response(self, 404, {"error": "not found"})

    def do_GET(self):
        if self.headers.get("Authorization", "") != f"Bearer {TOKEN}":
            return json_response(self, 401, {"error": "unauthorized"})
        if self.path == "/health":
            return json_response(self, 200, {"ok": True, "cs2_dir_exists": CS2_DIR.exists(), "server_running": bool(active and active["process"].poll() is None)})
        match = re.fullmatch(r"/v1/servers/(\d+)", self.path)
        if match:
            if not active or active["id"] != match.group(1) or active["process"].poll() is not None:
                return json_response(self, 200, {"status": "failed"})
            response = dict(active["response"])
            response["status"] = "ready" if time.time() - active["started_at"] >= 15 else "provisioning"
            return json_response(self, 200, response)
        return json_response(self, 404, {"error": "not found"})


if __name__ == "__main__":
    if not (CS2_DIR / "game/bin/linuxsteamrt64/cs2").is_file():
        raise SystemExit("CS2_DIR must point to the installed Linux CS2 dedicated server")
    server = ThreadingHTTPServer(("0.0.0.0", PORT), Handler)
    print(f"CS2 private-LAN agent listening on :{PORT}", flush=True)
    server.serve_forever()
