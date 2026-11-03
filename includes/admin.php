<?php
/* Developer: Alindevx00x (https://alindevx00x.me) */
declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/../config/database.php';

olivar_start_session();

function admin_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function admin_csrf_token(): string
{
    if (empty($_SESSION['admin_csrf_token'])) {
        $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['admin_csrf_token'];
}

function admin_csrf_is_valid(): bool
{
    $submitted = $_POST['csrf_token'] ?? '';

    return is_string($submitted)
        && isset($_SESSION['admin_csrf_token'])
        && hash_equals((string) $_SESSION['admin_csrf_token'], $submitted);
}

function admin_is_signed_in(): bool
{
    return isset($_SESSION['admin_user_id'], $_SESSION['admin_username']);
}

function admin_require_login(): void
{
    if (!admin_is_signed_in()) {
        header('Location: index.php');
        exit;
    }
}

function admin_csv_safe(string $value): string
{
    if (preg_match('/^[\s\x00-\x1F]*[=+@-]/u', $value) === 1) {
        return "'" . $value;
    }

    return $value;
}

function admin_google_calendar_url(array $request): string
{
    $fullName = (string) ($request['full_name'] ?? '');
    $guests = (int) ($request['guests'] ?? 1);
    $email = (string) ($request['email'] ?? '');
    $arrival = (string) ($request['arrival_date'] ?? '');
    $departure = (string) ($request['departure_date'] ?? '');
    $message = (string) ($request['message'] ?? '');
    $id = (int) ($request['id'] ?? 0);
    $createdAt = (string) ($request['created_at'] ?? '');

    $arrClean = str_replace('-', '', $arrival);
    $depClean = str_replace('-', '', $departure);
    if ($arrClean >= $depClean && $arrival !== '') {
        try {
            $depObj = new DateTimeImmutable($arrival);
            $depClean = $depObj->modify('+1 day')->format('Ymd');
        } catch (Throwable) {
            $depClean = $arrClean;
        }
    }

    $title = 'Séjour El Olivar : ' . $fullName . ' (' . $guests . ' pers.)';
    $details = "Réservation El Olivar Zarqa\n"
        . "------------------------------\n"
        . "Client : " . $fullName . "\n"
        . "Email : " . $email . "\n"
        . "Voyageurs : " . $guests . "\n"
        . "Dates : " . $arrival . " -> " . $departure . "\n"
        . "Message :\n" . ($message !== '' ? $message : '(Aucun message)') . "\n"
        . "------------------------------\n"
        . "Demande #" . $id . " reçue le " . $createdAt . "\n"
        . "Lien Google Maps : https://maps.app.goo.gl/K4xCdhYZC3KkJu1q8";
    $location = 'El Olivar, Ain Zarka, Tétouan, Maroc';

    return 'https://calendar.google.com/calendar/render?' . http_build_query([
        'action' => 'TEMPLATE',
        'text' => $title,
        'dates' => $arrClean . '/' . $depClean,
        'details' => $details,
        'location' => $location,
    ]);
}
