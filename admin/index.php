<?php
/* Developer: Alindevx00x (https://alindevx00x.me) */
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
$pdo = null;
$databaseReady = false;
$adminCount = 0;
$databaseError = '';
$error = '';
$notice = is_string($_SESSION['admin_notice'] ?? null) ? $_SESSION['admin_notice'] : '';
unset($_SESSION['admin_notice']);

try {
    $pdo = database_connection();
    $adminCount = (int) $pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
    $databaseReady = true;
} catch (Throwable $exception) {
    error_log('El Olivar admin page unavailable: ' . $exception->getMessage());
    $databaseError = 'Impossible d’accéder aux comptes administrateur. Importez le fichier database.sql mis à jour dans phpMyAdmin, puis rechargez cette page.';
}

if (!admin_is_signed_in() && $_SERVER['REQUEST_METHOD'] === 'POST' && $databaseReady && $adminCount > 0) {
    $username = trim(is_string($_POST['username'] ?? null) ? $_POST['username'] : '');
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $blockedUntil = (int) ($_SESSION['admin_login_blocked_until'] ?? 0);

    if (!admin_csrf_is_valid()) {
        $error = 'La page a expiré. Rechargez-la puis réessayez.';
    } elseif ($blockedUntil > time()) {
        $error = 'Trop de tentatives. Attendez quelques minutes avant de réessayer.';
    } elseif (strlen($username) > 80 || strlen($password) > 255) {
        $error = 'Identifiant ou mot de passe incorrect.';
    } else {
        try {
            $statement = $pdo->prepare('SELECT id, username, password_hash FROM admin_users WHERE username = :username LIMIT 1');
            $statement->execute([':username' => $username]);
            $admin = $statement->fetch();

            if (is_array($admin) && password_verify($password, (string) $admin['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['admin_user_id'] = (int) $admin['id'];
                $_SESSION['admin_username'] = (string) $admin['username'];
                $_SESSION['admin_login_attempts'] = 0;
                unset($_SESSION['admin_login_blocked_until']);
                $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
                header('Location: index.php');
                exit;
            }

            $attempts = (int) ($_SESSION['admin_login_attempts'] ?? 0) + 1;
            if ($attempts >= 5) {
                $_SESSION['admin_login_attempts'] = 0;
                $_SESSION['admin_login_blocked_until'] = time() + 300;
                $error = 'Trop de tentatives. Attendez cinq minutes avant de réessayer.';
            } else {
                $_SESSION['admin_login_attempts'] = $attempts;
                $error = 'Identifiant ou mot de passe incorrect.';
            }
        } catch (Throwable $exception) {
            error_log('El Olivar admin login failed: ' . $exception->getMessage());
            $error = 'La connexion est momentanément indisponible. Réessayez plus tard.';
        }
    }
}

$requests = [];
$totalRequests = 0;
$currentPage = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = 50;
$pageCount = 1;
$dashboardError = '';

if (admin_is_signed_in() && $databaseReady && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $adminAction = (string) ($_POST['admin_action'] ?? '');
    if ($adminAction !== '') {
        header('Content-Type: application/json; charset=UTF-8');
        if (!admin_csrf_is_valid()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Session expirée. Rechargez la page.']);
            exit;
        }

        if ($adminAction === 'update_status') {
            $inquiryId = (int) ($_POST['id'] ?? 0);
            $newStatus = (string) ($_POST['status'] ?? '');
            if (!in_array($newStatus, ['new', 'contacted', 'closed'], true) || $inquiryId <= 0) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Statut ou identifiant invalide.']);
                exit;
            }
            $stmt = $pdo->prepare('UPDATE stay_inquiries SET status = :status WHERE id = :id');
            $stmt->execute([':status' => $newStatus, ':id' => $inquiryId]);
            echo json_encode(['success' => true, 'id' => $inquiryId, 'status' => $newStatus]);
            exit;
        }

        if ($adminAction === 'update_note') {
            $inquiryId = (int) ($_POST['id'] ?? 0);
            $note = trim((string) ($_POST['note'] ?? ''));
            if ($inquiryId <= 0 || strlen($note) > 5000) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Note trop longue ou identifiant invalide.']);
                exit;
            }
            $stmt = $pdo->prepare('UPDATE stay_inquiries SET admin_notes = :note WHERE id = :id');
            $stmt->execute([':note' => $note !== '' ? $note : null, ':id' => $inquiryId]);
            echo json_encode(['success' => true, 'id' => $inquiryId, 'note' => $note]);
            exit;
        }

        if ($adminAction === 'change_password') {
            $currentPass = (string) ($_POST['current_password'] ?? '');
            $newPass = (string) ($_POST['new_password'] ?? '');
            $confirmPass = (string) ($_POST['confirm_password'] ?? '');
            $adminId = (int) ($_SESSION['admin_user_id'] ?? 0);

            if (strlen($newPass) < 8) {
                echo json_encode(['success' => false, 'message' => 'Le nouveau mot de passe doit comporter au moins 8 caractères.']);
                exit;
            }
            if ($newPass !== $confirmPass) {
                echo json_encode(['success' => false, 'message' => 'Les deux nouveaux mots de passe ne correspondent pas.']);
                exit;
            }

            $stmt = $pdo->prepare('SELECT password_hash FROM admin_users WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $adminId]);
            $user = $stmt->fetch();

            if (!is_array($user) || !password_verify($currentPass, (string) $user['password_hash'])) {
                echo json_encode(['success' => false, 'message' => 'Le mot de passe actuel est incorrect.']);
                exit;
            }

            $newHash = password_hash($newPass, PASSWORD_DEFAULT);
            $updateStmt = $pdo->prepare('UPDATE admin_users SET password_hash = :hash WHERE id = :id');
            $updateStmt->execute([':hash' => $newHash, ':id' => $adminId]);

            echo json_encode(['success' => true, 'message' => 'Votre mot de passe a été modifié avec succès !']);
            exit;
        }
    }
}

if (admin_is_signed_in() && $databaseReady) {
    try {
        if (($_GET['export'] ?? '') === 'csv') {
            $exportRows = $pdo->query(
                'SELECT id, full_name, email, arrival_date, departure_date, guests, message, consent_at, status, admin_notes, created_at
                 FROM stay_inquiries ORDER BY created_at DESC, id DESC'
            )->fetchAll();

            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="el-olivar-demandes-' . date('Y-m-d') . '.csv"');
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new RuntimeException('Unable to open CSV output stream.');
            }
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['ID', 'Nom complet', 'E-mail', 'Arrivée', 'Départ', 'Voyageurs', 'Message', 'Consentement le', 'Statut', 'Note interne', 'Demande reçue le'], ',', '"', '');
            foreach ($exportRows as $row) {
                fputcsv($output, [
                    (int) $row['id'],
                    admin_csv_safe((string) $row['full_name']),
                    admin_csv_safe((string) $row['email']),
                    (string) $row['arrival_date'],
                    (string) $row['departure_date'],
                    (int) $row['guests'],
                    admin_csv_safe((string) ($row['message'] ?? '')),
                    (string) $row['consent_at'],
                    (string) $row['status'],
                    admin_csv_safe((string) ($row['admin_notes'] ?? '')),
                    (string) $row['created_at'],
                ], ',', '"', '');
            }
            fclose($output);
            exit;
        }

        if (($_GET['export'] ?? '') === 'gcal_ics') {
            $idsParam = trim(is_string($_GET['ids'] ?? null) ? $_GET['ids'] : '');
            $selectedIds = array_values(array_filter(array_map('intval', explode(',', $idsParam)), static fn($v) => $v > 0));

            if ($selectedIds !== []) {
                $inClause = implode(',', array_fill(0, count($selectedIds), '?'));
                $icsStmt = $pdo->prepare("SELECT * FROM stay_inquiries WHERE id IN ($inClause) ORDER BY arrival_date ASC, id ASC");
                $icsStmt->execute($selectedIds);
                $events = $icsStmt->fetchAll();
            } else {
                $events = $pdo->query('SELECT * FROM stay_inquiries ORDER BY arrival_date ASC, id ASC')->fetchAll();
            }

            header('Content-Type: text/calendar; charset=UTF-8');
            header('Content-Disposition: attachment; filename="el-olivar-google-calendar-' . date('Y-m-d') . '.ics"');

            $out = "BEGIN:VCALENDAR\r\n";
            $out .= "VERSION:2.0\r\n";
            $out .= "PRODID:-//El Olivar Zarqa//Reservations//FR\r\n";
            $out .= "CALSCALE:GREGORIAN\r\n";
            $out .= "METHOD:PUBLISH\r\n";
            $out .= "X-WR-CALNAME:El Olivar Zarqa - Réservations\r\n";
            $out .= "X-WR-TIMEZONE:Africa/Casablanca\r\n";

            foreach ($events as $ev) {
                $arrClean = str_replace('-', '', (string) $ev['arrival_date']);
                $depClean = str_replace('-', '', (string) $ev['departure_date']);
                if ($arrClean >= $depClean) {
                    try {
                        $depObj = new DateTimeImmutable((string) $ev['arrival_date']);
                        $depClean = $depObj->modify('+1 day')->format('Ymd');
                    } catch (Throwable) {
                        $depClean = $arrClean;
                    }
                }
                $createdStamp = gmdate('Ymd\THis\Z', strtotime((string) $ev['created_at']));
                $uid = 'el-olivar-' . (int) $ev['id'] . '-' . $arrClean . '@elolivarzarqa.com';
                $summary = 'Séjour El Olivar : ' . addcslashes((string) $ev['full_name'], ",;\\") . ' (' . (int) $ev['guests'] . ' pers.)';
                $desc = "Réservation El Olivar Zarqa\\n"
                    . "Client : " . addcslashes((string) $ev['full_name'], ",;\\") . "\\n"
                    . "Email : " . addcslashes((string) $ev['email'], ",;\\") . "\\n"
                    . "Personnes : " . (int) $ev['guests'] . "\\n"
                    . "Arrivée : " . (string) $ev['arrival_date'] . "\\n"
                    . "Départ : " . (string) $ev['departure_date'] . "\\n"
                    . "Message : " . addcslashes(str_replace(["\r\n", "\r", "\n"], "\\n", (string) ($ev['message'] ?? '')), ",;\\") . "\\n"
                    . "Lien Google Maps : https://maps.app.goo.gl/K4xCdhYZC3KkJu1q8";

                $out .= "BEGIN:VEVENT\r\n";
                $out .= "UID:{$uid}\r\n";
                $out .= "DTSTAMP:{$createdStamp}\r\n";
                $out .= "DTSTART;VALUE=DATE:{$arrClean}\r\n";
                $out .= "DTEND;VALUE=DATE:{$depClean}\r\n";
                $out .= "SUMMARY:{$summary}\r\n";
                $out .= "DESCRIPTION:{$desc}\r\n";
                $out .= "LOCATION:El Olivar\\, Ain Zarka\\, Tétouan\\, Maroc\r\n";
                $out .= "STATUS:CONFIRMED\r\n";
                $out .= "END:VEVENT\r\n";
            }

            $out .= "END:VCALENDAR\r\n";
            echo $out;
            exit;
        }

        $totalRequests = (int) $pdo->query('SELECT COUNT(*) FROM stay_inquiries')->fetchColumn();
        $pageCount = max(1, (int) ceil($totalRequests / $pageSize));
        $currentPage = min($currentPage, $pageCount);
        $offset = ($currentPage - 1) * $pageSize;
        $statement = $pdo->prepare(
            'SELECT id, full_name, email, arrival_date, departure_date, guests, message, status, admin_notes, created_at
             FROM stay_inquiries ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        $requests = $statement->fetchAll();
    } catch (Throwable $exception) {
        error_log('El Olivar admin request list failed: ' . $exception->getMessage());
        $dashboardError = 'Les demandes n’ont pas pu être chargées. Vérifiez que la table stay_inquiries existe.';
    }
}

