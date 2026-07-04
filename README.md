# AskMySQL — Secure Text-to-SQL Web App using PHP, MySQL and OpenAI

AskMySQL is a framework-free PHP 8+ web application that converts natural-language questions into safe MySQL `SELECT` queries using OpenAI, executes them through PDO, and displays both the generated SQL and the result table.

The app is designed for internal reporting, MIS dashboards, ERP databases, sales analysis, and admin teams who want to ask business questions without writing SQL manually.

---

## Example

User asks:

```text
Show sales city-wise between 01-06-2026 to 05-06-2026
```

The system generates a safe SQL query like:

```sql
SELECT city, SUM(total_amount) AS total_sales
FROM sales
WHERE sale_date BETWEEN '2026-06-01' AND '2026-06-05'
GROUP BY city
ORDER BY total_sales DESC
LIMIT 200;
```

Then it displays:

* The original question
* The generated SQL query
* The result table

---

## Features

* Modern responsive SaaS-style UI using Tailwind CSS
* Vanilla JavaScript frontend
* PHP 8+ backend with clean OOP structure
* MySQL database using PDO
* OpenAI API integration using native PHP cURL
* Live connected database schema viewer
* Shows tables, columns, data types, and relationships
* Chat-style input for natural language questions
* GPT converts questions into MySQL `SELECT` queries only
* Executes only validated safe queries
* Displays generated SQL and query result table
* IP-based request limit
* Maximum 10 requests per IP
* Request logging in MySQL
* CSRF protection
* Sanitized frontend output
* Friendly error messages
* No framework required
* No OpenAI SDK required

---

## Tech Stack

| Layer        | Technology                             |
| ------------ | -------------------------------------- |
| Backend      | PHP 8+                                 |
| Database     | MySQL                                  |
| DB Access    | PDO                                    |
| Frontend     | HTML, Tailwind CSS, Vanilla JavaScript |
| AI API       | OpenAI API using native PHP cURL       |
| Architecture | Clean OOP, no framework                |

---

## Project Structure

```text
text-to-sql/
├── app/
│   ├── Config/
│   │   ├── AppConfig.php
│   │   ├── Database.php
│   │   └── Env.php
│   ├── Exceptions/
│   │   ├── OpenAIException.php
│   │   ├── QueryExecutionException.php
│   │   └── SqlValidationException.php
│   ├── Helpers/
│   │   ├── JsonResponse.php
│   │   └── Logger.php
│   ├── Security/
│   │   ├── ClientIp.php
│   │   └── Csrf.php
│   └── Services/
│       ├── OpenAIClient.php
│       ├── QueryExecutor.php
│       ├── RateLimiter.php
│       ├── RequestLogger.php
│       ├── SchemaReader.php
│       └── SqlValidator.php
├── bootstrap/
│   └── app.php
├── database/
│   ├── schema.sql
│   └── demo_target.sql
├── public/
│   ├── api/
│   │   ├── query.php
│   │   └── schema.php
│   ├── assets/
│   │   └── js/
│   │       └── app.js
│   ├── .htaccess
│   └── index.php
├── storage/
│   └── logs/
├── tests/
│   └── SqlValidatorTest.php
├── .env.example
├── .gitignore
├── .htaccess
└── README.md
```

---

## Core Flow

1. User opens the web app.
2. The app loads the connected database schema.
3. Schema is displayed in the UI.
4. User enters a natural-language question.
5. Backend sends the question and schema to OpenAI.
6. OpenAI returns only a SQL `SELECT` query or `OUT_OF_SCOPE`.
7. Backend validates the generated SQL.
8. Only safe `SELECT` queries are executed.
9. Result is returned as JSON.
10. Frontend displays the question, SQL query, and result table.

---

## Security Rules

This project is designed with strict SQL safety checks.

Allowed:

```sql
SELECT
```

Blocked:

```sql
INSERT
UPDATE
DELETE
DROP
ALTER
TRUNCATE
CREATE
REPLACE
UNION
WITH
CALL
EXECUTE
DESCRIBE
SHOW
USE
```

The system also blocks:

* Multiple SQL statements
* SQL comments
* Subqueries
* CTEs
* Cross-database access
* System schemas
* Unknown tables
* Unknown columns
* Unsafe SQL functions
* Non-SELECT queries

---

## OpenAI System Prompt

The backend uses a strict Text-to-SQL prompt:

```text
You are a Text-to-SQL assistant.

Convert the user's question into a safe MySQL SELECT query only.

Rules:
- Use only the provided database schema.
- Do not explain.
- Do not use markdown.
- Do not generate INSERT, UPDATE, DELETE, DROP, ALTER, TRUNCATE, CREATE, REPLACE, or any destructive query.
- Do not assume tables or columns that are not present in the schema.
- Do not access system tables.
- Do not generate multiple statements.
- If the request is outside the schema or unsafe, return exactly: OUT_OF_SCOPE.
```

