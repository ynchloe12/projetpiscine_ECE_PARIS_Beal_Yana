-- =============================================================
--  MERCATO NOVA — Script SQL complet
--  Compatible MySQL / MariaDB (WAMP)
--  Charset : utf8mb4
-- =============================================================

CREATE DATABASE IF NOT EXISTS mercato_nova
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE mercato_nova;

-- =============================================================
--  1. UTILISATEURS
-- =============================================================
CREATE TABLE IF NOT EXISTS users (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pseudo      VARCHAR(60)  NOT NULL UNIQUE,
  email       VARCHAR(120) NOT NULL UNIQUE,
  password    VARCHAR(255) NOT NULL,          -- bcrypt hash
  role        ENUM('admin','vendeur','client') NOT NULL DEFAULT 'client',
  adresse     VARCHAR(255) DEFAULT NULL,
  avatar_url  VARCHAR(500) DEFAULT NULL,
  blocked     TINYINT(1)   NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- =============================================================
--  2. ANNONCES / PRODUITS
-- =============================================================
CREATE TABLE IF NOT EXISTS annonces (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  vendeur_id  INT UNSIGNED NOT NULL,
  titre       VARCHAR(200) NOT NULL,
  description TEXT         DEFAULT NULL,
  categorie   VARCHAR(80)  DEFAULT NULL,
  genre       VARCHAR(80)  DEFAULT NULL,
  etat        ENUM('neuf','tres_bon_etat','bon_etat','occasion') NOT NULL DEFAULT 'bon_etat',
  type        ENUM('achat','enchere','nego') NOT NULL DEFAULT 'achat',
  prix        DECIMAL(10,2) NOT NULL,
  photo_url   VARCHAR(500)  DEFAULT NULL,
  flagged     TINYINT(1)    NOT NULL DEFAULT 0,
  active      TINYINT(1)    NOT NULL DEFAULT 1,
  created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (vendeur_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =============================================================
--  3. PANIER
-- =============================================================
CREATE TABLE IF NOT EXISTS panier (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  annonce_id  INT UNSIGNED NOT NULL,
  quantite    TINYINT UNSIGNED NOT NULL DEFAULT 1,
  prix_unitaire DECIMAL(10,2) NOT NULL,       -- prix au moment de l'ajout
  added_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_panier (user_id, annonce_id),
  FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
  FOREIGN KEY (annonce_id) REFERENCES annonces(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =============================================================
--  4. TRANSACTIONS (achats validés)
-- =============================================================
CREATE TABLE IF NOT EXISTS transactions (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  acheteur_id     INT UNSIGNED NOT NULL,
  annonce_id      INT UNSIGNED NOT NULL,
  vendeur_id      INT UNSIGNED NOT NULL,
  prix_final      DECIMAL(10,2) NOT NULL,
  quantite        TINYINT UNSIGNED NOT NULL DEFAULT 1,
  type_vente      ENUM('achat','enchere','nego') NOT NULL,
  statut          ENUM('en_attente','payee','annulee') NOT NULL DEFAULT 'en_attente',
  order_ref       VARCHAR(30) NOT NULL UNIQUE,
  -- Adresse de livraison (capturée au moment du paiement)
  livraison_nom   VARCHAR(100) DEFAULT NULL,
  livraison_adresse VARCHAR(255) DEFAULT NULL,
  livraison_cp    VARCHAR(10)  DEFAULT NULL,
  livraison_ville VARCHAR(100) DEFAULT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (acheteur_id) REFERENCES users(id) ON DELETE RESTRICT,
  FOREIGN KEY (annonce_id)  REFERENCES annonces(id) ON DELETE RESTRICT,
  FOREIGN KEY (vendeur_id)  REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- =============================================================
--  5. ENCHÈRES
-- =============================================================
CREATE TABLE IF NOT EXISTS encheres (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  annonce_id      INT UNSIGNED NOT NULL UNIQUE,
  prix_depart     DECIMAL(10,2) NOT NULL,
  prix_actuel     DECIMAL(10,2) NOT NULL,
  dernier_miseur  INT UNSIGNED DEFAULT NULL,
  nb_mises        INT UNSIGNED NOT NULL DEFAULT 0,
  date_fin        DATETIME NOT NULL,
  statut          ENUM('en_cours','terminee','annulee') NOT NULL DEFAULT 'en_cours',
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (annonce_id)     REFERENCES annonces(id) ON DELETE CASCADE,
  FOREIGN KEY (dernier_miseur) REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB;

-- Historique des mises
CREATE TABLE IF NOT EXISTS encheres_offres (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  enchere_id  INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NOT NULL,
  montant     DECIMAL(10,2) NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (enchere_id) REFERENCES encheres(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE
) ENGINE=InnoDB;

-- =============================================================
--  6. NÉGOCIATIONS
-- =============================================================
CREATE TABLE IF NOT EXISTS negociations (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  annonce_id      INT UNSIGNED NOT NULL,
  acheteur_id     INT UNSIGNED NOT NULL,
  nb_echanges     TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_echanges    TINYINT UNSIGNED NOT NULL DEFAULT 5,
  statut          ENUM('en_cours','acceptee','refusee','expiree','abandonnee') NOT NULL DEFAULT 'en_cours',
  prix_accorde    DECIMAL(10,2) DEFAULT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (annonce_id)  REFERENCES annonces(id) ON DELETE CASCADE,
  FOREIGN KEY (acheteur_id) REFERENCES users(id)    ON DELETE CASCADE
) ENGINE=InnoDB;

-- Messages d'une négociation
CREATE TABLE IF NOT EXISTS negociations_messages (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  negociation_id  INT UNSIGNED NOT NULL,
  auteur_id       INT UNSIGNED NOT NULL,
  role            ENUM('acheteur','vendeur','systeme') NOT NULL,
  type            ENUM('message','offre','contre_offre','accepte','refuse') NOT NULL DEFAULT 'message',
  contenu         TEXT NOT NULL,
  montant         DECIMAL(10,2) DEFAULT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (negociation_id) REFERENCES negociations(id) ON DELETE CASCADE,
  FOREIGN KEY (auteur_id)      REFERENCES users(id)        ON DELETE CASCADE
) ENGINE=InnoDB;

-- =============================================================
--  7. NOTIFICATIONS
-- =============================================================
CREATE TABLE IF NOT EXISTS notifications (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  type        VARCHAR(60)  NOT NULL,           -- ex: 'enchere_battue', 'nego_offre', 'achat_confirme'
  titre       VARCHAR(200) NOT NULL,
  message     TEXT         DEFAULT NULL,
  lue         TINYINT(1)   NOT NULL DEFAULT 0,
  lien        VARCHAR(300) DEFAULT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =============================================================
--  8. SESSIONS PHP (optionnel — si on utilise les sessions DB)
-- =============================================================
-- (On utilise les sessions fichier PHP par défaut sous WAMP)

-- =============================================================
--  DONNÉES DE DÉMO
-- =============================================================

-- Admin (password: admin2025)
INSERT IGNORE INTO users (pseudo, email, password, role) VALUES
('Admin', 'admin@mercatonova.fr',
 '$2y$12$eAGKTgKwIjwnFRVJLBBBcO7NxHE7r9sVA.Q1IKZ7c/G5OD3I8uZ9G',
 'admin');

-- Vendeur démo (password: vinyl2025)
INSERT IGNORE INTO users (pseudo, email, password, role) VALUES
('VinylCollector75', 'vinyl75@exemple.com',
 '$2y$12$D.gDrQjD3f4j4C6KYjcO3.gW1L.WY0KXLP7i0jJPST4OqGF9wSzuO',
 'vendeur');

-- Client démo (password: demo1234)
INSERT IGNORE INTO users (pseudo, email, password, role) VALUES
('DemoClient', 'client@exemple.com',
 '$2y$12$ov6K3LD1.C5cxs3W7Zu/5.yc1Y5IhX7MBxYXJGLmUVl/PaD3gAcmu',
 'client');
