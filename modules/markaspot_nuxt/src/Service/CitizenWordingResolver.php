<?php

declare(strict_types=1);

namespace Drupal\markaspot_nuxt\Service;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\node\NodeInterface;

/**
 * Resolves citizen-facing terminology configured for a jurisdiction.
 *
 * Nuxt applies the selected wording preset at runtime. Backend-generated
 * citizen text needs the same small, stable vocabulary without duplicating
 * the full frontend i18n bundles. Invalid, missing, and malformed config
 * deliberately falls back to the established "report" preset.
 */
final class CitizenWordingResolver {

  /**
   * Runtime placeholders supported by shipped notification mail templates.
   *
   * These deliberately use moustache-style delimiters instead of Drupal
   * Token syntax. MailTextResolver clears unknown Drupal tokens while it
   * resolves node data, whereas these values are replaced first by the mail
   * builder with terminology from the jurisdiction itself.
   */
  public const MAIL_SINGULAR_PLACEHOLDER = '{{ citizen_term_singular }}';

  /**
   * Sentence-start form of the singular mail placeholder.
   */
  public const MAIL_SINGULAR_TITLE_PLACEHOLDER = '{{ citizen_term_singular_title }}';

  /**
   * Runtime plural mail placeholder.
   */
  public const MAIL_PLURAL_PLACEHOLDER = '{{ citizen_term_plural }}';

  /**
   * Sentence-start form of the plural mail placeholder.
   */
  public const MAIL_PLURAL_TITLE_PLACEHOLDER = '{{ citizen_term_plural_title }}';

  /**
   * The wording preset used when a tenant has no valid selection.
   */
  public const DEFAULT_PRESET = 'report';

  /**
   * Curated preset IDs shared with the Nuxt i18n contract.
   */
  public const PRESETS = [
    self::DEFAULT_PRESET,
    'suggestion',
    'entry',
    'contribution',
  ];

  /**
   * Message key for a workspace-wide tier limit.
   */
  public const VALIDATION_TIER_TOTAL = 'tier_total';

  /**
   * Message key for a monthly workspace tier limit.
   */
  public const VALIDATION_TIER_MONTHLY = 'tier_monthly';

  /**
   * Message key for a published-item tier limit.
   */
  public const VALIDATION_TIER_PUBLISHED = 'tier_published';

  /**
   * Message key for the repeated-email validation limit.
   */
  public const VALIDATION_EMAIL_DAILY_LIMIT = 'email_daily_limit';

  /**
   * Message key for a viewable duplicate candidate.
   */
  public const VALIDATION_DUPLICATE_VISIBLE = 'duplicate_visible';

  /**
   * Message key for a duplicate candidate hidden by access control.
   */
  public const VALIDATION_DUPLICATE_HIDDEN = 'duplicate_hidden';

  /**
   * Message key for the optional duplicate-submission hint.
   */
  public const VALIDATION_DUPLICATE_HINT = 'duplicate_hint';

  /**
   * Message key for the hard duplicate-block suffix.
   */
  public const VALIDATION_DUPLICATE_BLOCK = 'duplicate_block';

