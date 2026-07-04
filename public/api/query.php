<?php

declare(strict_types=1);

use App\Config\Database;
use App\Exceptions\OpenAIException;
use App\Exceptions\QueryExecutionException;
use App\Exceptions\SqlValidationException;
use App\Helpers\JsonResponse;
use App\Security\ClientIp;
use App\Security\Csrf;
use App\Services\OpenAIClient;
use App\Services\QueryExecutor;
use App\Services\RateLimiter;
use App\Services\RequestLogger;
use App\Services\SchemaReader;
use App\Services\SqlValidator;

$bootstrap = require dirname(__DIR__, 2) . '/bootstrap/app.php';
$config = $bootstrap['config'];
$logger = $bootstrap['logger'];
$reference = strtoupper(bin2hex(random_bytes(4)));

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    JsonResponse::send([
        'success' => false,
        'message' => 'Only POST requests are allowed.',
    ], 405);
}

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!Csrf::validate(is_string($csrfToken) ? $csrfToken : null)) {
    JsonResponse::send([
        'success' => false,
        'message' => 'Your session has expired. Refresh the page and try again.',
        'code' => 'CSRF_FAILED',
    ], 419);
}

$question = '';
$generatedSql = null;
$ipAddress = ClientIp::detect((bool) $config['security']['trust_proxy_headers']);
$requestLogger = null;

