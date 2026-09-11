<?php

declare(strict_types=1);

namespace Drupal\markaspot_tenant_import\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Password\PasswordGeneratorInterface;
use Drupal\group\Entity\GroupMembership;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_tenant_import\Exception\TenantImportValidationException;
use Drupal\markaspot_group\MembershipRoleNormalizer;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\user\UserInterface;

/**
 * Plans and applies versioned tenant configuration imports.
 */
final class TenantImporter {

  /**
   * Supported top-level import sections.
   */
  public const SECTIONS = [
    'organisations',
    'categories',
    'statuses',
    'users',
  ];

  /**
   * Constructs a TenantImporter object.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly JurisdictionHierarchyResolverInterface $hierarchyResolver,
    private readonly Connection $database,
    private readonly TenantConfigValidator $validator,
    private readonly TenantImportFieldMapper $fields,
    private readonly PasswordGeneratorInterface $passwordGenerator,
    private readonly LockBackendInterface $lock,
  ) {}

  /**
   * Reads and decodes a tenant configuration file.
   *
   * @return array<string, mixed>
   *   Decoded configuration.
   */
  public function decodeFile(string $path): array {
    if (!is_file($path) || !is_readable($path)) {
      throw new TenantImportValidationException([
        sprintf('Configuration file "%s" does not exist or is not readable.', $path),
      ]);
    }
    if (filesize($path) > 10 * 1024 * 1024) {
      throw new TenantImportValidationException(['Configuration exceeds the 10 MiB size limit.']);
    }

    $contents = file_get_contents($path);
    if ($contents === FALSE) {
      throw new TenantImportValidationException([
        sprintf('Configuration file "%s" could not be read.', $path),
      ]);
    }

    try {
      $configuration = json_decode($contents, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException $exception) {
      throw new TenantImportValidationException([
        sprintf('Configuration file is not valid JSON: %s', $exception->getMessage()),
      ]);
    }

    if (!is_array($configuration)) {
      throw new TenantImportValidationException([
        'Configuration root must be a JSON object.',
      ]);
    }

    return $configuration;
  }

  /**
   * Validates, plans and optionally applies the import, locking before lookups.
   *
   * @return array{rows: array, errors: string[], created_terms: bool}
   *   Plan or application results.
   */
  public function import(
    array $configuration,
    int $jurisdictionId,
    array $skip = [],
    bool $apply = FALSE,
    bool $sendMails = FALSE,
    bool $allowCrossTenantUsers = FALSE,
  ): array {
    $skip = array_values(array_unique($skip));
    $errors = $this->validate($configuration, $skip);
    if ($sendMails && !$apply) {
      $errors[] = '--send-mails can only be used together with --apply.';
    }
    if ($errors !== []) {
      throw new TenantImportValidationException($errors);
    }
    $lockName = 'markaspot_tenant_import.tenant_import';
    if ($apply && !$this->lock->acquire($lockName, 3600.0)) {
      throw new TenantImportValidationException(['Another tenant import is already running.']);
    }
    try {
      if ($apply) {
        foreach (['group', 'taxonomy_term', 'user', 'group_relationship'] as $type) {
          $this->entityTypeManager->getStorage($type)->resetCache();
        }
      }
      $context = $this->prepareContext($configuration, $jurisdictionId, $skip, $allowCrossTenantUsers);
      $rows = $this->buildPlan($configuration, $context, $skip);
      return $apply
        ? $this->applyPlan($configuration, $context, $skip, $rows, $sendMails)
        : ['rows' => $rows, 'errors' => [], 'created_terms' => FALSE];
    }
    finally {
      if ($apply) {
        $this->lock->release($lockName);
      }
    }
  }

  /**
   * Validates the whole document, including skipped sections.
   */
  public function validate(array $configuration, array $skip = []): array {
    return $this->validator->validate($configuration, $skip);
  }

  /**
   * Prepares entity lookups and validates the installed model.
   */
  private function prepareContext(array $configuration, int $jurisdictionId, array $skip, bool $allowCrossTenantUsers): array {
    $errors = [];
    $references = $this->organisationReferences($configuration, $skip);
    $needsOrganisations = !in_array('organisations', $skip, TRUE) || $references !== [];
    $groupStorage = $this->entityTypeManager->getStorage('group');
    $codeFields = [
      'organisations' => ['group', 'org', 'field_org_code'],
      'categories' => ['taxonomy_term', 'service_category', 'field_service_code'],
    ];
    foreach ($codeFields as $section => [$type, $bundle, $field]) {
      if (in_array($section, $skip, TRUE)) {
        continue;
      }
      $definitions = $this->entityFieldManager->getFieldDefinitions($type, $bundle);
      $limit = isset($definitions[$field]) ? (int) $definitions[$field]->getSetting('max_length') : 0;
      foreach ($configuration[$section] as $index => $row) {
        if ($limit > 0 && mb_strlen($row['code']) > $limit) {
          $errors[] = sprintf('%s[%d].code exceeds installed field maximum of %d characters.', $section, $index, $limit);
        }
      }
    }
    $jurisdiction = $groupStorage->load($jurisdictionId);
    if (!$jurisdiction instanceof GroupInterface) {
      throw new TenantImportValidationException([
        sprintf('Jurisdiction group %d does not exist.', $jurisdictionId),
      ]);
    }
    if ($jurisdiction->bundle() !== 'jur') {
      throw new TenantImportValidationException([
        sprintf('Group %d has type "%s"; expected "jur".', $jurisdictionId, $jurisdiction->bundle()),
      ]);
    }

    $rootId = $this->hierarchyResolver->getRootJurisdictionId($jurisdictionId);
    if ($rootId === NULL || $rootId <= 0) {
      $errors[] = sprintf('Could not resolve the root jurisdiction for group %d.', $jurisdictionId);
    }
    if ($rootId !== NULL && $rootId !== $jurisdictionId
      && (!in_array('organisations', $skip, TRUE)
        || !in_array('categories', $skip, TRUE)
        || !in_array('statuses', $skip, TRUE)
        || (!in_array('users', $skip, TRUE) && in_array('users', array_merge(...array_values($references ?: [[]])), TRUE)))) {
      $errors[] = sprintf('Jurisdiction %d is a child of root %d. Organisations and taxonomy are root-owned; import these sections at the root to avoid modifying shared data or granting root memberships implicitly.', $jurisdictionId, $rootId);
    }

    foreach ([
      'categories' => 'field_service_categories',
      'statuses' => 'field_service_statuses',
    ] as $section => $field) {
      if (!in_array($section, $skip, TRUE) && !$jurisdiction->hasField($field)) {
        $errors[] = sprintf('Target jurisdiction is missing required field %s.', $field);
      }
    }

    $termDefinitions = [
      'service_category' => [
        'field_jurisdiction',
        'field_service_code',
        'field_category_gid',
        'field_category_hex',
        'field_category_icon',
        'field_service_definition',
      ],
      'service_status' => [
        'field_jurisdiction',
        'field_status_hex',
        'field_status_icon',
        'field_open311_mapping',
        'field_notification_key',
        'field_status_definition',
      ],
    ];
    foreach ($termDefinitions as $bundle => $fields) {
      if (($bundle === 'service_category' && in_array('categories', $skip, TRUE))
        || ($bundle === 'service_status' && in_array('statuses', $skip, TRUE))) {
        continue;
      }
      if (!$this->entityTypeManager->getStorage('taxonomy_vocabulary')->load($bundle)) {
        $errors[] = sprintf('Taxonomy vocabulary %s does not exist.', $bundle);
        continue;
      }
      $definitions = $this->entityFieldManager->getFieldDefinitions('taxonomy_term', $bundle);
      foreach ($fields as $field) {
        if (!isset($definitions[$field])) {
          $errors[] = sprintf('Taxonomy bundle %s is missing required field %s.', $bundle, $field);
        }
      }
    }

    if ($needsOrganisations) {
      $definitions = $this->entityFieldManager->getFieldDefinitions('group', 'org');
      foreach ([
        'field_org_code',
        'field_jurisdiction',
        'field_parent_org',
        'field_head_organisation_e_mail',
        'field_service_categories',
      ] as $field) {
        if (!isset($definitions[$field])) {
          $errors[] = sprintf('Organisation groups are missing required field %s.', $field);
        }
      }
    }

    if (!in_array('users', $skip, TRUE)) {
      $roleStorage = $this->entityTypeManager->getStorage('group_role');
      /** @var list<array<string, mixed>> $users */
      $users = $configuration['users'];
      $requiredRoles = [];
      foreach ($users as $user) {
        $sourceRole = (string) $user['role'];
        $requiredRoles[] = TenantConfigValidator::ROLES[$sourceRole];
        if ($sourceRole === 'tenant_admin') {
          $requiredRoles[] = 'jur-member';
          if (!$this->entityTypeManager->getStorage('user_role')->load('tenant_admin')) {
            $errors[] = 'Drupal role tenant_admin is required by the existing membership sync hook.';
          }
        }
        if ($sourceRole === 'org_member') {
          $requiredRoles[] = 'org-member';
        }
      }
      foreach (array_unique($requiredRoles) as $roleId) {
        if (!$roleStorage->load($roleId)) {
          $errors[] = sprintf('Required group role %s does not exist.', $roleId);
        }
      }
    }

    if ($errors !== [] || $rootId === NULL) {
      throw new TenantImportValidationException(array_values(array_unique($errors)));
    }

    $organisationRows = $configuration['organisations'];
    $categoryRows = $configuration['categories'];
    $statusRows = $configuration['statuses'];
    $userRows = $configuration['users'];

    $organisations = [];
    foreach ($organisationRows as $row) {
      $code = (string) $row['code'];
      $organisations[mb_strtolower($code)] = $needsOrganisations
        ? $this->loadUniqueOrganisation($code, $rootId)
        : NULL;
    }
    $categories = [];
    foreach ($categoryRows as $row) {
      $code = (string) $row['code'];
      $categories[mb_strtolower($code)] = in_array('categories', $skip, TRUE)
        ? NULL
        : $this->loadUniqueTerm(
          'service_category',
          'field_service_code',
          $code,
          $rootId,
        );
    }
    $statuses = [];
    foreach ($statusRows as $row) {
      $name = (string) $row['name'];
      $statuses[mb_strtolower($name)] = in_array('statuses', $skip, TRUE)
        ? NULL
        : $this->loadUniqueTerm(
          'service_status',
          'name',
          $name,
          $rootId,
        );
    }
    $users = [];
    $userNotices = [];
    foreach ($userRows as $row) {
      $email = (string) $row['email'];
      $users[mb_strtolower($email)] = in_array('users', $skip, TRUE)
        ? NULL
        : $this->loadUserByEmail($email);
      $existing = $users[mb_strtolower($email)];
      if ($existing instanceof UserInterface) {
        $otherJurisdictions = $this->otherJurisdictions($existing, $rootId);
        $privileged = (int) $existing->id() === 1 || $existing->hasRole('administrator');
        if (($privileged || $otherJurisdictions > 0) && !$allowCrossTenantUsers) {
          $errors[] = sprintf('User "%s" is privileged or a member of jurisdictions outside target root %d; use --allow-cross-tenant-users only after review.', $email, $rootId);
        }
        if ($allowCrossTenantUsers) {
          $userNotices[mb_strtolower($email)] = sprintf(' member of %d other jurisdictions.%s', $otherJurisdictions, $privileged ? ' Privileged account explicitly allowed.' : '');
        }
      }
    }

    if (in_array('organisations', $skip, TRUE)) {
      foreach ($organisationRows as $row) {
        $code = mb_strtolower((string) $row['code']);
        if ($organisations[$code] === NULL
          && isset($references[$code])) {
          $errors[] = sprintf(
            'Organisation "%s" is required by an imported category or user but --skip=organisations prevents creating it.',
            $row['code'],
          );
        }
      }
    }
    if ($errors !== []) {
      throw new TenantImportValidationException(array_values(array_unique($errors)));
    }

    $rootCategories = in_array('categories', $skip, TRUE)
      ? []
      : $this->loadRootTerms('service_category', $rootId);
    $rootStatuses = in_array('statuses', $skip, TRUE)
      ? []
      : $this->loadRootTerms('service_status', $rootId);

    if (!in_array('statuses', $skip, TRUE)) {
      foreach ($rootStatuses as $term) {
        if ($term->get('field_open311_mapping')->getString() === 'initial'
          && !isset($statuses[mb_strtolower((string) $term->label())])) {
          $errors[] = sprintf('Existing initial status "%s" (TID %d) is omitted. Include it in statuses with its intended kind so the resulting root has exactly one initial status.', (string) $term->label(), (int) $term->id());
        }
      }
    }
    if (!in_array('categories', $skip, TRUE)) {
      $remaining = $this->selectedIdsWithInheritance($jurisdiction, 'field_service_categories', $rootCategories);
      $hasActive = FALSE;
      foreach ($categoryRows as $row) {
        if ($row['active'] === TRUE) {
          $hasActive = TRUE;
        }
        elseif (($term = $categories[mb_strtolower((string) $row['code'])]) instanceof TermInterface) {
          $remaining = array_diff($remaining, [(int) $term->id()]);
        }
      }
      if (!$hasActive && $remaining === [] && $rootCategories !== []) {
        $errors[] = 'Cannot deselect every category: an empty jurisdiction selection means inherit all categories in the current data model.';
      }
    }
    if ($errors !== []) {
      throw new TenantImportValidationException(array_values(array_unique($errors)));
    }

    return [
      'jurisdiction' => $jurisdiction,
      'root_id' => $rootId,
      'organisations' => $organisations,
      'categories' => $categories,
      'statuses' => $statuses,
      'users' => $users,
      'user_notices' => $userNotices,
      'organisation_categories' => in_array('categories', $skip, TRUE) ? []
        : $this->plannedOrganisationCategories($configuration, $organisations, $categories, $rootCategories, $jurisdiction),
      'root_categories' => $rootCategories,
      'root_statuses' => $rootStatuses,
    ];
  }

  /**
   * Builds all dry-run plan rows without changing entities.
   */
  private function buildPlan(array $configuration, array $context, array $skip): array {
    $rows = [];
    /** @var list<array<string, mixed>> $organisations */
    $organisations = $configuration['organisations'];
    /** @var list<array<string, mixed>> $categories */
    $categories = $configuration['categories'];
    /** @var list<array<string, mixed>> $statuses */
    $statuses = $configuration['statuses'];
    /** @var list<array<string, mixed>> $users */
    $users = $configuration['users'];
    /** @var array<string, GroupInterface|null> $existingOrganisations */
    $existingOrganisations = $context['organisations'];
    /** @var array<string, TermInterface|null> $existingCategories */
    $existingCategories = $context['categories'];
    /** @var array<string, TermInterface|null> $existingStatuses */
    $existingStatuses = $context['statuses'];
    /** @var array<string, UserInterface|null> $existingUsers */
    $existingUsers = $context['users'];
    /** @var GroupInterface $jurisdiction */
    $jurisdiction = $context['jurisdiction'];
    $rootId = (int) $context['root_id'];
    /** @var array<string, mixed> $tenant */
    $tenant = $configuration['tenant'];

    $targetReason = sprintf(
      'Explicit target GID %d is "%s".',
      (int) $jurisdiction->id(),
      (string) $jurisdiction->label(),
    );
    if ($jurisdiction->hasField('field_slug')) {
      $targetSlug = $jurisdiction->get('field_slug')->getString();
      $sourceSlug = (string) $tenant['slug'];
      $targetReason .= sprintf(' Target slug is "%s"; configuration slug is "%s".', $targetSlug, $sourceSlug);
      if ($targetSlug !== '' && strcasecmp($targetSlug, $sourceSlug) !== 0) {
        $targetReason .= ' Warning: slugs differ; the explicit --jurisdiction option remains authoritative.';
      }
    }
    $rows[] = $this->row(
      'jurisdiction',
      (string) $jurisdiction->id(),
      'unchanged',
      $targetReason,
    );

    foreach ($organisations as $organisation) {
      $code = (string) $organisation['code'];
      if (in_array('organisations', $skip, TRUE)) {
        $rows[] = $this->row('organisation', $code, 'skip', 'Section skipped by option.');
        continue;
      }
      $existing = $existingOrganisations[mb_strtolower($code)];
      if (!$existing instanceof GroupInterface) {
        $reason = 'Organisation does not exist in the target jurisdiction.';
        $reason .= $this->organisationTypeReason($organisation);
        $rows[] = $this->row('organisation', $code, 'create', $reason);
        continue;
      }

      $changes = [];
      if ((string) $existing->label() !== (string) $organisation['name']) {
        $changes[] = 'label';
      }
      foreach ([
        'field_org_code' => $code,
        'field_jurisdiction' => ['target_id' => $rootId],
        'field_head_organisation_e_mail' => $this->nullableString($organisation['email'] ?? NULL),
      ] as $field => $value) {
        if ($this->fieldDiffers($existing, $field, $value)) {
          $changes[] = $field;
        }
      }
      if ($this->referenceDiffers($existing, 'field_parent_org', $organisation['parent_code'] ?? NULL, $existingOrganisations)) {
        $changes[] = 'field_parent_org';
      }
      if (!in_array('categories', $skip, TRUE)
        && $this->referenceIdsDiffer($existing, 'field_service_categories', $context['organisation_categories'][mb_strtolower($code)])) {
        $changes[] = 'field_service_categories';
      }

      $reason = $changes === []
        ? 'All mapped organisation values and relationships already match.'
        : 'Changed values: ' . implode(', ', $changes) . '.';
      $reason .= $this->organisationTypeReason($organisation);
      $rows[] = $this->row('organisation', $code, $changes === [] ? 'unchanged' : 'update', $reason);
    }

    $selectedCategoryIds = in_array('categories', $skip, TRUE)
      ? []
      : $this->selectedIdsWithInheritance(
        $jurisdiction,
        'field_service_categories',
        $context['root_categories'],
      );
    foreach ($categories as $index => $category) {
      $code = (string) $category['code'];
      if (in_array('categories', $skip, TRUE)) {
        $rows[] = $this->row('category', $code, 'skip', 'Section skipped by option.');
        continue;
      }
      $existing = $existingCategories[mb_strtolower($code)];
      if (($category['active'] ?? FALSE) !== TRUE) {
        if (!$existing instanceof TermInterface) {
          $rows[] = $this->row('category', $code, 'skip', 'Inactive category is not created.');
        }
        elseif (in_array((int) $existing->id(), $selectedCategoryIds, TRUE)) {
          $rows[] = $this->row('category', $code, 'update', 'Inactive category will be removed from the jurisdiction selection; the term is retained.');
        }
        else {
          $rows[] = $this->row('category', $code, 'unchanged', 'Inactive category is already absent from the jurisdiction selection.');
        }
        continue;
      }
      if (!$existing instanceof TermInterface) {
        $rows[] = $this->row('category', $code, 'create', 'Active category does not exist in the root jurisdiction.');
        continue;
      }
      $changes = $this->categoryChanges($existing, $category, $index, $rootId, $existingCategories, $existingOrganisations);
      if (!in_array((int) $existing->id(), $selectedCategoryIds, TRUE)) {
        $changes[] = 'field_service_categories selection';
      }
      $rows[] = $this->row('category', $code, $changes === [] ? 'unchanged' : 'update', $changes === [] ? 'All category values and relationships already match.' : 'Changed values: ' . implode(', ', array_unique($changes)) . '.');
    }

    $selectedStatusIds = in_array('statuses', $skip, TRUE)
      ? []
      : $this->selectedIdsWithInheritance(
        $jurisdiction,
        'field_service_statuses',
        $context['root_statuses'],
      );
    foreach ($statuses as $status) {
      $name = (string) $status['name'];
      if (in_array('statuses', $skip, TRUE)) {
        $rows[] = $this->row('status', $name, 'skip', 'Section skipped by option.');
        continue;
      }
      $existing = $existingStatuses[mb_strtolower($name)];
      if (!$existing instanceof TermInterface) {
        $rows[] = $this->row('status', $name, 'create', 'Status does not exist in the root jurisdiction.');
        continue;
      }
      $changes = $this->statusChanges($existing, $status, $rootId);
      if (!in_array((int) $existing->id(), $selectedStatusIds, TRUE)) {
        $changes[] = 'field_service_statuses selection';
      }
      $rows[] = $this->row('status', $name, $changes === [] ? 'unchanged' : 'update', $changes === [] ? 'All status values and relationships already match.' : 'Changed values: ' . implode(', ', array_unique($changes)) . '.');
    }

    foreach ($users as $user) {
      $email = (string) $user['email'];
      if (in_array('users', $skip, TRUE)) {
        $rows[] = $this->row('user', $email, 'skip', 'Section skipped by option.');
        continue;
      }
      $existing = $existingUsers[mb_strtolower($email)];
      $notice = $context['user_notices'][mb_strtolower($email)] ?? '';
      if ($user['role'] === 'org_member') {
        $notice .= ' Form-only report visibility additionally requires org-moderator or contractor; neither is granted by this import.';
      }
      if ($existing instanceof UserInterface && $existing->isBlocked()) {
        $rows[] = $this->row('user', $email, 'skip', 'blocked, membership skipped.' . $notice);
        continue;
      }
      if (!$existing instanceof UserInterface) {
        $reason = 'User does not exist. A random password will be generated and never displayed.';
        $reason .= $this->userNameFieldReason() . $notice;
        $rows[] = $this->row('user', $email, 'create', $reason);
        continue;
      }
      $changes = $this->userChanges($existing, $user, $jurisdiction, $existingOrganisations);
      $reason = $changes === []
        ? 'User profile and required memberships already match.'
        : 'Changed values: ' . implode(', ', array_unique($changes)) . '.';
      $reason .= $this->userNameFieldReason() . $notice;
      $rows[] = $this->row('user', $email, $changes === [] ? 'unchanged' : 'update', $reason);
    }

    foreach ($this->fields->jurisdictionFieldValues($tenant) as $field => $value) {
      if (!$jurisdiction->hasField($field)) {
        $rows[] = $this->row('jurisdiction', $field, 'skip', 'Field is not installed on the target jurisdiction.');
        continue;
      }
      $changed = $this->fieldDiffers($jurisdiction, $field, $value);
      $rows[] = $this->row('jurisdiction', $field, $changed ? 'update' : 'unchanged', $changed ? 'Filled tenant value differs from the stored field.' : 'Filled tenant value already matches.');
    }

    return $rows;
  }

  /**
   * Applies a previously built plan in one database transaction.
   */
  private function applyPlan(array $configuration, array $context, array $skip, array $rows, bool $sendMails): array {
    $errors = [];
    $createdUsers = [];
    $createdTerms = FALSE;
    $transaction = $this->database->startTransaction();

    /** @var GroupInterface $jurisdiction */
    $jurisdiction = $context['jurisdiction'];
    $rootId = (int) $context['root_id'];
    $organisationRows = $configuration['organisations'];
    $categoryRows = $configuration['categories'];
    $statusRows = $configuration['statuses'];
    $userRows = $configuration['users'];
    /** @var array<string, GroupInterface|null> $organisationEntities */
    $organisationEntities = $context['organisations'];
    /** @var array<string, TermInterface|null> $categoryEntities */
    $categoryEntities = $context['categories'];
    /** @var array<string, TermInterface|null> $statusEntities */
    $statusEntities = $context['statuses'];
    /** @var array<string, UserInterface|null> $userEntities */
    $userEntities = $context['users'];

    if (!in_array('organisations', $skip, TRUE)) {
      foreach ($organisationRows as $organisation) {
        $code = (string) $organisation['code'];
        try {
          $entity = $organisationEntities[mb_strtolower($code)];
          if (!$entity instanceof GroupInterface) {
            $entity = $this->entityTypeManager->getStorage('group')->create(['type' => 'org']);
            $organisationEntities[mb_strtolower($code)] = $entity;
          }
          $changed = $entity->isNew();
          foreach ([
            'label' => $organisation['name'],
            'field_org_code' => $code,
            'field_jurisdiction' => ['target_id' => $rootId],
            'field_head_organisation_e_mail' => $this->nullableString($organisation['email'] ?? NULL),
          ] as $field => $value) {
            $changed = $this->setFieldIfChanged($entity, $field, $value) || $changed;
          }
          if ($changed) {
            $entity->save();
          }
        }
        catch (\Throwable $exception) {
          $this->recordApplyError($rows, $errors, 'organisation', $code, $exception);
          $organisationEntities[mb_strtolower($code)] = NULL;
        }
      }

      foreach ($organisationRows as $organisation) {
        $code = (string) $organisation['code'];
        $entity = $organisationEntities[mb_strtolower($code)];
        if (!$entity instanceof GroupInterface) {
          continue;
        }
        try {
          $parentCode = trim((string) ($organisation['parent_code'] ?? ''));
          $parent = $parentCode === '' ? NULL : $organisationEntities[mb_strtolower($parentCode)];
          if ($parentCode !== '' && !$parent instanceof GroupInterface) {
            throw new \RuntimeException(sprintf('Parent organisation "%s" is unavailable.', $parentCode));
          }
          $value = $parent instanceof GroupInterface ? ['target_id' => (int) $parent->id()] : NULL;
          if ($this->setFieldIfChanged($entity, 'field_parent_org', $value)) {
            $entity->save();
          }
        }
        catch (\Throwable $exception) {
          $this->recordApplyError($rows, $errors, 'organisation', $code, $exception);
        }
      }
    }

    if (!in_array('categories', $skip, TRUE)) {
      foreach ($categoryRows as $index => $category) {
        $code = (string) $category['code'];
        if (($category['active'] ?? FALSE) !== TRUE) {
          continue;
        }
        try {
          $entity = $categoryEntities[mb_strtolower($code)];
          $organisationCode = trim((string) ($category['organisation_code'] ?? ''));
          $organisation = $organisationCode === '' ? NULL : $organisationEntities[mb_strtolower($organisationCode)];
          if ($organisationCode !== '' && !$organisation instanceof GroupInterface) {
            throw new \RuntimeException(sprintf('Organisation "%s" is unavailable.', $organisationCode));
          }
          if (!$entity instanceof TermInterface) {
            /** @var \Drupal\taxonomy\TermInterface $entity */
            $entity = $this->entityTypeManager->getStorage('taxonomy_term')->create([
              'vid' => 'service_category',
              'name' => (string) $category['name'],
            ]);
            $categoryEntities[mb_strtolower($code)] = $entity;
          }
          $wasNew = $entity->isNew();
          $changes = $wasNew
            ? ['new']
            : $this->categoryChanges($entity, $category, $index, $rootId, $categoryEntities, $organisationEntities);
          $this->applyCategoryFields($entity, $category, $index, $rootId, $organisation);
          if ($wasNew || $changes !== []) {
            $entity->save();
          }
          if ($wasNew) {
            $createdTerms = TRUE;
          }
        }
        catch (\Throwable $exception) {
          $this->recordApplyError($rows, $errors, 'category', $code, $exception);
          $categoryEntities[mb_strtolower($code)] = NULL;
        }
      }

      foreach ($categoryRows as $category) {
        $code = (string) $category['code'];
        if (($category['active'] ?? FALSE) !== TRUE) {
          continue;
        }
        $entity = $categoryEntities[mb_strtolower($code)];
        if (!$entity instanceof TermInterface || $entity->id() === NULL) {
          continue;
        }
        try {
          $parentCode = trim((string) ($category['parent_code'] ?? ''));
          $parent = $parentCode === '' ? NULL : $categoryEntities[mb_strtolower($parentCode)];
          if ($parentCode !== '' && (!$parent instanceof TermInterface || $parent->id() === NULL)) {
            throw new \RuntimeException(sprintf('Parent category "%s" is unavailable.', $parentCode));
          }
          $value = ['target_id' => $parent instanceof TermInterface ? (int) $parent->id() : 0];
          if ($this->setFieldIfChanged($entity, 'parent', $value)) {
            $entity->save();
          }
        }
        catch (\Throwable $exception) {
          $this->recordApplyError($rows, $errors, 'category', $code, $exception);
        }
      }

      try {
        $selected = $this->selectedIdsWithInheritance(
          $jurisdiction,
          'field_service_categories',
          $context['root_categories'],
        );
        $inheritedSelection = $jurisdiction->get('field_service_categories')->isEmpty();
        $previousSelection = $selected;
        foreach ($categoryRows as $category) {
          $entity = $categoryEntities[mb_strtolower((string) $category['code'])];
          if (!$entity instanceof TermInterface || $entity->id() === NULL) {
            continue;
          }
          $id = (int) $entity->id();
          if (($category['active'] ?? FALSE) === TRUE) {
            $selected[] = $id;
          }
          else {
            $selected = array_values(array_diff($selected, [$id]));
          }
        }
        $selected = array_values(array_unique(array_map('intval', $selected)));
        if ((!$inheritedSelection || $selected !== $previousSelection)
          && $this->setReferenceIdsIfChanged($jurisdiction, 'field_service_categories', $selected)) {
          $jurisdiction->save();
        }
      }
      catch (\Throwable $exception) {
        $this->recordApplyError($rows, $errors, 'jurisdiction', 'field_service_categories', $exception);
      }

      foreach ($organisationRows as $organisation) {
        if (in_array('organisations', $skip, TRUE)) {
          break;
        }
        $code = (string) $organisation['code'];
        $entity = $organisationEntities[mb_strtolower($code)];
        if (!$entity instanceof GroupInterface) {
          continue;
        }
        try {
          $categoryIds = [];
          foreach ($context['organisation_categories'][mb_strtolower($code)] as $reference) {
            if (is_int($reference)) {
              $categoryIds[] = $reference;
              continue;
            }
            $category = $categoryEntities[substr($reference, 5)] ?? NULL;
            if (!$category instanceof TermInterface || $category->id() === NULL) {
              throw new \RuntimeException('Planned organisation category could not be created.');
            }
            $categoryIds[] = (int) $category->id();
          }
          if ($this->setReferenceIdsIfChanged($entity, 'field_service_categories', $categoryIds)) {
            $entity->save();
          }
        }
        catch (\Throwable $exception) {
          $this->recordApplyError($rows, $errors, 'organisation', $code, $exception);
        }
      }
    }

    if (!in_array('statuses', $skip, TRUE)) {
      foreach ($statusRows as $status) {
        $name = (string) $status['name'];
        try {
          $entity = $statusEntities[mb_strtolower($name)];
          if (!$entity instanceof TermInterface) {
            /** @var \Drupal\taxonomy\TermInterface $entity */
            $entity = $this->entityTypeManager->getStorage('taxonomy_term')->create([
              'vid' => 'service_status',
              'name' => $name,
            ]);
            $statusEntities[mb_strtolower($name)] = $entity;
          }
          $wasNew = $entity->isNew();
          $changes = $wasNew ? ['new'] : $this->statusChanges($entity, $status, $rootId);
          $this->applyStatusFields($entity, $status, $rootId);
          if ($wasNew || $changes !== []) {
            $entity->save();
          }
          if ($wasNew) {
            $createdTerms = TRUE;
          }
        }
        catch (\Throwable $exception) {
          $this->recordApplyError($rows, $errors, 'status', $name, $exception);
          $statusEntities[mb_strtolower($name)] = NULL;
        }
      }

      try {
        $selected = $this->selectedIdsWithInheritance(
          $jurisdiction,
          'field_service_statuses',
          $context['root_statuses'],
        );
        $inheritedSelection = $jurisdiction->get('field_service_statuses')->isEmpty();
        $previousSelection = $selected;
        foreach ($statusEntities as $entity) {
          if ($entity instanceof TermInterface && $entity->id() !== NULL) {
            $selected[] = (int) $entity->id();
          }
        }
        $selected = array_values(array_unique(array_map('intval', $selected)));
        if ((!$inheritedSelection || $selected !== $previousSelection)
          && $this->setReferenceIdsIfChanged($jurisdiction, 'field_service_statuses', $selected)) {
          $jurisdiction->save();
        }
      }
      catch (\Throwable $exception) {
        $this->recordApplyError($rows, $errors, 'jurisdiction', 'field_service_statuses', $exception);
      }
    }

    if (!in_array('users', $skip, TRUE)) {
      foreach ($userRows as $userRow) {
        $email = (string) $userRow['email'];
        try {
          $user = $userEntities[mb_strtolower($email)];
          if ($user instanceof UserInterface && $user->isBlocked()) {
            continue;
          }
          $wasNew = !$user instanceof UserInterface;
          $profileChanged = FALSE;
          if ($wasNew) {
            /** @var \Drupal\user\UserInterface $user */
            $user = $this->entityTypeManager->getStorage('user')->create([
              'name' => $email,
              'mail' => $email,
              'status' => 1,
              'pass' => $this->passwordGenerator->generate(32),
            ]);
          }
          else {
            $profileChanged = $this->userProfileDiffers($user, $userRow);

          }
          foreach ([
            'field_first_name' => (string) $userRow['first_name'],
            'field_last_name' => (string) $userRow['last_name'],
          ] as $field => $value) {
            if ($user->hasField($field)) {
              $user->set($field, $value);
            }
          }
          if ($wasNew || $profileChanged) {
            $user->save();
          }
          $userEntities[mb_strtolower($email)] = $user;
          if ($wasNew) {
            $createdUsers[$email] = $user;
          }

          $sourceRole = (string) $userRow['role'];
          $jurisdictionRole = TenantConfigValidator::ROLES[$sourceRole];
          $roleIds = MembershipRoleNormalizer::normalize([$jurisdictionRole], 'jur');
          $this->ensureMembershipRoles($jurisdiction, $user, $roleIds);

          if ($sourceRole === 'org_member') {
            $organisationCode = mb_strtolower(trim((string) $userRow['organisation_code']));
            $organisation = $organisationEntities[$organisationCode] ?? NULL;
            if (!$organisation instanceof GroupInterface) {
              throw new \RuntimeException(sprintf('Organisation "%s" is unavailable.', $userRow['organisation_code']));
            }
            $this->ensurePlainMembership($organisation, $user);
          }
        }
        catch (\Throwable $exception) {
          unset($createdUsers[$email]);
          $this->recordApplyError($rows, $errors, 'user', $email, $exception);
        }
      }
    }

    /** @var array<string, mixed> $tenant */
    $tenant = $configuration['tenant'];
    foreach ($this->fields->jurisdictionFieldValues($tenant) as $field => $value) {
      if (!$jurisdiction->hasField($field)) {
        continue;
      }
      try {
        if ($this->setFieldIfChanged($jurisdiction, $field, $value)) {
          $jurisdiction->save();
        }
      }
      catch (\Throwable $exception) {
        $this->recordApplyError($rows, $errors, 'jurisdiction', $field, $exception);
      }
    }

    if ($errors !== []) {
      $transaction->rollBack();
      foreach (['group', 'taxonomy_term', 'user', 'group_relationship'] as $entityType) {
        $this->entityTypeManager->getStorage($entityType)->resetCache();
      }
      foreach ($rows as &$row) {
        if (in_array($row['action'], ['create', 'update'], TRUE)) {
          $row['action'] = 'skip';
          $row['reason'] = 'Transaction rolled back after application errors. ' . $row['reason'];
        }
      }
      unset($row);
      return [
        'rows' => $rows,
        'errors' => $errors,
        'created_terms' => FALSE,
      ];
    }

    unset($transaction);

    if ($sendMails) {
      foreach ($createdUsers as $email => $user) {
        try {
          if (!_user_mail_notify('password_reset', $user)) {
            throw new \RuntimeException('Drupal did not send the password-reset mail. Check user.settings notify.password_reset and the mail transport.');
          }
          $this->appendRowReason($rows, 'user', $email, 'Password-reset mail sent.');
        }
        catch (\Throwable $exception) {
          $this->recordApplyError($rows, $errors, 'user', $email, $exception);
        }
      }
    }

    return [
      'rows' => $rows,
      'errors' => $errors,
      'created_terms' => $createdTerms,
    ];
  }

  /**
   * Returns field changes required for an existing category.
   */
  private function categoryChanges(TermInterface $term, array $category, int $index, int $rootId, array $categories, array $organisations): array {
    $changes = [];
    if ((string) $term->label() !== (string) $category['name']) {
      $changes[] = 'name';
    }
    foreach ($this->fields->categoryFieldValues($term, $category, $index, $rootId) as $field => $value) {
      if ($this->fieldDiffers($term, $field, $value)) {
        $changes[] = $field;
      }
    }
    if ($this->referenceDiffers($term, 'parent', $category['parent_code'] ?? NULL, $categories)) {
      $changes[] = 'parent';
    }
    if ($this->referenceDiffers($term, 'field_category_gid', $category['organisation_code'] ?? NULL, $organisations)) {
      $changes[] = 'field_category_gid';
    }
    return $changes;
  }

  /**
   * Applies all non-parent category fields.
   */
  private function applyCategoryFields(TermInterface $term, array $category, int $index, int $rootId, ?GroupInterface $organisation): void {
    $term->set('name', (string) $category['name']);
    foreach ($this->fields->categoryFieldValues($term, $category, $index, $rootId) as $field => $value) {
      $term->set($field, $value);
    }
    $term->set(
      'field_category_gid',
      $organisation instanceof GroupInterface ? ['target_id' => (int) $organisation->id()] : NULL,
    );
  }

  /**
   * Returns field changes required for an existing status.
   */
  private function statusChanges(TermInterface $term, array $status, int $rootId): array {
    $changes = [];
    if ((string) $term->label() !== (string) $status['name']) {
      $changes[] = 'name';
    }
    foreach ($this->fields->statusFieldValues($term, $status, $rootId) as $field => $value) {
      if ($this->fieldDiffers($term, $field, $value)) {
        $changes[] = $field;
      }
    }
    return $changes;
  }

  /**
   * Applies all status fields.
   */
  private function applyStatusFields(TermInterface $term, array $status, int $rootId): void {
    $term->set('name', (string) $status['name']);
    foreach ($this->fields->statusFieldValues($term, $status, $rootId) as $field => $value) {
      $term->set($field, $value);
    }
  }

  /**
   * Returns user profile and membership changes.
   */
  private function userChanges(UserInterface $user, array $row, GroupInterface $jurisdiction, array $organisations): array {
    $changes = [];
    if ($this->userProfileDiffers($user, $row)) {
      $changes[] = 'profile';
    }
    $sourceRole = (string) $row['role'];
    $requiredRoles = MembershipRoleNormalizer::normalize([
      TenantConfigValidator::ROLES[$sourceRole],
    ], 'jur');
    if (!$this->membershipHasRoles($jurisdiction, $user, $requiredRoles)) {
      $changes[] = 'jurisdiction membership';
    }
    if ($sourceRole === 'org_member') {
      $code = mb_strtolower(trim((string) $row['organisation_code']));
      $organisation = $organisations[$code] ?? NULL;
      if (!$organisation instanceof GroupInterface || !$organisation->getMember($user)) {
        $changes[] = 'organisation membership';
      }
    }
    return $changes;
  }

  /**
   * Checks mapped user profile fields.
   */
  private function userProfileDiffers(UserInterface $user, array $row): bool {
    foreach ([
      'field_first_name' => (string) $row['first_name'],
      'field_last_name' => (string) $row['last_name'],
    ] as $field => $value) {
      if ($user->hasField($field) && $this->fieldDiffers($user, $field, $value)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Ensures a membership exists and contains all requested individual roles.
   */
  private function ensureMembershipRoles(GroupInterface $group, UserInterface $user, array $roleIds): void {
    $membership = GroupMembership::loadSingle($group, $user);
    if (!$membership) {
      $relationship = $group->addRelationship($user, 'group_membership');
      $relationship->set('group_roles', $roleIds);
      $relationship->save();
      return;
    }
    $existing = array_column($membership->get('group_roles')->getValue(), 'target_id');
    $desired = array_values(array_unique(array_merge($existing, $roleIds)));
    if ($desired !== $existing) {
      $membership->set('group_roles', $desired);
      $membership->save();
    }
  }

  /**
   * Ensures a plain group membership exists.
   */
  private function ensurePlainMembership(GroupInterface $group, UserInterface $user): void {
    if (!GroupMembership::loadSingle($group, $user)) {
      $group->addRelationship($user, 'group_membership')->save();
    }
  }

  /**
   * Checks whether a membership contains every requested individual role.
   */
  private function membershipHasRoles(GroupInterface $group, UserInterface $user, array $roleIds): bool {
    $membership = GroupMembership::loadSingle($group, $user);
    if (!$membership) {
      return FALSE;
    }
    $existing = array_column(
      $membership->get('group_roles')->getValue(),
      'target_id',
    );
    return array_diff($roleIds, $existing) === [];
  }

  /**
   * Loads one organisation by case-insensitive code and root jurisdiction.
   */
  private function loadUniqueOrganisation(string $code, int $rootId): ?GroupInterface {
    $ids = $this->entityTypeManager->getStorage('group')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'org')
      ->condition('field_jurisdiction', $rootId)
      ->execute();
    $matches = [];
    foreach ($this->entityTypeManager->getStorage('group')->loadMultiple($ids) as $group) {
      if ($group instanceof GroupInterface
        && $group->hasField('field_org_code')
        && strcasecmp(trim($group->get('field_org_code')->getString()), trim($code)) === 0) {
        $matches[] = $group;
      }
    }
    if (count($matches) > 1) {
      throw new TenantImportValidationException([
        sprintf('Organisation code "%s" is not unique in root jurisdiction %d.', $code, $rootId),
      ]);
    }
    return $matches[0] ?? NULL;
  }

  /**
   * Loads one root-owned taxonomy term by a case-insensitive key.
   */
  private function loadUniqueTerm(string $vocabulary, string $field, string $value, int $rootId): ?TermInterface {
    $terms = $this->loadRootTerms($vocabulary, $rootId);
    $matches = [];
    foreach ($terms as $term) {
      $candidate = $field === 'name' ? (string) $term->label() : $term->get($field)->getString();
      if (strcasecmp(trim($candidate), trim($value)) === 0) {
        $matches[] = $term;
      }
    }
    if (count($matches) > 1) {
      throw new TenantImportValidationException([
        sprintf('%s key "%s" is not unique in root jurisdiction %d.', $vocabulary, $value, $rootId),
      ]);
    }
    return $matches[0] ?? NULL;
  }

  /**
   * Loads all taxonomy terms owned by one root jurisdiction.
   */
  private function loadRootTerms(string $vocabulary, int $rootId): array {
    $ids = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery()
      ->accessCheck(FALSE)
      ->condition('vid', $vocabulary)
      ->condition('field_jurisdiction', $rootId)
      ->execute();
    $terms = [];
    foreach ($this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($ids) as $term) {
      if ($term instanceof TermInterface) {
        $terms[(int) $term->id()] = $term;
      }
    }
    return $terms;
  }

  /**
   * Queries matching accounts using Drupal's case-insensitive mail condition.
   */
  private function loadUserByEmail(string $email): ?UserInterface {
    $matches = $this->entityTypeManager->getStorage('user')->loadByProperties(['mail' => $email]);
    if (count($matches) > 1) {
      throw new TenantImportValidationException([sprintf('User email "%s" is not unique case-insensitively.', $email)]);
    }
    return $matches === [] ? NULL : reset($matches);
  }

  /**
   * Counts distinct jurisdiction memberships outside the target root.
   */
  private function otherJurisdictions(UserInterface $user, int $rootId): int {
    $other = [];
    foreach (GroupMembership::loadByUser($user) as $membership) {
      $group = $membership->getGroup();
      if ($group->bundle() === 'jur'
        && $this->hierarchyResolver->getRootJurisdictionId((int) $group->id()) !== $rootId) {
        $other[(int) $group->id()] = TRUE;
      }
    }
    return count($other);
  }

  /**
   * Returns selected reference IDs, expanding an empty inherited selection.
   */
  private function selectedIdsWithInheritance(GroupInterface $jurisdiction, string $field, array $rootTerms): array {
    if (!$jurisdiction->get($field)->isEmpty()) {
      return array_values(array_unique(array_map(
        'intval',
        array_column($jurisdiction->get($field)->getValue(), 'target_id'),
      )));
    }
    return array_values(array_map('intval', array_keys($rootTerms)));
  }

  /**
   * Computes desired IDs once; new terms use code placeholders until saved.
   */
  private function plannedOrganisationCategories(array $configuration, array $organisations, array $categories, array $rootCategories, GroupInterface $jurisdiction): array {
    $desired = array_fill_keys(array_keys($organisations), []);
    $configured = [];
    foreach ($configuration['categories'] as $row) {
      $code = mb_strtolower($row['code']);
      $configured[$code] = TRUE;
      $org = mb_strtolower($row['organisation_code'] ?? '');
      if ($row['active'] && $org !== '') {
        $term = $categories[$code];
        $desired[$org][] = $term instanceof TermInterface ? (int) $term->id() : 'code:' . $code;
      }
    }
    $selected = $this->selectedIdsWithInheritance($jurisdiction, 'field_service_categories', $rootCategories);
    foreach ($rootCategories as $term) {
      $code = mb_strtolower($term->get('field_service_code')->getString());
      $org = mb_strtolower($this->referencedOrganisationCode($term, 'field_category_gid') ?? '');
      if (!isset($configured[$code]) && isset($desired[$org]) && in_array((int) $term->id(), $selected, TRUE)) {
        $desired[$org][] = (int) $term->id();
      }
    }
    return $desired;
  }

  /**
   * Compares reference sets, including symbolic IDs for not-yet-created terms.
   */
  private function referenceIdsDiffer(FieldableEntityInterface $entity, string $field, array $ids): bool {
    $current = array_map('intval', array_column($entity->get($field)->getValue(), 'target_id'));
    $ids = array_values(array_unique($ids));
    sort($current);
    sort($ids);
    return $current !== $ids;
  }

  /**
   * Leaves reference order intact when the same unique IDs are already stored.
   */
  private function setReferenceIdsIfChanged(FieldableEntityInterface $entity, string $field, array $ids): bool {
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$this->referenceIdsDiffer($entity, $field, $ids)) {
      return FALSE;
    }
    $entity->set($field, array_map(static fn(int $id): array => ['target_id' => $id], $ids));
    return TRUE;
  }

  /**
   * Checks whether setting a field would change its normalized storage value.
   */
  private function fieldDiffers(FieldableEntityInterface $entity, string $field, mixed $value): bool {
    if (!$entity->hasField($field)) {
      return FALSE;
    }
    if ($value === NULL || $value === []) {
      return !$entity->get($field)->isEmpty();
    }
    if ($entity->get($field)->getFieldDefinition()->getType() === 'color_field_type') {
      return strtolower((string) $entity->get($field)->color) !== strtolower((string) $value['color'])
        || (float) $entity->get($field)->opacity !== (float) $value['opacity'];
    }
    $copy = clone $entity;
    $copy->set($field, $value);
    return !$entity->get($field)->equals($copy->get($field));
  }

  /**
   * Sets a field only when its normalized storage value differs.
   */
  private function setFieldIfChanged(FieldableEntityInterface $entity, string $field, mixed $value): bool {
    if (!$this->fieldDiffers($entity, $field, $value)) {
      return FALSE;
    }
    $entity->set($field, $value);
    return TRUE;
  }

  /**
   * Returns a nullable trimmed string.
   */
  private function nullableString(mixed $value): ?string {
    $value = trim((string) $value);
    return $value === '' ? NULL : $value;
  }

  /**
   * Compares exact IDs; a new referenced entity cannot already match.
   */
  private function referenceDiffers(FieldableEntityInterface $entity, string $field, ?string $code, array $targets): bool {
    $key = mb_strtolower($code ?? '');
    $expected = $key === '' ? 0 : (int) (($targets[$key] ?? NULL)?->id() ?? -1);
    return (int) $entity->get($field)->target_id !== $expected;
  }

  /**
   * Returns a referenced organisation's administrative code.
   */
  private function referencedOrganisationCode(FieldableEntityInterface $entity, string $field): ?string {
    if (!$entity->hasField($field) || $entity->get($field)->isEmpty()) {
      return NULL;
    }
    $organisation = $entity->get($field)->entity;
    if (!$organisation instanceof GroupInterface || !$organisation->hasField('field_org_code')) {
      return NULL;
    }
    return $organisation->get('field_org_code')->getString();
  }

  /**
   * Explains that organisation type has no matching field in the model.
   */
  private function organisationTypeReason(array $organisation): string {
    $type = trim((string) ($organisation['type'] ?? ''));
    return $type === ''
      ? ''
      : sprintf(' Source type "%s" is not mapped because org groups have no type field.', $type);
  }

  /**
   * Reports unsupported name fields without exposing their personal values.
   */
  private function userNameFieldReason(): string {
    $definitions = $this->entityFieldManager->getFieldDefinitions('user', 'user');
    return isset($definitions['field_first_name'], $definitions['field_last_name'])
      ? '' : ' names not mapped.';
  }

  /**
   * Indexes organisation references once for imported category and user rows.
   */
  private function organisationReferences(array $configuration, array $skip): array {
    $references = [];
    foreach (['categories', 'users'] as $section) {
      if (in_array($section, $skip, TRUE)) {
        continue;
      }
      foreach ($configuration[$section] as $row) {
        $code = mb_strtolower($row['organisation_code'] ?? '');
        if ($code !== '' && ($section === 'users' || $row['active'])) {
          $references[$code][] = $section;
        }
      }
    }
    return $references;
  }

  /**
   * Creates one structured output row.
   */
  private function row(string $entity, string $key, string $action, string $reason): array {
    return compact('entity', 'key', 'action', 'reason');
  }

  /**
   * Records an application exception on the matching plan row.
   */
  private function recordApplyError(array &$rows, array &$errors, string $entity, string $key, \Throwable $exception): void {
    $message = sprintf('%s %s: %s', $entity, $key, $exception->getMessage());
    $errors[] = $message;
    foreach ($rows as &$row) {
      if ($row['entity'] === $entity && strcasecmp($row['key'], $key) === 0) {
        $row['action'] = 'error';
        $row['reason'] = $message;
        unset($row);
        return;
      }
    }
    unset($row);
    $rows[] = $this->row($entity, $key, 'error', $message);
  }

  /**
   * Appends a successful side-effect note to a plan row.
   */
  private function appendRowReason(array &$rows, string $entity, string $key, string $reason): void {
    foreach ($rows as &$row) {
      if ($row['entity'] === $entity && strcasecmp($row['key'], $key) === 0) {
        $row['reason'] .= ' ' . $reason;
        break;
      }
    }
    unset($row);
  }

}
