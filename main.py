from fastapi import FastAPI, Query, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import HTMLResponse
import httpx
from bs4 import BeautifulSoup
import time
import re
from datetime import datetime
from typing import Optional

# ── App ────────────────────────────────────────────────────────────────────

app = FastAPI(
    title="Kayak Polo U18 2026 — API Complète",
    description="""
API non-officielle pour le **Championnat de France U18 2026** de kayak polo.

Données extraites automatiquement depuis [kayak-polo.info](https://www.kayak-polo.info).

## Fonctionnalités
-  Tous les matchs (joués + à venir)
-  Classement temporaire en temps réel
- Prochain match global ou par équipe
-  Goal average & stats détaillées par équipe
-  Programme par journée
-  Confrontations directes entre deux équipes
- Invalidation manuelle du cache
""",
    version="2.0.0",
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["*"],
    allow_headers=["*"],
)

BASE_URL = "https://www.kayak-polo.info"
CACHE_DURATION = 300  # 5 minutes
_cache: dict = {}

U18_URL = f"{BASE_URL}/kpmatchs.php?Compet=*&Group=N18&Saison=2026"

# Journées connues (à mettre à jour si besoin)
JOURNEES = {
    "J1": {"dates": ["28/03/2026", "29/03/2026"], "lieu": "Acigné"},
    "J2": {"dates": ["25/04/2026"], "lieu": "Saint-Omer"},
    "J3": {"dates": ["23/05/2026"], "lieu": "Avranches"},
    "Finales": {"dates": ["03/07/2026", "04/07/2026", "05/07/2026"], "lieu": "TBD"},
}


# ── Cache ──────────────────────────────────────────────────────────────────

def get_cached(key: str):
    if key in _cache:
        data, ts = _cache[key]
        if time.time() - ts < CACHE_DURATION:
            return data
    return None


def set_cache(key: str, data):
    _cache[key] = (data, time.time())


def parse_date(date_str: str) -> Optional[datetime]:
    """Parse 'DD/MM/YYYY' ou 'DD/MM' vers datetime."""
    for fmt in ("%d/%m/%Y", "%d/%m"):
        try:
            return datetime.strptime(date_str.strip(), fmt)
        except ValueError:
            continue
    return None


def detect_journee(date_str: str) -> str:
    """Retourne la journée correspondant à une date."""
    for nom, info in JOURNEES.items():
        if date_str in info["dates"]:
            return nom
    return "?"


# ── Scraping ───────────────────────────────────────────────────────────────

