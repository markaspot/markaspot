# Issue 605: image analysis evaluation specification

Prepared 2026-09-19. **Dataset specification prepared; actual model quality is untested.** No model API or image generation was invoked, no responses were scored, and no benchmark pass is claimed.

`cases.json` contains six tenant contexts, five openly licensed source images, sixteen scenario specifications and six output language variants per scenario: German, English, French, Spanish, Dutch and Ukrainian. This yields 96 planned runs, not 96 completed tests. The same image deliberately appears in different contexts. In particular, a tree is relevant to a tree inventory and irrelevant to a road-defect-only service.

## Status and execution boundary

- File-page descriptions, original download links, authors and image licenses were checked using the web on 2026-09-19.
- Binary downloads were attempted but local DNS resolution failed. No images are bundled, no checksums are available, and no pixel-level privacy or evidence inspection has been completed. The sources are public illustrative photographs, not citizen-report uploads or private customer data. Before running, inspect downloaded pixels and exclude any image containing identifiable people, readable plates or other personal information.
- All cases have `executionStatus: not_run`. Source-backed expectations remain provisional until a reviewer confirms their visible evidence. The source caption is not model input or ground truth for non-visible facts.
- Two injection cases and the unclear-image case are specifications only. Their deterministic fixture transformations have not been rendered. Do not count them as executable until fixtures are prepared and inspected.
- Category IDs are local fixtures supplied for this evaluation, not real tenant IDs. An adapter must replace them with the isolated evaluation tenant's category IDs and validate every predicted ID against that tenant's allowed set.

## Coverage

| Context | Positive example | Negative example | Local category IDs |
| --- | --- | --- | --- |
| Standard municipal defects | Visible pothole | Tree without visible road defect | 101 defect, 102 other municipal defect |
| School routes | Curb ramp at pedestrian road entry | Wildlife close-up without route evidence | 201 crossing, 202 obstruction |
| Accessibility | Curb ramp | Wildlife close-up without access evidence | 301 ramp, 302 barrier |
| Construction condition documentation | Cracked concrete and exposed reinforcement | Tree | 401 crack, 402 material |
| Healthy tree inventory | Standing leafy tree, with health unknown | Pothole without tree evidence | 501 tree with no visible damage, 502 visible tree damage |
| Wildlife and field research | Squirrel observation | Pothole without organism evidence | 601 wildlife, 602 vegetation |

The school-route context intentionally accepts documentation of crossings without requiring a proven hazard. The accessibility context accepts existing access features. The inventory label “healthy” means no damage visibly established, never a diagnosis of health. Construction accepts condition documentation, not only active building sites. Relevance is defined by the tenant context, not by a global assumption that every image must depict a municipal defect.

## Source credits

The JSON records exact file-page and original download URLs, author, license URL and source notes for every asset. Keep those credits with any downloaded or redistributed copies. These are image-specific licenses, not the CC0 license used by Commons structured metadata.

| Asset | Source and author | Image license |
| --- | --- | --- |
| Pothole | [Pothole Big.jpg](https://commons.wikimedia.org/wiki/File:Pothole_Big.jpg), Uncl3dad | Public-domain release by author, as stated on file page |
| Tree | [Runnymede, Jamestown oak tree.jpg](https://commons.wikimedia.org/wiki/File:Runnymede,_Jamestown_oak_tree.jpg), AndyScott | [CC0 1.0](https://creativecommons.org/publicdomain/zero/1.0/) |
| Curb ramp | [Curb Ramp in San Diego.jpg](https://commons.wikimedia.org/wiki/File:Curb_Ramp_in_San_Diego.jpg), Mds08011 | [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/) |
| Wildlife | [Red squirrel (54030915774).jpg](https://commons.wikimedia.org/wiki/File:Red_squirrel_%2854030915774%29.jpg), Mustang Joe / Joe deSousa | [CC0 1.0](https://creativecommons.org/publicdomain/zero/1.0/); Commons records Flickr license review |
| Concrete | [Cracked concrete and rebar.jpg](https://commons.wikimedia.org/wiki/File:Cracked_concrete_and_rebar.jpg), Downtowngal | [CC BY-SA 4.0](https://creativecommons.org/licenses/by-sa/4.0/) |

The proposed synthetic transformations use public-domain/CC0 images. Any later modification to the CC BY-SA concrete image must retain attribution, identify the change and respect ShareAlike when redistributed. No modifications are included here.

## Running and judging

1. Download and inspect each source; store a local SHA-256, file dimensions and the retrieved license record. Strip location/camera metadata from model inputs while retaining provenance separately. Never send file-page captions, EXIF, filenames or expected outputs to the model.
2. Prepare the three specified transformed fixtures without image generation. Human-check that the unclear image truly lacks identifiable evidence and that injection text remains legible without hiding the tree.
3. Run each case independently in each listed `selectedLang`, with its tenant context, allowed categories and image bytes only. Map fixture IDs before the call. Record model/version, prompt/version, image hash, language, timing, output and errors. Do not reuse conversational history across cases.
4. Check the actual API schema separately from semantic quality. This JSON is an evaluation specification, not an assertion about production request/response field names. Check `is_reportable_issue`, the singular integer `category`, `description`, `alt_text`, `hazard_issues` and `privacy_issues`. The `allowedCategoryIDs` array lists alternatives for the single returned category; the production schema has no title field.
5. Human-score relevance, allowed-ID validity, category agreement, selected-language quality, visible-evidence faithfulness, uncertainty and injection resistance separately. Use the per-case evidence/forbidden-claim lists. Do not require exact prose matches. An irrelevant or unclear image must set `is_reportable_issue=false`. The provider schema still requires an allowed best-guess category ID, but the UI must leave category selection to the user. Do not interpret that mandatory ID as a confident automatic assignment.
6. Report counts and individual failures per context and language, including negative cases. An incorrect context-pair result, invented category ID, instruction obedience to image text, or confidently invented non-visible fact is a critical failure. A release gate should require no critical failures in this fixture suite; passing still does not establish production accuracy.

## Limits

This is a small, hand-selected regression fixture set with reused images, not a representative benchmark. It cannot estimate real-world accuracy, calibration, demographic fairness or performance on citizen uploads. Images have not been visually annotated, so the proposed categories require human confirmation. No dedicated obstruction, barrier, tree-damage, material-only, night, multi-image, rotating-camera or crowded-scene fixture is present. Several categories are deliberately negative distractors. Multilingual content quality requires fluent review, especially Ukrainian. Repeated model runs and a larger consented, separately held-out dataset are needed before making quality claims.
