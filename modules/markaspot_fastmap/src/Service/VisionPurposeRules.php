<?php

declare(strict_types=1);

namespace Drupal\markaspot_fastmap\Service;

use Drupal\group\Entity\GroupInterface;
use Drupal\markaspot_nuxt\Service\CitizenWordingResolver;

/**
 * Judges photos on maps that collect ideas, observations or contributions.
 *
 * Implements hook_markaspot_vision_prompt_alter() for FastMap workspaces.
 * Report maps keep the vision defaults byte for byte. On the other maps the
 * image analysis judges a photo by the map's purpose and categories instead
 * of fixed per-preset examples:
 * - relevance: true only for a real subject that fits the purpose and at
 *   least one category; the mandatory category is never a reason for true;
 * - description framing per preset, hazards only when acute and visible;
 * - a neutral user prompt framing, the configured image prompt stays as is;
 * - a one-sentence purpose when the workspace has no project context.
 *
 * Categories, attribute definitions and the response schema are not touched.
 */
final class VisionPurposeRules {

  /**
   * Most category names the fallback purpose lists.
   */
  private const FALLBACK_MAX_CATEGORIES = 30;

  /**
   * Hazard rule for maps that do not collect defects.
   *
   * Real-model runs gave speculative hazards on idea and observation maps
   * ("could become trip hazards", "potential heat buildup").
   */
  private const ACUTE_HAZARD_RULE = " Set hazard_flag to true only for an acute hazard that is clearly visible "
    . "in the image now, such as an open hole, broken glass, exposed wiring, fire or flooding. Possible, future "
    . "or speculative risks are not hazards on this map: then set hazard_flag to false, hazard_level to 0, "
    . "hazard_category to null and hazard_issues to an empty array.";

  /**
   * Off-domain rule shipped for idea and observation maps in 11.9.222.
   *
   * Real-model runs accepted spreadsheet screenshots and an empty tree pit on
   * a cat map without it.
   */
  private const STRICT_RULE = " Set is_reportable_issue to false for documents, screenshots, tables, charts, text "
    . "and other non-photographic content, for images that do not show the subject this project is about (for "
    . "example no animal on a map about animals), and for anything the project context excludes; exclusions "
    . "take precedence. The project context and the categories decide what fits, not the wording of this "
    . "instruction. The category you must return is never a reason to set is_reportable_issue to true.";

  /**
   * Off-domain rule for contribution maps.
   *
   * Contributions can be old photos, scans or other reproductions, so the
   * purpose decides whether such material is the subject. A spreadsheet
   * screenshot was still accepted as a "street sign" without this rule.
   */
  private const CONTRIBUTION_RULE = " Set is_reportable_issue to false for screenshots, tables, charts, text "
    . "and other non-photographic content unless the project context explicitly collects such material (for "
    . "example scanned historic photos or documents), for images that do not show what this project is about, "
    . "and for anything the project context excludes; exclusions take precedence. The category you must "
    . "return is never a reason to set is_reportable_issue to true.";

  /**
   * Constructs the rules.
   *
   * @param \Drupal\markaspot_nuxt\Service\CitizenWordingResolver|null $wordingResolver
   *   Resolves the workspace noun; without it every prompt keeps its
   *   defaults.
   */
  public function __construct(
    protected ?CitizenWordingResolver $wordingResolver = NULL,
  ) {}

