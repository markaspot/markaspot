<?php

declare(strict_types=1);

namespace Drupal\markaspot_ai\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_nuxt\Service\FeatureScopeResolver;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Scores workspaces for likely spam activity.
 *
 * The scanner combines deterministic traffic/content signals with optional
 * semantic classification through AiClientService. It is designed for
 * operator-run Drush jobs first; automatic blocking only happens when the
 * caller explicitly passes apply=true.
 */
class SpamRiskScannerService {

  /**
   * Constructs a new SpamRiskScannerService.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly AiClientService $aiClient,
    protected readonly TimeInterface $time,
    protected readonly FeatureScopeResolver $featureScopeResolver,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('markaspot_ai');
  }

  /**
   * The logger.
   */
  protected LoggerInterface $logger;

  /**
   * Scans recent service requests and returns one row per workspace.
   *
   * @return array<int, array<string, mixed>>
   *   Scan rows keyed numerically for Drush table output.
   */
  public function scanRecent(
    int $sinceTimestamp,
    ?string $workspace = NULL,
    int $limit = 500,
    bool $useAi = FALSE,
    bool $apply = FALSE,
    int $autoBlockThreshold = 95,
    int $newWorkspaceDays = 7,
    int $aiTriggerScore = 50,
    int $sampleLimit = 10,
    ?string $provider = NULL,
  ): array {
    $workspaceId = $workspace !== NULL && $workspace !== ''
      ? (int) $this->loadWorkspace($workspace)->id()
      : NULL;

    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'service_request')
      ->condition('created', $sinceTimestamp, '>=')
      ->sort('created', 'ASC')
      ->range(0, max(1, $limit));

    if ($workspaceId !== NULL) {
      $query->condition('field_jurisdiction.target_id', $workspaceId);
    }

    $ids = $query->execute();
    if ($ids === []) {
      return [];
    }

    $buckets = $this->buildWorkspaceBuckets($storage->loadMultiple($ids), $sampleLimit);
    $rows = [];
    foreach ($buckets as $groupId => $bucket) {
      $group = $this->entityTypeManager->getStorage('group')->load($groupId);
      if ($workspaceId === NULL && !$this->isNewWorkspace($bucket, $newWorkspaceDays)) {
        continue;
      }
      $signals = $this->scoreWorkspaceSignals($bucket);

      if ($useAi && $signals['risk_score'] >= $aiTriggerScore) {
        $signals = $this->mergeAiDecision($signals, $bucket, $provider);
      }

      $action = 'none';
      if ($apply && $signals['risk_score'] >= $autoBlockThreshold && $group instanceof GroupInterface) {
        $action = $this->blockWorkspace($group);
      }

      $rows[] = [
        'workspace_id' => (string) $groupId,
        'workspace' => $group instanceof GroupInterface ? (string) $group->label() : 'Unknown',
        'risk_score' => (string) $signals['risk_score'],
        'decision' => $signals['decision'],
        'action' => $action,
        'request_count' => (string) ($bucket['request_count'] ?? 0),
        'request_ids' => implode(', ', array_slice($bucket['request_ids'] ?? [], 0, 8)),
        'reasons' => implode('; ', array_slice($signals['reasons'], 0, 6)),
      ];
    }

