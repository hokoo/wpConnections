<?php

namespace iTRON\wpConnections\Tests;

use iTRON\wpConnections\Client;
use iTRON\wpConnections\Exceptions\ClientRegisterFail;
use iTRON\wpConnections\Internal\DeletedPostRepairClientRegistry;
use iTRON\wpHooksDispatcher\Contracts\SiteContextProvider;
use iTRON\wpHooksDispatcher\SiteContext;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DeletedPostRepairRegistryContextProvider implements SiteContextProvider
{
    private SiteContext $context;

    public function __construct(SiteContext $context)
    {
        $this->context = $context;
    }

    public function current(): SiteContext
    {
        return $this->context;
    }

    public function switchTo(SiteContext $context): void
    {
        $this->context = $context;
    }
}

final class DeletedPostRepairRegistryClient extends Client
{
    private string $testName;

    public function __construct(string $name, int $siteId, string $sitePrefix)
    {
        $this->testName = $name;
        DeletedPostRepairTestClientContext::initialize($this, $siteId, $sitePrefix);
    }

    public function getName(): string
    {
        return $this->testName;
    }
}

final class DeletedPostRepairClientRegistryTest extends TestCase
{
    private SiteContext $siteA;
    private SiteContext $siteB;
    private SiteContext $siteASameBlogDifferentPrefix;
    private DeletedPostRepairRegistryContextProvider $contexts;
    private array $reconciliationSignals;

    protected function setUp(): void
    {
        parent::setUp();

        $this->siteA = new SiteContext(1, 'wp_');
        $this->siteB = new SiteContext(2, 'wp_2_');
        $this->siteASameBlogDifferentPrefix = new SiteContext(1, 'tenant_');
        $this->contexts = new DeletedPostRepairRegistryContextProvider($this->siteA);
        $this->reconciliationSignals = [];
    }

    public function test_same_client_registration_is_idempotent_and_initial_eligibility_signals_once(): void
    {
        $registry = $this->registry();
        $client = $this->client('alpha');

        $first = $registry->register($client);
        $second = $registry->register($client);

        self::assertSame($first, $second);
        self::assertTrue($first->isActive());
        self::assertTrue($first->isAutomaticEnabled());
        self::assertSame($client, $registry->resolve('alpha'));
        self::assertSame($client, $registry->resolveAutomatically('alpha'));
        self::assertSame([ 'alpha' ], $registry->getAutomaticallyEligibleClientNames());
        self::assertSame([ [ 1, 'wp_' ] ], $this->reconciliationSignals);
    }

    public function test_distinct_live_client_for_the_same_site_and_name_is_rejected(): void
    {
        $registry = $this->registry();
        $first = $this->client('duplicate');
        $second = $this->client('duplicate');
        $registry->register($first);

        try {
            $registry->register($second);
            self::fail('A second live owner must be rejected.');
        } catch (ClientRegisterFail $failure) {
            self::assertStringContainsString('already registered', $failure->getMessage());
        }

        self::assertSame($first, $registry->resolve('duplicate'));
        self::assertSame([ 'duplicate' ], $registry->getAutomaticallyEligibleClientNames());
    }

    public function test_disable_preserves_manual_resolution_and_reenable_emits_one_signal(): void
    {
        $registry = $this->registry();
        $client = $this->client('toggle');
        $registration = $registry->register($client);
        $this->reconciliationSignals = [];

        self::assertTrue($registration->disableAutomatic());
        self::assertFalse($registration->disableAutomatic());
        self::assertFalse($registration->isAutomaticEnabled());
        self::assertSame($client, $registry->resolve('toggle'));
        self::assertNull($registry->resolveAutomatically('toggle'));
        self::assertSame([], $registry->getAutomaticallyEligibleClientNames());
        self::assertSame([], $this->reconciliationSignals);

        self::assertTrue($registration->enableAutomatic());
        self::assertFalse($registration->enableAutomatic());
        self::assertSame($client, $registry->resolveAutomatically('toggle'));
        self::assertSame([ [ 1, 'wp_' ] ], $this->reconciliationSignals);
    }

    public function test_same_name_on_distinct_site_contexts_resolves_independently(): void
    {
        $registry = $this->registry();
        $siteAClient = $this->client('shared');
        $registry->register($siteAClient);

        $this->contexts->switchTo($this->siteB);
        $siteBClient = $this->client('shared');
        $registry->register($siteBClient);
        self::assertSame($siteBClient, $registry->resolve('shared'));
        self::assertSame([ 'shared' ], $registry->getAutomaticallyEligibleClientNames());

        $this->contexts->switchTo($this->siteA);
        self::assertSame($siteAClient, $registry->resolve('shared'));
        self::assertSame([ 'shared' ], $registry->getAutomaticallyEligibleClientNames());
    }

