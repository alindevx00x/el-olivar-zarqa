<?php
/* Developer: Alindevx00x (https://alindevx00x.me) */
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
$pdo = null;
$adminCount = 0;
$databaseReady = false;
$error = '';
$username = '';

try {
    $pdo = database_connection();
    $adminCount = (int) $pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
    $databaseReady = true;
} catch (Throwable $exception) {
    error_log('El Olivar admin setup unavailable: ' . $exception->getMessage());
    $error = 'Impossible d’accéder à la table des comptes. Importez le fichier database.sql mis à jour dans phpMyAdmin, puis rechargez cette page.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $databaseReady && $adminCount === 0) {
    $username = trim(is_string($_POST['username'] ?? null) ? $_POST['username'] : '');
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $passwordConfirmation = is_string($_POST['password_confirmation'] ?? null) ? $_POST['password_confirmation'] : '';

    if (!admin_csrf_is_valid()) {
        $error = 'La page a expiré. Rechargez-la puis réessayez.';
    } elseif (!preg_match('/^[A-Za-z0-9._-]{3,80}$/', $username)) {
        $error = 'Le nom de connexion doit contenir de 3 à 80 lettres, chiffres, points, tirets ou tirets bas.';
    } elseif (strlen($password) < 12 || strlen($password) > 255) {
        $error = 'Choisissez un mot de passe de 12 à 255 caractères.';
    } elseif ($password !== $passwordConfirmation) {
        $error = 'Les deux mots de passe ne correspondent pas.';
    } else {
        $lockAcquired = false;
        try {
            $lockAcquired = (int) $pdo->query("SELECT GET_LOCK('el_olivar_zarqa_admin_setup', 5)")->fetchColumn() === 1;
            if (!$lockAcquired) {
                throw new RuntimeException('The one-time administrator setup lock could not be acquired.');
            }

            $adminCount = (int) $pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
            if ($adminCount !== 0) {
                $error = 'Le compte initial existe déjà. Connectez-vous depuis la page d’administration.';
            } else {
                $statement = $pdo->prepare('INSERT INTO admin_users (username, password_hash) VALUES (:username, :password_hash)');
                $statement->execute([
                    ':username' => $username,
                    ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ]);
                if ($statement->rowCount() !== 1) {
                    throw new RuntimeException('Administrator account was not created.');
                }

                $_SESSION['admin_notice'] = 'Votre compte administrateur est créé. Connectez-vous pour consulter les demandes.';
                header('Location: index.php');
                exit;
            }
        } catch (Throwable $exception) {
            error_log('El Olivar admin setup failed: ' . $exception->getMessage());
            if ($error === '') {
                $error = 'Le compte administrateur n’a pas pu être créé. Vérifiez la connexion MySQL puis réessayez.';
            }
        } finally {
            if ($lockAcquired) {
                try {
                    $pdo->query("SELECT RELEASE_LOCK('el_olivar_zarqa_admin_setup')");
                } catch (Throwable $releaseException) {
                    error_log('El Olivar admin setup lock release failed: ' . $releaseException->getMessage());
                }
            }
        }
    }
}

$setupComplete = $databaseReady && $adminCount > 0;
$csrfToken = admin_csrf_token();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Créer le compte administrateur — El Olivar Zarqa</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/admin.css">
</head>
<body class="admin-page">
    <main class="admin-auth-shell">
        <a class="admin-brand" href="../index.php"><img src="../assets/icon.svg" alt=""><span><strong>EL OLIVAR</strong><small>ADMINISTRATION</small></span></a>
        <section class="admin-auth-card">
            <?php if ($setupComplete): ?>
                <p class="admin-kicker">CONFIGURATION TERMINÉE</p>
                <h1>Le compte existe déjà.</h1>
                <p>La création initiale est désactivée. Connectez-vous pour voir les demandes de séjour.</p>
                <a class="admin-button" href="index.php">Aller à la connexion</a>
            <?php else: ?>
                <p class="admin-kicker">PREMIÈRE UTILISATION</p>
                <h1>Créer le compte administrateur</h1>
                <p>Choisissez un identifiant et un mot de passe privé. Cette page se désactive dès que le premier compte est créé.</p>
                <?php if ($error !== ''): ?><div class="admin-alert admin-alert--error" role="alert"><?= admin_h($error) ?></div><?php endif; ?>
                <?php if ($databaseReady): ?>
                    <form class="admin-form" method="post" action="setup.php" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?= admin_h($csrfToken) ?>">
                        <label for="username">Nom de connexion</label>
                        <input id="username" name="username" type="text" value="<?= admin_h($username) ?>" minlength="3" maxlength="80" pattern="[A-Za-z0-9._-]{3,80}" autocomplete="username" required>
                        <label for="password">Mot de passe</label>
                        <input id="password" name="password" type="password" minlength="12" maxlength="255" autocomplete="new-password" required>
                        <label for="password-confirmation">Confirmer le mot de passe</label>
                        <input id="password-confirmation" name="password_confirmation" type="password" minlength="12" maxlength="255" autocomplete="new-password" required>
                        <button class="admin-button" type="submit">Créer mon compte</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
            <a class="admin-back-link" href="../index.php">← Retour au site</a>
        </section>
    </main>
</body>
</html>
