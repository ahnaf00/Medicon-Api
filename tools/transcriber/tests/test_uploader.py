import asyncio
from datetime import UTC, datetime

import httpx

from medicon_transcriber.chunks import ChunkInfo
from medicon_transcriber.uploader import RETRY_DELAYS, Uploader

SECRET = "s" * 40
BASE = "http://api.test/api/v1"


def make_chunk(tmp_path, name="user-1-1000.flac"):
    path = tmp_path / name
    path.write_bytes(b"fLaC fake audio")
    return ChunkInfo(room="appointment-12", identity="user-1", path=path, started_at_ms=1000, duration_ms=2500)


def run_upload(tmp_path, responder, chunk=None):
    """Upload one chunk through a mocked transport; returns (uploader, requests, sleeps)."""
    requests, sleeps = [], []

    def handler(request: httpx.Request) -> httpx.Response:
        requests.append(request)
        return responder(len(requests))

    async def fake_sleep(seconds):
        sleeps.append(seconds)

    async def go():
        client = httpx.AsyncClient(transport=httpx.MockTransport(handler))
        up = Uploader(BASE, SECRET, datetime(2026, 10, 4, 11, 0, tzinfo=UTC), client=client, sleep=fake_sleep)
        up.start()
        up.submit(chunk or make_chunk(tmp_path))
        await up.drain(5)
        await up.aclose()
        return up

    return asyncio.run(go()), requests, sleeps


def test_successful_upload_sends_fields_and_deletes_the_file(tmp_path):
    chunk = make_chunk(tmp_path)
    up, requests, sleeps = run_upload(tmp_path, lambda n: httpx.Response(201), chunk)

    assert len(requests) == 1 and sleeps == []
    req = requests[0]
    assert str(req.url) == f"{BASE}/internal/transcriber/rooms/appointment-12/chunks"
    assert req.headers["X-Transcriber-Secret"] == SECRET
    body = req.read().decode(errors="replace")
    for field, value in {"identity": "user-1", "started_at_ms": "1000", "duration_ms": "2500",
                         "session_started_at": "2026-10-04T11:00:00+00:00"}.items():
        assert f'name="{field}"' in body and value in body
    assert 'name="file"; filename="user-1-1000.flac"' in body and "audio/flac" in body
    assert not chunk.path.exists()
    assert up.failed == []


def test_server_errors_are_retried_with_backoff_then_succeed(tmp_path):
    chunk = make_chunk(tmp_path)
    up, requests, sleeps = run_upload(
        tmp_path, lambda n: httpx.Response(503 if n < 3 else 200), chunk)

    assert len(requests) == 3
    assert sleeps == [RETRY_DELAYS[0], RETRY_DELAYS[1]]
    assert not chunk.path.exists()


def test_gives_up_after_three_retries_and_keeps_the_file(tmp_path):
    chunk = make_chunk(tmp_path)
    up, requests, sleeps = run_upload(tmp_path, lambda n: httpx.Response(500), chunk)

    assert len(requests) == 4
    assert sleeps == list(RETRY_DELAYS)
    assert chunk.path.exists()
    assert up.failed == [chunk]


def test_network_errors_are_retried(tmp_path):
    chunk = make_chunk(tmp_path)

    def responder(n):
        if n < 2:
            raise httpx.ConnectError("refused")
        return httpx.Response(201)

    up, requests, sleeps = run_upload(tmp_path, responder, chunk)
    assert len(requests) == 2 and sleeps == [RETRY_DELAYS[0]]
    assert not chunk.path.exists()


def test_a_refusal_is_not_retried_and_the_file_is_kept(tmp_path):
    chunk = make_chunk(tmp_path)
    up, requests, sleeps = run_upload(tmp_path, lambda n: httpx.Response(403), chunk)

    assert len(requests) == 1 and sleeps == []
    assert chunk.path.exists()
    assert up.failed == [chunk]


def test_complete_reports_the_room_with_the_secret():
    seen = []

    async def go():
        client = httpx.AsyncClient(transport=httpx.MockTransport(
            lambda r: (seen.append(r), httpx.Response(200))[1]))
        up = Uploader(BASE, SECRET, datetime(2026, 10, 4, 11, 0, tzinfo=UTC), client=client)
        ok = await up.complete("appointment-12")
        await up.aclose()
        return ok

    assert asyncio.run(go()) is True
    assert str(seen[0].url) == f"{BASE}/internal/transcriber/rooms/appointment-12/complete"
    assert seen[0].headers["X-Transcriber-Secret"] == SECRET
