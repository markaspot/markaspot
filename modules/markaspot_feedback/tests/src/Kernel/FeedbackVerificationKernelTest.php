<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_feedback\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\markaspot_feedback\Controller\FeedbackController;
use Drupal\markaspot_feedback\FeedbackService;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests that only the reporter can give feedback on a report.
 *
 * @group markaspot_feedback
 */
#[RunTestsInSeparateProcesses]
final class FeedbackVerificationKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'field_permissions',
    'filter',
    'text',
    'node',
    'taxonomy',
    'markaspot_feedback',
  ];

  /**
   * The closed status the report is in.
   */
  private Term $closed;

  /**
   * The status a reopened report gets.
   */
  private Term $reopened;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'user', 'field', 'filter', 'node']);
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['uid' => 1, 'name' => 'fixture-root', 'status' => 1])->save();
    Role::load('anonymous')->grantPermission('access content')->save();

    NodeType::create(['type' => 'service_request', 'name' => 'Service request'])->save();
    Vocabulary::create(['vid' => 'service_status', 'name' => 'Status'])->save();
    $this->closed = Term::create(['vid' => 'service_status', 'name' => 'Closed']);
    $this->closed->save();
    $this->reopened = Term::create(['vid' => 'service_status', 'name' => 'In progress']);
    $this->reopened->save();

    FieldStorageConfig::loadByName('node', 'field_feedback')?->delete();
    $fields = [
      'field_e_mail' => ['email', []],
      'field_feedback' => ['text_long', []],
      'field_has_feedback' => ['boolean', []],
      'field_status' => ['entity_reference', ['target_type' => 'taxonomy_term']],
    ];
    foreach ($fields as $name => [$type, $settings]) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'type' => $type,
        'settings' => $settings,
        'third_party_settings' => $name === 'field_feedback' ? [
          'field_permissions' => ['permission_type' => 'custom'],
        ] : [],
      ])->save();
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'bundle' => 'service_request',
        'label' => $name,
      ])->save();
    }

    $this->config('markaspot_feedback.settings')
      ->set('status_feedback_enabled', [(string) $this->closed->id() => (string) $this->closed->id()])
      ->set('set_progress_tid', [(string) $this->reopened->id() => (string) $this->reopened->id()])
      ->set('set_status_note', '')
      ->save();
  }

  /**
   * Feedback without the reporter address is refused.
   */
  public function testFeedbackWithoutVerificationIsRefused(): void {
    $report = $this->report();
    $response = $this->post($report, ['feedback' => 'Not fixed at all', 'set_status' => TRUE]);

    $this->assertSame(403, $response->getStatusCode());
    $this->assertUnchanged($report);
  }

  /**
   * Feedback with another address is refused, with the same answer.
   */
  public function testFeedbackWithWrongAddressIsRefused(): void {
    $report = $this->report();
    $wrong = $this->post($report, [
      'feedback' => 'x',
      'set_status' => TRUE,
      'email_verification' => 'someone@example.org',
    ]);
    $this->assertSame(403, $wrong->getStatusCode());
    $this->assertUnchanged($report);

    // A report without reporter address gives the same answer, so the
    // response does not reveal whether a report has one.
    $anonymous = $this->report(NULL);
    $none = $this->post($anonymous, ['feedback' => 'x', 'email_verification' => 'someone@example.org']);
    $this->assertSame(403, $none->getStatusCode());
    $this->assertSame($wrong->getContent(), $none->getContent());
  }

  /**
   * The reporter's feedback is saved and may reopen the report.
   */
  public function testReporterFeedbackIsSavedAndReopens(): void {
    $report = $this->report();
    $response = $this->post($report, [
      'feedback' => 'Still dangerous in the morning.',
      'set_status' => TRUE,
      'email_verification' => ' Reporter@Example.org ',
    ]);

    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $saved = Node::load($report->id());
    $this->assertSame('Still dangerous in the morning.', $saved->get('field_feedback')->value);
    $this->assertTrue((bool) $saved->get('field_has_feedback')->value);
    $this->assertSame((int) $this->reopened->id(), (int) $saved->get('field_status')->target_id);
  }

  /**
   * The verified endpoint writes for citizens without any feedback grant.
   */
  public function testCitizensCanSubmitWithoutFieldGrants(): void {
    $citizen = User::create(['name' => 'fictional-citizen', 'status' => 1]);
    $citizen->save();
    foreach ([User::getAnonymousUser(), $citizen] as $account) {
      $this->container->get('current_user')->setAccount($account);
      $report = $this->report('reporter@example.org', (int) $account->id());
      foreach (['create', 'edit', 'view'] as $operation) {
        $this->assertFalse($account->hasPermission($operation . ' field_feedback'));
      }
      $this->assertFalse($report->get('field_feedback')->access('view', $account));
      $this->assertFalse($report->get('field_feedback')->access('edit', $account));
      $response = $this->post($report, [
        'feedback' => 'Fictional citizen feedback without field grants.',
        'email_verification' => 'reporter@example.org',
      ]);
      $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
      $saved = $this->container->get('entity_type.manager')->getStorage('node')->loadUnchanged($report->id());
      $this->assertSame('Fictional citizen feedback without field grants.', $saved->get('field_feedback')->value);
      $this->assertTrue((bool) $saved->get('field_has_feedback')->value);
      $this->assertFalse($saved->get('field_feedback')->access('view', $account));
    }
  }

  /**
   * Guessing addresses is limited per client and report.
   */
  public function testVerificationIsRateLimited(): void {
    $report = $this->report();
    for ($i = 0; $i < FeedbackController::FLOOD_THRESHOLD; $i++) {
      $this->assertSame(403, $this->post($report, ['feedback' => 'x', 'email_verification' => "guess$i@example.org"])->getStatusCode());
    }
    $blocked = $this->post($report, ['feedback' => 'x', 'email_verification' => 'reporter@example.org']);
    $this->assertSame(429, $blocked->getStatusCode());
    $this->assertUnchanged($report);
  }

  /**
   * Without a reporter address the author never stands in.
   *
   * No mail goes out and the endpoint answers like a wrong address.
   */
  public function testAuthorIsNoFallbackRecipient(): void {
    $author = User::create(['name' => 'author', 'mail' => 'author@example.org', 'status' => 1]);
    $author->save();
    $report = $this->report(NULL, (int) $author->id());
    $this->assertNull(FeedbackService::recipientEmail($report));

    $response = $this->post($report, ['feedback' => 'Thanks', 'email_verification' => 'author@example.org']);
    $this->assertSame(403, $response->getStatusCode());
    $wrong = $this->post($this->report(), ['feedback' => 'Thanks', 'email_verification' => 'author@example.org']);
    $this->assertSame((string) $wrong->getContent(), (string) $response->getContent(), 'Same answer as a wrong address.');
    $this->assertUnchanged($report);
  }

  /**
   * The read endpoint shows viewable reports only and repeats no feedback.
   */
  public function testReadEndpointRespectsNodeAccess(): void {
    $controller = FeedbackController::create($this->container);
    $unpublished = $this->report();
    $unpublished->setUnpublished()->save();
    $this->assertSame(404, $controller->getServiceRequest($unpublished->uuid())->getStatusCode());

    $report = $this->report();
    $this->assertSame(200, $controller->getServiceRequest($report->uuid())->getStatusCode());

    // A second feedback is refused without echoing the first one.
    $this->post($report, ['feedback' => 'First answer', 'email_verification' => 'reporter@example.org']);
    $again = $this->post($report, ['feedback' => 'Second', 'email_verification' => 'reporter@example.org']);
    $this->assertSame(409, $again->getStatusCode());
    $this->assertStringNotContainsString('First answer', (string) $again->getContent());

    // The read endpoint says it was answered but never shows the text, not
    // even to staff who may read the field.
    Role::create([
      'id' => 'feedback_reader', 'label' => 'Feedback reader',
      'permissions' => ['access content', 'view field_feedback'],
    ])->save();
    $staff = User::create([
      'name' => 'staff', 'mail' => 'staff@example.org', 'status' => 1,
      'roles' => ['feedback_reader'],
    ]);
    $staff->save();
    $this->assertTrue($report->get('field_feedback')->access('view', $staff));
    $this->container->get('current_user')->setAccount($staff);
    $read = $controller->getServiceRequest($report->uuid());
    $this->assertSame(200, $read->getStatusCode());
    $this->assertTrue(json_decode((string) $read->getContent(), TRUE)['has_feedback']);
    $this->assertStringNotContainsString('First answer', (string) $read->getContent());
    $this->assertArrayNotHasKey('feedback', json_decode((string) $read->getContent(), TRUE));
  }

  /**
   * Creates a closed report.
   */
  private function report(?string $mail = 'reporter@example.org', int $uid = 0): NodeInterface {
    $node = Node::create([
      'type' => 'service_request',
      'title' => 'Report',
      'uid' => $uid,
      'status' => 1,
      'field_e_mail' => $mail,
      'field_status' => $this->closed->id(),
    ]);
    $node->save();
    return $node;
  }

  /**
   * Posts feedback for a report to the REST controller.
   */
  private function post(NodeInterface $report, array $body): object {
    $request = Request::create('/api/feedback/' . $report->uuid(), 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($body));
    return FeedbackController::create($this->container)->updateFeedback($report->uuid(), $request);
  }

  /**
   * Asserts a report kept its status and has no feedback.
   */
  private function assertUnchanged(NodeInterface $report): void {
    $stored = Node::load($report->id());
    $this->assertTrue($stored->get('field_feedback')->isEmpty());
    $this->assertSame((int) $this->closed->id(), (int) $stored->get('field_status')->target_id);
  }

}