  /**
   * Basic citizen-facing terms by supported locale and wording preset.
   *
   * This intentionally contains only noun forms needed by backend copy. The
   * full wording remains owned by the Nuxt bundles under
   * i18n/wording-presets/. Keep preset IDs aligned with that contract.
   *
   * @var array<string, array<string, array{singular: string, plural: string}>>
   */
  private const TERM_DATA = [
    'ar' => [
      'report' => ['singular' => 'بلاغ', 'plural' => 'بلاغات'],
      'suggestion' => ['singular' => 'اقتراح', 'plural' => 'اقتراحات'],
      'entry' => ['singular' => 'إدخال', 'plural' => 'إدخالات'],
      'contribution' => ['singular' => 'مساهمة', 'plural' => 'مساهمات'],
    ],
    'cs' => [
      'report' => ['singular' => 'hlášení', 'plural' => 'hlášení'],
      'suggestion' => ['singular' => 'návrh', 'plural' => 'návrhy'],
      'entry' => ['singular' => 'záznam', 'plural' => 'záznamy'],
      'contribution' => ['singular' => 'příspěvek', 'plural' => 'příspěvky'],
    ],
    'da' => [
      'report' => ['singular' => 'henvendelse', 'plural' => 'henvendelser'],
      'suggestion' => ['singular' => 'forslag', 'plural' => 'forslag'],
      'entry' => ['singular' => 'indlæg', 'plural' => 'indlæg'],
      'contribution' => ['singular' => 'bidrag', 'plural' => 'bidrag'],
    ],
    'de' => [
      'report' => ['singular' => 'Meldung', 'plural' => 'Meldungen'],
      'suggestion' => ['singular' => 'Vorschlag', 'plural' => 'Vorschläge'],
      'entry' => ['singular' => 'Eintrag', 'plural' => 'Einträge'],
      'contribution' => ['singular' => 'Beitrag', 'plural' => 'Beiträge'],
    ],
    'de-ls' => [
      'report' => ['singular' => 'Meldung', 'plural' => 'Meldungen'],
      'suggestion' => ['singular' => 'Vorschlag', 'plural' => 'Vorschläge'],
      'entry' => ['singular' => 'Eintrag', 'plural' => 'Einträge'],
      'contribution' => ['singular' => 'Beitrag', 'plural' => 'Beiträge'],
    ],
    'en' => [
      'report' => ['singular' => 'report', 'plural' => 'reports'],
      'suggestion' => ['singular' => 'suggestion', 'plural' => 'suggestions'],
      'entry' => ['singular' => 'entry', 'plural' => 'entries'],
      'contribution' => ['singular' => 'contribution', 'plural' => 'contributions'],
    ],
    'es' => [
      'report' => ['singular' => 'informe', 'plural' => 'informes'],
      'suggestion' => ['singular' => 'sugerencia', 'plural' => 'sugerencias'],
      'entry' => ['singular' => 'entrada', 'plural' => 'entradas'],
      'contribution' => ['singular' => 'contribución', 'plural' => 'contribuciones'],
    ],
    'fi' => [
      'report' => ['singular' => 'ilmoitus', 'plural' => 'ilmoitukset'],
      'suggestion' => ['singular' => 'ehdotus', 'plural' => 'ehdotukset'],
      'entry' => ['singular' => 'merkintä', 'plural' => 'merkinnät'],
      'contribution' => ['singular' => 'panos', 'plural' => 'panokset'],
    ],
    'fr' => [
      'report' => ['singular' => 'signalement', 'plural' => 'signalements'],
      'suggestion' => ['singular' => 'proposition', 'plural' => 'propositions'],
      'entry' => ['singular' => 'saisie', 'plural' => 'saisies'],
      'contribution' => ['singular' => 'contribution', 'plural' => 'contributions'],
    ],
    'hu' => [
      'report' => ['singular' => 'bejelentés', 'plural' => 'bejelentések'],
      'suggestion' => ['singular' => 'javaslat', 'plural' => 'javaslatok'],
      'entry' => ['singular' => 'bejegyzés', 'plural' => 'bejegyzések'],
      'contribution' => ['singular' => 'hozzájárulás', 'plural' => 'hozzájárulások'],
    ],
    'it' => [
      'report' => ['singular' => 'segnalazione', 'plural' => 'segnalazioni'],
      'suggestion' => ['singular' => 'proposta', 'plural' => 'proposte'],
      'entry' => ['singular' => 'voce', 'plural' => 'voci'],
      'contribution' => ['singular' => 'contributo', 'plural' => 'contributi'],
    ],
    'nb' => [
      'report' => ['singular' => 'melding', 'plural' => 'meldinger'],
      'suggestion' => ['singular' => 'forslag', 'plural' => 'forslag'],
      'entry' => ['singular' => 'innlegg', 'plural' => 'innlegg'],
      'contribution' => ['singular' => 'bidrag', 'plural' => 'bidrag'],
    ],
    'nl' => [
      'report' => ['singular' => 'melding', 'plural' => 'meldingen'],
      'suggestion' => ['singular' => 'suggestie', 'plural' => 'suggesties'],
      'entry' => ['singular' => 'vermelding', 'plural' => 'vermeldingen'],
      'contribution' => ['singular' => 'bijdrage', 'plural' => 'bijdragen'],
    ],
    'pl' => [
      'report' => ['singular' => 'zgłoszenie', 'plural' => 'zgłoszenia'],
      'suggestion' => ['singular' => 'propozycja', 'plural' => 'propozycje'],
      'entry' => ['singular' => 'wpis', 'plural' => 'wpisy'],
      'contribution' => ['singular' => 'wkład', 'plural' => 'wkłady'],
    ],
    'pt' => [
      'report' => ['singular' => 'relatório', 'plural' => 'relatórios'],
      'suggestion' => ['singular' => 'sugestão', 'plural' => 'sugestões'],
      'entry' => ['singular' => 'registo', 'plural' => 'registos'],
      'contribution' => ['singular' => 'contribuição', 'plural' => 'contribuições'],
    ],
    'sv' => [
      'report' => ['singular' => 'anmälan', 'plural' => 'anmälningar'],
      'suggestion' => ['singular' => 'förslag', 'plural' => 'förslag'],
      'entry' => ['singular' => 'inlägg', 'plural' => 'inlägg'],
      'contribution' => ['singular' => 'bidrag', 'plural' => 'bidrag'],
    ],
    'tr' => [
      'report' => ['singular' => 'rapor', 'plural' => 'raporlar'],
      'suggestion' => ['singular' => 'öneri', 'plural' => 'öneriler'],
      'entry' => ['singular' => 'kayıt', 'plural' => 'kayıtlar'],
      'contribution' => ['singular' => 'katkı', 'plural' => 'katkılar'],
    ],
    'uk' => [
      'report' => ['singular' => 'звернення', 'plural' => 'звернення'],
      'suggestion' => ['singular' => 'пропозиція', 'plural' => 'пропозиції'],
      'entry' => ['singular' => 'запис', 'plural' => 'записи'],
      'contribution' => ['singular' => 'внесок', 'plural' => 'внески'],
    ],
  ];

