<?php

declare(strict_types=1);

namespace Drupal\markaspot_sso\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Psr\Log\LoggerInterface;

/**
 * Links validated SSO identities to Drupal users and tenant memberships.
 */
final class SsoIdentityLinker {
  private const PRIVILEGED_ROLES = [
    'administrator',
    'tenant_admin',
    'editorial_board',
    'moderator',
    'api_editor',
  ];
  private const ASSURANCE_RANKS = [
    'low' => 1,
    'normal' => 1,
    'substantial' => 2,
    'hoch' => 3,
    'high' => 3,
  ];

  /**
   * Constructs the SSO authenticator.
   */
  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly SsoGroupMembershipService $groupMembership,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {
  }

  /**
   * Authenticates a validated SSO subject.
   *
   * @param string $provider_id
   *   Provider machine name.
   * @param array<string, mixed> $provider
   *   Provider configuration.
   * @param string $name_id
   *   SSO NameID.
   * @param array<string, array<int, mixed>> $attributes
   *   SSO attributes keyed by name.
   * @param array<string, array<int, mixed>> $friendly_attributes
   *   SSO attributes keyed by FriendlyName.
   *
   * @return array<string, mixed>
   *   Authenticated user response.
   */
  public function authenticate(
    string $provider_id,
    array $provider,
    string $name_id,
    array $attributes,
    array $friendly_attributes,
  ): array {
    $subject_hash = $this->subjectHash($provider_id, $name_id);
    $mapped = $this->mapAttributes($provider, $attributes, $friendly_attributes);
    $assurance_level = $mapped['assurance_level'] ?? NULL;
    $this->assertAssuranceLevel($provider, $assurance_level);

    $user = $this->loadUserForSubject($provider_id, $subject_hash);
    $existing_identity = $user instanceof UserInterface;
    if (!$user instanceof UserInterface) {
      $user = $this->resolveOrCreateUser($provider_id, $subject_hash, $provider, $mapped);
    }

    try {
      user_login_finalize($user);
      $groups = $this->groupMembership->apply($user, $provider, $existing_identity);
      if ($existing_identity) {
        $this->updateIdentity($provider_id, $subject_hash, $assurance_level);
      }
      else {
        $this->storeIdentity($provider_id, $subject_hash, (int) $user->id(), $assurance_level);
      }
    }
    catch (\Throwable $exception) {
      user_logout();
      throw $exception;
    }

    $this->logger->info('SSO login for provider @provider linked to uid @uid.', [
      '@provider' => $provider_id,
      '@uid' => $user->id(),
    ]);

    return [
      'uid' => (int) $user->id(),
      'name' => $user->getAccountName(),
      'email' => $user->getEmail(),
      'roles' => $user->getRoles(),
      'groups' => $groups,
      'auth_provider' => $provider_id,
      'assurance_level' => $assurance_level,
    ];
  }

  /**
   * Maps SSO attributes to canonical user keys.
   *
   * @param array<string, mixed> $provider
   *   Provider configuration.
   * @param array<string, array<int, mixed>> $attributes
   *   Attributes keyed by SSO attribute name.
   * @param array<string, array<int, mixed>> $friendly_attributes
   *   Attributes keyed by FriendlyName.
   *
   * @return array<string, string>
   *   Canonical mapped values.
   */
  public function mapAttributes(array $provider, array $attributes, array $friendly_attributes = []): array {
    $map = is_array($provider['attribute_map'] ?? NULL) ? $provider['attribute_map'] : [];
    $result = [];
    foreach ($map as $target => $names) {
      if (!is_array($names)) {
        continue;
      }
      $value = $this->firstAttributeValue($names, $attributes, $friendly_attributes);
      if ($value !== NULL && $value !== '') {
        $result[(string) $target] = $value;
      }
    }

    if (empty($result['full_name'])) {
      $full_name = trim(($result['first_name'] ?? '') . ' ' . ($result['last_name'] ?? ''));
      if ($full_name !== '') {
        $result['full_name'] = $full_name;
      }
    }

    if (!empty($result['email']) && !filter_var($result['email'], FILTER_VALIDATE_EMAIL)) {
      unset($result['email']);
    }

    return $result;
  }

  /**
   * Resolves an existing identity mapping.
   */
  private function loadUserForSubject(string $provider_id, string $subject_hash): ?UserInterface {
    $uid = $this->database->select('markaspot_sso_identities', 'i')
      ->fields('i', ['uid'])
      ->condition('provider', $provider_id)
      ->condition('subject_hash', $subject_hash)
      ->range(0, 1)
      ->execute()
      ->fetchField();
    if (!$uid) {
      return NULL;
    }

    $user = $this->entityTypeManager->getStorage('user')->load((int) $uid);
    return $user instanceof UserInterface && $user->isActive() ? $user : NULL;
  }

  /**
   * Resolves by email when allowed, otherwise creates an identity-scoped user.
   *
   * @param string $provider_id
   *   Provider machine name.
   * @param string $subject_hash
   *   Hashed external subject.
   * @param array<string, mixed> $provider
   *   Provider configuration.
   * @param array<string, string> $mapped
   *   Mapped attributes.
   */
  private function resolveOrCreateUser(
    string $provider_id,
    string $subject_hash,
    array $provider,
    array $mapped,
  ): UserInterface {
    $email = $mapped['email'] ?? NULL;
    $link_by_email = (bool) ($provider['link_by_email'] ?? FALSE);
    if ($link_by_email && $email !== NULL) {
      $existing = $this->loadUserByMail($email);
      if ($existing instanceof UserInterface && $existing->isActive()) {
        $this->assertEmailLinkAllowed($existing);
        return $existing;
      }
    }

    if (array_key_exists('create_users', $provider) && empty($provider['create_users'])) {
      throw new \RuntimeException('SSO auto-registration is disabled for this provider.');
    }

    $username = 'sso.' . $provider_id . '.' . substr($subject_hash, 0, 16);
    $mail = $this->usableMail($provider_id, $subject_hash, $email, $link_by_email);
    $existing = $this->loadUserByName($username);
    if ($existing instanceof UserInterface && $existing->isActive()) {
      return $existing;
    }

    $user = User::create([
      'name' => $username,
      'mail' => $mail,
      'status' => 1,
      'roles' => ['authenticated'],
    ]);
    $user->save();

    return $user;
  }

