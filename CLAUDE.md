# CLAUDE.md

Instructions pour Claude Code (et tout agent) travaillant dans ce dépôt.

## Hook git pre-commit (lint PHP)

Ce dépôt fournit un hook `pre-commit` versionné dans `.githooks/pre-commit` qui
lance `php -l` sur chaque fichier `.php` stagé et bloque le commit si l'un
d'eux ne compile pas. Ce garde-fou existe suite à un incident où un fichier
PHP cassé (constante de classe redéfinie) a fait planter un serveur qui
chargeait le code du plugin au runtime — une erreur fatale de ce type n'est
pas rattrapable par un `try/catch` côté consommateur.

**Avant tout commit**, vérifier que le hook est actif :

```sh
git config core.hooksPath
```

S'il ne renvoie pas `.githooks`, l'activer :

```sh
git config core.hooksPath .githooks
```

Ce réglage est local au clone (non versionné dans `.git/config`), donc chaque
nouveau clone — humain ou agent — doit l'exécuter une fois.

### Pourquoi `php -l` seul (pas PHPStan/Psalm)

`php -l` (lint de syntaxe) a été retenu seul, sans PHPStan ni Psalm. Ce
dépôt est un plugin Jeedom : les classes du noyau qu'il étend (`eqLogic`,
`cmd`, `log`, ...) ne sont chargées qu'à l'exécution par Jeedom et ne sont
disponibles ni via Composer ni via un paquet de stubs officiel. Sans ces
stubs, un analyseur statique comme PHPStan remonte des centaines de faux
positifs (`unknown class eqLogic`, `Call to an undefined method`, ...) dès le
niveau le plus bas, ce qui le rend inutilisable en l'état pour un hook de
pre-commit. `php -l` reste néanmoins suffisant pour l'objectif recherché :
empêcher qu'un fichier qui ne compile pas (erreur fatale de parsing) entre
dans l'historique git.
