# 🛠️ Guide d'installation — Mercato Nova (WAMP)

## Prérequis

- **WAMP Server** installé (v3.3+ recommandé) — [wampserver.com](https://www.wampserver.com)
- PHP 8.0+ (inclus dans WAMP)
- MySQL 5.7+ ou MariaDB 10.5+ (inclus dans WAMP)
- Navigateur moderne (Chrome, Firefox, Edge)

---

## Étape 1 — Copier les fichiers dans WAMP

1. Ouvrez le dossier **`C:\wamp64\www\`** (ou `C:\wamp\www\` selon votre installation).
2. Créez un dossier **`mercato_nova`**.
3. Copiez **l'ensemble des fichiers HTML/images** du projet existant dans `C:\wamp64\www\mercato_nova\`.
4. Copiez le dossier **`api\`**, le dossier **`config\`**, le fichier **`api-client.js`** et le fichier **`.htaccess`** dans ce même dossier.

Structure finale attendue :
```
C:\wamp64\www\mercato_nova\
├── .htaccess
├── api-client.js
├── index_mercatonova.html
├── catalogue.html
├── article.html
├── encheres.html
├── panier.html
├── creation_annonce.html
├── creation_profil.html
├── profil_utilisateur.html
├── admin.html
├── [images ...]
├── api\
│   ├── index.php
│   ├── helpers.php
│   └── routes\
│       ├── auth.php
│       ├── users.php
│       ├── annonces.php
│       ├── panier.php
│       ├── transactions.php
│       ├── encheres.php
│       ├── negociations.php
│       ├── notifications.php
│       └── admin.php
├── config\
│   ├── db.php
│   └── auth.php
└── sql\
    └── mercato_nova.sql
```

---

## Étape 2 — Créer la base de données

### Via phpMyAdmin (recommandé)

1. Démarrez WAMP (icône verte dans la barre des tâches).
2. Ouvrez **phpMyAdmin** : [http://localhost/phpmyadmin](http://localhost/phpmyadmin)
3. Identifiants par défaut : **root** / *(pas de mot de passe)*
4. Cliquez sur **Importer** (onglet du haut).
5. Cliquez sur **Choisir un fichier** et sélectionnez `sql/mercato_nova.sql`.
6. Cliquez sur **Exécuter**.

La base de données `mercato_nova` est créée avec toutes les tables et trois comptes de démo.

### Via la ligne de commande MySQL (alternatif)

```bash
mysql -u root -p < C:\wamp64\www\mercato_nova\sql\mercato_nova.sql
```

---

## Étape 3 — Vérifier la configuration PHP

Ouvrez **`config/db.php`** et vérifiez / adaptez ces constantes :

```php
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'mercato_nova');
define('DB_USER', 'root');
define('DB_PASS', '');          // Laissez vide si WAMP par défaut
```

Si vous avez défini un mot de passe root dans WAMP, renseignez-le dans `DB_PASS`.

---

## Étape 4 — Inclure api-client.js dans les pages HTML

Ajoutez la ligne suivante dans le `<head>` ou juste avant `</body>` de **chaque fichier HTML** :

```html
<script src="api-client.js"></script>
```

Exemple pour `index_mercatonova.html` :
```html
<!-- Ajouter avant le </body> -->
<script src="api-client.js"></script>
```

> Le script remplace automatiquement les appels `localStorage` par des appels API PHP,
> tout en maintenant la compatibilité avec le code existant.

---

## Étape 5 — Lancer le site

Ouvrez dans votre navigateur :

```
http://localhost/mercato_nova/index_mercatonova.html
```

---

## Comptes de démo

| Rôle    | Email                      | Mot de passe |
|---------|----------------------------|-------------|
| Admin   | admin@mercatonova.fr       | admin2025   |
| Vendeur | vinyl75@exemple.com        | vinyl2025   |
| Client  | client@exemple.com         | demo1234    |

---

## Tester l'API directement

Tous les endpoints sont accessibles via :
```
http://localhost/mercato_nova/api/index.php?route=<route>
```

Exemples :
- `GET http://localhost/mercato_nova/api/index.php?route=annonces` — liste des annonces
- `POST .../api/index.php?route=auth/login` avec `{"email":"...","password":"..."}`
- `GET .../api/index.php?route=auth/me` — utilisateur connecté