  /**
   * Returns a mail address safe for a newly created SSO user.
   */
  private function usableMail(string $provider_id, string $subject_hash, ?string $email, bool $link_by_email): string {
    if ($email !== NULL) {
      $existing = $this->loadUserByMail($email);
      if (!$existing instanceof UserInterface || $link_by_email) {
        return $email;
      }
    }

    return sprintf('%s.%s@sso.local.invalid', $provider_id, substr($subject_hash, 0, 20));
  }

  /**
   * Prevents first-login email linking into privileged accounts.
   */
  private function assertEmailLinkAllowed(UserInterface $user): void {
    if ((int) $user->id() === 1 || array_intersect($user->getRoles(), self::PRIVILEGED_ROLES) !== []) {
      throw new \RuntimeException('Privileged users must be pre-linked before SSO email linking.');
    }
  }

  /**
   * Enforces an optional provider minimum assurance level.
   *
   * @param array<string, mixed> $provider
   *   Provider configuration.
   * @param string|null $assurance_level
   *   Mapped assurance level from the SSO assertion.
   */
  private function assertAssuranceLevel(array $provider, ?string $assurance_level): void {
    $minimum = $provider['minimum_assurance_level'] ?? '';
    $minimum = is_scalar($minimum) ? strtolower(trim((string) $minimum)) : '';
    if ($minimum === '') {
      return;
    }

    $actual = $assurance_level !== NULL ? strtolower(trim($assurance_level)) : '';
    if ($actual === '') {
      throw new \RuntimeException('SSO assertion does not include the required assurance level.');
    }

    $minimum_rank = self::ASSURANCE_RANKS[$minimum] ?? NULL;
    $actual_rank = self::ASSURANCE_RANKS[$actual] ?? NULL;
    if ($minimum_rank !== NULL && $actual_rank !== NULL) {
      if ($actual_rank < $minimum_rank) {
        throw new \RuntimeException('SSO assurance level is too low.');
      }
      return;
    }

    if ($actual !== $minimum) {
      throw new \RuntimeException('SSO assurance level does not match the configured minimum.');
    }
  }

  /**
   * Stores a new identity mapping.
   */
  private function storeIdentity(string $provider_id, string $subject_hash, int $uid, ?string $assurance_level): void {
    $now = time();
    $this->database->merge('markaspot_sso_identities')
      ->keys([
        'provider' => $provider_id,
        'subject_hash' => $subject_hash,
      ])
      ->fields([
        'uid' => $uid,
        'assurance_level' => $assurance_level,
        'created' => $now,
        'changed' => $now,
      ])
      ->execute();
  }

  /**
   * Updates mutable identity metadata.
   */
  private function updateIdentity(string $provider_id, string $subject_hash, ?string $assurance_level): void {
    $this->database->update('markaspot_sso_identities')
      ->fields([
        'assurance_level' => $assurance_level,
        'changed' => time(),
      ])
      ->condition('provider', $provider_id)
      ->condition('subject_hash', $subject_hash)
      ->execute();
  }

  /**
   * Finds the first scalar attribute value by configured names.
   *
   * @param array<int, mixed> $names
   *   Candidate SSO attribute names.
   * @param array<string, array<int, mixed>> $attributes
   *   Attributes keyed by name.
   * @param array<string, array<int, mixed>> $friendly_attributes
   *   Attributes keyed by FriendlyName.
   */
  private function firstAttributeValue(array $names, array $attributes, array $friendly_attributes): ?string {
    foreach ($names as $name) {
      if (!is_scalar($name)) {
        continue;
      }
      $key = (string) $name;
      foreach ([$attributes[$key] ?? NULL, $friendly_attributes[$key] ?? NULL] as $values) {
        if (!is_array($values) || $values === []) {
          continue;
        }
        $value = reset($values);
        if (is_scalar($value) && trim((string) $value) !== '') {
          return trim((string) $value);
        }
      }
    }

    return NULL;
  }

  /**
   * Hashes the external subject without storing raw NameIDs.
   */
  private function subjectHash(string $provider_id, string $name_id): string {
    try {
      $hash_salt = Settings::getHashSalt();
    }
    catch (\RuntimeException $exception) {
      throw new \RuntimeException('SSO identity hashing requires a private Drupal hash salt.', 0, $exception);
    }
    if (!is_string($hash_salt) || trim($hash_salt) === '') {
      throw new \RuntimeException('SSO identity hashing requires a private Drupal hash salt.');
    }

    return hash_hmac(
      'sha256',
      $provider_id . "\n" . $name_id,
      $hash_salt,
    );
  }

  /**
   * Loads a user by email.
   */
  private function loadUserByMail(string $email): ?UserInterface {
    $users = $this->entityTypeManager->getStorage('user')->loadByProperties(['mail' => $email]);
    $user = reset($users);
    return $user instanceof UserInterface ? $user : NULL;
  }

  /**
   * Loads a user by account name.
   */
  private function loadUserByName(string $name): ?UserInterface {
    $users = $this->entityTypeManager->getStorage('user')->loadByProperties(['name' => $name]);
    $user = reset($users);
    return $user instanceof UserInterface ? $user : NULL;
  }

}