  /**
   * Locale-complete, grammar-neutral templates for visible 422 details.
   *
   * The selected plural always follows a colon, so a tenant can choose any
   * supported citizen label without requiring an unreviewed inflected form.
   * These texts deliberately live beside TERM_DATA: the backend has no PO
   * catalog for its JSON:API validation details, while the frontend renders
   * those details verbatim.
   *
   * @var array<string, array<string, string>>
   */
  private const VALIDATION_MESSAGE_TEMPLATES = [
    'ar' => [
      self::VALIDATION_TIER_TOTAL => 'تم الوصول إلى حد مساحة العمل. @wording_plural: @limit. اختر خطة للمتابعة.',
      self::VALIDATION_TIER_MONTHLY => 'تم الوصول إلى الحد الشهري لمساحة العمل. @wording_plural: @limit. اختر خطة أو حاول مجدداً الشهر القادم.',
      self::VALIDATION_TIER_PUBLISHED => 'تم الوصول إلى حد النشر. @wording_plural: @limit. ألغِ نشر العناصر الحالية أو اختر خطة.',
      self::VALIDATION_EMAIL_DAILY_LIMIT => 'تم الوصول إلى الحد اليومي للبريد الإلكتروني. @wording_plural: @count. يرجى المحاولة لاحقاً.',
    ],
    'cs' => [
      self::VALIDATION_TIER_TOTAL => 'Limit pracovního prostoru byl dosažen. @wording_plural: @limit. Chcete-li pokračovat, zvolte tarif.',
      self::VALIDATION_TIER_MONTHLY => 'Měsíční limit pracovního prostoru byl dosažen. @wording_plural: @limit. Zvolte tarif nebo to zkuste příští měsíc.',
      self::VALIDATION_TIER_PUBLISHED => 'Limit pro zveřejňování byl dosažen. @wording_plural: @limit. Zrušte zveřejnění stávajících položek nebo zvolte tarif.',
      self::VALIDATION_EMAIL_DAILY_LIMIT => 'Denní e-mailový limit byl dosažen. @wording_plural: @count. Zkuste to prosím později.',
    ],
    'da' => [
      self::VALIDATION_TIER_TOTAL => 'Arbejdsområdets grænse er nået. @wording_plural: @limit. Vælg et abonnement for at fortsætte.',
      self::VALIDATION_TIER_MONTHLY => 'Den månedlige grænse for arbejdsområdet er nået. @wording_plural: @limit. Vælg et abonnement, eller prøv igen næste måned.',
      self::VALIDATION_TIER_PUBLISHED => 'Grænsen for offentliggørelse er nået. @wording_plural: @limit. Afpublicér eksisterende elementer, eller vælg et abonnement.',
      self::VALIDATION_EMAIL_DAILY_LIMIT => 'Den daglige e-mailgrænse er nået. @wording_plural: @count. Prøv igen senere.',
    ],
    'de' => [
      self::VALIDATION_TIER_TOTAL => 'Das Kontingent des Arbeitsbereichs ist erreicht. @wording_plural: @limit. Wählen Sie einen Tarif, um fortzufahren.',
      self::VALIDATION_TIER_MONTHLY => 'Das monatliche Kontingent des Arbeitsbereichs ist erreicht. @wording_plural: @limit. Wählen Sie einen Tarif oder versuchen Sie es nächsten Monat erneut.',
      self::VALIDATION_TIER_PUBLISHED => 'Das Veröffentlichungskontingent ist erreicht. @wording_plural: @limit. Heben Sie die Veröffentlichung bestehender Inhalte auf oder wählen Sie einen Tarif.',
      self::VALIDATION_EMAIL_DAILY_LIMIT => 'Das tägliche E-Mail-Kontingent ist erreicht. @wording_plural: @count. Bitte versuchen Sie es später erneut.',
    ],
    'de-ls' => [
      self::VALIDATION_TIER_TOTAL => 'Das Kontingent ist erreicht. @wording_plural: @limit. Wählen Sie einen Tarif, um weiterzumachen.',
      self::VALIDATION_TIER_MONTHLY => 'Das monatliche Kontingent ist erreicht. @wording_plural: @limit. Wählen Sie einen Tarif oder versuchen Sie es nächsten Monat erneut.',
      self::VALIDATION_TIER_PUBLISHED => 'Das Kontingent für Veröffentlichungen ist erreicht. @wording_plural: @limit. Heben Sie die Veröffentlichung bestehender Inhalte auf oder wählen Sie einen Tarif.',
      self::VALIDATION_EMAIL_DAILY_LIMIT => 'Das tägliche E-Mail-Kontingent ist erreicht. @wording_plural: @count. Bitte versuchen Sie es später erneut.',
    ],
    'en' => [
      self::VALIDATION_TIER_TOTAL => 'The workspace limit has been reached. @wording_plural: @limit. Choose a plan to continue.',
      self::VALIDATION_TIER_MONTHLY => 'The monthly workspace limit has been reached. @wording_plural: @limit. Choose a plan or try again next month.',
      self::VALIDATION_TIER_PUBLISHED => 'The publication limit has been reached. @wording_plural: @limit. Unpublish existing items or choose a plan.',
      self::VALIDATION_EMAIL_DAILY_LIMIT => 'The daily email limit has been reached. @wording_plural: @count. Please try again later.',
    ],
    'es' => [
      self::VALIDATION_TIER_TOTAL => 'Se ha alcanzado el límite del espacio de trabajo. @wording_plural: @limit. Elija un plan para continuar.',
      self::VALIDATION_TIER_MONTHLY => 'Se ha alcanzado el límite mensual del espacio de trabajo. @wording_plural: @limit. Elija un plan o inténtelo de nuevo el próximo mes.',
      self::VALIDATION_TIER_PUBLISHED => 'Se ha alcanzado el límite de publicación. @wording_plural: @limit. Anule la publicación de elementos existentes o elija un plan.',
      self::VALIDATION_EMAIL_DAILY_LIMIT => 'Se ha alcanzado el límite diario de correo electrónico. @wording_plural: @count. Inténtelo de nuevo más tarde.',
    ],
    'fi' => [
      self::VALIDATION_TIER_TOTAL => 'Työtilan raja on saavutettu. @wording_plural: @limit. Valitse tilaus jatkaaksesi.',
      self::VALIDATION_TIER_MONTHLY => 'Työtilan kuukausiraja on saavutettu. @wording_plural: @limit. Valitse tilaus tai yritä uudelleen ensi kuussa.',
      self::VALIDATION_TIER_PUBLISHED => 'Julkaisuraja on saavutettu. @wording_plural: @limit. Peru nykyisten kohteiden julkaisu tai valitse tilaus.',
      self::VALIDATION_EMAIL_DAILY_LIMIT => 'Sähköpostin päiväraja on saavutettu. @wording_plural: @count. Yritä myöhemmin uudelleen.',
    ],
    'fr' => [
      self::VALIDATION_TIER_TOTAL => 'La limite de l’espace de travail est atteinte. @wording_plural : @limit. Choisissez une formule pour continuer.',
      self::VALIDATION_TIER_MONTHLY => 'La limite mensuelle de l’espace de travail est atteinte. @wording_plural : @limit. Choisissez une formule ou réessayez le mois prochain.',
      self::VALIDATION_TIER_PUBLISHED => 'La limite de publication est atteinte. @wording_plural : @limit. Dépubliez des éléments existants ou choisissez une formule.',
      self::VALIDATION_EMAIL_DAILY_LIMIT => 'La limite quotidienne d’e-mail est atteinte. @wording_plural : @count. Veuillez réessayer plus tard.',
    ],
    'hu' => [
      self::VALIDATION_TIER_TOTAL => 'Elérte a munkaterület korlátját. @wording_plural: @limit. A folytatáshoz válasszon csomagot.',
      self::VALIDATION_TIER_MONTHLY => 'Elérte a munkaterület havi korlátját. @wording_plural: @limit. Válasszon csomagot, vagy próbálja újra a következő hónapban.',
      self::VALIDATION_TIER_PUBLISHED => 'Elérte a közzétételi korlátot. @wording_plural: @limit. Vonja vissza a meglévő elemek közzétételét, vagy válasszon csomagot.',
      self::VALIDATION_EMAIL_DAILY_LIMIT => 'Elérte a napi e-mail-korlátot. @wording_plural: @count. Próbálja újra később.',
    ],
    'it' => [
      self::VALIDATION_TIER_TOTAL => 'È stato raggiunto il limite dello spazio di lavoro. @wording_plural: @limit. Scegli un piano per continuare.',
      self::VALIDATION_TIER_MONTHLY => 'È stato raggiunto il limite mensile dello spazio di lavoro. @wording_plural: @limit. Scegli un piano o riprova il mese prossimo.',
      self::VALIDATION_TIER_PUBLISHED => 'È stato raggiunto il limite di pubblicazione. @wording_plural: @limit. Annulla la pubblicazione degli elementi esistenti o scegli un piano.',
      self::VALIDATION_EMAIL_DAILY_LIMIT => 'È stato raggiunto il limite giornaliero delle e-mail. @wording_plural: @count. Riprova più tardi.',
    ],
    'nb' => [
      self::VALIDATION_TIER_TOTAL => 'Grensen for arbeidsområdet er nådd. @wording_plural: @limit. Velg et abonnement for å fortsette.',
      self::VALIDATION_TIER_MONTHLY => 'Den månedlige grensen for arbeidsområdet er nådd. @wording_plural: @limit. Velg et abonnement, eller prøv igjen neste måned.',
      self::VALIDATION_TIER_PUBLISHED => 'Publiseringsgrensen er nådd. @wording_plural: @limit. Avpubliser eksisterende elementer, eller velg et abonnement.',
      self::VALIDATION_EMAIL_DAILY_LIMIT => 'Den daglige e-postgrensen er nådd. @wording_plural: @count. Prøv igjen senere.',
    ],
    'nl' => [
      self::VALIDATION_TIER_TOTAL => 'De limiet van de werkruimte is bereikt. @wording_plural: @limit. Kies een abonnement om door te gaan.',
      self::VALIDATION_TIER_MONTHLY => 'De maandelijkse limiet van de werkruimte is bereikt. @wording_plural: @limit. Kies een abonnement of probeer het volgende maand opnieuw.',
      self::VALIDATION_TIER_PUBLISHED => 'De publicatielimiet is bereikt. @wording_plural: @limit. Maak bestaande items niet meer openbaar of kies een abonnement.',
      self::VALIDATION_EMAIL_DAILY_LIMIT => 'De dagelijkse e-maillimiet is bereikt. @wording_plural: @count. Probeer het later opnieuw.',
    ],
    'pl' => [
      self::VALIDATION_TIER_TOTAL => 'Osiągnięto limit obszaru roboczego. @wording_plural: @limit. Aby kontynuować, wybierz plan.',
      self::VALIDATION_TIER_MONTHLY => 'Osiągnięto miesięczny limit obszaru roboczego. @wording_plural: @limit. Wybierz plan lub spróbuj ponownie w przyszłym miesiącu.',
      self::VALIDATION_TIER_PUBLISHED => 'Osiągnięto limit publikacji. @wording_plural: @limit. Cofnij publikację istniejących elementów lub wybierz plan.',
      self::VALIDATION_EMAIL_DAILY_LIMIT => 'Osiągnięto dzienny limit e-maili. @wording_plural: @count. Spróbuj ponownie później.',
    ],
    'pt' => [
      self::VALIDATION_TIER_TOTAL => 'O limite do espaço de trabalho foi atingido. @wording_plural: @limit. Escolha um plano para continuar.',
      self::VALIDATION_TIER_MONTHLY => 'O limite mensal do espaço de trabalho foi atingido. @wording_plural: @limit. Escolha um plano ou tente novamente no próximo mês.',
      self::VALIDATION_TIER_PUBLISHED => 'O limite de publicação foi atingido. @wording_plural: @limit. Despublique itens existentes ou escolha um plano.',
      self::VALIDATION_EMAIL_DAILY_LIMIT => 'O limite diário de e-mail foi atingido. @wording_plural: @count. Tente novamente mais tarde.',
    ],
    'sv' => [
      self::VALIDATION_TIER_TOTAL => 'Arbetsytans gräns har nåtts. @wording_plural: @limit. Välj en plan för att fortsätta.',
      self::VALIDATION_TIER_MONTHLY => 'Arbetsytans månatliga gräns har nåtts. @wording_plural: @limit. Välj en plan eller försök igen nästa månad.',
      self::VALIDATION_TIER_PUBLISHED => 'Publiceringsgränsen har nåtts. @wording_plural: @limit. Avpublicera befintliga objekt eller välj en plan.',
      self::VALIDATION_EMAIL_DAILY_LIMIT => 'Den dagliga e-postgränsen har nåtts. @wording_plural: @count. Försök igen senare.',
    ],
    'tr' => [
      self::VALIDATION_TIER_TOTAL => 'Çalışma alanı sınırına ulaşıldı. @wording_plural: @limit. Devam etmek için bir plan seçin.',
      self::VALIDATION_TIER_MONTHLY => 'Çalışma alanının aylık sınırına ulaşıldı. @wording_plural: @limit. Bir plan seçin veya gelecek ay yeniden deneyin.',
      self::VALIDATION_TIER_PUBLISHED => 'Yayımlama sınırına ulaşıldı. @wording_plural: @limit. Mevcut ögelerin yayımlanmasını kaldırın veya bir plan seçin.',
      self::VALIDATION_EMAIL_DAILY_LIMIT => 'Günlük e-posta sınırına ulaşıldı. @wording_plural: @count. Lütfen daha sonra yeniden deneyin.',
    ],
    'uk' => [
      self::VALIDATION_TIER_TOTAL => 'Досягнуто ліміту робочого простору. @wording_plural: @limit. Виберіть план, щоб продовжити.',
      self::VALIDATION_TIER_MONTHLY => 'Досягнуто щомісячного ліміту робочого простору. @wording_plural: @limit. Виберіть план або повторіть спробу наступного місяця.',
      self::VALIDATION_TIER_PUBLISHED => 'Досягнуто ліміту публікації. @wording_plural: @limit. Зніміть з публікації наявні елементи або виберіть план.',
      self::VALIDATION_EMAIL_DAILY_LIMIT => 'Досягнуто денного ліміту електронної пошти. @wording_plural: @count. Будь ласка, спробуйте пізніше.',
    ],
  ];

