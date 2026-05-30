<?php
// =============================================================
//  api/helpers.php — Fonctions utilitaires partagées
// =============================================================

/**
 * Envoie une réponse JSON de succès et arrête le script.
 */
function jsonOk(mixed $data = null, int $code = 200): void {
    http_response_code($code);
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Envoie une réponse JSON d'erreur et arrête le script.
 */
function jsonError(string $message, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Valide qu'un paramètre de corps est présent et non vide.
 */
function required(array $body, string ...$fields): void {
    foreach ($fields as $f) {
        if (!isset($body[$f]) || $body[$f] === '' || $body[$f] === null) {
            jsonError("Champ requis manquant : $f");
        }
    }
}

/**
 * Sanitise une chaîne (trim + strip_tags).
 */
function clean(string $s): string {
    return htmlspecialchars(strip_tags(trim($s)), ENT_QUOTES, 'UTF-8');
}

/**
 * Génère une référence de commande unique.
 */
function generateOrderRef(): string {
    return 'MN-' . strtoupper(base_convert(time(), 10, 36)) . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

/**
 * Insère une notification en base.
 */
function createNotification(PDO $db, int $userId, string $type, string $titre, string $message = '', string $lien = ''): void {
    $stmt = $db->prepare(
        "INSERT INTO notifications (user_id, type, titre, message, lien)
         VALUES (:uid, :type, :titre, :msg, :lien)"
    );
    $stmt->execute([
        ':uid'   => $userId,
        ':type'  => $type,
        ':titre' => $titre,
        ':msg'   => $message,
        ':lien'  => $lien,
    ]);
}