try {
    $rawBody = file_get_contents('php://input');
    if ($rawBody === false || trim($rawBody) === '') {
        JsonResponse::send([
            'success' => false,
            'message' => 'Please enter a question.',
        ], 422);
    }

    if (strlen($rawBody) > 16384) {
        JsonResponse::send([
            'success' => false,
            'message' => 'The request body is too large.',
        ], 413);
    }

    $decodedPayload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decodedPayload)) {
        JsonResponse::send([
            'success' => false,
            'message' => 'The JSON request must contain an object.',
        ], 400);
    }

    /** @var array<string, mixed> $payload */
    $payload = $decodedPayload;
    $question = trim((string) ($payload['question'] ?? ''));

    if ($question === '') {
        JsonResponse::send([
            'success' => false,
            'message' => 'Please enter a question.',
        ], 422);
    }

    $questionLength = function_exists('mb_strlen')
        ? mb_strlen($question, 'UTF-8')
        : strlen($question);

    if ($questionLength > (int) $config['security']['max_question_length']) {
        JsonResponse::send([
            'success' => false,
            'message' => sprintf(
                'Please keep the question under %d characters.',
                $config['security']['max_question_length']
            ),
        ], 422);
    }

    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $question) === 1) {
        JsonResponse::send([
            'success' => false,
            'message' => 'The question contains invalid control characters.',
        ], 422);
    }

    $appPdo = Database::connect($config['database']['app']);
    $requestLogger = new RequestLogger($appPdo);

    $safeLog = static function (
        string $status,
        ?string $sql = null
    ) use (&$requestLogger, &$question, $ipAddress, $logger, $reference): void {
        if (!$requestLogger instanceof RequestLogger) {
            return;
        }

        try {
            $requestLogger->log($ipAddress, $question, $sql, $status);
        } catch (Throwable $logException) {
            $logger->exception($logException, $reference . '-LOG');
        }
    };

    $rateLimiter = new RateLimiter(
        $appPdo,
        (int) $config['security']['rate_limit_max'],
        (int) $config['security']['rate_limit_window_hours']
    );
    $rateStatus = $rateLimiter->consume($ipAddress);

    if (!$rateStatus['allowed']) {
        $safeLog('rate_limited');

        JsonResponse::send([
            'success' => false,
            'message' => 'You have reached the request limit for this IP address.',
            'code' => 'RATE_LIMITED',
            'rate_limit' => $rateStatus,
        ], 429);
    }

    // The target connection should use a MySQL user with SELECT-only privileges.
    $targetPdo = Database::connect($config['database']['target'], true);
    $schemaReader = new SchemaReader(
        $targetPdo,
        (string) $config['database']['target']['name'],
        $config['security']['excluded_tables']
    );
    $schema = $schemaReader->read();

    if ($schema === []) {
        $safeLog('schema_empty');
        JsonResponse::send([
            'success' => false,
            'message' => 'No queryable tables were found in the connected database.',
            'rate_limit' => $rateStatus,
        ], 422);
    }

    $openAI = new OpenAIClient($config['openai']);
    $generatedSql = $openAI->generateSql($question, $schemaReader->toPrompt($schema));

    if (strtoupper(trim($generatedSql)) === 'OUT_OF_SCOPE') {
        $safeLog('out_of_scope', $generatedSql);
        JsonResponse::send([
            'success' => false,
            'message' => 'That question cannot be answered safely from the connected schema. Try asking about the available tables and columns.',
            'code' => 'OUT_OF_SCOPE',
            'rate_limit' => $rateStatus,
        ], 422);
    }

    $validator = new SqlValidator($config['security']['excluded_tables']);
    $validatedSql = $validator->validate($generatedSql, $schema);

    $executor = new QueryExecutor(
        $targetPdo,
        (int) $config['security']['max_result_rows'],
        (int) $config['security']['query_timeout_ms']
    );
    $result = $executor->execute($validatedSql);

    $safeLog('success', $validatedSql);

    JsonResponse::send([
        'success' => true,
        'question' => $question,
        'sql' => $validatedSql,
        'result' => $result,
        'rate_limit' => $rateStatus,
    ]);
} catch (JsonException $exception) {
    JsonResponse::send([
        'success' => false,
        'message' => 'The request body is not valid JSON.',
    ], 400);
} catch (SqlValidationException $exception) {
    if ($requestLogger instanceof RequestLogger) {
        try {
            $requestLogger->log($ipAddress, $question, $generatedSql, 'validation_failed');
        } catch (Throwable $logException) {
            $logger->exception($logException, $reference . '-LOG');
        }
    }

    $logger->exception($exception, $reference);
    JsonResponse::send([
        'success' => false,
        'message' => 'The generated query was blocked by the SQL safety validator.',
        'code' => 'SQL_BLOCKED',
        'reference' => $reference,
    ], 422);
} catch (OpenAIException $exception) {
    if ($requestLogger instanceof RequestLogger) {
        try {
            $requestLogger->log($ipAddress, $question, $generatedSql, 'openai_error');
        } catch (Throwable $logException) {
            $logger->exception($logException, $reference . '-LOG');
        }
    }

    $logger->exception($exception, $reference);
    JsonResponse::send([
        'success' => false,
        'message' => (bool) $config['app']['debug']
            ? $exception->getMessage()
            : 'The AI service could not generate a query right now.',
        'code' => 'OPENAI_ERROR',
        'reference' => $reference,
    ], 502);
} catch (QueryExecutionException $exception) {
    if ($requestLogger instanceof RequestLogger) {
        try {
            $requestLogger->log($ipAddress, $question, $generatedSql, 'query_error');
        } catch (Throwable $logException) {
            $logger->exception($logException, $reference . '-LOG');
        }
    }

    $logger->exception($exception, $reference);
    JsonResponse::send([
        'success' => false,
        'message' => 'The generated query was safe but could not be executed. Try phrasing the question more specifically.',
        'code' => 'QUERY_ERROR',
        'reference' => $reference,
    ], 422);
} catch (Throwable $exception) {
    if ($requestLogger instanceof RequestLogger) {
        try {
            $requestLogger->log($ipAddress, $question, $generatedSql, 'server_error');
        } catch (Throwable $logException) {
            $logger->exception($logException, $reference . '-LOG');
        }
    }

    $logger->exception($exception, $reference);
    JsonResponse::send([
        'success' => false,
        'message' => 'An unexpected server error occurred. Please check the configuration and logs.',
        'code' => 'SERVER_ERROR',
        'reference' => $reference,
    ], 500);
}
