# 🛶 Kayak Polo U18 2026 — API Complète v2

API non-officielle pour le **Championnat de France U18 2026**.  
Données extraites automatiquement depuis [kayak-polo.info](https://www.kayak-polo.info).

---

## ⚡ Démarrage rapide

```bash
pip install -r requirements.txt
uvicorn main:app --reload
```

- API : **http://localhost:8000**
- Swagger : **http://localhost:8000/docs**
- Page d'accueil : **http://localhost:8000**

---

## 📡 Tous les endpoints

### 📋 Matchs & Programme

| Endpoint | Description |
|----------|-------------|
| `GET /matches` | Tous les matchs (joués + à venir) |
| `GET /matches?joues_seulement=true` | Uniquement les matchs joués |
| `GET /matches?journee=J1` | Matchs d'une journée spécifique |
| `GET /prochain-match` | Prochain match toutes équipes confondues |
| `GET /prochain-match/{equipe}` | Prochain match d'une équipe |
| `GET /journee/{nom}` | Programme complet d'une journée (J1/J2/J3/Finales) |
| `GET /derniers-resultats` | Les N derniers matchs joués (`?n=5`) |

### 🏆 Classements

| Endpoint | Description |
|----------|-------------|
| `GET /classement` | Classement complet (points → GA → BP) |
| `GET /goal-average` | Classement trié par goal average |
| `GET /stats` | Stats globales (meilleure attaque, défense, etc.) |

### 👕 Équipes

| Endpoint | Description |
|----------|-------------|
| `GET /equipes` | Liste de toutes les équipes |
| `GET /equipe/{nom}` | Stats + matchs d'une équipe |
| `GET /equipe/{nom}?joues_seulement=true` | Avec filtre sur les matchs joués |
| `GET /confrontation/{eq1}/{eq2}` | Matchs directs entre deux équipes |

### 🔧 Utilitaires

| Endpoint | Description |
|----------|-------------|
| `POST /cache/clear` | Vider le cache (forcer une mise à jour) |
| `GET /cache/status` | Âge du cache, matchs en mémoire |

---

## 💡 Exemples d'appels

```bash
# Prochain match de Grand Est
curl http://localhost:8000/prochain-match/Grand%20Est

# Classement actuel
curl http://localhost:8000/classement

# Programme de la J1
curl http://localhost:8000/journee/J1

# Confrontations Grand Est vs Avranches
curl "http://localhost:8000/confrontation/Grand%20Est/Avranches"

# Stats globales
curl http://localhost:8000/stats

# Vider le cache
curl -X POST http://localhost:8000/cache/clear
```

---

## 📅 Calendrier 2026

| Journée | Date | Lieu |
|---------|------|------|
| J1 | 28-29 mars 2026 | Acigné |
| J2 | 25 avril 2026 | Saint-Omer |
| J3 | 23 mai 2026 | Avranches |
| Finales | 3-5 juillet 2026 | TBD |

---

## 🏐 Équipes engagées

- CR Grand Est 18 I
- Avranches 18 I
- CD Loire-Atlantique 18 I
- Thury-Harcourt 18 I
- Condé-sur-Vire 18 I
- Pont d'Ouilly 18 I
- Saint-Grégoire 18 I
- Saint-Domineuc - Acigné 18 I
- Ploërmel-Vern-sur-S. U18

---

## ⚙️ Notes techniques

- **Cache** : 5 minutes. Utilisez `POST /cache/clear` après une journée de matchs.
- **Recherche équipe** : partielle et insensible à la casse (`grand est`, `Condé`, `pont`…).
- **Classement** : calculé uniquement sur les matchs avec score saisi.
