<?php
// =============================================================
//  routes/negociations.php — Vente par négociation
// =============================================================

const MAX_ECHANGES = 5;

function handleListNegociations(): void {
    $user = requireAuth();
    $db   = getDB();

    // Acheteur voit ses propres négos, vendeur voit celles sur ses annonces
    $stmt = $db->prepare(
        "SELECT n.id, n.statut, n.nb_echanges, n.max_echanges, n.prix_accorde, n.created_at,
                a.id AS annonce_id, a.titre, a.prix AS prix_initial, a.photo_url,
                u_a.pseudo AS acheteur, u_v.pseudo AS vendeur
         FROM negociations n
         JOIN annonces a   ON a.id = n.annonce_id
         JOIN users u_a    ON u_a.id = n.acheteur_id
         JOIN users u_v    ON u_v.id = a.vendeur_id
         WHERE n.acheteur_id = :uid
            OR a.vendeur_id = :uid2
         ORDER BY n.updated_at DESC"
    );
    $stmt->execute([':uid' => $user['id'], ':uid2' => $user['id']]);
    jsonOk($stmt->fetchAll());
}

function handleGetNegociation(): void {
    $user = requireAuth();
    $id   = (int)($_GET['id'] ?? 0);
    if (!$id) jsonError('Paramètre id manquant.');

    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT n.*, a.titre, a.prix AS prix_initial, a.photo_url,
                a.vendeur_id, u_a.pseudo AS acheteur, u_v.pseudo AS vendeur
         FROM negociations n
         JOIN annonces a ON a.id = n.annonce_id
         JOIN users u_a  ON u_a.id = n.acheteur_id
         JOIN users u_v  ON u_v.id = a.vendeur_id
         WHERE n.id = :id"
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    if (!$row) jsonError('Négociation introuvable.', 404);
    if ($row['acheteur_id'] !== $user['id'] && $row['vendeur_id'] !== $user['id'] && $user['role'] !== 'admin') {
        jsonError('Accès refusé.', 403);
    }

    // Messages
    $msgs = $db->prepare(
        "SELECT nm.*, u.pseudo
         FROM negociations_messages nm
         JOIN users u ON u.id = nm.auteur_id
         WHERE nm.negociation_id = :nid ORDER BY nm.created_at ASC"
    );
    $msgs->execute([':nid' => $id]);
    $row['messages'] = $msgs->fetchAll();

    jsonOk($row);
}

