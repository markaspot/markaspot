<?php

declare(strict_types=1);

namespace Drupal\Tests\markaspot_vision\Unit;

use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\markaspot_vision\Hook\HazardLevelHooks;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Pins how photo hazard assessments reach the request.
 *
 * NULL means "not assessed": a request whose photos the AI never analysed
 * keeps it, and 0 is only written when a photo was assessed as "None".
 */
#[Group('markaspot_vision')]
final class HazardLevelHooksTest extends UnitTestCase {

  /**
   * The propagation runs before the write, so the level is stored.
   */
  public function testRunsAsNodePresave(): void {
    $attributes = (new \ReflectionMethod(HazardLevelHooks::class, 'nodePresave'))->getAttributes(Hook::class);
    $this->assertCount(1, $attributes);
    $this->assertSame('node_presave', $attributes[0]->newInstance()->hook);
  }

  /**
   * Photos without an assessment leave the level untouched (NULL stays).
   */
  public function testUnassessedPhotosLeaveTheLevelUntouched(): void {
    $node = $this->request(TRUE, [$this->photo(11, NULL), $this->photo(12, NULL, FALSE)], NULL);
    $node->expects($this->never())->method('set');
    (new HazardLevelHooks())->nodePresave($node);
  }

  /**
   * The highest assessed level is written; unassessed photos do not count.
   */
  public function testHighestAssessedLevelIsWritten(): void {
    $node = $this->request(TRUE, [$this->photo(11, 1), $this->photo(12, NULL), $this->photo(13, 2)], NULL);
    $node->expects($this->once())->method('set')->with('field_hazard_level', 2);
    (new HazardLevelHooks())->nodePresave($node);
  }

  /**
   * Photos assessed as "None" write 0, a real assessment.
   */
  public function testPhotosAssessedAsNoneWriteZero(): void {
    $node = $this->request(TRUE, [$this->photo(11, 0), $this->photo(12, NULL)], NULL);
    $node->expects($this->once())->method('set')->with('field_hazard_level', 0);
    (new HazardLevelHooks())->nodePresave($node);
  }

  /**
   * An unrelated save of a stored request does not fill in its level.
   */
  public function testUnchangedPhotosOfStoredRequestWriteNothing(): void {
    $node = $this->request(FALSE, [$this->photo(11, 3)], NULL, [11]);
    $node->expects($this->never())->method('set');
    (new HazardLevelHooks())->nodePresave($node);
  }

  /**
   * Changed photos raise the level but never lower a higher assessment.
   */
  public function testChangedPhotosRaiseButNeverLower(): void {
    $higher = $this->request(FALSE, [$this->photo(11, 0), $this->photo(14, 1)], 3, [11]);
    $higher->expects($this->never())->method('set');
    (new HazardLevelHooks())->nodePresave($higher);

    $lower = $this->request(FALSE, [$this->photo(11, 0), $this->photo(14, 4)], 1, [11]);
    $lower->expects($this->once())->method('set')->with('field_hazard_level', 4);
    (new HazardLevelHooks())->nodePresave($lower);
  }

  /**
   * Other bundles are not touched.
   */
  public function testOtherBundlesAreIgnored(): void {
    $node = $this->request(TRUE, [$this->photo(11, 4)], NULL, NULL, 'page');
    $node->expects($this->never())->method('get');
    $node->expects($this->never())->method('set');
    (new HazardLevelHooks())->nodePresave($node);
  }

  /**
   * Builds a request with photos, a stored level and an original.
   *
   * @param bool $isNew
   *   Whether the request is new.
   * @param list<\Drupal\media\MediaInterface> $photos
   *   Referenced photos.
   * @param int|null $level
   *   The current field_hazard_level, NULL when empty.
   * @param list<int>|null $originalIds
   *   Photo IDs of the stored request, NULL for a new request.
   * @param string $bundle
   *   The node bundle.
   */
  private function request(bool $isNew, array $photos, ?int $level, ?array $originalIds = NULL, string $bundle = 'service_request'): NodeInterface&MockObject {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn($bundle);
    $node->method('hasField')->willReturn(TRUE);
    $node->method('isNew')->willReturn($isNew);
    $photoIds = array_map(static fn(MediaInterface $photo): int => (int) $photo->id(), $photos);
    $node->method('get')->willReturnMap([
      ['field_request_media', $this->photoList($photoIds, $photos)],
      ['field_hazard_level', $this->valueList($level)],
    ]);
    if ($originalIds !== NULL) {
      $original = $this->createMock(NodeInterface::class);
      $original->method('hasField')->willReturn(TRUE);
      $original->method('get')->willReturnMap([
        ['field_request_media', $this->photoList($originalIds, [])],
      ]);
      $node->method('getOriginal')->willReturn($original);
    }
    return $node;
  }

  /**
   * Builds a photo reference list.
   *
   * @param list<int> $ids
   *   Referenced media IDs.
   * @param list<\Drupal\media\MediaInterface> $photos
   *   Referenced media entities.
   */
  private function photoList(array $ids, array $photos): EntityReferenceFieldItemListInterface {
    $list = $this->createMock(EntityReferenceFieldItemListInterface::class);
    $list->method('getValue')->willReturn(array_map(static fn(int $id): array => ['target_id' => $id], $ids));
    $list->method('referencedEntities')->willReturn($photos);
    return $list;
  }

  /**
   * Builds a photo with or without an AI hazard assessment.
   *
   * @param int $id
   *   Media ID.
   * @param int|null $level
   *   The field_ai_hazard_level value, NULL when not assessed.
   * @param bool $hasField
   *   Whether the media type has field_ai_hazard_level at all.
   */
  private function photo(int $id, ?int $level, bool $hasField = TRUE): MediaInterface {
    $photo = $this->createMock(MediaInterface::class);
    $photo->method('id')->willReturn($id);
    $photo->method('hasField')->willReturn($hasField);
    $photo->method('get')->willReturn($this->valueList($level));
    return $photo;
  }

  /**
   * Builds a single-value field list.
   */
  private function valueList(?int $value): FieldItemListInterface {
    $list = $this->createMock(FieldItemListInterface::class);
    $list->method('isEmpty')->willReturn($value === NULL);
    $list->method('__get')->willReturnCallback(static fn(string $property): ?int => $property === 'value' ? $value : NULL);
    return $list;
  }

}
