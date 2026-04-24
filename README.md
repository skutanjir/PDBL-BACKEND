# PDBL Backend

REST API backend for the WUDI task management platform. Built with Laravel 12 and PHP 8.2, providing authentication, task management, team collaboration, push notifications, and scheduled reminders.

---

## Table of Contents

- [Features](#features)
- [Tech Stack](#tech-stack)
- [Architecture](#architecture)
- [Project Structure](#project-structure)
- [Requirements](#requirements)
- [Installation](#installation)
- [Environment Variables](#environment-variables)
- [Running the Application](#running-the-application)
- [Docker Deployment](#docker-deployment)
- [API Reference](#api-reference)
  - [Authentication](#1-authentication)
  - [Profile Management](#2-profile-management)
  - [Task Management](#3-task-management)
  - [Team Collaboration](#4-team-collaboration)
  - [Notifications](#5-notifications)
  - [Notification Settings](#6-notification-settings)
- [Database Schema](#database-schema)
- [Middleware](#middleware)
- [Scheduled Commands](#scheduled-commands)
- [Queue Jobs](#queue-jobs)
- [Security](#security)
- [License](#license)

---

## Features

- **JWT Authentication** -- Stateless token-based auth with automatic refresh and idempotent token rotation to handle concurrent requests.
- **Google OAuth** -- Sign in or register using a Google ID token. Existing accounts with matching email are linked automatically; new accounts are provisioned on first login.
- **Email Verification** -- New registrations require email confirmation via a 6-digit OTP before full access is granted. Resend endpoint included.
- **Password Reset via OTP** -- Forgot password flow: request OTP by email, verify the 6-digit code, then submit a new password. OTPs are single-use and expire after 10 minutes.
- **Guest Mode** -- Full task management without an account using device-based identification (X-Device-ID). Guest data migrates automatically on registration or login.
- **Task Management** -- CRUD with deadlines, priority levels (high/medium/low), completion tracking, pagination, and bulk sync for offline-first clients.
- **Team Collaboration** -- Create teams with avatar, deadline, and max member limits. Invite members by email, accept or decline invitations, remove or ban members. Owner-based permission model.
- **Team Task Completion** -- Per-member completion tracking via `completed_by` array. A task is marked complete only when all assigned members have individually checked it.
- **Push Notifications** -- Firebase Cloud Messaging (FCM HTTP v1) with queued delivery via Laravel Jobs. Notifications for team invitations, member removals, and deadline reminders.
- **In-App Notifications** -- Persistent notification records with read/unread state, mark-as-read, and bulk mark-all-as-read.
- **Notification Settings** -- Per-user configuration for reminder days, reminder time, vibration, and remote alert toggles.
- **Profile Management** -- Avatar upload with storage management, password change with current password verification, email update, and display name update.
- **Scheduled Reminders** -- Artisan command that checks task deadlines daily and sends FCM push notifications based on each user's notification settings and timezone.
- **Gzip Compression** -- Automatic response compression for JSON payloads exceeding 1KB.
- **Timezone Sync** -- Automatic timezone capture from client headers (X-Timezone) persisted to user profile.
- **Rate Limiting** -- Separate throttle groups for authentication endpoints and general API traffic.
- **Docker Ready** -- Production Dockerfile with Apache, Supervisor for queue workers, and health check endpoint.

---

## Tech Stack

| Component          | Technology                                       |
|--------------------|--------------------------------------------------|
| Framework          | Laravel 12                                       |
| Language           | PHP 8.2                                          |
| Authentication     | JWT (php-open-source-saver/jwt-auth 2.8)         |
| Database           | PostgreSQL (production) / SQLite (development)   |
| Push Notifications | Firebase Cloud Messaging via kreait/laravel-firebase 6.2 |
| Queue              | Database driver with Supervisor in production    |
| Caching            | Database driver                                  |
| Web Server         | Apache (Docker) / Artisan Serve (development)    |
| Frontend Assets    | Vite + Tailwind CSS (admin panel)                |

---

## Architecture

```
Client Request
    |
    v
[Rate Limiting] --> [ForceGzipResponse] --> [SyncTimezone]
    |
    v
[Controller] --> [Model/Eloquent] --> [PostgreSQL]
    |
    v
[Queue Job] --> [FirebaseService] --> [FCM HTTP v1]
    |
    v
[Scheduled Command] --> [Deadline Check] --> [Push Notification]
```

**Key Design Decisions:**
- Hybrid authentication: JWT tokens for registered users, X-Device-ID headers for guests
- Idempotent token refresh using JTI-based cache to prevent 401 storms from concurrent requests
- Queued push notifications to avoid blocking API responses
- Per-member completion tracking on team tasks using JSON arrays (assigned_emails, completed_by)

---

## Project Structure

```
PDBL-BACKEND/
|-- app/
|   |-- Console/
|   |   |-- Commands/
|   |       |-- CheckTaskDeadlines.php     # Daily deadline check and notification creation
|   |       |-- SendTaskReminders.php      # FCM push reminders based on user settings
|   |-- Http/
|   |   |-- Controllers/
|   |   |   |-- AuthController.php         # Register, login, logout, refresh, FCM token, email check
|   |   |   |-- TodoController.php         # CRUD, toggle member, bulk store
|   |   |   |-- TeamController.php         # CRUD, invite, accept/decline, remove member
|   |   |   |-- ProfileController.php      # Avatar, password, email, name updates
|   |   |   |-- NotificationController.php # List, mark read, mark all read, delete
|   |   |   |-- UserNotificationSettingController.php  # Get/update reminder settings
|   |   |-- Middleware/
|   |       |-- ForceGzipResponse.php      # Gzip compression for JSON responses > 1KB
|   |       |-- SyncTimezone.php           # Auto-save user timezone from X-Timezone header
|   |-- Jobs/
|   |   |-- SendPushNotification.php       # Queued FCM push notification delivery
|   |-- Models/
|   |   |-- User.php                       # Auth, JWT, avatar accessor, relationships
|   |   |-- Todo.php                       # Tasks with deadline, priority, assigned_emails
|   |   |-- Team.php                       # Team with owner, members (pivot), todos
|   |   |-- Notification.php               # In-app notification with type and read state
|   |   |-- UserNotificationSetting.php    # Per-user reminder configuration
|   |-- Providers/
|   |   |-- AppServiceProvider.php
|   |-- Services/
|       |-- FirebaseService.php            # FCM HTTP v1 integration with credential resolution
|-- bootstrap/
|   |-- app.php                            # Middleware registration, exception handling
|-- config/
|   |-- jwt.php                            # JWT configuration (algorithm, TTL, blacklist)
|   |-- database.php, auth.php, queue.php  # Standard Laravel config
|-- database/
|   |-- migrations/                        # 18 migration files (see Database Schema)
|   |-- factories/
|   |-- seeders/
|-- routes/
|   |-- api.php                            # All API route definitions
|   |-- console.php                        # Scheduled command registration
|   |-- web.php                            # Web routes
|-- Dockerfile                             # Production container with Apache + Supervisor
|-- composer.json
```

---

## Requirements

- PHP 8.2 or higher
- Composer 2.x
- PostgreSQL 14+ (production) or SQLite (development)
- Node.js 18+ and npm (for Vite frontend assets)
- Firebase project with service account credentials (for push notifications)

---

## Installation

1. Clone the repository:
   ```bash
   git clone https://github.com/skutanjir/PDBL-BACKEND.git
   cd PDBL-BACKEND
   ```

2. Install PHP dependencies:
   ```bash
   composer install
   ```

3. Configure environment:
   ```bash
   cp .env.example .env
   ```
   Edit `.env` and set your database credentials, JWT secret, and Firebase configuration (see [Environment Variables](#environment-variables)).

> [!IMPORTANT]
> You must add your Firebase Service Account JSON file to `storage/app/`. The filename should match the `FIREBASE_CREDENTIALS` path in your `.env` (e.g., `storage/app/firebase.json`). This file contains sensitive credentials and is excluded from version control.

4. Generate application key and JWT secret:
   ```bash
   php artisan key:generate
   php artisan jwt:secret
   ```

5. Run database migrations:
   ```bash
   php artisan migrate
   ```

6. Create storage symlink (for avatar uploads):
   ```bash
   php artisan storage:link
   ```

7. Install frontend dependencies (optional, for admin panel):
   ```bash
   npm install
   npm run build
   ```

---

## Environment Variables

| Variable               | Description                                | Example                        |
|------------------------|--------------------------------------------|--------------------------------|
| `APP_ENV`              | Application environment                    | `local` / `production`         |
| `APP_KEY`              | Laravel application key                    | (auto-generated)               |
| `APP_URL`              | Base URL of the application                | `https://api.example.com`      |
| `DB_CONNECTION`        | Database driver                            | `pgsql`                        |
| `DB_HOST`              | Database host                              | `127.0.0.1`                    |
| `DB_PORT`              | Database port                              | `5432`                         |
| `DB_DATABASE`          | Database name                              | `pdbl`                         |
| `DB_USERNAME`          | Database user                              | `postgres`                     |
| `DB_PASSWORD`          | Database password                          | (your password)                |
| `JWT_SECRET`           | JWT signing secret                         | (auto-generated via artisan)   |
| `JWT_ALGO`             | JWT signing algorithm                      | `HS256`                        |
| `QUEUE_CONNECTION`     | Queue driver                               | `database`                     |
| `FIREBASE_CREDENTIALS` | Path to Firebase service account JSON      | `storage/app/firebase.json`    |
| `FIREBASE_PROJECT_ID`  | Firebase project identifier                | `your-project-id`              |
| `MAIL_MAILER`          | Mail transport driver                      | `smtp`                         |
| `MAIL_HOST`            | SMTP host                                  | `smtp.gmail.com`               |
| `MAIL_PORT`            | SMTP port                                  | `587`                          |
| `MAIL_USERNAME`        | SMTP username / sender address             | `app@example.com`              |
| `MAIL_PASSWORD`        | SMTP password or app password              | (your password)                |
| `MAIL_FROM_ADDRESS`    | From address used in all outgoing mail     | `app@example.com`              |

---

## Running the Application

**Development** (runs server, queue worker, log tail, and Vite concurrently):
```bash
composer dev
```

**Production:**
```bash
php artisan serve --host=0.0.0.0 --port=8000
php artisan queue:work --tries=3 --timeout=90
```

**Run scheduled commands:**
```bash
php artisan schedule:work
```

---

## Docker Deployment

Build and run:
```bash
docker build -t pdbl-backend .
docker run -p 80:80 --env-file .env pdbl-backend
```

The Docker image includes:
- PHP 8.2 with Apache (mpm_prefork)
- Extensions: pdo_mysql, pdo_pgsql, mbstring, exif, pcntl, bcmath, gd, zip
- Supervisor managing both Apache and queue worker
- Automatic migration on container start

---

## API Reference

Base URL: `/api`

All authenticated endpoints require `Authorization: Bearer <jwt_token>` header.
Guest endpoints accept `X-Device-ID: <uuid>` header as alternative.

### 1. Authentication

| Method | Endpoint                         | Auth     | Description                                             |
|--------|----------------------------------|----------|---------------------------------------------------------|
| POST   | `/register`                      | Public   | Create account. Syncs guest data if X-Device-ID sent    |
| POST   | `/login`                         | Public   | Authenticate and receive JWT token                      |
| POST   | `/auth/google`                   | Public   | Sign in or register with Google ID token                |
| POST   | `/auth/verify-email`             | Public   | Verify new account email with 6-digit OTP               |
| POST   | `/auth/resend-verification`      | Public   | Resend email verification OTP                           |
| POST   | `/auth/forgot-password`          | Public   | Send password reset OTP to email                        |
| POST   | `/auth/verify-otp`               | Public   | Verify password reset OTP and get reset token           |
| POST   | `/auth/reset-password`           | Public   | Submit new password using verified reset token          |
| POST   | `/refresh`                       | Bearer   | Refresh JWT token (idempotent, race-safe)               |
| GET    | `/user`                          | Bearer   | Get authenticated user profile                          |
| POST   | `/logout`                        | Bearer   | Invalidate current token                                |
| POST   | `/auth/register-fcm-token`       | Bearer   | Register Firebase Cloud Messaging device token          |
| GET    | `/users/check-email`             | Bearer   | Check if email exists and get user info                 |

**Register / Login Payload:**
```json
{
  "name": "User Name",
  "email": "user@example.com",
  "password": "password",
  "password_confirmation": "password"
}
```

**Google Login Payload:**
```json
{
  "id_token": "<google_id_token_from_client>"
}
```

**Password Reset Flow:**
1. `POST /auth/forgot-password` with `{ "email": "..." }` -- sends 6-digit OTP via email, expires in 10 minutes.
2. `POST /auth/verify-otp` with `{ "email": "...", "otp": "123456" }` -- returns a one-time `reset_token`.
3. `POST /auth/reset-password` with `{ "email": "...", "reset_token": "...", "password": "...", "password_confirmation": "..." }`.

**Token Refresh:** Send the current (possibly expiring) token in the Authorization header. The endpoint uses JTI-based idempotency caching to safely handle concurrent refresh requests during the blacklist grace period.

### 2. Profile Management

| Method | Endpoint             | Auth   | Description                                     |
|--------|----------------------|--------|-------------------------------------------------|
| POST   | `/profile/avatar`    | Bearer | Upload avatar image (max 2MB, image/* types)    |
| POST   | `/profile/password`  | Bearer | Change password (requires current_password)     |
| POST   | `/profile/email`     | Bearer | Change email (requires current_password)        |
| POST   | `/profile/update`    | Bearer | Update display name                             |

**Avatar Upload:** Multipart form data with `avatar` field. Old avatar is automatically deleted from storage.

### 3. Task Management

| Method | Endpoint                        | Auth       | Description                                      |
|--------|---------------------------------|------------|--------------------------------------------------|
| GET    | `/todos`                        | Hybrid     | List tasks with pagination (default 50 per page) |
| POST   | `/todos`                        | Hybrid     | Create a new task                                |
| GET    | `/todos/{id}`                   | Hybrid     | Get task details                                 |
| PUT    | `/todos/{id}`                   | Hybrid     | Update task attributes                           |
| DELETE | `/todos/{id}`                   | Hybrid     | Delete a task                                    |
| POST   | `/todos/{id}/toggle-member`     | Bearer     | Toggle current user's completion on a team task  |
| POST   | `/todos/bulk`                   | Hybrid     | Bulk create tasks (for offline sync)             |

**Hybrid Auth:** Accepts either `Authorization: Bearer <token>` for registered users or `X-Device-ID: <uuid>` for guests.

**Create Task Payload:**
```json
{
  "judul": "Task Title",
  "deskripsi": "Task description",
  "deadline": "2026-12-31 23:59:59",
  "priority": "high",
  "team_id": null,
  "assigned_emails": ["member@example.com"]
}
```

**Query Parameters for GET `/todos`:**

| Parameter       | Type    | Description                                    |
|-----------------|---------|------------------------------------------------|
| `per_page`      | integer | Items per page (default: 50)                   |
| `assigned_only` | boolean | Only show tasks specifically assigned to user  |

**Toggle Member:** For team tasks, each assigned member checks completion individually. The task `is_completed` field becomes `true` only when all assigned members have checked.

**Bulk Store Payload:**
```json
{
  "tasks": [
    {
      "local_id": "uuid-from-client",
      "judul": "Task Title",
      "deskripsi": "Description",
      "is_completed": false,
      "deadline": "2026-12-31 23:59:59",
      "priority": "medium"
    }
  ]
}
```

### 4. Team Collaboration

| Method | Endpoint                              | Auth   | Description                      |
|--------|---------------------------------------|--------|----------------------------------|
| GET    | `/teams`                              | Bearer | List teams and pending invitations |
| POST   | `/teams`                              | Bearer | Create a new team                |
| GET    | `/teams/{id}`                         | Bearer | Team details with member stats   |
| PUT    | `/teams/{id}`                         | Bearer | Update team name/description     |
| DELETE | `/teams/{id}`                         | Bearer | Delete team (owner only)         |
| POST   | `/teams/{id}/invite`                  | Bearer | Invite user by email             |
| POST   | `/teams/{id}/accept`                  | Bearer | Accept team invitation           |
| POST   | `/teams/{id}/decline`                 | Bearer | Decline team invitation          |
| DELETE | `/teams/{id}/members/{user_id}`       | Bearer | Remove member (owner only)       |

**Team Detail Response** includes per-member progress statistics calculated from assigned tasks and completion state.

**Invite Payload:**
```json
{
  "email": "registered-user@email.com"
}
```

### 5. Notifications

| Method | Endpoint                          | Auth   | Description                    |
|--------|-----------------------------------|--------|--------------------------------|
| GET    | `/notifications`                  | Bearer | List latest 50 notifications   |
| POST   | `/notifications/{id}/read`        | Bearer | Mark single notification read  |
| POST   | `/notifications/read-all`         | Bearer | Mark all notifications read    |
| DELETE | `/notifications/{id}`             | Bearer | Delete a notification          |

**Notification Types:** `invite`, `kick`, `reminder_h1`, `reminder_h2`, `reminder_h3`, `todo_reminder`, `update`

### 6. Notification Settings

| Method | Endpoint                  | Auth   | Description                          |
|--------|---------------------------|--------|--------------------------------------|
| GET    | `/notification-settings`  | Bearer | Get current notification preferences |
| POST   | `/notification-settings`  | Bearer | Update notification preferences      |

**Settings Payload:**
```json
{
  "reminder_days": [0, 1, 2, 3],
  "reminder_time": "09:00",
  "vibration": true,
  "remote_alerts": true
}
```

`reminder_days` values: `0` = on deadline day, `1` = 1 day before, `2` = 2 days before, etc.

---

## Database Schema

### Models and Relationships

```
User (1) ---> (*) Todo
User (1) ---> (*) Notification
User (1) ---> (1) UserNotificationSetting
User (1) ---> (*) PasswordOtp
User (*) <--> (*) Team [pivot: team_user with status]
Team (1) ---> (*) Todo
Team (1) ---> (1) User [owner via created_by]
```

### Migration Timeline

| Migration                                             | Description                                    |
|-------------------------------------------------------|------------------------------------------------|
| `create_users_table`                                  | Base users with email, password                |
| `create_cache_table`                                  | Laravel cache store                            |
| `create_jobs_table`                                   | Queue jobs table                               |
| `create_todos_table`                                  | Tasks with judul, deskripsi, is_completed      |
| `create_personal_access_tokens_table`                 | Sanctum tokens (legacy)                        |
| `add_deadline_and_priority_to_todos_table`            | Deadline datetime, priority enum               |
| `make_user_id_nullable_add_device_id_to_todos_table`  | Guest support via device_id                    |
| `create_teams_table`                                  | Teams with name, created_by                    |
| `create_team_user_table`                              | Pivot table with status (pending/accepted/banned) |
| `add_team_id_to_todos_table`                          | Link tasks to teams                            |
| `add_status_and_description_to_teams_table`           | Team description and status fields             |
| `add_banned_to_team_user_status`                      | Ban status for team members                    |
| `add_avatar_to_users_table`                           | User avatar file path                          |
| `create_notifications_table`                          | In-app notifications                           |
| `add_fcm_token_to_users_table`                        | Firebase device token storage                  |
| `add_assigned_emails_to_todos_table`                  | JSON array of assigned member emails           |
| `add_completed_by_to_todos_table`                     | JSON array tracking per-member completion       |
| `create_user_notification_settings_table`             | Per-user reminder preferences                  |
| `add_timezone_to_users_table`                         | User timezone for localized reminders          |
| `add_google_id_to_users_table`                        | Google OAuth: stores google_id for linked accounts |
| `create_password_otp_table`                           | OTP records for email verification and password reset |
| `add_deadline_to_teams_table`                         | Optional deadline field on team                |
| `change_teams_description_to_text`                    | Widens description column to TEXT              |
| `add_type_to_password_otps_table`                     | OTP type: email_verification or password_reset |
| `add_avatar_to_teams_table`                           | Team avatar image path                         |
| `add_max_members_to_teams_table`                      | Maximum allowed member count per team          |
| `add_performance_indexes_to_todos` (v1, v2)           | Database indexes for query optimization        |
| `add_today_target_to_users_table`                     | Daily task target setting                      |

---

## Middleware

| Middleware            | Scope | Description                                                        |
|-----------------------|-------|--------------------------------------------------------------------|
| `ForceGzipResponse`   | API   | Compresses JSON responses larger than 1KB using gzip level 6       |
| `SyncTimezone`         | API   | Reads X-Timezone header and persists to user profile               |
| `throttle:auth`        | Auth  | Rate limiting on register/login/refresh endpoints                  |
| `throttle:api`         | API   | Rate limiting on general API endpoints                             |
| `throttle:20,1`        | API   | Stricter rate limit (20 requests/minute) on sensitive operations   |

---

## Scheduled Commands

| Command             | Schedule | Description                                                     |
|---------------------|----------|-----------------------------------------------------------------|
| `check:deadlines`  | Daily    | Scans incomplete tasks with deadlines in the next 1-3 days. Creates in-app notification records and sends FCM push notifications to users based on their individual reminder settings and timezone. |

---

## Queue Jobs

| Job                     | Trigger                          | Description                           |
|-------------------------|----------------------------------|---------------------------------------|
| `SendPushNotification`  | Team invite, member removal      | Sends FCM push notification via FirebaseService. Retryable on failure. |

---

## Security

- Passwords hashed with Bcrypt (12 rounds)
- JWT authentication with configurable TTL and blacklist grace period
- Idempotent token refresh prevents concurrent 401 storms
- Rate limiting on all endpoints with stricter limits on auth and profile operations
- Input validation on all request payloads
- Authorization checks on resource ownership (task owner, team owner, team member)
- CORS configuration for cross-origin requests
- Gzip compression with Accept-Encoding negotiation
- Firebase credentials resolved from secure file paths, not environment variables
- Stale FCM token detection and warning logs

---

## License

Proprietary Software. All rights reserved.