$csrfToken = admin_csrf_token();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Demandes de séjour — El Olivar Zarqa</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/admin.css">
</head>
<body class="admin-page">
<?php if (!admin_is_signed_in()): ?>
    <main class="admin-auth-shell">
        <a class="admin-brand" href="../index.php"><img src="../assets/icon.svg" alt=""><span><strong>EL OLIVAR</strong><small>ADMINISTRATION</small></span></a>
        <section class="admin-auth-card">
            <p class="admin-kicker">ESPACE PRIVÉ</p>
            <h1>Demandes de séjour</h1>
            <p>Connectez-vous pour consulter et exporter les demandes reçues.</p>
            <?php if ($notice !== ''): ?><div class="admin-alert admin-alert--success" role="status"><?= admin_h($notice) ?></div><?php endif; ?>
            <?php if ($databaseError !== ''): ?><div class="admin-alert admin-alert--error" role="alert"><?= admin_h($databaseError) ?></div><?php endif; ?>
            <?php if ($error !== ''): ?><div class="admin-alert admin-alert--error" role="alert"><?= admin_h($error) ?></div><?php endif; ?>
            <?php if ($databaseReady && $adminCount === 0): ?>
                <div class="admin-alert admin-alert--info">Aucun compte n’est configuré. Créez votre compte administrateur pour continuer.</div>
                <a class="admin-button" href="setup.php">Créer le premier compte</a>
            <?php elseif ($databaseReady): ?>
                <form class="admin-form" method="post" action="index.php" autocomplete="on">
                    <input type="hidden" name="csrf_token" value="<?= admin_h($csrfToken) ?>">
                    <label for="username">Nom de connexion</label>
                    <input id="username" name="username" type="text" maxlength="80" autocomplete="username" required autofocus>
                    <label for="password">Mot de passe</label>
                    <input id="password" name="password" type="password" maxlength="255" autocomplete="current-password" required>
                    <button class="admin-button" type="submit">Se connecter</button>
                </form>
            <?php endif; ?>
            <a class="admin-back-link" href="../index.php">← Retour au site</a>
        </section>
    </main>
