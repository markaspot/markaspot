<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Mail\Builder;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Utility\Token;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\MailBuilderInterface;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\markaspot_mail\Mail\MailMessage;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Builder for markaspot_resubmission:resubmit_request mails.
 *
 * Sent by the markaspot_resubmission cron worker when a citizen's report
 * needs more information. The legacy hook_mail reads
 * markaspot_resubmission.mail.resubmit_request config (subject + body)
 * and runs Drupal token replacement with ['node' => $node]. We re-use
 * the same config + token pipeline but wrap the result in branded
 * card_transactional output.
 *
 * Required param:
 *   - node: NodeInterface (service_request)
 *
 * Mode: jurisdiction when the node's field_jurisdiction resolves to a
 * jur group; platform otherwise.
 */
final class ResubmissionRequestBuilder implements MailBuilderInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ConfigurableLanguageManagerInterface $languageManager,
    private readonly Token $token,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getType(): MailType {
    return MailType::ECA_RESUBMISSION;
  }

  /**
   * {@inheritdoc}
   */
  public function supports(string $module, string $key): bool {
    return $module === 'markaspot_resubmission' && $key === 'resubmit_request';
  }

  /**
   * {@inheritdoc}
   */
  public function build(MailContext $ctx): ?MailMessage {
    $node = $ctx->params['node'] ?? NULL;
    if (!$node instanceof NodeInterface) {
      $this->logger->warning('resubmit_request: missing or invalid "node" param, skipping branded render.');
      return NULL;
    }

    [$mode, $jurisdictionId] = $this->resolveJurisdiction($node);

    $subject = $this->resolveFromConfig('subject', $node, $ctx->langcode)
      ?: (string) $this->t('Your report needs more information', [], ['langcode' => $ctx->langcode]);

    $body = $this->resolveFromConfig('body', $node, $ctx->langcode);
    $paragraphs = $body !== '' ? $this->splitParagraphs($body) : [];
    $intro = array_shift($paragraphs) ?? (string) $this->t('We need a bit more information to process your report.', [], ['langcode' => $ctx->langcode]);

    return new MailMessage(
      subject: $subject,
      variant: 'card_transactional',
      content: [
        'preheader' => (string) $this->t('We need more information about your report', [], ['langcode' => $ctx->langcode]),
        'headline' => (string) $this->t('Please clarify your report', [], ['langcode' => $ctx->langcode]),
        'intro' => $intro,
        'body_blocks' => $paragraphs,
      ],
      mode: $mode,
      jurisdictionId: $jurisdictionId,
    );
  }

  /**
   * Reads a config template and runs Drupal token replacement.
   */
  private function resolveFromConfig(string $key, NodeInterface $node, string $langcode): string {
    $config = $this->languageManager
      ->getLanguageConfigOverride($langcode, 'markaspot_resubmission.mail')
      ->get('resubmit_request');
    if (!is_array($config) || empty($config[$key])) {
      $config = $this->configFactory
        ->get('markaspot_resubmission.mail')
        ->get('resubmit_request');
    }
    if (!is_array($config) || empty($config[$key])) {
      return '';
    }
    return (string) $this->token->replace((string) $config[$key], ['node' => $node], [
      'langcode' => $langcode,
      'clear' => TRUE,
    ]);
  }

  /**
   * Resolves (mode, jurisdictionId) from the node's field_jurisdiction.
   *
   * @return array{0: string, 1: int|null}
   */
  private function resolveJurisdiction(NodeInterface $node): array {
    if (!$node->hasField('field_jurisdiction')) {
      return ['platform', NULL];
    }
    $field = $node->get('field_jurisdiction');
    if (!$field instanceof EntityReferenceFieldItemListInterface || $field->isEmpty()) {
      return ['platform', NULL];
    }
    $target = $field->referencedEntities()[0] ?? NULL;
    if (!$target instanceof ContentEntityInterface
      || $target->getEntityTypeId() !== 'group'
      || $target->bundle() !== 'jur') {
      return ['platform', NULL];
    }
    return ['jurisdiction', (int) $target->id()];
  }

  /**
   * Splits a body string into paragraph-delimited blocks.
   *
   * @return list<string>
   */
  private function splitParagraphs(string $body): array {
    $raw = preg_split("/\n\s*\n/", $body) ?: [$body];
    $out = [];
    foreach ($raw as $paragraph) {
      $paragraph = trim($paragraph);
      if ($paragraph !== '') {
        $out[] = $paragraph;
      }
    }
    return $out;
  }

}
