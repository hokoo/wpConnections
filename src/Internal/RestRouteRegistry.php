<?php

namespace iTRON\wpConnections\Internal;

use iTRON\wpConnections\Client;
use iTRON\wpConnections\ClientRestApi;
use iTRON\wpConnections\Exceptions\ClientRegisterFail;
use iTRON\wpHooksDispatcher\ActionDispatcher;
use iTRON\wpHooksDispatcher\ActionSubscription;
use iTRON\wpHooksDispatcher\Contracts\SiteContextProvider;
use iTRON\wpHooksDispatcher\SiteContext;
use iTRON\wpHooksDispatcher\WordPressSiteContextProvider;
use Throwable;
use WP_REST_Server;

/**
 * Process-wide owner map behind context-neutral REST callbacks.
 *
 * @internal
 */
final class RestRouteRegistry
{
    private static ?self $instance = null;

    private ActionDispatcher $dispatcher;
    private SiteContextProvider $contexts;

    /**
     * @var array<string, array{
     *     route_subscription: ActionSubscription,
     *     query_subscription: ActionSubscription,
     *     owners: array<string, bool>
     * }>
     */
    private array $contextSubscriptions = [];

    /**
     * @var array<string, array{
     *     context: string,
     *     delegate: ClientRestApi,
     *     delegate_id: int,
     *     identity: RestRouteIdentity,
     *     token: int
     * }>
     */
    private array $owners = [];

    /** @var array<int, RestRouteRegistration> */
    private array $delegateRegistrations = [];

    /** @var array<string, int> */
    private array $ownerReservations = [];

    /** @var array<int, bool> */
    private array $pendingAcknowledgements = [];

    /** @var array<string, RestRouteBoundary> */
    private array $boundaries = [];

    private int $nextToken = 0;

    private function __construct()
    {
        $this->contexts = new WordPressSiteContextProvider();
        $this->dispatcher = new ActionDispatcher();
    }

    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Activates one factory-selected delegate and returns its revocable handle.
     *
     * @throws ClientRegisterFail
     */
    public function activate(ClientRestApi $delegate): RestRouteRegistration
    {
        $client = $delegate->getClient();
        $client->assertIntegrationLifecycleActive();
        $delegateId = spl_object_id($delegate);
        if (isset($this->delegateRegistrations[ $delegateId ])) {
            return $this->delegateRegistrations[ $delegateId ];
        }

        $context = $this->contexts->current();
        $contextKey = $this->contextKey($context);
        $ownerKey = $this->ownerKey($context, $client->getName());

        if (isset($this->owners[ $ownerKey ]) || isset($this->ownerReservations[ $ownerKey ])) {
            throw new ClientRegisterFail(
                'A REST API client is already registered for this WordPress site context.'
            );
        }

        $this->ownerReservations[ $ownerKey ] = $delegateId;
        $this->pendingAcknowledgements[ $delegateId ] = false;
        $registration = null;
        $contextSubscriptionCreated = false;

        try {
            $delegate->init();
            $client->assertIntegrationLifecycleActive();
            if (! $this->pendingAcknowledgements[ $delegateId ]) {
                throw new ClientRegisterFail(
                    'A custom REST API must call parent::init() to activate managed routes.'
                );
            }

            $identity = RestRouteIdentity::fromDelegate($delegate);
            $contextSubscriptionCreated = $this->ensureContextSubscription($contextKey);
            $token = ++$this->nextToken;
            $registration = new RestRouteRegistration(
                $this,
                $ownerKey,
                $contextKey,
                $delegateId,
                $token
            );

            $this->owners[ $ownerKey ] = [
                'context'     => $contextKey,
                'delegate'    => $delegate,
                'delegate_id' => $delegateId,
                'identity'    => $identity,
                'token'       => $token,
            ];
            $this->delegateRegistrations[ $delegateId ] = $registration;
            $this->contextSubscriptions[ $contextKey ]['owners'][ $ownerKey ] = true;

            global $wp_rest_server;
            if (did_action('rest_api_init') > 0 && $wp_rest_server instanceof WP_REST_Server) {
                $this->registerAllRoutes($wp_rest_server);
            }
            $client->assertIntegrationLifecycleActive();

            if (doing_action('rest_api_init')) {
                $this->normalizeRepeatedRelationSelectors(
                    $contextKey,
                    $GLOBALS['wp'] ?? null
                );
            }

            return $registration;
        } catch (Throwable $exception) {
            if ($registration instanceof RestRouteRegistration) {
                $registration->revoke();
            } elseif ($contextSubscriptionCreated) {
                $this->releaseEmptyContextSubscription($contextKey);
            }

            throw $exception;
        } finally {
            unset(
                $this->pendingAcknowledgements[ $delegateId ],
                $this->ownerReservations[ $ownerKey ]
            );
        }
    }

