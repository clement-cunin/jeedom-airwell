# Changelog

## 2026-11-13

- Nouveau bouton **Diagnostic** sur l'équipement : teste le sous-réseau, la présence de l'appareil (ping / ARP), la réponse au protocole Gree en direct, la découverte par broadcast et la clé. Il conclut sur la cause la plus probable, propose de corriger l'IP ou la MAC, et permet de copier le rapport.
- Correction : l'adresse MAC saisie à la main est désormais normalisée (`7C:B8:E6:25:1C:43` → `7cb8e6251c43`), au format attendu par le climatiseur. Avant, une MAC avec séparateurs ou en majuscules faisait échouer le binding avec « GreeProtocol: no response ».
- Une adresse MAC invalide est refusée à la sauvegarde avec un message explicite.
- Les équipements existants sont corrigés automatiquement lors de la mise à jour du plugin.
- Documentation : sauvegarder l'équipement avant de lancer le binding.

## 1.0.0

- Première publication sur le Market Jeedom
- Pilotage WiFi local via protocole Gree (sans cloud)
- Découverte automatique des climatiseurs sur le réseau
- Binding et authentification automatique (cipher v1 ECB et v2 GCM)
- Widget dashboard personnalisé
- Commandes : alimentation, mode, température, vitesse ventilateur, affichage, swing
- Rafraîchissement automatique de l'état toutes les 5 minutes
