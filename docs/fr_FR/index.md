# Plugin Airwell

Plugin Jeedom pour le pilotage des climatiseurs Airwell via le protocole Gree (WiFi local, sans cloud).

## Prérequis

- Climatiseur Airwell équipé d'un module WiFi Gree
- Jeedom 4.4+
- PHP 8.0+
- Le climatiseur doit être sur le même réseau local que Jeedom

## Installation

Installer le plugin depuis le Market Jeedom, puis l'activer.

## Configuration d'un équipement

1. Aller dans **Plugins > Confort > Airwell**
2. Cliquer sur **Ajouter**
3. Renseigner l'adresse IP et MAC du climatiseur
4. Cliquer sur l'icône de binding pour établir la connexion
5. Sauvegarder

## Commandes disponibles

| Commande | Type | Description |
|---|---|---|
| Alimentation | Info binaire | État on/off |
| Mode | Info string | Mode actif (auto/cool/heat/dry/fan_only) |
| Température consigne | Info numérique | Température cible en °C |
| Vitesse ventilateur | Info numérique | Vitesse du ventilateur |
| Allumer | Action | Met en marche le climatiseur |
| Éteindre | Action | Arrête le climatiseur |
| Régler température | Action slider | Définit la température consigne (16-30°C) |
| Régler mode | Action select | Change le mode de fonctionnement |
