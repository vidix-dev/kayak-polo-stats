# Kayak Polo Stats
![License: GPL v3](https://img.shields.io/badge/license-GPLv3-blue.svg)

Dashboard non-officiel pour le **kayak polo en France**
Données extraites automatiquement depuis [kayak-polo.info](https://www.kayak-polo.info).

Site en ligne : **https://kp-stats.duckdns.org**
Serveur : Apache

---

## Fonctionnalités

- Sélection de la compétition mémorisée par cookie
- Sélection de son équipe mémorisée par cookie
- Prochain match de ton équipe avec countdown
- Prochaines arbitrages (principal et secondaire)
- Classement provisoire en temps réel
- Simulation d'impact : quel rang si victoire / nul / défaite
- Stats complètes de ton équipe
- Calendrier complet avec matchs et arbitrages mis en évidence
- Mise à jour automatique toutes les 5 minutes depuis KPI
- Compteur de visites avec log par IP, compétition, équipe
- Loader animé au chargement
---

## Stack technique

| Couche | Techno |
|--------|--------|
| Backend | PHP 8.x (fichier unique `index.php`) |
| Scraping | `cURL` + `DOMDocument` |
| Cache | JSON fichier, TTL 5 min |
| Serveur | Apache 2.4 + mod_rewrite + mod_ssl |
| HTTPS | Let's Encrypt via certbot-dns-duckdns |
| DNS | DuckDNS (kayak-polo-stats.duckdns.org) |

---

## Structure des fichiers (serveur)

```
/var/www/POLO/
├── index.php          — dashboard complet
├── kps.png            — logo favicon
├── cache/
│   ├── matches_XXX.json
│   └── matches_XXX.json
└── logs/
    └── visits.log     — log des visites
```

---

## Stats de visite

Accessible à l'URL  :

```
https://kp-stats.duckdns.org/?stats=kps_vidix_2026
```

Affiche : total visites, visiteurs uniques, breakdown par jour, par compétition, et les 20 dernières visites.

---

Made by **Vidix**
