<?php
// =============================================================
//  routes/panier.php — Gestion du panier
// =============================================================

function handleGetPanier(): void {
    $user = requireAuth();
    $db   = getDB();

    $stmt = $db->prepare(
        "SELECT p.id, p.annonce_id, p.quantite, p.prix_unitaire,
                a.titre, a.type, a.photo_url, a.active, a.flagged
         FROM panier p
         JOIN annonces a ON a.id = p.annonce_id
         WHERE p.user_id = :uid"
    );
    $stmt->execute([':uid' => $user['id']]);
    $items = $stmt->fetchAll();

    $subtotal = array_reduce($items, fn($s, $i) => $s + $i['prix_unitaire'] * $i['quantite'], 0);

    jsonOk(['items' => $items, 'subtotal' => round($subtotal, 2)]);
}

function handleAddPanier(array $body): void {
    $user = requireAuth();
    required($body, 'annonce_id');

    $annonceId = (int)$body['annonce_id'];
    $qty       = max(1, min(10, (int)($body['quantite'] ?? 1)));
    $db        = getDB();

    // Vérifie que l'annonce existe et est active
    $stmt = $db->prepare("SELECT id, prix, type, vendeur_id FROM annonces WHERE id = :id AND active = 1 AND flagged = 0");
    $stmt->execute([':id' => $annonceId]);
    $annonce = $stmt->fetch();
    if (!$annonce) {
        jsonError('Annonce introuvable ou indisponible.', 404);
    }
    if ($annonce['vendeur_id'] === $user['id']) {
        jsonError("Vous ne pouvez pas acheter votre propre annonce.");
    }

    // Prix à utiliser (pour nego, le prix négocié peut être passé)
    $prix = isset($body['prix_negocie']) && (float)$body['prix_negocie'] > 0
        ? (float)$body['prix_negocie']
        : (float)$annonce['prix'];

    // INSERT OR UPDATE (ON DUPLICATE KEY)
    $stmt = $db->prepare(
        "INSERT INTO panier (user_id, annonce_id, quantite, prix_unitaire)
         VALUES (:uid, :aid, :qty, :prix)
         ON DUPLICATE KEY UPDATE quantite = LEAST(quantite + :qty2, 10)"
    );
    $stmt->execute([
        ':uid'  => $user['id'],
        ':aid'  => $annonceId,
        ':qty'  => $qty,
        ':prix' => $prix,
        ':qty2' => $qty,
    ]);

    jsonOk(['message' => 'Ajouté au panier.']);
}

function handleUpdatePanier(array $body): void {
    $user = requireAuth();
    required($body, 'annonce_id', 'quantite');

    $annonceId = (int)$body['annonce_id'];
    $qty       = (int)$body['quantite'];
    $db        = getDB();

    if ($qty <= 0) {
        // Supprimer
        $db->prepare("DELETE FROM panier WHERE user_id = :uid AND annonce_id = :aid")
           ->execute([':uid' => $user['id'], ':aid' => $annonceId]);
        jsonOk(['message' => 'Article retiré.']);
    }

    $qty = min(10, $qty);
    $stmt = $db->prepare(
        "UPDATE panier SET quantite = :qty WHERE user_id = :uid AND annonce_id = :aid"
    );
    $stmt->execute([':qty' => $qty, ':uid' => $user['id'], ':aid' => $annonceId]);

    jsonOk(['message' => 'Quantité mise à jour.']);
}

function handleRemovePanier(array $body): void {
    $user = requireAuth();
    required($body, 'annonce_id');

    $db = getDB();
    $db->prepare("DELETE FROM panier WHERE user_id = :uid AND annonce_id = :aid")
       ->execute([':uid' => $user['id'], ':aid' => (int)$body['annonce_id']]);

    jsonOk(['message' => 'Article retiré du panier.']);
}

function handleClearPanier(): void {
    $user = requireAuth();
    $db   = getDB();
    $db->prepare("DELETE FROM panier WHERE user_id = :uid")
       ->execute([':uid' => $user['id']]);
    jsonOk(['message' => 'Panier vidé.']);
}
