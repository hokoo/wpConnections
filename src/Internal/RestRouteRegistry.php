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
     * @var array<string, array{subscription: ActionSubscription, owners: array<string, bool>}>
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
        $delegateId = spl_object_id($delegate);
        if (isset($this->delegateRegistrations[ $delegateId ])) {
            return $this->delegateRegistrations[ $delegateId ];
        }

        $context = $this->contexts->current();
        $contextKey = $this->contextKey($context);
        $ownerKey = $this->ownerKey($context, $delegate->getClient()->getName());

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
        $delegateId = spl_object_id($delegate);
        if (! isset($this->delegateRegistrations[ $delegateId ])) {
            return;
        }

        global $wp_rest_server;
        if ($wp_rest_server instanceof WP_REST_Server) {
            $this->registerAllRoutes($wp_rest_server);
        }
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

        $subscription = $this->dispatcher->subscribe(
            'rest_api_init',
            function ($server): void {
                if ($server instanceof WP_REST_Server) {
                    $this->registerAllRoutes($server);
                }
            },
            10,
            1
        );

        $this->contextSubscriptions[ $contextKey ] = [
            'subscription' => $subscription,
            'owners'       => [],
        ];

        return true;
    }

    private function releaseEmptyContextSubscription(string $contextKey): void
    {
        $contextSubscription = $this->contextSubscriptions[ $contextKey ] ?? null;
        if (null === $contextSubscription || ! empty($contextSubscription['owners'])) {
            return;
        }

        $contextSubscription['subscription']->unsubscribe();
        unset($this->contextSubscriptions[ $contextKey ]);
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
