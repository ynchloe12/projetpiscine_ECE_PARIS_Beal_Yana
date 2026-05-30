<?php
// =============================================================
//  routes/admin.php — Administration de la plateforme
// =============================================================

function adminListUsers(): void {
    requireRole('admin');
    $db   = getDB();
    $q    = trim($_GET['q'] ?? '');

    $where  = [];
    $params = [];
    if ($q) {
        $where[]     = '(pseudo LIKE :q OR email LIKE :q2)';
        $params[':q']  = "%$q%";
        $params[':q2'] = "%$q%";
    }

    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $stmt = $db->prepare(
        "SELECT id, pseudo, email, role, blocked, created_at FROM users $whereSQL ORDER BY created_at DESC"
    );
    $stmt->execute($params);
    jsonOk($stmt->fetchAll());
}

function adminBlockUser(array $body): void {
    requireRole('admin');
    required($body, 'id');

    $db      = getDB();
    $id      = (int)$body['id'];
    $blocked = isset($body['blocked']) ? (int)(bool)$body['blocked'] : null;

    if ($blocked === null) {
        // Toggle
        $db->prepare("UPDATE users SET blocked = NOT blocked WHERE id = :id AND role != 'admin'")
           ->execute([':id' => $id]);
    } else {
        $db->prepare("UPDATE users SET blocked = :b WHERE id = :id AND role != 'admin'")
           ->execute([':b' => $blocked, ':id' => $id]);
    }

    jsonOk(['message' => 'Statut utilisateur mis à jour.']);
}

function adminDeleteUser(array $body): void {
    requireRole('admin');
    required($body, 'id');

    $id = (int)$body['id'];
    $db = getDB();

    // Vérifie qu'on ne supprime pas l'admin lui-même
    $chk = $db->prepare("SELECT role FROM users WHERE id = :id");
    $chk->execute([':id' => $id]);
    $u = $chk->fetch();
    if (!$u) jsonError('Utilisateur introuvable.', 404);
    if ($u['role'] === 'admin') jsonError('Impossible de supprimer un administrateur.');

    $db->prepare("DELETE FROM users WHERE id = :id")->execute([':id' => $id]);
    jsonOk(['message' => 'Utilisateur supprimé.']);
}

function adminListAnnonces(): void {
    requireRole('admin');
    $db = getDB();
    $q  = trim($_GET['q'] ?? '');

    $where  = [];
    $params = [];
    if ($q) {
        $where[]     = 'a.titre LIKE :q';
        $params[':q'] = "%$q%";
    }

    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $stmt = $db->prepare(
        "SELECT a.id, a.titre, a.categorie, a.type, a.prix, a.created_at, a.active, a.flagged,
                u.pseudo AS vendeur
         FROM annonces a JOIN users u ON u.id = a.vendeur_id
         $whereSQL ORDER BY a.created_at DESC"
    );
    $stmt->execute($params);
    jsonOk($stmt->fetchAll());
}

function adminFlagAnnonce(array $body): void {
    requireRole('admin');
    required($body, 'id');

    $id      = (int)$body['id'];
    $flagged = isset($body['flagged']) ? (int)(bool)$body['flagged'] : null;
    $db      = getDB();

    if ($flagged === null) {
        $db->prepare("UPDATE annonces SET flagged = NOT flagged WHERE id = :id")->execute([':id' => $id]);
    } else {
        $db->prepare("UPDATE annonces SET flagged = :f WHERE id = :id")->execute([':f' => $flagged, ':id' => $id]);
    }

    jsonOk(['message' => 'Annonce mise à jour.']);
}

function adminDeleteAnnonce(array $body): void {
    requireRole('admin');
    required($body, 'id');

    $db = getDB();
    $db->prepare("DELETE FROM annonces WHERE id = :id")->execute([':id' => (int)$body['id']]);
    jsonOk(['message' => 'Annonce supprimée.']);
}

function adminStats(): void {
    requireRole('admin');
    $db = getDB();

    $stats = [];
    $stats['users']         = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $stats['vendeurs']      = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='vendeur'")->fetchColumn();
    $stats['annonces']      = (int)$db->query("SELECT COUNT(*) FROM annonces WHERE active=1")->fetchColumn();
    $stats['flagged']       = (int)$db->query("SELECT COUNT(*) FROM annonces WHERE flagged=1")->fetchColumn();
    $stats['transactions']  = (int)$db->query("SELECT COUNT(*) FROM transactions")->fetchColumn();
    $stats['encheres']      = (int)$db->query("SELECT COUNT(*) FROM encheres WHERE statut='en_cours'")->fetchColumn();
    $stats['negociations']  = (int)$db->query("SELECT COUNT(*) FROM negociations WHERE statut='en_cours'")->fetchColumn();

    jsonOk($stats);
}
