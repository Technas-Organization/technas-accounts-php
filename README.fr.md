# Technas Accounts pour PHP

Gérez les utilisateurs de votre app depuis votre serveur : retrouver ou créer le compte d'un
numéro ou d'un e-mail, vérifier le jeton qu'un utilisateur présente, lire les comptes, donner des
rôles, désactiver un compte. Le même secret que Technas Pay. [Read in English](README.md).

C'est le **paquet mince, pour les comptes seuls**. Vous voulez tous les produits Technas en une
installation ? Prenez [`technas/technas`](https://github.com/Technas-Organization/technas-php), qui réunit ce paquet et celui des
paiements derrière un seul client.

## Installer

```bash
composer config repositories.technas-accounts vcs https://github.com/Technas-Organization/technas-accounts-php
composer require technas/accounts:^0.1
```

Aucune dépendance Composer : PHP 8.1 ou plus, `ext-curl`, `ext-json`.

## Avant de commencer

1. Dans la [console](https://console.technas.fr), ouvrez votre app, puis **Utilisateurs ›
   Connexion**, et ouvrez la connexion de l'environnement voulu (le bac à sable d'abord).
2. Copiez votre secret (**Développeurs › Clés**) dans `TECHNAS_SECRET`. Un secret `tps_test_…`
   ne lit et n'écrit **que les utilisateurs du bac à sable** ; un secret `tps_live_…`, que ceux
   de la production.

## Utiliser

```php
use Technas\Accounts\TechnasAccounts;

$accounts = new TechnasAccounts();              // lit TECHNAS_SECRET (ou TECHNAS_PAY_SECRET)

// Quelqu'un a écrit à votre bot WhatsApp : vous savez déjà que ce numéro est le sien.
$user = $accounts->users->resolve(phone: '+22890123456', proof: 'verified');

// Votre API reçoit « Authorization: Bearer <jeton d'accès> » de votre app.
$check = $accounts->tokens->verify($accessToken);
if (!$check['valid']) { http_response_code(401); exit; }   // voir $check['reason']
echo $check['user_id'], ' ', $check['phone'];

$accounts->roles->declare(['admin', 'staff']);
$accounts->users->setRoles($user['id'], ['staff']);   // exactement ces rôles, au prochain jeton
$page = $accounts->users->list(search: 'awa', pageSize: 25);
$accounts->users->disable($user['id']);               // déconnecté partout, rien n'est supprimé
```

| Appel | Ce qu'il fait |
|---|---|
| `users->resolve(phone \| email, proof)` | Le compte de cet identifiant : retrouvé, ou créé. Un numéro demande `proof: 'verified'` |
| `users->get($id)` / `users->list(search, page, pageSize)` | Lire un utilisateur (avec ses rôles) / une page d'utilisateurs |
| `users->setRoles($id, $roles)` | Les rôles de l'utilisateur, exactement |
| `users->disable($id)` / `users->enable($id)` | Bloquer ou rendre la connexion |
| `users->revokeSessions($id)` | Déconnecter l'utilisateur partout |
| `roles->list()` / `roles->declare($roles)` | Les rôles que connaît votre app (déclarer n'en retire jamais) |
| `tokens->verify($token)` | Ce jeton est-il celui d'un de vos utilisateurs, encore valide ? Ne lève jamais pour un mauvais jeton |
| `config()` | Émetteur, identifiant de client et adresse de découverte à brancher dans votre app (OpenID Connect, PKCE) |

## Erreurs

Tout échec est une `TechnasException` avec un `code` stable, le `status` HTTP et `retryable`.

| `code` | Sens | Quoi faire |
|---|---|---|
| `invalid_secret` | Secret absent ou mal formé (vu avant tout appel) | Vérifier `TECHNAS_SECRET` |
| `invalid_api_key` | La clé est révoquée ou vient d'un autre environnement | Créer une clé dans la console |
| `accounts_not_enabled` | La connexion n'est pas ouverte pour cet environnement | Console › Utilisateurs › Connexion |
| `user_not_found` | Aucun utilisateur de ce nom dans cette app et cet environnement | Vérifier l'identifiant et le mode du secret |
| `phone_proof_required` | Un numéro ne s'enregistre que vérifié | Passer la preuve `verified` une fois le numéro vérifié |
| `unknown_role` | Le rôle n'a jamais été déclaré | Le déclarer d'abord |
| `conflict` | Plusieurs comptes correspondent, ou l'identifiant est à un autre compte | Chercher parmi les utilisateurs |
| `rate_limited`, `unavailable` | Trop d'appels, ou panne passagère | Réessayer plus tard (`retryable` vaut vrai) |

## Tests

```bash
composer test
```

Les tests tournent contre un faux serveur qui répond d'après
`contract/accounts-contract.v1.json`, le même fichier pour tous les SDK Technas.

Licence MIT.
