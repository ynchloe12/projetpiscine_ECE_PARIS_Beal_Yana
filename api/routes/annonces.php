<?php
// =============================================================
//  routes/annonces.php — Catalogue & annonces vendeur
// =============================================================

function handleListAnnonces(): void {
    $db = getDB();

    // Paramètres de filtrage/tri (GET)
    $search   = trim($_GET['q']     ?? '');
    $cat      = trim($_GET['cat']   ?? '');
    $type     = trim($_GET['type']  ?? '');
    $etat     = trim($_GET['etat']  ?? '');
    $sort     = $_GET['sort']       ?? 'recent';    // recent | prix_asc | prix_desc
    $page     = max(1, (int)($_GET['page'] ?? 1));
    $perPage  = 20;
    $offset   = ($page - 1) * $perPage;

    $where  = ['a.active = 1', 'a.flagged = 0'];
    $params = [];

    if ($search !== '') {
        $where[]          = '(a.titre LIKE :q OR a.description LIKE :q2)';
        $params[':q']     = "%$search%";
        $params[':q2']    = "%$search%";
    }
    if ($cat !== '') {
        $where[]        = 'a.categorie = :cat';
        $params[':cat'] = $cat;
    }
    if ($type !== '') {
        $where[]         = 'a.type = :type';
        $params[':type'] = $type;
    }
    if ($etat !== '') {
        $where[]         = 'a.etat = :etat';
        $params[':etat'] = $etat;
    }

    $whereSQL = implode(' AND ', $where);

    $orderSQL = match($sort) {
        'prix_asc'  => 'a.prix ASC',
        'prix_desc' => 'a.prix DESC',
        default     => 'a.created_at DESC',
    };

    // Compte total (pagination)
    $countStmt = $db->prepare("SELECT COUNT(*) FROM annonces a WHERE $whereSQL");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    // Données
    $sql = "
        SELECT a.id, a.titre, a.categorie, a.etat, a.type, a.prix, a.photo_url,
               a.created_at, u.pseudo AS vendeur
        FROM annonces a
        JOIN users u ON u.id = a.vendeur_id
        WHERE $whereSQL
        ORDER BY $orderSQL
        LIMIT :limit OFFSET :offset
    ";
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    // Pour les enchères en cours, on ajoute le prix actuel
    foreach ($rows as &$row) {
        if ($row['type'] === 'enchere') {
            $e = $db->prepare("SELECT prix_actuel, nb_mises, date_fin, statut FROM encheres WHERE annonce_id = :aid");
            $e->execute([':aid' => $row['id']]);
            $enc = $e->fetch();
            if ($enc) {
                $row['enchere_prix_actuel'] = $enc['prix_actuel'];
                $row['enchere_nb_mises']    = $enc['nb_mises'];
                $row['enchere_date_fin']    = $enc['date_fin'];
                $row['enchere_statut']      = $enc['statut'];
            }
        }
    }
    unset($row);

    jsonOk([
        'items'      => $rows,
        'total'      => $total,
        'page'       => $page,
        'per_page'   => $perPage,
        'last_page'  => (int) ceil($total / $perPage),
    ]);
}

function handleGetAnnonce(): void {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) {
        jsonError('Paramètre id manquant.');
    }

    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT a.*, u.pseudo AS vendeur, u.avatar_url AS vendeur_avatar
         FROM annonces a JOIN users u ON u.id = a.vendeur_id
         WHERE a.id = :id"
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    if (!$row) {
        jsonError('Annonce introuvable.', 404);
    }

    // Enchère associée
    if ($row['type'] === 'enchere') {
        $e = $db->prepare(
            "SELECT e.*, u.pseudo AS top_miseur
             FROM encheres e
             LEFT JOIN users u ON u.id = e.dernier_miseur
             WHERE e.annonce_id = :aid"
        );
        $e->execute([':aid' => $id]);
        $row['enchere'] = $e->fetch() ?: null;

        if ($row['enchere']) {
            $h = $db->prepare(
                "SELECT eo.montant, u.pseudo, eo.created_at
                 FROM encheres_offres eo JOIN users u ON u.id = eo.user_id
                 WHERE eo.enchere_id = :eid ORDER BY eo.created_at DESC LIMIT 20"
            );
            $h->execute([':eid' => $row['enchere']['id']]);
            $row['enchere']['historique'] = $h->fetchAll();
        }
    }

    jsonOk($row);
}

