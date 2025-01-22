# BasarenoWeb

**BasarenoWeb** ist die kostenlose Websoftware für Nummernbasare bzw. auch bekannt als Abgabebasar. Sie vereinfacht den Prozess für Verkäufer und Veranstalter, sich zu registrieren, Etiketten mit QRcodes zu erstellen und den Verkauf sowie die Abrechnung effizient zu verwalten.

## Funktionen

- **Benutzerverwaltung**: Nach der Installation kann der System-'Admin' weitere Benutzer erstellen. Diese können eine der folgenden Rollen haben:
  - **Verkäufer**: Hat eine Verkäufernummer und kann Artikel erstellen.
  - **Assistenten**: Können alles, was Verkäufer können, und zusätzlich die Korbannahme und Korbrückgabe durchführen.
  - **Kassierer**: Können alles, was Verkäufer und Assistenten dürfen, und zusätzlich die Scannerkasse bedienen.
  - **Administratoren**: Dürfen zusätzlich Benutzer manuell administrieren und Abrechnungen durchführen.
- **Verkäuferregistrierung**: Verkäufer können über ein Online-Portal ein Konto erstellen und eine Verkäufernummer beantragen. Nach E-Mail-Verifizierung können Etiketten mit Artikelnamen, Größen, Preisen und Barcodes erstellt werden.
- **Korbannahme**: Vor dem Basar können Assistenten die Korbannahme dokumentieren und so Sicherstellen, das nur Teilnimmt, wer den Registrierungsprozess durchlaufen hat (max. Anzahl Artikel wurde eingehalten, Helferverwaltung etc.).
- **Datenmanagement**: Artikel und Verkäufer können exportiert und importiert werden, um den Übergang zwischen Online- (z. B. Website) und Offline-Installationen (z. B. Raspberry Pi) nahtlos zu gestalten.
- **Kassierbetrieb**: Kassierer können Barcodes während des Basars mit ihren Mobilgeräten scannen.
- **Abrechnung**: Nach dem Basar können Administratoren Abrechnungen für jeden Verkäufer generieren, einschließlich verkaufter und unverkaufter Artikel sowie Gesamteinnahmen.
- **Korbrückgabe**: Zum Schluss können die Assistenten die Korbannahme dokumentieren und so Sicherstellen, das nur Teilnimmt, wer den Registrierungsprozess durchlaufen hat (max. Anzahl Artikel wurde eingehalten, Helferverwaltung etc.).
- **Integration**: Enthält ein Joomla-Modul, um den Registrierungsprozess einfach in die Homepage des Basars zu integrieren. Ein einfacher Link reicht in den meisten Fällen aus.

## Voraussetzungen

- **Webserver**: z. B. nginx oder apache
- **PHP**: Version 7.4 oder höher
- **MySQL**: Version 10.3 oder höher

## Installation

1. **Dateien bereitstellen**: Kopieren Sie alle Dateien in das Web-Stammverzeichnis. Öffnen Sie die config.php.template und nehmen Sie die notwendigen Einstellungen vor (Mailversand, Datenbankzugang, geheimer Schlüssel). Falls `config.php` fehlt, wird der Einrichtungsprozess automatisch gestartet.
2. **Serveranforderungen**: Stellen Sie sicher, dass der Webserver PHP 7.4, MySQL und eine Sendmail-Installation hat.
3. **Offline-Installation**: Für Installationen auf einem Raspberry Pi für die Offline-Nutzung müssen die entsprechenden Berechtigungen gesetzt sein. Hilfe finden Sie in den Systemeinstellungen.

## Anwendungsstruktur

- `login.php`: Login
- `admin_manage_bazaar.php`: Basar-Verwaltung für Administratoren
- `admin_manage_sellers.php`: Verkäuferverwaltung und Abrechnung
- `admin_manage_users.php`: Benutzerverwaltung für den Basar
- `cashier.php`: Kassensystem
- `checkout.php`: Internes Abrechnungssystem
- `admin_dashboard.php`: Administrator-Dashboard
- `seller_dashboard.php`: Verkäufer-Dashboard
- `first_time_setup.php`: Einrichtung
- `index.php`: Verkäuferanmeldung
- `load_seller_products.php`: Artikel laden (intern)
- `print_qrcodes.php`: Etiketten drucken
- `print_verified_sellers.php`: Liste der registrierten Verkäufer drucken
- `seller_products.php`: Artikel erstellen
- `system_settings.php`: Systemeinstellungen
- `utilities.php`: PHP-Funktionen (intern)
- `verify.php`: Verifizierung von Nummernanfragen

## Screenshots

<details>
<summary>Installationsassistent</summary>
Assistent zur Einrichtung der Datenbank und erforderlicher Konfigurationen.  
![Installationsassistent](/docs/first_time_setup.png)
</details>

<details>
<summary>Verkäufernummer beantragen</summary>
Verkäufer können eine Nummer beantragen, sobald ein Basar erstellt wurde und das Startdatum der Nummernvergabe festgelegt ist.  
![Verkäufernummer beantragen](/docs/index.png)
</details>

<details>
<summary>Bestätigungs-E-Mail für Verkäufer</summary>
Verkäufer erhalten einen Link, um ihr Konto zu bestätigen.  Der Text ist Konfigurierbar.
![Bestätigungs-E-Mail](/docs/admin_manage_bazaar.jpg)
</details>

<details>
<summary>Artikel erstellen</summary>
Verkäufer fügen Artikel mit Namen und Preisen hinzu.  
![Artikel erstellen](/docs/seller_products.jpg)
</details>

<details>
<summary>Etiketten drucken</summary>
![Etiketten drucken](/docs/Clipboard01.jpg)
</details>

<details>
<summary>Kassierer-Login</summary>
Während des Verkaufs scannen Kassierer verkaufte Artikel.  
![Kassierer-Login](/docs/Clipboard01.jpg)
</details>

<details>
<summary>Artikel scannen</summary>
Jeder erkannte Artikel wird als „Verkauft“ markiert. Wenn ein Code nicht lesbar ist, können Ziffern manuell eingegeben werden. Die letzten 30 gescannten Artikel werden angezeigt und können bei Bedarf manuell zurückgesetzt werden.  
![Artikel scannen](/docs/Clipboard01.jpg)
</details>

<details>
<summary>Administrator-Login</summary>
Nach dem Basar verteilt der Administrator die Einnahmen an die Verkäufer. Eine Liste der verkauften/nicht verkauften Artikel kann angezeigt, gedruckt und/oder dem Verkäufer per E-Mail als Zusammenfassung gesendet werden.  
![Administrator-Login](/docs/Clipboard01.jpg)
</details>

<details>
<summary>Benutzerverwaltung</summary>
Nach der Ersteinrichtung können zusätzliche Kassierer und Administratoren hinzugefügt oder entfernt werden.  
![Benutzerverwaltung](/docs/Clipboard01.jpg)
</details>

<details>
<summary>Basare erstellen</summary>
Nummernvergabe ist nur möglich, nachdem der Administrator einen Basar erstellt hat und das aktuelle Datum vor dem „Startdatum der Nummernvergabe“ liegt. Nach Beginn des Basars und vor Erreichen des nächsten „Startdatums der Nummernvergabe“ ist keine Nummernvergabe möglich. Eine entsprechende Meldung erscheint.  
![Basare erstellen](/docs/Clipboard01.jpg)
</details>

## Download

Laden Sie die Software aus dem [GitHub-Repository](https://github.com/c3rebro/bazaar) herunter.

---

Für weitere Informationen besuchen Sie die [GitHub Pages-Seite des Projekts](https://c3rebro.github.io/bazaar/).