  /**
   * Locale-complete duplicate-validation details and suffixes.
   *
   * The selected singular follows a neutral type label rather than a
   * language-specific article or case form. This keeps all four wording
   * presets safe in every supported locale.
   *
   * @var array<string, array<string, string>>
   */
  private const DUPLICATE_VALIDATION_MESSAGE_TEMPLATES = [
    'ar' => [
      self::VALIDATION_DUPLICATE_VISIBLE => 'عنصر مكرر محتمل في الفئة نفسها. النوع: @wording_singular. المعرّف: @id. المسافة: @radius @unit.',
      self::VALIDATION_DUPLICATE_HIDDEN => 'عنصر مكرر محتمل في الفئة نفسها. النوع: @wording_singular. المسافة: @radius @unit.',
      self::VALIDATION_DUPLICATE_HINT => 'يمكنك الإرسال مرة أخرى. يرجى مقارنة العنصر المكرر المحتمل: @wording_singular.',
      self::VALIDATION_DUPLICATE_BLOCK => 'شكراً لك. سنراجع هذا الموقع قريباً.',
    ],
    'cs' => [
      self::VALIDATION_DUPLICATE_VISIBLE => 'Možná duplicita ve stejné kategorii. Typ: @wording_singular. ID: @id. Vzdálenost: @radius @unit.',
      self::VALIDATION_DUPLICATE_HIDDEN => 'Možná duplicita ve stejné kategorii. Typ: @wording_singular. Vzdálenost: @radius @unit.',
      self::VALIDATION_DUPLICATE_HINT => 'Můžete odeslat znovu. Porovnejte prosím možnou duplicitu: @wording_singular.',
      self::VALIDATION_DUPLICATE_BLOCK => 'Děkujeme. Toto místo brzy prověříme.',
    ],
    'da' => [
      self::VALIDATION_DUPLICATE_VISIBLE => 'Mulig dublet i samme kategori. Type: @wording_singular. ID: @id. Afstand: @radius @unit.',
      self::VALIDATION_DUPLICATE_HIDDEN => 'Mulig dublet i samme kategori. Type: @wording_singular. Afstand: @radius @unit.',
      self::VALIDATION_DUPLICATE_HINT => 'Du kan indsende igen. Sammenlign venligst den mulige dublet: @wording_singular.',
      self::VALIDATION_DUPLICATE_BLOCK => 'Tak. Vi gennemgår snart dette sted.',
    ],
    'de' => [
      self::VALIDATION_DUPLICATE_VISIBLE => 'Mögliche Dublette in derselben Kategorie. Art: @wording_singular. ID: @id. Abstand: @radius @unit.',
      self::VALIDATION_DUPLICATE_HIDDEN => 'Mögliche Dublette in derselben Kategorie. Art: @wording_singular. Abstand: @radius @unit.',
      self::VALIDATION_DUPLICATE_HINT => 'Sie können erneut senden. Vergleichen Sie bitte die mögliche Dublette: @wording_singular.',
      self::VALIDATION_DUPLICATE_BLOCK => 'Vielen Dank. Wir prüfen diesen Ort in Kürze.',
    ],
    'de-ls' => [
      self::VALIDATION_DUPLICATE_VISIBLE => 'Mögliche doppelte Übermittlung in derselben Kategorie. Art: @wording_singular. ID: @id. Abstand: @radius @unit.',
      self::VALIDATION_DUPLICATE_HIDDEN => 'Mögliche doppelte Übermittlung in derselben Kategorie. Art: @wording_singular. Abstand: @radius @unit.',
      self::VALIDATION_DUPLICATE_HINT => 'Sie können noch einmal senden. Vergleichen Sie bitte die mögliche doppelte Übermittlung: @wording_singular.',
      self::VALIDATION_DUPLICATE_BLOCK => 'Vielen Dank. Wir prüfen diesen Ort bald.',
    ],
    'en' => [
      self::VALIDATION_DUPLICATE_VISIBLE => 'Possible duplicate in the same category. Type: @wording_singular. ID: @id. Distance: @radius @unit.',
      self::VALIDATION_DUPLICATE_HIDDEN => 'Possible duplicate in the same category. Type: @wording_singular. Distance: @radius @unit.',
      self::VALIDATION_DUPLICATE_HINT => 'You can submit again. Please compare the possible duplicate: @wording_singular.',
      self::VALIDATION_DUPLICATE_BLOCK => 'Thank you. We will review this location soon.',
    ],
    'es' => [
      self::VALIDATION_DUPLICATE_VISIBLE => 'Posible duplicado en la misma categoría. Tipo: @wording_singular. ID: @id. Distancia: @radius @unit.',
      self::VALIDATION_DUPLICATE_HIDDEN => 'Posible duplicado en la misma categoría. Tipo: @wording_singular. Distancia: @radius @unit.',
      self::VALIDATION_DUPLICATE_HINT => 'Puede enviarlo de nuevo. Compare el posible duplicado: @wording_singular.',
      self::VALIDATION_DUPLICATE_BLOCK => 'Gracias. Revisaremos este lugar pronto.',
    ],
    'fi' => [
      self::VALIDATION_DUPLICATE_VISIBLE => 'Mahdollinen kaksoiskappale samassa luokassa. Tyyppi: @wording_singular. Tunnus: @id. Etäisyys: @radius @unit.',
      self::VALIDATION_DUPLICATE_HIDDEN => 'Mahdollinen kaksoiskappale samassa luokassa. Tyyppi: @wording_singular. Etäisyys: @radius @unit.',
      self::VALIDATION_DUPLICATE_HINT => 'Voit lähettää uudelleen. Vertaa mahdollista kaksoiskappaletta: @wording_singular.',
      self::VALIDATION_DUPLICATE_BLOCK => 'Kiitos. Tarkistamme tämän sijainnin pian.',
    ],
    'fr' => [
      self::VALIDATION_DUPLICATE_VISIBLE => 'Doublon possible dans la même catégorie. Type : @wording_singular. ID : @id. Distance : @radius @unit.',
      self::VALIDATION_DUPLICATE_HIDDEN => 'Doublon possible dans la même catégorie. Type : @wording_singular. Distance : @radius @unit.',
      self::VALIDATION_DUPLICATE_HINT => 'Vous pouvez soumettre à nouveau. Comparez le doublon possible : @wording_singular.',
      self::VALIDATION_DUPLICATE_BLOCK => 'Merci. Nous examinerons bientôt cet emplacement.',
    ],
    'hu' => [
      self::VALIDATION_DUPLICATE_VISIBLE => 'Lehetséges ismétlődés ugyanabban a kategóriában. Típus: @wording_singular. Azonosító: @id. Távolság: @radius @unit.',
      self::VALIDATION_DUPLICATE_HIDDEN => 'Lehetséges ismétlődés ugyanabban a kategóriában. Típus: @wording_singular. Távolság: @radius @unit.',
      self::VALIDATION_DUPLICATE_HINT => 'Újra elküldheti. Hasonlítsa össze a lehetséges ismétlődést: @wording_singular.',
      self::VALIDATION_DUPLICATE_BLOCK => 'Köszönjük. Hamarosan ellenőrizzük ezt a helyet.',
    ],
    'it' => [
      self::VALIDATION_DUPLICATE_VISIBLE => 'Possibile duplicato nella stessa categoria. Tipo: @wording_singular. ID: @id. Distanza: @radius @unit.',
      self::VALIDATION_DUPLICATE_HIDDEN => 'Possibile duplicato nella stessa categoria. Tipo: @wording_singular. Distanza: @radius @unit.',
      self::VALIDATION_DUPLICATE_HINT => 'Puoi inviare di nuovo. Confronta il possibile duplicato: @wording_singular.',
      self::VALIDATION_DUPLICATE_BLOCK => 'Grazie. Esamineremo presto questa posizione.',
    ],
    'nb' => [
      self::VALIDATION_DUPLICATE_VISIBLE => 'Mulig duplikat i samme kategori. Type: @wording_singular. ID: @id. Avstand: @radius @unit.',
      self::VALIDATION_DUPLICATE_HIDDEN => 'Mulig duplikat i samme kategori. Type: @wording_singular. Avstand: @radius @unit.',
      self::VALIDATION_DUPLICATE_HINT => 'Du kan sende inn på nytt. Sammenlign det mulige duplikatet: @wording_singular.',
      self::VALIDATION_DUPLICATE_BLOCK => 'Takk. Vi vil snart gjennomgå dette stedet.',
    ],
    'nl' => [
      self::VALIDATION_DUPLICATE_VISIBLE => 'Mogelijk duplicaat in dezelfde categorie. Type: @wording_singular. ID: @id. Afstand: @radius @unit.',
      self::VALIDATION_DUPLICATE_HIDDEN => 'Mogelijk duplicaat in dezelfde categorie. Type: @wording_singular. Afstand: @radius @unit.',
      self::VALIDATION_DUPLICATE_HINT => 'Je kunt opnieuw indienen. Vergelijk het mogelijke duplicaat: @wording_singular.',
      self::VALIDATION_DUPLICATE_BLOCK => 'Bedankt. We beoordelen deze locatie binnenkort.',
    ],
    'pl' => [
      self::VALIDATION_DUPLICATE_VISIBLE => 'Możliwy duplikat w tej samej kategorii. Typ: @wording_singular. ID: @id. Odległość: @radius @unit.',
      self::VALIDATION_DUPLICATE_HIDDEN => 'Możliwy duplikat w tej samej kategorii. Typ: @wording_singular. Odległość: @radius @unit.',
      self::VALIDATION_DUPLICATE_HINT => 'Możesz wysłać ponownie. Porównaj możliwy duplikat: @wording_singular.',
      self::VALIDATION_DUPLICATE_BLOCK => 'Dziękujemy. Wkrótce sprawdzimy tę lokalizację.',
    ],
    'pt' => [
      self::VALIDATION_DUPLICATE_VISIBLE => 'Possível duplicado na mesma categoria. Tipo: @wording_singular. ID: @id. Distância: @radius @unit.',
      self::VALIDATION_DUPLICATE_HIDDEN => 'Possível duplicado na mesma categoria. Tipo: @wording_singular. Distância: @radius @unit.',
      self::VALIDATION_DUPLICATE_HINT => 'Pode enviar novamente. Compare o possível duplicado: @wording_singular.',
      self::VALIDATION_DUPLICATE_BLOCK => 'Obrigado. Analisaremos este local em breve.',
    ],
    'sv' => [
      self::VALIDATION_DUPLICATE_VISIBLE => 'Möjlig dubblett i samma kategori. Typ: @wording_singular. ID: @id. Avstånd: @radius @unit.',
      self::VALIDATION_DUPLICATE_HIDDEN => 'Möjlig dubblett i samma kategori. Typ: @wording_singular. Avstånd: @radius @unit.',
      self::VALIDATION_DUPLICATE_HINT => 'Du kan skicka in igen. Jämför den möjliga dubbletten: @wording_singular.',
      self::VALIDATION_DUPLICATE_BLOCK => 'Tack. Vi granskar snart den här platsen.',
    ],
    'tr' => [
      self::VALIDATION_DUPLICATE_VISIBLE => 'Aynı kategoride olası yineleme. Tür: @wording_singular. Kimlik: @id. Mesafe: @radius @unit.',
      self::VALIDATION_DUPLICATE_HIDDEN => 'Aynı kategoride olası yineleme. Tür: @wording_singular. Mesafe: @radius @unit.',
      self::VALIDATION_DUPLICATE_HINT => 'Yeniden gönderebilirsiniz. Olası yinelemeyi karşılaştırın: @wording_singular.',
      self::VALIDATION_DUPLICATE_BLOCK => 'Teşekkür ederiz. Bu konumu yakında inceleyeceğiz.',
    ],
    'uk' => [
      self::VALIDATION_DUPLICATE_VISIBLE => 'Можливий дублікат у тій самій категорії. Тип: @wording_singular. ID: @id. Відстань: @radius @unit.',
      self::VALIDATION_DUPLICATE_HIDDEN => 'Можливий дублікат у тій самій категорії. Тип: @wording_singular. Відстань: @radius @unit.',
      self::VALIDATION_DUPLICATE_HINT => 'Ви можете надіслати повторно. Порівняйте можливий дублікат: @wording_singular.',
      self::VALIDATION_DUPLICATE_BLOCK => 'Дякуємо. Незабаром ми перевіримо це місце.',
    ],
  ];

