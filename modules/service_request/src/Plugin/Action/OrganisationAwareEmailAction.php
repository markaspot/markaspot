<?php

namespace Drupal\service_request\Plugin\Action;

use Drupal\Core\Action\Plugin\Action\EmailAction;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\node\NodeInterface;
use Drupal\service_request\OrganisationNotificationPolicy;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Honors organisation opt-outs in legacy actions using the org mailbox token.
 *
 * No new plugin ID: markaspot_group replaces only the stock core email action.
 * Other recipient expressions and custom action implementations are untouched.
 */
class OrganisationAwareEmailAction extends EmailAction {

  /**
   * Whether the original recipient selects the first responsible org mailbox.
   */
  protected bool $usesOrganisationMailbox = FALSE;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    // Capture before ECA replaces tokens in setConfiguration(). Recipient
    // addresses cannot safely identify mail purpose (mailboxes may be shared).
    $instance->usesOrganisationMailbox = in_array(trim((string) ($configuration['recipient'] ?? '')), [
      '[node:field_organisation:entity:field_head_organisation_e_mail]',
      '[node:field_organisation:entity:field_head_organisation_e_mail:value]',
    ], TRUE);
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    $node = $this->configuration['node'] ?? $entity;
    if ($this->usesOrganisationMailbox && $node instanceof NodeInterface
      && $node->hasField('field_organisation')
      && !$node->get('field_organisation')->isEmpty()) {
      // A chained :entity token resolves the first field item, not all orgs.
      $organisation = $node->get('field_organisation')->entity;
      if ($organisation instanceof FieldableEntityInterface
        && !OrganisationNotificationPolicy::isEnabled($organisation)) {
        return;
      }
    }
    parent::execute($entity);
  }

}