function handleCreateAnnonce(array $body): void {
    $user = requireAuth();

    // Seuls vendeur et admin peuvent créer des annonces
    if (!in_array($user['role'], ['vendeur', 'admin'], true)) {
        jsonError('Seuls les vendeurs peuvent publier des annonces.', 403);
    }

    required($body, 'titre', 'categorie', 'etat', 'type', 'prix');

    $type = $body['type'];
    if (!in_array($type, ['achat', 'enchere', 'nego'], true)) {
        jsonError('Type de vente invalide.');
    }

    $prix = (float)$body['prix'];
    if ($prix <= 0) {
        jsonError('Prix invalide.');
    }

    $db = getDB();
    $db->beginTransaction();

    try {
        $stmt = $db->prepare(
            "INSERT INTO annonces (vendeur_id, titre, description, categorie, genre, etat, type, prix, photo_url)
             VALUES (:vid, :titre, :desc, :cat, :genre, :etat, :type, :prix, :photo)"
        );
        $stmt->execute([
            ':vid'   => $user['id'],
            ':titre' => clean($body['titre']),
            ':desc'  => isset($body['description']) ? clean($body['description']) : null,
            ':cat'   => clean($body['categorie']),
            ':genre' => isset($body['genre']) ? clean($body['genre']) : null,
            ':etat'  => $body['etat'],
            ':type'  => $type,
            ':prix'  => $prix,
            ':photo' => $body['photo_url'] ?? null,
        ]);
        $annonceId = (int) $db->lastInsertId();

        // Si enchère : créer l'entrée dans la table encheres
        if ($type === 'enchere') {
            $dateFin = $body['date_fin'] ?? null;
            if (!$dateFin) {
                // Durée par défaut : 2h
                $dateFin = date('Y-m-d H:i:s', strtotime('+2 hours'));
            }
            $eStmt = $db->prepare(
                "INSERT INTO encheres (annonce_id, prix_depart, prix_actuel, date_fin)
                 VALUES (:aid, :pd, :pc, :df)"
            );
            $eStmt->execute([
                ':aid' => $annonceId,
                ':pd'  => $prix,
                ':pc'  => $prix,
                ':df'  => $dateFin,
            ]);
        }

        $db->commit();
        jsonOk(['id' => $annonceId, 'message' => 'Annonce publiée.'], 201);
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function handleUpdateAnnonce(array $body): void {
    $user = requireAuth();
    $id   = (int)($body['id'] ?? 0);
    if (!$id) {
        jsonError('Paramètre id manquant.');
    }

    $db   = getDB();
    $stmt = $db->prepare("SELECT vendeur_id FROM annonces WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row  = $stmt->fetch();

    if (!$row) {
        jsonError('Annonce introuvable.', 404);
    }
    if ($row['vendeur_id'] !== $user['id'] && $user['role'] !== 'admin') {
        jsonError('Non autorisé.', 403);
    }

    $fields = [];
    $params = [':id' => $id];

    foreach (['titre', 'description', 'categorie', 'genre', 'etat', 'photo_url'] as $f) {
        if (isset($body[$f])) {
            $fields[]     = "$f = :$f";
            $params[":$f"] = clean((string)$body[$f]);
        }
    }
    if (isset($body['prix']) && (float)$body['prix'] > 0) {
        $fields[]      = 'prix = :prix';
        $params[':prix'] = (float)$body['prix'];
    }

    if (empty($fields)) {
        jsonError('Aucune modification envoyée.');
    }

    $db->prepare('UPDATE annonces SET ' . implode(', ', $fields) . ' WHERE id = :id')
       ->execute($params);

    jsonOk(['message' => 'Annonce mise à jour.']);
}

function handleDeleteAnnonce(array $body): void {
    $user = requireAuth();
    $id   = (int)($body['id'] ?? 0);
    if (!$id) {
        jsonError('Paramètre id manquant.');
    }

    $db   = getDB();
    $stmt = $db->prepare("SELECT vendeur_id FROM annonces WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row  = $stmt->fetch();

    if (!$row) {
        jsonError('Annonce introuvable.', 404);
    }
    if ($row['vendeur_id'] !== $user['id'] && $user['role'] !== 'admin') {
        jsonError('Non autorisé.', 403);
    }

    $db->prepare("DELETE FROM annonces WHERE id = :id")->execute([':id' => $id]);
    jsonOk(['message' => 'Annonce supprimée.']);
}

function handleMesAnnonces(): void {
    $user = requireAuth();
    $db   = getDB();

    $stmt = $db->prepare(
        "SELECT id, titre, type, prix, photo_url, etat, active, flagged, created_at
         FROM annonces WHERE vendeur_id = :vid ORDER BY created_at DESC"
    );
    $stmt->execute([':vid' => $user['id']]);
    jsonOk($stmt->fetchAll());
}
