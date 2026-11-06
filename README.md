# Bracket — Tournament operations

Laravel API + Vue 3 organizer dashboard. The current starter implements tournament creation/listing, hashed team invitation codes, and a CS2 server-provisioning boundary. The Vue dashboard includes interactive tournament draft creation and a CS2 match-server operations panel.

## Local setup

Requirements: PHP 8.2+, Composer, Node 20+, and SQLite for the local starter.

1. Install PHP and JavaScript dependencies: `composer install` and `npm install`.
2. Copy `.env.example` to `.env`, then generate the Laravel key with `php artisan key:generate`.
3. Create the local SQLite database file at `database/database.sqlite`.
4. Run migrations with `php artisan migrate`.
5. Start a queue worker with `php artisan queue:work`; start Vite with `npm run dev`; start the Laravel app with `php artisan serve`.

Run the match-result regression scenarios with `composer test` (or `php tests/run.php`). They use an in-memory SQLite database and leave the local development data untouched.

To authenticate the API, use Laravel Sanctum personal access tokens. The Vue workspace has separate overview, tournament, team, live-match, and analytics pages. Sign-in and registration are split into dedicated Vue form components.

The browser routes are `/login`, `/register`, and `/` (the signed-in workspace). Laravel serves the Vue entry point at each auth URL so direct navigation and refresh work. Unauthenticated visits to `/` go to `/login`; authenticated visits to an auth page go to the workspace.

## CS2 private-LAN server prototype

This prototype runs one CS2 match server at a time on a Linux machine that is reachable from the Laravel machine over a private LAN. It is intended for LAN testing only; it has no public-IP discovery, NAT traversal, DDoS protection, multi-server scheduling, or production-grade process isolation. Never expose the agent port to the public internet.

### Linux server setup

1. Install Ubuntu Server, Docker Compose, Python 3, and SteamCMD on the Linux machine. Create a private-LAN IP reservation for it (for example `192.168.1.50`). Copy the `cs2-agent` folder to that host.
2. Install CS2 with SteamCMD: `steamcmd +login anonymous +app_update 730 validate +quit`. Set `CS2_DIR` to the directory containing `game/bin/linuxsteamrt64/cs2`.
3. On the Laravel machine, run `php artisan serve --host 0.0.0.0 --port 8000` so the Linux machine can upload demos. Use the Laravel machine's private IP for `CS2_CALLBACK_URL` and allow inbound TCP 8000 on Windows Firewall for the Private profile.
4. Copy `cs2-agent/agent.py` and `cs2-agent/agent.env.example` to Linux as `/opt/cs2-agent/agent.py` and `/opt/cs2-agent/agent.env`. Set one long random shared secret in both `CS2_AGENT_TOKEN` and `CS2_CALLBACK_TOKEN`, then use the same value for `CS2_PROVISIONER_TOKEN` in the Laravel `.env`. Set `CS2_SERVER_HOST` to the Linux machine's private IP and `CS2_CALLBACK_BASE` to the Laravel machine's private IP plus `:8000`.
5. On Laravel, set `CS2_PROVISIONER_URL=http://<linux-private-ip>:8787` and `CS2_CALLBACK_URL=http://<laravel-private-ip>:8000`. Keep these values and the shared token out of Git. Restart Laravel and its queue worker after editing `.env`.
6. Start the agent on Linux with `set -a; . /opt/cs2-agent/agent.env; set +a; python3 /opt/cs2-agent/agent.py`.
7. Start the private-LAN browser-stream relay with `CS2_SERVER_HOST=<linux-private-ip> docker compose -f cs2-agent/mediamtx.compose.yml up -d`. Configure the Linux firewall to allow only your LAN on TCP 8787, UDP 27015 and 27020, TCP 1935/8888/8889, and UDP 8189. The Laravel host must be able to reach 8787 and the browser clients must reach the relay ports.
8. Run `php artisan migrate`, then keep `php artisan queue:work` running. The organizer can provision a match from the tournament page.

The agent enables GOTV and automatic demo recording. When the organizer presses **Stop server**, the agent stops CS2 and uploads the latest `.dem` to Laravel's private storage; authorized match participants and the organizer can download it. Configure PHP `upload_max_filesize` and `post_max_size` above the expected demo size (the current app limit is 100 MB).

For browser video, run OBS on a machine that can view the match as a CS2 spectator. Set OBS's custom streaming server to `rtmp://<linux-private-ip>:1935/<match-id>` and start streaming. The tournament page opens MediaMTX's built-in WebRTC player for that match at `http://<linux-private-ip>:8889/<match-id>/`. This streams the observer's video capture; the headless dedicated server itself does not render a video feed. All viewers need LAN access to the Linux machine. These test streams are unauthenticated on the LAN; do not expose them publicly.

The Linux agent accepts only a single active match and the configured CS2 map allowlist. It has no automatic match-end detection yet: demo finalization happens when the organizer stops the server. The current tournament result flow remains separate from server telemetry.

