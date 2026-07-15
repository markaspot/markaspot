# Changelog

## [11.9.155](https://github.com/markaspot/markaspot/compare/11.9.154...11.9.155) (2026-07-15)

### Features

* notify assignee by mail on per user assignment ([4bd94c4](https://github.com/markaspot/markaspot/commit/4bd94c4a68d32412577d1de0a0aaf3e3936c9845))
* optional single organisation assignment for service requests ([70919d7](https://github.com/markaspot/markaspot/commit/70919d7541812333aa7a355e64d72304fa1f0385))

### Bug Fixes

* add assignee notification mail translations across locales ([75277a6](https://github.com/markaspot/markaspot/commit/75277a636b8b0526c4d2045367df0f66cab2c69a))
* expose translate tab on the mail texts form ([ee8c6a1](https://github.com/markaspot/markaspot/commit/ee8c6a1aad97b18fa585270edea21425ebe2126f))
* **group:** enforce single organisation assignment ([9bb8367](https://github.com/markaspot/markaspot/commit/9bb8367734b281270efb3e6c408d40fbf4029ca9))

## [11.9.154](https://github.com/markaspot/markaspot/compare/11.9.153...11.9.154) (2026-07-14)

### Bug Fixes

* enforce strict json schema for vision structured output ([4497582](https://github.com/markaspot/markaspot/commit/44975821a593a09541f28cad45b43a43446c15d9))

## [11.9.153](https://github.com/markaspot/markaspot/compare/11.9.151...11.9.153) (2026-07-14)

### Bug Fixes

* drop invalid composer replace entries rejected by packagist ([d1f06e0](https://github.com/markaspot/markaspot/commit/d1f06e0c66d67bd4214b3e95a12ce11170400866))
* restore field_slug filterability on group jur jsonapi resource ([48ebdf6](https://github.com/markaspot/markaspot/commit/48ebdf68d5a72818c00f4c51c4fa1f07db38749b))

## [11.9.151](https://github.com/markaspot/markaspot/compare/11.9.150...11.9.151) (2026-07-13)

### Features

* **maintenance:** add global core maintenance mode ([ede6bdb](https://github.com/markaspot/markaspot/commit/ede6bdbc881133fe91795465bf6572d333ce1f40))

### Bug Fixes

* replace typescript-only mapbox type packages rejected by composer 2.10.2 ([ae2358f](https://github.com/markaspot/markaspot/commit/ae2358fddfe1cc44a51b0bc0379070429e7a30cd))

## [11.9.150](https://github.com/markaspot/markaspot/compare/11.9.149...11.9.150) (2026-07-12)

### Bug Fixes

* **group:** assign member roles to invitations ([2e5b189](https://github.com/markaspot/markaspot/commit/2e5b189de7c4e7765f602d484ddea2640d67a042))

## [11.9.149](https://github.com/markaspot/markaspot/compare/11.9.148...11.9.149) (2026-07-12)

### Features

* **nuxt:** gate tier fallback and org toggles by edition ([b568bb3](https://github.com/markaspot/markaspot/commit/b568bb3dba03bf6782a2f006d4fb7373d51554ee))

## [11.9.148](https://github.com/markaspot/markaspot/compare/11.9.147...11.9.148) (2026-07-12)

### Features

* **emergency:** add scoped crisis mode and CAP guard ([a94681b](https://github.com/markaspot/markaspot/commit/a94681b0e17a0603d359cc3896b91ff85ae97cb9))
* **fastmap:** add default in-progress workflow state ([9999c71](https://github.com/markaspot/markaspot/commit/9999c71a6b71f001cb4c7211325965d4c9055de4))
* **nuxt:** enforce platform, tenant and jurisdiction scopes for feature flags ([499fe1d](https://github.com/markaspot/markaspot/commit/499fe1d7ca275f1c45a8404fb1ac83a8da34db69))

### Bug Fixes

* **group:** authorize cross-jurisdiction report re-homing ([068bd7c](https://github.com/markaspot/markaspot/commit/068bd7c69812ad16758209fcd47fdfd340a3a431))
* **nuxt:** keep photo AI enabled by default across feature scopes ([5f31c0c](https://github.com/markaspot/markaspot/commit/5f31c0c16b554e793382bc2a84721442847b7b93))

## [11.9.147](https://github.com/markaspot/markaspot/compare/11.9.146...11.9.147) (2026-07-11)

### Bug Fixes

* repair invitation links and dashboard JSONAPI resources ([db97a8c](https://github.com/markaspot/markaspot/commit/db97a8c6feec9a5666df3ba08971198fd7d0f3b0))

## [11.9.146](https://github.com/markaspot/markaspot/compare/11.9.145...11.9.146) (2026-07-11)

### Features

* add configurable citizen terminology ([ae1a1ad](https://github.com/markaspot/markaspot/commit/ae1a1ad9de3ab094bd57c5b8bf93f6a51c3e60e7))

### Bug Fixes

* clear assignments on escalation and refine team mail suppression ([b08fa69](https://github.com/markaspot/markaspot/commit/b08fa694c923c9908ff60e726d7f1cfbc837ef95))
* normalise legacy feature flag shapes in tenant settings ([49778da](https://github.com/markaspot/markaspot/commit/49778da8101a3ffe3d95f46ede5eaca60a9cae9e))

### Performance

* memoize organisation metadata and child id lookups ([55c41da](https://github.com/markaspot/markaspot/commit/55c41da361ce910a33fc18ad4b23bae14265025c))

## [11.9.145](https://github.com/markaspot/markaspot/compare/11.9.144...11.9.145) (2026-07-10)

### Bug Fixes

* align FastMap demo activation state ([92248e9](https://github.com/markaspot/markaspot/commit/92248e9c326afa58583a9a908ed4ce3a6b429939))

## [11.9.144](https://github.com/markaspot/markaspot/compare/11.9.143...11.9.144) (2026-07-09)

### Features

* assign service requests to an organisation unit ([8c81ff7](https://github.com/markaspot/markaspot/commit/8c81ff7fac3d8b1dcc08e10b1a38deb823b7b845))
* expose organisation code, level and path for the assignment picker ([b123f45](https://github.com/markaspot/markaspot/commit/b123f45fd0e9e5bb72e17077d128c895fa9e27e1))
* optionally sync responsibility to the assignee organisation ([baab0c8](https://github.com/markaspot/markaspot/commit/baab0c88fda91c9860fbafe3ac4508790246ab70))

### Bug Fixes

* exclude the superuser from assignment candidates ([39c101e](https://github.com/markaspot/markaspot/commit/39c101eb6508ab77ed23b84bc6871b383a0dd93f))

## [11.9.143](https://github.com/markaspot/markaspot/compare/11.9.142...11.9.143) (2026-07-07)

### Features

* assign service requests to individual users ([bc3d295](https://github.com/markaspot/markaspot/commit/bc3d295b227881799ee69a391eae0b29bbf83b8a))

## [11.9.142](https://github.com/markaspot/markaspot/compare/11.9.141...11.9.142) (2026-07-07)

### Features

* harden archive anonymization, skip taken request ids and keep address subfields optional ([a5ebf81](https://github.com/markaspot/markaspot/commit/a5ebf818c1d47617ce49ec6f3392a6ac75c62157))

## [11.9.141](https://github.com/markaspot/markaspot/compare/11.9.140...11.9.141) (2026-07-07)

### Features

* accept comma-separated ids in georeport request index lookup ([c5edd78](https://github.com/markaspot/markaspot/commit/c5edd789448d65154085915369e320049b3dfd75))
* add tenant text overrides settings endpoint ([99be87b](https://github.com/markaspot/markaspot/commit/99be87bed581059ca6e86f94ccd3cfba5b4f5a0e))

### Bug Fixes

* allow demo OTP multi-tenant override ([2f3fb46](https://github.com/markaspot/markaspot/commit/2f3fb469bc16eb6d7b2c1976c8f2fd8e43232e3f))
* harden cacheability of jurisdiction-scoped taxonomy access ([7c20779](https://github.com/markaspot/markaspot/commit/7c207798c6cdee226b4e508643308103bf65f114))
* scope taxonomy term visibility by jurisdiction membership ([255371a](https://github.com/markaspot/markaspot/commit/255371a454554f4ee990725176c0f8228b084734))

### Refactoring

* validate all media publication updates before saving ([1c98a97](https://github.com/markaspot/markaspot/commit/1c98a97923badd55d18a26682a3d558e9bcf0919))

## [11.9.140](https://github.com/markaspot/markaspot/compare/11.9.139...11.9.140) (2026-07-06)

### Features

* accept responsible organisation membership for escalation and delegation ([ce62ce1](https://github.com/markaspot/markaspot/commit/ce62ce1fda0491f52e1c0f962243b0140d1d3464))
* add parent organisation field with cycle-safe validation for org hierarchies ([0605d45](https://github.com/markaspot/markaspot/commit/0605d459cd34e43a02dba04d75ab748a5170cae4))
* add reminder scope and fix org group recipient resolution for resubmission ([699292a](https://github.com/markaspot/markaspot/commit/699292a1b8f9b1c3121f6e3cfa95757176f556c7))
* allowlist delegationNoteRequired in tenant settings features ([2d398b8](https://github.com/markaspot/markaspot/commit/2d398b83c5140995c77eec9e2e61926cbf3167ad))
* expose org hierarchy and legitimate escalation target for the responsibility picker ([b771ac8](https://github.com/markaspot/markaspot/commit/b771ac8ad474ea478f339d503e59afa698531350))
* expose per-request note requirement in extended attributes ([d73a23e](https://github.com/markaspot/markaspot/commit/d73a23eff4457bfea54a639e75736af370f3c1ec))
* make escalation and delegation org-hierarchy aware ([9bd757b](https://github.com/markaspot/markaspot/commit/9bd757b3d13a6ceda1029f90f9ef12d954db8e39))
* make escalation note requirement configurable per jurisdiction ([5ddbabc](https://github.com/markaspot/markaspot/commit/5ddbabc8c9c1cdffe7f08873686c3ed7ad62d75e))

### Bug Fixes

* accept the literal note zero when notes are required ([d01b3fb](https://github.com/markaspot/markaspot/commit/d01b3fbc5518447d1e4ba850f40560deb86faccb))
* align org parent validator depth cap with hierarchy engine ([8af30c8](https://github.com/markaspot/markaspot/commit/8af30c8e60c205ae1234cd5d04d3ba101a5df9f8))
* allow escalation endpoints to load unpublished service requests ([d8f0eb6](https://github.com/markaspot/markaspot/commit/d8f0eb65f3ee08dd2c8337b0941c510bfd52430e))
* allow lateral delegation for jurisdiction staff and expose escalation target label ([23ead86](https://github.com/markaspot/markaspot/commit/23ead86daf0c0dd041ca6c2030c66df8c874517d))
* descriptive audit remark when responsibility changes without a note ([ab42aa9](https://github.com/markaspot/markaspot/commit/ab42aa99115cc0a9178a3db36c6eb85f3efa0c78))
* guard demo otp on multi-tenant installs ([5985f64](https://github.com/markaspot/markaspot/commit/5985f647e188f4514950741204b6e7405f369637))
* never resolve a request's own jurisdiction as its escalation target ([b3263e8](https://github.com/markaspot/markaspot/commit/b3263e847863995a2f4b7f9d9a7fb50e8b0269c9))
* return delegationNoteRequired in tenant feature settings response ([1606735](https://github.com/markaspot/markaspot/commit/16067357d55049cb5f0bf32703540fc875267570))
* scope internal status term access by jurisdiction membership ([424d455](https://github.com/markaspot/markaspot/commit/424d455bf28c2154cb5aa8a25f6c709e3e824189))
* scope passwordless flood keys by jurisdiction ([303a6af](https://github.com/markaspot/markaspot/commit/303a6afd7446ce3348634f6f9446c4a0b51aebee))
* use runtime allowed_values format when adding staff source channel ([9da9382](https://github.com/markaspot/markaspot/commit/9da9382b5484f2753ad9a0640df78f5853c74cb8))
* validate jurisdiction scope in open311 processor via shared resolver ([bf98096](https://github.com/markaspot/markaspot/commit/bf980969e73e75f06e3c2abb2797e98ceb083619))
* validate numeric jurisdiction ids ([2658d45](https://github.com/markaspot/markaspot/commit/2658d45f68f6295cfca6c6f92f2b222376d5c048))

### Refactoring

* extract generic parent tree engine with jurisdiction and org facades ([0dcb9a0](https://github.com/markaspot/markaspot/commit/0dcb9a0474d3bf39a9f3df47555b566f3e5eca2c))

### Documentation

* record bulk triage exception from the note requirement ([9f0b8fe](https://github.com/markaspot/markaspot/commit/9f0b8fee3290bdf6affbd0788dfab91ca10633b4))

## [11.9.139](https://github.com/markaspot/markaspot/compare/11.9.138...11.9.139) (2026-07-05)

### Features

* add loginLink feature flag for footer sign-in visibility ([db9a692](https://github.com/markaspot/markaspot/commit/db9a69251b7c59dc6836e110b6632e4a675392e9))

## [11.9.138](https://github.com/markaspot/markaspot/compare/11.9.137...11.9.138) (2026-07-05)

### Features

* add onboardingTour feature flag with operating-mode default ([b552fb4](https://github.com/markaspot/markaspot/commit/b552fb4fa34a28c49b62c296d2be2d6ebbf1a57f))
* add tenant boundary settings endpoint with GeoJSON validation ([11de4c0](https://github.com/markaspot/markaspot/commit/11de4c0209ad128ef8cbc45dad2aac173955f800))

### Bug Fixes

* apply onboardingTour operating-mode default in the config endpoint the frontend reads ([2f1932d](https://github.com/markaspot/markaspot/commit/2f1932d1cb3c5f530c981a91b6e691c9f3621e3b))
* treat empty boundary FeatureCollection as clear, cap properties size, guard missing field_boundary ([481c1b9](https://github.com/markaspot/markaspot/commit/481c1b97be713b5db1a4bdb33355d851d71e2228))

## [11.9.137](https://github.com/markaspot/markaspot/compare/11.9.136...11.9.137) (2026-07-03)

### Features

* add admin form for markaspot_mail notification texts ([6834c61](https://github.com/markaspot/markaspot/commit/6834c61875f93216e2927434ec2cdc61ec87fbd2))
* add mail text editor API for the Nuxt dashboard ([a2128a9](https://github.com/markaspot/markaspot/commit/a2128a96fadad050a1da293fa9666d90e121812a))
* add markaspot_mail_send_notification ECA action and NotificationTextBuilder ([858bc94](https://github.com/markaspot/markaspot/commit/858bc941b93d1c380b9d9911b4b044f6ac8ae3fa))
* add markaspot_mail.texts notification config with EN/DE defaults ([5287486](https://github.com/markaspot/markaspot/commit/5287486a87bd865922c867359b926a90fb4fd82a))
* add markaspot:mail-texts-migrate drush command for ECA to notification text migration ([ed7edc5](https://github.com/markaspot/markaspot/commit/ed7edc5d34acacb339c1269058cb9de5b487821e))
* attribute vision privacy findings per image in the analysis response ([e0fe08e](https://github.com/markaspot/markaspot/commit/e0fe08ed72e0fd6ffdaf09f548f65bde828e8c76))
* gate mail text editor behind enterprise SaaS tier ([9324cff](https://github.com/markaspot/markaspot/commit/9324cff3691d1fc0c10e94a70aca0d68c45f3b36))

### Bug Fixes

* build notification cta from branding frontend base instead of node canonical url ([62f011e](https://github.com/markaspot/markaspot/commit/62f011ee57bd0106f05d038bcdcba0799e14478e))
* configure mocked magic field getter in vision env drift health test ([0e80027](https://github.com/markaspot/markaspot/commit/0e800274da291cc17ca361d36b9819beb137f4d3))
* correct inverted status mail conditions in notify status eca model ([0b78a69](https://github.com/markaspot/markaspot/commit/0b78a69bcd1503675bd5d118de492246602aef8f))
* guard markaspot_mail.texts overwrite against admin-edited wording in migrate apply ([fd03666](https://github.com/markaspot/markaspot/commit/fd03666f8a625ef0a2baa34ed101a7ca8dd9b1e1))
* let explicit georeport media publication hand off from AI pipeline ([ca0bcfd](https://github.com/markaspot/markaspot/commit/ca0bcfdf541448dfb872644f69d1919541289e25))
* mock manual publication keyvalue in image processing controller tests ([669e16f](https://github.com/markaspot/markaspot/commit/669e16fe12860a53fe1baf5b0af785f702e4922d))
* reject dotted mail-text keys, cap slot length and custom key count ([622690f](https://github.com/markaspot/markaspot/commit/622690f5c4b52c9f86594c5f5f1724df2855b1a6))
* scope mail-text samples to jurisdiction, mask PII, and sanitize admin HTML ([2e87c63](https://github.com/markaspot/markaspot/commit/2e87c63ace1da772762fdb090141fb317f277ef2))
* strip response-only vision keys from provider echoes before persisting audit metadata ([0ad79f1](https://github.com/markaspot/markaspot/commit/0ad79f1e4978b4a6ca1f3708c4f97ff09bc5f038))

### Refactoring

* extract MailTextResolver service for shared config text merge ([6d61882](https://github.com/markaspot/markaspot/commit/6d61882a143936bff92e9824efde160ccb85ade3))

## [11.9.136](https://github.com/markaspot/markaspot/compare/11.9.135...11.9.136) (2026-07-02)

### Features

* add branded card_transactional builder for workspace welcome mail ([9ad0131](https://github.com/markaspot/markaspot/commit/9ad0131695e8fc9c6a8ed7ecd1f4603e6775c1fe))
* add mail coverage health check for fastmap templates and builder registry ([59e023a](https://github.com/markaspot/markaspot/commit/59e023a1a10d7629c1a2388684f00fd6663f396d))
* add markaspot:mail-render-test drush command as deploy gate ([998c244](https://github.com/markaspot/markaspot/commit/998c244436db2c905506a9780bdb9e7732793b4f))

### Bug Fixes

* backfill workspace_welcome mail config and harden hook_mail against missing templates ([926fe48](https://github.com/markaspot/markaspot/commit/926fe48f201c289d0114ea71c17b3380319a18ec))
* give inbound triage replies their own mail type to end eca_action collision ([7c489a9](https://github.com/markaspot/markaspot/commit/7c489a905e1f2669c7f82b627f29fddec3a33aa0))
* keep welcome mail dashboard and login links clickable in html part ([334aa52](https://github.com/markaspot/markaspot/commit/334aa52d8a0d0761f7448015a599a9f6bc83b24c))

## [11.9.135](https://github.com/markaspot/markaspot/compare/11.9.134...11.9.135) (2026-07-02)

### Features

* split service request into linked sibling request ([7f69deb](https://github.com/markaspot/markaspot/commit/7f69deb08ec3fc6c98a6da96f50986c0c7df2f67))

### Bug Fixes

* cascade gdpr erasure to split siblings and harden split transaction ([cd9189c](https://github.com/markaspot/markaspot/commit/cd9189c54c8103a737c1f683722add4548a944ef))
* resolve jurisdiction hierarchy in split access and category checks ([aaa7fff](https://github.com/markaspot/markaspot/commit/aaa7fffd4934159d0c95992d203ddcabb7e523ab))

## [11.9.134](https://github.com/markaspot/markaspot/compare/11.9.133...11.9.134) (2026-07-02)

### Features

* accept and persist wording preset in tenant language settings ([3f0c975](https://github.com/markaspot/markaspot/commit/3f0c97575107d8af7fc2ecc1e6ac1b8b097cb3c6))
* accept wording preset at workspace provisioning ([36dee70](https://github.com/markaspot/markaspot/commit/36dee70b0f31ddb4e8ba014c8390db5b58f58aa8))
* localized welcome mail and curated category icons for workspace onboarding ([0ea7898](https://github.com/markaspot/markaspot/commit/0ea78982c7c2c0f2453075c8557bec95a1dcbfef))

### Bug Fixes

* parse international address formats and sanitize georeport addresses ([2fa6b95](https://github.com/markaspot/markaspot/commit/2fa6b955828fda633661bc9f73548df20215ac1b))
* scope georeport media publication updates ([4cb1864](https://github.com/markaspot/markaspot/commit/4cb1864d4af547ee114d4e82e3f4063f9afb7347))
* strip CR LF and NUL from workspace name to prevent mail header injection ([fad97a6](https://github.com/markaspot/markaspot/commit/fad97a622c1faca9b7963287fd26253b28c4e696))

### Refactoring

* derive status term server-side and sanitize field_address input ([e5a5ad0](https://github.com/markaspot/markaspot/commit/e5a5ad0edef74fcd8352bff7306df2f5ff77ad18))

## [11.9.133](https://github.com/markaspot/markaspot/compare/11.9.132...11.9.133) (2026-07-01)

### Features

* expose real_request_count and harden demo content AI prompt ([a1d9172](https://github.com/markaspot/markaspot/commit/a1d91729b9d697be27a41c2e2784556184333936))

### Bug Fixes

* match FastMap demo request content to its category ([4ab07c5](https://github.com/markaspot/markaspot/commit/4ab07c54e2fe0eadd589cfdea38080255861b9bd))

### Refactoring

* serve real_request_count from dedicated fastmap report-stats route ([2524227](https://github.com/markaspot/markaspot/commit/252422755d52e34795263840670a91b87da7b74a))

## [11.9.132](https://github.com/markaspot/markaspot/compare/11.9.131...11.9.132) (2026-07-01)

### Bug Fixes

* make management form-mode, staff fields and translations fresh-install-safe ([0be7afc](https://github.com/markaspot/markaspot/commit/0be7afc7cee94aded6a7aa9423232180379ee6f7))

## [11.9.131](https://github.com/markaspot/markaspot/compare/11.9.130...11.9.131) (2026-06-28)

### Bug Fixes

* send demo expiry reminder to workspace creator, bcc technical admin ([d6cff5f](https://github.com/markaspot/markaspot/commit/d6cff5fb57e740b4405352e2b8b9b3128bc7e5cf))

## [11.9.130](https://github.com/markaspot/markaspot/compare/11.9.129...11.9.130) (2026-06-26)

### Features

* lean OSS first-install — opt-in pro modules, lean-safe management view, uncapped seed ([aa56749](https://github.com/markaspot/markaspot/commit/aa56749f49f64f62ce6cddd5f47dff4de94706d8))
* ship service_request management view to OSS profile ([e57f955](https://github.com/markaspot/markaspot/commit/e57f95568921205b0f4176f59368362ad49d4a56))

### Bug Fixes

* harden tenant form defaults ([b1d88cd](https://github.com/markaspot/markaspot/commit/b1d88cd8c919aae99f36876f3385319da8e4e9db))
* seed api_user field_address perms + honest test-data count on OSS install ([c452a6f](https://github.com/markaspot/markaspot/commit/c452a6f94aac037f806a9933abc6e218418bbcc9))

## [11.9.129](https://github.com/markaspot/markaspot/compare/11.9.128...11.9.129) (2026-06-25)

### Bug Fixes

* **auth:** expose user uuid in auth payloads ([1556b88](https://github.com/markaspot/markaspot/commit/1556b88e5cdc49928b772e69234c121a68a55ef4))
* preserve runtime API key config ([35f3138](https://github.com/markaspot/markaspot/commit/35f313894039c1f43d719acd9a6f4554025c1278))

## [11.9.128](https://github.com/markaspot/markaspot/compare/11.9.127...11.9.128) (2026-06-23)

### Bug Fixes

* gate blurred media visibility safely ([3af40de](https://github.com/markaspot/markaspot/commit/3af40de1ad502ae570d5366f5fbd4d915e7e39eb))

## [11.9.127](https://github.com/markaspot/markaspot/compare/11.9.126...11.9.127) (2026-06-23)

### Bug Fixes

* **vision:** publish safe anonymized media ([67f591c](https://github.com/markaspot/markaspot/commit/67f591c3b93baabb5cc627870c7788a53942366d))

## [11.9.126](https://github.com/markaspot/markaspot/compare/11.9.125...11.9.126) (2026-06-23)

### Bug Fixes

* **open311:** stamp changed only on create so no-op updates keep updated_datetime ([291ac3c](https://github.com/markaspot/markaspot/commit/291ac3c2d8b352b49905307b5bce365c0232fb03))

## [11.9.125](https://github.com/markaspot/markaspot/compare/11.9.124...11.9.125) (2026-06-17)

### Bug Fixes

* harden vision health runtime check ([506cd80](https://github.com/markaspot/markaspot/commit/506cd8080e0941d27547d9f64a0a3f4318d88e64))

## [11.9.124](https://github.com/markaspot/markaspot/compare/11.9.123...11.9.124) (2026-06-17)

### Bug Fixes

* stabilize smoke checks for optional tenant features ([e078c8a](https://github.com/markaspot/markaspot/commit/e078c8a7f73a70ab6a0fed979d17518ecef2fcdf))

## [11.9.123](https://github.com/markaspot/markaspot/compare/11.9.122...11.9.123) (2026-06-17)

### Bug Fixes

* **open311:** restore service request author access ([ba4b123](https://github.com/markaspot/markaspot/commit/ba4b123e651bfe4387674a1eb9db45d58c4fa70c))

## [11.9.122](https://github.com/markaspot/markaspot/compare/11.9.121...11.9.122) (2026-06-16)

### Features

* expose intake source backend ([78d3b15](https://github.com/markaspot/markaspot/commit/78d3b1538cbba00573611bf8933cd8362636a981))
* **nuxt:** add allowGeoreportPost and categoryDescriptions to config schema ([#323](https://github.com/markaspot/markaspot/issues/323)) ([be08242](https://github.com/markaspot/markaspot/commit/be0824282965de5a2c55c84c05c279d14bea9838))
* **open311:** configurable create-specific flood control ([#474](https://github.com/markaspot/markaspot/issues/474)) ([520ec9f](https://github.com/markaspot/markaspot/commit/520ec9fe480919db68e161e3803995a3f5f076ed))
* **service-provider:** expose field_organisation on JSON:API resource for existing tenants ([#429](https://github.com/markaspot/markaspot/issues/429)) ([c54f904](https://github.com/markaspot/markaspot/commit/c54f90435a6cd73f1ba695ba7bee1a661ce49aff))
* support dashboard-created request intake ([30eb28c](https://github.com/markaspot/markaspot/commit/30eb28ca466ac16843c9781189b88f94c955c16d))

### Bug Fixes

* **auth:** expose dashboard permissions in auth payload ([cd73837](https://github.com/markaspot/markaspot/commit/cd73837285d03165d0c951623c34d4dcc8aacefe))
* harden open311 read scope ([8788451](https://github.com/markaspot/markaspot/commit/87884519fd4a945d907093ed6c5f8efb52ead6e9))
* **mail:** sanitize override + redirect Reply-To in recipient fence ([#388](https://github.com/markaspot/markaspot/issues/388)) ([4978ee9](https://github.com/markaspot/markaspot/commit/4978ee91da94bcbc9562e2eb3ffe9da0766d9b9b))

## [11.9.121](https://github.com/markaspot/markaspot/compare/11.9.120...11.9.121) (2026-06-14)

### Features

* assign organisations from facilities ([c0db258](https://github.com/markaspot/markaspot/commit/c0db2587c2b78a047044008ab19a7ad4efa00284))
* normalize facility catalogue storage ([40df3f4](https://github.com/markaspot/markaspot/commit/40df3f4ca8487f18b07e82585fa3d99e85c90c79))
* support facilities in Open311 flows ([7a0ecd0](https://github.com/markaspot/markaspot/commit/7a0ecd0376570aac708baabe7ac60bf660bc51b6))

### Bug Fixes

* **group:** grant uid 1 administrator in onboarding update instead of aborting updb ([12df383](https://github.com/markaspot/markaspot/commit/12df3836dcf2987071b336caa7e436678f9179b8))
* guard facility catalogue clears ([cdc6584](https://github.com/markaspot/markaspot/commit/cdc6584d1e8d1845026eb247ae24dd1e6d9479be))
* **mail-inbound:** fail closed on missing jurisdiction scope ([1a3d16e](https://github.com/markaspot/markaspot/commit/1a3d16ee0439b6abd0ea804a4846750e50d17e7e))
* repair AI attribute discovery ([1c4b35e](https://github.com/markaspot/markaspot/commit/1c4b35e5257ae6add930278a4b5c46a0b0f444ff))

## [11.9.120](https://github.com/markaspot/markaspot/compare/11.9.119...11.9.120) (2026-06-14)

### Features

* **nuxt:** expose operations-dashboard tier capability in feature settings ([5d02ea3](https://github.com/markaspot/markaspot/commit/5d02ea3e9abfcafce5337f6fa94e3252e17da076))
* **nuxt:** expose privacyBlockOnFlag in tenant feature settings ([#477](https://github.com/markaspot/markaspot/issues/477)) ([78a8d35](https://github.com/markaspot/markaspot/commit/78a8d35f4683224ae47451c2d6f1f71bef0ca2bd))

### Bug Fixes

* **dashboard:** enforce operations overview gates ([87e9df0](https://github.com/markaspot/markaspot/commit/87e9df0c5466a7e8e655ab8ff17cf44f5941c5f9))
* gate service request creator exposure ([7823143](https://github.com/markaspot/markaspot/commit/7823143135fc49701c95a6d2064e59c44203728c))
* **nuxt:** block anonymous user enumeration ([3fb0d27](https://github.com/markaspot/markaspot/commit/3fb0d277a4df2b8e0d9f64cd72afac10bb531bdc))
* **open311:** fall back to entity bundle for author gating ([9c09e3b](https://github.com/markaspot/markaspot/commit/9c09e3b33ad98409daa858f27bf0cef79bf2ea5f))
* **open311:** localize initial status notes ([a17ed5f](https://github.com/markaspot/markaspot/commit/a17ed5f051686fde4670b505d0a4f234edd7a333))

### Refactoring

* **nuxt:** replace deprecated basename calls ([deae3f1](https://github.com/markaspot/markaspot/commit/deae3f1f4383865af13744ae7bbb8148e901fa97))

## [11.9.119](https://github.com/markaspot/markaspot/compare/11.9.118...11.9.119) (2026-06-12)

### Performance

* **nuxt:** decouple service request count queries ([618d253](https://github.com/markaspot/markaspot/commit/618d2538f285ed459b7193f221fa0bce30d7a293))

## [11.9.118](https://github.com/markaspot/markaspot/compare/11.9.117...11.9.118) (2026-06-12)

### Performance

* **nuxt:** defer access checks in service_request collection queries via candidate window ([c435226](https://github.com/markaspot/markaspot/commit/c4352269ae4244423289e464f4a15e7731a8be62))

## [11.9.117](https://github.com/markaspot/markaspot/compare/11.9.116...11.9.117) (2026-06-12)

### Bug Fixes

* **nuxt:** restrict count cache to authenticated users, document jsonapi_defaults conflict ([5cf08d9](https://github.com/markaspot/markaspot/commit/5cf08d917c565bea71756c52021faab86e43ee4e))

## [11.9.116](https://github.com/markaspot/markaspot/compare/11.9.115...11.9.116) (2026-06-12)

### Bug Fixes

* **nuxt:** JSON:API filter arrives as Filter object, hash it before the count-cache wrapper ([33c6679](https://github.com/markaspot/markaspot/commit/33c66795b08e3307de986a23f4c52da2e577b529))

### Performance

* **nuxt:** cache JSON:API collection count for service requests ([3ba655f](https://github.com/markaspot/markaspot/commit/3ba655ff6e487c47f260c2ddb52ed8a2379073be))

## [11.9.115](https://github.com/markaspot/markaspot/compare/11.9.114...11.9.115) (2026-06-11)

### Features

* add visibility tier to WMS layer config schema ([7079372](https://github.com/markaspot/markaspot/commit/7079372959d0345e5b1ba4d54431bcb58c2bb2d9))
* **emergency,cap:** extract EmergencyModeService, harden CAP pipeline ([eb35ae5](https://github.com/markaspot/markaspot/commit/eb35ae5399e6f836a46aea041125177e570c5c7a))
* **mail_inbound:** inbound email staging pipeline with manual triage ([#467](https://github.com/markaspot/markaspot/issues/467)) ([9cd5f17](https://github.com/markaspot/markaspot/commit/9cd5f17b17db4ade7fe470201339bb8c1b89a2f7))
* **mail_inbound:** triage dashboard API, return channel and AI suggestions ([#482](https://github.com/markaspot/markaspot/issues/482)) ([6621a93](https://github.com/markaspot/markaspot/commit/6621a93cf0db1fdff344479aa63ca11fcc5f0a2e))
* **sec:** lock anonymous Drupal Views to authenticated (headless hardening) ([c5f15d3](https://github.com/markaspot/markaspot/commit/c5f15d3bd33fece7b803e9de27b5b5956ca96806))

## [11.9.114](https://github.com/markaspot/markaspot/compare/11.9.113...11.9.114) (2026-06-10)

### Bug Fixes

* **ai:** grant duplicate-detection perms to tenant_admin on install ([72e93b8](https://github.com/markaspot/markaspot/commit/72e93b836f1db5d3e469f4a452f524492aa6793e))
* **dashboard:** create field_author on fresh install ([7da2c96](https://github.com/markaspot/markaspot/commit/7da2c968f8aa07f0d272631180581c36f167c3b2))
* harden tenant safety gates ([5674b4e](https://github.com/markaspot/markaspot/commit/5674b4e369912a7612d4cb86c1b8ce4d6f3cadcc))
* **tenant_admin:** grant view field_sentiment to tenant_admin ([b6344f5](https://github.com/markaspot/markaspot/commit/b6344f5be59f0cea5c379ff64de7543352bcbb26))

## [11.9.113](https://github.com/markaspot/markaspot/compare/11.9.112...11.9.113) (2026-06-08)

### Bug Fixes

* harden status note smoke fixture ([f81c639](https://github.com/markaspot/markaspot/commit/f81c639d9750cfbf14ccaceb3bca4ccefd2f6d34))
* **vision:** fall back to a writable temp derivative when public://styles is not writable ([993de53](https://github.com/markaspot/markaspot/commit/993de530865a7c7f4f071f727668e1a8d6450c74))

## [11.9.112](https://github.com/markaspot/markaspot/compare/11.9.111...11.9.112) (2026-06-07)

### Bug Fixes

* clarify frontend fallback precedence copy ([f8dc49f](https://github.com/markaspot/markaspot/commit/f8dc49f24cb1175ea3b30b4ae61468425ff8de1c))
* clarify frontend link fallback copy ([2f93e48](https://github.com/markaspot/markaspot/commit/2f93e48a8ead821b819321afd8ccef4aa2dd3e63))
* harden frontend mail links ([d50326b](https://github.com/markaspot/markaspot/commit/d50326be4a849848074f0a9b3de55f72ee91792b))
* install stark as a profile theme dependency ([fa84490](https://github.com/markaspot/markaspot/commit/fa844903541cf0936a5236f5d751909f14a2e628))
* route markaspot_frontend:url token through the notification resolver ([a3bde0d](https://github.com/markaspot/markaspot/commit/a3bde0d6b20d41c3454e78a10a5b96913b688df4))
* scope mail frontend fallback to notification tokens ([f5a61d9](https://github.com/markaspot/markaspot/commit/f5a61d9680c872ca167db672dbe6d0492870ba29))
* **sso:** convert first-login role guard to allowlist and disable mock email linking ([aa9cb2b](https://github.com/markaspot/markaspot/commit/aa9cb2bd1b89869348a012b8c79107e6a0607f18))
* **sso:** harden first-login provisioning and ACS session handling ([5240a13](https://github.com/markaspot/markaspot/commit/5240a13473d8f8bfc51bb233c78be17298485cd2))
* support mail frontend env links ([a416f6f](https://github.com/markaspot/markaspot/commit/a416f6f80dcb63ef29c67c61c2765ded562290cf))

### Documentation

* **sso:** annotate FIRST_LOGIN_ALLOWED_SUFFIXES with member-suffix assumption ([dbbc441](https://github.com/markaspot/markaspot/commit/dbbc441215a6e944e6340b59deb6640bc26e9dbf))

## [11.9.111](https://github.com/markaspot/markaspot/compare/11.9.110...11.9.111) (2026-06-07)

### Features

* add markaspot_sso ([92393ad](https://github.com/markaspot/markaspot/commit/92393ad6d5c4e7a27a36127fae3bf01c725cdd12))

## [11.9.110](https://github.com/markaspot/markaspot/compare/11.9.109...11.9.110) (2026-06-07)

### Bug Fixes

* **open311:** add api_key_auth to GET supported_auth for all GeoReport REST resources ([728e87f](https://github.com/markaspot/markaspot/commit/728e87fa3756f9fa5363c492629e31577f1ca5c9))
* **open311:** assign jur-member to api_user so headless reads return reports ([a6320b9](https://github.com/markaspot/markaspot/commit/a6320b9071f640c5e2949afdbcc2a48fe8ad1351))
* **open311:** update hook 11810 to add api_key_auth to GeoReport REST GET methods ([258cb8e](https://github.com/markaspot/markaspot/commit/258cb8eebdc6fc7ef012b77298c1ce78d3892fe5))

## [11.9.109](https://github.com/markaspot/markaspot/compare/11.9.108...11.9.109) (2026-06-06)

### Bug Fixes

* **install:** repair all role permissions after site:install and cap media upload size ([4d5ff1e](https://github.com/markaspot/markaspot/commit/4d5ff1e9d6e07843f0848fcc90612009e0480d3c))

## [11.9.108](https://github.com/markaspot/markaspot/compare/11.9.107...11.9.108) (2026-06-06)

### Bug Fixes

* keep config uuid repair off read-only sync ([52c2895](https://github.com/markaspot/markaspot/commit/52c28951))

## [11.9.107](https://github.com/markaspot/markaspot/compare/11.9.106...11.9.107) (2026-06-06)

### Bug Fixes

* **open311:** add json_form_widget as hard dependency ([1e177d0](https://github.com/markaspot/markaspot/commit/1e177d0f1c5de1d63f47b333e1321ac828049d84))
* **open311:** tolerate missing status widget during updates ([b8c470a](https://github.com/markaspot/markaspot/commit/b8c470ac01d3550dc0a5dfc4fed604a7f089cf67))
* prepare catalog image config before import ([aaef150](https://github.com/markaspot/markaspot/commit/aaef150edb626d604cad69701163dc018dddd037))

## [11.9.106](https://github.com/markaspot/markaspot/compare/11.9.105...11.9.106) (2026-06-05)

### Bug Fixes

* repair config entity uuid drift idempotently ([f9cacaf](https://github.com/markaspot/markaspot/commit/f9cacafd6fea991287d0077091eda2998a5a6ddc))

## [11.9.105](https://github.com/markaspot/markaspot/compare/11.9.104...11.9.105) (2026-06-05)

### Features

* **profile:** guard profile-owned config during config import ([7cdcc23](https://github.com/markaspot/markaspot/commit/7cdcc23fe1a366889eef75ecb4852252be493523))

### Bug Fixes

* **install:** make a fresh site:install fully functional end-to-end ([e0e7bcd](https://github.com/markaspot/markaspot/commit/e0e7bcd8e79d5cb0dd67e03dbc9602bd6b17bf6a))

## [11.9.104](https://github.com/markaspot/markaspot/compare/11.9.103...11.9.104) (2026-06-05)

### Bug Fixes

* **install:** guard optional-module entity types in update hooks (no updb crash on divergent tenants) ([856105e](https://github.com/markaspot/markaspot/commit/856105efefa6652e5dc64946b6957ac2750c31f8))

## [11.9.103](https://github.com/markaspot/markaspot/compare/11.9.102...11.9.103) (2026-06-05)

### Features

* dedicated ai_analysis image style for Vision API ([#227](https://github.com/markaspot/markaspot/issues/227)) ([1f2801a](https://github.com/markaspot/markaspot/commit/1f2801a9faa793aa18c7c468610a9c9b3e3d4b9b))
* **nuxt:** JSON:API version-history resource via jsonapi_resources (markaspot-ui[#329](https://github.com/markaspot/markaspot/issues/329)) ([89ca1b2](https://github.com/markaspot/markaspot/commit/89ca1b253f2cfe24d09fedb96d8c4c427657200a))
* **open311:** expose revision author + enable service_request revisions (markaspot-ui[#472](https://github.com/markaspot/markaspot/issues/472)) ([a7eb8d8](https://github.com/markaspot/markaspot/commit/a7eb8d845faf9777be97cc39c3ae1cc911b4622c))

### Bug Fixes

* **facility:** guard cross-tenant field_facility + accept display fields ([#367](https://github.com/markaspot/markaspot/issues/367), [#368](https://github.com/markaspot/markaspot/issues/368)) ([6270666](https://github.com/markaspot/markaspot/commit/6270666fef4ccb90aa64d63e65ad8a9e8de51932))
* **facility:** make FacilityOwnershipConstraintValidator final (phpstan new static) ([d2bb731](https://github.com/markaspot/markaspot/commit/d2bb73141127ecde6eb416ea7e67f5d279fe0727))
* **facility:** persist + validate icon/description/url, clear foreign tag ([#368](https://github.com/markaspot/markaspot/issues/368), [#367](https://github.com/markaspot/markaspot/issues/367)) ([7e0f508](https://github.com/markaspot/markaspot/commit/7e0f508d656b689712c44e735943d2f7ff0f630e))
* **facility:** strip control chars + require non-empty host in facility url ([ec6a29c](https://github.com/markaspot/markaspot/commit/ec6a29c42e986e29a4680cce2a59c54653663020))
* **jsonapi:** hide revision_uid/log/timestamp on public service_request resource (update_11904) ([c3062ca](https://github.com/markaspot/markaspot/commit/c3062ca06b2475c99f61e69f3b5fa46b42e00292))

### Performance

* **nuxt:** version-history reads revision metadata in one query, not per-vid entity loads (markaspot-ui[#329](https://github.com/markaspot/markaspot/issues/329)) ([36420db](https://github.com/markaspot/markaspot/commit/36420db7eacfd69945ecce944387981a315fd48d))

### Refactoring

* centralise gpt-4.1-mini fallback into AiClientService::DEFAULT_CHAT_MODEL ([#341](https://github.com/markaspot/markaspot/issues/341)) ([58ffacb](https://github.com/markaspot/markaspot/commit/58ffacb89138228ba1f65908c02a021841392939))

## [11.9.102](https://github.com/markaspot/markaspot/compare/11.9.101...11.9.102) (2026-06-04)

### Features

* **open311:** attribute Open311-created revisions to acting user (markaspot-ui[#472](https://github.com/markaspot/markaspot/issues/472)) ([7e07d7d](https://github.com/markaspot/markaspot/commit/7e07d7d8283db2f151394d52b3d5a4e2d5dcaadb))
* **service_request:** assign organisation from geocoded sublocality ([d71804d](https://github.com/markaspot/markaspot/commit/d71804dbf7a2ea4ec24c9c5dacb709dbd5b29268))

### Bug Fixes

* **geocoder:** re-seed default district_mappings when empty (update_11003) ([bdbf36c](https://github.com/markaspot/markaspot/commit/bdbf36c7d649fc8e825c57fd4264ca48a931e897))
* guard legacy mail update edges ([949d62c](https://github.com/markaspot/markaspot/commit/949d62ca41ebdc83909eb606a5486faa4845ec9d))
* hide organisation mailboxes from jsonapi ([59e77f5](https://github.com/markaspot/markaspot/commit/59e77f5def7a3ec51e14e137360442bcbf2cbc18))
* keep html mail backend aligned ([82d2424](https://github.com/markaspot/markaspot/commit/82d242440f032a91b5ab1caac05cc7e3896006f7))
* **open311:** persist status and status_notes on GeoReport update requests ([b190fd7](https://github.com/markaspot/markaspot/commit/b190fd7c7b6673085d8715a78a96f9f473a56bfa))
* restore organisation notification mailbox field ([8f0b9a0](https://github.com/markaspot/markaspot/commit/8f0b9a033517cfcfc72221f8e6076afa29d93142))

## [11.9.101](https://github.com/markaspot/markaspot/compare/11.9.100...11.9.101) (2026-05-28)

### Bug Fixes

* vary management form cache by staff roles ([aecebb6](https://github.com/markaspot/markaspot/commit/aecebb6fe4823f8f8df66f37700966bfa4ba6222))

## [11.9.100](https://github.com/markaspot/markaspot/compare/11.9.99...11.9.100) (2026-05-28)

### Bug Fixes

* protect management form mode settings cache ([5ba9a9b](https://github.com/markaspot/markaspot/commit/5ba9a9b35899e3fee5ea3df4c02e7d9e610c5545))

## [11.9.99](https://github.com/markaspot/markaspot/compare/11.9.98...11.9.99) (2026-05-28)

### Bug Fixes

* mirror request attribute management updates ([ef12962](https://github.com/markaspot/markaspot/commit/ef129629898f054a17a5dd148aa5a51b39025bcc))

## [11.9.98](https://github.com/markaspot/markaspot/compare/11.9.97...11.9.98) (2026-05-28)

### Bug Fixes

* align request attributes management group ([3eb601c](https://github.com/markaspot/markaspot/commit/3eb601cadfa1fd6c61d6435ebc8125c041de495c))

## [11.9.97](https://github.com/markaspot/markaspot/compare/11.9.96...11.9.97) (2026-05-28)

### Features

* add jurisdiction request id prefixes ([a28db00](https://github.com/markaspot/markaspot/commit/a28db00bcaead7a1fc691ee80d5327ed945246a5))

### Bug Fixes

* align demo expiry mail label ([0533e0e](https://github.com/markaspot/markaspot/commit/0533e0e6fdab7bfadd1f4009c89f39d3b76bbadf))
* expose request attributes in management form ([7ff63b9](https://github.com/markaspot/markaspot/commit/7ff63b929e4cb222f1be4d8cea6a88d149eeaba9))
* localize organisation assignment mails ([5e1a052](https://github.com/markaspot/markaspot/commit/5e1a0524a5268552ba10975019e9f0650c6b0d5e))
* make request id prefix update deploy-safe ([a15d837](https://github.com/markaspot/markaspot/commit/a15d8371b26437cbbcb541fc154e3e8455a21abd))
* mirror request management update hooks ([f23d48a](https://github.com/markaspot/markaspot/commit/f23d48aad662ef59b94a17c25a08ff71e2d8ad7c))
* notify organisation group members ([8757358](https://github.com/markaspot/markaspot/commit/87573587789362280e8fdcb357c9554aae13d62c))
* prepare request image upload storage ([c1bd438](https://github.com/markaspot/markaspot/commit/c1bd438657335db23422d040ec83ca6ab183a7bd))

### Refactoring

* align request management fields and jurisdiction scope ([9731c95](https://github.com/markaspot/markaspot/commit/9731c952f8bd99e4ac3d08b66f3a1444839f16c8))

## [11.9.96](https://github.com/markaspot/markaspot/compare/11.9.95...11.9.96) (2026-05-26)

### Features

* **open311:** comma-IN operator for allowlisted field_* filters ([6b0522c](https://github.com/markaspot/markaspot/commit/6b0522ceb51318230b203966577d71082726945f))

### Bug Fixes

* repair NULL/invalid config-entity uuids on migrated tenants ([42883fc](https://github.com/markaspot/markaspot/commit/42883fcc1573701865a83404ec4e156d2584a364))
* **security:** allowlist field_* parameters on GeoReport requests index ([848867a](https://github.com/markaspot/markaspot/commit/848867acf698514e875f1ae9635e0de4278b6795))
* **security:** minimise user--user JSON:API fields and gate internal_remark writes ([896e17c](https://github.com/markaspot/markaspot/commit/896e17c6e5360887319a209e44550b8faf8b1bb5))

## [11.9.95](https://github.com/markaspot/markaspot/compare/11.9.94...11.9.95) (2026-05-25)

### Bug Fixes

* **vision:** bind analysis to upload context ([134b517](https://github.com/markaspot/markaspot/commit/134b517c3f7729d86873b0dbd8802121c7c352ca))

## [11.9.94](https://github.com/markaspot/markaspot/compare/11.9.93...11.9.94) (2026-05-25)

### Features

* **vision:** keep privacy_flag strict, suppress citizen prompt only on blur-remediated PII ([999118e](https://github.com/markaspot/markaspot/commit/999118e1de477132cf371a8066541c464abff7e1))
* **vision:** return blurred thumbnail (data URL) for citizen upload preview ([68651a7](https://github.com/markaspot/markaspot/commit/68651a7f47d8e8bda57e9860efacc3398f0fa074))

### Bug Fixes

* add service request category organisation action ([6b6df04](https://github.com/markaspot/markaspot/commit/6b6df04244bb563b6e79f1a5165dc93433f738fd))
* add service request organisation sync action ([da2bfaf](https://github.com/markaspot/markaspot/commit/da2bfafec238c6ad4b85d546dccdf6c9618e4765))
* declare taxonomy/language/content_translation deps for markaspot_language ([f2713f1](https://github.com/markaspot/markaspot/commit/f2713f183c0aef8cdbcab680decd466a31e6bb2f))
* keep initial status read-only after adding a new status ([b5bab9c](https://github.com/markaspot/markaspot/commit/b5bab9c9cbb1216bc674ca4ca084ad7dac740c8b)), closes [lhm#510](https://github.com/markaspot/lhm/issues/510)
* prevent editing the initial status via "Edit all" ([89a3eb5](https://github.com/markaspot/markaspot/commit/89a3eb5eb53e103c2826f6e4ea46bd38f6e92486)), closes [lhm#510](https://github.com/markaspot/lhm/issues/510) [#disabled](https://github.com/markaspot/markaspot/issues/disabled)

### Refactoring

* **vision:** deterministic blur privacy signal + off-domain detection ([45122e9](https://github.com/markaspot/markaspot/commit/45122e998ffa6927decd4fd4304350f780978a60))

## [11.9.93](https://github.com/markaspot/markaspot/compare/11.9.92...11.9.93) (2026-05-18)

### Features

* clarify free tier in demo expiry reminder mail ([3e0d80b](https://github.com/markaspot/markaspot/commit/3e0d80b0e06d3405b088d042fbb6b5575b931323))

### Bug Fixes

* **dashboard:** restrict internal remarks to staff roles ([dc9b9f0](https://github.com/markaspot/markaspot/commit/dc9b9f03b725c6409e9743e7f7b17614a99cc60c))
* restore anonymous public report submission permissions ([11c73f7](https://github.com/markaspot/markaspot/commit/11c73f7f5d77ca28d08f9f5993fc05a7fc0527c0))
* **tenant_admin:** scope request_image media updates to managed jurisdictions ([1166b42](https://github.com/markaspot/markaspot/commit/1166b42b37d394086d7450e5d82afe6d90f83f7c))

## [11.9.92](https://github.com/markaspot/markaspot/compare/11.9.91...11.9.92) (2026-05-17)

### Features

* status attributes for service status transitions ([541ea1f](https://github.com/markaspot/markaspot/commit/541ea1f89b34ec792385799bf71a0ccd7d4e4126))

### Bug Fixes

* **health:** bind status-note smoke fixture to its jurisdiction group ([ed3d13c](https://github.com/markaspot/markaspot/commit/ed3d13cd74b3f0b0ed45659f34b33d669eb5322e))

## [11.9.91](https://github.com/markaspot/markaspot/compare/11.9.90...11.9.91) (2026-05-16)

### Bug Fixes

* **nuxt:** harden tenant settings logo deletion and access caching ([9524fc4](https://github.com/markaspot/markaspot/commit/9524fc44e6988737ca4fedaf42642b60b769b2f2))

## [11.9.90](https://github.com/markaspot/markaspot/compare/11.9.89...11.9.90) (2026-05-16)

### Features

* **open311:** resolve entity references in full field export ([d07f5d4](https://github.com/markaspot/markaspot/commit/d07f5d4087ec8a77e3c44793e709bb55bf47a6b6))

### Bug Fixes

* allow configured wms layers for classic tenants ([974fac9](https://github.com/markaspot/markaspot/commit/974fac9c25df19323dea10dd289fecf72bf322ce))
* **media:** migrate existing tenants to the request_media selection handler ([8f63a45](https://github.com/markaspot/markaspot/commit/8f63a45424a8381dceffffb8439c37b1845a9104))
* **open311:** gate full entity export behind dedicated PII permission ([0b4396a](https://github.com/markaspot/markaspot/commit/0b4396a959a847121a351400db4202961339a759))

### Refactoring

* **fastmap:** group field_nuxt_config keys by concern ([1bfbcff](https://github.com/markaspot/markaspot/commit/1bfbcff9c124752f3efccc8daf219a0bb87f7602))

## [11.9.89](https://github.com/markaspot/markaspot/compare/11.9.88...11.9.89) (2026-05-15)

### Features

* **fastmap:** SaaS billing admin, legal notice generator, workspace hardening ([2535064](https://github.com/markaspot/markaspot/commit/2535064015563ba6a36963d2fdb12c207a48c4fd))
* **nuxt:** tier-gate features.customWmsLayers for paid workspaces ([ec5565b](https://github.com/markaspot/markaspot/commit/ec5565b088e2dfc3bbead2aa714beeb4fa5ac881))

### Bug Fixes

* align mail health attachment config ([602a362](https://github.com/markaspot/markaspot/commit/602a36243a79f9fcaca98c4c4632b04d9e2db9d3))
* **fastmap:** distinguish canceled subscription from pending checkout ([ed66804](https://github.com/markaspot/markaspot/commit/ed66804f0ee99df3d129a40c736111021807c15c))
* **fastmap:** expose has_stripe_customer boolean in nuxt jurisdiction config ([0f1f3ef](https://github.com/markaspot/markaspot/commit/0f1f3ef7feb4bef8f633b7a2fcda40b0a07978ae))
* **mail:** drop pre-existing Reply-To before branding override ([b126ec6](https://github.com/markaspot/markaspot/commit/b126ec666e98cb3dc1960a3ea45bde32788c364c))

### Refactoring

* **nuxt:** decouple privacyNotice display from field_gdpr consent ([57bfda1](https://github.com/markaspot/markaspot/commit/57bfda1e7bc0f0c3e4dc2581e2fde88275be0167))

## [11.9.88](https://github.com/markaspot/markaspot/compare/11.9.87...11.9.88) (2026-05-13)

### Bug Fixes

* **group:** import CacheableJsonResponse + CacheableMetadata in admin endpoint ([0337220](https://github.com/markaspot/markaspot/commit/0337220b417ff7da638a6c8baa04cf607a555d89))

## [11.9.87](https://github.com/markaspot/markaspot/compare/11.9.86...11.9.87) (2026-05-13)

### Bug Fixes

* **fastmap:** re-emit blocked-visibility recovery as update_11921 ([86a951a](https://github.com/markaspot/markaspot/commit/86a951adaf009d3ddc4284e5ba9d45ac633d7b78))

## [11.9.86](https://github.com/markaspot/markaspot/compare/11.9.85...11.9.86) (2026-05-13)

### Bug Fixes

* **fastmap:** handle both allowed_values shapes in update_11920 ([e643765](https://github.com/markaspot/markaspot/commit/e643765367d40a40663f73b05784e562f8a15fae))

## [11.9.85](https://github.com/markaspot/markaspot/compare/11.9.84...11.9.85) (2026-05-13)

### Features

* **spam:** workspace blocking, AI scanner, admin jurisdictions endpoint ([ebea0e1](https://github.com/markaspot/markaspot/commit/ebea0e16b7b5774bab0036a8b534acc0771bc2ad))

## [11.9.84](https://github.com/markaspot/markaspot/compare/11.9.83...11.9.84) (2026-05-13)

### Features

* **fastmap:** backfill features.passwordless drift in workspace provisioning ([d533aa2](https://github.com/markaspot/markaspot/commit/d533aa21641f84749bc3fc2baadf4768782acb61))

## [11.9.83](https://github.com/markaspot/markaspot/compare/11.9.82...11.9.83) (2026-05-13)

### Bug Fixes

* **tenant_admin:** remove duplicate use Drupal\user\Entity\User import ([0c19bcc](https://github.com/markaspot/markaspot/commit/0c19bccad1c8ec9d8d317a548796f65e6a9d5f53))

## [11.9.82](https://github.com/markaspot/markaspot/compare/11.9.81...11.9.82) (2026-05-12)

### Features

* **install:** default register=admin_only for new tenants ([a9cc944](https://github.com/markaspot/markaspot/commit/a9cc94439e850f526695d7253c16156dd33f4c0f))

## [11.9.81](https://github.com/markaspot/markaspot/compare/11.9.80...11.9.81) (2026-05-12)

### Bug Fixes

* **tenant_admin:** invalidate status access on membership revoke + log cross-jur ([faff484](https://github.com/markaspot/markaspot/commit/faff484996f7f02fba63e3a9ce5ad9f73a0e1d27))
* **tenant_admin:** jurisdiction-scoped publish access for moderators + service_request ([dbca875](https://github.com/markaspot/markaspot/commit/dbca8755a5ff74374e2cd74aeaeb4b366d8959f9))

## [11.9.80](https://github.com/markaspot/markaspot/compare/11.9.79...11.9.80) (2026-05-12)

### Bug Fixes

* **open311:** guard field_author set on status paragraph ([1dee350](https://github.com/markaspot/markaspot/commit/1dee3508659f65d73d9708866e79522827dd979e))
* **open311:** guard field_boilerplate + log missing field_author ([38a6d45](https://github.com/markaspot/markaspot/commit/38a6d45250c3649f94243cc93cd7c91c66ecca74))
* **service_request:** localize status note body across enabled languages ([7cbcd11](https://github.com/markaspot/markaspot/commit/7cbcd11e08daaaec6bf60f63c205f8058a4319e7))

## [11.9.79](https://github.com/markaspot/markaspot/compare/11.9.78...11.9.79) (2026-05-12)

### Bug Fixes

* install notification REST resource ([ba6c782](https://github.com/markaspot/markaspot/commit/ba6c7825b3b038792b52cd49d00cd69fd8325778))
* **notification:** classify mail side effects ([ce634c1](https://github.com/markaspot/markaspot/commit/ce634c184e48b017e91cc66fb5371e30ec90cf22))
* **service_request:** drop invalid body field dependency ([1081da3](https://github.com/markaspot/markaspot/commit/1081da330770bda6ff9319321d664accc46c7723))

## [11.9.78](https://github.com/markaspot/markaspot/compare/11.9.77...11.9.78) (2026-05-11)

### Bug Fixes

* **geocoder:** security and robustness hardening ([1be3a29](https://github.com/markaspot/markaspot/commit/1be3a29f331e34eece8554e14541a298d35fc779))

## [11.9.77](https://github.com/markaspot/markaspot/compare/11.9.76...11.9.77) (2026-05-11)

### Bug Fixes

* **group:** allow jurisdiction roles to read boilerplates ([7d26863](https://github.com/markaspot/markaspot/commit/7d26863218ff4b0ea5e46fc27409847b7f60579f))
* **mail:** fallback to single-jurisdiction branding when platform mails lack context ([28bb73b](https://github.com/markaspot/markaspot/commit/28bb73b41aefdd94375cb14f59ae9b2596fe1d75))
* **mail:** support single-tenant legal links ([a4f3eb0](https://github.com/markaspot/markaspot/commit/a4f3eb0e0f67b16bd74441be2204e27533babd3a))

## [11.9.76](https://github.com/markaspot/markaspot/compare/11.9.75...11.9.76) (2026-05-10)

### Features

* **geocoder:** add explicit forward lookup ([3b72974](https://github.com/markaspot/markaspot/commit/3b72974c445a878479f3d4a7155372c360e69db6))
* **markaspot_health:** add F-21 scope-lock smoke acceptance test ([bc10d9e](https://github.com/markaspot/markaspot/commit/bc10d9e905bbae853d80c20f9a91ecf7cda6a0b3))
* **markaspot_health:** smoke v2 — mutating-track, editorial readiness, pretty TTY, CI ([00e3dcf](https://github.com/markaspot/markaspot/commit/00e3dcf97f28fa12df51ec989e7f15977243741c))

### Bug Fixes

* **ci:** require drush in the smoke workflow bootstrap ([f409675](https://github.com/markaspot/markaspot/commit/f4096755dc2029fc350040512c11f165ff081aa0))
* **markaspot_health:** accept 401/403 in http_frontend_public smoke ([d579a1f](https://github.com/markaspot/markaspot/commit/d579a1fd82af04747bd399eecc976c9c80f4b5ca))
* **markaspot_health:** smoke fixtures handle WBD-style hierarchical tenants ([f9ebf7f](https://github.com/markaspot/markaspot/commit/f9ebf7f9f6125ffcd55979f3dc9daab7ae00beb9))
* **markaspot_open311+health:** contain post-save throws + mail-failure HealthCheck ([58d1af4](https://github.com/markaspot/markaspot/commit/58d1af44a41ebf95f79d5913335a9950f51903ff))
* **markaspot_open311:** rate-limit UPDATE endpoint + Retry-After jitter ([b9f9ed3](https://github.com/markaspot/markaspot/commit/b9f9ed3f25f9a72f47d64c53be67358043070372))

## [11.9.75](https://github.com/markaspot/markaspot/compare/11.9.74...11.9.75) (2026-05-09)

### Features

* add Drupal session handoff endpoints ([5e45b20](https://github.com/markaspot/markaspot/commit/5e45b201deb2254e09c3ab4037071188e4c6f748))
* **markaspot_health:** tenant API smoke suite (drush markaspot:smoke) ([d072944](https://github.com/markaspot/markaspot/commit/d072944034d89258d432feeddb858f37030fd121))

### Bug Fixes

* **markaspot_mail:** synthesize tenant footer when group fields empty ([caf3dff](https://github.com/markaspot/markaspot/commit/caf3dff5f99b411bd7e9aa6c75cbac7fbb02c08d))

## [11.9.74](https://github.com/markaspot/markaspot/compare/11.9.73...11.9.74) (2026-05-08)

### Bug Fixes

* **markaspot_dashboard:** drop typed configFactory redeclaration in DashboardFilterForm ([a8ea4bf](https://github.com/markaspot/markaspot/commit/a8ea4bfc9d97fe5cad7d299b439dc5f261bac4c1))

## [11.9.73](https://github.com/markaspot/markaspot/compare/11.9.72...11.9.73) (2026-05-08)

### Bug Fixes

* **markaspot_dashboard:** drop typed configFactory promotion ([a4301f9](https://github.com/markaspot/markaspot/commit/a4301f9545bcd5a05d1a0f554119a1bb39cb1a50))

## [11.9.72](https://github.com/markaspot/markaspot/compare/11.9.71...11.9.72) (2026-05-08)

### Features

* improve passwordless mail branding ([dd1ec46](https://github.com/markaspot/markaspot/commit/dd1ec46b87c15907844a2a36227397f4db371f90))
* **markaspot_nuxt:** setup completion flag for first-run branding ([7c35506](https://github.com/markaspot/markaspot/commit/7c355060d7157ecddb2b6271b81ea9a72d6a084f))
* **markaspot_nuxt:** tenant alerts API + branding alert source ([54cb2e5](https://github.com/markaspot/markaspot/commit/54cb2e5eda25cfa201897d92de39097b8207c623))
* **passwordless:** expose jurisdiction slug on auth_user.groups ([be42583](https://github.com/markaspot/markaspot/commit/be42583ccf4413f6ec240d31793b849e527e4e59)), closes [markaspot-ui#438](https://github.com/markaspot/markaspot-ui/issues/438)

### Bug Fixes

* embed mail module logo assets ([efa6fb8](https://github.com/markaspot/markaspot/commit/efa6fb819764f6d62bf8a1929aee9b1c331e94a6))
* **passwordless:** trim, regex-validate, and reject digit-only slugs ([4ec70a0](https://github.com/markaspot/markaspot/commit/4ec70a0b19a6b155e50a5948452a93d24e6c7f8d)), closes [markaspot-ui#438](https://github.com/markaspot/markaspot-ui/issues/438)
* preserve eca mail line breaks ([0cf8d59](https://github.com/markaspot/markaspot/commit/0cf8d598da15f04841bf63063bc4215c52102bb6))
* require explicit tenant ai opt-in ([aa5bb63](https://github.com/markaspot/markaspot/commit/aa5bb63a64e6064ef50c19717535efe23a1a23b5))
* **theme:** allow Tailwind v4.2 warm/organic neutrals in palette whitelist ([b5d7ad4](https://github.com/markaspot/markaspot/commit/b5d7ad4c71d9aea188e8c486d98e2055c47dc720))

## [11.9.71](https://github.com/markaspot/markaspot/compare/11.9.70...11.9.71) (2026-05-04)

### Bug Fixes

* add health repair guidance ([dfd7934](https://github.com/markaspot/markaspot/commit/dfd7934bd824843e4ec4166347bf9b82a848b39a))

## [11.9.70](https://github.com/markaspot/markaspot/compare/11.9.69...11.9.70) (2026-05-04)

### Features

* **group:** organisation-jurisdiction integrity model ([e3eafc8](https://github.com/markaspot/markaspot/commit/e3eafc8e43713df972775b4fd4d5c167ce8c56f9))
* **health:** admin health check plugin system ([74eadf9](https://github.com/markaspot/markaspot/commit/74eadf9275a67850a9f4b585f460c87b3b097fd2))
* tenant scope hardening + tenant-admin role lifecycle ([4e274b5](https://github.com/markaspot/markaspot/commit/4e274b5aa3c6340e0a1f49f6e2b3767df57c465f))
* tenant-scoped open311, service-provider, nuxt config alignment ([5d892e8](https://github.com/markaspot/markaspot/commit/5d892e806950f1c38b5a995b546a2da33281fdf4))

### Bug Fixes

* reconcile jurisdiction relationships for new requests ([5f37b3f](https://github.com/markaspot/markaspot/commit/5f37b3f776ed2231bfd1c8001766736c0bfa2ba6))

## [11.9.69](https://github.com/markaspot/markaspot/compare/11.9.68...11.9.69) (2026-04-29)

### Features

* **mail:** add MARKASPOT_OPERATING_MODE env-driven branding kill-switch ([5054bd5](https://github.com/markaspot/markaspot/commit/5054bd567a2221b5b61b708618ce71093058391f))
* **token:** add [node:latest_status_note] for citizen mail personalisation ([82f8b77](https://github.com/markaspot/markaspot/commit/82f8b772884b4c84c79bd897f96da53577fb1075))

### Bug Fixes

* **i18n:** skip status_note translation import on non-DE sites ([e5f3119](https://github.com/markaspot/markaspot/commit/e5f31191aaaf326ced53f341b83753a4285b88e3))
* **i18n:** translate 'Status changed.' fallback for citizen mails ([3b24ff8](https://github.com/markaspot/markaspot/commit/3b24ff84292c32f138852e52739ce552b7d45915))
* **mail:** correct Markup namespace and align builder test assertions ([6994863](https://github.com/markaspot/markaspot/commit/699486354c6eb39f1dead8a7990bb408e42f5d94))
* register feature flag access service ([b3a9926](https://github.com/markaspot/markaspot/commit/b3a9926dfcd867dd660716d4b9ea172c1702e0cc))
* **token:** harden status_note token resolution ([6eb524b](https://github.com/markaspot/markaspot/commit/6eb524bdb5761114c0bb09f13387c05491436186))

### Refactoring

* **mail:** pass absolute URLs through buildModuleAssetUrl ([a9c77e1](https://github.com/markaspot/markaspot/commit/a9c77e15fb794c16a94fcdaf10cbba03dcc4dc5a))
* **mail:** read operating mode via Settings::get with memoization ([937797b](https://github.com/markaspot/markaspot/commit/937797b91bef0121a07bf5558794ca4a6d218bba))

## [11.9.68](https://github.com/markaspot/markaspot/compare/11.9.67...11.9.68) (2026-04-27)

### Bug Fixes

* **group:** show status terms for tenant_admin without jur-tenant_admin group role ([330ab37](https://github.com/markaspot/markaspot/commit/330ab37d71561e8a88c6a20f893e00dd77f1e2d4))

## [11.9.67](https://github.com/markaspot/markaspot/compare/11.9.66...11.9.67) (2026-04-26)

### Features

* **i18n:** register Czech locale and add Hungarian backend parity ([2f53031](https://github.com/markaspot/markaspot/commit/2f53031d1f6105b87d338a5afab962fe7042052a))

## [11.9.66](https://github.com/markaspot/markaspot/compare/11.9.65...11.9.66) (2026-04-26)

### Features

* **facility:** accept structured FacilityAddress with ISO country validation ([6adba7e](https://github.com/markaspot/markaspot/commit/6adba7ed7c5c57b83ef29fa1a7800418534dab50))

### Bug Fixes

* **workspace:** localize default status terms on workspace create ([86639d3](https://github.com/markaspot/markaspot/commit/86639d3c434b3e918faebb174813e5e0f18de17e))

## [11.9.65](https://github.com/markaspot/markaspot/compare/11.9.64...11.9.65) (2026-04-24)

### Features

* **facility:** add facility-based reporting ([94db1ad](https://github.com/markaspot/markaspot/commit/94db1ad02924287ecb8312fc68bd89d447cdf69d))
* **facility:** gate exclusive-mode assumption on location + address ([b5020e0](https://github.com/markaspot/markaspot/commit/b5020e0f7aa1373a92baf90ba3f7e36c7d89356b))
* **markaspot_validation:** check_unpublished config for moderated workflows ([b5047f8](https://github.com/markaspot/markaspot/commit/b5047f8d6c19a54e295bf3e229da14975a9bd1c7))
* **markaspot_validation:** surface duplicate-check cause via JSON:API meta ([bcad249](https://github.com/markaspot/markaspot/commit/bcad249cde87814e1626dac137547aedd51689be))
* **open311:** expose field_facility in extended attributes ([abcea70](https://github.com/markaspot/markaspot/commit/abcea70e042dcfdb82578d9282bfd3f77d7aea80))

## [11.9.64](https://github.com/markaspot/markaspot/compare/11.9.63...11.9.64) (2026-04-20)

### Bug Fixes

* **mail:** add b/i to mail tag whitelist for CKEditor compat ([09b45ff](https://github.com/markaspot/markaspot/commit/09b45ffa1feb01ddc59ef9b83c65d98ae31c68ba))
* **mail:** render token HTML as raw in transactional card template ([38e7f83](https://github.com/markaspot/markaspot/commit/38e7f83eb4687c558eb2679bac645cd341235f9d))
* **mail:** resolve Media entities in AttachmentResolver, add media dependency ([228b75b](https://github.com/markaspot/markaspot/commit/228b75b01ea0b55542c508d037375c8f25a10871))
* **mail:** sanitize body paragraphs and harden AttachmentResolver ([bc6b2e0](https://github.com/markaspot/markaspot/commit/bc6b2e0ef92d44e0d26c8d1d36f2f7639256fa08))
* **mail:** tighten XSS filter to mail-safe tag whitelist ([45b2ad8](https://github.com/markaspot/markaspot/commit/45b2ad83fbf6632e025063f709d1884c68df82a4))

## [11.9.63](https://github.com/markaspot/markaspot/compare/11.9.62...11.9.63) (2026-04-20)

### Features

* add markaspot_notification module for ECA side-effect feedback ([f7c17ae](https://github.com/markaspot/markaspot/commit/f7c17ae6b703ec149a3f9ce9573586350a52f0cd))
* **mail:** add attachment pipeline for staff-recipient mails ([432c128](https://github.com/markaspot/markaspot/commit/432c12811199e3f0d1b67d2445fcff29a3d2527f))

### Bug Fixes

* **dashboard:** guard organisation field join against missing node table ([b2319c3](https://github.com/markaspot/markaspot/commit/b2319c32145cb61218e49bbfb4c54e996200d359))
* **eca:** pass entity to action_send_email_action via object key ([218fe6e](https://github.com/markaspot/markaspot/commit/218fe6ea19ae096e35d54b35d5d7f700f3fcaadd))
* **eca:** remove citizen body from confirmation email to prevent token injection ([9c056a2](https://github.com/markaspot/markaspot/commit/9c056a225781af14c09de84f9da60f594d40260d))
* **mail:** keep ECA transactional intro and body blocks as strings ([de952fd](https://github.com/markaspot/markaspot/commit/de952fdff5e4f1df9bb00531facb32e47979b1ad))
* **mail:** render token HTML in ECA bodies, use group label as platform_name fallback ([65c8c5d](https://github.com/markaspot/markaspot/commit/65c8c5dd707ad84b90deb814d545e7ef5f7c1f76))
* **mail:** render token-replaced HTML in ECA action email body blocks ([ba7dcef](https://github.com/markaspot/markaspot/commit/ba7dcef8f55dcd482df609f22a1387073d93d945))

## [11.9.62](https://github.com/markaspot/markaspot/compare/11.9.61...11.9.62) (2026-04-19)

### Bug Fixes

* skip address module personal-name constraints on jurisdiction address field ([e8b21c5](https://github.com/markaspot/markaspot/commit/e8b21c56307d539f474a147581fadec164ecc665))

## [11.9.61](https://github.com/markaspot/markaspot/compare/11.9.60...11.9.61) (2026-04-19)

### Bug Fixes

* **mail:** replace old gray wordmark with new blue brand logo PNG ([b9f5ced](https://github.com/markaspot/markaspot/commit/b9f5cedb24f6c06e04f63082a14f833d4d2bb1b1)), closes [#587cff](https://github.com/markaspot/markaspot/issues/587cff) [#002682](https://github.com/markaspot/markaspot/issues/002682)

## [11.9.60](https://github.com/markaspot/markaspot/compare/11.9.59...11.9.60) (2026-04-19)

### Features

* **dashboard:** add admin aggregate endpoints ([46b7a60](https://github.com/markaspot/markaspot/commit/46b7a60f7db346f018f1c04d60d9f089e7e9d364))

### Bug Fixes

* ensure phpmailer_smtp sends HTML on migrated tenants ([310f6bb](https://github.com/markaspot/markaspot/commit/310f6bb1854f35ab662e702c9561224e302924c8))
* improve update hook 11913 per review ([a662191](https://github.com/markaspot/markaspot/commit/a662191e34ff744cc549e20d117442dfc67ab924))

## [11.9.59](https://github.com/markaspot/markaspot/compare/11.9.58...11.9.59) (2026-04-19)

### Bug Fixes

* **markaspot_mail:** remove OTP space that broke copy-paste, reduce hero code size ([46af993](https://github.com/markaspot/markaspot/commit/46af9935bdc51d943d4fff8423193528972a2ccc))
* **markaspot_mail:** restore 3+3 visual grouping via template spans ([2a378d1](https://github.com/markaspot/markaspot/commit/2a378d1ca43cbc63e2c62f830573dc188f24018c))
* **markaspot_mail:** use px instead of em for span gap for Outlook compat ([e15ef0e](https://github.com/markaspot/markaspot/commit/e15ef0e0ae01adf8cbb3412a6c0d3c6b3dc586e7))

## [11.9.58](https://github.com/markaspot/markaspot/compare/11.9.57...11.9.58) (2026-04-19)

### Bug Fixes

* **markaspot_mail:** restore Content-Type text/html and add passthrough theme ([bad0e7d](https://github.com/markaspot/markaspot/commit/bad0e7d6bc0c99125f141c2baf2c6cb2eee02e4f))

## [11.9.57](https://github.com/markaspot/markaspot/compare/11.9.56...11.9.57) (2026-04-19)

### Bug Fixes

* **mail:** strip style blocks before phpmailer_smtp generates AltBody ([b4daf78](https://github.com/markaspot/markaspot/commit/b4daf78711bcf8b810a0e4d88257bba2dc818553))
* **management-form:** replace field_service_provider_files with field_sp_attachment in group_service_provider ([b3ce16f](https://github.com/markaspot/markaspot/commit/b3ce16f696b62b6743ad55f3317caa349637a0ff))

## [11.9.56](https://github.com/markaspot/markaspot/compare/11.9.55...11.9.56) (2026-04-19)

### Bug Fixes

* **mail:** remove explicit Content-Type header to allow multipart/alternative ([d1b4e86](https://github.com/markaspot/markaspot/commit/d1b4e86e1842e035deb2e63b6c6ddf8fc54d6ab5))

## [11.9.55](https://github.com/markaspot/markaspot/compare/11.9.54...11.9.55) (2026-04-18)

### Bug Fixes

* inline sanitized SVG jurisdiction logos in transactional mails ([7b00294](https://github.com/markaspot/markaspot/commit/7b00294aadd800b3dc727aebfb26165b4dc7d20e))

## [11.9.54](https://github.com/markaspot/markaspot/compare/11.9.53...11.9.54) (2026-04-18)

### Features

* **boilerplate:** add field_organisation for org-specific boilerplates ([e4336f3](https://github.com/markaspot/markaspot/commit/e4336f36c5fe9e51a3997cb531993c5495be053c))
* **boilerplate:** allow multiple orgs per boilerplate ([f1d8bb4](https://github.com/markaspot/markaspot/commit/f1d8bb469b7aef3949ec152a2bf68739e22e9cf9))
* **markaspot_validation:** add excluded_statuses config for duplicate check ([2cdd00a](https://github.com/markaspot/markaspot/commit/2cdd00afec059fe06752c8d7f0272f5e5899b50c))
* **service_request:** update_11009 adds field_service_provider_status + group merge ([d30a3f4](https://github.com/markaspot/markaspot/commit/d30a3f483efd1e971014c90a9c7d95d85585d1cf))
* **service_request:** update_11009 adds management form groups + SP/internal components ([6a42175](https://github.com/markaspot/markaspot/commit/6a42175b556f88fbd0e02504b9b0d579a1ad9c95))

### Bug Fixes

* **group:** propagate ECA set:clear -> append + dedupe field_organisation ([2579f3a](https://github.com/markaspot/markaspot/commit/2579f3ab47e9bd3674c5b7e91e31cc98377b8e0d))

### Refactoring

* **boilerplate,group:** relocate cardinality bump, fix ECA append ([0cbc367](https://github.com/markaspot/markaspot/commit/0cbc367b7f32b51de24488872c893c3eac6fde2b))
* **markaspot_validation:** fix settings form review findings ([38233af](https://github.com/markaspot/markaspot/commit/38233afac81d56e5fe3cd15b0fdc501de118a9c6))
* **markaspot_validation:** remove useless validateForm override, cast integer config values ([c1f144d](https://github.com/markaspot/markaspot/commit/c1f144dbee50dcaabae81c034cecba0a4eb0e9e6))

## [11.9.53](https://github.com/markaspot/markaspot/compare/11.9.52...11.9.53) (2026-04-17)

### Bug Fixes

* **mail:** rewrite asset URLs that resolve with a container-internal host ([47a2ac4](https://github.com/markaspot/markaspot/commit/47a2ac4a9353a2cd54e3a60283e389d85d398f9c))

## [11.9.52](https://github.com/markaspot/markaspot/compare/11.9.51...11.9.52) (2026-04-17)

### Bug Fixes

* **mail:** resolve asset URLs absolutely for CLI and queue rendering ([a75b105](https://github.com/markaspot/markaspot/commit/a75b105a42bd69b3e8eceb7ec4488a16e6aa2078))

### Refactoring

* **mail:** strip brand-specific defaults from mail settings ([d60fc61](https://github.com/markaspot/markaspot/commit/d60fc61684af2cb91c62292c35a165d621168943))

## [11.9.51](https://github.com/markaspot/markaspot/compare/11.9.50...11.9.51) (2026-04-17)

### Bug Fixes

* **mail:** force phpmailer_smtp default format to html ([f6621ca](https://github.com/markaspot/markaspot/commit/f6621ca64be29fef9189d439c6becffc40df0ae4))

## [11.9.50](https://github.com/markaspot/markaspot/compare/11.9.49...11.9.50) (2026-04-17)

### Bug Fixes

* **mail:** enable markaspot_mail on existing and fresh installs ([6a11bb5](https://github.com/markaspot/markaspot/commit/6a11bb50bb1415b69a4bea5c60665b9fd53f2439))

## [11.9.49](https://github.com/markaspot/markaspot/compare/11.9.48...11.9.49) (2026-04-17)

### Features

* **mail:** add EcaActionEmailBuilder for Drupal-core EmailAction mails ([568df58](https://github.com/markaspot/markaspot/commit/568df583c295dbe70dad0e5fd98d7a29299cbcbf))
* **mail:** add FastMap workspace builders (Stage 3) ([1ab4285](https://github.com/markaspot/markaspot/commit/1ab4285fdcfeda5761cd9a8ec651735af5780e40))
* **mail:** add FeedbackRequestBuilder (first ECA builder POC) ([1b9e17e](https://github.com/markaspot/markaspot/commit/1b9e17eff00b47f77d43be7dcce36a114a44c755))
* **mail:** add four consumer builders (Escalation, Resubmission, Moderation, OrgNotification) ([6e7ca2b](https://github.com/markaspot/markaspot/commit/6e7ca2b15348f71e68f2e882ce9af9ef7a460021))
* **mail:** add GroupMemberInvitationBuilder (second ECA builder POC) ([1844a55](https://github.com/markaspot/markaspot/commit/1844a55f6452b9bd223a443f948fc18a4254b217))
* **mail:** add markaspot_mail foundation for branded HTML mails ([d1e5e4b](https://github.com/markaspot/markaspot/commit/d1e5e4b5325fdb5f65701cefc9a06085720246b4)), closes [#004ced](https://github.com/markaspot/markaspot/issues/004ced) [#EEF3FF](https://github.com/markaspot/markaspot/issues/EEF3FF)
* **mail:** add PasswordlessOtpBuilder with Anthropic-style hero_code (Stage 4) ([9a029c0](https://github.com/markaspot/markaspot/commit/9a029c0983b041f070918d450b3245a9809ed7f3)), closes [#004ced](https://github.com/markaspot/markaspot/issues/004ced) [#325](https://github.com/markaspot/markaspot/issues/325)
* **mail:** honor show_platform_footer flag to hide Civic Patches attribution ([eedebee](https://github.com/markaspot/markaspot/commit/eedebee440e09a94b1c85dd12cacac8fbfc87f4c))
* **mail:** populate 15 locale translations (codex first pass) ([0558962](https://github.com/markaspot/markaspot/commit/05589622ef5aaee061230988f9253120721cccdd))
* **mail:** ship Stage 5 i18n infrastructure + DE translation ([43bd427](https://github.com/markaspot/markaspot/commit/43bd427e81636acfd516588168108ed32181da1b))

### Bug Fixes

* **ai:** IBAN must match before phone pattern in PII redaction ([1fbe8ff](https://github.com/markaspot/markaspot/commit/1fbe8ff8894240dff1de43940236f20379a49f8e))
* **ai:** three-tier model fallback for sentiment and node analysis ([374f52c](https://github.com/markaspot/markaspot/commit/374f52ceb99e13c7f70d625f9373395bf96a6898))
* broaden IBAN regex to match compact 22-char input without spaces ([d1392f5](https://github.com/markaspot/markaspot/commit/d1392f518c2276cb5d1099ed645cd8f27c658d9f))
* **mail:** add platform-mode legal-links fallback when Zone 2 is suppressed ([e0f4207](https://github.com/markaspot/markaspot/commit/e0f4207ffe595b8cffde321c7696b818762b742c))
* **mail:** keep OTP code out of mail subject + document plainText override ([943bd50](https://github.com/markaspot/markaspot/commit/943bd50402b0e150e436b54230b0941201e23f7b))
* **mail:** resolve request_id from base field + run token service on subject ([70249da](https://github.com/markaspot/markaspot/commit/70249dacae07cd3945a3970ed230404ba35f46ee))
* **mail:** sanitize subject header and extract test stubs ([83c70c1](https://github.com/markaspot/markaspot/commit/83c70c16f2d1eed72bfa4e420ea7cc3db2a9b605))
* **mail:** sanitize workspace slug before URL assembly in demo reminder ([5cb41fb](https://github.com/markaspot/markaspot/commit/5cb41fb98af9da03958cebf69396ffd905fca825))

### Refactoring

* **ai:** rename pii_redaction.use_llm to detect_names ([a2f1b58](https://github.com/markaspot/markaspot/commit/a2f1b58ffe684be1f40b06165e60be61d391d5ed)), closes [#states](https://github.com/markaspot/markaspot/issues/states)
* **mail:** extract SplitParagraphs + ResolveJurisdictionFromNode traits ([e4dc0e9](https://github.com/markaspot/markaspot/commit/e4dc0e9f35902e88c770756efba7d5314104cab4))
* **mail:** replace whitelist config with tagged-service builder dispatcher ([2bd98ac](https://github.com/markaspot/markaspot/commit/2bd98ac0f018e40dab0422d30e429d18f1400ddd))
* **mail:** split ECA_GROUP enum into invitation + org_notification cases ([463c7b7](https://github.com/markaspot/markaspot/commit/463c7b7a8caefa2fffe0090c741e33b031554b5b))

### Documentation

* **mail:** clarify resolveSubject contract on token flags + sanitization ([4a7267e](https://github.com/markaspot/markaspot/commit/4a7267e23d9f7aae4056db48a38181b080daa9f9))

## [11.9.48](https://github.com/markaspot/markaspot/compare/11.9.47...11.9.48) (2026-04-16)

### Bug Fixes

* default GDPR consent validation to opt-in ([e148812](https://github.com/markaspot/markaspot/commit/e1488123a3348808fe5de32e1c936f53c8a46a4e))

## [11.9.47](https://github.com/markaspot/markaspot/compare/11.9.46...11.9.47) (2026-04-16)

### Bug Fixes

* **nuxt:** add access_check tag for FeatureFlagAccessCheck service ([97eacb7](https://github.com/markaspot/markaspot/commit/97eacb7eb46a8aa81f3498be396bfa40d2c1f829))
* **nuxt:** implement ContainerInjectionInterface on FeatureFlagAccessCheck ([da66056](https://github.com/markaspot/markaspot/commit/da660569320eed5e3d293d3010f4c3413a0ff916))

## [11.9.46](https://github.com/markaspot/markaspot/compare/11.9.45...11.9.46) (2026-04-16)

### Bug Fixes

* **open311:** correct field_category_gid type from string to entity_reference ([0536086](https://github.com/markaspot/markaspot/commit/0536086918055dad2e7bd48e591997b19e724ff0))

## [11.9.45](https://github.com/markaspot/markaspot/compare/11.9.44...11.9.45) (2026-04-15)

### Features

* **geocoder:** ship sensible default district mappings ([3a567ba](https://github.com/markaspot/markaspot/commit/3a567ba9949cc417df44cdd6100478fb90c15922))
* **nuxt:** reusable FeatureFlagAccessCheck + gate stats routes ([c2636c5](https://github.com/markaspot/markaspot/commit/c2636c5f34acf8c3b130257c6258a983c6d2b2d9))

### Bug Fixes

* **ai:** skip PII redaction on node updates and during config sync ([5b54ce1](https://github.com/markaspot/markaspot/commit/5b54ce1def3c305de163c98699fc9640ec530213))
* **auth:** namespace OTP storage by jurisdiction to close cross-tenant reuse ([367bb4e](https://github.com/markaspot/markaspot/commit/367bb4e3e6be2969cd8c775677841084359643fa))
* **geocoder:** re-evaluate district mapping on location change ([e2a6ece](https://github.com/markaspot/markaspot/commit/e2a6ece8c821fc70fb7d8c53902492285d1873f5))
* **nuxt:** enable jsonapi_extras for district and sublocality taxonomies ([d034ef0](https://github.com/markaspot/markaspot/commit/d034ef0c1c4156cf67339f3a4d08e720374f71ff))
* **nuxt:** harden FeatureFlagAccessCheck against cache leak and GID bypass ([3d8f614](https://github.com/markaspot/markaspot/commit/3d8f6141598e1ae5ac88387584a2dcd3d4510adc))
* **service_request:** set field_permissions public on field instances ([3ec3ea8](https://github.com/markaspot/markaspot/commit/3ec3ea8997422c70ebd9122f27d80536ad58ae9f))
* **tenant-settings:** allow sublocality in dashboard column whitelist ([bacb09a](https://github.com/markaspot/markaspot/commit/bacb09ad95df455d30a85f62a87ac66875213080))
* **tests:** restore vision + open311 unit suites after service hardening ([9f3c806](https://github.com/markaspot/markaspot/commit/9f3c806aba4b47eef9c8955cf59b376c7d642c3c))

### Refactoring

* **nuxt:** remove orphan privacyBlur feature flag ([671c11b](https://github.com/markaspot/markaspot/commit/671c11b2aa0110e27421510e47175646b4fcabe8)), closes [#319](https://github.com/markaspot/markaspot/issues/319)

## [11.9.44](https://github.com/markaspot/markaspot/compare/11.9.43...11.9.44) (2026-04-15)

### Features

* **passwordless:** enforce passwordless feature flag on auth endpoints ([6469799](https://github.com/markaspot/markaspot/commit/6469799aef972c5f7fccbafdea5686861f692203))
* **privacy:** enforce privacyNotice feature flag on report creation ([253a78e](https://github.com/markaspot/markaspot/commit/253a78e086c823bfd2c22c37f50b3a09ef83292a))
* **vision:** enforce aiAnalysis feature flag in vision endpoint ([1229ec1](https://github.com/markaspot/markaspot/commit/1229ec1a460adf70f86f253993fe944883dbe56c))

### Bug Fixes

* **group:** auto-create missing group_roles field on cloud migration ([8adaa3a](https://github.com/markaspot/markaspot/commit/8adaa3a0bc08af06f16d1337003da4c8090803ec))
* **open311:** scope tenant isolation to dashboard-level users ([33bb7b1](https://github.com/markaspot/markaspot/commit/33bb7b19c0f7c90f9bc5718f5567d9061acb4888))
* **privacy:** follow-up fixes for [#320](https://github.com/markaspot/markaspot/issues/320) landing ([4824c72](https://github.com/markaspot/markaspot/commit/4824c72cd431834f32b1007db807722d4558f6e6))
* **request_id:** post-update backfill for jurisdiction_id=0 legacy rows ([341cdc6](https://github.com/markaspot/markaspot/commit/341cdc63d035414f9d3c6c54c04ade64f925d66c))
* **vision:** declare markaspot_nuxt dependency + i18n error string ([40d4668](https://github.com/markaspot/markaspot/commit/40d46688c206010618220712be723ac9fbeac3a1))

### Refactoring

* **open311:** remove redundant isAnonymous guard in controller ([024e86c](https://github.com/markaspot/markaspot/commit/024e86c3ac79bead4ddf94e3f24e55fcde6eaa9f))

## [11.9.43](https://github.com/markaspot/markaspot/compare/11.9.42...11.9.43) (2026-04-14)

### Features

* **passwordless:** add PATCH /api/auth/preferences for user langcode ([7ad8ddb](https://github.com/markaspot/markaspot/commit/7ad8ddb7ac43c83cda5993f8c144093b6d3fdf6a))

### Refactoring

* **ai,vision:** unify bearer ENV lookups on MARKASPOT_* schema ([ecf572c](https://github.com/markaspot/markaspot/commit/ecf572c76f47b6ac8bcc44bd226ac27f48096b36))
* **vision:** unify blur service URL on MARKASPOT_BLUR_URL ([088db4f](https://github.com/markaspot/markaspot/commit/088db4fc72083d8117bf70e2db9b32e5cd6b0fd0))

## [11.9.42](https://github.com/markaspot/markaspot/compare/11.9.41...11.9.42) (2026-04-13)

### Features

* **ai:** expose self-hosted NLP container as configurable PII provider ([8cbb22e](https://github.com/markaspot/markaspot/commit/8cbb22eb7f4c00f104eba34544d844d967340165))

## [11.9.41](https://github.com/markaspot/markaspot/compare/11.9.40...11.9.41) (2026-04-11)

### Features

* **fastmap:** add workspace URL and login link to demo expiry email ([7280634](https://github.com/markaspot/markaspot/commit/7280634fa3c4c39a4713b16dee1fac2094113900))
* **vision:** include service definition attributes in AI analysis ([85f95b4](https://github.com/markaspot/markaspot/commit/85f95b4f45a28896f7808ed8d172b228c3caa6c7))

### Bug Fixes

* preserve meta response structure in GeoReport API ([dc335d6](https://github.com/markaspot/markaspot/commit/dc335d6542c8708434022edb71a2421a49bf90cf))
* **vision:** improve prompt sanitization and per-category truncation ([231c048](https://github.com/markaspot/markaspot/commit/231c048d8d0297e1cfb876bfd3216623265a4176))

## [11.9.40](https://github.com/markaspot/markaspot/compare/11.9.39...11.9.40) (2026-04-11)

### Bug Fixes

* always include English in workspace available languages ([be12530](https://github.com/markaspot/markaspot/commit/be12530b23553d3f7def4fbf1fe817516900feca))
* **group:** update hooks 11917/11918 fix bundle_field_map after organisation -> org rename ([25f05cc](https://github.com/markaspot/markaspot/commit/25f05cc7951f3dba4eeb5d913138d02477dabc74))
* **security:** enforce jurisdiction membership on all GeoReport GET endpoints ([3f799fb](https://github.com/markaspot/markaspot/commit/3f799fb5fc43f31f12e31f636e9d0f11794cba0e))
* use requested language for workspace status terms regardless of category keys ([dd9cdd6](https://github.com/markaspot/markaspot/commit/dd9cdd6806eb0a678f5f37428a621a4900a3caff))

### Refactoring

* **fastmap:** extract getDefaultCategories helper for multilingual fallback ([dd33f19](https://github.com/markaspot/markaspot/commit/dd33f19fec41563691517962ead4e93eb30acb4b))

## [11.9.39](https://github.com/markaspot/markaspot/compare/11.9.38...11.9.39) (2026-04-08)

### Bug Fixes

* **boilerplate:** remove invalid field_boilerplate_type permissions ([3802ca1](https://github.com/markaspot/markaspot/commit/3802ca1d8649b6b5facdda30e6bef7193e95e0ca))

## [11.9.38](https://github.com/markaspot/markaspot/compare/11.9.37...11.9.38) (2026-04-08)

### Features

* **boilerplate:** add field_boilerplate_type for field-scoped text templates ([e2c2c45](https://github.com/markaspot/markaspot/commit/e2c2c4572b330b011da7d6fe114bc821a32274ee))

### Bug Fixes

* **geocoder:** correct default district mapping fallback chains ([5463ac5](https://github.com/markaspot/markaspot/commit/5463ac5c406a00cd596a05b1e6f8f6b0add7d54e))
* remove duplicate field.storage.node.body from service_request config ([bd1b45e](https://github.com/markaspot/markaspot/commit/bd1b45e8a569619d830ba95292f3a9b50fd647e1))

## [11.9.37](https://github.com/markaspot/markaspot/compare/11.9.36...11.9.37) (2026-04-07)

### Features

* **ai:** add IONOS AI provider (Berlin, DE) ([2c0d7b7](https://github.com/markaspot/markaspot/commit/2c0d7b7c7c6e4e3cc822092ae23d66507c009d4f))
* **ai:** add NlpClientService for local Ollama fallback ([f0e92ab](https://github.com/markaspot/markaspot/commit/f0e92aba007e4916d4148080a166b6d0d581878f))
* **ai:** add PII redaction in hook_node_presave ([76dd184](https://github.com/markaspot/markaspot/commit/76dd184bd2260b713675c9f1b514cb00214c2593))

### Bug Fixes

* **ai:** add per-feature jurisdiction gate and NLP fallback ([0b21577](https://github.com/markaspot/markaspot/commit/0b21577e795a0866528afce64dbf2a18e7a1520c))
* **ai:** use configured provider for embedding queue ([7bffa30](https://github.com/markaspot/markaspot/commit/7bffa30882a749e8515a0429dd411441b71c96a1))

## [11.9.36](https://github.com/markaspot/markaspot/compare/11.9.35...11.9.36) (2026-04-06)

### Features

* **ai:** add Azure OpenAI provider support ([27e0667](https://github.com/markaspot/markaspot/commit/27e0667adf36d11e7f9e6c7a1fd27ac5387ad3ca))

### Bug Fixes

* **ai:** security hardening for Azure provider ([a0f810e](https://github.com/markaspot/markaspot/commit/a0f810e8b6866e36d767e6cd1de38ac7b2cd93b4))

## [11.9.35](https://github.com/markaspot/markaspot/compare/11.9.34...11.9.35) (2026-04-06)

### Features

* **geocoder:** add configurable district mapping from geocoder results ([dfb6b38](https://github.com/markaspot/markaspot/commit/dfb6b38bb0c1728ec17fb25b0567d44d8cb9c027)), closes [markaspot/markaspot-ui#277](https://github.com/markaspot/markaspot-ui/issues/277)
* **nuxt:** add district and sublocality options to settings endpoint ([1c12f4f](https://github.com/markaspot/markaspot/commit/1c12f4fa5766bd3136ea90d1b43c0c75c3e9ad5f)), closes [markaspot/markaspot-ui#277](https://github.com/markaspot/markaspot-ui/issues/277)
* **open311:** expose district and sublocality in API response ([090659c](https://github.com/markaspot/markaspot/commit/090659c16b8efcedb83d285ba37c05c6b6b48e7d)), closes [markaspot/markaspot-ui#277](https://github.com/markaspot/markaspot-ui/issues/277)
* **service_request:** add field_sublocality vocabulary and field ([2c900e6](https://github.com/markaspot/markaspot/commit/2c900e68ccbb986534f7f29c48229654f4ec6157)), closes [markaspot/markaspot-ui#277](https://github.com/markaspot/markaspot-ui/issues/277)
* **vision:** add Bearer auth support for blur service ([db83c97](https://github.com/markaspot/markaspot/commit/db83c9716d847e9134bd3b24659b10a63bd3de79))

## [11.9.34](https://github.com/markaspot/markaspot/compare/11.9.33...11.9.34) (2026-04-05)

### Features

* add dashboard column defaults to tenant settings ([#282](https://github.com/markaspot/markaspot/issues/282)) ([39626c8](https://github.com/markaspot/markaspot/commit/39626c866434f15995dd8cabcb17a823a1f365d3))
* add update hook 11911 to uninstall legacy modules ([3d6d573](https://github.com/markaspot/markaspot/commit/3d6d573d3df7df0811c479d60ab7f0f04c1dedc8))

### Bug Fixes

* accept zero-value coordinates in settings controller ([a0eca31](https://github.com/markaspot/markaspot/commit/a0eca3179201c3454478042a873aa80b9f3f2e64))
* coordinate truthy check in markaspot.install ([8f4903d](https://github.com/markaspot/markaspot/commit/8f4903d3c4448b9d37ac2107f1fe5bfc32ea5682))
* skip catalog_image field setup when media type missing ([af10384](https://github.com/markaspot/markaspot/commit/af10384a41a5ecd5a32ed01b6ade1b4695186945))
* **test:** add missing isDefaultTranslation mock to TenantSettings tests ([4bfdb59](https://github.com/markaspot/markaspot/commit/4bfdb5943686de540a06b862424904ab7573010d))
* **test:** add VISION_BLUR_URL to vision test ENV isolation ([03709bf](https://github.com/markaspot/markaspot/commit/03709bfa5660f89c62abae0d7187355a86f0d6bf))
* **test:** isolate vision tests from host ENV variables ([6d50c46](https://github.com/markaspot/markaspot/commit/6d50c46f493c34266d530ebc71c4f47da3e5789b))

### Refactoring

* consolidate organization to organisation naming in Dashboard API ([647e98e](https://github.com/markaspot/markaspot/commit/647e98e294ffe206973b207880084259cbd5870a))

## [11.9.33](https://github.com/markaspot/markaspot/compare/11.9.32...11.9.33) (2026-04-05)

### Features

* add media_group filter for imagelist service definition attributes ([0556051](https://github.com/markaspot/markaspot/commit/05560519b342d63441262cc14affcee04509760a)), closes [markaspot/markaspot-ui#278](https://github.com/markaspot/markaspot-ui/issues/278)

### Bug Fixes

* **security:** server-side role filtering on /api/organisations ([#279](https://github.com/markaspot/markaspot/issues/279)) ([21f8f2c](https://github.com/markaspot/markaspot/commit/21f8f2c236df7daf175fa537dd7a2a7301686f2d))

## [11.9.32](https://github.com/markaspot/markaspot/compare/11.9.31...11.9.32) (2026-04-04)

### Features

* **vision:** support API URL and auth type via ENV variables ([d02385b](https://github.com/markaspot/markaspot/commit/d02385b2bea317246f5715f4c219046a2188b805))

## [11.9.31](https://github.com/markaspot/markaspot/compare/11.9.30...11.9.31) (2026-04-04)

### Features

* **open311:** support multi-organisation assignment (fixes markaspot/markaspot-ui[#273](https://github.com/markaspot/markaspot/issues/273)) ([1fee419](https://github.com/markaspot/markaspot/commit/1fee4192a5e2e1336b6584afc601262802afa028))

### Bug Fixes

* **group:** cross-tenant guard, dedup IDs, multi-org delegation fixes (markaspot/markaspot-ui[#273](https://github.com/markaspot/markaspot/issues/273)) ([2065e13](https://github.com/markaspot/markaspot/commit/2065e13a66e2a9c093c161840f6a84f4a22644ce))
* **install:** harden update_11910 for schema variants, reset static in tests ([7472481](https://github.com/markaspot/markaspot/commit/7472481b9565c9820fb2da32a1cd542bd884f297))

## [11.9.30](https://github.com/markaspot/markaspot/compare/11.9.29...11.9.30) (2026-04-03)

### Bug Fixes

* harden group type migration hook and SQL parameterization ([#271](https://github.com/markaspot/markaspot/issues/271)) ([18be85e](https://github.com/markaspot/markaspot/commit/18be85e909f1bf921f85de01f3e5349ba2569e35))

## [11.9.29](https://github.com/markaspot/markaspot/compare/11.9.28...11.9.29) (2026-04-03)

### Bug Fixes

* grant tenant_admin status field access scoped to jurisdiction (fixes [#261](https://github.com/markaspot/markaspot/issues/261)) ([e130cd9](https://github.com/markaspot/markaspot/commit/e130cd980d4cec817c3592d788ddec517127da1c))
* remove global boilerplate/service_request permissions, add Group-scoped boilerplate access ([a54e954](https://github.com/markaspot/markaspot/commit/a54e9541f7e33fda0efc28897e1d287cbf88a677))
* remove global edit permissions from tenant_admin role (fixes [#268](https://github.com/markaspot/markaspot/issues/268)) ([dab60d1](https://github.com/markaspot/markaspot/commit/dab60d1a5045b5b41f949481303d7f3912b7cd87))

## [11.9.28](https://github.com/markaspot/markaspot/compare/11.9.27...11.9.28) (2026-04-03)

### Features

* add Drupal Gin admin UI for API-only modules ([314c3f9](https://github.com/markaspot/markaspot/commit/314c3f95b0a2f4dee92e4f1f90772a743d3ecc41))
* add local task tabs for admin content and reports ([b96b79e](https://github.com/markaspot/markaspot/commit/b96b79eaf7b5e03d32285c316a1e954e95196420))
* **vision:** improve CAP hazard detection prompt ([7235544](https://github.com/markaspot/markaspot/commit/7235544a908028d27f7f27ee0f463baee034c646))

### Bug Fixes

* enable toolbar and gin_toolbar on fresh install ([65c4a13](https://github.com/markaspot/markaspot/commit/65c4a13f69b066aa6f3460722f4100c5261a48f3))
* ensure gin theme is installed on every fresh install ([2bd2cf7](https://github.com/markaspot/markaspot/commit/2bd2cf79ff69fa28fd1523ce5ff0e2c0afca2ae8))
* replace hardcoded group entity IDs with dynamic queries in start.sh (fixes [#266](https://github.com/markaspot/markaspot/issues/266)) ([02378ff](https://github.com/markaspot/markaspot/commit/02378ff8ae89ec656c49d188896834a75234f837))
* replace removed user_role_names() with Role entity API ([7f62280](https://github.com/markaspot/markaspot/commit/7f622802fb36ad2b2c46a7664a62b9d693237f6d))
* use color property for color_field_type in extended_attributes ([5d9b091](https://github.com/markaspot/markaspot/commit/5d9b0919f76f426e5af12a75d0d99f03d0397648))

## [11.9.27](https://github.com/markaspot/markaspot/compare/11.9.26...11.9.27) (2026-03-31)

### Bug Fixes

* add missing hex_opacity process mappings to category and status migrations ([fd35590](https://github.com/markaspot/markaspot/commit/fd35590b1528e8920c692f65163011deeae62a32))
* allow tenant admins to promote/sticky pages without administer nodes ([125ab2f](https://github.com/markaspot/markaspot/commit/125ab2fb7c5bba271ed3263f9aae1ebb8ecc6116))
* close hook_install vs hook_update_N gaps on fresh installs (fixes [#247](https://github.com/markaspot/markaspot/issues/247)) ([4620698](https://github.com/markaspot/markaspot/commit/46206983bf1a8a968dbf0986242173c7b108aaf3))
* grant page permissions to jur-tenant_admin group role ([255b97d](https://github.com/markaspot/markaspot/commit/255b97dfe05d835b05398a36b6a8c22bbe58f2ba))
* guard update_11915 backfill against multi-tenant environments ([ee2ace4](https://github.com/markaspot/markaspot/commit/ee2ace49a3b212cc4d5dd1f3578c4b913e91568c))
* null-safe email parameter in SP completion metadata (PHP 8.3) ([0845702](https://github.com/markaspot/markaspot/commit/0845702d7073cd7d468ddac3dcda9bc55b3a7e90))
* repair field permissions stripped during fresh install ([4dc7380](https://github.com/markaspot/markaspot/commit/4dc7380dd023a005eed6a3057ec8f8aa126f9d96))
* set field_jurisdiction in all default content migrations (fixes [#246](https://github.com/markaspot/markaspot/issues/246) task 2) ([a6cb44f](https://github.com/markaspot/markaspot/commit/a6cb44f243030f8e35f9e5fb487e2daef73abdbe))
* sync group relationships when field_jurisdiction changes ([af5f7ad](https://github.com/markaspot/markaspot/commit/af5f7ad65ac6df7a752158ab7d0488af960cf34b))
* use Core TimeInterface in FastMap TosController (fixes [#238](https://github.com/markaspot/markaspot/issues/238)) ([e5acbf5](https://github.com/markaspot/markaspot/commit/e5acbf5de930cb413b9a7aabc4dedc2fae353ea0))
* use EntityOwnerInterface import instead of inline FQCN ([03e06c7](https://github.com/markaspot/markaspot/commit/03e06c7d3f348be49e2ecc48a9833d1b8148aae0))
* **vision:** add GDPR audit log and URI validation for blur overwrite (fixes [#251](https://github.com/markaspot/markaspot/issues/251)) ([2c16fee](https://github.com/markaspot/markaspot/commit/2c16fee61b8495fc153e4877fd401e986261202d))

### Refactoring

* **vision:** remove dead FileRepositoryInterface dependency ([319c119](https://github.com/markaspot/markaspot/commit/319c1194eeba7638893ce104a9b05e9a4d9ff61b))

## [11.9.26](https://github.com/markaspot/markaspot/compare/11.9.25...11.9.26) (2026-03-29)

### Bug Fixes

* mock FileRepositoryInterface in ImageProcessingServiceTest ([79bd65f](https://github.com/markaspot/markaspot/commit/79bd65f9bc2dc81640075c807be9a35efcb6f3d0))

### Refactoring

* **vision:** replace original image with blurred version instead of separate field ([51a20c5](https://github.com/markaspot/markaspot/commit/51a20c5bfe6505fe6fc7ad98d7156eecbe4dfae3))

## [11.9.25](https://github.com/markaspot/markaspot/compare/11.9.24...11.9.25) (2026-03-28)

### Features

* **vision:** add optional blur preprocessing for face and license plate privacy ([9cf8089](https://github.com/markaspot/markaspot/commit/9cf8089b6a1ec9ee6dfb565f68778ff2586a6559)), closes [markaspot/markaspot-ui#242](https://github.com/markaspot/markaspot-ui/issues/242)

### Bug Fixes

* backfill field_jurisdiction on orphaned entities (update_11915) ([6fe78bf](https://github.com/markaspot/markaspot/commit/6fe78bf0ec7db9a1efea6526183a8836780db0f7))
* **demo:** pass module_handler to DemoOtpService parent constructor ([683234c](https://github.com/markaspot/markaspot/commit/683234c92940056e3a964750c9553874fe620334))

## [11.9.24](https://github.com/markaspot/markaspot/compare/11.9.23...11.9.24) (2026-03-27)

### Bug Fixes

* PHPCS violations, OTP moduleHandler DI, and add update_11904 for email_verified index ([c2df624](https://github.com/markaspot/markaspot/commit/c2df624b79b9cd0e81c21099820782c0e2759394))

## [11.9.23](https://github.com/markaspot/markaspot/compare/11.9.22...11.9.23) (2026-03-27)

### Bug Fixes

* revert TimeInterface to Component namespace (TypeError on property assignment) ([aebe9f6](https://github.com/markaspot/markaspot/commit/aebe9f61b44219b7a90d221ae80dd150d7843913))

## [11.9.22](https://github.com/markaspot/markaspot/compare/11.9.21...11.9.22) (2026-03-27)

### Bug Fixes

* add missing email_verified index in update_11903 ([a8b8f17](https://github.com/markaspot/markaspot/commit/a8b8f17317626d50da4b24ca3077d2cfab2606e3))
* atomic OTP attempt counter and timing side channel protection ([855fffa](https://github.com/markaspot/markaspot/commit/855fffa17239b836656bd7f430d53abbf2b5617e))
* OTP timing side-channel and double-claim prevention ([aabcd68](https://github.com/markaspot/markaspot/commit/aabcd6811b0c0db3da59885b1e14e00c88589489))
* remove stale markaspot_tenant_admin dependency from moderation module ([314e987](https://github.com/markaspot/markaspot/commit/314e987abf3ffea66b4d2ce282bb48c720e94485))
* use Drupal host as sole fallback for invitation email URLs ([c6b2d8e](https://github.com/markaspot/markaspot/commit/c6b2d8e1687a67e7ff27c835fb7e575e761755df))

### Refactoring

* move TosController from markaspot_nuxt to markaspot_fastmap ([1ae6f8a](https://github.com/markaspot/markaspot/commit/1ae6f8a026573a64104f18e49486f76d22fef801))

## [11.9.21](https://github.com/markaspot/markaspot/compare/11.9.20...11.9.21) (2026-03-27)

### Bug Fixes

* add hook_install to grant workspace usage permissions ([774f952](https://github.com/markaspot/markaspot/commit/774f952695a6dea6ac21937fbc467e0d1fcb8eff)), closes [markaspot/markaspot-ui#239](https://github.com/markaspot/markaspot-ui/issues/239)
* grant moderation permissions in hook_install ([5855b5c](https://github.com/markaspot/markaspot/commit/5855b5cf302b7971a433b5eea1d0e6896cfafae5)), closes [markaspot/markaspot-ui#236](https://github.com/markaspot/markaspot-ui/issues/236)
* hash OTP codes with bcrypt instead of plaintext storage ([d633295](https://github.com/markaspot/markaspot/commit/d633295d13a3bb60ec21ccd680e5ba0993a02055))
* include tenant_admin in demo OTP auto-detection ([b391f88](https://github.com/markaspot/markaspot/commit/b391f883f7d4a1d77b3e0fb1595d680060153744))
* remove incompatible _csrf_token from headless group-members routes ([1a198bd](https://github.com/markaspot/markaspot/commit/1a198bd9ff5d3f26636f267629fcbe45daef27d3)), closes [markaspot/markaspot-ui#237](https://github.com/markaspot/markaspot-ui/issues/237)
* use Component\Datetime\TimeInterface in TosController ([7d6ac5a](https://github.com/markaspot/markaspot/commit/7d6ac5a30bc91cb6189bbcdbe9fb25101d4bd823)), closes [markaspot/markaspot-ui#238](https://github.com/markaspot/markaspot-ui/issues/238)
* use Origin header for invitation email URLs ([e3442bf](https://github.com/markaspot/markaspot/commit/e3442bfe74576c4a19bb6ddf1646a7a78ad133aa))
* use TenantAdminHelper from markaspot_group in ModerationController ([2810bcb](https://github.com/markaspot/markaspot/commit/2810bcb81c6d1a78d3105c0e88e6192e46535d37))

## [11.9.20](https://github.com/markaspot/markaspot/compare/11.9.19...11.9.20) (2026-03-26)

### Features

* **#108:** add ToS acceptance endpoints and field_tos_accepted_at on user entity ([e0c4eb1](https://github.com/markaspot/markaspot/commit/e0c4eb15f3479845d66027d6f298191092831322)), closes [#108](https://github.com/markaspot/markaspot/issues/108)

## [11.9.19](https://github.com/markaspot/markaspot/compare/11.9.18...11.9.19) (2026-03-25)

### Features

* **nuxt:** add content translation REST endpoint ([15a2e36](https://github.com/markaspot/markaspot/commit/15a2e365eecb3123218c0e4828815995cdf83ae2))

### Bug Fixes

* add missing GroupInterface use import in TenantSettingsController ([a94c56d](https://github.com/markaspot/markaspot/commit/a94c56d3ab17a6743fc7410de559c9c2d17ca768))
* **open311:** omit empty fields from getAllFieldValues() API response ([7a587d6](https://github.com/markaspot/markaspot/commit/7a587d6a33dccd0d41635ea1825078188b800afa))
* resolve PHP 8.4 dynamic property deprecations in unit tests ([34dd6fb](https://github.com/markaspot/markaspot/commit/34dd6fb078a672fdbf6109e61dfb1fa384716c3b))
* skip empty entity references and cast integers in field serialization ([f58ebc2](https://github.com/markaspot/markaspot/commit/f58ebc276ef8312ffa111584ecdbef1d78191975))

## [11.9.18](https://github.com/markaspot/markaspot/compare/11.9.17...11.9.18) (2026-03-25)

### Features

* add jurisdiction_id and count params to georeport-client.sh ([8506ceb](https://github.com/markaspot/markaspot/commit/8506ceb04379adb71a1b905199c95804883511c5))
* add markaspot_moderation module for content flag management ([#217](https://github.com/markaspot/markaspot/issues/217)) ([4750c2d](https://github.com/markaspot/markaspot/commit/4750c2d9d7d9b364c91646097cfdc96da6856805))
* add markaspot_moderation to profile dependencies ([aa55f82](https://github.com/markaspot/markaspot/commit/aa55f82c5c2b38181a2bd58a4a84fddfdbae47ff))
* add tenant-settings endpoints for features, map, navigation ([#180](https://github.com/markaspot/markaspot/issues/180)) ([58fe9a2](https://github.com/markaspot/markaspot/commit/58fe9a2bff461780eeebe4fd65afd00c7dcd7c78))

### Bug Fixes

* **ai:** queue sentiment-missing nodes and expose sentiment.missing in status ([f1edff9](https://github.com/markaspot/markaspot/commit/f1edff9e1db4d6d2ea337f571cc5ff6cb0ef1337))
* normalize formFirst and deferredMap to boolean in settings GET ([22575d4](https://github.com/markaspot/markaspot/commit/22575d4959c9196365d23d47e76d136d29619835))
* require jurisdiction_id on multi-tenant and add default fallback ([243f3dd](https://github.com/markaspot/markaspot/commit/243f3dd86e29bf70c3181744d5e69baed6cac2d9))

## [11.9.17](https://github.com/markaspot/markaspot/compare/11.9.16...11.9.17) (2026-03-24)

### Features

* **#220:** add mail template translations for passwordless OTP emails ([79b4acf](https://github.com/markaspot/markaspot/commit/79b4acfcd229c7a10314e6664ea367eede1f72d0)), closes [#220](https://github.com/markaspot/markaspot/issues/220)
* **#223:** auto-enable markaspot_fastmap when NUXT_FASTMAP=true ([45ed86e](https://github.com/markaspot/markaspot/commit/45ed86e3e5e45372bc99598f8dcdf04aa2349e36)), closes [#223](https://github.com/markaspot/markaspot/issues/223)
* enable markaspot_boilerplate module by default ([c210371](https://github.com/markaspot/markaspot/commit/c210371e2b7bb4ddfa0a44afbd25fc1a00fac502))

### Bug Fixes

* **#223:** add aiAnalysis + classicReporting to default feature flags ([bb1909c](https://github.com/markaspot/markaspot/commit/bb1909c15199e8171d8f728039f7511fb1d8a44f)), closes [#223](https://github.com/markaspot/markaspot/issues/223)
* **#223:** add toolbar module + tenant_admin/moderator roles for uid 1 ([3826b70](https://github.com/markaspot/markaspot/commit/3826b705f8e666ddb84cbbace5b2a73b3af77fa2)), closes [#223](https://github.com/markaspot/markaspot/issues/223)
* **#223:** configure AI vision from OPENAI_API_KEY ENV on fresh install ([e29167c](https://github.com/markaspot/markaspot/commit/e29167c5586b576131c3eb939c567a7b89485a71)), closes [#223](https://github.com/markaspot/markaspot/issues/223)
* **#223:** enable dashboard + passwordless features by default ([bb63392](https://github.com/markaspot/markaspot/commit/bb6339261cbe8fc524920b32b57c15dfc5024a4d)), closes [#223](https://github.com/markaspot/markaspot/issues/223)
* **#223:** enable fastmap with service_key from ENV on fresh install ([b7a79dc](https://github.com/markaspot/markaspot/commit/b7a79dc1f88b8b2bb76b81c03698feee2f95ddc2)), closes [#223](https://github.com/markaspot/markaspot/issues/223)
* **#223:** enable markaspot_ai module when OPENAI_API_KEY is set ([eece7c9](https://github.com/markaspot/markaspot/commit/eece7c95ee6ce6fbef2180a80e874aee3085103f)), closes [#223](https://github.com/markaspot/markaspot/issues/223)
* **#223:** fresh install must be fully functional out of the box ([d797f79](https://github.com/markaspot/markaspot/commit/d797f79bfec50ae9366b4ff63def6cce5975dd50)), closes [#223](https://github.com/markaspot/markaspot/issues/223)
* **#223:** remove REST resource deps from role configs + media cardinality ([b0e4576](https://github.com/markaspot/markaspot/commit/b0e45766aa2877ce805fdb542c78d3d1f757107a)), closes [#223](https://github.com/markaspot/markaspot/issues/223)
* **#223:** set verify_base_url + fix demo banner for non-SaaS installs ([5a1057a](https://github.com/markaspot/markaspot/commit/5a1057ad3d99eeef1bd5cdac5a6ba4f04916cae2))
* **#224:** add group_contact to management form display ([fb11602](https://github.com/markaspot/markaspot/commit/fb116023a9b7545c9f4c64e6ab36d4de5f67f8a5)), closes [#224](https://github.com/markaspot/markaspot/issues/224)
* add fastmap mail translation update hook and fix Italian typo ([00ec2f9](https://github.com/markaspot/markaspot/commit/00ec2f9f817f9ad11de5cf6c58811453a1923f1b))
* address review findings in start.sh ([797bfbc](https://github.com/markaspot/markaspot/commit/797bfbcafdd9757f8e28068d3e4d1f8949d49666))
* correct Next Steps output (port 3001, skip ddev hint in prod) ([734aaa8](https://github.com/markaspot/markaspot/commit/734aaa89383c5f2fbf6a28c5c6bbe0f91a5ea093))
* detect actual MIME type for AI vision base64 encoding ([bb12afc](https://github.com/markaspot/markaspot/commit/bb12afcad8b426493e9879a416a5c8ff4a1b9a49))
* disable classicReporting by default for FastMap workspaces ([6de6a3f](https://github.com/markaspot/markaspot/commit/6de6a3f56bb6cac1b785f1601eba810ddffdcd0c))
* **open311:** serialize entity references in getFieldValues() for JSON output ([174b00f](https://github.com/markaspot/markaspot/commit/174b00fb1aeae30daa242f2ff8d01726d59ac0ce))
* remove AVIF conversion from wide image style for AI vision ([2c28d73](https://github.com/markaspot/markaspot/commit/2c28d73bcae1a2b1db6030b3dd840aa68940d440))

### Refactoring

* dissolve markaspot_tenant_admin into markaspot_group ([a05087f](https://github.com/markaspot/markaspot/commit/a05087fece7cf7e4b4181df446a40128819c4174))

## [11.9.16](https://github.com/markaspot/markaspot/compare/11.9.15...11.9.16) (2026-03-23)

### Features

* **#216:** jurisdiction-specific boilerplates via Group Content ([404d468](https://github.com/markaspot/markaspot/commit/404d46883963f41d8e118ded0f07a3d3a911075f)), closes [#216](https://github.com/markaspot/markaspot/issues/216)

### Bug Fixes

* **#214:** add field_request_media to form display on fresh install ([45b59ec](https://github.com/markaspot/markaspot/commit/45b59ec77c90dc30ff1278ed6f3d0e9d18d461f3)), closes [#214](https://github.com/markaspot/markaspot/issues/214)
* **#214:** remove deprecated field_request_image from management form display ([4468748](https://github.com/markaspot/markaspot/commit/44687484629955c07f28c5d3edfea8ebd9c4d19c)), closes [#214](https://github.com/markaspot/markaspot/issues/214)
* **security:** close cross-tenant data leak for tenant_admin role ([f1e1405](https://github.com/markaspot/markaspot/commit/f1e1405d3bda2e8bc159a0207ff88830097538bd))

## [11.9.15](https://github.com/markaspot/markaspot/compare/11.9.14...11.9.15) (2026-03-23)

### Features

* **#172:** add ECA confirmation email with jurisdiction footer ([af19783](https://github.com/markaspot/markaspot/commit/af197831431f4e54cc538e222db0cecf496c524a)), closes [#172](https://github.com/markaspot/markaspot/issues/172)
* billing address fields on jurisdiction, synced from Stripe ([6cc6444](https://github.com/markaspot/markaspot/commit/6cc64449998b5c08640868ea916ad002d3059b0e))

### Bug Fixes

* **#172:** add missing dependencies and use custom status note token ([b366bc4](https://github.com/markaspot/markaspot/commit/b366bc4b649d46f3a492d248b02666bddec0cc20)), closes [#172](https://github.com/markaspot/markaspot/issues/172)
* **#194:** pass ai_system_prompt from controller to workspace data ([c8a78fd](https://github.com/markaspot/markaspot/commit/c8a78fd26499b045097ff9ec48886013d3f2ffcf)), closes [#194](https://github.com/markaspot/markaspot/issues/194)
* **#207:** allow tenant admin to invite members to org groups ([9a97909](https://github.com/markaspot/markaspot/commit/9a979097087624f3225f14caca433fbb08d1a507)), closes [#207](https://github.com/markaspot/markaspot/issues/207)
* add sv, nb, fi to dashboard language settings ([#183](https://github.com/markaspot/markaspot/issues/183)) ([748d20d](https://github.com/markaspot/markaspot/commit/748d20db2f7e8cf0833098b62661bbc18bc87a7f))
* defensive error handling and backfill for missing start pages ([#197](https://github.com/markaspot/markaspot/issues/197)) ([e0259f9](https://github.com/markaspot/markaspot/commit/e0259f9df1d686af73fe1a9486ccce75fc7e7f48))
* restrict workspace usage to tenant_admin group role ([ee20b86](https://github.com/markaspot/markaspot/commit/ee20b86773e057777ba17359dd05609e29f59657))
* **test:** add functional test setup signatures for PHP 8.4 ([922bfa4](https://github.com/markaspot/markaspot/commit/922bfa426fab10712361a90d1d5e5be2b91c046a))
* **test:** mock wait() in AiClientServiceTest to avoid real sleep ([372f43e](https://github.com/markaspot/markaspot/commit/372f43e6fd63b07e5a87ae0fd82ef656338130e7))
* use requested language in normalizeCategories fallback ([d6ed2fb](https://github.com/markaspot/markaspot/commit/d6ed2fbbb8f6f045f28e51a2da9f608af01165f4))

### Refactoring

* **#196:** move jurisdiction address/email fields to markaspot_group ([47daf62](https://github.com/markaspot/markaspot/commit/47daf62864534b24b5acb0503cb91494b7d6265a)), closes [#196](https://github.com/markaspot/markaspot/issues/196)
* **ai:** extract wait() method from retry loop for testability ([6245c90](https://github.com/markaspot/markaspot/commit/6245c90191876f89c7a2e7313f832a0e19e8b055))

## [11.9.14](https://github.com/markaspot/markaspot/compare/11.9.13...11.9.14) (2026-03-22)

### Features

* add AI analysis budget per tier ([#181](https://github.com/markaspot/markaspot/issues/181)) ([292f93c](https://github.com/markaspot/markaspot/commit/292f93c40cd7029a8f21775c579fd1b631ad2212))
* Stripe-gated tier onboarding with demo workspace lifecycle ([ab65c42](https://github.com/markaspot/markaspot/commit/ab65c426db8e36717102a6d7ba2e3c868f98afde)), closes [markaspot/markaspot-ui#137](https://github.com/markaspot/markaspot-ui/issues/137) [markaspot/markaspot-ui#185](https://github.com/markaspot/markaspot-ui/issues/185) [markaspot/markaspot-ui#186](https://github.com/markaspot/markaspot-ui/issues/186)

### Bug Fixes

* add DELETE route for logo removal via tenant-settings API ([2472fbc](https://github.com/markaspot/markaspot/commit/2472fbc48c7358a7df7b587f675ab063ede7cdd2))
* add sv, fi, nb to ALLOWED_LANGS in FastMap onboarding pipeline ([cf5c8c7](https://github.com/markaspot/markaspot/commit/cf5c8c7730d78c096535f5cf81fa6a186dd7708e))

## [11.9.13](https://github.com/markaspot/markaspot/compare/11.9.12...11.9.13) (2026-03-21)

### Features

* add branding settings API endpoint for theme colors and custom CSS ([2d8dc50](https://github.com/markaspot/markaspot/commit/2d8dc50748b2ce2ee1a722911dc9bf9ee0dc8679))
* add field_legal_notice and field_privacy_policy to jurisdiction group type ([6e600a7](https://github.com/markaspot/markaspot/commit/6e600a7f6a40e95f4175eb599f17a00828ced632))
* add language settings API + slug-based tenant routes ([2e61f7b](https://github.com/markaspot/markaspot/commit/2e61f7b8232f01f1bee4f3a4a15070ca7f17e895))
* add multilingual support for custom statuses and welcome pages ([93da6d3](https://github.com/markaspot/markaspot/commit/93da6d3f50b08f0914474b236901382ebad38a6e))
* auto-create initial status note with configurable boilerplate ([#172](https://github.com/markaspot/markaspot/issues/172)) ([92683ac](https://github.com/markaspot/markaspot/commit/92683acee5de6ac87c6c8252bcd813673938621b))
* create promoted start page during workspace provisioning ([aad4365](https://github.com/markaspot/markaspot/commit/aad4365185e91efa0875eb711cd12eafb5e22514)), closes [markaspot/markaspot-ui#158](https://github.com/markaspot/markaspot-ui/issues/158)
* demo workspace auto-delete with expiry date and cron cleanup ([c081ee5](https://github.com/markaspot/markaspot/commit/c081ee5f75280468cedc054b26f45af87230d963)), closes [markaspot/markaspot-ui#109](https://github.com/markaspot/markaspot-ui/issues/109)
* move markaspot_demo module into profile ([9afb8d3](https://github.com/markaspot/markaspot/commit/9afb8d367fec54bd927af7952993b479f66ea2c3))

### Bug Fixes

* accept numeric jurisdiction IDs in Settings API for embed compatibility ([bcd6d7b](https://github.com/markaspot/markaspot/commit/bcd6d7b3df369afa6b1838974f29a0488de732a5)), closes [markaspot/markaspot-ui#151](https://github.com/markaspot/markaspot-ui/issues/151)
* add field_request_media to default and management form displays ([cd512b6](https://github.com/markaspot/markaspot/commit/cd512b6652466a1148359f61c057406075afd56b)), closes [markaspot/markaspot-ui#158](https://github.com/markaspot/markaspot-ui/issues/158)
* add missing service_request dependency to group, media, stats modules ([f8ac58c](https://github.com/markaspot/markaspot/commit/f8ac58c61f415a6870947bec07852a2ab355e981))
* demo requests use workspace coordinates, correct category icons ([be93a92](https://github.com/markaspot/markaspot/commit/be93a92852115df341057675c3ec79c05c05ad72)), closes [markaspot/markaspot-ui#158](https://github.com/markaspot/markaspot-ui/issues/158)
* enable filter_html_nofollow on basic_html text format ([bdd367e](https://github.com/markaspot/markaspot/commit/bdd367e0c51bb5cf163d9c95602e907ea31c1f8d))
* enable showBoundaryOnMap in workspace nuxt_config defaults ([67fde03](https://github.com/markaspot/markaspot/commit/67fde03e13e4a0b644a2ced8d1bfc7bfb6a49fd7))
* logo upload - use POST instead of PATCH and fix SVG mime detection ([a35d75d](https://github.com/markaspot/markaspot/commit/a35d75d232af395874260853d7dbdf9950586370))
* migrate geolocation widget from mapbox to nominatim when mapbox is missing ([#145](https://github.com/markaspot/markaspot/issues/145)) ([665d6d0](https://github.com/markaspot/markaspot/commit/665d6d0cf31040fdbc2fa6889df8212a19ae6ca5))
* move jur-tenant_admin group role to markaspot_fastmap ([4c51d4a](https://github.com/markaspot/markaspot/commit/4c51d4a27c6e9d6d464d714967809356c9aefbac))
* revert field_request_media from config/install to prevent circular deps ([f082bc9](https://github.com/markaspot/markaspot/commit/f082bc9fde5edf2529473962c1ec207727b34e4b)), closes [markaspot/markaspot-ui#158](https://github.com/markaspot/markaspot-ui/issues/158)
* **security:** add Xss::filter, language guards and allowlist validation ([f607b6e](https://github.com/markaspot/markaspot/commit/f607b6e9a07628f1c1676d1d470322009f378722))
* set OpenFreeMap defaults for fallback_style in config/install ([ede88b4](https://github.com/markaspot/markaspot/commit/ede88b40acd137024d836220d8b2a90e04751793)), closes [markaspot/markaspot-ui#158](https://github.com/markaspot/markaspot-ui/issues/158)
* use dynamic form display lookup and remove dead dependency code ([c973b7f](https://github.com/markaspot/markaspot/commit/c973b7fc95af11eb1a2c0d7cacab43f83b533ce6))
* wrap raw Polygon/MultiPolygon in FeatureCollection in Settings API ([2f700a6](https://github.com/markaspot/markaspot/commit/2f700a657e44e0f50158297838ecf924bd05e86c))

## [11.9.12](https://github.com/markaspot/markaspot/compare/11.9.11...11.9.12) (2026-03-19)

### Features

* accept jurisdiction slugs on all API endpoints ([6a8509e](https://github.com/markaspot/markaspot/commit/6a8509e400afdd27ec61d33aba543ea415d7aef7))
* add smoke tests to start.sh ([2a7000b](https://github.com/markaspot/markaspot/commit/2a7000baa2e1760e307c6c5c0f11652b0b059d76))
* expose operator data in Settings API for legal pages ([545a811](https://github.com/markaspot/markaspot/commit/545a8116cf290ad775c31403ae95168b35652986))

### Bug Fixes

* add all jur fields to group form display ([785e522](https://github.com/markaspot/markaspot/commit/785e52284a938552157e0963fc8fd2503a9f9d38))
* add cache metadata to error responses, clean up start.sh ([5d3ceb3](https://github.com/markaspot/markaspot/commit/5d3ceb324a9980b92ac54b25103a1b5b77c93145))
* add field_service_categories to jur group type via update hook ([f4a2b49](https://github.com/markaspot/markaspot/commit/f4a2b49fcef80abf05c8692a8c040b5039e8f269))
* add jsonapi to profile deps, set jurisdiction on pages ([02e22ea](https://github.com/markaspot/markaspot/commit/02e22ea8ed34ebcec00c7af95dd8409a9c27c3fa))
* add OpenFreeMap styles to default jurisdiction config ([6db813a](https://github.com/markaspot/markaspot/commit/6db813a67a708c3bcf56d007c93c359fa479881d))
* complete taxonomy form displays for category and status ([195715c](https://github.com/markaspot/markaspot/commit/195715cd2fef3dc1bb85e6c25c581f7dd60cf0a5))
* do not add api_user to jurisdiction group ([4175054](https://github.com/markaspot/markaspot/commit/4175054c75ababc3eab17f53670ceebb340f2a27))
* fresh install experience (map styles, API auth, features, start page) ([987a579](https://github.com/markaspot/markaspot/commit/987a5799b446c7d220049506bc41f5e6d904e9ec))
* handle center object format in settings controller, final cache clear ([7f78e83](https://github.com/markaspot/markaspot/commit/7f78e830d5f35d9dbaa865ca320914238150dfad))
* harden start.sh (admin password, drush cmd, smoke test URLs) ([fc58c14](https://github.com/markaspot/markaspot/commit/fc58c143388f8b0ef329bcec6f7f724589482601))
* keep numeric IDs blocked, add group cache tag on slug lookup ([ea588ee](https://github.com/markaspot/markaspot/commit/ea588ee050a6e119b7344a16065f7d3528fe2e13))
* restore form displays from profile config/optional after install ([1a5d514](https://github.com/markaspot/markaspot/commit/1a5d514bff7b7a274809ab7b1b7541f599d1cf6c))
* security review fixes (injection, null deref, cache, PII) ([0143d12](https://github.com/markaspot/markaspot/commit/0143d121e3b55f46f6b63cd246cfda579ceb2a9c))
* set fallback_style in start.sh, restore numeric jurisdiction ID support ([51dda05](https://github.com/markaspot/markaspot/commit/51dda05080be504fbbc27ad2d35a81bb3100fa00))
* update customAttribution from CartoDB/MapTiler to OpenFreeMap ([6cd8c6a](https://github.com/markaspot/markaspot/commit/6cd8c6ae6f58dd9a2d8354bab217e88ee8a83086)), closes [markaspot/markaspot-ui#143](https://github.com/markaspot/markaspot-ui/issues/143)
* use correct cache tag for group list invalidation ([76b8a26](https://github.com/markaspot/markaspot/commit/76b8a26bec7e90d2976ef83e7145e9afb8ab3dea))

## [11.9.11](https://github.com/markaspot/markaspot/compare/11.9.10...11.9.11) (2026-03-17)

### Features

* add markaspot_geocoder to profile with ENV-based provider switching ([84770cd](https://github.com/markaspot/markaspot/commit/84770cda3fe2071e76bb503115813db836688e3a)), closes [markaspot/markaspot-ui#146](https://github.com/markaspot/markaspot-ui/issues/146)
* **fastmap:** store AI system prompt from workspace creation ([b66336f](https://github.com/markaspot/markaspot/commit/b66336f8972cd3bce51517a9c09d171fe30c23f8))
* move setup scripts from markaspot-cloud to profile ([cff3013](https://github.com/markaspot/markaspot/commit/cff30134501249d01f94646f06499741549ea446))

### Bug Fixes

* add create field_address and field_e_mail to authenticated role ([55cbad6](https://github.com/markaspot/markaspot/commit/55cbad6839596580881b36d37ef5909cd315524e))
* **fastmap:** use correct variable names for circle boundary fallback ([47971d0](https://github.com/markaspot/markaspot/commit/47971d0d1a02da0df38097e97c26c96851cc0e88))
* resolve site:install dependency errors ([cfc4d92](https://github.com/markaspot/markaspot/commit/cfc4d92156f1c8c2c4d5d54841ae957b3bc97e64))
* set Gin as admin theme during site:install ([92aef4a](https://github.com/markaspot/markaspot/commit/92aef4ab96832d901d915ee37c07f1340284b39d))
* **vision:** validate jurisdiction group bundle type ([654ed02](https://github.com/markaspot/markaspot/commit/654ed028485188ebf97eea3c915a2cb89faf9f3e))

## [11.9.10](https://github.com/markaspot/markaspot/compare/11.9.9...11.9.10) (2026-03-16)

### Features

* add anonymous and authenticated role configs to profile ([7adc520](https://github.com/markaspot/markaspot/commit/7adc520209713ac77b39818fbc8d56587ff1ba1d))
* add field_jurisdiction configs to markaspot_group ([63af6b2](https://github.com/markaspot/markaspot/commit/63af6b20fc6a90c725e6ea094cbb584181ee6cf6))
* add group field storages to markaspot_nuxt config/install ([fbe53bc](https://github.com/markaspot/markaspot/commit/fbe53bc853e62d098993aba7c34dcffbc39f8e72))
* config audit - profile installs without config/sync ([fe7ecac](https://github.com/markaspot/markaspot/commit/fe7ecac6c4c593c2f53d75c03fa4a16b4aa6f5af))
* **fastmap:** concurrent published limit for Free tier ([c08a414](https://github.com/markaspot/markaspot/commit/c08a414ee50830c75043579be45293f57e2b7363))

### Bug Fixes

* add markaspot_group as dependency of markaspot_open311 ([66de93a](https://github.com/markaspot/markaspot/commit/66de93a9bd3b6edb8f8497922f5a5c8d80f3662c))
* guard field_jurisdiction queries in MarkASpotSettingsController ([4b9af52](https://github.com/markaspot/markaspot/commit/4b9af523ea7fc4916282397124fb535c3d914241))
* move field.storage.node.body to config/optional ([dc1650f](https://github.com/markaspot/markaspot/commit/dc1650f4773cbf497218e430a124a5d648c1ac9b))
* move group configs with external dependencies to config/optional ([3987bd7](https://github.com/markaspot/markaspot/commit/3987bd7ddcf23eabd358f01c2afa112fa3346794))
* move markaspot_nuxt field storages to config/optional ([f7a563b](https://github.com/markaspot/markaspot/commit/f7a563b0187d0c9de08ed3e763d2c8fcb4f96a67))
* move page field config to config/optional in default_content ([662b7fd](https://github.com/markaspot/markaspot/commit/662b7fd206e739a17022ac9c8aa96d83a7840e05))
* move profile configs with optional dependencies to config/optional ([6e10cf4](https://github.com/markaspot/markaspot/commit/6e10cf4382c9ceef0f7a1d83e4501f1f04f7442a))
* remove toolbar module dependency from editorial_board role ([2775076](https://github.com/markaspot/markaspot/commit/2775076bb52de5002f19fecf9299fdb469e63c34))
* **security:** block numeric jurisdiction IDs on settings endpoint ([7ec0ba4](https://github.com/markaspot/markaspot/commit/7ec0ba445a0566bda61c3e7b02187cf7cf00577b))
* **security:** remove API keys from public settings response ([839a616](https://github.com/markaspot/markaspot/commit/839a616c4684b045e383f1562e88f5a10d4647eb))

## [11.9.9](https://github.com/markaspot/markaspot/compare/11.9.8...11.9.9) (2026-03-15)

### Features

* add status term translations for all 12 supported languages ([62f0d19](https://github.com/markaspot/markaspot/commit/62f0d19c47325fb5b80934126fe96205a7ec0ff6)), closes [markaspot/markaspot-ui#123](https://github.com/markaspot/markaspot-ui/issues/123)
* add Stripe billing fields and controller to markaspot_fastmap ([761a258](https://github.com/markaspot/markaspot/commit/761a2582fd229d1ff6fb800f7e91377c4b40b981))
* expose embed config from field_nuxt_config to frontend ([dad48b9](https://github.com/markaspot/markaspot/commit/dad48b9108b02a157e1c7dd1721f8611121a84f9))
* **fastmap:** add usage permission to dashboard roles and document access check ([88db8df](https://github.com/markaspot/markaspot/commit/88db8df7656cca5f6d6bde2248dda7da8061de52))
* generate 1km circle boundary when no boundary provided ([935f3d8](https://github.com/markaspot/markaspot/commit/935f3d828c559319290d24a5ef3cb617bdbe692c))
* generate demo reports on workspace creation with security fixes ([a289c30](https://github.com/markaspot/markaspot/commit/a289c301c971d2c0be9efeabd493bf5c8b7aae76))
* **group:** add member invitation system with email tokens ([#86](https://github.com/markaspot/markaspot/issues/86)) ([ba7c22f](https://github.com/markaspot/markaspot/commit/ba7c22f566bfa3d56753f5e304161245e1f04d5c))
* **group:** add update hook 11909 for moderator role rename and scope fix ([68cbd7b](https://github.com/markaspot/markaspot/commit/68cbd7b497b93f1ebba79717151e186f0ece08af))
* **group:** add user detail and profile update endpoints for member management ([#105](https://github.com/markaspot/markaspot/issues/105)) ([b3fe6f3](https://github.com/markaspot/markaspot/commit/b3fe6f31e31668cfc2f405c58d7a83a80cfdb832))
* support custom status terms in workspace provisioning ([03e8126](https://github.com/markaspot/markaspot/commit/03e8126856a491bff1f84bc6493c89bc0b195821)), closes [markaspot/markaspot-ui#124](https://github.com/markaspot/markaspot-ui/issues/124)

### Bug Fixes

* **ai,vision:** add api_key_auth to all AI/Vision routes and fix resolveApiKey priority ([d9ced9d](https://github.com/markaspot/markaspot/commit/d9ced9d830390dc739242f898f7928b26b2c388a))
* **fastmap:** accept frontend_base_url for verify email links ([57586d2](https://github.com/markaspot/markaspot/commit/57586d2ce40da4d0ec54830e0ddc7f51a9267b90))
* **fastmap:** re-verify support after email prefetch consumes token ([0a3c32a](https://github.com/markaspot/markaspot/commit/0a3c32a6de8aba5d890595cfcb6a1ec7f3626cfa)), closes [markaspot/markaspot-ui#104](https://github.com/markaspot/markaspot-ui/issues/104)
* **fastmap:** use /start/verify/ path for email verification links ([befe605](https://github.com/markaspot/markaspot/commit/befe60582631c3b883196f2f41973ec66d760ff9))
* **group:** harden invitation system against security review findings ([d8cd5ba](https://github.com/markaspot/markaspot/commit/d8cd5bae607adbc64365a66ca97ed7c95a229e5f))
* **group:** randomize auto-created usernames to prevent enumeration ([f235bff](https://github.com/markaspot/markaspot/commit/f235bffc87c5e96b801c871ca2f717860765b460))
* **group:** remove editorial role from invitation permitted roles ([4aba889](https://github.com/markaspot/markaspot/commit/4aba889339e91fb846a8c79fad24036ee9f61f42))
* **group:** replace _csrf_token route requirement with _auth cookie for API endpoints ([f3d726f](https://github.com/markaspot/markaspot/commit/f3d726f8528deb750f802e181b2aeecb39be074b))
* **group:** resolve PHPCS violations in GroupInvitationController ([e55e870](https://github.com/markaspot/markaspot/commit/e55e870a7af8eafc7b781a183945bb90a1f17bd3))
* harmonize ALLOWED_LANGS to 12 languages, auto-install missing languages ([9b8e5e7](https://github.com/markaspot/markaspot/commit/9b8e5e706aba2482f7e6dbf75abcd0fd4ae6537c)), closes [markaspot/markaspot-ui#122](https://github.com/markaspot/markaspot-ui/issues/122)
* make field_address optional and validate coordinate bounds ([abdb858](https://github.com/markaspot/markaspot/commit/abdb8582f9117c803e1394e7358cf0784e6f625c)), closes [markaspot/markaspot-ui#130](https://github.com/markaspot/markaspot-ui/issues/130)
* reduce demo report fallback radius from 2km to 500m ([3ce83f1](https://github.com/markaspot/markaspot/commit/3ce83f15b610c16b7a8eb9dfdb66bc761ca9dd92))
* review findings - language install, icon sanitization, test property ([1ac1fc7](https://github.com/markaspot/markaspot/commit/1ac1fc70db4ca7a8b1bbb8f2f96ef1129c9a2f31))
* **security:** harden user management endpoints against review findings ([b2185d1](https://github.com/markaspot/markaspot/commit/b2185d1cea88984f4551f3dd4888a1f15d06742e))
* **security:** protect admin accounts from tenant admin access ([6d539e2](https://github.com/markaspot/markaspot/commit/6d539e2f8e4954dc76537a7b650e881058035c80)), closes [markaspot/markaspot-ui#105](https://github.com/markaspot/markaspot-ui/issues/105)
* swap center_lat/center_lng extraction from map.center array ([c8def97](https://github.com/markaspot/markaspot/commit/c8def97d1ae0e06f03c1d6d6c15d9e840a5687d7)), closes [markaspot/markaspot-ui#126](https://github.com/markaspot/markaspot-ui/issues/126)
* use GeoJSON convention [lng, lat] in install hook map.center ([68b78fe](https://github.com/markaspot/markaspot/commit/68b78fe17e062357fd2ed52c0e4c8b3a658174a6)), closes [markaspot/markaspot-ui#126](https://github.com/markaspot/markaspot-ui/issues/126)
* verify endpoint returns JSON with login_token for Nuxt proxy ([d867072](https://github.com/markaspot/markaspot/commit/d867072395310b34e3d75f06734e8f3247c0f938))

### Refactoring

* **group:** inject TierConfigService via DI instead of static call ([e891059](https://github.com/markaspot/markaspot/commit/e89105944df945d031a2d628bbdaa73c23b703fc))
* simplify default statuses to 2 (Created/Done) ([e58f7cb](https://github.com/markaspot/markaspot/commit/e58f7cb646dada294a8e90116a5c1054a690a440))

## [11.9.8](https://github.com/markaspot/markaspot/compare/11.9.7...11.9.8) (2026-03-12)

### Features

* add optional markaspot_redis status module ([5aa373b](https://github.com/markaspot/markaspot/commit/5aa373b065c18492e3e6a4456b11a3dd02b89830))
* **fastmap:** add field_tier for workspace subscription management ([81dca2b](https://github.com/markaspot/markaspot/commit/81dca2b72e22369e5fd6437515d416bd79dd2899))
* **fastmap:** add tier limit validation constraint ([7270c64](https://github.com/markaspot/markaspot/commit/7270c64c9a19f1d0908cd9ba16dd398026de9ea9))
* **fastmap:** add workspace visibility modes (public/submission_only/authenticated) ([4550131](https://github.com/markaspot/markaspot/commit/455013199944bb9c081eaa606d0cb878dd45bb7d))
* **fastmap:** workspace visibility enforcement and cache invalidation ([0e20981](https://github.com/markaspot/markaspot/commit/0e209814673c4b80f3b07851dbb491fd55e2f8d7))
* **group:** add field_tier for SaaS subscription tiers ([4dac7de](https://github.com/markaspot/markaspot/commit/4dac7de53ad1b779be8c33abca995b70c4727b25))
* **markaspot_nuxt:** tenant settings API for self-service configuration ([d00e8c8](https://github.com/markaspot/markaspot/commit/d00e8c8f09080daa6b82572c2d31319c18511f62))
* **nuxt:** add [node:markaspot_media_url] token ([cecaf46](https://github.com/markaspot/markaspot/commit/cecaf46b700fc71dca52a1a9a96c66073a657ec0))
* **nuxt:** add FastMap workspace creation endpoint ([ac59578](https://github.com/markaspot/markaspot/commit/ac59578741f014b0f5b9083c5e01b054be0bdfee))
* **nuxt:** add settings alter hook for module extensibility ([20423ab](https://github.com/markaspot/markaspot/commit/20423ab3810a142706c80534b15b91299172ad55))
* **open311:** add imagelist datatype with backend validation ([94006fb](https://github.com/markaspot/markaspot/commit/94006fb39e0a78ee0ddb9270e3f06fb73fd5cfe5))
* **passwordless:** translatable mail templates with jurisdiction context ([b4687d3](https://github.com/markaspot/markaspot/commit/b4687d363b70d921a7720d904fd423676acf46f9))

### Bug Fixes

* **ai:** use isNotNull for longtext field checks in attribute queries ([36b7604](https://github.com/markaspot/markaspot/commit/36b7604ce0731ca09e27799b92e43fddb9f6127b))
* **fastmap:** rename api_key to service_key to avoid auth provider collision ([019704d](https://github.com/markaspot/markaspot/commit/019704deb9befb5da9ec8f23e3d0a53487e6d00d))
* **fastmap:** use TrustedRedirectResponse and add {id} placeholder ([3cfc93e](https://github.com/markaspot/markaspot/commit/3cfc93ed0bc203e1bacfc5da4d2512b44b375086))
* resolve all phpcs errors and add FastMap unit tests ([3464ead](https://github.com/markaspot/markaspot/commit/3464ead465938adf416b1e82d313bb0c03af58d5))

### Refactoring

* **fastmap:** extract dedicated module with security and i18n fixes ([4bdd953](https://github.com/markaspot/markaspot/commit/4bdd953f287eb6c99b3bcf01a02a8ab25381e881))

## [11.9.7](https://github.com/markaspot/markaspot/compare/11.9.6...11.9.7) (2026-03-09)

### Features

* **media:** entity reference selection allowing unpublished request_image during AI screening ([b4de59c](https://github.com/markaspot/markaspot/commit/b4de59cb9084775fb3cfd07e5317432a6e2a1551))
* **nuxt:** enforce feature flags based on installed modules ([2c18dd9](https://github.com/markaspot/markaspot/commit/2c18dd9e4e5025b05d6c8e3b978af58592b64ec3))

### Bug Fixes

* **ai:** scope findMissingEmbeddings by jurisdiction at query level ([045782b](https://github.com/markaspot/markaspot/commit/045782b29369ca3106dc61fbd203510753aec881))

## [11.9.6](https://github.com/markaspot/markaspot/compare/11.9.5...11.9.6) (2026-03-09)

### Features

* **ai:** add NodeAnalysisService for combined sentiment + hazard analysis ([e9163f1](https://github.com/markaspot/markaspot/commit/e9163f1fea614a46024217283bc108482f8fe6e7))

### Bug Fixes

* **ai:** process unpublished nodes and update default models ([f43bced](https://github.com/markaspot/markaspot/commit/f43bceda84d0f20898ad382e4ce3f78bf12c5550))
* **ai:** scope status options by jurisdiction in AI assist ([114d01a](https://github.com/markaspot/markaspot/commit/114d01a506f4372cd616d0f6edf337acb3119e5a))
* **group:** create group_roles field instance on jur-group_membership ([f1bd72a](https://github.com/markaspot/markaspot/commit/f1bd72a87df67a03946181bb6722a91996c2c126))
* **group:** enable gnode + create group_roles field storage in update_11907 ([fab1a6c](https://github.com/markaspot/markaspot/commit/fab1a6c37cc509466162fcc17aa475720d03c805))
* **group:** use nuxt_config_json_form widget in update_11907 ([31a4aea](https://github.com/markaspot/markaspot/commit/31a4aea70653d7c4cb6ab17c3d4853b3d24564b8))
* privacy fail-closed, hazard propagation, CSRF, PII-safe descriptions ([32b0104](https://github.com/markaspot/markaspot/commit/32b01041fae406530dfee6bbde6d7e1039f7b396))
* publish media after AI screening, unpublish on PII ([d9e69f3](https://github.com/markaspot/markaspot/commit/d9e69f32b11ef085cc8ba13b0edb9021ecfda192))
* resolve root jurisdiction for AI assist status term selection ([8b01501](https://github.com/markaspot/markaspot/commit/8b015011d7f774a09d0d27a711047d7f10800ea0))
* save hazard_level and hazard_category from vision AI, propagate to node ([b387c71](https://github.com/markaspot/markaspot/commit/b387c71c1b9651f2783f7389f19e8ece8842a9f1))

## [11.9.5](https://github.com/markaspot/markaspot/compare/11.9.4...11.9.5) (2026-03-07)

### Features

* add dashboard + escalation as default profile dependencies ([ece7c8a](https://github.com/markaspot/markaspot/commit/ece7c8a25d5116d655e7ccca21181a49175bc0fe))
* **ai:** add AI-powered attribute filling for service requests ([1d2e83d](https://github.com/markaspot/markaspot/commit/1d2e83d2fd218b2e27ce17228ebbf067803fcd00))
* **ai:** add form assistant endpoint with status, organisation, and priority suggestions ([bfa6a3e](https://github.com/markaspot/markaspot/commit/bfa6a3e09719c57a1f7734fe0653661af5c16d82))
* **group:** add update_11907 reconciliation hook for jur/org infrastructure ([0890c77](https://github.com/markaspot/markaspot/commit/0890c772115a95a00237754331e49dd338ce8180))
* **nuxt:** add /api/jurisdiction-hosts endpoint for app-mode resolution ([ac7f803](https://github.com/markaspot/markaspot/commit/ac7f803f9d02951d5bba0ae7e1fe53f6611659a1))
* **open311:** add datatype_description, default_value, validation, and conditions to service definition schema ([f020eef](https://github.com/markaspot/markaspot/commit/f020eeff01c8fa4da2e21e18c561c94eda2098b8))
* **open311:** add service definition attributes support ([aeedfe8](https://github.com/markaspot/markaspot/commit/aeedfe8415d4f01dce33d003ff05d5545a92c44e))

### Bug Fixes

* **escalation:** update field_jurisdiction when escalating request ([7ca537c](https://github.com/markaspot/markaspot/commit/7ca537c90244ffe4bc30df62cb4a58f4597fdae2))
* **open311:** harden service definition with DI, input validation and permissions ([a948fd1](https://github.com/markaspot/markaspot/commit/a948fd1dce4cbb48e432f6fb34afa5408320984e))
* **privacy:** publish media on node save instead of unpublishing flagged media ([e5ed1b5](https://github.com/markaspot/markaspot/commit/e5ed1b5dd64d338cea91030f18f9fef17d06e472))
* resolve remaining PHPCS comment and naming violations ([7885691](https://github.com/markaspot/markaspot/commit/7885691914474e14b4721dcad430668b5397f5de))
* **service_request:** use private:// for request images ([8a20cfd](https://github.com/markaspot/markaspot/commit/8a20cfd60bb0a8518e8204433af410f497a2d76f))
* set langcode on internal_remark paragraphs and t() consistency ([bc2f3b2](https://github.com/markaspot/markaspot/commit/bc2f3b2609e382783ef9b1e04bf569a1f29508c1))
* set paragraph langcode from parent node on status notes ([fa2bd27](https://github.com/markaspot/markaspot/commit/fa2bd27fa4128cee244a272382f63b7dc2687230))
* **vision:** remove access checks blocking unpublished media in AI analysis ([c0b66a1](https://github.com/markaspot/markaspot/commit/c0b66a1275635d5eccef2e61fa0c4f20ca43fdb2))

### Performance

* **request_id:** use SQL for jurisdiction backfill in update hook ([4912ac5](https://github.com/markaspot/markaspot/commit/4912ac5b756402639296b42fe86bd1acfe20665b))

* fix(security): validate frontend_base_url scheme and sanitize URL construction (7ea449d)
* feat(nuxt): add [node:markaspot_frontend_url] token with jurisdiction slug (a90e77f)
* fix(group): resolve root jurisdiction from category when multiple roots exist (7caee09)

## [11.9.3](https://github.com/markaspot/markaspot/compare/11.9.2...11.9.3) (2026-03-01)

### Features

* **escalation:** auto-grant permissions via update hooks ([e308934](https://github.com/markaspot/markaspot/commit/e308934a12c00a044bf944adefc9010b2d60f9d1))
* **group:** add field_jurisdiction to service_request with jur metadata fields ([ae6872d](https://github.com/markaspot/markaspot/commit/ae6872d272db8238dcc98a85e662e1d66800a342))
* **group:** grant tenant_admin field and escalation permissions ([7929020](https://github.com/markaspot/markaspot/commit/792902055743d02820fbc4a50a973d688f87e0b9))
* **vision:** add per-jurisdiction AI system prompt ([99237dd](https://github.com/markaspot/markaspot/commit/99237ddbe40d2e2ce3270b075297c1582823bcd8))

### Bug Fixes

* **group:** add sync guard to backfill saves and prevent phase cascade ([e0825bc](https://github.com/markaspot/markaspot/commit/e0825bc220657d91993f27ec04f8ced9dc599b61))
* **group:** resolve deepest child jurisdiction in nested hierarchies ([ce1f54d](https://github.com/markaspot/markaspot/commit/ce1f54d2c3c6d02f4d7a15f9bcf1237773e85737))
* **open311:** resolve most-specific jurisdiction in API response ([e76fb0e](https://github.com/markaspot/markaspot/commit/e76fb0e5578933500908db8d2c619a0f3e5dfd58))
* **tenant-admin:** resolve jurisdiction hierarchy for taxonomy term access ([61a4c86](https://github.com/markaspot/markaspot/commit/61a4c86c93da6dbd3823371cf916bc6845a32183))
* **tests:** use request host for multisite compatibility ([5ede383](https://github.com/markaspot/markaspot/commit/5ede383634d64aee413946550aded43f19cf5154))
* **vision:** guard update hook against missing jur group type ([94d00c7](https://github.com/markaspot/markaspot/commit/94d00c7dc2f0728a5664131fc8efca0e6dafca0b))

### Performance

* **group:** use cursor-based backfill in update_11905 for large datasets ([64a2edd](https://github.com/markaspot/markaspot/commit/64a2edd37587837f8402198043bcc94d76fe054e))

## [11.9.2](https://github.com/markaspot/markaspot/compare/11.9.1...11.9.2) (2026-02-26)

### Features

* **group:** category filtering for child jurisdictions with allow-list ([21b5ee7](https://github.com/markaspot/markaspot/commit/21b5ee75356bf8b8d7bd4c2f1068c09f38248ddc))
* **group:** make pages jurisdiction-aware with sticky start page ([9b98c81](https://github.com/markaspot/markaspot/commit/9b98c81791816e939f0a18e768b7679359b88de7))

### Bug Fixes

* **group:** harden update hook idempotency and relax multi-tenant test ([ce4ad93](https://github.com/markaspot/markaspot/commit/ce4ad9368f3efa1ece01d6bc37816fe5ea784b90))

## [11.9.1](https://github.com/markaspot/markaspot/compare/11.9.0...11.9.1) (2026-02-26)

### Features

* configurable jurisdiction/organisation visibility in API response ([fe19fe6](https://github.com/markaspot/markaspot/commit/fe19fe64794ceaa259a447bc9d822f514a11eada))
* **dashboard:** internal remarks, status note authors, session handoff, user matrix ([6bb2228](https://github.com/markaspot/markaspot/commit/6bb2228c87fa4222bf332ce0e732805e1fb9cf8d))
* **escalation:** add markaspot_escalation module with jurisdiction-aware delegation ([c775916](https://github.com/markaspot/markaspot/commit/c77591688fe48fea56e1c833662b2f2212104fdb))
* **group:** jurisdiction hierarchy, org derivation, taxonomy inheritance, backfill hooks ([84f7af5](https://github.com/markaspot/markaspot/commit/84f7af5ad67bed7994904bf50e8e2342d7fa98cd))
* **group:** members matrix with hierarchy headers, CSRF enforcement, N+1 fix ([3868844](https://github.com/markaspot/markaspot/commit/3868844bf473b59f6aab553fca5a37de83c372fd))
* hierarchical jurisdiction resolution with API parameter cleanup ([d7f9d2b](https://github.com/markaspot/markaspot/commit/d7f9d2b44610eb1e215a2a56a80f8e1fb52ba3a7))
* **multi-tenant:** GeoReport jurisdiction filtering, boundary validation, emergency scoping ([4fc4e62](https://github.com/markaspot/markaspot/commit/4fc4e621b4d40a4da58fa8faba13866cce7be19a))
* **nuxt-api:** settings API extensions, geocoding config, request ID service, JSON schema ([5494528](https://github.com/markaspot/markaspot/commit/549452821e89769b4892b9c3fc044641c3631db0))
* **tenant_admin:** add per-jurisdiction taxonomy management module ([5df8bc8](https://github.com/markaspot/markaspot/commit/5df8bc8dc0c7088ecb411614c99da8ec4da364c3))
* **vision:** jurisdiction-aware AI processing, language translation, GDPR gate ([ebeebcf](https://github.com/markaspot/markaspot/commit/ebeebcfaaa56a03853dcae68fa54bcdfd3d7ba46))

### Bug Fixes

* **group:** jurisdiction-aware initial status for multi-tenant requests ([c047f2f](https://github.com/markaspot/markaspot/commit/c047f2ff7053652376496af33d815a11ae1c0618))

## [11.9.0] - 2026-02-26

### Features
- **ai:** process both embedding and duplicate scan queues ([4a5c39c](https://github.com/markaspot/markaspot/commit/4a5c39c))
- **contact:** add markaspot_contact REST API module ([eb3dded](https://github.com/markaspot/markaspot/commit/eb3dded))
- **group:** auto-assign service requests to sub-jurisdictions by boundary ([793803a](https://github.com/markaspot/markaspot/commit/793803a))
- **nuxt:** add /api/fonts.css endpoint for custom font delivery ([b0b89c4](https://github.com/markaspot/markaspot/commit/b0b89c4))
- **nuxt:** add conditionalFields schema, deprecate legacy category arrays ([f7426b1](https://github.com/markaspot/markaspot/commit/f7426b1))
- **nuxt:** add systemNotice to JSON schema for admin UI editing ([687cb9f](https://github.com/markaspot/markaspot/commit/687cb9f))
- **nuxt:** extend config schema with feature flags and category arrays ([e9c067f](https://github.com/markaspot/markaspot/commit/e9c067f))
- **nuxt:** pass systemNotice from field_nuxt_config to settings API ([30798b0](https://github.com/markaspot/markaspot/commit/30798b0))
- **open311:** add Accept-Language support to single request endpoint ([04f59b5](https://github.com/markaspot/markaspot/commit/04f59b5))
- **open311:** restrict sensitive fields to managers and add gid stats filter ([73ca19f](https://github.com/markaspot/markaspot/commit/73ca19f))
- **service-provider:** add form_alter for read-only notes display ([b4b3f60](https://github.com/markaspot/markaspot/commit/b4b3f60))
- **vision:** add AI hazard and privacy fields to media entity ([43b6923](https://github.com/markaspot/markaspot/commit/43b6923))

### Bug Fixes
- add Vary header for Accept-Language on GeoReport API responses ([988787c](https://github.com/markaspot/markaspot/commit/988787c))
- **ai:** count all service requests in processing status ([b5dd420](https://github.com/markaspot/markaspot/commit/b5dd420))
- **markaspot_group:** change jur-admin scope from insider to individual ([2a8cc44](https://github.com/markaspot/markaspot/commit/2a8cc44))
- **markaspot_group:** add update hooks for group role scope fixes ([66ce851](https://github.com/markaspot/markaspot/commit/66ce851))
- **open311:** use site default language instead of hardcoded 'en' fallback ([2d4e1f9](https://github.com/markaspot/markaspot/commit/2d4e1f9))

### Refactoring
- **open311:** extract language negotiation into shared trait ([5c65b0f](https://github.com/markaspot/markaspot/commit/5c65b0f))

## [11.8.0] - 2026-02-02

### Features
- **ai:** add markaspot_ai module for AI-powered features ([c899888](https://github.com/markaspot/markaspot/commit/c899888))
- **cap:** add CAP 1.2 export module + fix Drush 13 commands ([b244a00](https://github.com/markaspot/markaspot/commit/b244a00))
- **config:** add service_request fields and WMS layer support ([673b029](https://github.com/markaspot/markaspot/commit/673b029))
- **dashboard:** add markaspot_dashboard module, fix AI cron ENV check ([b2645c8](https://github.com/markaspot/markaspot/commit/b2645c8))
- **nuxt:** add custom color picker with Tailwind presets
- **nuxt:** add groupTypes config for JSON:API relationships
- **open311:** add nid sort field for numeric ID sorting
- **profile:** add jur group type config for multi-tenant support
- **service_provider:** add ECA event for SP response notifications
- **update:** add field_all_groups_member for Group 3 upgrade
- **vision:** add hazard level and category fields with update hook

### Bug Fixes
- **deps:** remove npm-asset/leaflet.heat
- **markaspot_group:** cast target_id to int for JSON:API compatibility
- **markaspot_nuxt:** fix XSS vulnerability and remove unused code
- **nuxt_config:** simplify oneOf patterns to object type
- **open311:** prevent leading comma in address formatting
- **open311:** prevent null reference error when status_term is missing
- **publisher:** process all categories in each cron run
- filter stats API by content language
- update info block

### Refactoring
- **nuxt:** make json_form_widget a soft dependency
- move StatusNoteController from markaspot_nuxt to markaspot_dashboard
- **vision:** use CAP standard codes for hazard categories

## [11.7.0] - 2025-01-23

### Added
- **Passwordless Authentication**: New module for passwordless login with configurable redirect and group support
- **Emergency Mode**: New module with translations and configuration options
- **Jurisdiction Support**: Added as optional group type for multi-tenant setups
- **Icon Module**: New markaspot_icon module for enhanced iconography
- **Publisher Module**: Queue-based publishing workflow for service requests
- **Stats Module**: Enhanced statistics module with improved routing
- **Confirm Module**: New confirmation flow module for Mark-a-Spot workflows
- **Feedback Module**: Service provider assignment feedback notes and citizen feedback validation by status
- **Address Field**: Added to GeoReport search functionality
- **Media Status Updates**: Support for delta-based updates via extended_attributes
- **Published Status Flag**: Added to Open311 API extended attributes
- **Media Alt Text**: Added to extended GeoReport property
- **Custom Token**: New token for use as site:url replacement in emails
- **Queue Workers**: Implemented for high-volume jobs and cron-related tasks
- **Field Disable**: Added field_disable_form for category terms
- **Dark Mode**: Added dark mode style for MapLibre

### Changed
- **Drupal 11 Compatibility**: Updated dependencies and constraints for Drupal 11 support
- **Map Configuration**: Separated map config for headless setups, made markaspot_map optional
- **Headless Architecture**: Cleaned up routes for decoupled Nuxt frontend with UUID-based routing
- **Group Module API**: Updated from deprecated GroupContent to GroupRelationship
- **Icon Picker**: Switched to modern iconpicker widget and library
- **MapBox Migration**: Removed MapBox dependencies, replaced with MapLibre
- **ECA to EventSubscriber**: Replaced ECA with EventSubscriber for email sending in resubmission module
- **API Performance**: Improved with caching and query optimization
- **Gin Theme Integration**: Updated Mark-a-Spot UI module to work with Gin theme and Gin Toolbar
- **Vector Styles**: Added fallback map vector style URL
- **Field Group**: Bumped to 4.0 to resolve doubled asterisk issue
- **Archive Days**: Refactored handling with new taxonomy term field for config overrides

### Fixed
- **markaspot_request_id**: Removed ineffective database update function, added field existence checks, fixed form labels, cleaned up legacy module references
- **GeoReport API**: Improved field handling and validation errors
- **Extended Attributes**: Ensured Drupal extended attributes appear in single request endpoint
- **Unpublished Nodes**: Enable user 1 and bypass node access users to view unpublished nodes via GeoReport API
- **Cookie Handling**: RemoveCookieSubscriber now checks session UID directly
- **Installation**: Resolved dependencies and cleaned up optional modules
- **Node Display**: Enhanced in Gin theme
- **Service Request Links**: Only link to published service requests
- **Migration**: Added migration from markaspot_map to markaspot_nuxt
- **Email Validation**: Restored email verification for citizen feedback, support multiple email addresses
- **File Deletion**: Prevented aggressive file deletion in markaspot_nuxt module
- **Page Parameters**: Restored page and offset parameters for service requests
- **Address Updates**: Fixed address update handling
- **Status Notes**: Fixed field_status changes to add status_note automatically
- **Symfony Compatibility**: Fixed Symfony incompatibility issues
- **MapLibre**: Fixed map initialization, scrolling, and library loading
- **Media URLs**: Fixed protocol handling to respect current request stack
- **Nominatim**: Improved formatting of Nominatim addresses
- **Type Safety**: Fixed type safety in GeoreportProcessorService and request_id module
- **Null Values**: Added fallback for empty initial status and undefined color properties
- **JSON/XML Format**: Switched to event subscriber to check for request format
- **Widget Hooks**: Updated deprecated __WIDGET_TYPE_form_alter hook in shstweak module
- **Library Aggregation**: Fixed JavaScript error when aggregation of MapBox library enabled

### Deprecated
- **markaspot_action_front**: Deprecated for headless architectures (optional for traditional themes)
- **markaspot_action_stats**: Deprecated for headless architectures (optional for traditional themes)

### Removed
- **MapBox**: Removed all MapBox dependencies in favor of MapLibre
- **Trend Module**: Removed deprecated trend module
- **Metatag Module**: Removed from dependencies
- **Views Data Export Hook**: Removed deprecated hook
- **Field Formatter Class**: Removed as dependency
- **Deprecated Actions**: Created update hook to remove deprecated actions

### Security
- **Headless Mode Protection**: Added protection for standard Drupal routes in headless mode
- **Session Handling**: Remove session when api_key is passed as form parameter

### Refactored
- **Code Quality**: Extensive linting and PHP_CodeSniffer fixes across multiple modules
- **Logging**: Reduced excessive logging in feedback, open311, and service provider modules
- **Publisher Module**: Refactored with Drupal best practices
- **Open311 Server**: Major refactoring of Open311 server code
- **Uninstall Hooks**: Added hooks to preserve crucial field data on uninstall
- **Service Provider**: Separated service provider from feedback module

## [10.6.0] - 2023-12-06
### New Features
- **Map Integrations**: Integrated Maplibre and Mapbox into a dedicated git package with added dependencies for enhanced map functionalities. Included maplibre-gl-js for improved map and widget integration.
- **Internationalization Enhancements**: Introduced multi-lingual support for terms and added a module for translation, broadening global usability.
- **User Interface Improvements**: Implemented scrolling pop-ups and fixed logo padding in the toolbar for an enhanced user experience.
- **API Flexibility**: Added a validation bypass for API users.

### Fixes and Improvements
- **Installation and Setup**: Corrected directory post-installation issues and updated maplibre-gl-leaflet constraints.
- **Library Updates**: Updated various dependencies and libraries in line with Drupal 10 requirements.
- **Performance Enhancements**: Numerous bug fixes and minor enhancements for overall stability and performance improvement.

## [9.5.1] - Previous Version
- The version prior to the updates and improvements leading to 10.6.0.

## [8.5.0] - 2020-07-01

- Update Core to 8.9.1
- Added Privacy (GDPR) Module
- Added Resubmission Module
- Added settings and reset to Static JSON Module
- Update Geolocation Nominatim Module
- Update dependent Drupal Modules

## [8.4.4] - 2018-09-19

- Fix installation issues with 8.6 and default content module (#74)

## [8.4.3] - 2018-09-17

- Update Core to >8.6
- Fix static file generation

## [8.4.2] - 2018-07-14

- Theme Updates.
- Add exif orientation module
- Make vue.js filter translatable
- Update Drush, VBO

## [8.4.1] - 2018-03-31

- Theme Updates.
- Add Stats Block.
- Fix markaspot/mark-a-spot#68 geoReport endpoint format detection.
- Update to Drupal 8.5.x

## [8.4.0-rc3] - 2018-02-19

- Bug fixes.
- Add js-files as es6 where needed.


## [8.4.0-rc2] - 2018-02-06

- Minified vue.js.

## [8.4.0-rc1] - 2018-02-06

- Fixed path for dateFormat library.
- Fixed link in footer, about page. Close markaspot/mark-a-spot#64 (travis)
- Fixed properties for title and request_id.
- Switch to node hook in several modules.
- Fixed category and status design in list teasers.
- Restructured default content, now with referenced images.
- Fixed button and selectbox styles.

## [8.4.0-beta1 - 8.4.0-beta4] 2018-02-04

- Fixed image upload widget.
- Added Mark-a-Spot Group module.
- Update components to handle new base field for request_ids.
- Added Mark-a-Spot Request ID module.
- Refactored ID-Generation (Module now deprecated)
- Added update hook for front page.
- Update stats view.
- Fixed a leaking cache error.
- Added Mark-a-Spot Front Page module for enabling panelized Home.
- Added Mark-a-Spot trend module.
- Added boostrap-select, Fixed buttons and shadows.
- Fixed status rest display.
- Added boostrap-select.
- Defined map request block in config.
- Switch shariff design.
- Changed footer layout, Fixed logo stuff.
- Added new config for stats, modify blocks for panelized pages.
- Fixed default image icons in teaser.
- Added Organisation and Status fields.
- Added all Mark-a-Spot modules to profile
- Added theme and module dependencies
- Added MasRadix theme and Geolocation Nominatim

## [8.3.2 - 8.3.3] 2017-11-14

- Update map module with composer installer extension.
- Move installer-extender to top.
- Added composer.lock to repo
- Added shariff as dependency.
- Added installer paths and Fixed geoPHP.

## [8.3.1] 2017-11-12

- Changed shariff library to dev-master.
- Updated Slideout library.
- Reduced repos and Added asset packagist.
- Fixed link for editing nodes via management view.
- Update Leaflet libries and Drupal core to 8.4
- Update Features and Addedress module

## [8.3.0] 2017-05-14

- Added shariff and font-awesome as library requirement (tag: 8.3.0-rc2, tag: 8.3.0)
- Removed message module dependency.
- Removed facets from .info.yml dependeny
- Added scripts for distro installation; remove facets.
- Issue #2878220 by tormi: Installation issue (a non-existent service "rest.link_manager")
- Added new custom token module, close markaspot/mark-a-spot#54 (tag: 8.3.0-rc1)
- Added some management view based on search api index.
- Added pathauto settings, change module order on install
- Updated dependencies and index and request view. (tag: 8.3.0-alpha1)
