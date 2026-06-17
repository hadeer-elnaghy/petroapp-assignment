# Station Transfer Ingestion API (Laravel 12)

This repository implements the take-home engineering assignment for ingesting station transfer events safely, idempotently, and concurrently. 

**GitHub Repository:** [https://github.com/hadeer-elnaghy/petroapp-assignment.git](https://github.com/hadeer-elnaghy/petroapp-assignment.git)

**API Specs & Imports:**
* **OpenAPI 3.0 Spec:** [openapi.json](openapi.json)
* **Postman Collection:** [postman_collection.json](postman_collection.json)

---

## 🛠️ Tech Stack & Requirements

* **Framework:** Laravel 12.x
* **PHP Version:** PHP 8.2+
* **Database & Cache:** SQLite (default) / Database-backed Cache locks
* **Containerization:** Docker & Docker Compose
* **Package Manager:** Composer

---

## 🚀 Running Contract (Commands)

### Option A: Local Execution
Ensure you have PHP 8.2+ and SQLite enabled locally.

1. **Setup & Install Dependencies:**
   ```bash
   composer install
   cp .env.example .env
   php artisan key:generate
   touch database/database.sqlite
   php artisan migrate --force
   ```
2. **Start the server:**
   Using the script or Makefile target:
   ```bash
   # Using script
   ./scripts/run.sh

   # Or using make
   make run
   ```
   *The local server will start on `http://127.0.0.1:8000`.*

3. **Run the test suite:**
   ```bash
   # Using script
   ./scripts/test.sh

   # Or using make
   make test
   ```

---

### Option B: Dockerized Execution (Recommended)
You only need Docker and Docker Compose installed.

1. **Build & Start the application:**
   ```bash
   docker compose up --build
   ```
   *This starts the application, runs migrations, and exposes the API on `http://localhost:8000`.*

2. **Run tests inside Docker:**
   ```bash
   docker compose run --rm app test
   ```

---

## 📖 API Documentation & Examples

### 1. Ingest Transfer Events
* **Endpoint:** `POST /transfers`
* **Content-Type:** `application/json`

#### **Payload Example (Request):**
```bash
curl -X POST http://localhost:8000/transfers \
  -H "Content-Type: application/json" \
  -d '{
    "events": [
      {
        "event_id": "evt_101",
        "station_id": "station_A",
        "amount": 100.50,
        "status": "approved",
        "created_at": "2026-02-19T10:00:00Z"
      },
      {
        "event_id": "evt_102",
        "station_id": "station_A",
        "amount": 50.00,
        "status": "pending",
        "created_at": "2026-02-19T11:00:00Z"
      }
    ]
  }'
```

#### **Success Response (200 OK):**
```json
{
  "inserted": 2,
  "duplicates": 0
}
```

#### **Validation Failure Response (400 Bad Request):**
Occurs when the payload structure is invalid (e.g. negative amount, non-ISO8601 date, or missing fields).
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "events.0.amount": [
      "The events.0.amount field must be at least 0."
    ]
  }
}
```

---

### 2. Retrieve Station Summary
* **Endpoint:** `GET /stations/{station_id}/summary`

#### **Request Example:**
```bash
curl http://localhost:8000/stations/station_A/summary
```

#### **Success Response (200 OK):**
*Note: Only approved events count towards the `total_approved_amount`. All received statuses count towards the total `events_count`.*
```json
{
  "station_id": "station_A",
  "total_approved_amount": 100.50,
  "events_count": 2
}
```

---

## 🧠 Design Notes & Architectural Choices

### 1. Concurrency & Idempotency Strategy

We support two swappable drivers configured via `.env` (`TRANSFER_STORAGE_DRIVER=database` or `in_memory`):

* **Database Driver (`database`):**
  * **Idempotency**: Enforced by a **unique constraint** on the `event_id` column in the database schema.
  * **Concurrency Safety**: Handled inside a database transaction (`DB::transaction`) using Laravel's **`insertOrIgnore`** raw method.
  * **Trade-off**: The database handles atomic transaction safety natively. If two concurrent hits attempt to write the same `event_id`, only one write commits. The other is ignored cleanly without throwing exceptions, ensuring totals are always consistent and no duplicate records are created.

* **In-Memory Driver (`in_memory`):**
  * **Idempotency & Concurrency Safety**: Uses Laravel's **atomic Cache Locks** (`Cache::lock()->block()`).
  * **Trade-off**: When a batch ingestion arrives, the process locks access. Concurrent requests block until the write releases the lock. The succeeding request reads the updated cache state, detects duplicate IDs, and filters them out before saving, preventing double-insertions.

---

### 2. Validation & Error Handling Strategy

As allowed by the specification, we have documented and implemented the following behaviors:

* **Validation Rules:**
  * `event_id`, `station_id`, `status`, and `created_at` are strictly required.
  * `amount` must be a non-negative number (greater than or equal to `0`).
  * `created_at` must be parseable as a valid ISO8601 datetime format.
* **Error Handling Choice - "Fail-Fast":**
  * If the payload shape is invalid or fails any validation rules, the API returns a `400 Bad Request` with a helpful validation error message.
  * We chose the **"Fail-Fast"** approach: the **entire batch is rejected**, and no events from that payload are saved or committed to storage. This prevents half-written or partially corrupt data shapes in the database/in-memory cache.
* **Duplicate Events - Partial Accept:**
  * If the payload is validation-valid, but contains some events that have already been ingested (either internally duplicated in the array or externally already in the database), we **do not fail the request**. Instead, we accept the unique ones, ignore the duplicates, and return a summary payload indicating the `inserted` and `duplicates` counts.

---

### 3. Station Summary & Reconciliation Rules

* **Events Count Choice - "All Statuses":**
  * We chose the **"All Statuses"** approach: `events_count` returns the count of **all** stored events for that station, regardless of whether their status is `approved`, `pending`, or any other value.
* **Amount Summing - "Approved Only":**
  * The `total_approved_amount` is calculated by summing the amounts of **approved** events only.
* **Handling of Unknown Statuses:**
  * Unknown/custom statuses are allowed to be ingested. However, they are treated as non-approved, meaning they count toward the total `events_count` but do not contribute to the `total_approved_amount` sum.

