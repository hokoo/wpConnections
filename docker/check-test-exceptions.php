<?php

declare(strict_types=1);

const EXIT_PASSED = 0;
const EXIT_POLICY_FAILURE = 1;
const EXIT_INVALID_INPUT = 2;

const REQUIRED_EXCEPTION_FIELDS = [
    'id',
    'scenario_ids',
    'test',
    'owner',
    'reason',
    'issue',
    'expires_on',
    'exit_condition',
    'scope',
    'approved_by',
];

/**
 * @return list<array<string, mixed>>
 */
function read_exception_registry(string $path): array
{
    if (! is_file($path) || ! is_readable($path)) {
        throw new RuntimeException("Test exception registry is not readable: {$path}");
    }

    try {
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException("Invalid test exception registry JSON: {$exception->getMessage()}", 0, $exception);
    }

    if (! is_array($data) || ($data['schema'] ?? null) !== 1) {
        throw new RuntimeException('Test exception registry must use schema 1');
    }

    if (! isset($data['exceptions']) || ! is_array($data['exceptions']) || ! array_is_list($data['exceptions'])) {
        throw new RuntimeException('Test exception registry exceptions must be a JSON array');
    }

    return $data['exceptions'];
}

/**
 * @param array<string, mixed> $exception
 * @param array<string, true>  $seenIds
 * @return array{critical: bool, id: string}
 */
function validate_exception(array $exception, int $index, array &$seenIds, string $today): array
{
    $actualFields = array_keys($exception);
    sort($actualFields);
    $requiredFields = REQUIRED_EXCEPTION_FIELDS;
    sort($requiredFields);

    if ($actualFields !== $requiredFields) {
        throw new RuntimeException(
            sprintf(
                'Exception record %d must contain exactly these fields: %s',
                $index,
                implode(', ', REQUIRED_EXCEPTION_FIELDS)
            )
        );
    }

    foreach (REQUIRED_EXCEPTION_FIELDS as $field) {
        if ($field === 'scenario_ids') {
            continue;
        }

        if (! is_string($exception[$field]) || trim($exception[$field]) === '') {
            throw new RuntimeException("Exception record {$index} field {$field} must be a non-empty string");
        }
    }

    $id = $exception['id'];
    if (preg_match('/^TQ-EX-[0-9]{3}$/', $id) !== 1) {
        throw new RuntimeException("Exception record {$index} id must match TQ-EX-NNN");
    }

    if (isset($seenIds[$id])) {
        throw new RuntimeException("Duplicate test exception id: {$id}");
    }
    $seenIds[$id] = true;

    $scenarioIds = $exception['scenario_ids'];
    if (! is_array($scenarioIds) || ! array_is_list($scenarioIds) || $scenarioIds === []) {
        throw new RuntimeException("Exception {$id} scenario_ids must be a non-empty JSON array");
    }

    foreach ($scenarioIds as $scenarioId) {
        if (! is_string($scenarioId) ||
            ($scenarioId !== 'none' && preg_match('/^[A-Z]+(?:-[A-Z0-9]+)*-[0-9]{2}$/', $scenarioId) !== 1)
        ) {
            throw new RuntimeException("Exception {$id} contains an invalid scenario ID");
        }
    }

    if (in_array('none', $scenarioIds, true) && $scenarioIds !== ['none']) {
        throw new RuntimeException("Exception {$id} cannot combine none with critical scenario IDs");
    }

    $issueScheme = parse_url($exception['issue'], PHP_URL_SCHEME);
    if (! in_array($issueScheme, ['http', 'https'], true) || filter_var($exception['issue'], FILTER_VALIDATE_URL) === false) {
        throw new RuntimeException("Exception {$id} issue must be an HTTP(S) URL");
    }

    $expiry = DateTimeImmutable::createFromFormat('!Y-m-d', $exception['expires_on']);
    $expiryErrors = DateTimeImmutable::getLastErrors();
    if ($expiry === false ||
        ($expiryErrors !== false && ($expiryErrors['warning_count'] > 0 || $expiryErrors['error_count'] > 0)) ||
        $expiry->format('Y-m-d') !== $exception['expires_on']
    ) {
        throw new RuntimeException("Exception {$id} expires_on must be a valid YYYY-MM-DD date");
    }

    if ($exception['expires_on'] < $today) {
        throw new RuntimeException("Exception {$id} expired on {$exception['expires_on']}");
    }

    return [
        'critical' => $scenarioIds !== ['none'],
        'id' => $id,
    ];
}

/**
 * @param list<string> $arguments
 * @return array{profile: string, registry: string}
 */
function parse_arguments(array $arguments): array
{
    $profile = 'pr';

    if (isset($arguments[0]) && strncmp($arguments[0], '--profile=', 10) === 0) {
        $profile = substr(array_shift($arguments), 10);
    }

    if (! in_array($profile, ['pr', 'rc'], true)) {
        throw new RuntimeException("Unknown test exception profile: {$profile}");
    }

    if (count($arguments) !== 1) {
        throw new RuntimeException(
            'Usage: php docker/check-test-exceptions.php [--profile=pr|rc] <registry.json>'
        );
    }

    return [
        'profile' => $profile,
        'registry' => $arguments[0],
    ];
}

try {
    $parsedArguments = parse_arguments(array_slice($argv, 1));
    $profile = $parsedArguments['profile'];
    $exceptions = read_exception_registry($parsedArguments['registry']);
    $today = gmdate('Y-m-d');
    $seenIds = [];
    $criticalIds = [];
    $nonCriticalIds = [];

    foreach ($exceptions as $index => $exception) {
        // JSON objects decode as non-list arrays. Empty arrays are not records.
        if (! is_array($exception) || $exception === [] || array_is_list($exception)) {
            throw new RuntimeException("Exception record {$index} must be a JSON object");
        }

        $validated = validate_exception($exception, $index, $seenIds, $today);
        if ($validated['critical']) {
            $criticalIds[] = $validated['id'];
        } else {
            $nonCriticalIds[] = $validated['id'];
        }
    }

    printf(
        "Test exception policy (%s profile): %d active (%d critical, %d non-critical)\n",
        strtoupper($profile),
        count($exceptions),
        count($criticalIds),
        count($nonCriticalIds)
    );

    if ($profile === 'rc' && $criticalIds !== []) {
        fwrite(
            STDERR,
            'Release candidate blocked by active critical exceptions: ' . implode(', ', $criticalIds) . "\n"
        );
        exit(EXIT_POLICY_FAILURE);
    }

    if ($profile === 'rc' && $nonCriticalIds !== []) {
        printf(
            "Release-owner review required for non-critical exceptions: %s\n",
            implode(', ', $nonCriticalIds)
        );
    }

    exit(EXIT_PASSED);
} catch (Throwable $exception) {
    fwrite(STDERR, "Test exception configuration error: {$exception->getMessage()}\n");
    exit(EXIT_INVALID_INPUT);
}
