<?php
// =============================================================
//  routes/auth.php — Inscription, connexion, déconnexion
// =============================================================

function handleRegister(array $body): void {
    required($body, 'pseudo', 'email', 'password');

    $pseudo   = clean($body['pseudo']);
    $email    = filter_var(trim($body['email']), FILTER_VALIDATE_EMAIL);
    $password = $body['password'];
    $role     = in_array($body['role'] ?? '', ['client', 'vendeur'], true) ? $body['role'] : 'client';

    if (!$email) {
        jsonError('Email invalide.');
    }
    if (strlen($password) < 6) {
        jsonError('Le mot de passe doit contenir au moins 6 caractères.');
    }

    $db = getDB();

    // Vérifie unicité
    $stmt = $db->prepare("SELECT id FROM users WHERE email = :email OR pseudo = :pseudo LIMIT 1");
    $stmt->execute([':email' => $email, ':pseudo' => $pseudo]);
    if ($stmt->fetch()) {
        jsonError('Pseudo ou email déjà utilisé.', 409);
    }

    $hash = hashPassword($password);
    $stmt = $db->prepare(
        "INSERT INTO users (pseudo, email, password, role) VALUES (:pseudo, :email, :password, :role)"
    );
    $stmt->execute([':pseudo' => $pseudo, ':email' => $email, ':password' => $hash, ':role' => $role]);
    $id = (int) $db->lastInsertId();

    $user = [
        'id'     => $id,
        'pseudo' => $pseudo,
        'email'  => $email,
        'role'   => $role,
    ];

    startSession();
    $_SESSION['mn_user'] = $user;

    jsonOk($user, 201);
}

function handleLogin(array $body): void {
    required($body, 'email', 'password');

    $email    = trim($body['email']);
    $password = $body['password'];

    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if (!$user || !verifyPassword($password, $user['password'])) {
        jsonError('Identifiants incorrects.', 401);
    }
    if ($user['blocked']) {
        jsonError('Ce compte a été bloqué.', 403);
    }

    $sessionUser = [
        'id'     => (int) $user['id'],
        'pseudo' => $user['pseudo'],
        'email'  => $user['email'],
        'role'   => $user['role'],
    ];

    startSession();
    session_regenerate_id(true);
    $_SESSION['mn_user'] = $sessionUser;

    jsonOk($sessionUser);
}

function handleLogout(): void {
    startSession();
    session_unset();
    session_destroy();
    jsonOk(['message' => 'Déconnecté.']);
}

function handleMe(): void {
    $user = currentUser();
    if (!$user) {
        jsonOk(null);   // Pas d'erreur — juste null si non connecté
        return;
    }
    // Recharge depuis la BDD pour les infos fraîches
    $db   = getDB();
    $stmt = $db->prepare("SELECT id, pseudo, email, role, adresse, avatar_url, blocked FROM users WHERE id = :id");
    $stmt->execute([':id' => $user['id']]);
    $row = $stmt->fetch();
    if (!$row) {
        startSession();
        session_destroy();
        jsonOk(null);
        return;
    }
    jsonOk($row);
}
