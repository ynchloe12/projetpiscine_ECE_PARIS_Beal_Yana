/**
 * api-client.js — Mercato Nova
 * Remplace les appels localStorage par des requêtes vers le backend PHP.
 * À inclure dans chaque page HTML AVANT les scripts inline :
 *   <script src="api-client.js"></script>
 *
 * URL de base : on suppose que le projet est dans C:/wamp64/www/mercato_nova/
 * Donc l'API est accessible sur http://localhost/mercato_nova/api/index.php?route=<route>
 */

const API_BASE = '/mercato_nova/api/index.php';

/* ============================================================
   Utilitaire fetch centralisé
   ============================================================ */
async function apiCall(route, method = 'GET', body = null) {
    const url = `${API_BASE}?route=${encodeURIComponent(route)}`;
    const options = {
        method,
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',   // Envoie le cookie de session
    };
    if (body && method !== 'GET') {
        options.body = JSON.stringify(body);
    }
    const res = await fetch(url, options);
    const json = await res.json();
    if (!json.success) {
        throw new Error(json.error || 'Erreur inconnue');
    }
    return json.data;
}

/* ============================================================
   AUTH
   ============================================================ */
const Auth = {
    async register(pseudo, email, password, role = 'client') {
        return apiCall('auth/register', 'POST', { pseudo, email, password, role });
    },
    async login(email, password) {
        const user = await apiCall('auth/login', 'POST', { email, password });
        // Compatibilité localStorage (pour les pages qui lisent encore mn_currentUser)
        localStorage.setItem('mn_currentUser', JSON.stringify(user));
        return user;
    },
    async logout() {
        await apiCall('auth/logout', 'POST');
        localStorage.removeItem('mn_currentUser');
        window.location.href = 'creation_profil.html';
    },
    async me() {
        try {
            const user = await apiCall('auth/me');
            if (user) localStorage.setItem('mn_currentUser', JSON.stringify(user));
            return user;
        } catch {
            localStorage.removeItem('mn_currentUser');
            return null;
        }
    },
};

/* ============================================================
   UTILISATEURS / PROFIL
   ============================================================ */
const Users = {
    async getProfile() {
        return apiCall('users/profile');
    },
    async updateProfile(data) {
        return apiCall('users/profile', 'PUT', data);
    },
};

/* ============================================================
   ANNONCES
   ============================================================ */
const Annonces = {
    async list(params = {}) {
        const qs = new URLSearchParams(params).toString();
        return apiCall('annonces' + (qs ? '&' + qs : ''));
    },
    async get(id) {
        return apiCall(`annonces/detail&id=${id}`);
    },
    async create(data) {
        return apiCall('annonces', 'POST', data);
    },
    async update(data) {
        return apiCall('annonces', 'PUT', data);
    },
    async delete(id) {
        return apiCall('annonces', 'DELETE', { id });
    },
    async mesAnnonces() {
        return apiCall('annonces/mes-annonces');
    },
};

/* ============================================================
   PANIER
   ============================================================ */
const Panier = {
    async get() {
        return apiCall('panier');
    },
    async add(annonce_id, quantite = 1, prix_negocie = null) {
        return apiCall('panier', 'POST', { annonce_id, quantite, prix_negocie });
    },
    async update(annonce_id, quantite) {
        return apiCall('panier', 'PUT', { annonce_id, quantite });
    },
    async remove(annonce_id) {
        return apiCall('panier', 'DELETE', { annonce_id });
    },
    async clear() {
        return apiCall('panier/clear', 'DELETE');
    },
};

/* ============================================================
   TRANSACTIONS
   ============================================================ */
const Transactions = {
    async checkout(livraison) {
        return apiCall('transactions/checkout', 'POST', livraison);
    },
    async list() {
        return apiCall('transactions');
    },
};

/* ============================================================
   ENCHÈRES
   ============================================================ */
const Encheres = {
    async list(statut = 'en_cours') {
        return apiCall(`encheres&statut=${statut}`);
    },
    async get(id) {
        return apiCall(`encheres/detail&id=${id}`);
    },
    async placeBid(annonce_id, montant) {
        return apiCall('encheres/bid', 'POST', { annonce_id, montant });
    },
};

/* ============================================================
   NÉGOCIATIONS
   ============================================================ */
const Negociations = {
    async list() {
        return apiCall('negociations');
    },
    async get(id) {
        return apiCall(`negociations/detail&id=${id}`);
    },
    async create(annonce_id) {
        return apiCall('negociations', 'POST', { annonce_id });
    },
    async sendOffre(negociation_id, montant) {
        return apiCall('negociations/offre', 'POST', { negociation_id, montant });
    },
    async repondre(negociation_id, reponse, montant = null) {
        return apiCall('negociations/repondre', 'POST', { negociation_id, reponse, montant });
    },
    async abandonner(negociation_id) {
        return apiCall('negociations/abandonner', 'POST', { negociation_id });
    },
};

/* ============================================================
   NOTIFICATIONS
   ============================================================ */
const Notifications = {
    async list() {
        return apiCall('notifications');
    },
    async markRead(id) {
        return apiCall('notifications/lire', 'PUT', { id });
    },
    async markAllRead() {
        return apiCall('notifications/lire-tout', 'PUT');
    },
};

/* ============================================================
   ADMIN
   ============================================================ */
const Admin = {
    async stats() {
        return apiCall('admin/stats');
    },
    async listUsers(q = '') {
        return apiCall(`admin/users&q=${encodeURIComponent(q)}`);
    },
    async blockUser(id, blocked = null) {
        return apiCall('admin/users/block', 'PUT', { id, blocked });
    },
    async deleteUser(id) {
        return apiCall('admin/users', 'DELETE', { id });
    },
    async listAnnonces(q = '') {
        return apiCall(`admin/annonces&q=${encodeURIComponent(q)}`);
    },
    async flagAnnonce(id, flagged = null) {
        return apiCall('admin/annonces/flag', 'PUT', { id, flagged });
    },
    async deleteAnnonce(id) {
        return apiCall('admin/annonces', 'DELETE', { id });
    },
};

/* ============================================================
   Initialisation automatique : synchronise la session PHP
   avec le localStorage (rétrocompatibilité)
   ============================================================ */
document.addEventListener('DOMContentLoaded', async () => {
    const user = await Auth.me();
    // Dispatch un événement custom pour que les pages puissent réagir
    document.dispatchEvent(new CustomEvent('mn:auth', { detail: user }));
});