function handleCreateNegociation(array $body): void {
    $user = requireAuth();
    required($body, 'annonce_id');

    $annonceId = (int)$body['annonce_id'];
    $db        = getDB();

    $stmt = $db->prepare("SELECT id, type, vendeur_id, active, flagged FROM annonces WHERE id = :id");
    $stmt->execute([':id' => $annonceId]);
    $annonce = $stmt->fetch();

    if (!$annonce) jsonError('Annonce introuvable.', 404);
    if ($annonce['type'] !== 'nego') jsonError('Cette annonce ne supporte pas la négociation.');
    if (!$annonce['active'] || $annonce['flagged']) jsonError('Annonce non disponible.');
    if ($annonce['vendeur_id'] === $user['id']) jsonError('Vous ne pouvez pas négocier votre propre annonce.');

    // Vérifier qu'il n'y a pas déjà une négo active pour cet acheteur/annonce
    $chk = $db->prepare(
        "SELECT id FROM negociations WHERE annonce_id = :aid AND acheteur_id = :uid AND statut = 'en_cours'"
    );
    $chk->execute([':aid' => $annonceId, ':uid' => $user['id']]);
    if ($chk->fetch()) jsonError('Vous avez déjà une négociation en cours pour cet article.');

    $db->beginTransaction();
    try {
        $insert = $db->prepare(
            "INSERT INTO negociations (annonce_id, acheteur_id, max_echanges) VALUES (:aid, :uid, :max)"
        );
        $insert->execute([':aid' => $annonceId, ':uid' => $user['id'], ':max' => MAX_ECHANGES]);
        $negoId = (int)$db->lastInsertId();

        // Message système d'ouverture
        _addMessage($db, $negoId, $user['id'], 'systeme', 'message', 'Négociation ouverte.');

        // Notifie le vendeur
        createNotification($db, $annonce['vendeur_id'], 'nego_nouvelle',
            'Nouvelle demande de négociation',
            "{$user['pseudo']} souhaite négocier.",
            "article.html?id=$annonceId"
        );

        $db->commit();
        jsonOk(['id' => $negoId, 'message' => 'Négociation ouverte.'], 201);
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function handleSendOffre(array $body): void {
    $user = requireAuth();
    required($body, 'negociation_id', 'montant');

    $negoId  = (int)$body['negociation_id'];
    $montant = (float)$body['montant'];
    $db      = getDB();

    $db->beginTransaction();
    try {
        $nego = _getNego($db, $negoId, $user['id'], 'acheteur');

        if ($nego['nb_echanges'] >= $nego['max_echanges']) {
            $db->prepare("UPDATE negociations SET statut='expiree' WHERE id=:id")
               ->execute([':id' => $negoId]);
            $db->commit();
            jsonError('Nombre maximum d\'échanges atteint. Négociation expirée.');
        }
        if ($montant <= 0 || $montant > $nego['prix_initial']) {
            jsonError("L'offre doit être entre 1 € et {$nego['prix_initial']} €.");
        }

        $db->prepare(
            "UPDATE negociations SET nb_echanges = nb_echanges + 1 WHERE id = :id"
        )->execute([':id' => $negoId]);

        _addMessage($db, $negoId, $user['id'], 'acheteur', 'offre', "Offre proposée : $montant €", $montant);

        // Notifie le vendeur
        createNotification($db, $nego['vendeur_id'], 'nego_offre',
            'Nouvelle offre de négociation',
            "{$nego['acheteur']} propose $montant € pour \"{$nego['titre']}\".",
            "article.html?id={$nego['annonce_id']}"
        );

        $db->commit();
        jsonOk(['message' => 'Offre envoyée.']);
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function handleRepondreOffre(array $body): void {
    $user = requireAuth();
    required($body, 'negociation_id', 'reponse'); // reponse: 'accepter' | 'refuser' | 'contre_offre'

    $negoId  = (int)$body['negociation_id'];
    $reponse = $body['reponse'];
    $db      = getDB();

    $db->beginTransaction();
    try {
        $nego = _getNego($db, $negoId, $user['id'], 'vendeur');

        switch ($reponse) {
            case 'accepter':
                // Récupère la dernière offre de l'acheteur
                $lastOffer = $db->prepare(
                    "SELECT montant FROM negociations_messages
                     WHERE negociation_id = :nid AND type = 'offre'
                     ORDER BY created_at DESC LIMIT 1"
                );
                $lastOffer->execute([':nid' => $negoId]);
                $offerRow = $lastOffer->fetch();
                $prix     = $offerRow ? (float)$offerRow['montant'] : $nego['prix_initial'];

                $db->prepare(
                    "UPDATE negociations SET statut='acceptee', prix_accorde=:prix WHERE id=:id"
                )->execute([':prix' => $prix, ':id' => $negoId]);

                _addMessage($db, $negoId, $user['id'], 'vendeur', 'accepte', "Offre acceptée à $prix €.", $prix);

                createNotification($db, $nego['acheteur_id'], 'nego_acceptee',
                    'Offre acceptée !',
                    "{$nego['vendeur']} a accepté votre offre à $prix €.",
                    "panier.html"
                );
                break;

            case 'refuser':
                $db->prepare("UPDATE negociations SET statut='refusee' WHERE id=:id")
                   ->execute([':id' => $negoId]);

                _addMessage($db, $negoId, $user['id'], 'vendeur', 'refuse', 'Offre refusée.');

                createNotification($db, $nego['acheteur_id'], 'nego_refusee',
                    'Offre refusée',
                    "{$nego['vendeur']} a refusé votre offre.",
                    "article.html?id={$nego['annonce_id']}"
                );
                break;

            case 'contre_offre':
                required($body, 'montant');
                $montant = (float)$body['montant'];

                if ($nego['nb_echanges'] >= $nego['max_echanges']) {
                    $db->prepare("UPDATE negociations SET statut='expiree' WHERE id=:id")
                       ->execute([':id' => $negoId]);
                    $db->commit();
                    jsonError('Nombre maximum d\'échanges atteint.');
                }

                $db->prepare(
                    "UPDATE negociations SET nb_echanges = nb_echanges + 1 WHERE id = :id"
                )->execute([':id' => $negoId]);

                _addMessage($db, $negoId, $user['id'], 'vendeur', 'contre_offre',
                    "Contre-offre : $montant €", $montant);

                createNotification($db, $nego['acheteur_id'], 'nego_contre_offre',
                    'Contre-offre reçue',
                    "{$nego['vendeur']} propose $montant €.",
                    "article.html?id={$nego['annonce_id']}"
                );
                break;

            default:
                jsonError('Réponse invalide. Valeurs: accepter, refuser, contre_offre.');
        }

        $db->commit();
        jsonOk(['message' => 'Réponse envoyée.']);
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function handleAbandonner(array $body): void {
    $user   = requireAuth();
    $negoId = (int)($body['negociation_id'] ?? 0);
    if (!$negoId) jsonError('Paramètre negociation_id manquant.');

    $db = getDB();
    $stmt = $db->prepare(
        "SELECT n.*, a.vendeur_id, a.titre
         FROM negociations n JOIN annonces a ON a.id = n.annonce_id
         WHERE n.id = :id AND n.statut = 'en_cours'"
    );
    $stmt->execute([':id' => $negoId]);
    $nego = $stmt->fetch();

    if (!$nego) jsonError('Négociation introuvable ou déjà terminée.', 404);
    if ($nego['acheteur_id'] !== $user['id'] && $nego['vendeur_id'] !== $user['id']) {
        jsonError('Non autorisé.', 403);
    }

    $db->prepare("UPDATE negociations SET statut='abandonnee' WHERE id=:id")->execute([':id' => $negoId]);

    $otherParty = ($nego['acheteur_id'] === $user['id']) ? $nego['vendeur_id'] : $nego['acheteur_id'];
    createNotification($db, $otherParty, 'nego_abandonnee',
        'Négociation abandonnée',
        "La négociation pour \"{$nego['titre']}\" a été abandonnée.",
        ''
    );

    jsonOk(['message' => 'Négociation abandonnée.']);
}

// ── Helpers internes ─────────────────────────────────────────

function _getNego(PDO $db, int $negoId, int $userId, string $expectedRole): array {
    $stmt = $db->prepare(
        "SELECT n.*, a.vendeur_id, a.titre, a.annonce_id AS annonce_id_ref,
                a.prix AS prix_initial, a.id AS annonce_id,
                u_a.pseudo AS acheteur, u_v.pseudo AS vendeur
         FROM negociations n
         JOIN annonces a ON a.id = n.annonce_id
         JOIN users u_a  ON u_a.id = n.acheteur_id
         JOIN users u_v  ON u_v.id = a.vendeur_id
         WHERE n.id = :id AND n.statut = 'en_cours'
         FOR UPDATE"
    );
    $stmt->execute([':id' => $negoId]);
    $nego = $stmt->fetch();

    if (!$nego) jsonError('Négociation introuvable ou déjà terminée.', 404);

    if ($expectedRole === 'acheteur' && $nego['acheteur_id'] !== $userId) {
        jsonError('Seul l\'acheteur peut envoyer une offre.', 403);
    }
    if ($expectedRole === 'vendeur' && $nego['vendeur_id'] !== $userId) {
        jsonError('Seul le vendeur peut répondre.', 403);
    }

    return $nego;
}

function _addMessage(PDO $db, int $negoId, int $auteurId, string $role, string $type, string $contenu, ?float $montant = null): void {
    $db->prepare(
        "INSERT INTO negociations_messages (negociation_id, auteur_id, role, type, contenu, montant)
         VALUES (:nid, :uid, :role, :type, :contenu, :montant)"
    )->execute([
        ':nid'     => $negoId,
        ':uid'     => $auteurId,
        ':role'    => $role,
        ':type'    => $type,
        ':contenu' => $contenu,
        ':montant' => $montant,
    ]);
}
