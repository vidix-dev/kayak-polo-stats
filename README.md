# Kayak Polo Stats

Dashboard non-officiel pour le **Championnat de France de Kayak Polo 2026**.
Données extraites automatiquement depuis [kayak-polo.info](https://www.kayak-polo.info).

Site en ligne : **https://kayak-polo-stats.duckdns.org**
Serveur : Apache sur Raspberry Pi (192.168.1.105), HTTPS via Let's Encrypt + DuckDNS.

---

## Fonctionnalités

- Sélection de la compétition (U15 ou U18) mémorisée par cookie
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
- Design Apple-style, mobile-first, full white

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
│   ├── matches_N18.json   — cache U18
│   └── matches_N15.json   — cache U15
└── logs/
    └── visits.log     — log des visites
```

---

## Calendrier 2026

| Journée | Date | Lieu |
|---------|------|------|
| J1 | 28-29 mars 2026 | Acigné |
| J2 | 25 avril 2026 | Saint-Omer |
| J3 | 23 mai 2026 | Avranches |
| Finales | 3-5 juillet 2026 | TBD |

---

## Stats de visite

Accessible à l'URL secrète :

```
https://kayak-polo-stats.duckdns.org/?stats=kps_vidix_2026
```

Affiche : total visites, visiteurs uniques, breakdown par jour, par compétition, et les 20 dernières visites.

---

## Vider le cache manuellement

```
https://kayak-polo-stats.duckdns.org/?clear_cache=1
```

---

## API Python (legacy)

Le fichier `main.py` est l'ancienne API FastAPI qui a servi de base.
Elle tourne sur `localhost:8000` si besoin.

```bash
pip install -r requirements.txt
uvicorn main:app --reload
```

---

Made by **Vidix**
