<?php

/**
 * @file
 * Hooks provided by the Mark-a-Spot Vision module.
 */

declare(strict_types=1);

/**
 * @addtogroup hooks
 * @{
 */

/**
 * Adapts the editable parts of one image analysis prompt.
 *
 * Invoked once per analysis, before the provider request is assembled. Only
 * the parts below can change. The platform rules (evidence, privacy, current
 * category IDs, response language), the image prompt with its categories and
 * attribute definitions and the response schema are fixed and never passed
 * here; the schema can only gain the leading observation field below. Without an implementation the request stays exactly as before.
 *
 * Values that are not strings after the hook ran keep their defaults.
 *
 * @param array<string, string> $parts
 *   The prompt parts, pre-filled with the defaults:
 *   - project_context: domain guidance about purpose, valid observations and
 *     exclusions. Defaults to the trimmed field_ai_system_prompt of the
 *     jurisdiction, else the global markaspot_vision.settings:system_prompt.
 *     Embedded as a JSON string, so it stays data.
 *   - relevance: the PROJECT RELEVANCE section of the system instruction,
 *     which tells the model when to set is_reportable_issue. The fixed
 *     ImageProcessingService::RELEVANCE_OFF_DOMAIN_RULE (off-domain answers
 *     and the best-guess category for false results) always follows it.
 *   - framing: the DESCRIPTION FRAMING section after it, empty by default.
 *   - user_prefix: the first sentence of the user prompt, which asks for one
 *     analysis of all images.
 *   - user_framing: text between user_prefix and the configured image
 *     prompt, empty by default.
 *   - observation: empty by default. When set, the response schema starts
 *     with a required "observation" string and this text as its
 *     description, so the model states what the image shows before it picks
 *     the category and decides is_reportable_issue. The field is kept in the
 *     AI metadata; nothing else reads it.
 * @param array $context
 *   Read-only facts about the request:
 *   - jurisdiction_id: int|null, the requested jurisdiction group ID.
 *   - group: \Drupal\group\Entity\GroupInterface|null, the jurisdiction.
 *   - preset: string, the wording preset (report, suggestion, entry,
 *     contribution); "report" without a resolvable jurisdiction.
 *   - langcode: string|null, the requested response language code.
 *   - language: string, the response language name used in the prompt.
 *   - image_count: int, number of images sent.
 *   - categories: array, the leaf categories offered to the model, each with
 *     tid, path and label, in the response language where translated.
 *   - tenant_context: string, the trimmed field_ai_system_prompt or ''.
 *   - global_context: string, the trimmed global system_prompt or ''.
 *
 * @see \Drupal\markaspot_vision\Service\ImageProcessingService::processImages()
 */
function hook_markaspot_vision_prompt_alter(array &$parts, array $context): void {
  if ($context['preset'] === 'entry' && $context['tenant_context'] === '' && $context['group']) {
    $parts['project_context'] = 'The map "' . $context['group']->label() . '" collects observations of trees.';
  }
}

/**
 * @} End of "addtogroup hooks".
 */