    public function test_prefix_is_part_of_context_identity_even_when_blog_id_is_equal(): void
    {
        $registry = $this->registry();
        $siteAClient = $this->client('prefix-owner');
        $registry->register($siteAClient);

        $this->contexts->switchTo($this->siteASameBlogDifferentPrefix);
        self::assertNull($registry->resolve('prefix-owner'));
        $otherPrefixClient = $this->client('prefix-owner');
        $registry->register($otherPrefixClient);
        self::assertSame($otherPrefixClient, $registry->resolve('prefix-owner'));

        $this->contexts->switchTo($this->siteA);
        self::assertSame($siteAClient, $registry->resolve('prefix-owner'));
    }

    public function test_one_client_object_cannot_be_reused_in_another_site_context(): void
    {
        $registry = $this->registry();
        $client = $this->client('stale-object');
        $registry->register($client);
        $this->contexts->switchTo($this->siteB);

        $this->expectException(ClientRegisterFail::class);
        $registry->register($client);
    }

    public function test_first_registration_rejects_client_constructed_in_another_context(): void
    {
        $registry = $this->registry();
        $client = $this->client('stale-first-registration');
        $this->contexts->switchTo($this->siteB);

        try {
            $registry->register($client);
            self::fail('A stale Client must be rejected before its first registration.');
        } catch (ClientRegisterFail $failure) {
            self::assertStringContainsString('site context', $failure->getMessage());
        }

        self::assertNull($registry->resolve('stale-first-registration'));
        self::assertSame([], $this->reconciliationSignals);
    }

    public function test_tokenized_revoke_cannot_remove_a_replacement_owner(): void
    {
        $registry = $this->registry();
        $firstClient = $this->client('replaceable');
        $first = $registry->register($firstClient);
        $staleHandle = clone $first;
        $firstToken = $first->getToken();
        $first->revoke();

        $replacement = $this->client('replaceable');
        $replacementHandle = $registry->register($replacement);
        self::assertNotSame($firstToken, $replacementHandle->getToken());
        $staleHandle->revoke();

        self::assertSame($replacement, $registry->resolve('replaceable'));
        self::assertTrue($replacementHandle->isActive());
        self::assertTrue($replacementHandle->isAutomaticEnabled());
    }

    public function test_revocation_uses_registration_context_not_the_current_context(): void
    {
        $registry = $this->registry();
        $client = $this->client('cross-context-revoke');
        $registration = $registry->register($client);
        $this->contexts->switchTo($this->siteB);

        $registration->revoke();
        self::assertFalse($registration->isActive());

        $this->contexts->switchTo($this->siteA);
        self::assertNull($registry->resolve('cross-context-revoke'));
        self::assertSame([], $registry->getAutomaticallyEligibleClientNames());
    }

    public function test_eligibility_changes_from_a_stale_site_context_are_rejected(): void
    {
        $registry = $this->registry();
        $client = $this->client('stale-toggle');
        $registration = $registry->register($client);
        $this->contexts->switchTo($this->siteB);

        try {
            $registration->disableAutomatic();
            self::fail('A stale-context Client command must be rejected.');
        } catch (LogicException $failure) {
            self::assertStringContainsString('context', strtolower($failure->getMessage()));
        }

        $this->contexts->switchTo($this->siteA);
        self::assertTrue($registration->isAutomaticEnabled());
        self::assertSame($client, $registry->resolveAutomatically('stale-toggle'));
    }

    public function test_failed_initial_reconciliation_rolls_back_owner_and_reservation(): void
    {
        $fail = true;
        $registry = new DeletedPostRepairClientRegistry(
            $this->contexts,
            static function () use (&$fail): void {
                if ($fail) {
                    throw new RuntimeException('reconciliation signal failed');
                }
            }
        );
        $first = $this->client('rollback');

        try {
            $registry->register($first);
            self::fail('Failed activation must propagate.');
        } catch (RuntimeException $failure) {
            self::assertSame('reconciliation signal failed', $failure->getMessage());
        }

        self::assertNull($registry->resolve('rollback'));
        self::assertSame([], $registry->getAutomaticallyEligibleClientNames());
        $fail = false;
        $replacement = $this->client('rollback');
        $registration = $registry->register($replacement);
        self::assertTrue($registration->isActive());
        self::assertSame($replacement, $registry->resolve('rollback'));
    }

