<?php
// =============================================================
//  config/db.php — Connexion PDO centralisée
//  Modifiez les constantes ci-dessous selon votre WAMP
// =============================================================

define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'mercato_nova');
define('DB_USER', 'root');        // Utilisateur WAMP par défaut
define('DB_PASS', '');            // Mot de passe WAMP (vide par défaut)
define('DB_CHARSET', 'utf8mb4');

/**
 * Retourne une instance PDO (singleton).
 * Lance une exception si la connexion échoue (capturée dans api.php).
 */
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    }
    return $pdo;
}
