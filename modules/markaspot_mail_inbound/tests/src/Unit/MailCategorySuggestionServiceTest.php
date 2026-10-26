<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail_inbound\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Lock\NullLockBackend;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_ai\Service\AiClientService;
use Drupal\markaspot_mail_inbound\Entity\InboundMail;
use Drupal\markaspot_mail_inbound\Plugin\QueueWorker\MailCategorySuggestionQueueWorker;
use Drupal\markaspot_mail_inbound\Service\InboundMailPromoter;
use Drupal\markaspot_mail_inbound\Service\MailCategorySuggestionService;
use Drupal\markaspot_mail_inbound\Service\PromotableCategoryRepository;
use Drupal\markaspot_nuxt\Service\FeatureScopeResolver;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for MailCategorySuggestionService gate logic and description.
 *
 * All AI calls are mocked — zero real API calls. Tests cover:
 * - Gates -> skipped (module setting, feature flag, token budget, service none)
 * - Vision vs text path selection
 * - Invalid tid from model -> failed
 * - Text path: verdict validated, address stored, done status.
 * - Description storage: vision path reads 'description' from ai_result,
 *   text path reads 'summary'; both are sanitized before storing.
 * - sanitizeDescription: control chars stripped, length capped.
 *
 * @group markaspot_mail_inbound
 * @coversDefaultClass \Drupal\markaspot_mail_inbound\Service\MailCategorySuggestionService
 */
class MailCategorySuggestionServiceTest extends UnitTestCase {