async def fetch_matches_raw() -> list[dict]:
    cached = get_cached("u18_matches")
    if cached is not None:
        return cached

    headers = {"User-Agent": "Mozilla/5.0 (compatible; KayakPoloU18API/2.0)"}
    async with httpx.AsyncClient(timeout=15) as client:
        resp = await client.get(U18_URL, headers=headers)
        resp.raise_for_status()

    soup = BeautifulSoup(resp.text, "html.parser")
    table = soup.find("table")
    if not table:
        return []

    matches = []
    rows = table.find_all("tr")
    for row in rows:
        cols = row.find_all("td")
        if len(cols) < 8:
            continue
        num = cols[0].text.strip()
        if not num or num == "#":
            continue
        try:
            lieu_col = cols[2].text.strip() if len(cols) > 2 else ""

            equipe_a_tag = cols[5].find("a")
            equipe_a = equipe_a_tag.text.strip() if equipe_a_tag else cols[5].text.strip()

            score_raw = cols[6].text.strip()
            score_match = re.search(r"(\d+)\s*-\s*(\d+)", score_raw)
            if score_match:
                buts_a = int(score_match.group(1))
                buts_b = int(score_match.group(2))
                joue = True
            else:
                buts_a = None
                buts_b = None
                joue = False

            equipe_b_tag = cols[7].find("a") if len(cols) > 7 else None
            equipe_b = equipe_b_tag.text.strip() if equipe_b_tag else (cols[7].text.strip() if len(cols) > 7 else "")

            arbitre1 = cols[8].text.strip() if len(cols) > 8 else ""
            arbitre2 = cols[9].text.strip() if len(cols) > 9 else ""

            detail_col = cols[-1].text.strip() if cols else ""
            date_detail = re.search(r"(\d{2}/\d{2})\s+(\d{2}:\d{2})", detail_col)
            date_str = date_detail.group(1) + "/2026" if date_detail else ""
            heure_str = date_detail.group(2) if date_detail else ""

            terrain_match = re.search(r"Terr (\d+)", detail_col)
            terrain = terrain_match.group(1) if terrain_match else ""

            journee = detect_journee(date_str)

            # Résultat textuel
            if joue:
                if buts_a > buts_b:
                    resultat_a, resultat_b = "V", "D"
                elif buts_a < buts_b:
                    resultat_a, resultat_b = "D", "V"
                else:
                    resultat_a, resultat_b = "N", "N"
            else:
                resultat_a = resultat_b = None

            matches.append({
                "num": num,
                "journee": journee,
                "date": date_str,
                "heure": heure_str,
                "lieu": lieu_col,
                "terrain": terrain,
                "equipe_a": equipe_a,
                "equipe_b": equipe_b,
                "buts_a": buts_a,
                "buts_b": buts_b,
                "score": f"{buts_a} - {buts_b}" if joue else None,
                "resultat_a": resultat_a,
                "resultat_b": resultat_b,
                "joue": joue,
                "arbitre_principal": arbitre1,
                "arbitre_secondaire": arbitre2,
            })
        except Exception:
            continue

    seen = set()
    unique = []
    for m in matches:
        if m["num"] not in seen:
            seen.add(m["num"])
            unique.append(m)

    set_cache("u18_matches", unique)
    return unique


def get_all_teams(matches: list[dict]) -> list[str]:
    teams = set()
    for m in matches:
        teams.add(m["equipe_a"])
        teams.add(m["equipe_b"])
    return sorted(teams)


def build_standings(matches: list[dict]) -> list[dict]:
    teams: dict[str, dict] = {}

    def init_team(name):
        if name not in teams:
            teams[name] = {
                "equipe": name,
                "matchs_joues": 0,
                "victoires": 0,
                "nuls": 0,
                "defaites": 0,
                "buts_pour": 0,
                "buts_contre": 0,
                "goal_average": 0,
                "points": 0,
                "serie": [],  # Derniers résultats V/N/D
            }

    for m in matches:
        if not m["joue"]:
            continue
        a, b = m["equipe_a"], m["equipe_b"]
        ga, gb = m["buts_a"], m["buts_b"]
        init_team(a)
        init_team(b)

        teams[a]["matchs_joues"] += 1
        teams[b]["matchs_joues"] += 1
        teams[a]["buts_pour"] += ga
        teams[a]["buts_contre"] += gb
        teams[b]["buts_pour"] += gb
        teams[b]["buts_contre"] += ga

        if ga > gb:
            teams[a]["victoires"] += 1
            teams[a]["points"] += 3
            teams[a]["serie"].append("V")
            teams[b]["defaites"] += 1
            teams[b]["serie"].append("D")
        elif ga < gb:
            teams[b]["victoires"] += 1
            teams[b]["points"] += 3
            teams[b]["serie"].append("V")
            teams[a]["defaites"] += 1
            teams[a]["serie"].append("D")
        else:
            teams[a]["nuls"] += 1
            teams[a]["points"] += 1
            teams[a]["serie"].append("N")
            teams[b]["nuls"] += 1
            teams[b]["points"] += 1
            teams[b]["serie"].append("N")

    for t in teams.values():
        t["goal_average"] = t["buts_pour"] - t["buts_contre"]
        t["serie"] = t["serie"][-5:]  # 5 derniers résultats max

    ranking = sorted(
        teams.values(),
        key=lambda x: (-x["points"], -x["goal_average"], -x["buts_pour"])
    )
    for i, t in enumerate(ranking):
        t["rang"] = i + 1

    return ranking


