# API du connecteur Espace client

Ce que la plateforme (Hop, `client.fredrobert.com`) peut demander à un site, et ce que le connecteur
promet de ne pas casser. À tenir à jour à chaque route ajoutée.

## Adresse

`/wp-json/fred-espace-client/v1/…`, ou `/?rest_route=/fred-espace-client/v1/…` sur un site sans
permaliens. Le statut et l'inventaire annoncent cette adresse (`api`).

L'ancienne adresse `hop/v1` a répondu jusqu'à la 0.21.0. Elle est retirée depuis la 0.22.0.

## Signature

Chaque requête porte quatre en-têtes :

| En-tête | Contenu |
|---|---|
| `X-Hop-Key-Id` | identifiant de la clé (16 caractères hexadécimaux) |
| `X-Hop-Timestamp` | heure Unix ; refusée à plus de 60 secondes de l'horloge du site |
| `X-Hop-Nonce` | 16 à 64 caractères `[A-Za-z0-9_-]`, à usage unique |
| `X-Hop-Signature` | Ed25519, en hexadécimal, de la chaîne canonique |

Chaîne canonique, lignes séparées par `\n` : méthode en majuscules, route (par exemple
`/fred-espace-client/v1/status`), heure, nonce, identifiant de clé, SHA-256 du corps brut.

Seul le corps JSON signé porte des paramètres. Une chaîne de requête ou un corps d'un autre type
est refusé.

La signature couvre la route : une requête signée pour une route n'ouvre aucune autre route.

À l'appairage, la clé en attente (jeton `hop1_…`, secret partagé) ne sert qu'à `/handshake`. Ce
handshake la remplace par la clé publique de la plateforme. Le jeton `hop2_…` porte directement la clé
publique (WP-CLI).

## Routes

L'action est le nom à donner à `FRED_ESPACE_CLIENT_DENY` pour couper la route sur un site. Une route
sans action ne fait que lire.

| Méthode | Route | Paramètres | Action |
|---|---|---|---|
| POST | `/handshake` | `public_key` (avec la clé en attente) | |
| GET | `/status` | | |
| GET | `/inventory` | | |
| POST | `/login-token` | `email`, `name` | `login` |
| POST | `/updates/refresh` | | |
| POST | `/updates/apply` | `type` (plugin, theme, core), `slug`, `expected_from`, `expected_to` | `updates` |
| POST | `/plugins/state` | `slug`, `action` (activate, deactivate, delete) | `plugins` |
| POST | `/backups/database` | | `database` |
| POST | `/backups/database/start` | | `database` |
| POST | `/backups/database/continue` | `backup_id` | `database` |
| POST | `/backups/database/restore` | `backup_id` | `restore` |
| POST | `/backups/database/restore/start` | `backup_id` | `restore` |
| POST | `/backups/database/restore/continue` | `restore_id` | `restore` |
| POST | `/backups/pull/database` | | `pull` |
| POST | `/backups/pull/database/start` | | `pull` |
| POST | `/backups/pull/database/continue` | `backup_id` | `pull` |
| POST | `/backups/pull/chunk` | `backup_id`, `offset`, `length` | `pull` |
| POST | `/backups/pull/done` | `backup_id` | `pull` |
| POST | `/backups/pull/files` | `after` | `pull` |
| POST | `/backups/pull/fetch` | `items` | `pull` |
| POST | `/rollback` | `backup_id` | `restore` |

`HOP_CONNECTOR_DENY` et `HOP_CONNECTOR_DISABLED`, les constantes de l'ancien nom, comptent toujours.

## Point de secours

Il répond avant le chargement des extensions, même quand une mise à jour a fait planter le site :
`/?hop_rescue=status` (GET) et `/?hop_rescue=rollback` (POST, `backup_id`, action `restore`).

La route signée est `/hop/v1/rescue/status` ou `/hop/v1/rescue/rollback`. Elle fait partie du
protocole et ne suit pas l'adresse REST.

## Capacités

Le statut et l'inventaire annoncent `capabilities`. La plateforme décide d'après elles, plus
d'après la version. Un connecteur qui n'en annonce aucune (avant la 0.22.0) est jugé sur sa version.

| Capacité | Ce que le connecteur sait faire | Depuis |
|---|---|---|
| `ed25519` | vérifier la clé publique de la plateforme | 0.8.0 |
| `plugins_state` | activer, désactiver, supprimer une extension | 0.4.0 |
| `database_snapshot` | copier la base avant une mise à jour | 0.5.0 |
| `database_steps` | copier la base par étapes de moins d'une minute | 0.10.0 |
| `restore_steps` | restaurer la base par étapes, sans site à moitié remis | 0.11.0 |
| `pull` | laisser la plateforme tirer base et fichiers la nuit | 0.9.0 |
| `rescue` | répondre au point de secours | 0.3.0 |
| `rollback` | remettre la version d'avant une mise à jour | 0.3.0 |
| `login_token` | ouvrir wp-admin en un clic pour l’agence | 0.3.0 |
| `self_update` | lire ses mises à jour sur la plateforme | 0.18.0 |
| `cron_waits` | faire attendre le cron pendant une copie de fichiers | 0.19.0 |

## Erreurs

Les refus sont des `WP_Error` JSON (`code`, `message`, `data.status`). La plateforme lit les codes
`hop_*`, par exemple :
- `hop_unauthorized`, `hop_clock_skew`, `hop_ip_denied` ;
- `hop_action_denied`, `hop_locked` ;
- `hop_no_update`, `hop_version_mismatch` ;
- `hop_no_backup`.

Un code n'est jamais renommé.

## Règles d'évolution

- **Ajouter** une route, un paramètre facultatif, un champ de réponse ou une capacité : possible à
  tout moment, dans une version mineure.
- **Ne jamais** renommer une route, un paramètre, un champ, une capacité ou un code d'erreur, ni
  changer leur sens.
- **Retirer** une route ou une capacité, ou changer un sens, demande une nouvelle adresse
  (`fred-espace-client/v2`). Les deux adresses répondent alors, le temps que la plateforme passe à
  la nouvelle.