<?php else: ?>
    <header class="admin-topbar">
        <a class="admin-brand" href="../index.php"><img src="../assets/icon.svg" alt=""><span><strong>EL OLIVAR</strong><small>ADMINISTRATION</small></span></a>
        <div class="admin-topbar__actions">
            <span>Connecté : <strong><?= admin_h((string) $_SESSION['admin_username']) ?></strong></span>
            <button type="button" id="btn-open-password-modal" class="admin-button admin-button--subtle admin-button--sm">🔑 Mot de passe</button>
            <form method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?= admin_h($csrfToken) ?>"><button class="admin-button admin-button--subtle" type="submit">Déconnexion</button></form>
        </div>
    </header>
    <main class="admin-dashboard">
        <div class="admin-heading">
            <div><p class="admin-kicker">ESPACE PRIVÉ</p><h1>Demandes de séjour</h1><p>Gérez vos réservations, modifiez les statuts et ajoutez des notes en direct.</p></div>
            <div class="admin-heading__actions">
                <button id="admin-gcal-btn" class="admin-button admin-button--gcal" type="button" title="Ajouter les demandes sélectionnées à Google Calendar">
                    <img src="../assets/google-calendar.svg" alt="" width="18" height="18">
                    <span>Ajouter à Google Calendar</span>
                    <span id="admin-gcal-badge" class="admin-gcal-badge" style="display: none;">0</span>
                </button>
                <a class="admin-button" href="?export=csv">Exporter en CSV <span aria-hidden="true">↓</span></a>
            </div>
        </div>
        <?php if ($dashboardError !== ''): ?><div class="admin-alert admin-alert--error" role="alert"><?= admin_h($dashboardError) ?></div><?php endif; ?>
        
        <div class="admin-filter-bar">
            <div class="admin-filter-search">
                <input type="search" id="admin-search-input" placeholder="🔍 Rechercher voyageur, e-mail, séjour, note..." class="admin-search-input" aria-label="Rechercher">
            </div>
            <div class="admin-filter-status" role="group" aria-label="Filtrer par statut">
                <button type="button" class="admin-filter-btn is-active" data-status="all">Toutes (<span id="count-all"><?= count($requests) ?></span>)</button>
                <button type="button" class="admin-filter-btn" data-status="new">Nouvelles (<span id="count-new"><?= count(array_filter($requests, static fn($r) => ($r['status'] ?? 'new') === 'new')) ?></span>)</button>
                <button type="button" class="admin-filter-btn" data-status="contacted">Contactées (<span id="count-contacted"><?= count(array_filter($requests, static fn($r) => ($r['status'] ?? '') === 'contacted')) ?></span>)</button>
                <button type="button" class="admin-filter-btn" data-status="closed">Clôturées (<span id="count-closed"><?= count(array_filter($requests, static fn($r) => ($r['status'] ?? '') === 'closed')) ?></span>)</button>
            </div>
        </div>

        <div id="admin-selection-bar" class="admin-selection-bar" style="display: none;">
            <div class="admin-selection-bar__text">
                <span class="admin-selection-bar__count" id="admin-selection-count">0</span> demande(s) sélectionnée(s)
            </div>
            <div class="admin-selection-bar__actions">
                <button id="admin-gcal-btn-bar" class="admin-button admin-button--gcal admin-button--sm" type="button">
                    <img src="../assets/google-calendar.svg" alt="" width="16" height="16">
                    <span>Ajouter à Google Calendar</span>
                </button>
                <button id="admin-clear-selection" class="admin-button admin-button--secondary admin-button--sm" type="button">Tout désélectionner</button>
            </div>
        </div>

        <section class="admin-table-card">
            <?php if ($requests === [] && $dashboardError === ''): ?>
                <div class="admin-empty"><span aria-hidden="true">✳</span><h2>Aucune demande pour le moment</h2><p>Les demandes envoyées depuis le formulaire du site apparaîtront ici.</p></div>
            <?php else: ?>
                <div class="admin-table-scroll"><table class="admin-table">
                    <thead><tr>
                        <th class="admin-table__col-check"><input type="checkbox" id="admin-check-all" class="admin-checkbox" title="Tout sélectionner" aria-label="Tout sélectionner"></th>
                        <th>Reçue le</th>
                        <th>Voyageur</th>
                        <th>Coordonnées</th>
                        <th>Séjour</th>
                        <th>Pers.</th>
                        <th>Message</th>
                        <th>Statut (1-clic)</th>
                        <th>Note interne</th>
                        <th class="admin-table__col-actions">Actions</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($requests as $request): ?>
                        <?php
                            $status = in_array($request['status'], ['new', 'contacted', 'closed'], true) ? (string) $request['status'] : 'new';
                            $singleGcalUrl = admin_google_calendar_url($request);
                            $searchBlob = mb_strtolower(implode(' ', [
                                (string) $request['full_name'],
                                (string) $request['email'],
                                (string) $request['arrival_date'],
                                (string) $request['departure_date'],
                                (string) ($request['message'] ?? ''),
                                (string) ($request['admin_notes'] ?? '')
                            ]));
                        ?>
                        <tr id="row-<?= (int) $request['id'] ?>" data-id="<?= (int) $request['id'] ?>" data-status="<?= admin_h($status) ?>" data-search-text="<?= admin_h($searchBlob) ?>">
                            <td class="admin-table__col-check">
                                <input type="checkbox"
                                       class="admin-checkbox admin-row-check"
                                       value="<?= (int) $request['id'] ?>"
                                       data-id="<?= (int) $request['id'] ?>"
                                       data-name="<?= admin_h((string) $request['full_name']) ?>"
                                       data-arrival="<?= admin_h((string) $request['arrival_date']) ?>"
                                       data-departure="<?= admin_h((string) $request['departure_date']) ?>"
                                       data-guests="<?= (int) $request['guests'] ?>"
                                       data-gcal-url="<?= admin_h($singleGcalUrl) ?>"
                                       aria-label="Sélectionner la demande de <?= admin_h((string) $request['full_name']) ?>">
                            </td>
                            <td class="admin-table__date"><?= admin_h((string) $request['created_at']) ?></td>
                            <td><strong><?= admin_h((string) $request['full_name']) ?></strong></td>
                            <td><a href="mailto:<?= admin_h((string) $request['email']) ?>"><?= admin_h((string) $request['email']) ?></a></td>
                            <td><?= admin_h((string) $request['arrival_date']) ?><span class="admin-date-separator">→</span><?= admin_h((string) $request['departure_date']) ?></td>
                            <td><?= (int) $request['guests'] ?></td>
                            <td class="admin-table__message"><?= admin_h((string) ($request['message'] ?? '')) ?: '<span class="admin-muted">—</span>' ?></td>
                            <td>
                                <select class="admin-status-select admin-status-select--<?= admin_h($status) ?>" data-id="<?= (int) $request['id'] ?>" aria-label="Statut">
                                    <option value="new" <?= $status === 'new' ? 'selected' : '' ?>>● Nouvelle</option>
                                    <option value="contacted" <?= $status === 'contacted' ? 'selected' : '' ?>>● Contactée</option>
                                    <option value="closed" <?= $status === 'closed' ? 'selected' : '' ?>>● Clôturée</option>
                                </select>
                            </td>
                            <td>
                                <div class="admin-note-wrapper">
                                    <input type="text"
                                           class="admin-note-input"
                                           data-id="<?= (int) $request['id'] ?>"
                                           value="<?= admin_h((string) ($request['admin_notes'] ?? '')) ?>"
                                           placeholder="+ Ajouter note..."
                                           title="Note privée (ex: Acompte reçu, Lit bébé...)">
                                    <span class="admin-note-saved" style="display: none;">✓ Enregistré</span>
                                </div>
                            </td>
                            <td class="admin-table__col-actions">
                                <a class="admin-action-btn" href="<?= admin_h($singleGcalUrl) ?>" target="_blank" rel="noopener noreferrer" title="Ajouter cette demande à Google Calendar">
                                    <img src="../assets/google-calendar.svg" alt="" width="15" height="15">
                                    <span>Google Cal</span>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            <?php endif; ?>
        </section>
        <?php if ($pageCount > 1): ?>
            <nav class="admin-pagination" aria-label="Pagination des demandes">
                <?php if ($currentPage > 1): ?><a href="?page=<?= $currentPage - 1 ?>">← Précédent</a><?php endif; ?>
                <span>Page <?= $currentPage ?> sur <?= $pageCount ?></span>
                <?php if ($currentPage < $pageCount): ?><a href="?page=<?= $currentPage + 1 ?>">Suivant →</a><?php endif; ?>
            </nav>
        <?php endif; ?>
        <p class="admin-dashboard__note">Les demandes sont conservées dans la base MySQL du site. L’export CSV contient toutes les demandes avec notes internes.</p>
    </main>

    <!-- Modal Google Calendar -->
    <dialog id="admin-gcal-modal" class="admin-modal" aria-labelledby="gcal-modal-title">
        <div class="admin-modal__card">
            <div class="admin-modal__header">
                <div class="admin-modal__title-group">
                    <img src="../assets/google-calendar.svg" alt="" width="26" height="26">
                    <div>
                        <h2 id="gcal-modal-title">Ajouter à Google Calendar</h2>
                        <p class="admin-modal__subtitle" id="gcal-modal-count-label">1 réservation sélectionnée</p>
                    </div>
                </div>
                <button type="button" class="admin-modal__close" id="gcal-modal-close" aria-label="Fermer">×</button>
            </div>
            <div class="admin-modal__body">
                <div class="admin-modal__list" id="gcal-modal-list"></div>
                <div class="admin-modal__options">
                    <button type="button" id="gcal-modal-open-all" class="admin-button admin-button--gcal">
                        <img src="../assets/google-calendar.svg" alt="" width="18" height="18">
                        <span>Ouvrir dans Google Calendar</span>
                    </button>
                    <a id="gcal-modal-download-ics" class="admin-button admin-button--secondary" href="#">
                        <span>Télécharger l’import .ics</span>
                    </a>
                </div>
                <p class="admin-modal__notice">
                    💡 <strong>Astuce :</strong> Le fichier <code>.ics</code> s'importe directement dans Google Calendar (<em>Paramètres ⚙ &gt; Importer et exporter</em>) pour intégrer toutes les réservations sélectionnées en un seul clic sans blocage de pop-ups par votre navigateur.
                </p>
            </div>
        </div>
    </dialog>

    <!-- Modal Changer Mot de Passe -->
    <dialog id="admin-password-modal" class="admin-modal" aria-labelledby="password-modal-title">
        <div class="admin-modal__card">
            <div class="admin-modal__header">
                <div class="admin-modal__title-group">
                    <span style="font-size: 22px;">🔑</span>
                    <div>
                        <h2 id="password-modal-title">Modifier mon mot de passe</h2>
                        <p class="admin-modal__subtitle">Mettez à jour vos identifiants d'accès administrateur</p>
                    </div>
                </div>
                <button type="button" class="admin-modal__close" id="password-modal-close" aria-label="Fermer">×</button>
            </div>
            <form id="admin-password-form" class="admin-modal__body admin-form" method="post">
                <input type="hidden" name="csrf_token" value="<?= admin_h($csrfToken) ?>">
                <input type="hidden" name="admin_action" value="change_password">
                <div id="password-form-feedback" class="admin-alert" style="display: none;"></div>
                <label for="current_password">Mot de passe actuel</label>
                <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
                <label for="new_password">Nouveau mot de passe (min. 8 caractères)</label>
                <input type="password" id="new_password" name="new_password" minlength="8" required autocomplete="new-password">
                <label for="confirm_password">Confirmer le nouveau mot de passe</label>
                <input type="password" id="confirm_password" name="confirm_password" minlength="8" required autocomplete="new-password">
                <div class="admin-modal__options" style="margin-top: 15px;">
                    <button type="submit" class="admin-button">Enregistrer</button>
                    <button type="button" id="password-modal-cancel" class="admin-button admin-button--secondary">Annuler</button>
                </div>
            </form>
        </div>
    </dialog>

    <script>
    (() => {
        const csrfToken = <?= json_encode($csrfToken) ?>;

        // Checkbox & GCal Selection
        const checkAll = document.getElementById('admin-check-all');
        const rowChecks = Array.from(document.querySelectorAll('.admin-row-check'));
        const btnGcalHeading = document.getElementById('admin-gcal-btn');
        const btnGcalBar = document.getElementById('admin-gcal-btn-bar');
        const badgeGcal = document.getElementById('admin-gcal-badge');
        const selectionBar = document.getElementById('admin-selection-bar');
        const selectionCount = document.getElementById('admin-selection-count');
        const btnClear = document.getElementById('admin-clear-selection');

        const gcalModal = document.getElementById('admin-gcal-modal');
        const gcalModalClose = document.getElementById('gcal-modal-close');
        const gcalModalCountLabel = document.getElementById('gcal-modal-count-label');
        const gcalModalList = document.getElementById('gcal-modal-list');
        const gcalModalBtnOpenAll = document.getElementById('gcal-modal-open-all');
        const gcalModalBtnIcs = document.getElementById('gcal-modal-download-ics');

        const getSelected = () => rowChecks.filter((cb) => cb.checked);

        const updateSelectionState = () => {
            const selected = getSelected();
            const count = selected.length;

            rowChecks.forEach((cb) => {
                const row = cb.closest('tr');
                if (row) {
                    row.classList.toggle('admin-row--selected', cb.checked);
                }
            });

            if (checkAll) {
                checkAll.checked = count > 0 && count === rowChecks.length;
                checkAll.indeterminate = count > 0 && count < rowChecks.length;
            }

            if (badgeGcal) {
                if (count > 0) {
                    badgeGcal.textContent = String(count);
                    badgeGcal.style.display = 'inline-block';
                } else {
                    badgeGcal.style.display = 'none';
                }
            }

            if (selectionBar && selectionCount) {
                if (count > 0) {
                    selectionCount.textContent = String(count);
                    selectionBar.style.display = 'flex';
                } else {
                    selectionBar.style.display = 'none';
                }
            }
        };

        if (checkAll) {
            checkAll.addEventListener('change', () => {
                rowChecks.forEach((cb) => {
                    const row = cb.closest('tr');
                    if (row && row.style.display !== 'none') {
                        cb.checked = checkAll.checked;
                    }
                });
                updateSelectionState();
            });
        }

        rowChecks.forEach((cb) => {
            cb.addEventListener('change', updateSelectionState);
        });

        if (btnClear) {
            btnClear.addEventListener('click', () => {
                rowChecks.forEach((cb) => { cb.checked = false; });
                if (checkAll) checkAll.checked = false;
                updateSelectionState();
            });
        }

        const handleGcalAction = () => {
            const selected = getSelected();
            if (selected.length === 0) {
                alert('Veuillez sélectionner au moins une demande dans la liste pour l’ajouter à Google Calendar.');
                return;
            }

            if (selected.length === 1) {
                const url = selected[0].dataset.gcalUrl;
                if (url) {
                    window.open(url, '_blank', 'noopener,noreferrer');
                }
                return;
            }

            if (!gcalModal) return;

            gcalModalCountLabel.textContent = `${selected.length} réservations sélectionnées`;
            gcalModalList.innerHTML = '';

            const ids = selected.map((cb) => cb.value).join(',');
            gcalModalBtnIcs.href = `?export=gcal_ics&ids=${encodeURIComponent(ids)}`;

            selected.forEach((cb) => {
                const item = document.createElement('div');
                item.className = 'admin-modal__item';
                item.innerHTML = `
                    <div class="admin-modal__item-info">
                        <span class="admin-modal__item-name">${escapeHtml(cb.dataset.name || 'Voyageur')} (${cb.dataset.guests || 1} pers.)</span>
                        <span class="admin-modal__item-dates">${escapeHtml(cb.dataset.arrival)} → ${escapeHtml(cb.dataset.departure)}</span>
                    </div>
                    <a class="admin-modal__item-link" href="${cb.dataset.gcalUrl}" target="_blank" rel="noopener noreferrer">
                        Ajouter ↗
                    </a>
                `;
                gcalModalList.appendChild(item);
            });

            gcalModal.showModal();
        };

        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        if (btnGcalHeading) btnGcalHeading.addEventListener('click', handleGcalAction);
        if (btnGcalBar) btnGcalBar.addEventListener('click', handleGcalAction);

        if (gcalModalClose && gcalModal) {
            gcalModalClose.addEventListener('click', () => gcalModal.close());
            gcalModal.addEventListener('click', (e) => {
                if (e.target === gcalModal) gcalModal.close();
            });
        }

        if (gcalModalBtnOpenAll) {
            gcalModalBtnOpenAll.addEventListener('click', () => {
                const selected = getSelected();
                selected.forEach((cb) => {
                    if (cb.dataset.gcalUrl) {
                        window.open(cb.dataset.gcalUrl, '_blank', 'noopener,noreferrer');
                    }
                });
                if (gcalModal) gcalModal.close();
            });
        }

        // 1-Click Status Change
        const updateStatusCounts = () => {
            const rows = Array.from(document.querySelectorAll('.admin-table tbody tr'));
            const countAll = rows.length;
            const countNew = rows.filter((r) => (r.dataset.status || 'new') === 'new').length;
            const countContacted = rows.filter((r) => r.dataset.status === 'contacted').length;
            const countClosed = rows.filter((r) => r.dataset.status === 'closed').length;

            const elAll = document.getElementById('count-all');
            const elNew = document.getElementById('count-new');
            const elContacted = document.getElementById('count-contacted');
            const elClosed = document.getElementById('count-closed');

            if (elAll) elAll.textContent = String(countAll);
            if (elNew) elNew.textContent = String(countNew);
            if (elContacted) elContacted.textContent = String(countContacted);
            if (elClosed) elClosed.textContent = String(countClosed);
        };

        document.querySelectorAll('.admin-status-select').forEach((select) => {
            select.addEventListener('change', async () => {
                const id = select.dataset.id;
                const newStatus = select.value;
                const row = select.closest('tr');
                select.disabled = true;

                try {
                    const formData = new FormData();
                    formData.append('admin_action', 'update_status');
                    formData.append('csrf_token', csrfToken);
                    formData.append('id', id);
                    formData.append('status', newStatus);

                    const res = await fetch('index.php', { method: 'POST', body: formData });
                    const data = await res.json();
                    if (data.success) {
                        select.className = `admin-status-select admin-status-select--${newStatus}`;
                        if (row) row.dataset.status = newStatus;
                        updateStatusCounts();
                        filterRows();
                    } else {
                        alert(data.message || 'Erreur lors de la mise à jour du statut.');
                    }
                } catch (e) {
                    alert('Erreur réseau lors de la mise à jour du statut.');
                } finally {
                    select.disabled = false;
                }
            });
        });

        // Inline Notes Save
        document.querySelectorAll('.admin-note-input').forEach((input) => {
            const saveNote = async () => {
                const id = input.dataset.id;
                const note = input.value.trim();
                const wrapper = input.closest('.admin-note-wrapper');
                const indicator = wrapper ? wrapper.querySelector('.admin-note-saved') : null;

                try {
                    const formData = new FormData();
                    formData.append('admin_action', 'update_note');
                    formData.append('csrf_token', csrfToken);
                    formData.append('id', id);
                    formData.append('note', note);

                    const res = await fetch('index.php', { method: 'POST', body: formData });
                    const data = await res.json();
                    if (data.success && indicator) {
                        indicator.style.display = 'inline';
                        setTimeout(() => { indicator.style.display = 'none'; }, 2000);
                        const row = input.closest('tr');
                        if (row) {
                            row.dataset.searchText = `${row.dataset.searchText} ${note.toLowerCase()}`;
                        }
                    }
                } catch (e) {
                    console.error('Note save failed', e);
                }
            };

            input.addEventListener('change', saveNote);
            input.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') input.blur();
            });
        });

        // Live Search & Status Filters
        const searchInput = document.getElementById('admin-search-input');
        const filterBtns = Array.from(document.querySelectorAll('.admin-filter-btn'));
        let currentStatusFilter = 'all';

        const filterRows = () => {
            const q = (searchInput ? searchInput.value : '').toLowerCase().trim();
            const rows = Array.from(document.querySelectorAll('.admin-table tbody tr'));

            rows.forEach((row) => {
                const status = row.dataset.status || 'new';
                const text = (row.dataset.searchText || '') + ' ' + (row.querySelector('.admin-note-input')?.value || '');
                const matchesStatus = currentStatusFilter === 'all' || status === currentStatusFilter;
                const matchesQuery = q === '' || text.toLowerCase().includes(q);

                row.style.display = (matchesStatus && matchesQuery) ? '' : 'none';
            });
        };

        if (searchInput) {
            searchInput.addEventListener('input', filterRows);
        }

        filterBtns.forEach((btn) => {
            btn.addEventListener('click', () => {
                filterBtns.forEach((b) => b.classList.remove('is-active'));
                btn.classList.add('is-active');
                currentStatusFilter = btn.dataset.status;
                filterRows();
            });
        });

        // Password Modal
        const btnPasswordModal = document.getElementById('btn-open-password-modal');
        const modalPassword = document.getElementById('admin-password-modal');
        const closePasswordModal = document.getElementById('password-modal-close');
        const cancelPasswordModal = document.getElementById('password-modal-cancel');
        const formPassword = document.getElementById('admin-password-form');
        const feedbackPassword = document.getElementById('password-form-feedback');

        if (btnPasswordModal && modalPassword) {
            btnPasswordModal.addEventListener('click', () => {
                if (feedbackPassword) feedbackPassword.style.display = 'none';
                if (formPassword) formPassword.reset();
                modalPassword.showModal();
            });
        }
        if (closePasswordModal && modalPassword) {
            closePasswordModal.addEventListener('click', () => modalPassword.close());
        }
        if (cancelPasswordModal && modalPassword) {
            cancelPasswordModal.addEventListener('click', () => modalPassword.close());
        }
        if (modalPassword) {
            modalPassword.addEventListener('click', (e) => {
                if (e.target === modalPassword) modalPassword.close();
            });
        }
        if (formPassword) {
            formPassword.addEventListener('submit', async (e) => {
                e.preventDefault();
                const submitBtn = formPassword.querySelector('button[type="submit"]');
                if (submitBtn) submitBtn.disabled = true;

                try {
                    const formData = new FormData(formPassword);
                    const res = await fetch('index.php', { method: 'POST', body: formData });
                    const data = await res.json();

                    if (feedbackPassword) {
                        feedbackPassword.style.display = 'block';
                        if (data.success) {
                            feedbackPassword.className = 'admin-alert admin-alert--success';
                            feedbackPassword.textContent = data.message;
                            formPassword.reset();
                            setTimeout(() => { modalPassword.close(); }, 1800);
                        } else {
                            feedbackPassword.className = 'admin-alert admin-alert--error';
                            feedbackPassword.textContent = data.message || 'Une erreur est survenue.';
                        }
                    }
                } catch (err) {
                    if (feedbackPassword) {
                        feedbackPassword.style.display = 'block';
                        feedbackPassword.className = 'admin-alert admin-alert--error';
                        feedbackPassword.textContent = 'Erreur réseau.';
                    }
                } finally {
                    if (submitBtn) submitBtn.disabled = false;
                }
            });
        }
    })();
    </script>
<?php endif; ?>
</body>
</html>
