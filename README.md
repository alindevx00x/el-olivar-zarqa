<!-- Developer: Alindevx00x (https://alindevx00x.me) -->
<img src="assets/icon.svg" width="64" height="45" alt="">

# EL OLIVAR ZARQA

Site vitrine en PHP pour la maison rurale El Olivar à Ain Zarka, près de Tétouan. Il présente le logement, ses équipements, les environs et dirige les voyageurs vers Booking.com et Airbnb. Le formulaire de demande enregistre les demandes dans MySQL. Le français est la langue par défaut ; l’anglais, l’espagnol et l’arabe sont également disponibles. La mise en page arabe passe en droite-à-gauche.

## Démarrer avec MAMP

1. Placez le dossier du projet dans `/Applications/MAMP/htdocs/` (ou définissez-le comme racine web MAMP).
2. Démarrez les serveurs Apache et MySQL dans MAMP.
3. Ouvrez phpMyAdmin depuis la page d’accueil MAMP, puis importez `database.sql`.
4. Vérifiez les paramètres dans `config/database.php`. Les valeurs initiales correspondent aux réglages MAMP courants : hôte `127.0.0.1`, port MySQL `8889`, base `el_olivar_zarqa`, utilisateur `root`, mot de passe `root`.
5. Ouvrez `http://localhost:8888/el-olivar-zarqa/` (adaptez le chemin si vous avez choisi un autre nom de dossier).

## Utiliser le formulaire et consulter les demandes

Le visiteur saisit son nom, son e-mail, les dates et le nombre de voyageurs, puis accepte la case de consentement et choisit **Envoyer ma demande**. Le message est facultatif. Le formulaire enregistre une demande de séjour, il ne confirme pas une réservation. Les demandes soumises se trouvent dans phpMyAdmin : ouvrez la base `el_olivar_zarqa`, puis la table `stay_inquiries`. Triez `created_at` par ordre décroissant pour voir les plus récentes. Chaque ligne contient l’identité et les coordonnées du voyageur, les dates, le nombre de personnes, le message éventuel, la date du consentement et l’état de suivi.

Aucune notification par e-mail n’est configurée : les demandes sont enregistrées dans MySQL, mais ne sont pas envoyées automatiquement à une adresse. Consultez la table dans phpMyAdmin ou ajoutez un service e-mail avant une mise en ligne publique. Pour un hébergement en production, remplacez les identifiants de démonstration et gardez le fichier de configuration hors d’un dépôt public.

## Administration et export

Si le site est déjà installé, importez de nouveau `database.sql` dans phpMyAdmin. Les instructions `CREATE TABLE IF NOT EXISTS` ajoutent la table des comptes sans effacer les demandes existantes. Ouvrez ensuite `/admin/setup.php` et créez votre identifiant avec un mot de passe d’au moins 12 caractères. Cette page de création initiale se désactive après la création du premier compte. Connectez-vous ensuite sur `/admin/index.php` pour consulter les demandes ou télécharger l’export complet avec **Exporter en CSV**. Aucun identifiant par défaut n’est partagé : vous choisissez votre propre mot de passe pendant la configuration.

Pour réinitialiser le compte, supprimez son enregistrement dans la table `admin_users` avec phpMyAdmin. La page `/admin/setup.php` permettra alors de créer un nouveau compte administrateur.

## Contenu du site et fonctionnalités

- **Site responsive & multilingue** : Disponible en français (par défaut), anglais, espagnol et arabe poétique marocain (avec inversion automatique RTL).
- **Performances & Images WebP** : Servies via la balise `<picture>` avec formats WebP modernes et fallback JPEG.
- **Référencement & Réseaux Sociaux** : Balisage Schema.org (`LodgingBusiness`), Open Graph et Twitter Cards complets pour des aperçus optimaux sur WhatsApp, Facebook et X.
- **Formulaire interactif sans rechargement** : Soumission fluide en AJAX avec notification en place sans faire défiler ou sauter la page.
- **Bouton WhatsApp flottant** : Discussion directe pré-remplie avec les dates et le nombre de voyageurs sélectionnés.
- **Logos officiels SVG** : Icônes vectorielles nettes pour Booking.com, Airbnb, Instagram, Google Calendar et WhatsApp.
- **Intégration Google Maps** : Liens directs vers [la fiche officielle Google Maps](https://maps.app.goo.gl/K4xCdhYZC3KkJu1q8).

## Espace Administrateur (`/admin/`)

- **Gestion des demandes en direct (CRM)** :
  - **Changement de statut en 1 clic** : Bascule instantanée entre *Nouvelle*, *Contactée* et *Clôturée* sans recharger la page.
  - **Notes internes privées** : Prise de notes inline avec sauvegarde automatique (ex: *« Acompte reçu »*, *« Lit bébé »*).
  - **Filtres & recherche en temps réel** : Recherche instantanée par nom, e-mail, dates, message ou note interne, et filtrage par badge de statut.
  - **Ajout à Google Calendar** : Bouton individuel par ligne ou sélection multiple avec modal interactif et export de calendrier universel `.ics`.
  - **Changement de mot de passe sécurisé** : Modifiez votre mot de passe administrateur directement depuis l'en-tête du tableau de bord.
  - **Export CSV** : Téléchargement complet des demandes pour Excel ou tableurs.

## Photos

Les photos représentent le logement de l’annonce fournie. Le salon provient de l’URL image Booking.com indexée et vérifiée. Booking.com renvoyait des erreurs 404 pour les autres URL de photos récupérables ; les autres vues locales proviennent de la même annonce Airbnb fournie. Le site n’a pas besoin de charger les images depuis ces plateformes à chaque visite.

Développement : [Alindevx00x](https://alindevx00x.me).
