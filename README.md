# AskMySQL — Secure Text-to-SQL for PHP and MySQL
Live Demo: https://rasmus6992.com/randomaistuff/AskMySQL/public/ 
A framework-free PHP 8 application that converts a natural-language reporting question into a MySQL `SELECT` query, validates it against the live database schema, executes it through PDO, and displays both the SQL and the result table.

The application uses the OpenAI Responses API through native PHP cURL. No Composer package or OpenAI SDK is required.

## Features

- PHP 8+, PDO MySQL, native cURL, Tailwind CSS CDN, and Vanilla JavaScript
- Live schema explorer for tables, columns, data types, keys, and relationships
- Natural-language chat input with responsive loading and error states
- Strict OpenAI developer prompt that returns SQL or `OUT_OF_SCOPE`
- Server-side SQL validation independent of the model
- Separate application and target database connections
- IP-based limit of 10 requests per configured window
- CSRF protection for query requests
- Maximum execution-time request to MySQL and a server-side result-row cap
- Request status logging without exposing credentials to the browser
- JSON API responses for all AJAX operations
- Optional demo sales schema and sample data

## Project structure

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
│   ├── assets/js/app.js
│   ├── .htaccess
│   └── index.php
├── storage/logs/
├── tests/
│   └── SqlValidatorTest.php
├── .env.example
├── .gitignore
├── .htaccess
└── README.md
```

## Requirements

- PHP 8.0 or newer
- MySQL 8.0+ or a compatible modern MariaDB version
- PHP extensions: `pdo_mysql`, `curl`, `json`, and `session`
- `mbstring` is recommended but not mandatory
- An OpenAI API key and access to the model configured in `.env`
- HTTPS for production deployments

## Quick setup

### 1. Copy the environment file

```bash
cp .env.example .env
```

Edit `.env` and set the database credentials and `OPENAI_API_KEY`.

The default model is configurable:

```dotenv
OPENAI_MODEL=gpt-5.5
```

Use a model that is available to your OpenAI API project. The request uses `POST /v1/responses`, sends the safety prompt in `instructions`, sends the schema and question in `input`, and sets `store=false`.

Official API reference:

- https://developers.openai.com/api/reference/resources/responses/methods/create
- https://developers.openai.com/api/docs/guides/text

### 2. Create the application database tables

Create a database, then import:

```bash
mysql -u root -p text_to_sql < database/schema.sql
```

This creates:

- `request_logs`
- `rate_limits`

### 3. Import the optional demo target schema

For an immediate working demo, import the included city, customer, and sales data into the target database:

```bash
mysql -u root -p text_to_sql < database/demo_target.sql
```

You can then ask:

> Show sales city-wise between 01-06-2026 and 05-06-2026

For a real deployment, point `TARGET_DB_*` to the reporting database instead.

### 4. Configure database connections

The application deliberately supports two MySQL connections:

- `APP_DB_*`: writes request logs and rate-limit counters
- `TARGET_DB_*`: reads schema metadata and executes generated reporting queries

For local development, both can temporarily use the same database and MySQL user.

For production, create a dedicated read-only target user. Replace the example names and host restrictions for your environment:

```sql
CREATE USER 'textsql_reader'@'127.0.0.1' IDENTIFIED BY 'use-a-long-random-password';
GRANT SELECT ON reporting_database.* TO 'textsql_reader'@'127.0.0.1';
FLUSH PRIVILEGES;
```

The application database user only needs access to the two application tables:

```sql
CREATE USER 'textsql_app'@'127.0.0.1' IDENTIFIED BY 'use-another-long-random-password';
GRANT SELECT, INSERT, UPDATE ON application_database.rate_limits TO 'textsql_app'@'127.0.0.1';
GRANT INSERT ON application_database.request_logs TO 'textsql_app'@'127.0.0.1';
FLUSH PRIVILEGES;
```

The app user requires `SELECT` on `rate_limits` because it reads the current IP counter before performing an atomic update.

### 5. Set the web document root

Point Apache, Nginx, cPanel, Plesk, or your subdomain document root to:

```text
/path/to/text-to-sql/public
```

This is important. The `.env`, application classes, database scripts, and logs must remain outside the public web root.

For PHP's local development server:

```bash
php -S 127.0.0.1:8080 -t public
```

Open `http://127.0.0.1:8080`.

