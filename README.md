# Attendance Management System

A full-stack attendance management application. The React frontend provides the Manager interface, while a Laravel API manages authentication, students, centers, rooms, and attendance records.

## Project Structure

- `backend/` - Laravel 12 API, authentication, database models, migrations, and seeders.
- `frontend/` - React 19 single-page application built with Vite.

## Features

- Sign in and sign out using Laravel Sanctum bearer tokens.
- Manage students and their barcodes.
- Manage centers and rooms.
- Record attendance, scan student barcodes, and view attendance reports.
- Use a single Manager account to access and administer the system.

## Requirements

- PHP 8.2 or later and Composer.
- Node.js and npm.
- MySQL/MariaDB (for example, from XAMPP) or another Laravel-supported database.

## Local Setup

### 1. Configure the backend

In PowerShell, install dependencies and create the Laravel environment file:

```powershell
cd backend
composer install
Copy-Item .env.example .env
php artisan key:generate
```

Create a database (for example, `attendance_system`) in MySQL, then set the database values in `backend/.env`:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=attendance_system
DB_USERNAME=root
DB_PASSWORD=
```

Run migrations and configure the initial Manager account. Use a unique email and a password of at least 8 characters:

```dotenv
ADMIN_NAME="System Administrator"
ADMIN_EMAIL=admin@example.com
ADMIN_PASSWORD=replace-with-a-strong-password
```

Then seed the account:

```powershell
php artisan migrate
php artisan db:seed
```

The seeder creates the configured Manager only when no user accounts exist. If that Manager already exists, the seeder leaves all account data unchanged. It will not create another account when a different user already exists. Laravel hashes the password before it is stored. Change the example credentials before using the application beyond local development.

### 2. Start the backend

From `backend/`:

```powershell
php artisan serve --host=127.0.0.1 --port=8000
```

The API is available at `http://127.0.0.1:8000/api`.

### 3. Start the frontend

In a second terminal:

```powershell
cd frontend
npm install
npm run dev
```

Open the Vite URL printed in the terminal (normally `http://localhost:5173`). The frontend currently sends API requests to `http://127.0.0.1:8000/api`, configured in `frontend/src/api.js`.

## Application Pages

- `/login` - sign in.
- `/` - dashboard overview.
- `/attendance` - attendance checklist.
- `/students` - student records.
- `/reports` - attendance reports.

All pages except login require authentication.

## API Overview

All API routes are prefixed with `/api`.

| Method | Endpoint | Purpose |
| --- | --- | --- |
| `POST` | `/login` | Sign in and receive a bearer token |
| `POST` | `/logout` | Revoke the current token |
| `GET`, `POST` | `/rooms` | List and create rooms |
| `GET`, `POST` | `/centers` | List and create centers |
| `GET`, `POST`, `PUT`, `DELETE` | `/students` | List, create, update, and remove students |
| `GET` | `/sync/pull` | Pull change-log events incrementally |
| `POST` | `/sync/push` | Submit idempotent, version-checked sync mutations |
| `POST` | `/attendance/record` | Record attendance |
| `GET` | `/attendance/report` | Retrieve attendance reports |
| `POST` | `/attendance/scan` | Record attendance from a barcode scan |

All endpoints except login require the `Authorization: Bearer <token>` header. See `backend/routes/api.php` for route definitions and request validation in the API controllers.

### Pulling change-log events

`GET /api/sync/pull` requires an authenticated Manager bearer token. Optional query parameters are `cursor` (integer, minimum `0`, default `0`) and `limit` (integer from `1` to `500`, default `100`). Events are returned in ascending sequence-ID order, strictly after the cursor. The response follows the API's `success`/`data` convention:

```json
{
  "success": true,
  "data": {
    "changes": [
      {
        "id": 17,
        "entity_type": "student",
        "entity_uuid": "00000000-0000-4000-8000-000000000001",
        "operation": "updated",
        "entity_version": 2,
        "payload": {
          "id": 1,
          "uuid": "00000000-0000-4000-8000-000000000001",
          "version": 2,
          "name": "Sample Student"
        },
        "created_at": "2026-11-03T14:30:00.000000Z"
      }
    ],
    "next_cursor": 17,
    "has_more": false
  }
}
```

Each change contains the stored sequence ID, entity metadata, complete JSON payload, and creation timestamp. `next_cursor` is the last returned sequence ID; it remains the requested cursor when no events are returned. When `has_more` is true, continue from `next_cursor`.

### Pushing mutations

`POST /api/sync/push` uses the existing Sanctum bearer authentication and Manager middleware as a prototype restriction; device pairing and device authentication are not implemented. Submit between 1 and 100 ordered mutations:

```json
{
  "mutations": [
    {
      "mutation_uuid": "00000000-0000-4000-8000-000000000101",
      "entity_type": "student",
      "operation": "create",
      "entity_uuid": "00000000-0000-4000-8000-000000000102",
      "base_version": 0,
      "data": {
        "name": "Sample Student",
        "parent_phone": "01012345678",
        "grade_level": "prep_1",
        "center_uuid": "00000000-0000-4000-8000-000000000103"
      }
    }
  ]
}
```

Allowed `entity_type` values are `center`, `student`, `room`, and `attendance`; `operation` is `create`, `update`, or `delete`. Creates require `base_version: 0`; updates and deletes require the current server version. Relationships use `center_uuid` and `student_uuid`, never client database IDs. Each mutation is committed independently and the ordered `data.results` array reports `applied` or `rejected` receipts, so one rejected mutation does not prevent later items from being processed. Malformed batch envelopes return validation errors before processing.

The server stores each mutation UUID, a canonical hash of its request, and its receipt in the same transaction as the entity change and change-log events. An identical retry returns the saved receipt without reapplying the mutation. Reusing the mutation UUID with a different payload is rejected. Stale base versions are rejected and their outcome is saved for consistent retries; automatic conflict resolution is not provided. Only explicitly supported entity fields are accepted; IDs, UUID/version overrides, and unsupported fields are rejected.

## Database

Migrations define users, centers, rooms, students, and attendance records. Centers, students, rooms, and attendance records have a stable UUID and an initial version of `1` for synchronization identity. UUID columns remain nullable for SQLite compatibility; the migration backfills existing rows and adds unique indexes, while application model hooks assign UUIDs to new Eloquent records. Attendance bulk upserts assign UUIDs for inserts and preserve existing UUID/version values on updates. Run `php artisan migrate` after changing the database configuration. The system currently supports one Manager account; there is no user-creation API. `php artisan db:seed` creates the configured Manager only when the users table is empty, and leaves an existing Manager unchanged. It stops without changes if another account exists.

## Checks

Backend tests:

```powershell
cd backend
php artisan test
```

Frontend lint and production build:

```powershell
cd frontend
npm run lint
npm run build
```