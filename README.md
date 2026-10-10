# Agrosync---Agriculture-Eco-Farming

## Run with Docker

The whole system runs with Docker Compose: MySQL, the Laravel API (PHP-FPM behind nginx), the Next.js frontend, and Mailpit to catch outgoing email.

### Start

```bash
cp .env.docker.example .env
# Edit .env and set DB_PASSWORD and DB_ROOT_PASSWORD to long random values.
docker compose up -d --build
```

The first start runs the database migrations and, with `SEED_DEMO_DATA=true`, loads demo data. Seeding takes about a minute.

| Service | URL |
|---|---|
| Frontend | http://localhost:3000 |
| API | http://localhost:8000 (`/up` is the health check) |
| Mail inbox (OTP codes) | http://localhost:8025 |

The demo accounts all use the password `password123`, for example `admin@ams.com.bd`, `abdul.karim@farmer.com`, `vendor@ams.com.bd` and `company@ams.com.bd`. Email OTP codes for login and registration show up in the Mailpit inbox.

### Changing ports or URLs

If port 8000 or 3000 is already in use, change `API_PORT`/`API_URL` or `FRONTEND_PORT`/`FRONTEND_URL` in `.env`. Then rebuild the frontend, because the API URL is compiled into its browser bundle:

```bash
docker compose build frontend && docker compose up -d
```

### Common commands

```bash
docker compose logs -f backend                     # API logs
docker compose exec backend php artisan migrate    # run new migrations
docker compose exec backend php artisan tinker     # Laravel shell
docker compose down                                # stop (data is kept)
docker compose down -v                             # stop and delete the database and storage volumes
```

### Deploying to a server

Change these in `.env` before exposing the stack:

- `APP_ENV=production` and `APP_DEBUG=false`
- `SEED_DEMO_DATA=false` (the demo seeders refuse to run in production)
- `PAYMENTS_MOCK_ENABLED=false`, plus real `SSLCOMMERZ_*` and/or `STRIPE_SECRET_KEY` values
- `API_URL` and `FRONTEND_URL` set to the public HTTPS addresses, then rebuild the frontend
- Real SMTP settings (`MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_SCHEME`)
- Set `APP_KEY` explicitly (`docker compose exec backend php artisan key:generate --show`) so it doesn't depend on the storage volume

Put a TLS-terminating reverse proxy (Caddy, Traefik or nginx) in front of ports 3000 and 8000. The database port is never published.
