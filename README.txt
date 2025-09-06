Mobiele Data Only Portal (PHP 8.3, MySQL, Bootstrap)
====================================================

1) Vereisten
- PHP 8.3 + ext-pdo_mysql
- MySQL 8.x
- Webserver (Apache/Nginx). Voor eenvoudig testen kan ook de PHP built-in server.

2) Installatie
- Maak een MySQL database aan, bv: mdo_portal (utf8mb4).
- Importeer schema.sql via phpMyAdmin of mysql-cli.
- Kopieer alle bestanden naar uw web root (of host via ingebouwde server).
- Hernoem config.sample.php naar config.php en vul uw DB-gegevens in.
- (Optioneel) Pas 'session_secret' aan in config.php (lange random string).

3) Starten (dev)
php -S 0.0.0.0:8000 -t .
Open vervolgens: http://localhost:8000/index.php

4) Inloggen
- Super-admin (default): super@example.com / admin123
  Wijzig dit wachtwoord direct na de eerste login.

5) Rollen & rechten
- Super-admin: volledige rechten, /admin toegang, beheert simkaarten, plannen, leveranciers en gebruikers.
- Reseller: beheert eigen sub-resellers en eindklanten, kan simkaarten toewijzen en bestellen.
- Sub-reseller: beheert eigen eindklanten, toewijzing & bestellingen.
- Eindklant: mag alleen eigen gegevens/bestellingen zien.

6) Bestel-flow
- Reseller/Sub-reseller kan een bestelling aanmaken met status 'concept'.
- Bij 'Definitief plaatsen' gaat status naar 'wachten_op_activatie'.
- Super-admin kan status wijzigen naar 'geannuleerd' of 'voltooid'.
- Deze statuswijzigingen zijn administratief; daadwerkelijke activaties lopen extern.

7) Simkaart beheer
- Super-admin voegt simkaarten toe en wijst toe aan resellers.
- Reseller/Sub-reseller kan eigen simkaarten verder toewijzen binnen zijn boom.

8) Uitbreiden
- Leveranciers-tabel aanwezig voor toekomstige API-koppelingen.
