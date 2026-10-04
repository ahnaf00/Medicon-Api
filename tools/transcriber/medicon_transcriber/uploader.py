"""Uploads closed chunks to the Laravel API and reports when a room is done.

  POST {API_BASE_URL}/internal/transcriber/rooms/{room}/chunks    (multipart)
  POST {API_BASE_URL}/internal/transcriber/rooms/{room}/complete

Both carry ``X-Transcriber-Secret``. A local file is deleted only after a 2xx;
if every attempt fails it stays on disk so the audio is never lost.
"""

from __future__ import annotations

import asyncio
import logging
from collections.abc import Awaitable, Callable
from datetime import datetime

import httpx

from .chunks import ChunkInfo

logger = logging.getLogger("medicon.transcriber.uploader")

#: Delays before the 1st, 2nd and 3rd retry (so four attempts in total).
RETRY_DELAYS = (1.0, 2.0, 4.0)


def is_retryable(status: int | None) -> bool:
    """Network errors (None), rate limits and server errors may succeed later.

    Other 4xx answers (bad secret, consent refused, validation) will not.
    """
    return status is None or status == 429 or status >= 500


class Uploader:
    def __init__(self, base_url: str, secret: str, session_started_at: datetime,
                 client: httpx.AsyncClient | None = None,
                 sleep: Callable[[float], Awaitable[None]] = asyncio.sleep) -> None:
        self._base_url = base_url
        self._session_started_at = session_started_at.isoformat()
        self._client = client or httpx.AsyncClient(timeout=httpx.Timeout(60.0, connect=5.0))
        self._client.headers["X-Transcriber-Secret"] = secret
        self._client.headers["Accept"] = "application/json"
        self._sleep = sleep
        self._queue: asyncio.Queue[ChunkInfo] = asyncio.Queue()
        self._worker: asyncio.Task[None] | None = None
        self.failed: list[ChunkInfo] = []

    def start(self) -> None:
        if self._worker is None:
            self._worker = asyncio.create_task(self._run(), name="chunk-uploader")

    def submit(self, chunk: ChunkInfo) -> None:
        self._queue.put_nowait(chunk)

    async def drain(self, timeout: float) -> bool:
        """Wait until every submitted chunk has been tried. False on timeout."""
        try:
            await asyncio.wait_for(self._queue.join(), timeout)
            return True
        except TimeoutError:
            logger.error("upload drain timed out; %d chunk(s) left on disk", self._queue.qsize())
            return False

    async def complete(self, room: str) -> bool:
        url = f"{self._base_url}/internal/transcriber/rooms/{room}/complete"
        ok = await self._with_retries(
            f"complete {room}",
            lambda: self._client.post(url, json={"session_started_at": self._session_started_at}),
        )
        return ok

    async def aclose(self) -> None:
        if self._worker is not None:
            self._worker.cancel()
            await asyncio.gather(self._worker, return_exceptions=True)
        await self._client.aclose()

    async def _run(self) -> None:
        while True:
            chunk = await self._queue.get()
            try:
                if await self._upload(chunk):
                    chunk.path.unlink(missing_ok=True)
                else:
                    self.failed.append(chunk)
                    logger.error("chunk kept on disk after failed upload: %s", chunk.path)
            except Exception:  # never let one chunk kill the worker
                self.failed.append(chunk)
                logger.exception("unexpected error uploading %s", chunk.path)
            finally:
                self._queue.task_done()

    async def _upload(self, chunk: ChunkInfo) -> bool:
        url = f"{self._base_url}/internal/transcriber/rooms/{chunk.room}/chunks"
        data = {
            "identity": chunk.identity,
            "started_at_ms": str(chunk.started_at_ms),
            "duration_ms": str(chunk.duration_ms),
            "session_started_at": self._session_started_at,
        }

        def send() -> Awaitable[httpx.Response]:
            # Re-open the file on every attempt so a retry sends the full body.
            content = chunk.path.read_bytes()
            files = {"file": (chunk.path.name, content, "audio/flac")}
            return self._client.post(url, data=data, files=files)

        return await self._with_retries(f"chunk {chunk.path.name}", send)

    async def _with_retries(self, what: str, send: Callable[[], Awaitable[httpx.Response]]) -> bool:
        for attempt in range(len(RETRY_DELAYS) + 1):
            status: int | None = None
            try:
                response = await send()
                status = response.status_code
                if response.is_success:
                    logger.info("%s: uploaded (%d)", what, status)
                    return True
                logger.warning("%s: HTTP %d %s", what, status, response.text[:200])
            except httpx.HTTPError as e:
                logger.warning("%s: %s", what, e.__class__.__name__)

            if not is_retryable(status) or attempt == len(RETRY_DELAYS):
                return False
            await self._sleep(RETRY_DELAYS[attempt])
        return False
