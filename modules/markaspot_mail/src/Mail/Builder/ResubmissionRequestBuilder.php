<?php

declare(strict_types=1);

namespace Drupal\markaspot_mail\Mail\Builder;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Utility\Token;
use Drupal\markaspot_mail\Enum\MailType;
use Drupal\markaspot_mail\Mail\MailBuilderInterface;
use Drupal\markaspot_mail\Mail\MailContext;
use Drupal\markaspot_mail\Mail\MailMessage;
use Drupal\markaspot_mail\Mail\ResolveJurisdictionFromNodeTrait;
use Drupal\markaspot_mail\Mail\SplitParagraphsTrait;
use Drupal\markaspot_mail\Service\MailTextResolver;
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

  use ResolveJurisdictionFromNodeTrait;
  use SplitParagraphsTrait;
  use StringTranslationTrait;

  public function __construct(
    private readonly MailTextResolver $textResolver,
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

    [$mode, $jurisdictionId] = $this->resolveJurisdictionFromNode($node);

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
    $template = $this->textResolver->resolveField('markaspot_resubmission.mail', 'resubmit_request', $key, $langcode);
    if ($template === '') {
      return '';
    }
    return (string) $this->token->replace($template, ['node' => $node], [
      'langcode' => $langcode,
      'clear' => TRUE,
    ]);
  }

}