    usort($rows, static fn(array $a, array $b): int => (int) $b['risk_score'] <=> (int) $a['risk_score']);
    return $rows;
  }

  /**
   * Scores a workspace signal bucket without calling an AI provider.
   *
   * @param array<string, mixed> $signals
   *   Workspace signals. Required keys are request_count and texts.
   *
   * @return array{risk_score:int,decision:string,reasons:array<int,string>}
   *   Deterministic risk score and explanation.
   */
  public function scoreWorkspaceSignals(array $signals): array {
    $score = 0;
    $reasons = [];
    $requestCount = (int) ($signals['request_count'] ?? 0);
    $texts = array_values(array_filter($signals['texts'] ?? [], 'is_string'));

    if ($requestCount >= 20) {
      $score += 40;
      $reasons[] = "{$requestCount} requests in scan window";
    }
    elseif ($requestCount >= 10) {
      $score += 25;
      $reasons[] = "{$requestCount} requests in scan window";
    }
    elseif ($requestCount >= 5) {
      $score += 10;
      $reasons[] = "{$requestCount} requests in scan window";
    }

    $oldest = (int) ($signals['oldest_created'] ?? 0);
    $newest = (int) ($signals['newest_created'] ?? 0);
    if ($requestCount >= 10 && $oldest > 0 && $newest > 0 && ($newest - $oldest) <= 3600) {
      $score += 20;
      $reasons[] = 'request burst within one hour';
    }

    $urlCount = $this->countUrls($texts);
    if ($urlCount > 0) {
      $score += min(30, $urlCount * 8);
      $reasons[] = "{$urlCount} external URL signal(s)";
    }

    $keywordHits = $this->countSuspiciousKeywordHits($texts);
    if ($keywordHits > 0) {
      $score += min(25, $keywordHits * 6);
      $reasons[] = "{$keywordHits} spam keyword signal(s)";
    }

    $duplicateCount = $this->countDuplicateTexts($texts);
    if ($duplicateCount > 0) {
      $score += min(25, $duplicateCount * 8);
      $reasons[] = "{$duplicateCount} duplicate text signal(s)";
    }

    $workspaceCreated = (int) ($signals['workspace_created'] ?? 0);
    if ($workspaceCreated > 0 && ($this->time->getRequestTime() - $workspaceCreated) <= 172800 && $requestCount >= 3) {
      $score += 15;
      $reasons[] = 'new workspace with immediate activity';
    }

    $score = max(0, min(100, $score));
    return [
      'risk_score' => $score,
      'decision' => $this->decisionForScore($score),
      'reasons' => $reasons ?: ['no spam signals detected'],
    ];
  }

  /**
   * Redacts request text before it is sent to an AI provider.
   */
  public function redactForAi(string $text): string {
    $text = strip_tags($text);
    $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]', $text) ?? $text;
    $text = preg_replace('/https?:\/\/\S+|www\.\S+/i', '[url]', $text) ?? $text;
    $text = preg_replace('/(?<!\d)(?:\+?\d[\d\s().-]{6,}\d)(?!\d)/', '[phone]', $text) ?? $text;
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    return trim(mb_substr($text, 0, 700));
  }

  /**
   * Builds buckets keyed by workspace ID.
   *
   * @param array<int, mixed> $nodes
   *   Loaded nodes.
   * @param int $sampleLimit
   *   Maximum text samples to retain per workspace.
   *
   * @return array<int, array<string, mixed>>
   *   Signal buckets keyed by jurisdiction group ID.
   */
  protected function buildWorkspaceBuckets(array $nodes, int $sampleLimit = 10): array {
    $buckets = [];
    foreach ($nodes as $node) {
      if (!$node instanceof NodeInterface || $node->bundle() !== 'service_request') {
        continue;
      }

      $groupId = $this->resolveNodeJurisdictionId($node);
      if ($groupId === NULL) {
        continue;
      }

      $created = (int) $node->getCreatedTime();
      $bucket = &$buckets[$groupId];
      $bucket['request_count'] = (int) ($bucket['request_count'] ?? 0) + 1;
      $bucket['oldest_created'] = min((int) ($bucket['oldest_created'] ?? $created), $created);
      $bucket['newest_created'] = max((int) ($bucket['newest_created'] ?? $created), $created);
      if (count($bucket['texts'] ?? []) < $sampleLimit) {
        $bucket['texts'][] = $this->nodeText($node);
        $bucket['request_ids'][] = $this->nodeRequestId($node);
      }

      if (!isset($bucket['workspace_created'])) {
        $group = $this->entityTypeManager->getStorage('group')->load($groupId);
        $bucket['workspace_created'] = $this->groupCreatedTime($group);
      }
      unset($bucket);
    }

    return $buckets;
  }

  /**
   * Checks whether a workspace is inside the new-workspace scan window.
   */
  protected function isNewWorkspace(array $bucket, int $newWorkspaceDays): bool {
    if ($newWorkspaceDays <= 0) {
      return TRUE;
    }

    $created = (int) ($bucket['workspace_created'] ?? 0);
    if ($created <= 0) {
      return FALSE;
    }

    return ($this->time->getRequestTime() - $created) <= ($newWorkspaceDays * 86400);
  }

  /**
   * Adds optional AI classification to deterministic signals.
   */
  protected function mergeAiDecision(array $signals, array $bucket, ?string $provider = NULL): array {
    $samples = array_slice(array_map(
      fn(string $text): string => $this->redactForAi($text),
      array_values(array_filter($bucket['texts'] ?? [], 'is_string'))
    ), 0, 10);

    // Pin the scanner to the existing Anthropic adapter in AiClientService.
    // The privacy contract (and operator docs) state samples go only to
    // Anthropic; falling back to the configured default_provider would silently
    // route to OpenAI/Azure/IONOS. An explicit non-empty $provider override is
    // still honoured for ad-hoc operator runs.
    $effectiveProvider = $provider !== NULL && $provider !== '' ? $provider : 'anthropic';

    try {
      $options = [
        'temperature' => 0,
        'max_tokens' => 500,
        'response_format' => ['type' => 'json_object'],
        'provider' => $effectiveProvider,
      ];

      $response = $this->aiClient->chat([
        [
          'role' => 'system',
          'content' => 'You classify municipal service-request workspaces for spam risk. Return compact JSON only with risk_score 0-100, decision clean|review|block_recommended, reasons array.',
        ],
        [
          'role' => 'user',
          'content' => json_encode([
            'deterministic_score' => $signals['risk_score'],
            'deterministic_reasons' => $signals['reasons'],
            'request_count' => $bucket['request_count'] ?? 0,
            'redacted_samples' => $samples,
          ], JSON_UNESCAPED_SLASHES),
        ],
      ], $options);

      $content = $response['choices'][0]['message']['content'] ?? '';
      $ai = json_decode((string) $content, TRUE);
      if (!is_array($ai)) {
        return $signals;
      }

      $aiScore = max(0, min(100, (int) ($ai['risk_score'] ?? 0)));
      if ($aiScore > (int) $signals['risk_score']) {
        $signals['risk_score'] = $aiScore;
        $signals['decision'] = $this->decisionForScore($aiScore);
      }

      $aiReasons = array_values(array_filter($ai['reasons'] ?? [], 'is_string'));
      foreach (array_slice($aiReasons, 0, 3) as $reason) {
        $signals['reasons'][] = 'AI: ' . mb_substr($reason, 0, 140);
      }
    }
    catch (\Throwable $e) {
      $this->logger->warning('Spam AI classification failed: @message', ['@message' => $e->getMessage()]);
      $signals['reasons'][] = 'AI classification unavailable';
    }

    return $signals;
  }

  /**
   * Blocks a workspace group.
   */
  protected function blockWorkspace(GroupInterface $group): string {
    if (!$this->featureScopeResolver->isSelfServicePlatform()) {
      return 'skipped_not_self_service';
    }

    if (!$group->hasField('field_visibility')) {
      return 'cannot_block_missing_field';
    }

    if (!$group->get('field_visibility')->isEmpty() && $group->get('field_visibility')->value === 'blocked') {
      return 'already_blocked';
    }

    $group->set('field_visibility', 'blocked');
    $group->save();
    return 'blocked';
  }

  /**
   * Loads a workspace by numeric ID or slug.
   */
  protected function loadWorkspace(string $workspace): GroupInterface {
    $storage = $this->entityTypeManager->getStorage('group');
    if (ctype_digit($workspace)) {
      $group = $storage->load((int) $workspace);
    }
    else {
      $groups = $storage->loadByProperties([
        'type' => 'jur',
        'field_slug' => $workspace,
      ]);
      $group = reset($groups) ?: NULL;
    }

    if (!$group instanceof GroupInterface || $group->bundle() !== 'jur') {
      throw new \InvalidArgumentException(sprintf('Workspace "%s" was not found.', $workspace));
    }

    return $group;
  }

  /**
   * Resolves a node's jurisdiction field.
   */
  protected function resolveNodeJurisdictionId(NodeInterface $node): ?int {
    if ($node->hasField('field_jurisdiction') && !$node->get('field_jurisdiction')->isEmpty()) {
      $groupId = (int) $node->get('field_jurisdiction')->target_id;
      return $groupId > 0 ? $groupId : NULL;
    }

    return NULL;
  }

  /**
   * Builds a compact text sample from a service request node.
   */
  protected function nodeText(NodeInterface $node): string {
    $parts = [(string) $node->label()];
    foreach (['body', 'field_description', 'field_request_description'] as $field) {
      if ($node->hasField($field) && !$node->get($field)->isEmpty()) {
        $parts[] = (string) ($node->get($field)->value ?? '');
      }
    }
    return trim(implode(' ', array_filter($parts)));
  }

  /**
   * Resolves a human-readable request identifier.
   */
  protected function nodeRequestId(NodeInterface $node): string {
    if ($node->hasField('request_id') && !$node->get('request_id')->isEmpty()) {
      return (string) $node->get('request_id')->value;
    }
    return (string) $node->id();
  }

  /**
   * Resolves a group creation timestamp when available.
   */
  protected function groupCreatedTime(mixed $group): int {
    if (is_object($group) && method_exists($group, 'getCreatedTime')) {
      return (int) $group->getCreatedTime();
    }
    if ($group instanceof GroupInterface && $group->hasField('created') && !$group->get('created')->isEmpty()) {
      return (int) $group->get('created')->value;
    }
    return 0;
  }

  /**
   * Counts URLs in text samples.
   */
  protected function countUrls(array $texts): int {
    $count = 0;
    foreach ($texts as $text) {
      if (preg_match_all('/https?:\/\/\S+|www\.\S+/i', $text, $matches)) {
        $count += count($matches[0]);
      }
    }
    return $count;
  }

  /**
   * Counts suspicious keyword hits.
   */
  protected function countSuspiciousKeywordHits(array $texts): int {
    $keywords = [
      'backlink', 'casino', 'crypto', 'escort', 'loan', 'seo',
      'telegram', 'viagra', 'whatsapp', 'free money', 'buy now',
    ];

    $hits = 0;
    $text = mb_strtolower(implode(' ', $texts));
    foreach ($keywords as $keyword) {
      if (str_contains($text, $keyword)) {
        $hits++;
      }
    }
    return $hits;
  }

  /**
   * Counts repeated normalized text samples.
   */
  protected function countDuplicateTexts(array $texts): int {
    $seen = [];
    $duplicates = 0;
    foreach ($texts as $text) {
      $normalized = mb_strtolower(trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? $text));
      if ($normalized === '' || mb_strlen($normalized) < 20) {
        continue;
      }
      $hash = hash('sha256', $normalized);
      if (isset($seen[$hash])) {
        $duplicates++;
      }
      $seen[$hash] = TRUE;
    }
    return $duplicates;
  }

  /**
   * Converts a risk score to a decision label.
   */
  protected function decisionForScore(int $score): string {
    return match (TRUE) {
      $score >= 95 => 'block_recommended',
      $score >= 80 => 'review_high',
      $score >= 50 => 'review',
      default => 'clean',
    };
  }

}
