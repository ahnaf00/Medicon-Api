"""Stand-in for the Laravel transcriber endpoints (until task 6.6 adds them).

    python dev/stub_api.py --port 8765 --out dev-out

Accepts POST .../internal/transcriber/rooms/{room}/chunks and .../complete,
checks X-Transcriber-Secret like the real API will, saves uploaded FLAC files
under --out and appends one JSON line per request to --out/requests.jsonl.
Development and testing only.
"""

from __future__ import annotations

import argparse
import hmac
import json
import os
import re
from email.parser import BytesParser
from email.policy import HTTP
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

from dotenv import load_dotenv

ROUTE = re.compile(r"^/(?:.*/)?internal/transcriber/rooms/(appointment-\d+)/(chunks|complete)$")


def parse_multipart(content_type: str, body: bytes) -> tuple[dict[str, str], dict[str, tuple[str, bytes]]]:
    msg = BytesParser(policy=HTTP).parsebytes(
        b"Content-Type: " + content_type.encode() + b"\r\n\r\n" + body
    )
    fields, files = {}, {}
    for part in msg.iter_parts():
        name = part.get_param("name", header="content-disposition")
        filename = part.get_filename()
        payload = part.get_payload(decode=True) or b""
        if filename:
            files[name] = (filename, payload)
        else:
            fields[name] = payload.decode()
    return fields, files


def make_handler(out: Path, secret: str):
    log_path = out / "requests.jsonl"

    class Handler(BaseHTTPRequestHandler):
        def do_POST(self) -> None:  # noqa: N802 (http.server API)
            match = ROUTE.match(self.path)
            body = self.rfile.read(int(self.headers.get("Content-Length") or 0))
            if not match:
                return self._reply(404, {"message": "not found"})
            if not hmac.compare_digest(self.headers.get("X-Transcriber-Secret", ""), secret):
                return self._reply(403, {"message": "bad secret"})

            room, action = match.groups()
            record: dict = {"room": room, "action": action}
            if action == "chunks":
                fields, files = parse_multipart(self.headers["Content-Type"], body)
                filename, data = files["file"]
                target = out / room / filename
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(data)
                record |= fields | {"filename": filename, "bytes": len(data)}
            else:
                record |= json.loads(body or b"{}")

            with log_path.open("a", encoding="utf-8") as f:
                f.write(json.dumps(record) + "\n")
            self._reply(201 if action == "chunks" else 200, {"ok": True})

        def _reply(self, status: int, payload: dict) -> None:
            data = json.dumps(payload).encode()
            self.send_response(status)
            self.send_header("Content-Type", "application/json")
            self.send_header("Content-Length", str(len(data)))
            self.end_headers()
            self.wfile.write(data)

        def log_message(self, fmt: str, *args) -> None:
            print("stub:", fmt % args, flush=True)

    return Handler


def main() -> None:
    load_dotenv(Path(__file__).resolve().parent.parent / ".env")
    parser = argparse.ArgumentParser()
    parser.add_argument("--port", type=int, default=8765)
    parser.add_argument("--out", type=Path, default=Path("dev-out"))
    args = parser.parse_args()

    secret = os.environ.get("TRANSCRIBER_SECRET", "")
    if not secret:
        raise SystemExit("TRANSCRIBER_SECRET is not set")
    args.out.mkdir(parents=True, exist_ok=True)
    server = ThreadingHTTPServer(("127.0.0.1", args.port), make_handler(args.out, secret))
    print(f"stub API on http://127.0.0.1:{args.port}/api/v1 -> {args.out}", flush=True)
    server.serve_forever()


if __name__ == "__main__":
    main()
