# Local Guacamole test

This Compose stack runs Guacamole on the development PC for a one-station test. The web UI is bound to `127.0.0.1:8080`; the VNC target is the BlueStacks laptop at `192.168.0.32:5900`.

## Start

From PowerShell in this directory:

```powershell
docker compose up -d
docker compose ps
```

Open `http://localhost:8080/guacamole/`. The initial database account is `guacadmin` / `guacadmin`; change its password immediately after logging in.

Create a VNC connection in Guacamole with hostname `192.168.0.32`, port `5900`, and the VNC password configured on the laptop. Keep the service local while testing; this setup is not intended to be exposed to the internet.

## Organizer one-click access

The Laravel organizer action requests a short-lived Guacamole API token on the server and opens the configured connection directly. Do not put the Guacamole admin credentials in Laravel's `.env`.

In Guacamole, create a dedicated user such as `mlbb_station` and grant it **READ** permission only on the BlueStacks connection. Set its credentials in the Laravel project's `.env` using `GUACAMOLE_SERVICE_USERNAME` and `GUACAMOLE_SERVICE_PASSWORD`. The default local client ID in that file is the BlueStacks connection ID from the Guacamole URL. Keep `GUACAMOLE_API_URL` as the address Laravel can call and `GUACAMOLE_CLIENT_URL` as the address the organizer's browser can open.

After changing Laravel `.env`, run `php artisan config:clear` and restart the Laravel development server. If the Guacamole service account has no permission to the configured connection, token creation may succeed but opening the session will fail; grant only READ access to that one connection.

## Stop

```powershell
docker compose down
```

To also erase the local Guacamole account database and configuration, use `docker compose down --volumes`.