---

## Endpoints disponibles

### Auth
| Méthode | Route | Description |
|---------|-------|-------------|
| POST | `auth/register` | Créer un compte |
| POST | `auth/login` | Se connecter |
| POST | `auth/logout` | Se déconnecter |
| GET  | `auth/me` | Utilisateur connecté |

### Profil
| Méthode | Route | Description |
|---------|-------|-------------|
| GET | `users/profile` | Voir son profil |
| PUT | `users/profile` | Modifier son profil |

### Annonces
| Méthode | Route | Description |
|---------|-------|-------------|
| GET | `annonces` | Liste (filtres: q, cat, type, etat, sort, page) |
| GET | `annonces/detail&id=X` | Détail d'une annonce |
| POST | `annonces` | Créer une annonce (vendeur) |
| PUT | `annonces` | Modifier une annonce |
| DELETE | `annonces` | Supprimer une annonce |
| GET | `annonces/mes-annonces` | Mes annonces (vendeur) |

### Panier
| Méthode | Route | Description |
|---------|-------|-------------|
| GET | `panier` | Voir le panier |
| POST | `panier` | Ajouter un article |
| PUT | `panier` | Modifier la quantité |
| DELETE | `panier` | Retirer un article |
| DELETE | `panier/clear` | Vider le panier |

### Transactions
| Méthode | Route | Description |
|---------|-------|-------------|
| POST | `transactions/checkout` | Valider la commande |
| GET | `transactions` | Historique |

### Enchères
| Méthode | Route | Description |
|---------|-------|-------------|
| GET | `encheres` | Liste des enchères |
| GET | `encheres/detail&id=X` | Détail |
| POST | `encheres/bid` | Placer une enchère |

### Négociations
| Méthode | Route | Description |
|---------|-------|-------------|
| GET | `negociations` | Mes négociations |
| GET | `negociations/detail&id=X` | Détail |
| POST | `negociations` | Ouvrir une négociation |
| POST | `negociations/offre` | Envoyer une offre |
| POST | `negociations/repondre` | Accepter/refuser/contre-offrir |
| POST | `negociations/abandonner` | Abandonner |

### Notifications
| Méthode | Route | Description |
|---------|-------|-------------|
| GET | `notifications` | Mes notifications |
| PUT | `notifications/lire` | Marquer comme lue |
| PUT | `notifications/lire-tout` | Tout marquer lu |

### Admin
| Méthode | Route | Description |
|---------|-------|-------------|
| GET | `admin/stats` | Statistiques |
| GET | `admin/users` | Liste utilisateurs |
| PUT | `admin/users/block` | Bloquer/débloquer |
| DELETE | `admin/users` | Supprimer |
| GET | `admin/annonces` | Liste annonces |
| PUT | `admin/annonces/flag` | Signaler/valider |
| DELETE | `admin/annonces` | Supprimer |

---

## Problèmes fréquents

**WAMP n'est pas vert** → Vérifiez qu'aucun autre service n'occupe le port 80 (Skype, IIS...).

**Erreur 500 sur l'API** → Vérifiez les logs WAMP dans `C:\wamp64\logs\apache_error.log`.

**"Non authentifié" sur toutes les routes** → Vérifiez que les cookies de session sont envoyés (`credentials: 'include'` dans api-client.js).

**L'API retourne du HTML au lieu de JSON** → Le fichier `api/index.php` n'est pas trouvé. Vérifiez le chemin `API_BASE` dans `api-client.js`.

**mod_rewrite manquant** → Dans WAMP, clic gauche sur l'icône > Apache > Modules > activer `rewrite_module`.