    public function test_failed_reenable_reverts_to_manual_only_registration(): void
    {
        $fail = false;
        $registry = new DeletedPostRepairClientRegistry(
            $this->contexts,
            static function () use (&$fail): void {
                if ($fail) {
                    throw new RuntimeException('re-enable signal failed');
                }
            }
        );
        $client = $this->client('manual-fallback');
        $registration = $registry->register($client, false);
        $fail = true;

        try {
            $registration->enableAutomatic();
            self::fail('Failed re-enable signal must propagate.');
        } catch (RuntimeException $failure) {
            self::assertSame('re-enable signal failed', $failure->getMessage());
        }

        self::assertTrue($registration->isActive());
        self::assertFalse($registration->isAutomaticEnabled());
        self::assertSame($client, $registry->resolve('manual-fallback'));
        self::assertNull($registry->resolveAutomatically('manual-fallback'));
    }

    public function test_failed_reenable_after_revocation_does_not_recreate_a_ghost_owner(): void
    {
        $registration = null;
        $registry = new DeletedPostRepairClientRegistry(
            $this->contexts,
            static function () use (&$registration): void {
                $registration->revoke();
                throw new RuntimeException('re-enable signal failed after revocation');
            }
        );
        $registration = $registry->register($this->client('revoked-reenable'), false);

        try {
            $registration->enableAutomatic();
            self::fail('Failed re-enable after revocation must propagate.');
        } catch (RuntimeException $failure) {
            self::assertSame('re-enable signal failed after revocation', $failure->getMessage());
        }

        self::assertFalse($registration->isActive());
        $replacement = $this->client('revoked-reenable');
        $replacementRegistration = $registry->register($replacement, false);
        self::assertTrue($replacementRegistration->isActive());
        self::assertSame($replacement, $registry->resolve('revoked-reenable'));
    }

    public function test_failed_reenable_cannot_disable_a_reentrant_replacement_owner(): void
    {
        $registry = null;
        $registration = null;
        $replacementRegistration = null;
        $replacing = false;
        $replacement = $this->client('replacement-during-reenable');
        $registry = new DeletedPostRepairClientRegistry(
            $this->contexts,
            static function () use (
                &$registry,
                &$registration,
                &$replacementRegistration,
                &$replacing,
                $replacement
            ): void {
                if ($replacing) {
                    return;
                }

                $replacing = true;
                $registration->revoke();
                $replacementRegistration = $registry->register($replacement);
                throw new RuntimeException('re-enable signal failed after replacement');
            }
        );
        $registration = $registry->register(
            $this->client('replacement-during-reenable'),
            false
        );

        try {
            $registration->enableAutomatic();
            self::fail('Failed re-enable after replacement must propagate.');
        } catch (RuntimeException $failure) {
            self::assertSame('re-enable signal failed after replacement', $failure->getMessage());
        }

        self::assertFalse($registration->isActive());
        self::assertTrue($replacementRegistration->isActive());
        self::assertTrue($replacementRegistration->isAutomaticEnabled());
        self::assertSame($replacement, $registry->resolveAutomatically('replacement-during-reenable'));
    }

    /**
     * @dataProvider invalid_context_provider
     */
    public function test_invalid_site_context_fails_before_registration(int $blogId, string $prefix): void
    {
        $this->contexts->switchTo(new SiteContext($blogId, $prefix));
        $registry = $this->registry();

        $this->expectException(InvalidArgumentException::class);
        $registry->register($this->client('invalid-context'));
    }

    public function invalid_context_provider(): array
    {
        return [
            'non-positive blog' => [ 0, 'wp_' ],
            'empty prefix' => [ 1, '' ],
            'unsafe prefix' => [ 1, 'wp-unsafe_' ],
            'overlong prefix' => [ 1, str_repeat('a', 65) ],
        ];
    }

    public function test_invalid_lookup_name_fails_before_context_resolution(): void
    {
        $registry = $this->registry();

        $this->expectException(InvalidArgumentException::class);
        $registry->resolve('Not Canonical');
    }

    private function registry(): DeletedPostRepairClientRegistry
    {
        return new DeletedPostRepairClientRegistry(
            $this->contexts,
            function (SiteContext $context): void {
                $this->reconciliationSignals[] = [
                    $context->blogId(),
                    $context->databasePrefix(),
                ];
            }
        );
    }

    private function client(string $name): DeletedPostRepairRegistryClient
    {
        $context = $this->contexts->current();

        return new DeletedPostRepairRegistryClient(
            $name,
            $context->blogId(),
            $context->databasePrefix()
        );
    }
}
