# LinkAce Installation Guide (Without Docker)

This guide documents the process to install and run LinkAce v2.4.1 without Docker on a Linux system.

## Prerequisites

Ensure the following are installed and running on your system:

- **PHP**: Version 8.1 to 8.4 with the following extensions:
  - BCMath
  - Ctype
  - DOM
  - Fileinfo
  - JSON
  - Mbstring
  - OpenSSL
  - PDO
  - Tokenizer
  - XML

- **Database**: MySQL 5.6+, PostgreSQL 9.4+, SQLite 3.8.8+, or SQL Server 2017+
  - In this setup: MariaDB 10.11.13

- **Web Server**: Apache or Nginx
  - In this setup: Nginx with Laravel Valet

- **Composer**: PHP dependency manager

- **Git** (optional, for cloning)

## Installation Steps

### 1. Obtain the LinkAce Files

Download the LinkAce .zip package from the [GitHub releases page](https://github.com/Kovah/LinkAce/releases/latest) and extract it to your desired directory.

Alternatively, if you have the files already, ensure you're in the project root directory.

### 2. Install PHP Dependencies

Navigate to the project root and install dependencies:

```bash
cd /path/to/linkace-v2.4.1
composer install --no-dev
```

### 3. Configure Environment File

Copy the example environment file:

```bash
cp .env.example .env
```

Edit `.env` to configure your database connection. For local MariaDB with root user (no password):

```dotenv
DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=linkace
DB_USERNAME=root
DB_PASSWORD=
```

### 4. Generate Application Key

Generate a unique application key:

```bash
php artisan key:generate
```

### 5. Set Up Database

Ensure your database server is running. Create the database if it doesn't exist:

```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS linkace;"
```

Note: In this setup, the database tables were already present in the downloaded files, so migrations were skipped.

If starting fresh, run migrations:

```bash
php artisan migrate
```

### 6. Complete Setup

Mark the setup as completed:

```bash
php artisan setup:complete
```

### 7. Create Admin User

Create your first admin user:

```bash
php artisan registeruser --admin
```

Follow the prompts to set username, email, and password.

### 8. Set Storage Permissions

Make the storage directory writable by the web server:

```bash
chmod -R 755 storage
```

### 9. Configure Web Server

Point your web server to the `/public` directory of the LinkAce installation.

#### Using Laravel Valet (Recommended for Development)

If you have Laravel Valet installed:

```bash
valet link linkace
```

The app will be accessible at `http://linkace.test` (assuming default Valet domain).

#### Using Nginx (Manual Configuration)

Add to your Nginx configuration:

```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /path/to/linkace-v2.4.1/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-XSS-Protection "1; mode=block";
    add_header X-Content-Type-Options "nosniff";

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~* \.(?:css|js|map|scss)$ {
        expires 7d;
        access_log off;
        add_header Cache-Control "public";
        try_files $uri @fallback;
    }

    error_page 404 /index.php;
}
```

#### Using Apache

Ensure `mod_rewrite` is enabled. The included `.htaccess` file in `/public` should handle the routing.

### 10. Access the Application

Open your browser and navigate to your configured URL (e.g., `http://linkace.test`).

You should see the LinkAce login page. Log in with the admin credentials created in step 7.

## Post-Installation Steps

Refer to the [official post-setup documentation](https://www.linkace.org/docs/v2/setup/post-setup/) for additional configuration, including:

- Setting up automated backups
- Configuring link checking
- Setting up email notifications
- And more

## Troubleshooting

- **Database Connection Issues**: Verify your `.env` database settings and ensure the database server is running.
- **Permission Errors**: Ensure the `storage` directory is writable by the web server user.
- **Web Server Errors**: Check that the document root points to `/public` and that URL rewriting is enabled.
- **PHP Extensions Missing**: Install any missing PHP extensions using your package manager (e.g., `apt install php8.3-mbstring`).

## Environment Details (This Setup)

- **OS**: Linux
- **PHP**: 8.3.28
- **Database**: MariaDB 10.11.13
- **Web Server**: Nginx with Valet v2.4.4
- **Composer**: 2.9.2

## Automation Scripts

Two convenience scripts are included:

- **`build-production.sh`**: Prepares and creates a production zip
- **`switch-to-dev.sh`**: Switches back to development mode

Usage:
```bash
./build-production.sh    # Create production build
./switch-to-dev.sh       # Switch to development
```

This setup was successfully tested and LinkAce is running at `http://linkace.test`.