def find_next_match(matches: list[dict], equipe: str = None) -> Optional[dict]:
    """Retourne le prochain match à venir (global ou pour une équipe)."""
    now = datetime.now()
    a_venir = [m for m in matches if not m["joue"]]

    if equipe:
        terme = equipe.lower()
        a_venir = [
            m for m in a_venir
            if terme in m["equipe_a"].lower() or terme in m["equipe_b"].lower()
        ]

    if not a_venir:
        return None

    def sort_key(m):
        dt = parse_date(m["date"])
        if dt is None:
            return datetime(9999, 1, 1)
        if m["heure"]:
            try:
                h, mn = m["heure"].split(":")
                dt = dt.replace(hour=int(h), minute=int(mn))
            except Exception:
                pass
        return dt

    return sorted(a_venir, key=sort_key)[0]


# ── Page d'accueil ─────────────────────────────────────────────────────────

@app.get("/", response_class=HTMLResponse, include_in_schema=False)
async def home():
    return """<!DOCTYPE html>
<html><head><title>Kayak Polo U18 2026 — API</title>
<meta charset="utf-8">
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Segoe UI', sans-serif; background: #0d1f1a; color: #e8f5e9; min-height: 100vh; padding: 40px 20px; }
  .container { max-width: 900px; margin: 0 auto; }
  h1 { font-size: 2rem; color: #4caf79; margin-bottom: 8px; }
  .subtitle { color: #81c784; margin-bottom: 32px; font-size: 0.95rem; }
  h2 { color: #a5d6a7; font-size: 1.1rem; margin: 24px 0 12px; text-transform: uppercase; letter-spacing: 1px; }
  .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(380px, 1fr)); gap: 12px; }
  .ep { background: #1b3329; border: 1px solid #2e5242; border-radius: 8px; padding: 14px 16px; }
  .ep:hover { border-color: #4caf79; }
  .method { display: inline-block; background: #1a4731; color: #4caf79; font-size: 0.75rem; font-weight: 700; padding: 2px 8px; border-radius: 4px; margin-right: 8px; font-family: monospace; }
  .path { color: #c8e6c9; font-family: monospace; font-size: 0.9rem; }
  .desc { color: #81c784; font-size: 0.82rem; margin-top: 6px; }
  .badge { display: inline-block; background: #2e5242; color: #a5d6a7; font-size: 0.7rem; padding: 2px 7px; border-radius: 10px; margin-right: 4px; margin-top: 4px; }
  a.docs { display: inline-block; margin-top: 28px; background: #4caf79; color: #0d1f1a; padding: 10px 22px; border-radius: 6px; text-decoration: none; font-weight: 700; }
  a.docs:hover { background: #66bb6a; }
</style>
</head><body>
<div class="container">
  <h1>Kayak Polo U18 2026</h1>
  <p class="subtitle">API non-officielle — données extraites de kayak-polo.info · Cache 5 min</p>

  <h2>Matchs & Programme</h2>
  <div class="grid">
    <div class="ep"><span class="method">GET</span><span class="path">/matches</span>
      <div class="desc">Tous les matchs (joués + à venir)</div>
      <span class="badge">?joues_seulement=true</span></div>
    <div class="ep"><span class="method">GET</span><span class="path">/prochain-match</span>
      <div class="desc">Prochain match toutes équipes confondues</div></div>
    <div class="ep"><span class="method">GET</span><span class="path">/prochain-match/{equipe}</span>
      <div class="desc">Prochain match d'une équipe spécifique</div>
      <span class="badge">ex: Grand Est</span><span class="badge">ex: Avranches</span></div>
    <div class="ep"><span class="method">GET</span><span class="path">/journee/{nom}</span>
      <div class="desc">Tous les matchs d'une journée</div>
      <span class="badge">J1</span><span class="badge">J2</span><span class="badge">J3</span><span class="badge">Finales</span></div>
  </div>

  <h2>Classements & Stats</h2>
  <div class="grid">
    <div class="ep"><span class="method">GET</span><span class="path">/classement</span>
      <div class="desc">Classement temporaire en temps réel (points → GA → BP)</div></div>
    <div class="ep"><span class="method">GET</span><span class="path">/goal-average</span>
      <div class="desc">Toutes les équipes triées par goal average</div></div>
    <div class="ep"><span class="method">GET</span><span class="path">/equipes</span>
      <div class="desc">Liste de toutes les équipes engagées</div></div>
    <div class="ep"><span class="method">GET</span><span class="path">/stats</span>
      <div class="desc">Statistiques globales du championnat</div></div>
  </div>

  <h2>Par équipe</h2>
  <div class="grid">
    <div class="ep"><span class="method">GET</span><span class="path">/equipe/{nom}</span>
      <div class="desc">Matchs + stats complètes d'une équipe</div>
      <span class="badge">?joues_seulement=true</span></div>
    <div class="ep"><span class="method">GET</span><span class="path">/confrontation/{eq1}/{eq2}</span>
      <div class="desc">Historique des matchs entre deux équipes</div></div>
    <div class="ep"><span class="method">GET</span><span class="path">/derniers-resultats</span>
      <div class="desc">Les N derniers matchs joués</div>
      <span class="badge">?n=5</span></div>
  </div>

  <h2>Utilitaires</h2>
  <div class="grid">
    <div class="ep"><span class="method">POST</span><span class="path">/cache/clear</span>
      <div class="desc">Vider le cache pour forcer une mise à jour</div></div>
    <div class="ep"><span class="method">GET</span><span class="path">/cache/status</span>
      <div class="desc">État du cache (âge, nb entrées)</div></div>
  </div>

  <a class="docs" href="/docs">Documentation interactive Swagger</a>
</div>
</body></html>"""