  /**
   * Resolves a preset plus its basic locale-aware term data.
   *
   * @return array{preset: string, locale: string, singular: string, plural: string}
   *   The valid preset, effective term locale, and noun forms.
   */
  public function resolve(?GroupInterface $jurisdiction, string $langcode = 'en'): array {
    $preset = $this->resolvePreset($jurisdiction);
    $locale = $this->resolveLocale($langcode);
    $terms = self::TERM_DATA[$locale][$preset];

    return [
      'preset' => $preset,
      'locale' => $locale,
      'singular' => $terms['singular'],
      'plural' => $terms['plural'],
    ];
  }

  /**
   * Formats a locale-correct citizen-facing validation detail.
   *
   * @param string $messageKey
   *   One of the VALIDATION_* message keys.
   * @param \Drupal\group\Entity\GroupInterface|null $jurisdiction
   *   The relevant jurisdiction, if the validated entity has one.
   * @param string $langcode
   *   The response/content language.
   *
   * @return string
   *   A template whose selected plural is already substituted. Numeric
   *   validation placeholders such as @limit and @count remain available for
   *   the violation builder to replace safely.
   */
  public function formatValidationMessage(string $messageKey, ?GroupInterface $jurisdiction, string $langcode = 'en'): string {
    $terms = $this->resolve($jurisdiction, $langcode);
    $locale = $terms['locale'];
    $template = self::VALIDATION_MESSAGE_TEMPLATES[$locale][$messageKey]
      ?? self::DUPLICATE_VALIDATION_MESSAGE_TEMPLATES[$locale][$messageKey]
      ?? self::VALIDATION_MESSAGE_TEMPLATES['en'][$messageKey]
      ?? self::DUPLICATE_VALIDATION_MESSAGE_TEMPLATES['en'][$messageKey]
      ?? self::VALIDATION_MESSAGE_TEMPLATES['en'][self::VALIDATION_TIER_TOTAL];

    return strtr($template, [
      '@wording_singular' => $terms['singular'],
      '@wording_plural' => $terms['plural'],
    ]);
  }

