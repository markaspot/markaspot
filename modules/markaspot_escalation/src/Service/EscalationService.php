<?php

namespace Drupal\markaspot_escalation\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface;
use Drupal\markaspot_group\Service\OrgHierarchyResolverInterface;
use Drupal\markaspot_group\Trait\JurisdictionIdResolverTrait;
use Drupal\markaspot_open311\Service\GeoreportProcessorServiceInterface;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Psr\Log\LoggerInterface;

/**
 * Handles escalation and delegation of service requests.
 *
 * Escalation first routes to a parent organisation when the current org has
 * one, then falls back to the jurisdiction hierarchy. Delegation moves a
 * request downward or laterally to a different organisation. Both operations
 * create an internal remark, create a new node revision, and send email
 * notifications.
 */
class EscalationService implements EscalationServiceInterface {

  use JurisdictionIdResolverTrait;
  use StringTranslationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The jurisdiction hierarchy resolver.
   *
   * @var \Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface
   */
  protected JurisdictionHierarchyResolverInterface $hierarchyResolver;

  /**
   * The organisation hierarchy resolver.
   *
   * @var \Drupal\markaspot_group\Service\OrgHierarchyResolverInterface
   */
  protected OrgHierarchyResolverInterface $orgHierarchyResolver;

  /**
   * The GeoReport processor service.
   *
   * @var \Drupal\markaspot_open311\Service\GeoreportProcessorServiceInterface
   */
  protected GeoreportProcessorServiceInterface $processor;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected AccountInterface $currentUser;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected TimeInterface $time;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected MailManagerInterface $mailManager;

  /**
   * Cached delegation note requirements keyed by jurisdiction group ID.
   *
   * @var array<int, bool>
   */
  protected array $delegationNoteRequiredByJurisdiction = [];