# ── Endpoints ──────────────────────────────────────────────────────────────

@app.get("/matches", summary="Tous les matchs U18 2026", tags=["Matchs"])
async def get_matches(
    joues_seulement: bool = Query(False, description="Uniquement les matchs avec un score"),
    journee: Optional[str] = Query(None, description="Filtrer par journée (J1, J2, J3, Finales)"),
):
    """Retourne tous les matchs du Championnat de France U18 2026."""
    matches = await fetch_matches_raw()
    if joues_seulement:
        matches = [m for m in matches if m["joue"]]
    if journee:
        matches = [m for m in matches if m["journee"].upper() == journee.upper()]
    return {
        "total": len(matches),
        "joues": sum(1 for m in matches if m["joue"]),
        "a_venir": sum(1 for m in matches if not m["joue"]),
        "matches": matches,
    }


@app.get("/prochain-match", summary="Prochain match toutes équipes", tags=["Matchs"])
async def get_prochain_match():
    """Retourne le prochain match à jouer dans le championnat."""
    matches = await fetch_matches_raw()
    prochain = find_next_match(matches)
    if not prochain:
        raise HTTPException(status_code=404, detail="Aucun match à venir trouvé.")
    return {"prochain_match": prochain}


@app.get("/prochain-match/{nom_equipe}", summary="Prochain match d'une équipe", tags=["Matchs"])
async def get_prochain_match_equipe(nom_equipe: str):
    """
    Retourne le prochain match à venir pour une équipe donnée.

    Recherche partielle insensible à la casse.
    Exemples : `Grand Est`, `Avranches`, `Condé`, `Pont d'Ouilly`
    """
    matches = await fetch_matches_raw()
    terme = nom_equipe.lower().strip()

    # Vérifier que l'équipe existe
    toutes = get_all_teams(matches)
    equipe_trouvee = next((t for t in toutes if terme in t.lower()), None)
    if not equipe_trouvee:
        raise HTTPException(
            status_code=404,
            detail=f"Équipe '{nom_equipe}' introuvable. Équipes disponibles : {toutes}"
        )

    prochain = find_next_match(matches, equipe=nom_equipe)
    if not prochain:
        raise HTTPException(
            status_code=404,
            detail=f"Aucun match à venir pour '{equipe_trouvee}'."
        )

    # Quel rôle joue l'équipe (A ou B) ?
    role = "equipe_a" if terme in prochain["equipe_a"].lower() else "equipe_b"
    adversaire = prochain["equipe_b"] if role == "equipe_a" else prochain["equipe_a"]

    return {
        "equipe": equipe_trouvee,
        "adversaire": adversaire,
        "prochain_match": prochain,
    }


