<?php
// =============================================================
//  api/index.php — Point d'entrée unique de l'API REST
//  URL : /api/index.php?route=<route>
//  Méthodes : GET, POST, PUT, DELETE
// =============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/helpers.php';

// ── CORS (pour développement local WAMP) ──────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

startSession();

// ── Lecture du corps JSON ────────────────────────────────────
$body = [];
$raw = file_get_contents('php://input');
if (!empty($raw)) {
    $decoded = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $body = $decoded;
    }
}

$method = $_SERVER['REQUEST_METHOD'];
$route  = trim($_GET['route'] ?? '', '/');

// ── Routeur ─────────────────────────────────────────────────
try {
    switch ("$method:$route") {

        // ══════════════════════════════════════
        //  AUTH
        // ══════════════════════════════════════
        case 'POST:auth/register':
            require_once __DIR__ . '/routes/auth.php';
            handleRegister($body);
            break;

        case 'POST:auth/login':
            require_once __DIR__ . '/routes/auth.php';
            handleLogin($body);
            break;

        case 'POST:auth/logout':
            require_once __DIR__ . '/routes/auth.php';
            handleLogout();
            break;

        case 'GET:auth/me':
            require_once __DIR__ . '/routes/auth.php';
            handleMe();
            break;

        // ══════════════════════════════════════
        //  UTILISATEURS
        // ══════════════════════════════════════
        case 'GET:users/profile':
            require_once __DIR__ . '/routes/users.php';
            handleGetProfile();
            break;

        case 'PUT:users/profile':
            require_once __DIR__ . '/routes/users.php';
            handleUpdateProfile($body);
            break;

        // ══════════════════════════════════════
        //  ADMIN — utilisateurs
        // ══════════════════════════════════════
        case 'GET:admin/users':
            require_once __DIR__ . '/routes/admin.php';
            adminListUsers();
            break;

        case 'PUT:admin/users/block':
            require_once __DIR__ . '/routes/admin.php';
            adminBlockUser($body);
            break;

        case 'DELETE:admin/users':
            require_once __DIR__ . '/routes/admin.php';
            adminDeleteUser($body);
            break;

        // ══════════════════════════════════════
        //  ADMIN — annonces
        // ══════════════════════════════════════
        case 'GET:admin/annonces':
            require_once __DIR__ . '/routes/admin.php';
            adminListAnnonces();
            break;

        case 'PUT:admin/annonces/flag':
            require_once __DIR__ . '/routes/admin.php';
            adminFlagAnnonce($body);
            break;

        case 'DELETE:admin/annonces':
            require_once __DIR__ . '/routes/admin.php';
            adminDeleteAnnonce($body);
            break;

        case 'GET:admin/stats':
            require_once __DIR__ . '/routes/admin.php';
            adminStats();
            break;

        // ══════════════════════════════════════
        //  ANNONCES
        // ══════════════════════════════════════
        case 'GET:annonces':
            require_once __DIR__ . '/routes/annonces.php';
            handleListAnnonces();
            break;

        case 'GET:annonces/detail':
            require_once __DIR__ . '/routes/annonces.php';
            handleGetAnnonce();
            break;

        case 'POST:annonces':
            require_once __DIR__ . '/routes/annonces.php';
            handleCreateAnnonce($body);
            break;

        case 'PUT:annonces':
            require_once __DIR__ . '/routes/annonces.php';
            handleUpdateAnnonce($body);
            break;

        case 'DELETE:annonces':
            require_once __DIR__ . '/routes/annonces.php';
            handleDeleteAnnonce($body);
            break;

        case 'GET:annonces/mes-annonces':
            require_once __DIR__ . '/routes/annonces.php';
            handleMesAnnonces();
            break;

        // ══════════════════════════════════════
        //  PANIER
        // ══════════════════════════════════════
        case 'GET:panier':
            require_once __DIR__ . '/routes/panier.php';
            handleGetPanier();
            break;

        case 'POST:panier':
            require_once __DIR__ . '/routes/panier.php';
            handleAddPanier($body);
            break;

        case 'PUT:panier':
            require_once __DIR__ . '/routes/panier.php';
            handleUpdatePanier($body);
            break;

        case 'DELETE:panier':
            require_once __DIR__ . '/routes/panier.php';
            handleRemovePanier($body);
            break;

        case 'DELETE:panier/clear':
            require_once __DIR__ . '/routes/panier.php';
            handleClearPanier();
            break;

        // ══════════════════════════════════════
        //  TRANSACTIONS / CHECKOUT
        // ══════════════════════════════════════
        case 'POST:transactions/checkout':
            require_once __DIR__ . '/routes/transactions.php';
            handleCheckout($body);
            break;

        case 'GET:transactions':
            require_once __DIR__ . '/routes/transactions.php';
            handleListTransactions();
            break;

        // ══════════════════════════════════════
        //  ENCHÈRES
        // ══════════════════════════════════════
        case 'GET:encheres':
            require_once __DIR__ . '/routes/encheres.php';
            handleListEncheres();
            break;

        case 'GET:encheres/detail':
            require_once __DIR__ . '/routes/encheres.php';
            handleGetEnchere();
            break;

        case 'POST:encheres/bid':
            require_once __DIR__ . '/routes/encheres.php';
            handlePlaceBid($body);
            break;

        case 'POST:encheres/close':
            require_once __DIR__ . '/routes/encheres.php';
            handleCloseEnchere($body);
            break;

        // ══════════════════════════════════════
        //  NÉGOCIATIONS
        // ══════════════════════════════════════
        case 'GET:negociations':
            require_once __DIR__ . '/routes/negociations.php';
            handleListNegociations();
            break;

        case 'GET:negociations/detail':
            require_once __DIR__ . '/routes/negociations.php';
            handleGetNegociation();
            break;

        case 'POST:negociations':
            require_once __DIR__ . '/routes/negociations.php';
            handleCreateNegociation($body);
            break;

        case 'POST:negociations/offre':
            require_once __DIR__ . '/routes/negociations.php';
            handleSendOffre($body);
            break;

        case 'POST:negociations/repondre':
            require_once __DIR__ . '/routes/negociations.php';
            handleRepondreOffre($body);
            break;

        case 'POST:negociations/abandonner':
            require_once __DIR__ . '/routes/negociations.php';
            handleAbandonner($body);
            break;

        // ══════════════════════════════════════
        //  NOTIFICATIONS
        // ══════════════════════════════════════
        case 'GET:notifications':
            require_once __DIR__ . '/routes/notifications.php';
            handleListNotifications();
            break;

        case 'PUT:notifications/lire':
            require_once __DIR__ . '/routes/notifications.php';
            handleMarkRead($body);
            break;

        case 'PUT:notifications/lire-tout':
            require_once __DIR__ . '/routes/notifications.php';
            handleMarkAllRead();
            break;

        default:
            jsonError("Route inconnue : $method $route", 404);
    }
} catch (PDOException $e) {
    // Ne pas exposer les détails SQL en prod
    error_log('[MercatoNova DB] ' . $e->getMessage());
    jsonError('Erreur base de données.', 500);
} catch (Throwable $e) {
    error_log('[MercatoNova] ' . $e->getMessage());
    jsonError('Erreur serveur : ' . $e->getMessage(), 500);
}
