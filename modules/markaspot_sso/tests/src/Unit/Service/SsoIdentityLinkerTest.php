<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_sso\Unit\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\markaspot_sso\Service\SsoGroupMembershipService;
use Drupal\markaspot_sso\Service\SsoIdentityLinker;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests SSO identity safety guards.
 *
 * @group markaspot_sso
 */
final class SsoIdentityLinkerTest extends UnitTestCase {

  /**
   * Lower assurance levels are rejected before session finalization.
   */
  public function testRejectsTooLowAssuranceLevel(): void {
    $this->expectException(\RuntimeException::class);
    $this->invokeAssuranceGuard([
      'minimum_assurance_level' => 'high',
    ], 'low');
  }

  /**
   * Missing assurance level is rejected when a minimum is configured.
   */
  public function testRejectsMissingAssuranceLevel(): void {
    $this->expectException(\RuntimeException::class);
    $this->invokeAssuranceGuard([
      'minimum_assurance_level' => 'substantial',
    ], NULL);
  }

  /**
   * Equivalent or higher assurance levels are accepted.
   */
  public function testAllowsSufficientAssuranceLevel(): void {
    $this->invokeAssuranceGuard([
      'minimum_assurance_level' => 'substantial',
    ], 'high');

    $this->addToAssertionCount(1);
  }

  /**
   * Privileged users cannot be linked by email on first login.
   */
  public function testRejectsEmailLinkingForPrivilegedUser(): void {
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn('7');
    $user->method('getRoles')->willReturn(['authenticated', 'tenant_admin']);

    $this->expectException(\RuntimeException::class);
    $this->invokeEmailLinkGuard($user);
  }

  /**
   * UID 1 cannot be linked by email on first login.
   */
  public function testRejectsEmailLinkingForUidOne(): void {
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn('1');
    $user->method('getRoles')->willReturn(['authenticated']);

    $this->expectException(\RuntimeException::class);
    $this->invokeEmailLinkGuard($user);
  }

  /**
   * Non-privileged users remain eligible for email linking.
   */
  public function testAllowsEmailLinkingForCitizenUser(): void {
    $user = $this->createMock(UserInterface::class);
    $user->method('id')->willReturn('9');
    $user->method('getRoles')->willReturn(['authenticated']);

    $this->invokeEmailLinkGuard($user);
    $this->addToAssertionCount(1);
  }

  /**
   * Subject hashing fails closed when Drupal has no private hash salt.
   */
  public function testSubjectHashRequiresPrivateHashSalt(): void {
    new Settings([]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('SSO identity hashing requires a private Drupal hash salt.');

    $this->invokeSubjectHash('keycloak', 'subject-1');
  }

  /**
   * Subject hashes are stable and scoped by provider and external subject.
   */
  public function testSubjectHashIsStableAndProviderScoped(): void {
    new Settings(['hash_salt' => 'markaspot-sso-private-test-salt']);

    $hash = $this->invokeSubjectHash('keycloak', 'subject-1');

    $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
    $this->assertSame($hash, $this->invokeSubjectHash('keycloak', 'subject-1'));
    $this->assertNotSame($hash, $this->invokeSubjectHash('adfs', 'subject-1'));
    $this->assertNotSame($hash, $this->invokeSubjectHash('keycloak', 'subject-2'));
  }

  /**
   * Invokes the assurance guard.
   *
   * @param array<string, mixed> $provider
   *   Provider config.
   * @param string|null $assuranceLevel
   *   Assurance level value.
   */
  private function invokeAssuranceGuard(array $provider, ?string $assuranceLevel): void {
    $method = new \ReflectionMethod(SsoIdentityLinker::class, 'assertAssuranceLevel');
    $method->setAccessible(TRUE);
    $method->invoke($this->linker(), $provider, $assuranceLevel);
  }

  /**
   * Invokes the email-link guard.
   */
  private function invokeEmailLinkGuard(UserInterface $user): void {
    $method = new \ReflectionMethod(SsoIdentityLinker::class, 'assertEmailLinkAllowed');
    $method->setAccessible(TRUE);
    $method->invoke($this->linker(), $user);
  }

  /**
   * Invokes subject hashing.
   */
  private function invokeSubjectHash(string $providerId, string $nameId): string {
    $method = new \ReflectionMethod(SsoIdentityLinker::class, 'subjectHash');
    $method->setAccessible(TRUE);
    return (string) $method->invoke($this->linker(), $providerId, $nameId);
  }

  /**
   * Builds the identity linker with unused collaborators mocked.
   */
  private function linker(): SsoIdentityLinker {
    $groupMembership = new SsoGroupMembershipService(
          $this->createMock(EntityTypeManagerInterface::class),
          $this->createMock(ConfigFactoryInterface::class),
          $this->createMock(LoggerInterface::class),
      );

    return new SsoIdentityLinker(
          $this->createMock(Connection::class),
          $this->createMock(EntityTypeManagerInterface::class),
          $groupMembership,
          $this->createMock(ConfigFactoryInterface::class),
          $this->createMock(LoggerInterface::class),
      );
  }

}
