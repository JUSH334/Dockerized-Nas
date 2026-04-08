# NAS Web Server

A web-based interface for managing a Network Attached Storage (NAS) server, running on Docker.

## Tech Stack

- **Apache** — web server
- **PHP 8.2** — backend
- **MySQL 8.0** — database
- **phpMyAdmin** — database management UI
- **Docker** — containerized environment

## Prerequisites

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) installed and running

## Setup

1. **Clone the repository**
   ```
   git clone <repo-url>
   cd NAS
   ```

2. **Create a `.env` file** in the project root with your database credentials:
   ```
   MYSQL_ROOT_PASSWORD=your_root_password
   MYSQL_DATABASE=nas_db
   MYSQL_USER=nas_user
   MYSQL_PASSWORD=your_user_password
   ```

3. **Start the containers**
   ```
   docker compose up -d
   ```

4. **Verify** the services are running:
   ```
   docker compose ps
   ```

## Accessing the Services

| Service     | URL                    |
|-------------|------------------------|
| Web Server  | http://localhost:8080   |
| phpMyAdmin  | http://localhost:8081   |

## Project Structure

```
NAS/
├── docker-compose.yml   # Container definitions
├── .env                 # Database credentials (not committed)
├── web/
│   └── Dockerfile       # Apache + PHP image
├── www/                 # Web application files
│   └── index.php
├── uploads/             # File upload storage (not committed)
└── sql/
    └── init.sql         # Database schema (runs on first start)
```

## Stopping the Server

```
docker compose down
```

To also remove the database volume (deletes all data):
```
docker compose down -v
```
