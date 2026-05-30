<?php
// =============================================================
//  routes/notifications.php
// =============================================================

function handleListNotifications(): void {
    $user = requireAuth();
    $db   = getDB();

    $stmt = $db->prepare(
        "SELECT id, type, titre, message, lue, lien, created_at
         FROM notifications WHERE user_id = :uid
         ORDER BY created_at DESC LIMIT 50"
    );
    $stmt->execute([':uid' => $user['id']]);
    $rows = $stmt->fetchAll();

    $unread = array_sum(array_column($rows, 'lue') === []) + 0;
    // Compte les non lues
    $unreadCount = count(array_filter($rows, fn($r) => !$r['lue']));

    jsonOk(['notifications' => $rows, 'unread' => $unreadCount]);
}

function handleMarkRead(array $body): void {
    $user = requireAuth();
    $id   = (int)($body['id'] ?? 0);
    if (!$id) jsonError('Paramètre id manquant.');

    $db = getDB();
    $db->prepare("UPDATE notifications SET lue = 1 WHERE id = :id AND user_id = :uid")
       ->execute([':id' => $id, ':uid' => $user['id']]);

    jsonOk(['message' => 'Notification marquée comme lue.']);
}

function handleMarkAllRead(): void {
    $user = requireAuth();
    $db   = getDB();
    $db->prepare("UPDATE notifications SET lue = 1 WHERE user_id = :uid")
       ->execute([':uid' => $user['id']]);
    jsonOk(['message' => 'Toutes les notifications marquées comme lues.']);
}
