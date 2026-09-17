<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_mail_inbound\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_ai\Service\AiClientService;
use Drupal\markaspot_mail_inbound\Entity\InboundMail;
use Drupal\markaspot_mail_inbound\Service\MailCategorySuggestionService;
use Drupal\markaspot_mail_inbound\Service\PromotableCategoryRepository;
use Drupal\markaspot_nuxt\Service\FeatureScopeResolver;
use Drupal\taxonomy\TermInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for MailCategorySuggestionService gate logic and description.
 *
 * All AI calls are mocked — zero real API calls. Tests cover:
 * - Gates -> skipped (module setting, feature flag, token budget, service none)
 * - Vision vs text path selection
 * - Invalid tid from model -> failed
 * - Text path: confidence clamped, address stored, done status.
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

    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->method('getStorage')->willReturnMap([
      ['taxonomy_term', $termStorage],
      ['group', $groupStorage],
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
  protected function mail(int $jurId = 0, array $attachmentIds = []): InboundMail {
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
        'save',
      ])
      ->getMock();

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