## Environment options

```dotenv
APP_NAME=AskMySQL
APP_ENV=production
APP_DEBUG=false
APP_TIMEZONE=Asia/Kolkata

APP_DB_HOST=127.0.0.1
APP_DB_PORT=3306
APP_DB_NAME=text_to_sql
APP_DB_USER=textsql_app
APP_DB_PASSWORD=change_this_password
APP_DB_CHARSET=utf8mb4

TARGET_DB_HOST=127.0.0.1
TARGET_DB_PORT=3306
TARGET_DB_NAME=text_to_sql
TARGET_DB_USER=textsql_reader
TARGET_DB_PASSWORD=change_this_readonly_password
TARGET_DB_CHARSET=utf8mb4
TARGET_DB_EXCLUDED_TABLES=request_logs,rate_limits

OPENAI_API_KEY=
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

### Rate-limit behavior

The default is 10 consumed requests per IP in a 24-hour window. Change `RATE_LIMIT_WINDOW_HOURS` as required.

- Set it to `24` for a daily-style window.
- Set it to `1` for an hourly window.
- Set it to `0` for a non-resetting counter.

The rate-limit update uses a database transaction and `SELECT ... FOR UPDATE` to avoid normal concurrent-request races.

### Trusted proxy headers

Keep `TRUST_PROXY_HEADERS=false` unless the server is behind a trusted proxy or CDN that removes client-supplied forwarding headers and replaces them with verified values.

When enabled, the application considers `CF-Connecting-IP`, `X-Real-IP`, and the first `X-Forwarded-For` address. Enabling this on an untrusted direct server allows clients to spoof IPs and bypass the rate limit.

## Request flow

1. The browser obtains a session-bound CSRF token from `index.php`.
2. `schema.php` reads `information_schema` for the configured target database.
3. The user question is submitted as JSON to `query.php`.
4. The server validates the CSRF token, length, characters, and IP allowance.
5. The live allowed schema and question are sent to OpenAI.
6. OpenAI returns one SQL string or exactly `OUT_OF_SCOPE`.
7. `SqlValidator` validates the SQL independently.
8. `QueryExecutor` applies a maximum row limit, prepares the query with PDO, and executes it through the target connection.
9. The API returns sanitized JSON; the browser renders all dynamic content with DOM `textContent`.
10. The request status is written to `request_logs`.

## SQL safety policy

The validator allows a conservative reporting subset:

- One direct `SELECT`
- Explicit `JOIN` clauses
- `WHERE`, aggregates, `GROUP BY`, `HAVING`, `ORDER BY`
- A fixed numeric `LIMIT`
- A controlled allow-list of common read-only SQL functions

It rejects:

- `INSERT`, `UPDATE`, `DELETE`, `DROP`, `ALTER`, `TRUNCATE`, `CREATE`, and other administrative or write keywords
- Multiple statements and extra semicolons
- Comments
- `UNION`
- CTEs and nested `SELECT` statements
- Derived tables and table functions
- User/session variables
- File, delay, lock, and benchmark functions
- System schemas
- Cross-database references
- Unknown tables
- Unknown qualified columns
- Comma joins
- Arbitrary stored or user-defined functions

This intentionally prioritizes safety and predictable behavior over supporting every valid MySQL reporting construct.

### Why a read-only MySQL user is still required

Application validation reduces risk but is not a database permission boundary. The target MySQL account should have only the minimum `SELECT` privileges needed for reporting. Do not use `root`, an application owner account, or any account with write, file, administrative, or routine-execution privileges.

For highly sensitive databases, use one or more of these additional controls:

- A reporting replica
- A curated reporting schema or SQL views
- Column-level privileges that exclude secrets and personal data
- Network allow-lists
- A separate database server account with no stored routine privileges
- Query auditing and alerting

## Output and data handling

- Database credentials and the OpenAI key stay server-side in `.env`.
- The browser receives the schema names, generated SQL, and result values, because those are core UI requirements.
- Internal tables listed in `TARGET_DB_EXCLUDED_TABLES` are omitted from the model prompt and rejected by the validator.
- The OpenAI request sets `store=false`.
- Cell values are capped before JSON output; invalid UTF-8 binary values are omitted.
- Results are capped by `MAX_RESULT_ROWS`.
- Detailed exceptions are written to `storage/logs/app.log`; the browser receives a short reference code.

Review your organization's data-governance requirements before sending schema names or user reporting questions to any external AI API.

## API endpoints

### `GET /api/schema.php`

Returns the connected database name, visible tables, columns, foreign keys, schema counts, and current rate-limit state.

### `POST /api/query.php`

Headers:

```http
Content-Type: application/json
X-CSRF-Token: token-from-page-meta-tag
```

Body:

```json
{
  "question": "Show sales city-wise between 01-06-2026 and 05-06-2026"
}
```

Successful response shape:

```json
{
  "success": true,
  "question": "...",
  "sql": "SELECT ...",
  "result": {
    "columns": ["city_name", "total_sales"],
    "rows": [],
    "row_count": 0,
    "truncated": false
  },
  "rate_limit": {
    "allowed": true,
    "count": 1,
    "remaining": 9,
    "reset_at": "2026-07-03 10:00:00"
  }
}
```

## Testing

Run PHP syntax checks:

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

Run the included validator test:

```bash
php tests/SqlValidatorTest.php
```

The test covers valid joins and aggregates plus blocked write statements, unknown schema identifiers, comments, `UNION`, nested queries, and system-schema access.

## Production checklist

- Set `APP_DEBUG=false`.
- Serve only the `public/` directory.
- Protect `.env` and keep it outside the document root.
- Use HTTPS.
- Use a SELECT-only target MySQL account.
- Exclude internal and sensitive tables.
- Restrict sensitive columns through database views or privileges.
- Use long random database passwords.
- Ensure `storage/logs` is writable by PHP but not publicly accessible.
- Set `TRUST_PROXY_HEADERS` correctly for your hosting topology.
- Review and rotate the OpenAI key.
- Replace the Tailwind CDN with a compiled Tailwind build when enforcing a stricter production CSP.
- Monitor `request_logs`, database slow-query logs, and OpenAI usage.
- Test representative questions against a staging/reporting database before production use.

## Troubleshooting

### Schema panel says connection failed

- Verify `TARGET_DB_*` values.
- Confirm `pdo_mysql` is enabled.
- Confirm the target user can read `information_schema.COLUMNS` for tables it can access.
- Check `storage/logs/app.log` using the reference shown in the UI.

### Every query returns an OpenAI error

- Set `OPENAI_API_KEY`.
- Confirm the configured model is available to the API project.
- Confirm outbound HTTPS/cURL access is allowed by the host.
- Increase `OPENAI_TIMEOUT_SECONDS` if the hosting network is slow.

### Query is blocked despite being a SELECT

The validator is intentionally conservative. Rephrase the question so the model can use direct tables, explicit joins, standard aggregate functions, and no nested query.

### Rate limit does not identify users correctly behind a CDN

Enable `TRUST_PROXY_HEADERS` only after confirming the CDN or reverse proxy overwrites forwarding headers. Otherwise leave it disabled.

## License

Use and adapt this project for your own applications. Review security, privacy, and database privileges for each deployment.