@app.get("/journee/{nom_journee}", summary="Matchs d'une journée", tags=["Matchs"])
async def get_journee(nom_journee: str):
    """
    Retourne tous les matchs d'une journée.
    Valeurs possibles : `J1`, `J2`, `J3`, `Finales`
    """
    matches = await fetch_matches_raw()
    filtres = [m for m in matches if m["journee"].upper() == nom_journee.upper()]
    if not filtres:
        raise HTTPException(
            status_code=404,
            detail=f"Journée '{nom_journee}' introuvable ou sans matchs. Valeurs : J1, J2, J3, Finales"
        )
    info = JOURNEES.get(nom_journee.upper(), {})
    return {
        "journee": nom_journee.upper(),
        "lieu": info.get("lieu", "?"),
        "dates": info.get("dates", []),
        "total_matchs": len(filtres),
        "joues": sum(1 for m in filtres if m["joue"]),
        "a_venir": sum(1 for m in filtres if not m["joue"]),
        "matches": filtres,
    }


@app.get("/classement", summary="Classement temporaire", tags=["Classements"])
async def get_classement():
    """Classement en temps réel calculé sur les matchs joués. Tri : points → goal average → buts pour."""
    matches = await fetch_matches_raw()
    joues = [m for m in matches if m["joue"]]
    standings = build_standings(joues)
    if not standings:
        return {"message": "Aucun match joué — classement indisponible", "classement": []}
    return {
        "matchs_joues_total": len(joues),
        "equipes": len(standings),
        "derniere_mise_a_jour": datetime.now().strftime("%d/%m/%Y %H:%M"),
        "classement": standings,
    }


@app.get("/goal-average", summary="Classement au goal average", tags=["Classements"])
async def get_goal_average():
    """Toutes les équipes triées par goal average (buts pour − buts contre)."""
    matches = await fetch_matches_raw()
    joues = [m for m in matches if m["joue"]]
    standings = build_standings(joues)
    if not standings:
        return {"message": "Aucun match joué", "classement_goal_average": []}
    ga_ranking = sorted(standings, key=lambda x: (-x["goal_average"], -x["buts_pour"]))
    for i, t in enumerate(ga_ranking):
        t["rang_ga"] = i + 1
    return {
        "matchs_joues_total": len(joues),
        "classement_goal_average": [
            {
                "rang_ga": t["rang_ga"],
                "equipe": t["equipe"],
                "goal_average": t["goal_average"],
                "buts_pour": t["buts_pour"],
                "buts_contre": t["buts_contre"],
                "points": t["points"],
            }
            for t in ga_ranking
        ],
    }


@app.get("/equipes", summary="Liste des équipes", tags=["Équipes"])
async def get_equipes():
    """Retourne la liste de toutes les équipes engagées dans le championnat."""
    matches = await fetch_matches_raw()
    equipes = get_all_teams(matches)
    return {"total": len(equipes), "equipes": equipes}


