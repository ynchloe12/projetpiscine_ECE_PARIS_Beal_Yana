<?php
// =============================================================
//  routes/users.php — Profil utilisateur
// =============================================================

function handleGetProfile(): void {
    $user = requireAuth();
    $db   = getDB();

    $stmt = $db->prepare(
        "SELECT id, pseudo, email, role, adresse, avatar_url, created_at FROM users WHERE id = :id"
    );
    $stmt->execute([':id' => $user['id']]);
    $row = $stmt->fetch();

    if (!$row) {
        jsonError('Utilisateur introuvable.', 404);
    }

    // Annonces du vendeur
    $stmt2 = $db->prepare(
        "SELECT id, titre, type, prix, photo_url, created_at, active, flagged
         FROM annonces WHERE vendeur_id = :vid ORDER BY created_at DESC"
    );
    $stmt2->execute([':vid' => $user['id']]);
    $row['annonces'] = $stmt2->fetchAll();

    jsonOk($row);
}

function handleUpdateProfile(array $body): void {
    $user = requireAuth();
    $db   = getDB();

    $fields  = [];
    $params  = [':id' => $user['id']];

    if (isset($body['pseudo']) && $body['pseudo'] !== '') {
        $pseudo = clean($body['pseudo']);
        // Vérifie unicité (sauf pour soi-même)
        $chk = $db->prepare("SELECT id FROM users WHERE pseudo = :p AND id != :id");
        $chk->execute([':p' => $pseudo, ':id' => $user['id']]);
        if ($chk->fetch()) {
            jsonError('Ce pseudo est déjà pris.', 409);
        }
        $fields[] = 'pseudo = :pseudo';
        $params[':pseudo'] = $pseudo;
    }

    if (isset($body['email']) && $body['email'] !== '') {
        $email = filter_var(trim($body['email']), FILTER_VALIDATE_EMAIL);
        if (!$email) {
            jsonError('Email invalide.');
        }
        $chk = $db->prepare("SELECT id FROM users WHERE email = :e AND id != :id");
        $chk->execute([':e' => $email, ':id' => $user['id']]);
        if ($chk->fetch()) {
            jsonError('Cet email est déjà utilisé.', 409);
        }
        $fields[] = 'email = :email';
        $params[':email'] = $email;
    }

    if (isset($body['adresse'])) {
        $fields[] = 'adresse = :adresse';
        $params[':adresse'] = clean($body['adresse']);
    }

    if (!empty($body['password'])) {
        if (strlen($body['password']) < 6) {
            jsonError('Mot de passe trop court (min 6 caractères).');
        }
        $fields[] = 'password = :password';
        $params[':password'] = hashPassword($body['password']);
    }

    if (isset($body['avatar_url'])) {
        $fields[] = 'avatar_url = :avatar_url';
        $params[':avatar_url'] = $body['avatar_url'];
    }

    if (empty($fields)) {
        jsonError('Aucune modification envoyée.');
    }

    $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id';
    $db->prepare($sql)->execute($params);

    // Met à jour la session
    if (isset($params[':pseudo'])) {
        $_SESSION['mn_user']['pseudo'] = $params[':pseudo'];
    }
    if (isset($params[':email'])) {
        $_SESSION['mn_user']['email'] = $params[':email'];
    }

    jsonOk(['message' => 'Profil mis à jour.']);
}