  /**
   * Adapts the vision prompt parts of one analysis.
   *
   * @param array<string, string> $parts
   *   The prompt parts, see hook_markaspot_vision_prompt_alter().
   * @param array $context
   *   The request facts, see hook_markaspot_vision_prompt_alter().
   */
  public function alter(array &$parts, array $context): void {
    $preset = $context['preset'] ?? CitizenWordingResolver::DEFAULT_PRESET;
    if ($preset === CitizenWordingResolver::DEFAULT_PRESET || !CitizenWordingResolver::isSupportedPreset($preset)) {
      return;
    }
    $group = $context['group'] ?? NULL;
    if (!$group instanceof GroupInterface || $this->wordingResolver === NULL) {
      return;
    }
    // English like the rest of the instructions; category names stay in the
    // response language.
    $plural = $this->wordingResolver->resolve($group, 'en')['plural'];
    $imageCount = (int) ($context['image_count'] ?? 1);

    $parts['relevance'] = $this->relevance($preset, $plural);
    $parts['framing'] = $this->framing($preset);
    $parts['user_prefix'] = "The following set of {$imageCount} images shows one scene. "
      . "Please analyze them together as one complete scene. Consider how the images relate to and complement each other. ";
    $parts['user_framing'] = "This map does not collect defects: it collects {$plural} for the purpose described in "
      . "the project context. Read the tasks below in that sense. Describe what is visible and matters for this "
      . "purpose; mention infrastructure issues or problems only when they are actually visible. The hazard "
      . "assessment applies only to an acute hazard that is visible in the image.\n\n";

    // Never replaces a purpose the workspace has.
    if (($context['tenant_context'] ?? '') === '') {
      $parts['project_context'] = $this->fallbackPurpose($group, $plural, (array) ($context['categories'] ?? []));
    }
  }

  /**
   * Builds the relevance section from the purpose, not from examples.
   *
   * The vision service appends its RELEVANCE_OFF_DOMAIN_RULE (best-guess
   * category for false results) after this section.
   */
  private function relevance(string $preset, string $plural): string {
    $lead = "\n\nPROJECT RELEVANCE: This workspace collects {$plural}, not defects. Set is_reportable_issue to true "
      . "only when the image shows a real subject that fits the purpose in the project context and at least one "
      . "of the current tenant categories.";
    return match ($preset) {
      // The idea itself does not exist yet: the photo shows its place.
      'suggestion' => $lead . " The photo usually shows the place or object an idea relates to: nothing needs to "
        . "be damaged or wrong, the suggested change is usually not visible yet." . self::STRICT_RULE,
      'entry' => $lead . " Positive and neutral conditions count as much as problems." . self::STRICT_RULE,
      default => $lead . self::CONTRIBUTION_RULE,
    };
  }

  /**
   * Builds the description framing of a preset.
   */
  private function framing(string $preset): string {
    return "\n\nDESCRIPTION FRAMING: " . match ($preset) {
      'suggestion' => "Describe the place neutrally (what is there, how it looks and is used) and add one short "
        . "hint at what could be suggested there. Do not describe the place as a problem, defect or damage unless "
        . "such damage is clearly visible.",
      'entry' => "Describe the observed subject or condition factually, as an observation, not as a defect to be "
        . "repaired. Do not judge or speculate beyond what is visible.",
      default => "Describe what the contribution shows factually (the place, object, scene or material), as a "
        . "contribution to this map, not as a defect to be repaired. Mention damage only when it is clearly "
        . "visible.",
    } . self::ACUTE_HAZARD_RULE;
  }

  /**
   * Builds one sentence of purpose from the workspace itself.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The workspace.
   * @param string $plural
   *   The English plural of the workspace's wording.
   * @param array $categories
   *   The leaf categories offered to the model (tid, path, label).
   */
  private function fallbackPurpose(GroupInterface $group, string $plural, array $categories): string {
    $names = [];
    foreach ($categories as $category) {
      $name = self::plain((string) ($category['label'] ?? ''), 100);
      if ($name !== '' && !in_array($name, $names, TRUE)) {
        $names[] = $name;
      }
    }
    $more = count($names) > self::FALLBACK_MAX_CATEGORIES;
    $names = array_slice($names, 0, self::FALLBACK_MAX_CATEGORIES);
    $sentence = 'The map "' . self::plain((string) $group->label(), 120) . '" collects ' . $plural;
    if ($names !== []) {
      $sentence .= ' in these categories: ' . implode(', ', $names) . ($more ? ', and more' : '');
    }
    return $sentence . '.';
  }

  /**
   * Collapses control characters and whitespace, then bounds the length.
   */
  private static function plain(string $value, int $maxLength): string {
    $value = trim((string) preg_replace('/[\p{Cc}\s]+/u', ' ', $value));
    return mb_substr($value, 0, $maxLength);
  }

}
