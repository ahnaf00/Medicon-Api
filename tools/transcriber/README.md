# MediCon transcriber agent

A small Python worker that joins every video consultation room on the local
LiveKit server and records the conversation **only while both the doctor and
the patient have agreed**. It writes each person's microphone to separate FLAC
files and uploads them to the Laravel API, which transcribes them (task 6.6).

Local development and thesis demonstration only (see IMPLEMENTATION_PLAN.md,
Phase 6.11, for what production would still need).

## What it does

| Situation | Agent behaviour |
|---|---|
| A room named `appointment-{id}` is created | Joins as a hidden-from-UI *agent* participant, subscribes to audio only, publishes nothing |
| Any other room | Rejects the job |
| Doctor **and** patient present, both `consent=true` | Records each one's microphone to its own file |
| Either declines, withdraws, or leaves | Closes the open files immediately; audio arriving afterwards is dropped |
| A microphone is muted / its track is republished | Closes that speaker's file; a new one starts when audio resumes |
| A file reaches 5 minutes | Closes it and starts the next one |
| A file closes | Uploads it (3 retries with backoff on network/5xx/429 errors); deletes it **only after a 2xx** |
| The room closes (doctor ended the call, or everyone left) | Closes files, finishes uploads, then calls `/complete` |

The apps' **● Recording** badge is driven only by this agent's own `recording`
attribute, which is `true` only while a file is actually open and being written.

`role` and `consent` are participant attributes set by the Laravel API (in the
join token and through RoomService); phones cannot change them, so a client
cannot fake consent.

Files are `storage/{room}/{identity}-{started_at_ms}.flac`: 16 kHz, mono,
16-bit FLAC. `started_at_ms` is the offset of the file's first sample from the
moment the agent joined the room; each upload also carries the agent's
`session_started_at` (UTC) to tell separate room sessions apart.

## Setup (once)

Requires Python 3.14 (verified in the 6.0 spike) and the LiveKit server from the
Phase 6 runbook (`Medicon-api/README.md`).

```powershell
cd Medicon-api\tools\transcriber
py -3.14 -m venv .venv
.venv\Scripts\activate
pip install -r requirements.txt          # requirements-dev.txt adds pytest
copy .env.example .env                   # then fill in the values below
```

`.env`:

| Key | Value |
|---|---|
| `LIVEKIT_URL` | `ws://127.0.0.1:7880` (the agent runs on the same PC as LiveKit) |
| `LIVEKIT_API_KEY` / `LIVEKIT_API_SECRET` | The same pair as `Medicon-api/.env` and the server's `--keys` |
| `API_BASE_URL` | `http://127.0.0.1:8000/api/v1` |
| `TRANSCRIBER_SECRET` | The same 32+ character value as `Medicon-api/.env` |

## Run

```powershell
cd Medicon-api\tools\transcriber
.venv\Scripts\activate
python agent.py dev        # development (reloads on code changes)
# or: python agent.py start
```

Look for `registered worker` in the output. Then start a video consultation
in the app; the log shows `joined appointment-{id}`, and once both people have
agreed, `chunk opened` / `recording attribute -> True`.

## Uploads that failed

If the API is unreachable or rejects a chunk, the file stays in
`storage/{room}/` and the log names it (`chunk kept on disk after failed
upload`). Nothing is deleted without a successful upload.

What the API answers (task 6.6):

| Answer | Meaning |
|---|---|
| `201` / `200 {"duplicate": true}` | Stored (or already stored by an earlier attempt); the local file is deleted |
| `401` / `503` | Wrong or unconfigured `TRANSCRIBER_SECRET` |
| `403` | Not a participant, or both had not consented **while the chunk was recorded** (checked against the consent log, with `TRANSCRIBER_CONSENT_GRACE_MS`, default 2 s, for the moment it takes the agent to see a change) |
| `409` | The visit is not accepting audio (not started, or transcription already began) |
| `413` / `422` | The chunk is too large or malformed |

PHP's defaults (`upload_max_filesize=2M`, `post_max_size=8M`) reject a
5-minute chunk (about 4-5 MB). In the `php.ini` used by `php artisan serve`
(`php --ini` shows which), set:

```ini
upload_max_filesize = 16M
post_max_size = 20M
```

Transcription runs on the queue, so a worker must be running
(`php artisan queue:work --timeout=600`, or `composer run dev`). After the
doctor ends the call, the agent's `/complete` starts it; if the agent never
reports back, the API starts it anyway after `TRANSCRIBER_FINALIZE_DELAY_SECONDS`
(default 180).

Files that were refused stay in `storage/{room}/`. Delete them by hand if you
do not need them; they contain consultation audio.

## Tests

```powershell
python -m pytest -q
```

Unit tests cover the consent rule, the FLAC writer and the upload/retry rules.

End-to-end check against the real LiveKit server, without phones (uses a stub
API instead of Laravel):

```powershell
# terminal 1: stand-in for the 6.6 endpoints
python dev\stub_api.py --port 8765 --out dev-out\stub
# terminal 2: the agent, pointed at the stub, with 4-second files to exercise rotation
$env:API_BASE_URL="http://127.0.0.1:8765/api/v1"; $env:TRANSCRIBER_CHUNK_SECONDS="4"
$env:TRANSCRIBER_STORAGE_DIR="$PWD\dev-out\agent-storage"; python agent.py start
# terminal 3: a simulated doctor (440 Hz) and patient (660 Hz)
python dev\simulate_call.py --room appointment-99001 --scenario full
python dev\verify_run.py --room appointment-99001
```

`verify_run.py` checks every uploaded file is 16 kHz mono FLAC with the right
duration and the right speaker's tone, and lists the time covered per speaker
(the gap while consent was withdrawn must be empty). `--scenario decline` checks
that nothing is recorded when the patient declines.