    /**
     * Records that a custom init() delegated to the managed base activation.
     */
    public function acknowledgeActivation(ClientRestApi $delegate): bool
    {
        $delegateId = spl_object_id($delegate);
        if (! array_key_exists($delegateId, $this->pendingAcknowledgements)) {
            return false;
        }

        $this->pendingAcknowledgements[ $delegateId ] = true;

        return true;
    }

    /**
     * Rebinds the active delegate into an already-created REST server.
     */
    public function rebind(ClientRestApi $delegate): void
    {
        $client = $delegate->getClient();
        $client->assertIntegrationLifecycleActive();
        $delegateId = spl_object_id($delegate);
        if (! isset($this->delegateRegistrations[ $delegateId ])) {
            return;
        }

        global $wp_rest_server;
        if ($wp_rest_server instanceof WP_REST_Server) {
            $this->registerAllRoutes($wp_rest_server);
        }
        $client->assertIntegrationLifecycleActive();
    }

    public function deactivateDelegate(ClientRestApi $delegate): void
    {
        $registration = $this->delegateRegistrations[ spl_object_id($delegate) ] ?? null;
        if ($registration instanceof RestRouteRegistration) {
            $registration->revoke();
        }
    }

    public function deactivateClient(Client $client): void
    {
        $registrations = [];
        foreach ($this->owners as $owner) {
            if ($owner['delegate']->getClient() === $client) {
                $registrations [] = $this->delegateRegistrations[ $owner['delegate_id'] ] ?? null;
            }
        }

        foreach ($registrations as $registration) {
            if ($registration instanceof RestRouteRegistration) {
                $registration->revoke();
            }
        }
    }

    /**
     * @return array{delegate: ClientRestApi, token: int}|null
     */
    public function resolve(RestRouteIdentity $identity): ?array
    {
        $context = $this->contexts->current();
        $ownerKey = $this->ownerKey($context, $identity->getClientName());
        $owner = $this->owners[ $ownerKey ] ?? null;

        if (null === $owner || ! $owner['identity']->equals($identity)) {
            return null;
        }

        return [
            'delegate' => $owner['delegate'],
            'token'    => $owner['token'],
        ];
    }

    public function revoke(
        string $ownerKey,
        string $contextKey,
        int $delegateId,
        int $token
    ): void {
        $owner = $this->owners[ $ownerKey ] ?? null;
        if (
            null === $owner ||
            $owner['delegate_id'] !== $delegateId ||
            $owner['token'] !== $token
        ) {
            return;
        }

        unset($this->owners[ $ownerKey ], $this->delegateRegistrations[ $delegateId ]);
        unset($this->contextSubscriptions[ $contextKey ]['owners'][ $ownerKey ]);
        $this->releaseEmptyContextSubscription($contextKey);
    }

    private function ensureContextSubscription(string $contextKey): bool
    {
        if (isset($this->contextSubscriptions[ $contextKey ])) {
            return false;
        }

        $routeSubscription = $this->dispatcher->subscribe(
            'rest_api_init',
            function ($server): void {
                if ($server instanceof WP_REST_Server) {
                    $this->registerAllRoutes($server);
                }
            },
            10,
            1
        );
        try {
            $querySubscription = $this->dispatcher->subscribe(
                'parse_request',
                function ($wp) use ($contextKey): void {
                    $this->normalizeRepeatedRelationSelectors($contextKey, $wp);
                },
                9,
                1
            );
        } catch (Throwable $exception) {
            $routeSubscription->unsubscribe();
            throw $exception;
        }

        $this->contextSubscriptions[ $contextKey ] = [
            'route_subscription' => $routeSubscription,
            'query_subscription' => $querySubscription,
            'owners'             => [],
        ];

        return true;
    }

