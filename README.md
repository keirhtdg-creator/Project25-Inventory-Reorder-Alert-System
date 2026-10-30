# Project 25 – Inventory Reorder Alert System

## Project Description

The Inventory Reorder Alert System is a web-based application that monitors inventory levels and helps identify items that need to be reordered.

The system provides automated threshold alerts, prevents duplicate reorder requests, monitors aging reorder requests, and generates weekly stock risk summaries.

The application uses Laravel for the backend API and Flutter Web for the frontend interface.

## Technologies Used

### Backend

* PHP
* Laravel
* MySQL / MariaDB

### Frontend

* Flutter
* Dart

### Database

* MySQL or MariaDB

## Project Structure

```text
Project25-Inventory-Reorder-Alert-System/
│
├── backend/
│   ├── app/
│   ├── database/
│   ├── routes/
│   ├── tests/
│   ├── composer.json
│   └── .env.example
│
├── frontend/
│   ├── lib/
│   ├── test/
│   ├── web/
│   └── pubspec.yaml
│
├── documentation/
│
├── .gitignore
└── README.md
```

## System Requirements

Install the following before running the project:

* PHP version compatible with the Laravel dependencies
* Composer
* MySQL or MariaDB
* Flutter SDK
* Dart SDK
* Google Chrome or another supported browser

## Backend Installation

1. Open a terminal.
2. Navigate to the backend folder.

```bash
cd backend
```

3. Install dependencies.

```bash
composer install
```

4. Create your local environment file.

```bash
copy .env.example .env
```

5. Generate the Laravel application key.

```bash
php artisan key:generate
```

6. Create a local database and configure the database settings in `.env`.

7. Run database migrations.

```bash
php artisan migrate
```

8. Start the Laravel development server.

```bash
php artisan serve
```

The backend will normally be available at:

`http://127.0.0.1:8000`

## Frontend Installation

1. Open another terminal.
2. Navigate to the frontend folder.

```bash
cd frontend
```

3. Install Flutter dependencies.

```bash
flutter pub get
```

4. Start the Flutter Web application.

```bash
flutter run -d chrome
```

Make sure the Laravel backend is running while testing the frontend.

## Testing

### Backend Tests

Navigate to the backend folder and run:

```bash
php artisan test
```

### Frontend Analysis

Navigate to the frontend folder and run:

```bash
flutter analyze
```

### Frontend Tests

```bash
flutter test
```

## Main System Features

* Inventory management
* Stock-in and stock-out transactions
* Automated threshold alerts
* Duplicate reorder prevention
* Reorder request management
* Aging reorder escalation
* Weekly stock risk summaries
* Manual action management
* API integration with Flutter Web

## Important Notes

* The `.env` file is not included in the repository.
* Create your own local `.env` file using `.env.example`.
* Do not upload database files, passwords, API keys, or other private credentials.
* Use a separate local database for testing.
* The project is currently under development.