  /**
   * Resolves terms including sentence-start variants for mail copy.
   *
   * @return array{preset: string, locale: string, singular: string, plural: string, singular_title: string, plural_title: string}
   *   The shared noun forms plus variants safe at the beginning of a
   *   sentence or heading.
   */
  public function resolveMailTerms(?GroupInterface $jurisdiction, string $langcode = 'en'): array {
    $terms = $this->resolve($jurisdiction, $langcode);

    return $terms + [
      'singular_title' => Unicode::ucfirst($terms['singular']),
      'plural_title' => Unicode::ucfirst($terms['plural']),
    ];
  }

  /**
   * Replaces the explicit runtime placeholders used by shipped mail copy.
   *
   * This is intentionally opt-in: only templates containing one of the
   * constants above change. Operators' existing free-form templates are not
   * scanned or rewritten, and can use a placeholder deliberately when they
   * want the selected citizen terminology at runtime.
   */
  public function replaceMailPlaceholders(string $text, ?GroupInterface $jurisdiction, string $langcode = 'en'): string {
    if (!str_contains($text, '{{ citizen_term_')) {
      return $text;
    }

    $terms = $this->resolveMailTerms($jurisdiction, $langcode);
    return strtr($text, [
      self::MAIL_SINGULAR_PLACEHOLDER => $terms['singular'],
      self::MAIL_SINGULAR_TITLE_PLACEHOLDER => $terms['singular_title'],
      self::MAIL_PLURAL_PLACEHOLDER => $terms['plural'],
      self::MAIL_PLURAL_TITLE_PLACEHOLDER => $terms['plural_title'],
    ]);
  }