The organizer provisions from the CS2 match card. The queue worker starts the agent request; refresh the match server panel after startup. Players in the match can see the private connect address and browser stream. The organizer stops the server from **View match details**, which finalizes and uploads its latest demo. Laravel stores demos privately and streams them only to the organizer and participating entrants.

## Current API endpoints

- `GET /api/v1/tournaments` — public published/live listing.
- `GET /api/v1/tournaments/{id}` — tournament detail.
- `POST /api/v1/auth/register`, `POST /api/v1/auth/login`, `GET /api/v1/auth/me`, `POST /api/v1/auth/logout` — Sanctum bearer-token account flow.
- `GET /api/v1/my/tournaments` — tournaments owned by the signed-in organizer.
- `GET /api/v1/analytics/overview` — verified-match totals, per-game ELO tables, and game-specific performance summaries.
- `GET /api/v1/my/teams` — teams the signed-in user belongs to, including member count and their role.
- `GET /api/v1/tournaments/{id}/entries` — organizer-only entrant/check-in list.
- `POST /api/v1/tournaments/{id}/auto-seed` — organizer applies descending historical ELO seeds while registration is open. Ratings are isolated by game and use team ELO for 5v5, player ELO for 1v1; entrants without a game history start at 1000. Manual seed edits remain available.
- `PATCH /api/v1/tournaments/{id}/seeding-strategy` — organizer chooses fixed paths, random round reseeding, highest-vs-lowest ELO, or closest-ELO pairings before publishing.
- `POST /api/v1/tournaments` — authenticated organizer draft.
- `POST /api/v1/tournaments/{id}/open-registration` and `POST /api/v1/tournaments/{id}/register` — open an event and enter as a player/team.
- `POST /api/v1/tournament-entries/{id}/check-in` and `POST /api/v1/tournaments/{id}/publish` — check in and generate a single-elimination, double-elimination, or round-robin schedule.
- `PATCH /api/v1/tournament-entries/{id}/seed` — organizer assigns or clears a seed before publishing.
- `GET /api/v1/tournaments/{id}/bracket` — public match schedule and results.
- `GET /api/v1/tournaments/{id}/standings` — round-robin table based on confirmed results.
- Confirmed match rows can store a game-specific `game_stats` snapshot; the current demo generator supplies sample maps, agents, characters, heroes, and player stat lines.
- `POST /api/v1/demo-data/generate` refreshes four live demos with eight entrants each, four completed historical ELO events, a CS2 open-registration seeding lab, and a progressing CS2 double-elimination showcase. The generated history supplies game-specific 1v1/5v5 ratings, while the larger fields produce fuller brackets, standings, and analytics.
- `POST /api/v1/matches/{id}/result`, `/confirm-result`, `/dispute`, and `/resolve-dispute` — report, confirm, dispute, or referee-resolve match scores.
- `POST /api/v1/teams/{id}/invites` — owner creates a single-use or limited-use invite; raw code is returned once.
- `POST /api/v1/teams/join-by-code` — validate invite and join team.
- `POST /api/v1/matches/{id}/cs2-server`, `GET /api/v1/matches/{id}/game-room`, `POST /api/v1/matches/{id}/cs2-server/stop`, and `GET /api/v1/matches/{id}/cs2-demo` — CS2 server lifecycle and participant-authorized demo download.
- `POST /api/v1/matches/{id}/cs2-server` — provision or return an existing match server.
- `GET /api/v1/matches/{id}/cs2-server` — organizer views server state.

The invite code is never stored in plaintext. Tournament drafts support entrant capacity and an optional start time. Registration enforces capacity; entrants can check in or withdraw while registration is open. Team members can register, check in, and report results. Organizers can seed entrants before publishing and resolve disputed scores. Single-elimination brackets advance winners automatically. Double-elimination tournaments track losses, progress entrants through winners and losers lanes, and finish with a grand final. Round-robin schedules distribute fixtures across rounds with standings based on confirmed scores. The team page reloads memberships from the server after sign-in and team changes, so teams persist across sessions. The dashboard shows sample events when no visible events exist; overview analytics and the server panel are still illustrative. Groups-to-playoffs, Swiss, player-authorized server connect details, provider health callbacks, and real CS2 demo/VOD ingestion remain follow-up work.
# Match operations

The Live matches workspace lists upcoming fixtures, pending score confirmations, and disputes belonging to the signed-in user's tournaments. Organizers can set a fixture time; a player or team member can mark their entrant ready. Changing a fixture time clears both readiness marks. Apply the latest database migration with `php artisan migrate` after pulling this change.

Organizers can correct confirmed scores from the tournament match list by entering the corrected score and a reason. Corrections are recorded in the match details. Single-elimination corrections update an unplayed next-round pairing; corrections are blocked after a later match has started. Double-elimination corrections are blocked once downstream matches exist because changing a winner changes the loss bracket.

Organizers can enter or update player-level match statistics from **View match details** on a confirmed fixture. CS2, VALORANT, and MLBB include K/D/A; stats are tied to the match entrant's roster and flow into analytics. Signed-in entrants get a **My matches** tab with only their own fixtures and result actions, while organizers keep the full tournament operations view.
