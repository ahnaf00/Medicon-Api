<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## Running video consultations locally

The in-app video consultation (Phase 6) runs entirely on one Windows PC on the
same Wi-Fi as the test phones: Laravel, a queue worker, a LiveKit server in
Docker, and the Python transcriber agent. Local network only; see
`IMPLEMENTATION_PLAN.md` 6.11 for what production would still need.

How a recorded call flows:

1. The doctor starts the call; both people choose whether to allow recording.
2. While **both** have agreed, the transcriber agent records each microphone to
   its own file and uploads it (`/api/v1/internal/transcriber/*`). Every upload is
   checked against the consent log for the time it was recorded.
3. When the doctor ends the call, transcription starts on the queue (Gemini):
   one speaker-labelled transcript, then an AI draft of the summary.
4. The doctor reviews the transcript, uses or edits the draft, and saves it.
5. Only then can the patient read the transcript, and the consultation AI chat
   answers from it ("What was discussed between us?").

### One-time setup

1. **LAN IP.** Find this PC's Wi-Fi address with `ipconfig` (e.g. `192.168.0.104`).
2. **Firewall.** Allow inbound TCP 8000 for `php artisan serve` in Windows Firewall.
   LiveKit's 7880-7882 are normally admitted by Docker Desktop's own rule; add
   explicit rules only if that rule is missing.
3. **PHP upload limits.** A 5-minute audio chunk is about 4-5 MB, above PHP's
   defaults (2M / 8M). In the `php.ini` that `php --ini` reports, set:

   ```ini
   upload_max_filesize = 16M
   post_max_size = 20M
   ```

4. **`.env`** (see `.env.example` for the comments):

   | Key | Value |
   |---|---|
   | `QUEUE_CONNECTION` | `database` |
   | `DB_QUEUE_RETRY_AFTER` | `900` (must exceed the 600 s transcription job) |
   | `GEMINI_API_KEY` | your key (transcription and summaries use Gemini) |
   | `LIVEKIT_URL` | `ws://<LAN-IP>:7880` (what the phones connect to) |
   | `LIVEKIT_HTTP_URL` | `http://127.0.0.1:7880` |
   | `LIVEKIT_API_KEY` / `LIVEKIT_API_SECRET` | `devkey` / a random string of 32+ characters |
   | `TRANSCRIBER_SECRET` | another random string of 32+ characters |

5. **Transcriber agent.** Follow `tools/transcriber/README.md` (Python venv and
   its `.env`, using the same LiveKit pair and `TRANSCRIBER_SECRET`).
6. **Test accounts.** `php artisan migrate --seed`; make sure the doctor is
   verified (`php artisan doctor:verify <id>`).

### Every session (four terminals)

```powershell
# 1. LiveKit (same secret as LIVEKIT_API_SECRET)
docker run --rm -p 7880:7880 -p 7881:7881 -p 7882:7882/udp livekit/livekit-server `
  --dev --bind 0.0.0.0 --node-ip <LAN-IP> --keys "devkey: <LIVEKIT_API_SECRET>"

# 2. API, reachable from the phones
php artisan serve --host=0.0.0.0 --port=8000

# 3. Queue worker (transcription needs the long timeout)
php artisan queue:work --tries=1 --timeout=600

# 4. Transcriber agent
cd tools\transcriber; .venv\Scripts\activate; python agent.py dev
```

`composer run dev` starts the API and a suitable queue worker together
(`queue:listen --tries=1 --timeout=600`); LiveKit and the agent still need their
own terminals.

### The app

Set `EXPO_PUBLIC_API_URL=http://<LAN-IP>:8000/api/v1` for the build (the EAS
`development` profile's `10.0.2.2` works only on the Android emulator). Run the
dev build on two Android devices (emulator + phone, or two emulators), logged in
as the seeded doctor and patient. `php artisan demo:video-call` creates a video
appointment between them that can start right away.

### When something is stuck

| Symptom | Check |
|---|---|
| Transcript card stays on "Finishing the call recording…" | The agent finishes its uploads and then reports the room closed. Without the agent, the API finalises on its own after `TRANSCRIBER_FINALIZE_DELAY_SECONDS` (default 180), which needs the queue worker running. |
| "Transcribing…" never ends | The queue worker is not running, or was started without `--timeout=600`. |
| Status "Failed" | The doctor can press Retry; the audio is kept. See `storage/logs/laravel.log`. |
| "Not recorded" | Both people must agree before or during the call; audio from before both agreed is never kept. |
| Agent logs `HTTP 413`, or `422` on `file` | The `php.ini` upload limits above were not applied (restart `php artisan serve`). |
| Agent logs `HTTP 401` / `503` | `TRANSCRIBER_SECRET` differs between the two `.env` files, or is shorter than 32 characters. |

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
