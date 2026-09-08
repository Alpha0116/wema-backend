# 🚀 Wemá Backend API (« Le cahier qui écoute »)

API Backend en **Laravel** pour l'assistant vocal WhatsApp et le tableau de bord de gestion des ventes, du stock et des créances des commerçantes du marché au Bénin.

---

## 📑 Sommaire
1. [Documentation Interactive Swagger / OpenAPI](#-documentation-interactive-swagger--openapi)
2. [Démarrage Rapide](#-démarrage-rapide)
3. [Exposition en ligne avec Cloudflare Tunnel](#-exposition-en-ligne-avec-cloudflare-tunnel)
4. [Configuration des Webhooks Meta WhatsApp](#-configuration-des-webhooks-meta-whatsapp)
5. [Variables d'environnement (.env)](#-variables-denvironnement-env)
6. [Répertoire des Endpoints de l'API REST](#-répertoire-des-endpoints-de-lapi-rest)
7. [Simulation Vocale Locale & Démo](#-simulation-vocale-locale--démo)

---

## 📖 Documentation Interactive Swagger / OpenAPI

Le backend génère automatiquement une documentation OpenAPI interactive pour l'équipe Frontend (Groupe 1) :

* **Interface UI Swagger / OpenAPI** : [http://localhost:8000/docs/api](http://localhost:8000/docs/api)
* **Spécification JSON brute** : [http://localhost:8000/docs/api.json](http://localhost:8000/docs/api.json)

*(Disponible également sur votre URL publique Cloudflare Tunnel : `https://xxx.trycloudflare.com/docs/api`)*

---

## ⚡ Démarrage Rapide

```bash
# 1. Aller dans le dossier backend
cd backend

# 2. Installer les dépendances (si nécessaire)
composer install

# 3. Préparer la base de données et charger les données de démo
php artisan migrate:fresh --seed

# 4. Lancer le serveur local
php artisan serve --port=8000
```

---

## 🌐 Exposition en ligne avec Cloudflare Tunnel

Pour connecter le webhook WhatsApp de Meta et permettre au Frontend distant de consommer l'API :

```bash
# Installation de Cloudflare Tunnel (si pas encore installé)
brew install cloudflared

# Lancement du tunnel HTTPS vers le backend Laravel
cloudflared tunnel --url http://127.0.0.1:8000
```

Cloudflare affichera une URL publique sécurisée de type :  
👉 `https://votre-sous-domaine.trycloudflare.com`

---

## 📲 Configuration des Webhooks Meta WhatsApp

Dans le portail **Meta for Developers** (WhatsApp Cloud API) :
1. **Callback URL** : `https://votre-sous-domaine.trycloudflare.com/api/webhook/whatsapp`
2. **Verify Token** : `wema_secret_verify_token_2026` (configurable dans `.env`)
3. **Webhooks Fields** : S'abonner au champ `messages`.

---

## 🔑 Variables d'environnement (`.env`)

```ini
# Meta WhatsApp Cloud API (fourni par Groupe 2)
WHATSAPP_TOKEN=EAAG...
WHATSAPP_PHONE_NUMBER_ID=1234567890
WHATSAPP_VERIFY_TOKEN=wema_secret_verify_token_2026
WHATSAPP_BUSINESS_ACCOUNT_ID=

# Intelligence Artificielle (Transcription & Extraction)
OPENAI_API_KEY=sk-...
GROQ_API_KEY=gsk_...
```

---

## 📡 Répertoire des Endpoints de l'API REST

### 1. Écran 1 — Accueil / Dashboard
| Méthode | Route | Description |
|---|---|---|
| `GET` | `/api/dashboard/overview` | KPIs du jour (CA, encaissé, créances totales, validation en attente), alertes de stock/validation, flux récent. |

### 2. Écran 2 — Transactions & Journal
| Méthode | Route | Description |
|---|---|---|
| `GET` | `/api/transactions` | Journal complet avec pagination & filtres (`type`, `status`, `period`, `customer_id`, `product_id`, `search`). |
| `GET` | `/api/transactions/{id}` | Panneau de détail complet avec articles, client et note vocale source. |
| `POST` | `/api/transactions` | Création manuelle d'une transaction. |
| `PUT` | `/api/transactions/{id}` | Modification / correction humaine des articles ou montants. |
| `POST` | `/api/transactions/{id}/confirm` | Validation manuelle d'une transaction en attente (`PENDING` -> `CONFIRMED`). |
| `POST` | `/api/transactions/{id}/reject` | Rejet d'une transaction (`REJECTED`). |

### 3. Écran 3 — Clients & Créances
| Méthode | Route | Description |
|---|---|---|
| `GET` | `/api/customers` | Liste des clientes triée par solde dû décroissant (avec recherche sur alias). |
| `GET` | `/api/customers/{id}` | Fiche cliente détaillée, solde actuel et historique complet des impayés. |
| `POST` | `/api/customers` | Ajouter une nouvelle cliente. |
| `PUT` | `/api/customers/{id}` | Mettre à jour une cliente. |
| `DELETE` | `/api/customers/{id}` | Supprimer une cliente. |

### 4. Écran 4 — Produits & Stocks
| Méthode | Route | Description |
|---|---|---|
| `GET` | `/api/products` | Catalogue avec stocks, prix et alertes ruptures / seuils bas configurables. |
| `GET` | `/api/products/{id}` | Fiche produit avec historique des mouvements (ventes / réapprovisionnements). |
| `POST` | `/api/products` | Ajouter un produit. |
| `PUT` | `/api/products/{id}` | Mettre à jour un produit / son stock. |
| `DELETE` | `/api/products/{id}` | Supprimer un produit. |

### 5. Écran 5 — Messages & Traçabilité vocale
| Méthode | Route | Description |
|---|---|---|
| `GET` | `/api/messages` | Journal brut des vocaux (statut, transcription, indice de confiance, lien transaction). |
| `GET` | `/api/messages/{id}` | Détail d'un message avec la transaction associée. |

---

## 🎙️ Simulation Vocale Locale & Démo

Permet au Frontend de tester l'enregistrement vocal et la dictée sans dépendre du téléphone réel :

* **Endpoint** : `POST /api/simulate-voice`
* **Corps de la requête (JSON)** :
```json
{
  "text": "J'ai vendu 3 bidons d'huile à Maman Chantal, elle a payé 2000, elle doit 4000.",
  "confidence": 0.96
}
```
* **Corps de la requête (Multipart Form avec fichier audio)** :
  * Champ `audio` : fichier `.mp3`, `.ogg`, `.wav` ou `.m4a`.
