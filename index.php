<?php
/* Developer: Alindevx00x (https://alindevx00x.me) */
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/session.php';
olivar_start_session();

$supportedLanguages = ['fr', 'en', 'es', 'ar'];
if (isset($_GET['lang']) && is_string($_GET['lang']) && in_array($_GET['lang'], $supportedLanguages, true)) {
    $_SESSION['language'] = $_GET['lang'];
    header('Location: index.php#haut');
    exit;
}

$language = $_SESSION['language'] ?? 'fr';
if (!in_array($language, $supportedLanguages, true)) {
    $language = 'fr';
}
$translations = require __DIR__ . '/includes/translations.php';

function t(string $key): string
{
    global $translations, $language;
    return $translations[$language][$key] ?? $translations['fr'][$key] ?? $key;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function post_string(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;
    return is_string($value) ? trim($value) : $default;
}

function text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function valid_iso_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$formValues = [
    'name' => '',
    'email' => '',
    'phone' => '',
    'arrival' => '',
    'departure' => '',
    'guests' => '2',
    'message' => '',
];
$notice = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($formValues as $key => $default) {
        $formValues[$key] = post_string($key, $default);
    }

    $errors = [];
    $submittedToken = post_string('csrf_token');
    $isHoneypotFilled = post_string('secondary_contact') !== '';

    if (!hash_equals($_SESSION['csrf_token'], $submittedToken)) {
        $errors[] = t('form.error.csrf');
    }
    if ($isHoneypotFilled) {
        $errors[] = t('form.error.spam');
    }
    if ($formValues['name'] === '' || text_length($formValues['name']) > 120) {
        $errors[] = t('form.error.name');
    }
    if (!filter_var($formValues['email'], FILTER_VALIDATE_EMAIL) || strlen($formValues['email']) > 254) {
        $errors[] = t('form.error.email');
    }
    $phoneTrimmed = trim($formValues['phone']);
    if ($phoneTrimmed === '' || !preg_match('/^[0-9+\s().-]{6,40}$/', $phoneTrimmed)) {
        $errors[] = t('form.error.phone');
    }
    $arrivalIsValid = valid_iso_date($formValues['arrival']);
    $departureIsValid = valid_iso_date($formValues['departure']);
    if (!$arrivalIsValid || $formValues['arrival'] < $today) {
        $errors[] = t('form.error.arrival');
    }
    if (!$departureIsValid || ($arrivalIsValid && $formValues['departure'] <= $formValues['arrival'])) {
        $errors[] = t('form.error.departure');
    }
    if (!ctype_digit($formValues['guests']) || (int) $formValues['guests'] < 1 || (int) $formValues['guests'] > 5) {
        $errors[] = t('form.error.guests');
    }
    if (text_length($formValues['message']) > 2000) {
        $errors[] = t('form.error.message');
    }
    if (($_POST['consent'] ?? '') !== '1') {
        $errors[] = t('form.error.consent');
    }

    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

    if ($errors !== []) {
        if ($isAjax) {
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'message' => implode(' ', $errors),
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $notice = [
            'type' => 'error',
            'message' => implode(' ', $errors),
        ];
    } else {
        try {
            $pdo = database_connection();
            $statement = $pdo->prepare(
                'INSERT INTO stay_inquiries
                    (full_name, email, phone, arrival_date, departure_date, guests, message, consent_at)
                 VALUES
                    (:full_name, :email, :phone, :arrival_date, :departure_date, :guests, :message, CURRENT_TIMESTAMP)'
            );
            $statement->execute([
                ':full_name' => $formValues['name'],
                ':email' => $formValues['email'],
                ':phone' => $phoneTrimmed,
                ':arrival_date' => $formValues['arrival'],
                ':departure_date' => $formValues['departure'],
                ':guests' => (int) $formValues['guests'],
                ':message' => $formValues['message'] !== '' ? $formValues['message'] : null,
            ]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('Inquiry insert did not create exactly one row.');
            }

            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            if ($isAjax) {
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode([
                    'success' => true,
                    'message' => t('form.success'),
                    'new_csrf_token' => $_SESSION['csrf_token'],
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $_SESSION['flash'] = [
                'type' => 'success',
                'message' => t('form.success'),
            ];
            header('Location: index.php#contact');
            exit;
        } catch (Throwable $exception) {
            error_log('El Olivar inquiry save failed: ' . $exception->getMessage());
            if ($isAjax) {
                header('Content-Type: application/json; charset=UTF-8');
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'message' => t('form.unavailable'),
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $notice = [
                'type' => 'error',
                'message' => t('form.unavailable'),
            ];
        }
    }
}

$bookingUrl = 'https://www.booking.com/hotel/ma/el-olivar-casa-rural-ain-zarka-yn-lzrq.en-gb.html';
$airbnbUrl = 'https://www.airbnb.es/rooms/1224679573074878063';
$instagramUrl = 'https://www.instagram.com/el_olivar_zarqa/';
$googleMapsUrl = 'https://maps.app.goo.gl/K4xCdhYZC3KkJu1q8';

$whatsappNumber = '212600000000';
$whatsappEnabled = true;
try {
    $pdoSettings = database_connection();
    $stmtSettings = $pdoSettings->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('whatsapp_number', 'whatsapp_enabled')");
    $settingsList = $stmtSettings ? $stmtSettings->fetchAll(PDO::FETCH_KEY_PAIR) : [];
    if (isset($settingsList['whatsapp_number']) && trim((string) $settingsList['whatsapp_number']) !== '') {
        $whatsappNumber = trim((string) $settingsList['whatsapp_number']);
    }
    if (isset($settingsList['whatsapp_enabled'])) {
        $whatsappEnabled = ($settingsList['whatsapp_enabled'] === '1' || $settingsList['whatsapp_enabled'] === 'true' || $settingsList['whatsapp_enabled'] === 1);
    }
} catch (Throwable) {
    // Fallback gracieux si la base ou la table n'est pas encore accessible
}

$whatsappMessage = match ($language) {
    'ar' => 'السلام عليكم دار إل أوليفار، أود الاستفسار عن إمكانية الحجز لديكم.',
    'en' => 'Hello El Olivar, I would like to inquire about booking a stay.',
    'es' => 'Hola El Olivar, quisiera consultar disponibilidad para una estancia.',
    default => 'Bonjour El Olivar, je souhaite me renseigner pour une réservation.',
};
$whatsappUrl = 'https://wa.me/' . preg_replace('/\D/', '', $whatsappNumber) . '?text=' . rawurlencode($whatsappMessage);

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
    || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
$scheme = $isHttps ? 'https' : 'http';
$httpHost = $_SERVER['HTTP_HOST'] ?? 'elolivar.alindevx00x.me';
$siteBaseUrl = getenv('APP_URL') ?: ($scheme . '://' . rtrim($httpHost, '/'));
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$appRootUrl = $siteBaseUrl . ($scriptDir !== '' && $scriptDir !== '/' ? $scriptDir : '');
$shareImage = $appRootUrl . '/assets/images/exterior-mountain.jpg';
$canonicalUrl = $appRootUrl . '/';
$arrow = $language === 'ar' ? '↖' : '↗';
?>
<!doctype html>
<html lang="<?= h($language) ?>" dir="<?= $language === 'ar' ? 'rtl' : 'ltr' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f7f5ef">
    <meta name="description" content="<?= h(t('meta.description')) ?>">
    <title><?= h(t('meta.title')) ?></title>
    <link rel="icon" href="assets/icon.svg" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Alyamama:wght@300..900&family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@400;500;600&display=swap" rel="stylesheet">
    <?php
        $stylesCssPath = __DIR__ . '/assets/styles.css';
        $stylesCssVer = file_exists($stylesCssPath) ? (string) filemtime($stylesCssPath) : '1';
        $scriptJsPath = __DIR__ . '/assets/script.js';
        $scriptJsVer = file_exists($scriptJsPath) ? (string) filemtime($scriptJsPath) : '1';
    ?>
    <link rel="stylesheet" href="assets/styles.css?v=<?= $stylesCssVer ?>">

    <!-- Open Graph & Social Sharing -->
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="El Olivar Zarqa">
    <meta property="og:title" content="<?= h(t('meta.title')) ?>">
    <meta property="og:description" content="<?= h(t('meta.description')) ?>">
    <meta property="og:image" content="<?= h($shareImage) ?>">
    <meta property="og:url" content="<?= h($canonicalUrl) ?>">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= h(t('meta.title')) ?>">
    <meta name="twitter:description" content="<?= h(t('meta.description')) ?>">
    <meta name="twitter:image" content="<?= h($shareImage) ?>">

    <!-- Schema.org Structured Data -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": ["LodgingBusiness", "BedAndBreakfast"],
      "name": "El Olivar Zarqa",
      "url": <?= json_encode($canonicalUrl, JSON_UNESCAPED_SLASHES) ?>,
      "image": <?= json_encode($shareImage, JSON_UNESCAPED_SLASHES) ?>,
      "description": <?= json_encode(t('meta.description'), JSON_UNESCAPED_UNICODE) ?>,
      "address": {
        "@type": "PostalAddress",
        "streetAddress": "Ain Zarka",
        "addressLocality": "Tétouan",
        "addressRegion": "Tanger-Tétouan-Al Hoceïma",
        "postalCode": "93000",
        "addressCountry": "MA"
      },
      "geo": {
        "@type": "GeoCoordinates",
        "latitude": 35.5414,
        "longitude": -5.3211
      },
      "hasMap": "https://maps.app.goo.gl/K4xCdhYZC3KkJu1q8",
      "checkinTime": "15:00",
      "checkoutTime": "11:00",
      "numberOfRooms": 2,
      "amenityFeature": [
        { "@type": "LocationFeatureSpecification", "name": "Free Wi-Fi", "value": true },
        { "@type": "LocationFeatureSpecification", "name": "Free Private Parking", "value": true },
        { "@type": "LocationFeatureSpecification", "name": "Mountain View Terrace", "value": true },
        { "@type": "LocationFeatureSpecification", "name": "Private Kitchen", "value": true },
        { "@type": "LocationFeatureSpecification", "name": "Private Garden", "value": true }
      ],
      "sameAs": [
        "https://www.booking.com/hotel/ma/el-olivar-casa-rural-ain-zarka-yn-lzrq.en-gb.html",
        "https://www.airbnb.es/rooms/1224679573074878063",
        "https://www.instagram.com/el_olivar_zarqa/"
      ]
    }
    </script>
</head>
<body>
    <a class="skip-link" href="#contenu"><?= h(t('skip.content')) ?></a>
    <header class="site-header" id="haut">
        <div class="topline"><span><?= h(t('announcement')) ?></span><a class="topline__place" href="<?= h($googleMapsUrl) ?>" target="_blank" rel="noopener noreferrer"><?= h(t('location')) ?> <?= $arrow ?></a></div>
        <div class="header-inner">
            <a class="brand" href="#haut" aria-label="<?= h(t('brand.home')) ?>">
                <img class="brand__mark" src="assets/icon.svg" alt="">
                <span class="brand__text"><strong>EL OLIVAR</strong><small><?= h(t('brand.location')) ?></small></span>
            </a>
            <button class="menu-toggle" type="button" aria-label="<?= h(t('menu.open')) ?>" data-open-label="<?= h(t('menu.open')) ?>" data-close-label="<?= h(t('menu.close')) ?>" aria-expanded="false" aria-controls="main-navigation">
                <span class="menu-toggle__line"></span><span class="menu-toggle__line"></span><span class="menu-toggle__line"></span>
            </button>
            <nav class="main-nav" id="main-navigation" aria-label="<?= h(t('navigation.label')) ?>">
                <a href="#maison"><?= h(t('navigation.house')) ?></a>
                <a href="#galerie"><?= h(t('navigation.gallery')) ?></a>
                <a href="#alentours"><?= h(t('navigation.around')) ?></a>
                <a href="#contact"><?= h(t('navigation.contact')) ?></a>
            </nav>
            <details class="language-switcher">
                <summary aria-label="<?= h(t('language.label')) ?>">
                    <img src="assets/flags/<?= h($language) ?>.svg" alt="">
                    <span><?= strtoupper(h($language)) ?></span>
                    <span class="language-switcher__chevron" aria-hidden="true">⌄</span>
                </summary>
                <div class="language-switcher__options">
                    <a href="?lang=fr#haut" lang="fr" <?= $language === 'fr' ? 'aria-current="page"' : '' ?>><img src="assets/flags/fr.svg" alt=""><span>Français</span></a>
                    <a href="?lang=en#haut" lang="en" <?= $language === 'en' ? 'aria-current="page"' : '' ?>><img src="assets/flags/en.svg" alt=""><span>English</span></a>
                    <a href="?lang=es#haut" lang="es" <?= $language === 'es' ? 'aria-current="page"' : '' ?>><img src="assets/flags/es.svg" alt=""><span>Español</span></a>
                    <a href="?lang=ar#haut" lang="ar" dir="rtl" <?= $language === 'ar' ? 'aria-current="page"' : '' ?>><img src="assets/flags/ar.svg" alt=""><span>العربية</span></a>
                </div>
            </details>
            <a class="button button--nav" href="#reserver"><?= h(t('navigation.reserve')) ?> <span aria-hidden="true"><?= $arrow ?></span></a>
        </div>
    </header>

    <main id="contenu">
        <section class="hero" aria-labelledby="hero-title">
            <picture>
                <source srcset="assets/images/exterior-mountain.webp" type="image/webp">
                <img class="hero__image" src="assets/images/exterior-mountain.jpg" alt="<?= h(t('alt.house')) ?>" fetchpriority="high">
            </picture>
            <div class="hero__veil"></div>
            <div class="hero__content page-width">
                <p class="eyebrow eyebrow--light"><span class="eyebrow__dot"></span> <?= h(t('hero.eyebrow')) ?></p>
                <h1 id="hero-title"><?= h(t('hero.line1')) ?><br><?= h(t('hero.line2')) ?></h1>
                <p class="hero__intro"><?= h(t('hero.intro')) ?></p>
                <div class="hero__actions">
                    <a class="button button--cream" href="#reserver"><?= h(t('hero.cta')) ?> <span aria-hidden="true"><?= $arrow ?></span></a>
                    <a class="text-link text-link--light" href="#maison"><?= h(t('hero.discover')) ?> <span aria-hidden="true">↓</span></a>
                </div>
            </div>
            <div class="hero__caption"><span><?= h(t('hero.caption')) ?></span><span><?= h(t('hero.caption.location')) ?></span></div>
            <div class="hero__seal" aria-hidden="true"><span><?= h(t('hero.seal1')) ?></span><b>✳</b><span><?= h(t('hero.seal2')) ?></span></div>
        </section>

        <div class="quick-facts" aria-label="<?= h(t('facts.aria')) ?>">
            <div class="quick-facts__item"><span class="quick-facts__icon" aria-hidden="true">⌂</span><span><?= h(t('facts.house')) ?></span></div>
            <div class="quick-facts__item"><span class="quick-facts__icon" aria-hidden="true">⌑</span><span><?= h(t('facts.bedrooms')) ?></span></div>
            <div class="quick-facts__item"><span class="quick-facts__icon" aria-hidden="true">⌁</span><span><?= h(t('facts.terrace')) ?></span></div>
            <div class="quick-facts__item"><span class="quick-facts__icon" aria-hidden="true">⌖</span><span><?= h(t('facts.views')) ?></span></div>
        </div>

        <section class="intro section-pad" id="maison">
            <div class="intro__copy">
                <p class="eyebrow"><span class="eyebrow__dot"></span> <?= h(t('intro.eyebrow')) ?></p>
                <h2><?= h(t('intro.line1')) ?><br><?= h(t('intro.line2')) ?></h2>
                <p class="intro__lead"><?= h(t('intro.lead')) ?></p>
                <p><?= h(t('intro.body')) ?></p>
                <a class="text-link text-link--olive" href="#galerie"><?= h(t('intro.link')) ?> <span aria-hidden="true">→</span></a>
            </div>
            <div class="intro__visual">
                <figure class="intro__photo intro__photo--main">
                    <picture>
                        <source srcset="assets/images/garden-view.webp" type="image/webp">
                        <img src="assets/images/garden-view.jpg" alt="<?= h(t('alt.garden')) ?>" loading="lazy">
                    </picture>
                    <figcaption><span class="caption-dot"></span> <?= h(t('intro.caption')) ?></figcaption>
                </figure>
                <div class="intro__note"><span class="intro__note-mark">✳</span><span><?= nl2br(h(str_replace('|', "\n", t('intro.note')))) ?></span></div>
                <picture class="intro__photo-small">
                    <source srcset="assets/images/terrace-sunlight.webp" type="image/webp">
                    <img src="assets/images/terrace-sunlight.jpg" alt="<?= h(t('alt.terrace.small')) ?>" loading="lazy">
                </picture>
            </div>
        </section>

        <section class="gallery-section section-pad" id="galerie">
            <div class="section-heading">
                <div><p class="eyebrow"><span class="eyebrow__dot"></span> <?= h(t('gallery.eyebrow')) ?></p><h2><?= h(t('gallery.line1')) ?><br><em><?= h(t('gallery.line2')) ?></em></h2></div>
                <p><?= h(t('gallery.description')) ?></p>
            </div>
            <div class="gallery-grid" aria-label="<?= h(t('gallery.aria')) ?>">
                <!-- 1. Maison & paysage (Grande) -->
                <button class="gallery-tile gallery-tile--large" type="button" data-full="assets/images/exterior-mountain.jpg" data-alt="<?= h(t('alt.house')) ?>">
                    <picture>
                        <source srcset="assets/images/exterior-mountain.webp" type="image/webp">
                        <img src="assets/images/exterior-mountain.jpg" alt="<?= h(t('alt.house')) ?>" loading="lazy">
                    </picture>
                    <span class="gallery-tile__label"><?= h(t('gallery.house')) ?></span><span class="gallery-tile__zoom" aria-hidden="true"><?= $arrow ?></span>
                </button>

                <!-- 2. Salon vue jardin -->
                <button class="gallery-tile" type="button" data-full="assets/images/living-room-booking.jpg" data-alt="<?= h(t('alt.living')) ?>">
                    <picture>
                        <source srcset="assets/images/living-room-booking.webp" type="image/webp">
                        <img src="assets/images/living-room-booking.jpg" alt="<?= h(t('alt.living')) ?>" loading="lazy">
                    </picture>
                    <span class="gallery-tile__label"><?= h(t('gallery.living')) ?></span><span class="gallery-tile__zoom" aria-hidden="true"><?= $arrow ?></span>
                </button>

                <!-- 3. Première chambre -->
                <button class="gallery-tile" type="button" data-full="assets/images/bedroom.jpg" data-alt="<?= h(t('alt.bedroom')) ?>">
                    <picture>
                        <source srcset="assets/images/bedroom.webp" type="image/webp">
                        <img src="assets/images/bedroom.jpg" alt="<?= h(t('alt.bedroom')) ?>" loading="lazy">
                    </picture>
                    <span class="gallery-tile__label"><?= h(t('gallery.bedroom')) ?></span><span class="gallery-tile__zoom" aria-hidden="true"><?= $arrow ?></span>
                </button>

                <!-- 4. Deuxième chambre (lits jumeaux - Airbnb) -->
                <button class="gallery-tile" type="button" data-full="assets/images/bedroom-twin.jpg" data-alt="<?= h(t('alt.bedroom_twin')) ?>">
                    <picture>
                        <source srcset="assets/images/bedroom-twin.webp" type="image/webp">
                        <img src="assets/images/bedroom-twin.jpg" alt="<?= h(t('alt.bedroom_twin')) ?>" loading="lazy">
                    </picture>
                    <span class="gallery-tile__label"><?= h(t('gallery.bedroom_twin')) ?></span><span class="gallery-tile__zoom" aria-hidden="true"><?= $arrow ?></span>
                </button>

                <!-- 5. Salle de bain complète (Airbnb) -->
                <button class="gallery-tile" type="button" data-full="assets/images/bathroom.jpg" data-alt="<?= h(t('alt.bathroom')) ?>">
                    <picture>
                        <source srcset="assets/images/bathroom.webp" type="image/webp">
                        <img src="assets/images/bathroom.jpg" alt="<?= h(t('alt.bathroom')) ?>" loading="lazy">
                    </picture>
                    <span class="gallery-tile__label"><?= h(t('gallery.bathroom')) ?></span><span class="gallery-tile__zoom" aria-hidden="true"><?= $arrow ?></span>
                </button>

                <!-- 6. Pergola & terrasse extérieure (Large - Airbnb) -->
                <button class="gallery-tile gallery-tile--wide" type="button" data-full="assets/images/terrace-pergola.jpg" data-alt="<?= h(t('alt.terrace_pergola')) ?>">
                    <picture>
                        <source srcset="assets/images/terrace-pergola.webp" type="image/webp">
                        <img src="assets/images/terrace-pergola.jpg" alt="<?= h(t('alt.terrace_pergola')) ?>" loading="lazy">
                    </picture>
                    <span class="gallery-tile__label"><?= h(t('gallery.terrace_pergola')) ?></span><span class="gallery-tile__zoom" aria-hidden="true"><?= $arrow ?></span>
                </button>

                <!-- 7. Coin repas intérieur -->
                <button class="gallery-tile" type="button" data-full="assets/images/dining-area.jpg" data-alt="<?= h(t('alt.dining')) ?>">
                    <picture>
                        <source srcset="assets/images/dining-area.webp" type="image/webp">
                        <img src="assets/images/dining-area.jpg" alt="<?= h(t('alt.dining')) ?>" loading="lazy">
                    </picture>
                    <span class="gallery-tile__label"><?= h(t('gallery.dining')) ?></span><span class="gallery-tile__zoom" aria-hidden="true"><?= $arrow ?></span>
                </button>

                <!-- 8. Cuisine équipée -->
                <button class="gallery-tile" type="button" data-full="assets/images/kitchen.jpg" data-alt="<?= h(t('alt.kitchen')) ?>">
                    <picture>
                        <source srcset="assets/images/kitchen.webp" type="image/webp">
                        <img src="assets/images/kitchen.jpg" alt="<?= h(t('alt.kitchen')) ?>" loading="lazy">
                    </picture>
                    <span class="gallery-tile__label"><?= h(t('gallery.kitchen')) ?></span><span class="gallery-tile__zoom" aria-hidden="true"><?= $arrow ?></span>
                </button>

                <!-- 9. Grand salon marocain (Airbnb) -->
                <button class="gallery-tile" type="button" data-full="assets/images/traditional-salon.jpg" data-alt="<?= h(t('alt.traditional_salon')) ?>">
                    <picture>
                        <source srcset="assets/images/traditional-salon.webp" type="image/webp">
                        <img src="assets/images/traditional-salon.jpg" alt="<?= h(t('alt.traditional_salon')) ?>" loading="lazy">
                    </picture>
                    <span class="gallery-tile__label"><?= h(t('gallery.traditional_salon')) ?></span><span class="gallery-tile__zoom" aria-hidden="true"><?= $arrow ?></span>
                </button>

                <!-- 10. Patio & jardin arboré (Airbnb) -->
                <button class="gallery-tile" type="button" data-full="assets/images/patio-garden.jpg" data-alt="<?= h(t('alt.patio_garden')) ?>">
                    <picture>
                        <source srcset="assets/images/patio-garden.webp" type="image/webp">
                        <img src="assets/images/patio-garden.jpg" alt="<?= h(t('alt.patio_garden')) ?>" loading="lazy">
                    </picture>
                    <span class="gallery-tile__label"><?= h(t('gallery.patio_garden')) ?></span><span class="gallery-tile__zoom" aria-hidden="true"><?= $arrow ?></span>
                </button>

                <!-- 11. Espace repas extérieur (Large - Airbnb) -->
                <button class="gallery-tile gallery-tile--wide" type="button" data-full="assets/images/outdoor-dining.jpg" data-alt="<?= h(t('alt.outdoor_dining')) ?>">
                    <picture>
                        <source srcset="assets/images/outdoor-dining.webp" type="image/webp">
                        <img src="assets/images/outdoor-dining.jpg" alt="<?= h(t('alt.outdoor_dining')) ?>" loading="lazy">
                    </picture>
                    <span class="gallery-tile__label"><?= h(t('gallery.outdoor_dining')) ?></span><span class="gallery-tile__zoom" aria-hidden="true"><?= $arrow ?></span>
                </button>

                <!-- 12. Façade ensoleillée (Airbnb) -->
                <button class="gallery-tile" type="button" data-full="assets/images/villa-facade.jpg" data-alt="<?= h(t('alt.villa_facade')) ?>">
                    <picture>
                        <source srcset="assets/images/villa-facade.webp" type="image/webp">
                        <img src="assets/images/villa-facade.jpg" alt="<?= h(t('alt.villa_facade')) ?>" loading="lazy">
                    </picture>
                    <span class="gallery-tile__label"><?= h(t('gallery.villa_facade')) ?></span><span class="gallery-tile__zoom" aria-hidden="true"><?= $arrow ?></span>
                </button>

                <!-- 13. Terrasse ensoleillée -->
                <button class="gallery-tile" type="button" data-full="assets/images/terrace-sunlight.jpg" data-alt="<?= h(t('alt.terrace')) ?>">
                    <picture>
                        <source srcset="assets/images/terrace-sunlight.webp" type="image/webp">
                        <img src="assets/images/terrace-sunlight.jpg" alt="<?= h(t('alt.terrace')) ?>" loading="lazy">
                    </picture>
                    <span class="gallery-tile__label"><?= h(t('gallery.terrace')) ?></span><span class="gallery-tile__zoom" aria-hidden="true"><?= $arrow ?></span>
                </button>

                <!-- 14. Panorama spectaculaire vallée (Grande - Airbnb) -->
                <button class="gallery-tile gallery-tile--large" type="button" data-full="assets/images/panoramic-valley.jpg" data-alt="<?= h(t('alt.panoramic_valley')) ?>">
                    <picture>
                        <source srcset="assets/images/panoramic-valley.webp" type="image/webp">
                        <img src="assets/images/panoramic-valley.jpg" alt="<?= h(t('alt.panoramic_valley')) ?>" loading="lazy">
                    </picture>
                    <span class="gallery-tile__label"><?= h(t('gallery.panoramic_valley')) ?></span><span class="gallery-tile__zoom" aria-hidden="true"><?= $arrow ?></span>
                </button>

                <!-- 15. Soirée en terrasse au crépuscule -->
                <button class="gallery-tile" type="button" data-full="assets/images/terrace-evening.jpg" data-alt="<?= h(t('alt.evening')) ?>">
                    <picture>
                        <source srcset="assets/images/terrace-evening.webp" type="image/webp">
                        <img src="assets/images/terrace-evening.jpg" alt="<?= h(t('alt.evening')) ?>" loading="lazy">
                    </picture>
                    <span class="gallery-tile__label"><?= h(t('gallery.evening')) ?></span><span class="gallery-tile__zoom" aria-hidden="true"><?= $arrow ?></span>
                </button>

                <!-- 16. Crêtes et montagnes -->
                <button class="gallery-tile" type="button" data-full="assets/images/mountain-view.jpg" data-alt="<?= h(t('alt.mountain')) ?>">
                    <picture>
                        <source srcset="assets/images/mountain-view.webp" type="image/webp">
                        <img src="assets/images/mountain-view.jpg" alt="<?= h(t('alt.mountain')) ?>" loading="lazy">
                    </picture>
                    <span class="gallery-tile__label"><?= h(t('gallery.mountain')) ?></span><span class="gallery-tile__zoom" aria-hidden="true"><?= $arrow ?></span>
                </button>
            </div>
        </section>

        <section class="comfort section-pad">
            <div class="comfort__head"><p class="eyebrow eyebrow--light"><span class="eyebrow__dot"></span> <?= h(t('comfort.eyebrow')) ?></p><h2><?= h(t('comfort.line1')) ?><br><?= h(t('comfort.line2')) ?></h2><p><?= h(t('comfort.intro')) ?></p></div>
            <div class="comfort-grid">
                <article class="comfort-card"><span class="comfort-card__number">01</span><span class="line-icon" aria-hidden="true">⌂</span><h3><?= h(t('comfort.one.title')) ?></h3><p><?= h(t('comfort.one.body')) ?></p></article>
                <article class="comfort-card"><span class="comfort-card__number">02</span><span class="line-icon" aria-hidden="true">◒</span><h3><?= h(t('comfort.two.title')) ?></h3><p><?= h(t('comfort.two.body')) ?></p></article>
                <article class="comfort-card"><span class="comfort-card__number">03</span><span class="line-icon" aria-hidden="true">☼</span><h3><?= h(t('comfort.three.title')) ?></h3><p><?= h(t('comfort.three.body')) ?></p></article>
                <article class="comfort-card"><span class="comfort-card__number">04</span><span class="line-icon" aria-hidden="true">⌁</span><h3><?= h(t('comfort.four.title')) ?></h3><p><?= h(t('comfort.four.body')) ?></p></article>
            </div>
        </section>

        <section class="surroundings section-pad" id="alentours">
            <div class="surroundings__visual">
                <picture>
                    <source srcset="assets/images/mountain-view.webp" type="image/webp">
                    <img src="assets/images/mountain-view.jpg" alt="<?= h(t('alt.surroundings')) ?>" loading="lazy">
                </picture>
                <div class="surroundings__stamp"><span><?= h(t('surroundings.stamp.north')) ?></span><b><?= h(t('surroundings.stamp.of')) ?></b><span><?= h(t('surroundings.stamp.country')) ?></span><i aria-hidden="true">✳</i></div>
            </div>
            <div class="surroundings__copy">
                <p class="eyebrow"><span class="eyebrow__dot"></span> <?= h(t('surroundings.eyebrow')) ?></p>
                <h2><?= h(t('surroundings.line1')) ?><br><?= h(t('surroundings.line2')) ?></h2>
                <p class="surroundings__lead"><?= h(t('surroundings.intro')) ?></p>
                <div class="place-list">
                    <div class="place-list__item"><span class="place-list__marker">01</span><div><strong><?= h(t('surroundings.pool')) ?></strong><span><?= h(t('surroundings.pool.distance')) ?></span></div><span class="place-list__arrow" aria-hidden="true"><?= $arrow ?></span></div>
                    <div class="place-list__item"><span class="place-list__marker">02</span><div><strong><?= h(t('surroundings.city')) ?></strong><span><?= h(t('surroundings.city.distance')) ?></span></div><span class="place-list__arrow" aria-hidden="true"><?= $arrow ?></span></div>
                </div>
                <a class="text-link text-link--olive" href="<?= h($googleMapsUrl) ?>" target="_blank" rel="noopener noreferrer"><?= h(t('surroundings.map')) ?> <span aria-hidden="true"><?= $arrow ?></span></a>
            </div>
        </section>

        <section class="booking section-pad" id="reserver">
            <div class="booking__panel">
                <div class="booking__copy">
                    <p class="eyebrow eyebrow--light"><span class="eyebrow__dot"></span> <?= h(t('booking.eyebrow')) ?></p>
                    <h2><?= h(t('booking.line1')) ?><br><?= h(t('booking.line2')) ?></h2>
                    <p><?= h(t('booking.intro')) ?></p>
                    <div class="booking__note"><span aria-hidden="true">✳</span> <?= h(t('booking.note')) ?></div>
                </div>
                <div class="booking-options">
                    <a class="booking-option" href="<?= h($bookingUrl) ?>" target="_blank" rel="noopener noreferrer">
                        <span class="booking-option__top"><span class="booking-option__logo booking-option__logo--booking"><img src="assets/booking.svg" alt="Booking.com" width="24" height="24"></span><span class="booking-option__arrow" aria-hidden="true"><?= $arrow ?></span></span>
                        <strong><?= h(t('booking.title')) ?></strong><span><?= h(t('booking.detail')) ?></span>
                    </a>
                    <a class="booking-option" href="<?= h($airbnbUrl) ?>" target="_blank" rel="noopener noreferrer">
                        <span class="booking-option__top"><span class="booking-option__logo booking-option__logo--airbnb"><img src="assets/airbnb.svg" alt="Airbnb" width="22" height="22"></span><span class="booking-option__arrow" aria-hidden="true"><?= $arrow ?></span></span>
                        <strong><?= h(t('airbnb.title')) ?></strong><span><?= h(t('airbnb.detail')) ?></span>
                    </a>
                    <a class="booking-option booking-option--instagram" href="<?= h($instagramUrl) ?>" target="_blank" rel="noopener noreferrer">
                        <span class="booking-option__top"><span class="booking-option__logo booking-option__logo--instagram"><img src="assets/instagram.svg" alt="Instagram" width="22" height="22"></span><span class="booking-option__arrow" aria-hidden="true"><?= $arrow ?></span></span>
                        <strong>@el_olivar_zarqa</strong><span><?= h(t('booking.social')) ?></span>
                    </a>
                </div>
            </div>
        </section>

        <section class="contact section-pad" id="contact">
            <div class="contact__intro">
                <p class="eyebrow"><span class="eyebrow__dot"></span> <?= h(t('contact.eyebrow')) ?></p>
                <h2><?= h(t('contact.line1')) ?><br><?= h(t('contact.line2')) ?></h2>
                <p><?= h(t('contact.intro')) ?></p>
                <div class="contact__small-note"><span aria-hidden="true">✳</span> <?= h(t('contact.hours')) ?></div>
            </div>
            <form class="inquiry-form" action="index.php#contact" method="post" autocomplete="on">
                <?php if (is_array($notice)): ?>
                    <?php $noticeType = ($notice['type'] ?? 'error') === 'success' ? 'success' : 'error'; ?>
                    <div class="form-notice form-notice--<?= h($noticeType) ?>" role="<?= $noticeType === 'error' ? 'alert' : 'status' ?>" aria-live="<?= $noticeType === 'error' ? 'assertive' : 'polite' ?>"><?= h((string) ($notice['message'] ?? '')) ?></div>
                <?php endif; ?>
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <div class="form-trap" aria-hidden="true"><label for="secondary-contact"><?= h(t('form.trap')) ?></label><input id="secondary-contact" name="secondary_contact" type="text" tabindex="-1" autocomplete="new-password" autocapitalize="off" spellcheck="false"></div>
                <div class="form-row">
                    <div class="field"><label for="name"><?= h(t('form.name')) ?></label><input id="name" name="name" type="text" maxlength="120" value="<?= h($formValues['name']) ?>" autocomplete="name" required></div>
                    <div class="field"><label for="email"><?= h(t('form.email')) ?></label><input id="email" name="email" type="email" maxlength="254" value="<?= h($formValues['email']) ?>" autocomplete="email" required></div>
                </div>
                <div class="form-row">
                    <div class="field"><label for="phone"><?= h(t('form.phone')) ?></label><input id="phone" name="phone" type="tel" maxlength="40" value="<?= h($formValues['phone']) ?>" autocomplete="tel" placeholder="<?= h(t('form.phone.placeholder')) ?>" required></div>
                    <div class="field"><label for="guests"><?= h(t('form.guests')) ?></label><select id="guests" name="guests">
                        <?php for ($guestCount = 1; $guestCount <= 5; $guestCount++): ?>
                            <option value="<?= $guestCount ?>" <?= $formValues['guests'] === (string) $guestCount ? 'selected' : '' ?>><?= $guestCount ?> <?= h(t($guestCount === 1 ? 'form.guest.one' : 'form.guest.other')) ?></option>
                        <?php endfor; ?>
                    </select></div>
                </div>
                <div class="form-row form-row--dates">
                    <div class="field"><label for="arrival"><?= h(t('form.arrival')) ?></label><input id="arrival" name="arrival" type="date" min="<?= h($today) ?>" value="<?= h($formValues['arrival']) ?>" required></div>
                    <div class="field"><label for="departure"><?= h(t('form.departure')) ?></label><input id="departure" name="departure" type="date" min="<?= h($today) ?>" value="<?= h($formValues['departure']) ?>" required></div>
                </div>
                <div class="field"><label for="message"><?= h(t('form.message')) ?> <span><?= h(t('form.optional')) ?></span></label><textarea id="message" name="message" rows="3" maxlength="2000" placeholder="<?= h(t('form.placeholder')) ?>"><?= h($formValues['message']) ?></textarea></div>
                <label class="consent"><input type="checkbox" name="consent" value="1" required <?= ($_POST['consent'] ?? '') === '1' ? 'checked' : '' ?>><span><?= h(t('form.consent')) ?></span></label>
                <button class="button button--olive inquiry-form__submit" type="submit"><?= h(t('form.submit')) ?> <span aria-hidden="true"><?= $arrow ?></span></button>
                <p class="inquiry-form__privacy"><?= h(t('form.privacy')) ?></p>
            </form>
        </section>

        <section class="faq section-pad" aria-labelledby="faq-title">
            <div class="faq__heading"><p class="eyebrow"><span class="eyebrow__dot"></span> <?= h(t('faq.eyebrow')) ?></p><h2 id="faq-title"><?= h(t('faq.title')) ?></h2></div>
            <div class="faq-list">
                <details><summary><?= h(t('faq.one.question')) ?> <span aria-hidden="true">+</span></summary><p><?= h(t('faq.one.answer')) ?></p></details>
                <details><summary><?= h(t('faq.two.question')) ?> <span aria-hidden="true">+</span></summary><p><?= h(t('faq.two.answer')) ?></p></details>
                <details><summary><?= h(t('faq.three.question')) ?> <span aria-hidden="true">+</span></summary><p><?= h(t('faq.three.answer')) ?></p></details>
            </div>
        </section>
    </main>

    <footer class="site-footer">
        <div class="footer-main page-width">
            <a class="brand brand--footer" href="#haut" aria-label="<?= h(t('brand.top')) ?>">
                <img class="brand__mark" src="assets/icon.svg" alt="">
                <span class="brand__text"><strong>EL OLIVAR</strong><small><?= h(t('brand.location')) ?></small></span>
            </a>
            <p><?= nl2br(h(str_replace('|', "\n", t('footer.tagline')))) ?></p>
            <div class="footer-links"><a href="<?= h($bookingUrl) ?>" target="_blank" rel="noopener noreferrer"><img class="footer-link__icon" src="assets/booking.svg" alt="" width="14" height="14"> <?= h(t('footer.booking')) ?></a><a href="<?= h($airbnbUrl) ?>" target="_blank" rel="noopener noreferrer"><img class="footer-link__icon" src="assets/airbnb.svg" alt="" width="14" height="14"> <?= h(t('footer.airbnb')) ?></a><a href="<?= h($instagramUrl) ?>" target="_blank" rel="noopener noreferrer"><img class="footer-link__icon" src="assets/instagram.svg" alt="" width="14" height="14"> @el_olivar_zarqa <?= $arrow ?></a></div>
            <a class="back-top" href="#haut"><?= h(t('footer.back')) ?> <span aria-hidden="true">↑</span></a>
        </div>
        <div class="footer-bottom page-width"><span><?= h(str_replace('{year}', date('Y'), t('footer.copyright'))) ?></span><a class="footer-location-link" href="<?= h($googleMapsUrl) ?>" target="_blank" rel="noopener noreferrer"><?= h(t('footer.location')) ?> <?= $arrow ?></a><span><?= h(t('footer.made')) ?> <i aria-hidden="true">✳</i></span><a class="developer-credit" href="https://alindevx00x.me" target="_blank" rel="noopener noreferrer"><?= h(t('footer.developer')) ?> <?= $arrow ?></a></div>
    </footer>

    <?php if ($whatsappEnabled): ?>
    <aside class="whatsapp-float-wrapper">
        <a class="whatsapp-float" href="<?= h($whatsappUrl) ?>" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp" data-base-url="https://wa.me/<?= preg_replace('/\D/', '', $whatsappNumber) ?>" data-default-msg="<?= h($whatsappMessage) ?>" title="<?= $language === 'ar' ? 'تواصل معنا مباشرة عبر واتساب' : 'Discuter sur WhatsApp' ?>">
            <img src="assets/whatsapp.svg" alt="WhatsApp" width="28" height="28">
            <span class="whatsapp-float__label"><?= $language === 'ar' ? 'تواصل عبر واتساب' : 'Discuter sur WhatsApp' ?></span>
        </a>
    </aside>
    <?php endif; ?>

    <dialog class="lightbox" aria-label="<?= h(t('gallery.lightbox')) ?>">
        <button class="lightbox__close" type="button" aria-label="<?= h(t('gallery.close')) ?>">×</button>
        <button class="lightbox__previous" type="button" aria-label="<?= h(t('gallery.previous')) ?>">←</button>
        <figure><img src="" alt=""><figcaption></figcaption></figure>
        <button class="lightbox__next" type="button" aria-label="<?= h(t('gallery.next')) ?>">→</button>
    </dialog>

    <script src="assets/script.js?v=<?= $scriptJsVer ?>" defer></script>
</body>
</html>
