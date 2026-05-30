<?php
// =============================================================
//  routes/transactions.php — Paiement simulé & historique
// =============================================================

function handleCheckout(array $body): void {
    $user = requireAuth();
    required($body, 'livraison_nom', 'livraison_adresse', 'livraison_cp', 'livraison_ville');

    $db = getDB();

    // Récupère le panier de l'utilisateur
    $stmt = $db->prepare(
        "SELECT p.annonce_id, p.quantite, p.prix_unitaire,
                a.titre, a.type, a.vendeur_id, a.active, a.flagged
         FROM panier p
         JOIN annonces a ON a.id = p.annonce_id
         WHERE p.user_id = :uid"
    );
    $stmt->execute([':uid' => $user['id']]);
    $items = $stmt->fetchAll();

    if (empty($items)) {
        jsonError('Le panier est vide.');
    }

    // Vérifie que toutes les annonces sont encore disponibles
    foreach ($items as $item) {
        if (!$item['active'] || $item['flagged']) {
            jsonError("L'annonce \"{$item['titre']}\" n'est plus disponible.");
        }
    }

    $db->beginTransaction();
    try {
        $orderRef = generateOrderRef();
        $orders   = [];

        foreach ($items as $item) {
            $transStmt = $db->prepare(
                "INSERT INTO transactions
                   (acheteur_id, annonce_id, vendeur_id, prix_final, quantite, type_vente, statut, order_ref,
                    livraison_nom, livraison_adresse, livraison_cp, livraison_ville)
                 VALUES
                   (:ach, :aid, :vid, :prix, :qty, :type, 'payee', :ref,
                    :nom, :adr, :cp, :ville)"
            );
            $transStmt->execute([
                ':ach'   => $user['id'],
                ':aid'   => $item['annonce_id'],
                ':vid'   => $item['vendeur_id'],
                ':prix'  => $item['prix_unitaire'],
                ':qty'   => $item['quantite'],
                ':type'  => $item['type'],
                ':ref'   => $orderRef . '-' . $item['annonce_id'],
                ':nom'   => clean($body['livraison_nom']),
                ':adr'   => clean($body['livraison_adresse']),
                ':cp'    => clean($body['livraison_cp']),
                ':ville' => clean($body['livraison_ville']),
            ]);
            $orders[] = (int) $db->lastInsertId();

            // Désactive l'annonce après achat (achat direct ou nego)
            if (in_array($item['type'], ['achat', 'nego'], true)) {
                $db->prepare("UPDATE annonces SET active = 0 WHERE id = :id")
                   ->execute([':id' => $item['annonce_id']]);
            }

            // Notification au vendeur
            createNotification(
                $db,
                $item['vendeur_id'],
                'achat_confirme',
                'Vente confirmée : ' . $item['titre'],
                "L'acheteur {$user['pseudo']} a validé l'achat.",
                "article.html?id={$item['annonce_id']}"
            );
            // Notification à l'acheteur
            createNotification(
                $db,
                $user['id'],
                'commande_validee',
                'Commande confirmée !',
                "Votre commande pour \"{$item['titre']}\" a bien été enregistrée.",
                ''
            );
        }

        // Vide le panier
        $db->prepare("DELETE FROM panier WHERE user_id = :uid")->execute([':uid' => $user['id']]);

        $db->commit();
        jsonOk([
            'order_ref'    => $orderRef,
            'transactions' => $orders,
            'message'      => 'Commande validée !',
        ]);
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function handleListTransactions(): void {
    $user = requireAuth();
    $db   = getDB();

    // Affiche les transactions où l'utilisateur est acheteur ou vendeur
    $stmt = $db->prepare(
        "SELECT t.id, t.order_ref, t.prix_final, t.quantite, t.type_vente, t.statut, t.created_at,
                a.titre AS annonce_titre, a.photo_url,
                u_a.pseudo AS acheteur, u_v.pseudo AS vendeur
         FROM transactions t
         JOIN annonces a  ON a.id  = t.annonce_id
         JOIN users u_a   ON u_a.id = t.acheteur_id
         JOIN users u_v   ON u_v.id = t.vendeur_id
         WHERE t.acheteur_id = :uid OR t.vendeur_id = :uid2
         ORDER BY t.created_at DESC"
    );
    $stmt->execute([':uid' => $user['id'], ':uid2' => $user['id']]);
    jsonOk($stmt->fetchAll());
}
