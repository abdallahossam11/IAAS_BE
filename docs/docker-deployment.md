# Docker Deployment Guide

This project includes Docker support for easy deployment and testing.

## Prerequisites

- Docker Engine 20+
- Docker Compose v2+

## Setup Steps

1. **Clone the repository**
   ```bash
   git clone <REPO_URL>
   cd IAAS_B.E
   ```

2. **Configure Environment**
   Copy the example environment file:
   ```bash
   cp .env.example .env
   ```
   Edit `.env` to configure your database settings for Docker:
   ```env
   DB_HOST=mysql
   DB_USERNAME=iaas_user
   DB_PASSWORD=your_secure_password
   ```

3. **Build and Start Containers**
   Use Docker Compose to build and start the containers in detached mode:
   ```bash
   docker compose build
   docker compose up -d
   ```

4. **Initialize Application**
   Generate the application key and run database migrations/seeds:
   ```bash
   docker exec -it galala_iaas_app php artisan key:generate
   docker exec -it galala_iaas_app php artisan migrate:fresh --seed
   ```

5. **Verify**
   Check that your containers are running:
   ```bash
   docker compose ps
   ```
   The application should now be available at `http://localhost:8000`.

## Testing

> **Note on Testing**: The production Docker image intentionally installs dependencies with `--no-dev` to reduce image size and improve security. As a result, PHPUnit (`php artisan test`) is **not available** inside the production container.
>
> - Run tests locally (`php artisan test`) or in your CI/CD pipeline **before** building the production image.
> - If you require Docker-based testing, you should create a separate `Dockerfile.dev` that omits the `--no-dev` flag.


## Automated Deployment

You can also use the included `deploy.sh` script to automate the build and optimization steps:

```bash
chmod +x deploy.sh
./deploy.sh
```

## Production Deployment Rules

When deploying to a production server, you MUST follow these security and configuration rules:

1. **Do not commit `.env`** to version control.
2. **Do not use weak default passwords** in production.
3. **Generate a new `APP_KEY`** before production deployment.
4. **Use HTTPS domains** for `APP_URL` and `FRONTEND_URL`.
5. Run migrations with the `--force` flag in production: `php artisan migrate --force`.
6. Only run database seeding when `ADMIN_PASSWORD` is explicitly set in `.env`.

### Required Production Environment Variables

Your production `.env` file MUST define the following variables:

```env
APP_ENV=production
APP_DEBUG=false
APP_KEY=your_base64_generated_key_here
APP_URL=https://your-backend-domain.com

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=iaas_db
DB_USERNAME=iaas_user
DB_PASSWORD=strong_database_password
DB_ROOT_PASSWORD=strong_root_database_password

ADMIN_PASSWORD=strong_super_admin_password
GATE_API_KEY=strong_gate_api_key

SANCTUM_STATEFUL_DOMAINS=your-frontend-domain.com
FRONTEND_URL=https://your-frontend-domain.com
```

### CORS Configuration

If your frontend uses browser requests from a different domain, you must configure CORS properly:
- The frontend domain MUST be allowed by CORS.
- The `FRONTEND_URL` in `.env` determines the primary allowed origin in production.
- Make sure API clients send `Accept: application/json` and `Authorization: Bearer TOKEN` (for protected endpoints).
- Gate API clients must send `X-GATE-API-KEY`.
