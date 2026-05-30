<?php
// =============================================================
//  routes/encheres.php — Vente par enchère
// =============================================================

function handleListEncheres(): void {
    $db = getDB();

    $statut = trim($_GET['statut'] ?? 'en_cours');
    $params = [];
    $where  = ['a.type = \'enchere\'', 'a.flagged = 0'];

    if ($statut) {
        $where[]          = 'e.statut = :statut';
        $params[':statut'] = $statut;
    }

    $whereSQL = implode(' AND ', $where);
    $stmt = $db->prepare(
        "SELECT a.id, a.titre, a.photo_url, a.prix AS prix_depart, a.categorie,
                e.prix_actuel, e.nb_mises, e.date_fin, e.statut AS enchere_statut,
                u.pseudo AS vendeur
         FROM annonces a
         JOIN encheres e ON e.annonce_id = a.id
         JOIN users u    ON u.id = a.vendeur_id
         WHERE $whereSQL
         ORDER BY e.date_fin ASC"
    );
    $stmt->execute($params);

    // Clôture automatique des enchères expirées
    $db->prepare(
        "UPDATE encheres SET statut = 'terminee' WHERE statut = 'en_cours' AND date_fin < NOW()"
    )->execute();

    jsonOk($stmt->fetchAll());
}

function handleGetEnchere(): void {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonError('Paramètre id manquant.');

    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT a.*, e.id AS enchere_id, e.prix_depart, e.prix_actuel, e.nb_mises,
                e.date_fin, e.statut AS enchere_statut, e.dernier_miseur,
                u.pseudo AS vendeur,
                u2.pseudo AS top_miseur
         FROM annonces a
         JOIN encheres e ON e.annonce_id = a.id
         JOIN users u    ON u.id = a.vendeur_id
         LEFT JOIN users u2 ON u2.id = e.dernier_miseur
         WHERE a.id = :id"
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) jsonError('Enchère introuvable.', 404);

    // Historique des offres
    $h = $db->prepare(
        "SELECT eo.montant, u.pseudo, eo.created_at
         FROM encheres_offres eo
         JOIN users u ON u.id = eo.user_id
         WHERE eo.enchere_id = :eid
         ORDER BY eo.created_at DESC LIMIT 20"
    );
    $h->execute([':eid' => $row['enchere_id']]);
    $row['historique'] = $h->fetchAll();

    jsonOk($row);
}

function handlePlaceBid(array $body): void {
    $user = requireAuth();
    required($body, 'annonce_id', 'montant');

    $annonceId = (int)$body['annonce_id'];
    $montant   = (float)$body['montant'];
    $db        = getDB();

    // Verrouillage de la ligne enchère pour éviter les concurrences
    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            "SELECT e.id, e.prix_actuel, e.date_fin, e.statut, a.vendeur_id
             FROM encheres e
             JOIN annonces a ON a.id = e.annonce_id
             WHERE e.annonce_id = :aid
             FOR UPDATE"
        );
        $stmt->execute([':aid' => $annonceId]);
        $enc = $stmt->fetch();

        if (!$enc) jsonError('Enchère introuvable.', 404);
        if ($enc['statut'] !== 'en_cours') jsonError('Cette enchère est terminée.');
        if (new DateTime($enc['date_fin']) < new DateTime()) {
            // Clôture automatique
            $db->prepare("UPDATE encheres SET statut='terminee' WHERE id=:id")
               ->execute([':id' => $enc['id']]);
            $db->commit();
            jsonError('Cette enchère est expirée.');
        }
        if ($enc['vendeur_id'] === $user['id']) {
            jsonError('Vous ne pouvez pas enchérir sur votre propre annonce.');
        }
        if ($montant <= $enc['prix_actuel']) {
            jsonError("Mise minimum : " . ($enc['prix_actuel'] + 1) . " €");
        }

        // Met à jour l'enchère
        $db->prepare(
            "UPDATE encheres
             SET prix_actuel = :prix, dernier_miseur = :uid, nb_mises = nb_mises + 1
             WHERE id = :eid"
        )->execute([':prix' => $montant, ':uid' => $user['id'], ':eid' => $enc['id']]);

        // Historise l'offre
        $db->prepare(
            "INSERT INTO encheres_offres (enchere_id, user_id, montant) VALUES (:eid, :uid, :m)"
        )->execute([':eid' => $enc['id'], ':uid' => $user['id'], ':m' => $montant]);

        // Met à jour le prix de l'annonce pour cohérence affichage
        $db->prepare("UPDATE annonces SET prix = :prix WHERE id = :id")
           ->execute([':prix' => $montant, ':id' => $annonceId]);

        // Notifie le vendeur
        createNotification($db, $enc['vendeur_id'], 'enchere_nouvelle_mise',
            'Nouvelle enchère sur votre article',
            "{$user['pseudo']} a misé $montant €.",
            "article.html?id=$annonceId"
        );

        $db->commit();
        jsonOk(['prix_actuel' => $montant, 'message' => "Enchère placée à $montant € !"]);
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function handleCloseEnchere(array $body): void {
    // Peut être appelé manuellement par un admin ou par un cron simulé
    requireRole('admin');
    $annonceId = (int)($body['annonce_id'] ?? 0);
    if (!$annonceId) jsonError('Paramètre annonce_id manquant.');

    $db = getDB();
    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            "SELECT e.id, e.prix_actuel, e.dernier_miseur, a.vendeur_id, a.titre
             FROM encheres e JOIN annonces a ON a.id = e.annonce_id
             WHERE e.annonce_id = :aid AND e.statut = 'en_cours'
             FOR UPDATE"
        );
        $stmt->execute([':aid' => $annonceId]);
        $enc = $stmt->fetch();
        if (!$enc) jsonError('Enchère non trouvée ou déjà terminée.', 404);

        $db->prepare("UPDATE encheres SET statut='terminee' WHERE id=:id")
           ->execute([':id' => $enc['id']]);

        $db->prepare("UPDATE annonces SET active=0 WHERE id=:id")
           ->execute([':id' => $annonceId]);

        if ($enc['dernier_miseur']) {
            // Notifie le gagnant
            createNotification($db, $enc['dernier_miseur'], 'enchere_gagnee',
                'Vous avez remporté l\'enchère !',
                "Félicitations ! Vous avez remporté \"{$enc['titre']}\" pour {$enc['prix_actuel']} €.",
                "panier.html"
            );
            // Notifie le vendeur
            createNotification($db, $enc['vendeur_id'], 'enchere_terminee',
                'Enchère terminée',
                "L'enchère pour \"{$enc['titre']}\" est conclue à {$enc['prix_actuel']} €.",
                "profil_utilisateur.html"
            );
        }

        $db->commit();
        jsonOk(['message' => 'Enchère clôturée.', 'gagnant_id' => $enc['dernier_miseur']]);
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}
