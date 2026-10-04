"""MediCon consultation transcriber agent (Phase 6.5).

Run (from this folder, with the venv active):
    python agent.py dev      # local development, reloads on code changes
    python agent.py start    # normal run

LiveKit dispatches this worker into every new room; it accepts only
``appointment-*`` rooms. See README.md for the full runbook.
"""

from __future__ import annotations

import logging
from datetime import UTC, datetime
from pathlib import Path

from dotenv import load_dotenv
from livekit.agents import AutoSubscribe, JobContext, JobRequest, WorkerOptions, WorkerPermissions, cli

from medicon_transcriber.config import ROOM_PREFIX, Settings
from medicon_transcriber.session import RoomTranscriber
from medicon_transcriber.uploader import Uploader

BASE_DIR = Path(__file__).resolve().parent
load_dotenv(BASE_DIR / ".env")
SETTINGS = Settings.from_env(BASE_DIR)

logger = logging.getLogger("medicon.transcriber")

AGENT_IDENTITY = "medicon-transcriber"
#: Time allowed after the room closes to finish uploads and report /complete.
SHUTDOWN_TIMEOUT_S = 90.0
UPLOAD_DRAIN_TIMEOUT_S = 60.0


async def request_fnc(req: JobRequest) -> None:
    """Join consultation rooms only."""
    if not req.room.name.startswith(ROOM_PREFIX):
        logger.info("ignoring room %s", req.room.name)
        await req.reject()
        return
    await req.accept(
        name="MediCon transcriber",
        identity=AGENT_IDENTITY,
        # Start with an explicit "not recording" so the apps never have to guess.
        attributes={"recording": "false"},
    )


async def entrypoint(ctx: JobContext) -> None:
    room_name = ctx.room.name
    uploader = Uploader(SETTINGS.api_base_url, SETTINGS.transcriber_secret, datetime.now(UTC))
    uploader.start()

    # Audio only: the agent never needs video and never publishes anything.
    await ctx.connect(auto_subscribe=AutoSubscribe.AUDIO_ONLY)
    transcriber = RoomTranscriber(
        ctx.room, SETTINGS.storage_dir, uploader.submit, SETTINGS.chunk_seconds
    )
    transcriber.attach()
    logger.info("joined %s", room_name)

    async def on_shutdown(reason: str) -> None:
        # The doctor ended the call, or everyone left: close files, flush, report.
        logger.info("room %s closing (%s)", room_name, reason)
        try:
            await transcriber.close()
            await uploader.drain(UPLOAD_DRAIN_TIMEOUT_S)
            if not await uploader.complete(room_name):
                logger.error("could not report %s as complete", room_name)
            if uploader.failed:
                logger.error(
                    "%d chunk(s) were not uploaded and remain in %s",
                    len(uploader.failed), SETTINGS.storage_dir / room_name,
                )
        finally:
            await uploader.aclose()

    ctx.add_shutdown_callback(on_shutdown)


if __name__ == "__main__":
    cli.run_app(
        WorkerOptions(
            entrypoint_fnc=entrypoint,
            request_fnc=request_fnc,
            ws_url=SETTINGS.livekit_url,
            api_key=SETTINGS.livekit_api_key,
            api_secret=SETTINGS.livekit_api_secret,
            permissions=WorkerPermissions(
                can_publish=False,
                can_publish_data=False,
                can_subscribe=True,
                can_update_metadata=True,  # needed for its own "recording" attribute
            ),
            shutdown_process_timeout=SHUTDOWN_TIMEOUT_S,
        )
    )