The OpenAI response is never trusted directly. The generated SQL must pass the backend SQL validator before execution.

---

## Database Tables

The application database requires two tables.

### `request_logs`

Stores each user question, generated SQL, status, and IP.

```sql
CREATE TABLE request_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    question TEXT NOT NULL,
    generated_sql TEXT NULL,
    status VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### `rate_limits`

Stores IP-based request usage.

```sql
CREATE TABLE rate_limits (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL UNIQUE,
    request_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

---

## Requirements

* PHP 8.0 or newer
* MySQL 8.0 or compatible MariaDB version
* PHP extensions:

  * `pdo_mysql`
  * `curl`
  * `json`
  * `session`
* OpenAI API key
* Apache/Nginx/cPanel/Plesk supported
* HTTPS recommended for production

---

## Installation

### 1. Clone the repository

```bash
git clone https://github.com/your-username/text-to-sql.git
cd text-to-sql
```

---

### 2. Create `.env` file

Copy the example environment file:

```bash
cp .env.example .env
```

Then update your credentials.

```env
APP_NAME=AskMySQL
APP_ENV=local
APP_DEBUG=true
APP_TIMEZONE=Asia/Kolkata

APP_DB_HOST=127.0.0.1
APP_DB_PORT=3306
APP_DB_NAME=text_to_sql
APP_DB_USER=root
APP_DB_PASSWORD=
APP_DB_CHARSET=utf8mb4

TARGET_DB_HOST=127.0.0.1
TARGET_DB_PORT=3306
TARGET_DB_NAME=text_to_sql
TARGET_DB_USER=root
TARGET_DB_PASSWORD=
TARGET_DB_CHARSET=utf8mb4

TARGET_DB_EXCLUDED_TABLES=request_logs,rate_limits

OPENAI_API_KEY=your_openai_api_key_here
OPENAI_MODEL=gpt-5.5
OPENAI_ENDPOINT=https://api.openai.com/v1/responses
OPENAI_TIMEOUT_SECONDS=45

RATE_LIMIT_MAX_REQUESTS=10
RATE_LIMIT_WINDOW_HOURS=24

MAX_RESULT_ROWS=200
MAX_QUESTION_LENGTH=1000
QUERY_TIMEOUT_MS=8000

TRUST_PROXY_HEADERS=false
```

Do not commit `.env` to GitHub.

---

### 3. Create application database

Create a MySQL database:

```sql
CREATE DATABASE text_to_sql CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Import the application tables:

```bash
mysql -u root -p text_to_sql < database/schema.sql
```

---

### 4. Import demo data

For quick testing, import the included demo target schema:

```bash
mysql -u root -p text_to_sql < database/demo_target.sql
```

Then open the app and ask:

```text
Show sales city-wise between 01-06-2026 to 05-06-2026
```

---

### 5. Set document root

Your web server document root should point to:

```text
/path/to/text-to-sql/public
```

Only the `public/` folder should be web-accessible.

Do not expose:

```text
.env
app/
database/
storage/
bootstrap/
```

---

### 6. Run locally

Using PHP built-in server:

```bash
php -S 127.0.0.1:8080 -t public
```

Open:

```text
http://127.0.0.1:8080
```

---

## Recommended Production Database Users

For production, do not use the root database user.

Use two separate MySQL users:

1. Application user for logs and rate limits
2. Read-only reporting user for the target database

### Create read-only target user

```sql
CREATE USER 'textsql_reader'@'127.0.0.1' IDENTIFIED BY 'strong_readonly_password';

GRANT SELECT ON reporting_database.* 
TO 'textsql_reader'@'127.0.0.1';

FLUSH PRIVILEGES;
```

### Create application user

```sql
CREATE USER 'textsql_app'@'127.0.0.1' IDENTIFIED BY 'strong_app_password';

GRANT SELECT, INSERT, UPDATE 
ON text_to_sql.rate_limits 
TO 'textsql_app'@'127.0.0.1';

GRANT INSERT 
ON text_to_sql.request_logs 
TO 'textsql_app'@'127.0.0.1';

FLUSH PRIVILEGES;
```

---

## API Endpoints

### Get Schema

```http
GET /api/schema.php
```

Returns connected database schema information.

Example response:

```json
{
  "success": true,
  "database": "text_to_sql",
  "tables": [
    {
      "name": "sales",
      "columns": [
        {
          "name": "sale_date",
          "type": "date"
        },
        {
          "name": "total_amount",
          "type": "decimal"
        }
      ]
    }
  ]
}
```

---

### Ask Question

```http
POST /api/query.php
```

Headers:

```http
Content-Type: application/json
X-CSRF-Token: csrf_token_from_page
```

Body:

```json
{
  "question": "Show sales city-wise between 01-06-2026 to 05-06-2026"
}
```

Successful response:

```json
{
  "success": true,
  "question": "Show sales city-wise between 01-06-2026 to 05-06-2026",
  "sql": "SELECT city, SUM(total_amount) AS total_sales FROM sales WHERE sale_date BETWEEN '2026-06-01' AND '2026-06-05' GROUP BY city ORDER BY total_sales DESC LIMIT 200",
  "result": {
    "columns": ["city", "total_sales"],
    "rows": [
      {
        "city": "Surat",
        "total_sales": "25000.00"
      }
    ],
    "row_count": 1,
    "truncated": false
  },
  "rate_limit": {
    "allowed": true,
    "count": 1,
    "remaining": 9
  }
}
```

---

## Rate Limiting

Default rate limit:

```text
10 requests per IP
```

Configurable in `.env`:

```env
RATE_LIMIT_MAX_REQUESTS=10
RATE_LIMIT_WINDOW_HOURS=24
```

When the limit is reached, the app returns a friendly message instead of processing the request.

Example:

```json
{
  "success": false,
  "message": "You have reached the request limit. Please try again later."
}
```

---

## Query Validation

Generated SQL is validated before execution.

The validator checks:

* Query starts with `SELECT`
* Only one statement is present
* No destructive SQL keywords are used
* Tables exist in the connected schema
* Columns exist in the connected schema
* No cross-database query is used
* No system schema is accessed
* No comments or hidden SQL payloads exist
* Limit is numeric and controlled

This protects the database even if the AI returns an unsafe response.

---

## Output Handling

The frontend safely renders dynamic content using JavaScript DOM methods.

The app avoids inserting raw HTML from:

* User questions
* Generated SQL
* Database results
* API error messages

This helps reduce XSS risk.

---

## Error Handling

Errors are handled gracefully.

The user sees a friendly message, while technical details are written to:

```text
storage/logs/app.log
```

Example user-facing error:

```text
Unable to process your request right now. Please try again.
```

---

## Testing

Run PHP syntax check:

```bash
find . -name "*.php" -print0 | xargs -0 -n1 php -l
```

Run the SQL validator test:

```bash
php tests/SqlValidatorTest.php
```

---

## Deployment Checklist

Before production deployment:

* Set `APP_DEBUG=false`
* Use HTTPS
* Point document root to `public/`
* Keep `.env` outside public access
* Use a read-only MySQL user for target database
* Do not use root DB user
* Exclude sensitive/internal tables
* Use database views for reporting if needed
* Restrict access to sensitive columns
* Monitor request logs
* Monitor OpenAI API usage
* Rotate API keys regularly
* Ensure `storage/logs` is writable but not public
* Test all business questions on staging first

---

## Important Security Notes

This project validates generated SQL, but validation alone is not a full security boundary.

For sensitive databases, use:

* Read-only database user
* Reporting replica
* Separate reporting schema
* SQL views
* Column-level permissions
* Network allow-listing
* Query audit logs

Never connect this app directly to a production database using an admin or write-enabled user.

---

## Common Issues

### Schema is not loading

Check:

* `TARGET_DB_HOST`
* `TARGET_DB_NAME`
* `TARGET_DB_USER`
* `TARGET_DB_PASSWORD`
* `pdo_mysql` extension
* MySQL user permissions

---

### OpenAI request fails

Check:

* `OPENAI_API_KEY`
* `OPENAI_MODEL`
* Server outbound HTTPS access
* PHP cURL extension
* OpenAI account billing/access

---

### Query is blocked even though it is SELECT

The SQL validator is intentionally strict.

Try asking simpler reporting questions using direct tables, columns, filters, joins, grouping, and ordering.

---

### Rate limit is not working correctly behind Cloudflare/CDN

Use:

```env
TRUST_PROXY_HEADERS=true
```

Only enable this when your proxy/CDN is trusted and properly configured.

---

## Suggested Use Cases

* Sales reporting
* City-wise sales analysis
* MIS reports
* Franchise reporting
* ERP data exploration
* CRM data analysis
* Inventory reporting
* Finance summary reports
* Admin dashboards
* Internal business intelligence tools

---

## Future Improvements

* User login system
* Role-based table access
* Saved questions
* Export result to Excel/CSV
* Chart generation
* Query history dashboard
* Admin panel for usage analytics
* Support for multiple databases
* Business glossary mapping
* Column aliases for non-technical users
* Fine-tuned schema descriptions

---

## License

This project is free to use and customize for personal, internal, or commercial use.

Please review database security, privacy, and compliance requirements before using it with real business data.

---

## Author

Built by Rahul Singh.

LinkedIn: https://www.linkedin.com/in/rasmus6992/
Website: https://rasmus6992.com/