  /**
   * Creates a service with selectively stubbed dependencies.
   */
  protected function service(
    array $settings = [],
    bool $aiEnabled = TRUE,
    bool $tokenOk = TRUE,
    ?object $vision = NULL,
    ?object $aiClient = NULL,
    ?object $tokenTracking = NULL,
    ?object $featureScopeResolver = NULL,
    int $jurId = 1,
    array $categories = [],
    ?TermInterface $term = NULL,
    ?LoggerChannelInterface $logger = NULL,
    ?LockBackendInterface $lock = NULL,
  ): TestableSuggestionService {
    // Build config mock.
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['ai_suggestions_enabled', $settings['ai_suggestions_enabled'] ?? TRUE],
      ['auto_promote_enabled', FALSE],
      ['auto_promote_confidence_threshold', 0.85],
    ]);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    // Token tracking stub.
    if ($tokenTracking === NULL && $tokenOk !== NULL) {
      $tokenTrackingMock = new class($tokenOk) {

        /**
         * Whether the token budget allows a call.
         *
         * @var bool
         */
        public bool $ok;

        /**
         * Constructs the stub.
         */
        public function __construct(bool $ok) {
          $this->ok = $ok;
        }

        /**
         * Returns whether the budget allows the call.
         */
        public function checkLimit(): bool {
          return $this->ok;
        }

      };
      $tokenTracking = $tokenTrackingMock;
    }

    // Exercise the production tenant gate through its current dependency.
    if ($featureScopeResolver === NULL) {
      $featureScopeResolver = $this->createMock(FeatureScopeResolver::class);
      $featureScopeResolver->method('isEnabledEffective')->willReturn($aiEnabled);
    }

    // Entity type manager: taxonomy_term storage.
    $termStorage = $this->createMock(EntityStorageInterface::class);
    $termStorage->method('load')->willReturnCallback(
      static fn($id) => $term
    );
    $termStorage->method('loadByProperties')->willReturn([]);
    $jurisdiction = $this->createMock(GroupInterface::class);
    $jurisdiction->method('id')->willReturn($jurId);
    $groupStorage = $this->createMock(EntityStorageInterface::class);
    $groupStorage->method('load')->willReturnMap([[$jurId, $jurisdiction]]);

    // Persisting reloads the mail inside the promotion lock. By default the
    // storage hands back the very mail passed to suggest(), so expectations
    // on that double still apply; a test sets $svc->storedMail to model a
    // copy that changed in storage meanwhile.
    $svc = NULL;
    $mailStorage = $this->createMock(EntityStorageInterface::class);
    $mailStorage->method('loadUnchanged')->willReturnCallback(
      static function () use (&$svc) {
        return $svc->storedMail ?? $svc->lastSuggestedMail;
      }
    );

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->willReturnMap([
      ['taxonomy_term', $termStorage],
      ['group', $groupStorage],
      ['inbound_mail', $mailStorage],
    ]);

    // Language manager stub: always returns 'de' as site default.
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('de');
    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $languageManager->method('getDefaultLanguage')->willReturn($language);

    $categoryRepo = $this->createMock(PromotableCategoryRepository::class);
    $logger ??= $this->createMock(LoggerChannelInterface::class);

    $svc = new TestableSuggestionService(
      $etm,
      $configFactory,
      $categoryRepo,
      $logger,
      $languageManager,
      $lock ?? new NullLockBackend(),
      $vision,
      $aiClient,
      $tokenTracking,
      $featureScopeResolver,
    );
    $svc->overrideCategories = $categories;
    $svc->overrideTerm = $term;

    return $svc;
  }

  /**
   * Builds an InboundMail stub for testing.
   */
  protected function mail(int $jurId = 0, array $attachmentIds = [], string $state = InboundMail::STATE_STAGED): InboundMail {
    $mail = $this->getMockBuilder(InboundMail::class)
      ->disableOriginalConstructor()
      ->onlyMethods([
        'getJurisdictionId',
        'getAttachmentFileIds',
        'id',
        'getSubject',
        'getBody',
        'setSuggestedCategoryTid',
        'setSuggestionConfidence',
        'setSuggestionStatus',
        'setSuggestedAddress',
        'setSuggestedDescription',
        'getSuggestionStatus',
        'getSuggestedCategoryTid',
        'getSuggestionConfidence',
        'getState',
        'save',
      ])
      ->getMock();

    $mail->method('getState')->willReturn($state);
    $mail->method('getJurisdictionId')->willReturn($jurId);
    $mail->method('getAttachmentFileIds')->willReturn($attachmentIds);
    $mail->method('id')->willReturn(99);
    $mail->method('getSubject')->willReturn('Test subject');
    $mail->method('getBody')->willReturn('Test body');

    return $mail;
  }

  /**
   * Gate 1: module setting disabled -> skipped.
   *
   * @covers ::suggest
   */
  public function testModuleSettingDisabledSkips(): void {
    $svc = $this->service(settings: ['ai_suggestions_enabled' => FALSE]);
    $mail = $this->mail();

    $mail->expects($this->once())
      ->method('setSuggestionStatus')
      ->with(InboundMail::SUGGESTION_SKIPPED);
    $mail->expects($this->once())->method('save');

    $svc->suggest($mail);
  }

  /**
   * Gate 2: tenant feature flag disabled -> skipped.
   *
   * @covers ::suggest
   */
  public function testTenantFeatureFlagDisabledSkips(): void {
    $resolver = $this->createMock(FeatureScopeResolver::class);
    $resolver->expects($this->once())->method('isEnabledEffective')
      ->with('aiAnalysis', $this->callback(static fn(GroupInterface $group): bool => $group->id() === 17), TRUE)
      ->willReturn(FALSE);
    $provider = $this->createMock(AiClientService::class);
    $provider->expects($this->never())->method('chat');
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->once())->method('info')->with(
      'AI suggestion skipped for mail @id: tenant AI feature disabled (jur @jid).',
      ['@id' => 99, '@jid' => 17],
    );
    $svc = $this->service(
      aiClient: $provider,
      featureScopeResolver: $resolver,
      jurId: 17,
      categories: [['tid' => 5, 'path' => 'Roads', 'label' => 'Roads']],
      logger: $logger,
    );
    $mail = $this->mail(jurId: 17);

    $mail->expects($this->once())
      ->method('setSuggestionStatus')
      ->with(InboundMail::SUGGESTION_SKIPPED);
    $mail->expects($this->once())->method('save');

    $svc->suggest($mail);
  }

  /**
   * An enabled tenant feature reaches the provider and stores its suggestion.
   *
   * @covers ::suggest
   */
  public function testTenantFeatureFlagEnabledReachesProvider(): void {
    $resolver = $this->createMock(FeatureScopeResolver::class);
    $resolver->expects($this->once())->method('isEnabledEffective')
      ->with('aiAnalysis', $this->callback(static fn(GroupInterface $group): bool => $group->id() === 17), TRUE)
      ->willReturn(TRUE);
    $provider = $this->createMock(AiClientService::class);
    $provider->expects($this->once())->method('chat')->willReturn([
      'choices' => [['message' => ['content' => json_encode([
        'category_tid' => 5,
        'confidence' => 0.9,
        'address' => NULL,
        'is_report' => TRUE,
        'summary' => 'A road defect needs repair.',
      ])]]],
    ]);
    $svc = $this->service(
      aiClient: $provider,
      featureScopeResolver: $resolver,
      jurId: 17,
      categories: [['tid' => 5, 'path' => 'Roads', 'label' => 'Roads']],
    );
    $svc->overrideTermId = 5;
    $mail = $this->mail(jurId: 17);
    $mail->expects($this->once())->method('setSuggestedCategoryTid')->with(5);
    $mail->expects($this->once())->method('setSuggestionStatus')->with(InboundMail::SUGGESTION_DONE);
    $mail->expects($this->once())->method('save');
    $svc->suggest($mail);
  }

  /**
   * Gate 3: token budget exhausted -> skipped.
   *
   * @covers ::suggest
   */
  public function testTokenBudgetExhaustedSkips(): void {
    $svc = $this->service(tokenOk: FALSE);
    $mail = $this->mail();

    $mail->expects($this->once())
      ->method('setSuggestionStatus')
      ->with(InboundMail::SUGGESTION_SKIPPED);
    $mail->expects($this->once())->method('save');

    $svc->suggest($mail);
  }

  /**
   * Gate 4: no service available -> skipped.
   *
   * @covers ::suggest
   */
  public function testNoServiceAvailableSkips(): void {
    $svc = $this->service(vision: NULL, aiClient: NULL);
    $mail = $this->mail();

    $mail->expects($this->once())
      ->method('setSuggestionStatus')
      ->with(InboundMail::SUGGESTION_SKIPPED);
    $mail->expects($this->once())->method('save');

    $svc->suggest($mail);
  }

  /**
   * Vision path selected when attachments present; description from ai_result.
   *
   * @covers ::suggestVision
   */
  public function testVisionPathSelectedWhenAttachmentsPresent(): void {
    $term = $this->createMock(TermInterface::class);
    $term->method('label')->willReturn('Graffiti');

    // Vision service returns valid ai_result with category=42 and description.
    $visionMock = new class {

      /**
       * Whether processImages() was called.
       *
       * @var bool
       */
      public bool $called = FALSE;

      /**
       * Returns a fake vision result with category and description.
       */
      public function processImages(
        array $uris,
        ?string $lang,
        ?int $jur,
      ): ?array {
        $this->called = TRUE;
        return [
          'ai_result' => json_encode([
            'category' => 42,
            'is_reportable_issue' => TRUE,
            'description' => 'A graffiti tag is visible on the wall.',
          ]),
          'blur_results' => [],
        ];
      }

    };

    $svc = $this->service(vision: $visionMock, categories: []);
    // Override termExists to return TRUE for tid 42.
    $svc->overrideTerm = $term;
    $svc->overrideTermId = 42;

    $mail = $this->mail(jurId: 1, attachmentIds: [10]);
    // Override resolveAttachmentUris so we don't need a real entity manager.
    $svc->overrideAttachmentUris = ['public://test.jpg'];

    $mail->expects($this->once())
      ->method('setSuggestedCategoryTid')
      ->with(42);
    // Vision path: no confidence.
    $mail->expects($this->once())
      ->method('setSuggestionConfidence')
      ->with(NULL);
    // Description from vision ai_result['description'].
    $mail->expects($this->once())
      ->method('setSuggestedDescription')
      ->with('A graffiti tag is visible on the wall.');
    $mail->expects($this->once())
      ->method('setSuggestionStatus')
      ->with(InboundMail::SUGGESTION_DONE);

    $svc->suggest($mail);

    $this->assertTrue($visionMock->called);
  }

  /**
   * Vision path: no description in ai_result -> setter not called.
   *
   * @covers ::suggestVision
   */
  public function testVisionPathWithoutDescriptionDoesNotCallSetter(): void {
    $term = $this->createMock(TermInterface::class);

    $visionMock = new class {

      /**
       * Returns a fake vision result without a description key.
       */
      public function processImages(
        array $uris,
        ?string $lang,
        ?int $jur,
      ): ?array {
        return [
          'ai_result' => json_encode(['category' => 42]),
          'blur_results' => [],
        ];
      }

    };

    $svc = $this->service(vision: $visionMock, categories: []);
    $svc->overrideTerm = $term;
    $svc->overrideTermId = 42;

    $mail = $this->mail(jurId: 1, attachmentIds: [10]);
    $svc->overrideAttachmentUris = ['public://test.jpg'];

    // No description key in ai_result -> setSuggestedDescription not called.
    $mail->expects($this->never())->method('setSuggestedDescription');
    $mail->expects($this->once())
      ->method('setSuggestionStatus')
      ->with(InboundMail::SUGGESTION_DONE);

    $svc->suggest($mail);
  }

  /**
   * Text path selected when no attachments; description from 'summary' field.
   *
   * @covers ::suggestText
   */
  public function testTextPathSelectedWhenNoAttachments(): void {
    $term = $this->createMock(TermInterface::class);
    $term->method('label')->willReturn('Streetlight');

    $aiClientMock = new class {

      /**
       * Whether chat() was called.
       *
       * @var bool
       */
      public bool $called = FALSE;

      /**
       * Returns a fake chat response with category, confidence and summary.
       */
      public function chat(array $messages, array $options = []): array {
        $this->called = TRUE;
        return [
          'choices' => [
            [
              'message' => [
                'content' => json_encode([
                  'category_tid' => 7,
                  'confidence' => 0.92,
                  'address' => 'Hauptstrasse 12',
                  'is_report' => TRUE,
                  'summary' => 'A streetlight is not working.',
                ]),
              ],
            ],
          ],
        ];
      }

    };

    $svc = $this->service(aiClient: $aiClientMock, categories: [
      ['tid' => 7, 'path' => 'Streetlight', 'label' => 'Streetlight'],
    ]);
    $svc->overrideTerm = $term;
    $svc->overrideTermId = 7;

    $mail = $this->mail(jurId: 1, attachmentIds: []);

    $mail->expects($this->once())
      ->method('setSuggestedCategoryTid')
      ->with(7);
    $mail->expects($this->once())
      ->method('setSuggestionConfidence')
      ->with(0.92);
    $mail->expects($this->once())
      ->method('setSuggestedAddress')
      ->with('Hauptstrasse 12');
    // Description from the 'summary' field.
    $mail->expects($this->once())
      ->method('setSuggestedDescription')
      ->with('A streetlight is not working.');
    $mail->expects($this->once())
      ->method('setSuggestionStatus')
      ->with(InboundMail::SUGGESTION_DONE);

    $svc->suggest($mail);

    $this->assertTrue($aiClientMock->called);
  }

  /**
   * Text path: null summary -> setSuggestedDescription not called.
   *
   * @covers ::suggestText
   */
  public function testTextPathWithNullSummaryDoesNotCallSetter(): void {
    $term = $this->createMock(TermInterface::class);

    $aiClientMock = new class {

      /**
       * Returns a chat response with a null summary field.
       */
      public function chat(array $messages, array $options = []): array {
        return [
          'choices' => [
            [
              'message' => [
                'content' => json_encode([
                  'category_tid' => 7,
                  'confidence' => 0.80,
                  'address' => NULL,
                  'is_report' => TRUE,
                  'summary' => NULL,
                ]),
              ],
            ],
          ],
        ];
      }

    };

    $svc = $this->service(aiClient: $aiClientMock, categories: [
      ['tid' => 7, 'path' => 'Roads', 'label' => 'Roads'],
    ]);
    $svc->overrideTerm = $term;
    $svc->overrideTermId = 7;

    $mail = $this->mail(attachmentIds: []);

    // summary=null -> setSuggestedDescription must NOT be called.
    $mail->expects($this->never())->method('setSuggestedDescription');
    $mail->expects($this->once())
      ->method('setSuggestionStatus')
      ->with(InboundMail::SUGGESTION_DONE);

    $svc->suggest($mail);
  }

  /**
   * SanitizeDescription: strips control chars, collapses whitespace, caps len.
   *
   * @covers ::sanitizeDescription
   */
  public function testSanitizeDescription(): void {
    $svc = $this->service(aiClient: new class {

      /**
       * Stub chat method for the service factory.
       */
      public function chat(array $m, array $o = []): array {
        return [];
      }

    });

    // Control chars stripped (except \n preserved).
    $raw = "Line one\x00\x01\x02.\nLine two\x0B\x0C";
    $result = $svc->exposeSanitizeDescription($raw);
    $this->assertSame("Line one.\nLine two", $result);

    // Multiple newlines collapsed to max 2.
    $result = $svc->exposeSanitizeDescription("Para1\n\n\n\nPara2");
    $this->assertSame("Para1\n\nPara2", $result);

    // Horizontal whitespace collapsed.
    $result = $svc->exposeSanitizeDescription("word1   \t  word2");
    $this->assertSame('word1 word2', $result);

    // Length cap at 2000 chars.
    $long = str_repeat('x', 2500);
    $result = $svc->exposeSanitizeDescription($long);
    $this->assertSame(2000, mb_strlen($result));

    // Empty after trim -> NULL.
    $result = $svc->exposeSanitizeDescription("   \x01\x02  ");
    $this->assertNull($result);
  }

  /**
   * Invalid tid from text model -> failed.
   *
   * @covers ::suggest
   */
  public function testInvalidTidFromModelFails(): void {
    $aiClientMock = new class {

      /**
       * Returns a chat response with a tid that does not exist.
       */
      public function chat(array $messages, array $options = []): array {
        return [
          'choices' => [
            [
              'message' => [
                'content' => json_encode([
                  'category_tid' => 9999,
                  'confidence' => 0.9,
                  'address' => NULL,
                  'is_report' => TRUE,
                  'summary' => NULL,
                ]),
              ],
            ],
          ],
        ];
      }

    };

    // No term exists for tid 9999.
    $svc = $this->service(aiClient: $aiClientMock, categories: [
      ['tid' => 5, 'path' => 'Roads', 'label' => 'Roads'],
    ], term: NULL);
    $svc->overrideTermId = NULL;

    $mail = $this->mail(attachmentIds: []);

    $mail->expects($this->once())
      ->method('setSuggestionStatus')
      ->with(InboundMail::SUGGESTION_FAILED);
    $mail->expects($this->never())->method('setSuggestedCategoryTid');

    $svc->suggest($mail);
  }

  /**
   * Text path: no categories available -> skipped.
   *
   * @covers ::suggest
   */
  public function testNoCategoriesToClassifySkips(): void {
    $aiClientMock = new class {

      /**
       * Whether chat() was called.
       *
       * @var bool
       */
      public bool $called = FALSE;

      /**
       * Returns an empty choices array to detect unexpected calls.
       */
      public function chat(array $messages, array $options = []): array {
        $this->called = TRUE;
        return ['choices' => []];
      }

    };

    $svc = $this->service(aiClient: $aiClientMock, categories: []);

    $mail = $this->mail(attachmentIds: []);

    $mail->expects($this->once())
      ->method('setSuggestionStatus')
      ->with(InboundMail::SUGGESTION_SKIPPED);

    $svc->suggest($mail);

    $this->assertFalse($aiClientMock->called);
  }

  /**
   * The result goes onto the stored copy; the caller's stale mail is not saved.
   *
   * @covers ::persistSuggestion
   */
  public function testSuggestionIsWrittenOntoStoredCopyNotStaleMail(): void {
    $svc = $this->service(aiClient: $this->reportClient(), categories: [
      ['tid' => 7, 'path' => 'Streetlight', 'label' => 'Streetlight'],
    ]);
    $svc->overrideTermId = 7;

    $stale = $this->mail(jurId: 1);
    $untouched = [
      'setSuggestedCategoryTid',
      'setSuggestionConfidence',
      'setSuggestedAddress',
      'setSuggestedDescription',
      'setSuggestionStatus',
      'save',
    ];
    foreach ($untouched as $method) {
      $stale->expects($this->never())->method($method);
    }

    $fresh = $this->mail(jurId: 1);
    $fresh->expects($this->once())->method('setSuggestedCategoryTid')->with(7);
    $fresh->expects($this->once())->method('setSuggestionConfidence')->with(0.92);
    $fresh->expects($this->once())->method('setSuggestedAddress')->with('Hauptstrasse 12');
    $fresh->expects($this->once())->method('setSuggestedDescription')->with('A streetlight is not working.');
    $fresh->expects($this->once())->method('setSuggestionStatus')->with(InboundMail::SUGGESTION_DONE);
    $fresh->expects($this->once())->method('save');
    $svc->storedMail = $fresh;

    $svc->suggest($stale);
  }

  /**
   * A mail promoted during the AI call gets no suggestion and no save.
   *
   * @covers ::persistSuggestion
   */
  public function testMailPromotedDuringAiCallIsNotWritten(): void {
    $messages = [];
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->method('info')->willReturnCallback(function ($message) use (&$messages): void {
      $messages[] = (string) $message;
    });
    $svc = $this->service(aiClient: $this->reportClient(), categories: [
      ['tid' => 7, 'path' => 'Streetlight', 'label' => 'Streetlight'],
    ], logger: $logger);
    $svc->overrideTermId = 7;

    $stale = $this->mail(jurId: 1);
    $stale->expects($this->never())->method('save');
    $promoted = $this->mail(jurId: 1, state: InboundMail::STATE_PROMOTED);
    $promoted->expects($this->never())->method('setSuggestionStatus');
    $promoted->expects($this->never())->method('save');
    $svc->storedMail = $promoted;

    $svc->suggest($stale);

    $this->assertContains('AI suggestion for mail @id not stored: the mail is @state, not staged.', $messages);
  }

  /**
   * A promotion holding the lock: one short wait, then nothing is saved.
   *
   * @covers ::persistSuggestion
   */
  public function testLockHeldByPromotionSkipsPersisting(): void {
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->exactly(2))
      ->method('acquire')
      ->with('markaspot_mail_inbound.promote.99')
      ->willReturn(FALSE);
    $lock->expects($this->once())
      ->method('wait')
      ->with('markaspot_mail_inbound.promote.99');
    $lock->expects($this->never())->method('release');
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->once())
      ->method('warning')
      ->with('AI suggestion for mail @id not stored: the mail is locked by a promotion.');

    $svc = $this->service(aiClient: $this->reportClient(), categories: [
      ['tid' => 7, 'path' => 'Streetlight', 'label' => 'Streetlight'],
    ], logger: $logger, lock: $lock);
    $svc->overrideTermId = 7;

    $mail = $this->mail(jurId: 1);
    $mail->expects($this->never())->method('save');

    $svc->suggest($mail);
  }

  /**
   * An AI client double answering with a valid report verdict for tid 7.
   */
  protected function reportClient(): object {
    return new class {

      /**
       * Returns a valid report verdict.
       */
      public function chat(array $messages, array $options = []): array {
        return [
          'choices' => [
            [
              'message' => [
                'content' => json_encode([
                  'category_tid' => 7,
                  'confidence' => 0.92,
                  'address' => 'Hauptstrasse 12',
                  'is_report' => TRUE,
                  'summary' => 'A streetlight is not working.',
                ]),
              ],
            ],
          ],
        ];
      }

    };
  }

  /**
   * CouldRun() returns FALSE when module setting is disabled.
   *
   * @covers ::couldRun
   */
  public function testCouldRunFalseWhenSettingDisabled(): void {
    $svc = $this->service(
      settings: ['ai_suggestions_enabled' => FALSE],
      aiClient: new class {

        /**
         * Stub chat method for the service factory.
         */
        public function chat(array $m, array $o = []): array {
          return [];
        }

      },
    );
    $this->assertFalse($svc->couldRun());
  }

  /**
   * CouldRun() returns TRUE when setting enabled and at least one service.
   *
   * @covers ::couldRun
   */
  public function testCouldRunTrueWhenServiceWired(): void {
    $svc = $this->service(
      aiClient: new class {

        /**
         * Stub chat method for the service factory.
         */
        public function chat(array $m, array $o = []): array {
          return [];
        }

      },
    );
    $this->assertTrue($svc->couldRun());
  }

  /**
   * Provider answers fed through suggest() and the real promotion gate.
   *
   * Each answer goes through the real queue worker processItem(): it loads a
   * stateful staged mail, runs the real suggestion service and then its own
   * auto-promote gate (auto-promote enabled, threshold 0.85, a promotable
   * category). Only a valid report verdict above the threshold may reach the
   * promoter; every other mail stays staged.
   *
   * @dataProvider verdictPromotionProvider
   * @covers ::suggestText
   */
  public function testVerdictThroughSuggestionAndPromotion(array $answer, string $status, bool $promoted, ?float $storedConfidence): void {
    $aiClient = new class($answer) {

      /**
       * Constructs the stub.
       */
      public function __construct(private array $answer) {}

      /**
       * Returns the configured answer as JSON content.
       */
      public function chat(array $messages, array $options = []): array {
        return ['choices' => [['message' => ['content' => json_encode($this->answer)]]]];
      }

    };

    $svc = $this->service(aiClient: $aiClient, categories: [
      ['tid' => 7, 'path' => 'Streetlight', 'label' => 'Streetlight'],
    ]);
    $svc->overrideTerm = $this->createMock(TermInterface::class);
    $svc->overrideTermId = 7;

    $mail = $this->statefulMail();

    $promoter = $this->createMock(InboundMailPromoter::class);
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn(500);
    $promoter->expects($promoted ? $this->once() : $this->never())
      ->method('promoteToServiceRequest')
      ->willReturnCallback(function (InboundMail $promotedMail) use ($node) {
        $promotedMail->setState(InboundMail::STATE_PROMOTED);
        return $node;
      });

    $this->promotionWorker($promoter, $svc, $mail)->processItem(['mail_id' => 99]);

    $this->assertSame($status, $mail->getSuggestionStatus());
    $this->assertSame($storedConfidence, $mail->getSuggestionConfidence());
    $this->assertSame($promoted ? InboundMail::STATE_PROMOTED : InboundMail::STATE_STAGED, $mail->getState());
    if ($status !== InboundMail::SUGGESTION_DONE) {
      // A rejected verdict stores no category hint either.
      $this->assertNull($mail->getSuggestedCategoryTid());
    }
  }

  /**
   * Provider answers and their expected outcome.
   */
  public static function verdictPromotionProvider(): array {
    $valid = [
      'category_tid' => 7,
      'confidence' => 0.95,
      'address' => NULL,
      'is_report' => TRUE,
      'summary' => NULL,
    ];
    $missing = $valid;
    unset($missing['is_report']);
    $failed = InboundMail::SUGGESTION_FAILED;
    $done = InboundMail::SUGGESTION_DONE;
    return [
      'valid report above threshold' => [$valid, $done, TRUE, 0.95],
      'valid report below threshold' => [['confidence' => 0.5] + $valid, $done, FALSE, 0.5],
      'valid non-report stays reviewable' => [['is_report' => FALSE] + $valid, $done, FALSE, NULL],
      'missing is_report' => [$missing, $failed, FALSE, NULL],
      'null is_report' => [['is_report' => NULL] + $valid, $failed, FALSE, NULL],
      'string "false"' => [['is_report' => 'false'] + $valid, $failed, FALSE, NULL],
      'string "true"' => [['is_report' => 'true'] + $valid, $failed, FALSE, NULL],
      'numeric 1' => [['is_report' => 1] + $valid, $failed, FALSE, NULL],
      'numeric 0' => [['is_report' => 0] + $valid, $failed, FALSE, NULL],
      'array is_report' => [['is_report' => [TRUE]] + $valid, $failed, FALSE, NULL],
      'inflated confidence' => [['confidence' => 1.7] + $valid, $failed, FALSE, NULL],
      'string confidence' => [['confidence' => '0.99'] + $valid, $failed, FALSE, NULL],
      'category not offered' => [['category_tid' => 99] + $valid, $failed, FALSE, NULL],
    ];
  }

  /**
   * Builds a mail double that keeps the suggestion state it is given.
   */
  protected function statefulMail(): InboundMail {
    $state = new \ArrayObject([
      'state' => InboundMail::STATE_STAGED,
      'status' => InboundMail::SUGGESTION_PENDING,
      'tid' => NULL,
      'confidence' => NULL,
      'address' => NULL,
    ]);
    $mail = $this->getMockBuilder(InboundMail::class)
      ->disableOriginalConstructor()
      ->onlyMethods([
        'getJurisdictionId',
        'getAttachmentFileIds',
        'id',
        'getSubject',
        'getBody',
        'setSuggestedCategoryTid',
        'setSuggestionConfidence',
        'setSuggestionStatus',
        'setSuggestedAddress',
        'setSuggestedDescription',
        'getSuggestionStatus',
        'getSuggestedCategoryTid',
        'getSuggestionConfidence',
        'getSuggestedAddress',
        'getState',
        'setState',
        'save',
      ])
      ->getMock();
    $mail->method('getState')->willReturnCallback(fn() => $state['state']);
    $mail->method('setState')->willReturnCallback(function ($value) use ($state, &$mail) {
      $state['state'] = $value;
      return $mail;
    });
    $mail->method('getJurisdictionId')->willReturn(1);
    $mail->method('getAttachmentFileIds')->willReturn([]);
    $mail->method('id')->willReturn(99);
    $mail->method('getSubject')->willReturn('Streetlight');
    $mail->method('getBody')->willReturn('The streetlight is out.');
    $mail->method('setSuggestionStatus')->willReturnCallback(function ($value) use ($state, &$mail) {
      $state['status'] = $value;
      return $mail;
    });
    $mail->method('setSuggestedCategoryTid')->willReturnCallback(function ($value) use ($state, &$mail) {
      $state['tid'] = $value;
      return $mail;
    });
    $mail->method('setSuggestionConfidence')->willReturnCallback(function ($value) use ($state, &$mail) {
      $state['confidence'] = $value;
      return $mail;
    });
    $mail->method('setSuggestedAddress')->willReturnCallback(function ($value) use ($state, &$mail) {
      $state['address'] = $value;
      return $mail;
    });
    $mail->method('getSuggestionStatus')->willReturnCallback(fn() => $state['status']);
    $mail->method('getSuggestedCategoryTid')->willReturnCallback(fn() => $state['tid']);
    $mail->method('getSuggestionConfidence')->willReturnCallback(fn() => $state['confidence']);
    $mail->method('getSuggestedAddress')->willReturnCallback(fn() => $state['address']);
    return $mail;
  }

  /**
   * The real queue worker around the given service and mail.
   *
   * Auto-promote is enabled with threshold 0.85 and every category counts as
   * promotable, so only the verdict decides.
   */
  protected function promotionWorker(InboundMailPromoter $promoter, MailCategorySuggestionService $suggestionService, InboundMail $mail): MailCategorySuggestionQueueWorker {
    $mailStorage = $this->createMock(EntityStorageInterface::class);
    $mailStorage->method('load')->willReturnMap([[99, $mail]]);
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->willReturnMap([['inbound_mail', $mailStorage]]);

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['auto_promote_enabled', TRUE],
      ['auto_promote_confidence_threshold', 0.85],
    ]);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);
    $categoryRepo = $this->createMock(PromotableCategoryRepository::class);
    $categoryRepo->method('isPromotable')->willReturn(TRUE);

    return new MailCategorySuggestionQueueWorker(
      [],
      'markaspot_mail_inbound_suggest',
      [],
      $etm,
      $suggestionService,
      $promoter,
      $categoryRepo,
      $configFactory,
      $this->createMock(LoggerChannelInterface::class),
    );
  }

}

