# Galala University IAAS Backend

**Intelligent Academic Advisory System — Laravel API Backend & Filament Admin Dashboard**

## Overview

This is the backend system for Galala University's IAAS platform. It provides a RESTful API for the student-facing frontend and a Filament admin dashboard for university staff.

## Technology Stack

| Technology | Version | Purpose |
|------------|---------|---------|
| Laravel | 12 | PHP framework |
| MySQL | 8.x | Database |
| Laravel Sanctum | 4.x | Student API token authentication |
| Filament | 3.x | Admin dashboard |
| PHP | 8.2+ | Runtime |
| Composer | 2.x | Dependency management |
| Docker | 20+ | Container deployment (optional) |

## Current Scope

### Implemented

- ✅ Student API with Sanctum Bearer token authentication
- ✅ Filament admin dashboard with session-based login
- ✅ Role-based access control (super_admin, academic_admin, vehicle_admin, support_admin)
- ✅ Student profile management
- ✅ Vehicle access request workflow (submit → pending → approved/rejected)
- ✅ Protected root super admin account
- ✅ Docker deployment support

### Not Implemented Yet

- ❌ OTP / email verification
- ❌ Chatbot integration (handled by AI team separately)
- ❌ Support ticket system
- ❌ Bulk student upload
- ❌ Courses, majors, academic year tracking
- ❌ Payment system
- ❌ Staff vehicle permits
- ❌ Guest accounts
- ❌ Student self-registration

See [docs/future-enhancements.md](docs/future-enhancements.md) for details.

---

## Local Setup (Without Docker)

### Prerequisites

- PHP 8.2+
- Composer 2.x
- MySQL (via XAMPP or standalone)

### Steps

```bash
# 1. Clone the repository
git clone <REPO_URL>
cd IAAS_B.E

# 2. Install PHP dependencies
composer install

# 3. Copy environment file
cp .env.example .env

# 4. Generate application key
php artisan key:generate

# 5. Configure database
# Edit .env if needed (defaults are set for XAMPP):
#   DB_CONNECTION=mysql
#   DB_HOST=127.0.0.1
#   DB_PORT=3306
#   DB_DATABASE=iaas_db
#   DB_USERNAME=root
#   DB_PASSWORD=

# 6. Create the database manually
# Open phpMyAdmin at http://localhost/phpmyadmin
# Create a new database named: iaas_db

# 7. Run migrations and seed initial data
php artisan migrate:fresh --seed

# 8. Start the development server
php artisan serve
```

The application will be available at `http://127.0.0.1:8000`.

---

## Docker Setup

### Prerequisites

- Docker Engine 20+
- Docker Compose v2+

### Steps

```bash
# 1. Clone the repository
git clone <REPO_URL>
cd IAAS_B.E

# 2. Copy environment file and configure for Docker
cp .env.example .env
# Edit .env — set:
#   DB_HOST=mysql
#   DB_USERNAME=iaas_user
#   DB_PASSWORD=iaas_password

# 3. Build and start containers
docker compose build
docker compose up -d

# 4. Generate application key
docker exec -it galala_iaas_app php artisan key:generate

# 5. Run migrations and seed
docker exec -it galala_iaas_app php artisan migrate:fresh --seed

# 6. Verify
docker compose ps
```

The application will be available at `http://localhost:8000`.

Full Docker guide: [docs/docker-deployment.md](docs/docker-deployment.md)

---

## Default Credentials

### Admin Dashboard (Filament)

| Field | Value |
|-------|-------|
| URL | http://127.0.0.1:8000/admin |
| Email | `admin@galala.edu.eg` |
| Password | *Set via ADMIN_PASSWORD in .env* |

### Test Student (API)

*Note: The default `StudentSeeder` has been removed for production security.*

To test the student API, you must first create a student account manually from the Filament Admin Dashboard using a Super Admin or Academic Admin account. The login requires the student's **Student ID** and **Password**.

---

## API Endpoints

| Method | Endpoint | Auth |
|--------|----------|------|
| POST | `/api/v1/student/login` | No |
| POST | `/api/v1/student/logout` | Bearer |
| GET | `/api/v1/student/profile` | Bearer |
| GET | `/api/v1/student/vehicle` | Bearer |
| POST | `/api/v1/student/vehicle-requests` | Bearer |
| GET | `/api/v1/student/vehicle-requests/history` | Bearer |
| POST | `/api/v1/gate/vehicle-access/check` | X-GATE-API-KEY |