  /**
   * Constructs an EscalationService.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\markaspot_group\Service\JurisdictionHierarchyResolverInterface $hierarchyResolver
   *   The jurisdiction hierarchy resolver.
   * @param \Drupal\markaspot_open311\Service\GeoreportProcessorServiceInterface $processor
   *   The GeoReport processor service.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   * @param \Drupal\Core\Mail\MailManagerInterface $mailManager
   *   The mail manager.
   * @param \Drupal\markaspot_group\Service\OrgHierarchyResolverInterface $orgHierarchyResolver
   *   The organisation hierarchy resolver.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    JurisdictionHierarchyResolverInterface $hierarchyResolver,
    GeoreportProcessorServiceInterface $processor,
    AccountInterface $currentUser,
    ConfigFactoryInterface $configFactory,
    TimeInterface $time,
    LoggerInterface $logger,
    MailManagerInterface $mailManager,
    OrgHierarchyResolverInterface $orgHierarchyResolver,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->hierarchyResolver = $hierarchyResolver;
    $this->orgHierarchyResolver = $orgHierarchyResolver;
    $this->processor = $processor;
    $this->currentUser = $currentUser;
    $this->configFactory = $configFactory;
    $this->time = $time;
    $this->logger = $logger;
    $this->mailManager = $mailManager;
  }

  /**
   * {@inheritdoc}
   */
  public function escalateRequest(NodeInterface $node, int $targetGroupId, string $note): void {
    $groupStorage = $this->entityTypeManager->getStorage('group');

    // Load and validate the target group.
    $targetGroup = $groupStorage->load($targetGroupId);
    if (!$this->isJurisdictionGroup($targetGroup) && !$this->isOrganisationGroup($targetGroup)) {
      throw new \InvalidArgumentException(
        sprintf('Target group %d does not exist or is not a valid escalation target.', $targetGroupId)
      );
    }

    // Verify the target is the legitimate escalation target for this node.
    // This prevents callers from moving requests to arbitrary groups.
    $legitimateTarget = $this->resolveEscalationTarget($node);
    if ($legitimateTarget !== $targetGroupId) {
      throw new \InvalidArgumentException(
        sprintf('Target group %d is not a valid escalation target for node %d.', $targetGroupId, $node->id())
      );
    }

    if ($this->isOrganisationGroup($targetGroup)) {
      $escalationNote = $this->buildParentOrganisationEscalationNote($targetGroup, $note);
      $this->moveRequestToOrganisation($node, $targetGroup, $escalationNote, FALSE);

      $this->logger->notice('Escalated service request @nid to parent organisation "@org" (id=@oid). Note: @note', [
        '@nid' => $node->id(),
        '@org' => $targetGroup->label(),
        '@oid' => $targetGroupId,
        '@note' => $escalationNote,
      ]);
      return;
    }

    $jurGroup = $targetGroup;

    // Create internal remark paragraph with the escalation note. Notes are
    // optional (tenant-configurable), but the audit trail must still record
    // WHAT happened, so an empty note falls back to a descriptive text.
    $remarkText = trim($note) === ''
      ? (string) $this->t('Eskaliert an @jurisdiction.', ['@jurisdiction' => $jurGroup->label()])
      : $note;
    $paragraph = $this->createInternalRemarkParagraph($remarkText, $node->language()->getId());

    // Append to the node's field_internal_remark (unlimited cardinality).
    $this->appendInternalRemark($node, $paragraph);

    // Clear the organisation assignment.
    $node->set('field_organisation', NULL);

    // Set the escalation target to the new jurisdiction.
    $node->set('field_escalation', ['target_id' => $targetGroupId]);

    // Update field_jurisdiction so the API response reflects the new
    // jurisdiction. Without this, resolveNodeJurisdiction() returns the
    // original (child) jurisdiction because field_jurisdiction takes priority
    // over group_relationships.
    if ($node->hasField('field_jurisdiction')) {
      $node->set('field_jurisdiction', ['target_id' => $targetGroupId]);
    }

    // Create a new revision with a descriptive log message.
    $node->setNewRevision(TRUE);
    $node->setRevisionLogMessage(
      trim($note) === ''
        ? (string) $this->t('Escalated to @jurisdiction', ['@jurisdiction' => $jurGroup->label()])
        : (string) $this->t('Escalated to @jurisdiction: @note', [
          '@jurisdiction' => $jurGroup->label(),
          '@note' => $note,
        ])
    );
    $node->setRevisionCreationTime($this->time->getRequestTime());
    $node->setRevisionUserId($this->currentUser->id());

    // Suppress the bidirectional sync in markaspot_group so that
    // saving the node and manipulating jur relationships does not
    // overwrite field_organisation (which should reference org groups only).
    $syncFlag = &drupal_static('_markaspot_group_is_syncing', FALSE);
    $syncFlag = TRUE;

    try {
      $node->save();

      // Replace the jurisdiction group_relationship: remove old, add new.
      $relationshipStorage = $this->entityTypeManager->getStorage('group_relationship');
      $pluginId = 'group_node:service_request';

      // Remove existing jur relationships (skip the target to avoid re-adding).
      $existing = $relationshipStorage->loadByProperties([
        'entity_id' => $node->id(),
        'plugin_id' => $pluginId,
      ]);
      foreach ($existing as $relationship) {
        $group = $relationship->getGroup();
        if ($this->isJurisdictionGroup($group) && (int) $group->id() !== $targetGroupId) {
          $relationship->delete();
        }
      }

      // Add relationship to the target jurisdiction (skip if it already
      // exists, e.g. from boundary auto-assignment, to avoid duplicates).
      $existingTarget = $relationshipStorage->loadByProperties([
        'entity_id' => $node->id(),
        'gid' => $targetGroupId,
        'plugin_id' => $pluginId,
      ]);
      if (empty($existingTarget)) {
        $jurGroup->addRelationship($node, $pluginId);
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Escalation failed for node @nid to jurisdiction @gid: @error', [
        '@nid' => $node->id(),
        '@gid' => $targetGroupId,
        '@error' => $e->getMessage(),
      ]);
      throw new \RuntimeException('Escalation failed: ' . $e->getMessage(), 0, $e);
    }
    finally {
      $syncFlag = FALSE;
    }

    // Send email notification to the target jurisdiction.
    $this->sendEscalationNotification($jurGroup, $node, $note);

