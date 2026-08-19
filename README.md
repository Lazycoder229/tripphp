# TRIP PHP Framework

> A lightweight PHP framework for building modern web applications with a clean MVC architecture, dependency injection, attribute based routing, database abstraction, validation, sessions, views, and security utilities.

[![PHP](https://img.shields.io/badge/PHP-8.4%2B-777BB4?logo=php\&logoColor=white)](https://www.php.net/)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-PHPUnit-4C1.svg)](https://phpunit.de/)

TRIP is a lightweight PHP framework designed to provide the core tools needed to build structured web applications without unnecessary complexity.

The framework follows a modular architecture with separate components for HTTP handling, routing, dependency injection, database access, security, sessions, storage, views, configuration, logging, and exceptions.

---

## Features

### Core

* Lightweight application architecture
* PSR-4 autoloading
* Environment configuration with `vlucas/phpdotenv`
* Dependency injection container
* HTTP request and response handling
* Exception handling
* Application configuration
* Logging

### Routing

* HTTP routing
* Attribute-based route definitions
* Route parameters
* HTTP method matching
* Controller-based routing

### Database

* MySQL database connection
* Database connection configuration
* Database models
* Query-oriented model functionality
* Schema support
* Database migrations
* Database seeders

### Security

TRIP provides built-in security utilities including:

* CSRF protection
* Password hashing
* Encryption
* JWT handling
* Input validation
* API key middleware
* Authentication middleware
* Security headers
* CORS
* Rate limiting

### Web Application

* Controllers
* Services
* Models
* Middleware
* Sessions
* File storage
* File uploads
* PHP-based views
* Error pages

### Testing

The project uses PHPUnit for automated testing.

```bash
composer test
```

---

## Requirements

TRIP requires:

* PHP **8.4 or newer**
* Composer
* MySQL/MariaDB when using database features

The framework currently declares PHP `^8.4` as its runtime requirement.

---

## Installation

```bash
composer require trip/app <app-name>
```

Enter the project directory:

```bash
cd <app-name>
```

Install Composer dependencies:

```bash
composer install
```

Create your environment file:

```bash
cp .env.example .env
```

Then configure your environment variables.

---

## Configuration

TRIP uses environment variables for application and database configuration.

A typical configuration may include:

```env
APP_NAME=TRIP
APP_ENV=development
APP_DEBUG=true

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=trip
DB_USERNAME=root
DB_PASSWORD=
```

Run this two command in your Terminal after you copy the .env.example
It is require at boot time.
```bash
php trip key:generate
php trip jwt:secret
```

Refer to `.env.example` in the repository for the available configuration options.

---

# Testing

TRIP uses PHPUnit.

Run the test suite with:

```bash
composer test
```

The Composer configuration defines the test script as:

```bash
phpunit
```

PHPUnit is included as a development dependency.

---

# Contributing

Contributions are welcome.

Before submitting a pull request:

1. Fork the repository.
2. Create a feature branch.
3. Make your changes.
4. Add or update tests when appropriate.
5. Run the test suite.
6. Commit your changes.
7. Open a pull request.

For major architectural changes, consider opening an issue first.

---

# License

TRIP PHP Framework is licensed under the **MIT License**.

See the [LICENSE](LICENSE) file for the complete license text.

---

# Author

**Resty M. Gonzales**

TRIP PHP Framework is an independent PHP framework project focused on building a lightweight and modular foundation for modern PHP web applications.

---

## TRIP PHP Framework

**Lightweight PHP. Clean Architecture. Developer Control.**