@app.get("/equipe/{nom_equipe}", summary="Stats & matchs d'une équipe", tags=["Équipes"])
async def get_equipe(
    nom_equipe: str,
    joues_seulement: bool = Query(False, description="Uniquement les matchs joués"),
):
    """
    Retourne les matchs et statistiques complètes d'une équipe.
    Recherche partielle insensible à la casse.
    """
    matches = await fetch_matches_raw()
    terme = nom_equipe.lower().strip()

    filtres = [
        m for m in matches
        if terme in m["equipe_a"].lower() or terme in m["equipe_b"].lower()
    ]
    if not filtres:
        equipes = get_all_teams(matches)
        raise HTTPException(
            status_code=404,
            detail=f"'{nom_equipe}' introuvable. Équipes : {equipes}"
        )

    if joues_seulement:
        filtres_affichage = [m for m in filtres if m["joue"]]
    else:
        filtres_affichage = filtres

    joues = [m for m in filtres if m["joue"]]
    a_venir = [m for m in filtres if not m["joue"]]

    stats = {
        "victoires": 0, "nuls": 0, "defaites": 0,
        "buts_pour": 0, "buts_contre": 0, "points": 0,
    }
    equipe_nom = None
    serie = []

    for m in joues:
        if terme in m["equipe_a"].lower():
            equipe_nom = m["equipe_a"]
            gp, gc = m["buts_a"], m["buts_b"]
        else:
            equipe_nom = m["equipe_b"]
            gp, gc = m["buts_b"], m["buts_a"]

        stats["buts_pour"] += gp
        stats["buts_contre"] += gc

        if gp > gc:
            stats["victoires"] += 1
            stats["points"] += 3
            serie.append("V")
        elif gp == gc:
            stats["nuls"] += 1
            stats["points"] += 1
            serie.append("N")
        else:
            stats["defaites"] += 1
            serie.append("D")

    stats["goal_average"] = stats["buts_pour"] - stats["buts_contre"]
    stats["matchs_joues"] = len(joues)
    stats["matchs_a_venir"] = len(a_venir)
    stats["serie_recente"] = serie[-5:]

    prochain = find_next_match(matches, equipe=nom_equipe)

    return {
        "equipe": equipe_nom or nom_equipe,
        "stats": stats,
        "prochain_match": prochain,
        "total_matchs": len(filtres),
        "matches": filtres_affichage,
    }


@app.get("/confrontation/{equipe1}/{equipe2}", summary="Confrontations directes", tags=["Équipes"])
async def get_confrontation(equipe1: str, equipe2: str):
    """
    Retourne les matchs (passés et à venir) entre deux équipes.

    Exemples :
    - `/confrontation/Grand Est/Avranches`
    - `/confrontation/Condé/Thury`
    """
    matches = await fetch_matches_raw()
    t1 = equipe1.lower().strip()
    t2 = equipe2.lower().strip()

    filtres = [
        m for m in matches
        if (t1 in m["equipe_a"].lower() and t2 in m["equipe_b"].lower())
        or (t2 in m["equipe_a"].lower() and t1 in m["equipe_b"].lower())
    ]

    if not filtres:
        raise HTTPException(
            status_code=404,
            detail=f"Aucun match trouvé entre '{equipe1}' et '{equipe2}'."
        )

    # Bilan entre les deux équipes
    bilan = {equipe1: {"V": 0, "N": 0, "D": 0, "BP": 0, "BC": 0},
             equipe2: {"V": 0, "N": 0, "D": 0, "BP": 0, "BC": 0}}
    eq1_nom = eq2_nom = None

    for m in filtres:
        if not m["joue"]:
            continue
        if t1 in m["equipe_a"].lower():
            eq1_nom, eq2_nom = m["equipe_a"], m["equipe_b"]
            gp, gc = m["buts_a"], m["buts_b"]
        else:
            eq1_nom, eq2_nom = m["equipe_b"], m["equipe_a"]
            gp, gc = m["buts_b"], m["buts_a"]

        bilan[equipe1]["BP"] += gp
        bilan[equipe1]["BC"] += gc
        bilan[equipe2]["BP"] += gc
        bilan[equipe2]["BC"] += gp

        if gp > gc:
            bilan[equipe1]["V"] += 1
            bilan[equipe2]["D"] += 1
        elif gp < gc:
            bilan[equipe1]["D"] += 1
            bilan[equipe2]["V"] += 1
        else:
            bilan[equipe1]["N"] += 1
            bilan[equipe2]["N"] += 1

    return {
        "equipe1": eq1_nom or equipe1,
        "equipe2": eq2_nom or equipe2,
        "bilan": bilan,
        "total_matchs": len(filtres),
        "joues": sum(1 for m in filtres if m["joue"]),
        "matches": filtres,
    }


