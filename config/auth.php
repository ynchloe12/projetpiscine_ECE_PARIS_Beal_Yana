<?php
// =============================================================
//  config/auth.php — Helpers session / authentification
// =============================================================

/**
 * Démarre la session avec les paramètres sécurisés.
 * À appeler en début de chaque point d'entrée PHP.
 */
function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => false,   // Mettre true en prod (HTTPS)
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

/**
 * Retourne l'utilisateur connecté ou null.
 */
function currentUser(): ?array {
    startSession();
    return $_SESSION['mn_user'] ?? null;
}

/**
 * Vérifie que l'utilisateur est connecté.
 * Si non, envoie 401 et arrête.
 */
function requireAuth(): array {
    $user = currentUser();
    if (!$user) {
        jsonError('Non authentifié.', 401);
    }
    return $user;
}

/**
 * Vérifie que l'utilisateur a le rôle demandé.
 */
function requireRole(string ...$roles): array {
    $user = requireAuth();
    if (!in_array($user['role'], $roles, true)) {
        jsonError('Accès refusé.', 403);
    }
    return $user;
}

/**
 * Hash bcrypt d'un mot de passe.
 */
function hashPassword(string $plain): string {
    return password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * Vérifie un mot de passe contre son hash.
 */
function verifyPassword(string $plain, string $hash): bool {
    return password_verify($plain, $hash);
}