    $this->logger->notice('Escalated service request @nid to jurisdiction "@jur" (id=@jid). Note: @note', [
      '@nid' => $node->id(),
      '@jur' => $jurGroup->label(),
      '@jid' => $targetGroupId,
      '@note' => $note,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function resolveEscalationTarget(NodeInterface $node): ?int {
    $groupStorage = $this->entityTypeManager->getStorage('group');

    // 1. First escalation climbs one level up the organisation axis before
    // any jurisdiction-axis target is considered.
    if (!$node->hasField('field_escalation') || $node->get('field_escalation')->isEmpty()) {
      $parentOrgId = $this->resolveParentOrganisationEscalationTarget($node);
      if ($parentOrgId !== NULL) {
        return $parentOrgId;
      }
    }

    $requestJurisdictionId = $this->resolveRequestJurisdictionId($node);

    // 2. Check for a category-level jurisdiction target override.
    if ($node->hasField('field_category') && !$node->get('field_category')->isEmpty()) {
      $categoryTerm = $node->get('field_category')->entity;
      if ($categoryTerm
          && $categoryTerm->hasField('field_escalation_target')
          && !$categoryTerm->get('field_escalation_target')->isEmpty()) {
        $targetGroupId = (int) $categoryTerm->get('field_escalation_target')->target_id;
        // Skip if the request is already escalated to this target.
        $currentEscalation = ($node->hasField('field_escalation') && !$node->get('field_escalation')->isEmpty())
          ? (int) $node->get('field_escalation')->target_id
          : NULL;
        if ($currentEscalation !== $targetGroupId) {
          // Verify the target group still exists and is a jurisdiction.
          $targetGroup = $groupStorage->load($targetGroupId);
          if ($this->isJurisdictionGroup($targetGroup)) {
            if ($targetGroupId !== $requestJurisdictionId) {
              return $targetGroupId;
            }
          }
          else {
            $this->logger->warning('Category escalation target @gid is invalid for node @nid.', [
              '@gid' => $targetGroupId,
              '@nid' => $node->id(),
            ]);
          }
        }
      }
    }

    // 3. Fallback: traverse the jurisdiction hierarchy.
    if ($node->hasField('field_escalation') && !$node->get('field_escalation')->isEmpty()) {
      // Re-escalation case: the request is already escalated.
      // Target is the parent of the current escalation jurisdiction.
      $currentJurId = (int) $node->get('field_escalation')->target_id;
      $targetJurId = $this->getParentJurisdictionId($currentJurId);
      return $targetJurId !== $requestJurisdictionId ? $targetJurId : NULL;
    }

    // First escalation: use the source jurisdiction resolved above from group
    // memberships or field_organisation.
    if ($requestJurisdictionId !== NULL) {
      $targetJurId = $this->getParentJurisdictionId($requestJurisdictionId);
      return $targetJurId !== $requestJurisdictionId ? $targetJurId : NULL;
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function resolveRequestJurisdictionId(NodeInterface $node): ?int {
    if ($node->hasField('field_escalation')
        && !$node->get('field_escalation')->isEmpty()) {
      return (int) $node->get('field_escalation')->target_id;
    }

    return $this->resolveSourceJurisdiction($node);
  }

  /**
   * {@inheritdoc}
   */
  public function isDelegationNoteRequired(NodeInterface $node): bool {
    $jurisdictionId = $this->resolveRequestJurisdictionId($node);
    if ($jurisdictionId === NULL) {
      return FALSE;
    }

    return $this->delegationNoteRequiredForJurisdiction($jurisdictionId);
  }

  /**
   * Checks whether a jurisdiction requires escalation and delegation notes.
   *
   * @param int $jurisdictionId
   *   The jurisdiction group ID.
   *
   * @return bool
   *   TRUE if the jurisdiction config requires notes.
   */
  protected function delegationNoteRequiredForJurisdiction(
    int $jurisdictionId,
  ): bool {
    $cache = &$this->delegationNoteRequiredByJurisdiction;
    if (array_key_exists($jurisdictionId, $cache)) {
      return $cache[$jurisdictionId];
    }

    $jurisdiction = $this->entityTypeManager->getStorage('group')
      ->load($jurisdictionId);
    if (!$this->isJurisdictionGroup($jurisdiction)
        || !$jurisdiction->hasField('field_nuxt_config')
        || $jurisdiction->get('field_nuxt_config')->isEmpty()) {
      $cache[$jurisdictionId] = FALSE;
      return FALSE;
    }

    $decoded = json_decode(
      (string) $jurisdiction->get('field_nuxt_config')->value,
      TRUE
    );
    if (!is_array($decoded)) {
      $cache[$jurisdictionId] = FALSE;
      return FALSE;
    }

    $cache[$jurisdictionId] =
      ($decoded['features']['delegationNoteRequired'] ?? FALSE) === TRUE;
    return $cache[$jurisdictionId];
  }

  /**
   * {@inheritdoc}
   */
  public function delegateRequest(NodeInterface $node, int $targetOrgId, string $note): void {
    $groupStorage = $this->entityTypeManager->getStorage('group');

    // Load and validate the target organisation group.
    $orgGroup = $groupStorage->load($targetOrgId);
    if (!$orgGroup || $orgGroup->bundle() !== 'org') {
      throw new \InvalidArgumentException(
        sprintf('Target group %d does not exist or is not an organisation.', $targetOrgId)
      );
    }

    $this->moveRequestToOrganisation($node, $orgGroup, $note, TRUE);

    $this->logger->notice('Delegated service request @nid to organisation "@org" (id=@oid). Note: @note', [
      '@nid' => $node->id(),
      '@org' => $orgGroup->label(),
      '@oid' => $targetOrgId,
      '@note' => $note,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function canEscalate(NodeInterface $node, AccountInterface $account): bool {
    // 1. Check config: escalation must be enabled.
    $config = $this->configFactory->get('markaspot_escalation.settings');
    if (!$config->get('escalation_enabled')) {
      return FALSE;
    }

    // 2. Node must have the field_escalation field (module installed).
    if (!$node->hasField('field_escalation')) {
      return FALSE;
    }

    // 3. Permission check first (cheap, avoids expensive queries).
    if (!$account->hasPermission('escalate service requests')) {
      return FALSE;
    }

    // 4. Status must not be closed.
    if ($this->isRequestClosed($node)) {
      return FALSE;
    }

    // 5. Must have a valid escalation target.
    $targetGroupId = $this->resolveEscalationTarget($node);
    if ($targetGroupId === NULL) {
      return FALSE;
    }

    // 6. Group membership check based on current state.
    if ($node->get('field_escalation')->isEmpty()) {
      // First escalation: user must be a member of the source jurisdiction.
      // resolveEscalationTarget already called resolveSourceJurisdiction
      // internally, so we derive the source jur from the target's child.
      $sourceJurId = $this->resolveSourceJurisdiction($node);
      if ($sourceJurId === NULL) {
        return FALSE;
      }
      return $this->isGroupMember($sourceJurId, $account);
    }

    // Re-escalation: user must be a member of the current escalation target.
    // This is intentionally asymmetric: first escalation checks source jur
    // membership, but re-escalation checks the receiving jur. Only the
    // jurisdiction that received the escalated request can escalate further.
    $jurGroupId = (int) $node->get('field_escalation')->target_id;
    return $this->isGroupMember($jurGroupId, $account);
  }

  /**
   * {@inheritdoc}
   */
  public function canDelegate(NodeInterface $node, AccountInterface $account): bool {
    // 1. Check config: delegation must be enabled.
    $config = $this->configFactory->get('markaspot_escalation.settings');
    if (!$config->get('delegation_enabled')) {
      return FALSE;
    }

    // 2. Node must have the field_escalation field.
    if (!$node->hasField('field_escalation')) {
      return FALSE;
    }

    // 3. Status must not be closed.
    if ($this->isRequestClosed($node)) {
      return FALSE;
    }

    // 4. Permission check.
    if (!$account->hasPermission('delegate service requests')) {
      return FALSE;
    }

    // 5. Group membership check: user must be a member of a relevant
    //    jurisdiction.
    if ($node->hasField('field_escalation') && !$node->get('field_escalation')->isEmpty()) {
      // Request is escalated: user must be a member of the escalation jur.
      $jurGroupId = (int) $node->get('field_escalation')->target_id;
      return $this->isGroupMember($jurGroupId, $account);
    }

    // Not escalated: user must be a member of the org's OWN jurisdiction
    // (lateral delegation inside the tenant, the responsibility picker's
    // primary flow) or of a parent jur of ANY org's jurisdiction (legacy
    // redistribution path; multi-org support).
    if ($node->hasField('field_organisation') && !$node->get('field_organisation')->isEmpty()) {
      foreach ($node->get('field_organisation')->referencedEntities() as $orgGroup) {
        if ($orgGroup->hasField('field_jurisdiction')
            && !$orgGroup->get('field_jurisdiction')->isEmpty()) {
          $orgJurId = (int) $orgGroup->get('field_jurisdiction')->target_id;
          if ($this->isGroupMember($orgJurId, $account)) {
            return TRUE;
          }
          $parentJurId = $this->getParentJurisdictionId($orgJurId);
          if ($parentJurId !== NULL && $this->isGroupMember($parentJurId, $account)) {
            return TRUE;
          }
        }
      }
    }

    return FALSE;
  }

  /**
   * Creates an internal_remark paragraph with the given text.
   *
   * @param string $text
   *   The remark text.
   * @param string $langcode
   *   The language code. Defaults to site default.
   *
   * @return \Drupal\paragraphs\Entity\Paragraph
   *   The saved paragraph entity.
   */
  protected function createInternalRemarkParagraph(string $text, string $langcode = ''): Paragraph {
    $paragraph = Paragraph::create([
      'type' => 'internal_remark',
      'langcode' => $langcode ?: LanguageInterface::LANGCODE_NOT_SPECIFIED,
      'field_internal_remark_text' => [
        'value' => $text,
        'format' => 'plain_text',
      ],
    ]);
    $paragraph->save();
    return $paragraph;
  }

  /**
   * Appends a paragraph to the node's field_internal_remark field.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   * @param \Drupal\paragraphs\Entity\Paragraph $paragraph
   *   The paragraph to append.
   */
  protected function appendInternalRemark(NodeInterface $node, Paragraph $paragraph): void {
    if (!$node->hasField('field_internal_remark')) {
      $this->logger->warning('Node @nid does not have field_internal_remark.', [
        '@nid' => $node->id(),
      ]);
      return;
    }

    $current = $node->get('field_internal_remark')->getValue();
    $current[] = [
      'target_id' => $paragraph->id(),
      'target_revision_id' => $paragraph->getRevisionId(),
    ];
    $node->set('field_internal_remark', $current);
  }

  /**
   * Moves a request to one organisation using the delegation machinery.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   * @param \Drupal\group\Entity\GroupInterface $orgGroup
   *   The target organisation group.
   * @param string $note
   *   The note text for the internal remark and notification.
   * @param bool $clearEscalation
   *   Whether to clear field_escalation during the move.
   */
  protected function moveRequestToOrganisation(
    NodeInterface $node,
    GroupInterface $orgGroup,
    string $note,
    bool $clearEscalation,
  ): void {
    // Notes are optional (tenant-configurable); an empty note still leaves a
    // descriptive audit remark. Callers with a prefixed note (parent-org
    // escalation) pass a non-empty string, so no double prefix can occur.
    $remarkText = trim($note) === ''
      ? (string) $this->t('Delegiert an @organisation.', ['@organisation' => $orgGroup->label()])
      : $note;
    $paragraph = $this->createInternalRemarkParagraph($remarkText, $node->language()->getId());
    $this->appendInternalRemark($node, $paragraph);

    // Delegation is a deliberate reassignment: replaces ALL current orgs
    // with the single target org. This is by design, not a multi-value
    // oversight. The bidirectional sync in markaspot_group_node_presave()
    // handles group_relationship changes.
    $node->set('field_organisation', ['target_id' => (int) $orgGroup->id()]);

    if ($clearEscalation
        && $node->hasField('field_escalation')
        && !$node->get('field_escalation')->isEmpty()) {
      $node->set('field_escalation', NULL);
    }

    $node->setNewRevision(TRUE);
    $node->setRevisionLogMessage(
      trim($note) === ''
        ? (string) $this->t('Delegated to @organisation', ['@organisation' => $orgGroup->label()])
        : (string) $this->t('Delegated to @organisation: @note', [
          '@organisation' => $orgGroup->label(),
          '@note' => $note,
        ])
    );
    $node->setRevisionCreationTime($this->time->getRequestTime());
    $node->setRevisionUserId($this->currentUser->id());

    $node->save();
    $this->sendDelegationNotification($orgGroup, $node, $note);
  }

  /**
   * Resolves the next parent organisation escalation target.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   *
   * @return int|null
   *   The nearest parent organisation ID, or NULL when none is available.
   */
  protected function resolveParentOrganisationEscalationTarget(NodeInterface $node): ?int {
    if (!$node->hasField('field_organisation') || $node->get('field_organisation')->isEmpty()) {
      return NULL;
    }

    $groupStorage = $this->entityTypeManager->getStorage('group');
    foreach ($node->get('field_organisation')->referencedEntities() as $orgGroup) {
      if (!$this->isOrganisationGroup($orgGroup)) {
        continue;
      }

      $ancestorIds = $this->orgHierarchyResolver->getAncestorIds((int) $orgGroup->id());
      if (empty($ancestorIds)) {
        continue;
      }

      $parentOrgId = (int) reset($ancestorIds);
      $parentOrg = $groupStorage->load($parentOrgId);
      if ($this->isOrganisationGroup($parentOrg)) {
        return $parentOrgId;
      }

      $this->logger->warning('Parent organisation @pid referenced by @oid does not exist or is invalid.', [
        '@pid' => $parentOrgId,
        '@oid' => $orgGroup->id(),
      ]);
    }

    return NULL;
  }

  /**
   * Builds the note used for an escalation step to a parent organisation.
   *
   * @param \Drupal\group\Entity\GroupInterface $orgGroup
   *   The target organisation group.
   * @param string $note
   *   The original escalation note.
   *
   * @return string
   *   The internal remark and notification note.
   */
  protected function buildParentOrganisationEscalationNote(GroupInterface $orgGroup, string $note): string {
    $prefix = (string) $this->t('Eskaliert an übergeordnete Organisationseinheit @organisation', [
      '@organisation' => $orgGroup->label(),
    ]);

    $note = trim($note);
    if ($note === '') {
      return $prefix . '.';
    }

    return $prefix . ': ' . $note;
  }

  /**
   * Checks whether a group is an organisation group.
   *
   * @param mixed $group
   *   The candidate group entity.
   *
   * @return bool
   *   TRUE when the group is an organisation group.
   */
  protected function isOrganisationGroup(mixed $group): bool {
    return $group instanceof GroupInterface && $group->bundle() === 'org';
  }

  /**
   * Gets the parent jurisdiction ID for a given jurisdiction.
   *
   * @param int $jurId
   *   The jurisdiction group ID.
   *
   * @return int|null
   *   The parent jurisdiction group ID, or NULL if the jurisdiction is root.
   */
  protected function getParentJurisdictionId(int $jurId): ?int {
    $groupStorage = $this->entityTypeManager->getStorage('group');
    $jurGroup = $groupStorage->load($jurId);

    if (!$this->isJurisdictionGroup($jurGroup)) {
      return NULL;
    }

    if (!$jurGroup->hasField('field_parent_jurisdiction')
        || $jurGroup->get('field_parent_jurisdiction')->isEmpty()) {
      // This is a root jurisdiction, no parent to escalate to.
      return NULL;
    }

    $parentId = (int) $jurGroup->get('field_parent_jurisdiction')->target_id;

    // Verify parent exists and is a jurisdiction.
    $parentGroup = $groupStorage->load($parentId);
    if (!$this->isJurisdictionGroup($parentGroup)) {
      $this->logger->warning('Parent jurisdiction @pid referenced by @jid does not exist or is invalid.', [
        '@pid' => $parentId,
        '@jid' => $jurId,
      ]);
      return NULL;
    }

    return $parentId;
  }

  /**
   * Resolves the source jurisdiction for a non-escalated request.
   *
   * Handles both group_filter_type configurations:
   * - 'org': field_organisation -> org group -> field_jurisdiction -> jur ID
   * - 'jur': find the most specific (child) jur group_relationship.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   *
   * @return int|null
   *   The source jurisdiction group ID, or NULL if none found.
   */
  protected function resolveSourceJurisdiction(NodeInterface $node): ?int {
    // 1. Primary: find the most specific jur from group_relationships.
    // These reflect the actual creation context, e.g. citizen-created in Noord.
    // Prefer a child jur where the current user is a member (deterministic
    // tiebreaker when multiple child jurs exist).
    $relationshipStorage = $this->entityTypeManager->getStorage('group_relationship');
    $relationships = $relationshipStorage->loadByProperties([
      'entity_id' => $node->id(),
      'plugin_id' => 'group_node:service_request',
    ]);

    $childJurId = NULL;
    $rootJurId = NULL;
    foreach ($relationships as $relationship) {
      $group = $relationship->getGroup();
      if (!$this->isJurisdictionGroup($group)) {
        continue;
      }
      $gid = (int) $group->id();
      if ($group->hasField('field_parent_jurisdiction')
          && !$group->get('field_parent_jurisdiction')->isEmpty()) {
        // This jur has a parent, so it's a child (more specific).
        // Prefer the child where the performing user is a member.
        if ($childJurId === NULL || $this->isGroupMember($gid, $this->currentUser)) {
          $childJurId = $gid;
        }
      }
      else {
        $rootJurId = $gid;
      }
    }

    // Prefer the child (e.g. Noord) over the root (e.g. Amsterdam).
    if ($childJurId !== NULL || $rootJurId !== NULL) {
      return $childJurId ?? $rootJurId;
    }

    // 2. Fallback: field_organisation -> org's parent jurisdiction.
    // Org groups (e.g. Department 1) have field_jurisdiction pointing to
    // their parent jur. This may resolve to root when the org is defined
    // at root level, so it's less specific than group_relationships.
    // With multi-org, collect all jurisdiction IDs and warn on conflicts.
    if ($node->hasField('field_organisation') && !$node->get('field_organisation')->isEmpty()) {
      $orgJurIds = [];
      foreach ($node->get('field_organisation')->referencedEntities() as $orgGroup) {
        if ($orgGroup->bundle() === 'org'
            && $orgGroup->hasField('field_jurisdiction')
            && !$orgGroup->get('field_jurisdiction')->isEmpty()) {
          $orgJurIds[] = (int) $orgGroup->get('field_jurisdiction')->target_id;
        }
      }
      $orgJurIds = array_unique($orgJurIds);
      if (count($orgJurIds) > 1) {
        $this->logger->warning('Node @nid has organisations spanning multiple jurisdictions: @jur_ids. Using first.', [
          '@nid' => $node->id(),
          '@jur_ids' => implode(', ', $orgJurIds),
        ]);
      }
      if (!empty($orgJurIds)) {
        return reset($orgJurIds);
      }
    }

    // 3. Last resort: derive jurisdiction from the category term.
    // Categories are typically defined at root level, so this returns
    // the root jurisdiction. Covers requests with no org and no
    // group_relationships.
    if ($node->hasField('field_category') && !$node->get('field_category')->isEmpty()) {
      $categoryTerm = $node->get('field_category')->entity;
      if ($categoryTerm
          && $categoryTerm->hasField('field_jurisdiction')
          && !$categoryTerm->get('field_jurisdiction')->isEmpty()) {
        return (int) $categoryTerm->get('field_jurisdiction')->target_id;
      }
    }

    return NULL;
  }

  /**
   * Checks whether a user is a member of a given group.
   *
   * @param int $groupId
   *   The group ID.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   *
   * @return bool
   *   TRUE if the user is a member of the group.
   */
  protected function isGroupMember(int $groupId, AccountInterface $account): bool {
    $group = $this->entityTypeManager->getStorage('group')->load($groupId);
    if (!$group) {
      return FALSE;
    }

    $membership = $group->getMember($account);
    return $membership !== FALSE;
  }

  /**
   * Checks whether the service request status is closed.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The service request node.
   *
   * @return bool
   *   TRUE if the current status is a "closed" status term.
   */
  protected function isRequestClosed(NodeInterface $node): bool {
    if (!$node->hasField('field_status') || $node->get('field_status')->isEmpty()) {
      return FALSE;
    }

    $currentStatusTid = (int) $node->get('field_status')->target_id;
    $closedConfig = $this->configFactory->get('markaspot_open311.settings')->get('status_closed');

    if (!is_array($closedConfig)) {
      return FALSE;
    }

    // status_closed is stored as an associative array like {6: '6'}.
    $closedTids = array_map('intval', array_values($closedConfig));
    return in_array($currentStatusTid, $closedTids, TRUE);
  }

  /**
   * Sends an escalation email notification to the target jurisdiction.
   *
   * @param \Drupal\group\Entity\GroupInterface $jurGroup
   *   The target jurisdiction group.
   * @param \Drupal\node\NodeInterface $node
   *   The escalated service request node.
   * @param string $note
   *   The escalation note.
   */
  protected function sendEscalationNotification($jurGroup, NodeInterface $node, string $note): void {
    if (!$jurGroup->hasField('field_jurisdiction_e_mail')
        || $jurGroup->get('field_jurisdiction_e_mail')->isEmpty()) {
      $this->logger->notice('No email configured for jurisdiction "@jur" (id=@jid). Skipping escalation notification.', [
        '@jur' => $jurGroup->label(),
        '@jid' => $jurGroup->id(),
      ]);
      return;
    }

    $to = $jurGroup->get('field_jurisdiction_e_mail')->value;
    if (empty($to)) {
      return;
    }

    $params = [
      'node' => $node,
      'jurisdiction' => $jurGroup,
      'note' => $note,
      'subject' => (string) $this->t('Service request @id escalated to @jurisdiction', [
        '@id' => $node->getTitle(),
        '@jurisdiction' => $jurGroup->label(),
      ]),
      'body' => (string) $this->t("Service request @id has been escalated to @jurisdiction.\n\nNote: @note", [
        '@id' => $node->getTitle(),
        '@jurisdiction' => $jurGroup->label(),
        '@note' => $note,
      ]),
    ];

    $langcode = $this->currentUser->getPreferredLangcode();
    $result = $this->mailManager->mail(
      'markaspot_escalation',
      'escalation_notification',
      $to,
      $langcode,
      $params,
      NULL,
      TRUE
    );

    if (!$result['result']) {
      $this->logger->error('Failed to send escalation notification to @email for node @nid.', [
        '@email' => $to,
        '@nid' => $node->id(),
      ]);
    }
  }

  /**
   * Sends a delegation email notification to the target organisation.
   *
   * @param \Drupal\group\Entity\GroupInterface $orgGroup
   *   The target organisation group.
   * @param \Drupal\node\NodeInterface $node
   *   The delegated service request node.
   * @param string $note
   *   The delegation note.
   */
  protected function sendDelegationNotification($orgGroup, NodeInterface $node, string $note): void {
    if (!$orgGroup->hasField('field_head_organisation_e_mail')
        || $orgGroup->get('field_head_organisation_e_mail')->isEmpty()) {
      $this->logger->notice('No email configured for organisation "@org" (id=@oid). Skipping delegation notification.', [
        '@org' => $orgGroup->label(),
        '@oid' => $orgGroup->id(),
      ]);
      return;
    }

    $to = $orgGroup->get('field_head_organisation_e_mail')->value;
    if (empty($to)) {
      return;
    }

    $params = [
      'node' => $node,
      'organisation' => $orgGroup,
      'note' => $note,
      'subject' => (string) $this->t('Service request @id delegated to @organisation', [
        '@id' => $node->getTitle(),
        '@organisation' => $orgGroup->label(),
      ]),
      'body' => (string) $this->t("Service request @id has been delegated to @organisation.\n\nNote: @note", [
        '@id' => $node->getTitle(),
        '@organisation' => $orgGroup->label(),
        '@note' => $note,
      ]),
    ];

    $langcode = $this->currentUser->getPreferredLangcode();
    $result = $this->mailManager->mail(
      'markaspot_escalation',
      'delegation_notification',
      $to,
      $langcode,
      $params,
      NULL,
      TRUE
    );

    if (!$result['result']) {
      $this->logger->error('Failed to send delegation notification to @email for node @nid.', [
        '@email' => $to,
        '@nid' => $node->id(),
      ]);
    }
  }

}
