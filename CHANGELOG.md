# Changelog

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