    private function releaseEmptyContextSubscription(string $contextKey): void
    {
        $contextSubscription = $this->contextSubscriptions[ $contextKey ] ?? null;
        if (null === $contextSubscription || ! empty($contextSubscription['owners'])) {
            return;
        }

        $contextSubscription['query_subscription']->unsubscribe();
        $contextSubscription['route_subscription']->unsubscribe();
        unset($this->contextSubscriptions[ $contextKey ]);
    }

    /**
     * Restores repeated selector values that PHP collapsed before WordPress
     * builds its REST request, allowing native route validation to reject them.
     *
     * @param mixed $wp
     */
    private function normalizeRepeatedRelationSelectors(string $contextKey, $wp): void
    {
        if (
            'GET' !== strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''))
            || ! is_object($wp)
            || ! isset($wp->query_vars['rest_route'])
            || ! is_string($wp->query_vars['rest_route'])
        ) {
            return;
        }

        $route = untrailingslashit($wp->query_vars['rest_route']);
        if (! $this->isOwnedRelationRoute($contextKey, $route)) {
            return;
        }

        $rawQuery = $_SERVER['QUERY_STRING'] ?? '';
        if (! is_string($rawQuery) || '' === $rawQuery) {
            return;
        }

        $values = [
            'from' => [],
            'to'   => [],
            'both' => [],
        ];
        foreach ($this->splitRawQuery($rawQuery) as $component) {
            $parameters = $this->parseRawQueryComponent($component);
            foreach (array_keys($values) as $selector) {
                if (array_key_exists($selector, $parameters)) {
                    $values[ $selector ][] = $parameters[ $selector ];
                }
            }
        }

        foreach ($values as $selector => $selectorValues) {
            if (1 < count($selectorValues)) {
                $_GET[ $selector ] = $selectorValues;
            }
        }
    }

    /**
     * Uses PHP's query-key rules while containing warnings from malformed or
     * over-nested external keys to this inspection pass.
     *
     * @return array<string, mixed>
     */
    private function parseRawQueryComponent(string $component): array
    {
        $parameters = [];
        set_error_handler(
            static function (): bool {
                return true;
            },
            E_WARNING
        );

        try {
            parse_str($component, $parameters);
        } finally {
            restore_error_handler();
        }

        return $parameters;
    }

    private function isOwnedRelationRoute(string $contextKey, string $route): bool
    {
        foreach ($this->owners as $owner) {
            if ($contextKey !== $owner['context']) {
                continue;
            }

            $identity = $owner['identity'];
            $pattern = '/' . $identity->getNamespace() . $identity->getRelationRoute();
            if (1 === preg_match('@^' . str_replace('@', '\\@', $pattern) . '$@i', $route)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    private function splitRawQuery(string $rawQuery): array
    {
        $separators = (string) ini_get('arg_separator.input');
        if ('' === $separators) {
            $separators = '&';
        }

        return preg_split(
            '/[' . preg_quote($separators, '/') . ']/',
            $rawQuery
        ) ?: [];
    }

    private function registerAllRoutes(WP_REST_Server $server): void
    {
        $identities = [];
        foreach ($this->owners as $owner) {
            $identities[ $owner['identity']->getKey() ] = $owner['identity'];
        }

        foreach ($identities as $identity) {
            $boundary = $this->boundaries[ $identity->getKey() ] ?? null;
            if (! $boundary instanceof RestRouteBoundary) {
                $boundary = new RestRouteBoundary($this, $identity);
                $this->boundaries[ $identity->getKey() ] = $boundary;
            }

            RestRouteRegistrar::register($server, $identity, $boundary);
        }
    }

    private function contextKey(SiteContext $context): string
    {
        return $context->blogId() . "\0" . $context->databasePrefix();
    }

    private function ownerKey(SiteContext $context, string $clientName): string
    {
        return $this->contextKey($context) . "\0" . $clientName;
    }
}