/**
 * Testable subclass exposing protected methods and overriding side-effects.
 */
class TestableSuggestionService extends MailCategorySuggestionService {

  /**
   * Override for getCategories().
   *
   * @var array|null
   */
  public ?array $overrideCategories = NULL;

  /**
   * Override for term lookup.
   *
   * @var \Drupal\taxonomy\TermInterface|null
   */
  public ?object $overrideTerm = NULL;

  /**
   * Override for validateTid() — the tid to accept as valid.
   *
   * @var int|null
   */
  public ?int $overrideTermId = NULL;

  /**
   * Override for resolveAttachmentUris().
   *
   * @var string[]|null
   */
  public ?array $overrideAttachmentUris = NULL;

  /**
   * The mail last passed to suggest().
   *
   * @var \Drupal\markaspot_mail_inbound\Entity\InboundMail|null
   */
  public ?InboundMail $lastSuggestedMail = NULL;

  /**
   * The copy the storage returns when persisting; NULL means the passed mail.
   *
   * @var \Drupal\markaspot_mail_inbound\Entity\InboundMail|null
   */
  public ?InboundMail $storedMail = NULL;

  /**
   * {@inheritdoc}
   */
  public function suggest(InboundMail $mail): void {
    $this->lastSuggestedMail = $mail;
    parent::suggest($mail);
  }

  /**
   * {@inheritdoc}
   */
  protected function getCategories(?int $jurId): array {
    return $this->overrideCategories ?? parent::getCategories($jurId);
  }

  /**
   * {@inheritdoc}
   */
  protected function validateTid(int $tid, ?int $jurId): bool {
    if ($this->overrideTermId !== NULL) {
      return $tid === $this->overrideTermId;
    }
    // No term configured: anything fails validation.
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  protected function resolveAttachmentUris(InboundMail $mail): array {
    return $this->overrideAttachmentUris ?? [];
  }

  /**
   * Exposes sanitizeDescription() for direct testing.
   */
  public function exposeSanitizeDescription(string $raw): ?string {
    return $this->sanitizeDescription($raw);
  }

}