**Note on Gate API**:
- This endpoint is for gate/OCR/LPR devices only.
- It is not a student endpoint.
- It requires the `X-GATE-API-KEY` header.
- It receives OCR plate text in the `OCR` field.

Example request:

```json
{
  "OCR": "س م ١ ٤ ٦ ٩"
}
```

Full API documentation: [docs/api-documentation.md](docs/api-documentation.md)

Postman collection: [docs/postman/galala-iaas-api.postman_collection.json](docs/postman/galala-iaas-api.postman_collection.json)

---

## Documentation

| Document | Description |
|----------|-------------|
| [API Documentation](docs/api-documentation.md) | Full endpoint reference with examples |
| [Database Schema](docs/database-schema.md) | Table structures and relationships |
| [Filament Admin Guide](docs/filament-admin-guide.md) | Admin dashboard user guide |
| [Business Rules](docs/business-rules.md) | All system business rules |
| [Testing Checklist](docs/testing-checklist.md) | Manual testing procedures |
| [Frontend Integration](docs/frontend-integration.md) | Frontend developer guide |
| [Docker Deployment](docs/docker-deployment.md) | Docker setup and deployment |
| [Future Enhancements](docs/future-enhancements.md) | Planned modules and features |

---

## Security Notes

> **⚠️ Protected Root Admin Account**
>
> The account `admin@galala.edu.eg` is the permanent root super admin.
> - It **must not** be deleted.
> - Its role **must remain** `super_admin`.
> - Its email **must not** be changed.
> - These protections are enforced at both the UI and backend levels.

---

## Project Structure

```
app/
├── Filament/Resources/          # Filament admin resources
│   ├── AdminResource.php
│   ├── FacultyResource.php
│   ├── StudentResource.php
│   └── VehicleRequestResource.php
├── Http/
│   ├── Controllers/Api/V1/Student/  # Student API controllers
│   │   ├── AuthController.php
│   │   ├── ProfileController.php
│   │   └── VehicleController.php
│   └── Middleware/
│       └── EnsureIsStudent.php      # Sanctum token guard
├── Models/
│   ├── Admin.php
│   ├── Faculty.php
│   ├── Student.php
│   └── VehicleRequest.php
├── Policies/
│   ├── AdminPolicy.php
│   ├── FacultyPolicy.php
│   ├── StudentPolicy.php
│   └── VehicleRequestPolicy.php
└── Providers/
    └── AppServiceProvider.php       # Policy registration

database/
├── migrations/
│   ├── create_admins_table
│   ├── create_faculties_table
│   ├── create_students_table
│   └── create_vehicle_requests_table
└── seeders/
    ├── AdminSeeder.php
    ├── FacultySeeder.php
    ├── StudentSeeder.php
    └── DatabaseSeeder.php

docker/                          # Docker configuration
├── entrypoint.sh
├── nginx/default.conf
└── php/php.ini

docs/                            # Project documentation
├── api-documentation.md
├── business-rules.md
├── database-schema.md
├── docker-deployment.md
├── filament-admin-guide.md
├── frontend-integration.md
├── future-enhancements.md
├── testing-checklist.md
└── postman/
    └── galala-iaas-api.postman_collection.json

routes/
├── api.php                      # Student API routes
└── web.php                      # Health check JSON response
```

---

## GitHub Deployment

### First Push

```bash
git init
git checkout -b main
git add .
git status                      # Verify: no .env, no vendor, no node_modules
git commit -m "Initial Galala IAAS backend release"
git remote add origin <REPO_URL>
git push -u origin main
```

### Before Committing — Verify

- ✅ `.env` is **not** staged (in .gitignore)
- ✅ `vendor/` is **not** staged (in .gitignore)
- ✅ `node_modules/` is **not** staged (in .gitignore)
- ✅ `storage/logs/*` is **not** staged (in .gitignore)
- ✅ `bootstrap/cache/*` is **not** staged (in .gitignore)

---

## License

This project is developed for Galala University.