  /**
   * Resolves the configured jurisdiction group from a service request node.
   *
   * Mail hooks run before their branded builder can replace the original
   * plain-text message. Keeping this narrow reference lookup in the shared
   * terminology service lets those hooks use the same jurisdiction term
   * without depending on markaspot_mail's builder trait.
   */
  public function resolveJurisdictionFromNode(NodeInterface $node, string $jurisdictionGroupType = 'jur'): ?GroupInterface {
    if (!$node->hasField('field_jurisdiction')) {
      return NULL;
    }
    $field = $node->get('field_jurisdiction');
    if (!$field instanceof EntityReferenceFieldItemListInterface || $field->isEmpty()) {
      return NULL;
    }
    $target = $field->referencedEntities()[0] ?? NULL;
    $jurisdictionGroupType = trim($jurisdictionGroupType);
    if ($jurisdictionGroupType === '') {
      $jurisdictionGroupType = 'jur';
    }
    if (!$target instanceof GroupInterface
      || $target->getEntityTypeId() !== 'group'
      || $target->bundle() !== $jurisdictionGroupType) {
      return NULL;
    }
    return $target;
  }

  /**
   * Resolves only the active preset for callers that do not need term data.
   */
  public function resolvePreset(?GroupInterface $jurisdiction): string {
    if ($jurisdiction === NULL) {
      return self::DEFAULT_PRESET;
    }

    $config = $this->readConfig($jurisdiction);
    $i18n = $config['i18n'] ?? NULL;
    $preset = is_array($i18n) ? ($i18n['wording'] ?? NULL) : NULL;

    return self::isSupportedPreset($preset)
      ? $preset
      : self::DEFAULT_PRESET;
  }

  /**
   * Checks whether a value is one of the shared wording preset IDs.
   */
  public static function isSupportedPreset(mixed $preset): bool {
    return is_string($preset) && in_array($preset, self::PRESETS, TRUE);
  }

  /**
   * Reads the shared tenant config from its untranslated source entity.
   */
  private function readConfig(GroupInterface $jurisdiction): array {
    $source = $jurisdiction->getUntranslated();
    if (!$source instanceof GroupInterface
      || !$source->hasField('field_nuxt_config')
      || $source->get('field_nuxt_config')->isEmpty()) {
      return [];
    }

    $raw = $source->get('field_nuxt_config')->value;
    if (!is_string($raw) || $raw === '') {
      return [];
    }

    $decoded = json_decode($raw, TRUE);
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Returns the best term locale for a Drupal or BCP 47 language code.
   */
  private function resolveLocale(string $langcode): string {
    $locale = strtolower(str_replace('_', '-', trim($langcode)));
    if (isset(self::TERM_DATA[$locale])) {
      return $locale;
    }

    $baseLocale = explode('-', $locale, 2)[0];
    return isset(self::TERM_DATA[$baseLocale]) ? $baseLocale : 'en';
  }

}
