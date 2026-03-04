# Todo and Team Collaboration API Backend

A professional RESTful API backend built with Laravel for managing individual tasks and collaborative team environments. This service supports anonymous guest access with device-based tracking and seamless data synchronization upon user registration.

## Core Features

- **Anonymous Guest Support**: Manage tasks without an account using unique device identifiers (X-Device-ID).
- **Authentication System**: Secure registration and login using Laravel Sanctum (Token-based).
- **Data Synchronization**: Automatically transfer guest-created tasks to a permanent account upon signup.
- **Collaborative Teams**: Create teams and invite registered members via email for shared task management.
- **Advanced Task Attributes**: Support for task deadlines, priority levels (High, Medium, Low), and completion status.
- **Stateless Architecture**: High-performance API design optimized for low latency.

## Requirements

- PHP 8.2 or higher
- Composer
- PostgreSQL
- Nginx / Apache / Artisan Serve

## Installation

1. Clone the repository:
   ```bash
   git clone https://github.com/skutanjir/PDBL-BACKEND.git
   cd PDBL-BACKEND
   ```

2. Install dependencies:
   ```bash
   composer install
   ```

3. Configure environment:
   ```bash
   cp .env.example .env
   # Set DB_CONNECTION=pgsql and provide database credentials
   ```

4. Initialize application:
   ```bash
   php artisan key:generate
   php artisan migrate
   ```

## API Documentation

### 1. Authentication

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/register` | Create account and sync guest data. |
| POST | `/api/login` | Authenticate user and receive Bearer Token. |
| GET | `/api/user` | Retrieve authenticated user profile. |
| POST | `/api/logout` | Revoke current access token. |

#### Registration/Login Payload with Sync:
```json
{
  "email": "user@example.com",
  "password": "password",
  "device_id": "optional-uuid-string-to-sync-guest-data"
}
```

---

### 2. Task Management (Todos)

Tasks can be accessed via `Authorization: Bearer <token>` or `X-Device-ID: <uuid>`.

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/api/todos` | Optional | List all personal and team tasks. |
| POST | `/api/todos` | Optional | Create a new task. |
| GET | `/api/todos/{id}` | Optional | Retrieve task details. |
| PUT | `/api/todos/{id}` | Optional | Update task status or attributes. |
| DELETE | `/api/todos/{id}` | Optional | Remove a task. |

#### Create Task Payload:
```json
{
  "judul": "Task Title",
  "deskripsi": "Task Description",
  "deadline": "2026-12-31 23:59:59",
  "priority": "high",
  "team_id": null
}
```

---

### 3. Team Collaboration

Requires `Authorization: Bearer <token>`.

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/teams` | List teams owned or joined. |
| POST | `/api/teams` | Create a new collaboration team. |
| GET | `/api/teams/{id}` | View team members and team tasks. |
| POST | `/api/teams/{id}/invite` | Invite user to team by registered email. |
| DELETE | `/api/teams/{id}` | Delete team (Owner only). |

#### Invite Member Payload:
```json
{
  "email": "registered-user@email.com"
}
```

## Collaborative Logic

1. **Personal Tasks**: Created with `team_id: null`. Only visible to the owner.
2. **Team Tasks**: Created with a valid `team_id`. Visible to all members of that team.
3. **Guest Tasks**: Created without a token but with an `X-Device-ID` header. These remain private to the device until synced to an account.

## Security

- User passwords are encrypted using Bcrypt (12 rounds).
- API routes use Sanctum middleware for token validation.
- Cross-origin isolation handled via CORS configuration.

## License

Proprietary Software. All rights reserved.