@app.get("/derniers-resultats", summary="Derniers matchs joués", tags=["Matchs"])
async def get_derniers_resultats(n: int = Query(5, ge=1, le=50, description="Nombre de matchs à retourner")):
    """Retourne les N derniers matchs joués."""
    matches = await fetch_matches_raw()
    joues = [m for m in matches if m["joue"]]

    def sort_key(m):
        dt = parse_date(m["date"])
        if dt is None:
            return datetime(1970, 1, 1)
        if m["heure"]:
            try:
                h, mn = m["heure"].split(":")
                dt = dt.replace(hour=int(h), minute=int(mn))
            except Exception:
                pass
        return dt

    tries = sorted(joues, key=sort_key, reverse=True)
    return {
        "n": n,
        "total_joues": len(joues),
        "derniers_resultats": tries[:n],
    }


@app.get("/stats", summary="Statistiques globales", tags=["Classements"])
async def get_stats():
    """Statistiques globales du championnat : meilleure attaque, meilleure défense, etc."""
    matches = await fetch_matches_raw()
    joues = [m for m in matches if m["joue"]]
    standings = build_standings(joues)

    if not standings:
        return {"message": "Aucun match joué", "stats": {}}

    total_buts = sum(m["buts_a"] + m["buts_b"] for m in joues)
    meilleure_attaque = max(standings, key=lambda x: x["buts_pour"])
    meilleure_defense = min(standings, key=lambda x: x["buts_contre"])
    plus_grand_ecart = None
    if joues:
        plus_grand_ecart = max(joues, key=lambda m: abs(m["buts_a"] - m["buts_b"]))

    return {
        "matchs_joues": len(joues),
        "matchs_a_venir": len([m for m in matches if not m["joue"]]),
        "total_buts": total_buts,
        "moyenne_buts_par_match": round(total_buts / len(joues), 2) if joues else 0,
        "meilleure_attaque": {
            "equipe": meilleure_attaque["equipe"],
            "buts_pour": meilleure_attaque["buts_pour"],
        },
        "meilleure_defense": {
            "equipe": meilleure_defense["equipe"],
            "buts_contre": meilleure_defense["buts_contre"],
        },
        "plus_grand_ecart": {
            "match": f"{plus_grand_ecart['equipe_a']} {plus_grand_ecart['score']} {plus_grand_ecart['equipe_b']}" if plus_grand_ecart else None,
            "ecart": abs(plus_grand_ecart["buts_a"] - plus_grand_ecart["buts_b"]) if plus_grand_ecart else None,
        } if plus_grand_ecart else None,
    }


@app.post("/cache/clear", summary="Vider le cache", tags=["Utilitaires"])
async def clear_cache():
    """Force le rechargement des données depuis kayak-polo.info."""
    _cache.clear()
    return {"message": "Cache vidé. Les prochaines requêtes iront chercher les données fraîches."}


@app.get("/cache/status", summary="État du cache", tags=["Utilitaires"])
async def cache_status():
    """Retourne l'état actuel du cache."""
    if "u18_matches" not in _cache:
        return {"cache": "vide", "age_secondes": None, "nb_matches_en_cache": 0}
    data, ts = _cache["u18_matches"]
    age = int(time.time() - ts)
    expire_dans = max(0, CACHE_DURATION - age)
    return {
        "cache": "actif",
        "age_secondes": age,
        "expire_dans_secondes": expire_dans,
        "nb_matches_en_cache": len(data),
        "prochaine_mise_a_jour": f"dans {expire_dans}s" if expire_dans > 0 else "immédiate",
    }
