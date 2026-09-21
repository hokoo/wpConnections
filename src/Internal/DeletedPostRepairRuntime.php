<?php

namespace iTRON\wpConnections\Internal;

use iTRON\wpConnections\Client;
use iTRON\wpHooksDispatcher\ActionDispatcher;
use iTRON\wpHooksDispatcher\ActionSubscription;
use iTRON\wpHooksDispatcher\Contracts\SiteContextProvider;
use iTRON\wpHooksDispatcher\SiteContext;
use iTRON\wpHooksDispatcher\WordPressSiteContextProvider;
use LogicException;
use Throwable;

/**
 * @internal Process-wide composition root for site-scoped repair delivery.
 */
final class DeletedPostRepairRuntime
{
    private static ?self $instance = null;

    private SiteContextProvider $contexts;
    private ActionDispatcher $dispatcher;
    private DeletedPostRepairClientRegistry $registry;

    /**
     * @var array<string, array{
     *     context: SiteContext,
     *     ledger: DeletedPostRepairLedger,
     *     policy: DeletedPostRepairPolicy,
     *     executor: DeletedPostRepairExecutor,
     *     reconciler: DeletedPostRepairReconciler,
     *     worker: DeletedPostRepairWorker,
     *     cron_gateway: DeletedPostRepairCronGateway,
     *     cron_subscription: ActionSubscription
     * }>
     */
    private array $sites = [];

    /** @var array<int, DeletedPostRepairClientActivation> */
    private array $activations = [];

    private function __construct()
    {
        $this->contexts = new WordPressSiteContextProvider();
        $this->dispatcher = new ActionDispatcher(null, $this->contexts);
        $this->registry = $this->newRegistry();
    }

    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function activate(
        Client $client,
        bool $automaticEnabled
    ): DeletedPostRepairClientActivation {
        $clientId = spl_object_id($client);
        $existing = $this->activations[ $clientId ] ?? null;
        if (null !== $existing && $existing->owns($client)) {
            if ($automaticEnabled) {
                $existing->enable();
            } else {
                $existing->disable();
            }

            return $existing;
        }

        $context = $this->contexts->current();
        $client->assertDeletedPostRepairContext(
            $context->blogId(),
            $context->databasePrefix()
        );
        $site = $this->site($context);
        $registration = $this->registry->register($client, false);
        $activation = new DeletedPostRepairClientActivation(
            $client,
            $registration,
            $this->dispatcher,
            new DeletedPostRepairCoordinator(
                $client,
                $site['ledger'],
                $site['executor'],
                $site['policy'],
                $this->contexts,
                $site['reconciler']
            ),
            [ $site['ledger'], 'ensureReady' ]
        );

        try {
            if ($automaticEnabled) {
                $activation->enable();
            }
            $this->activations[ $clientId ] = $activation;

            return $activation;
        } catch (Throwable $failure) {
            $activation->revoke();
            throw $failure;
        }
    }

    /**
     * @internal Temporary teardown seam until LIFE-HOOK-01 adds Client::dispose().
     */
    public function deactivateClient(Client $client): void
    {
        $clientId = spl_object_id($client);
        $activation = $this->activations[ $clientId ] ?? null;
        if (null === $activation || ! $activation->owns($client)) {
            return;
        }

        $activation->revoke();
        unset($this->activations[ $clientId ]);
    }

    /**
     * @internal Test suites reset WordPress's global hook registry between cases.
     */
    public function resetForTests(): void
    {
        foreach ($this->activations as $activation) {
            $activation->revoke();
        }
        foreach ($this->sites as $site) {
            $site['cron_subscription']->unsubscribe();
        }

        $this->activations = [];
        $this->sites = [];
        $this->registry = $this->newRegistry();
    }

    private function reconcile(SiteContext $context): void
    {
        $this->assertCurrentContext($context);
        $this->site($context)['reconciler']->reconcile();
    }

    /**
     * @return array{
     *     context: SiteContext,
     *     ledger: DeletedPostRepairLedger,
     *     policy: DeletedPostRepairPolicy,
     *     executor: DeletedPostRepairExecutor,
     *     reconciler: DeletedPostRepairReconciler,
     *     worker: DeletedPostRepairWorker,
     *     cron_gateway: DeletedPostRepairCronGateway,
     *     cron_subscription: ActionSubscription
     * }
     */
    private function site(SiteContext $context): array
    {
        $this->assertCurrentContext($context);
        $key = $this->contextKey($context);
        if (! isset($this->sites[ $key ])) {
            $ledger = new DeletedPostRepairLedger();
            $policy = new DeletedPostRepairPolicy(new SystemDeletedPostRepairClock());
            $executor = new DeletedPostRepairExecutor($ledger, $policy);
            $reconciler = new DeletedPostRepairReconciler(
                $ledger,
                $this->registry,
                new WordPressDeletedPostRepairScheduler(),
                $policy
            );
            $reconcile = static function () use ($reconciler): void {
                $reconciler->reconcile();
            };
            $worker = new DeletedPostRepairWorker(
                $ledger,
                $this->registry,
                $executor,
                $policy
            );
            $cronGateway = new DeletedPostRepairCronGateway($worker, $reconcile);
            $cronSubscription = $this->dispatcher->subscribe(
                WordPressDeletedPostRepairScheduler::EVENT_HOOK,
                [ $cronGateway, 'run' ],
                10,
                0
            );
            $this->sites[ $key ] = [
                'context' => $context,
                'ledger' => $ledger,
                'policy' => $policy,
                'executor' => $executor,
                'reconciler' => $reconciler,
                'worker' => $worker,
                'cron_gateway' => $cronGateway,
                'cron_subscription' => $cronSubscription,
            ];
        }

        return $this->sites[ $key ];
    }

    private function assertCurrentContext(SiteContext $expected): void
    {
        if (! $expected->equals($this->contexts->current())) {
            throw new LogicException(
                'Deleted-post repair runtime cannot operate outside its WordPress site context.'
            );
        }
    }

    private function contextKey(SiteContext $context): string
    {
        return $context->blogId() . "\0" . $context->databasePrefix();
    }

    private function newRegistry(): DeletedPostRepairClientRegistry
    {
        return new DeletedPostRepairClientRegistry(
            $this->contexts,
            function (SiteContext $context): void {
                $this->reconcile($context);
            }
        );
    }
}
