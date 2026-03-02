# Todo API Backend

A RESTful API backend service for managing Todo tasks, built with Laravel. This service supports both authenticated users and anonymous guest interactions with automatic data synchronization upon registration or login.

## Features

- **Guest Task Management**: Users can create, update, and manage tasks anonymously using a unique device identifier.
- **User Authentication**: Secure registration, login, and token-based authentication using Laravel Sanctum.
- **Data Synchronization**: Automatic migration of guest tasks to a persistent user account upon registration or login.
- **Task Attributes**: Support for task deadlines, priority levels (high, medium, low), and completion status tracking.
- **Stateless Architecture**: Fully stateless API endpoints optimized for performance.

## Requirements

- PHP 8.2 or higher
- Composer
- PostgreSQL
- Nginx or Apache (for production deployment)

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

3. Configure environment variables:
   ```bash
   cp .env.example .env
   # Update database credentials in the .env file
   ```

4. Generate application key:
   ```bash
   php artisan key:generate
   ```

5. Run database migrations:
   ```bash
   php artisan migrate
   ```

6. Start the development server:
   ```bash
   php artisan serve
   ```

## API Endpoints

### Authentication

- `POST /api/register`: Register a new user account.
- `POST /api/login`: Authenticate and receive a Bearer token.
- `POST /api/logout`: Revoke the current authentication token (Requires Bearer Token).
- `GET /api/user`: Retrieve the authenticated user profile (Requires Bearer Token).

### Todo Tasks

Tasks can be managed using either a `Bearer Token` (for authenticated users) or an `X-Device-ID` header (for guest users).

- `GET /api/todos`: Retrieve all tasks associated with the user or device.
- `POST /api/todos`: Create a new task.
- `GET /api/todos/{id}`: Retrieve a specific task.
- `PUT /api/todos/{id}`: Update an existing task.
- `DELETE /api/todos/{id}`: Delete a task.

#### Guest Operations

To interact with tasks without an account, include a unique identifier in the request headers:
```http
X-Device-ID: unique-device-uuid-string
```

#### Task Synchronization

To migrate tasks from a guest session to a registered account, include the `device_id` parameter in the payload during the `/api/register` or `/api/login` requests.

```json
{
  "email": "user@example.com",
  "password": "securepassword",
  "device_id": "unique-device-uuid-string"
}
```

## License

This project is proprietary software. All rights reserved.