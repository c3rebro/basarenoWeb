<?php
// Start session with secure settings
session_start([
	'cookie_secure' => true,   // Ensure the session cookie is only sent over HTTPS
	'cookie_httponly' => true, // Prevent JavaScript access to the session cookie
	'cookie_samesite' => 'Strict' // Add SameSite attribute for additional CSRF protection
]);

$nonce = base64_encode(random_bytes(16));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'nonce-$nonce'; img-src 'self' 'nonce-$nonce' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none';");

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'guest'; // Default to guest

require_once 'utilities.php';

$success_msg = "";
$error_msg = "";

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['message'])) {
	if (!validate_csrf_token($_POST['csrf_token'])) {
		$error_msg = "CSRF-Fehler! Bitte Seite neu laden und erneut versuchen.";
	} else {
		$user_email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
		$message = htmlspecialchars($_POST['message'], ENT_QUOTES, 'UTF-8');

		if (!empty($message)) {
			$subject = "Unterstützungsanfrage von Benutzerrolle: $role";
			$body = "Email: " . ($user_email ?: "Nicht angegeben") . "\n\nMessage:\n$message";
			send_email('support@appmazing.de', $subject, $body);
			$success_msg = "Mitteilung erfolgreich gesendet. Vielen Dank!";
		} else {
			$error_msg = "Bitte geben Sie eine Nachricht ein.";
		}
	}
}
?>
<!DOCTYPE html>
<html lang="de">

<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
	<title>Support & Hilfe</title>
	<link rel="stylesheet" href="css/bootstrap.min.css">
	<link rel="icon" type="image/x-icon" href="favicon.ico">
	<!-- Preload and link CSS files -->
	<link rel="preload" href="css/bootstrap.min.css" as="style" id="bootstrap-css">
	<link rel="preload" href="css/all.min.css" as="style" id="all-css">
	<link rel="preload" href="css/style.css" as="style" id="style-css">
	<noscript>
		<link href="css/bootstrap.min.css" rel="stylesheet">
		<link href="css/all.min.css" rel="stylesheet">
		<link href="css/style.css" rel="stylesheet">
	</noscript>
	<script nonce="<?php echo $nonce; ?>">
		document.getElementById('bootstrap-css').rel = 'stylesheet';
		document.getElementById('all-css').rel = 'stylesheet';
		document.getElementById('style-css').rel = 'stylesheet';
	</script>

	<script src="js/jquery-3.7.1.min.js" nonce="<?php echo $nonce; ?>"></script>
	<script src="js/popper.min.js" nonce="<?php echo $nonce; ?>"></script>
	<script src="js/bootstrap.min.js" nonce="<?php echo $nonce; ?>"></script>
	<script nonce="<?php echo $nonce; ?>">
		$(document).ready(function() {
			$("#searchInput").on("keyup", function() {
				var value = $(this).val().toLowerCase();
				var matches = 0;

				$(".faq-item").each(function() {
					if ($(this).text().toLowerCase().indexOf(value) > -1) {
						$(this).show();
						matches++;
					} else {
						$(this).hide();
					}
				});

				if (matches === 0) {
					$("#noResults").show();
				} else {
					$("#noResults").hide();
				}
			});
		});
	</script>
</head>

<body>
	<!-- Navbar -->
	<?php include 'navbar.php'; ?> <!-- Include the dynamic navbar -->

	<div class="container">
		<h1 class="mt-4">Hilfe & Support</h1>
		<p>Hier findest Du Anleitungen und Lösungen für häufige Probleme. Außerdem kannst Du dem <a href="#contact">Team</a> eine Mitteilung zukommen lassen.</p>

		<input type="text" id="searchInput" class="form-control mb-3" placeholder="Suche nach Themen...">
		<p id="noResults" class="text-muted mt-3 hidden">Keine Ergebnisse gefunden.</p>

		<!-- Guest Welcome Section -->
		<?php if ($role == 'guest'): ?>
			<div class="alert alert-info">
				<h4>Willkommen! </h4>
				<p>Erstelle ein Konto oder logge dich ein, um alle Funktionen nutzen zu können.</p>
				<a href="index.php" class="btn btn-secondary">Registrieren</a>
				<a href="login.php" class="btn btn-primary">Anmelden</a>
			</div>
		<?php endif; ?>

		<!-- FAQ Sections -->
		<div id="faqContent">
			<div class="faq-item card">
				<div class="card-header">
					<h2 class="mb-0">
						<button class="btn btn-link" type="button" data-toggle="collapse" data-target="#generalHelp">
							<h4>📖 Allgemeine-Infos</h4>
						</button>
					</h2>
					<p class="text-center text-md-left">Hier findest Du grundsätzliches zur Bedienung.</p>
				</div>
				<div id="generalHelp" class="collapse fade">
					<div class="card-body">
						<div class="accordion" id="commonGuide">
							<ul>
								<li>
									<h5>🔐Wie melde ich mich an?</h5>
									<p>Besuche die <a href="login.php">Login-Seite</a>, gib deine E-Mail-Adresse und dein Passwort ein und klicke auf "Anmelden".</p>
								</li>

								<li>
									<h5>🆕 Wie registriere ich mich?</h5>
									<p>Falls Du noch kein Konto hast, kannst Du dich <a href="register.php">hier registrieren</a>. Folge den Anweisungen, um dein Konto zu erstellen.</p>
								</li>
								
								</li>

								<li>
									<h5>❓ Ich habe mein Passwort vergessen. Was kann ich tun?</h5>
									<p>Kein Problem! Gehe zur <a href="forgot_password.php">Passwort-Vergessen-Seite</a>, gib Deine E-Mail-Adresse ein und folge den Anweisungen, um ein neues Passwort zu setzen.</p>
								</li>

								<li>
									<h5>🔄 Wie kann ich mein Passwort zurücksetzen?</h5>
									<p>Falls Du einen Reset-Link per E-Mail erhalten hast, klicke auf den Link und folge den Schritten zur <a href="reset_password.php">Passwort-Zurücksetzen-Seite</a>.</p>
								</li>

								<li>
									<h5>🚪 Wie melde ich mich ab?</h5>
									<p>Klicke auf den "Abmelden"-Button in der Navigation oder besuche <a href="logout.php">diese Seite</a>, um Dich sicher abzumelden.</p>
								</li>

								<li>
									<h5>📌 Wie navigiere ich in der Anwendung?</h5>
									<p>Die Navigation befindet sich oben auf der Seite. Je nach Benutzerrolle hast Du Zugriff auf verschiedene Seiten:</p>
									<ul>
										<li>👤 **Alle Nutzer:** Startseite, Mein Konto</li>
										<li>🛒 **Verkäufer:** Verkäufer-Dashboard, Meine Artikel</li>
										<li>💰 **Kassierer:** Kassenbereich</li>
										<li>🛠 **Admin:** Admin-Dashboard, Benutzerverwaltung</li>
									</ul>
								</li>
							</ul>
						</div>
					</div>
				</div>
			</div>

			<div class="faq-item card">
				<div class="card-header">
					<h2 class="mb-0 text-center text-md-left">
						<button class="btn btn-link" type="button" data-toggle="collapse" data-target="#sellerRegistration">
							<h4>📖 Verkäufer-Anleitung</h4>
						</button>
					</h2>
					<p class="text-center text-md-left">Hier findest du alle wichtigen Informationen zur Bedienung des Systems als Verkäufer.</p>
				</div>
				<div id="sellerRegistration" class="collapse fade">
					<div class="card-body">


						<div class="accordion" id="sellerGuide">
							<!-- 1️⃣ Überblick -->
							<div class="card">
								<div class="card-header" id="overviewHeading">
									<h2 class="mb-0 text-center text-md-left">
										<button class="btn btn-link" type="button" data-toggle="collapse" data-target="#overview">
											<h5>1️⃣ Der Registrierungsprozess</h5>
										</button>
									</h2>
									<p class="text-center text-md-left">Um als Verkäufer am Basar teilzunehmen, musst Du ein Verkäuferkonto erstellen. Folge diesen Schritten, um Dich zu registrieren:</p>
								</div>
								<div id="overview" class="collapse fade" data-parent="#sellerGuide">
									<div class="card-body">
										<ol>
											<li>
												<h5>Öffne die Startseite</h5>
												<p>Besuche die <a href="index.php">Startseite</a>. Diese Seite wird <strong>dynamisch generiert</strong>, je nach aktueller Basarphase.</p>
											</li>
											<li>
												<h5>Überprüfe die Basarverfügbarkeit</h5>
												<p>Falls der Basar noch nicht geöffnet ist oder die maximale Anzahl an Verkäufern erreicht wurde, kann die Registrierung gesperrt sein. Die Seite zeigt dann eine entsprechende Nachricht.</p>
											</li>
											<li>
												<h5>Verkäufernummer beantragen</h5>
												<p>Falls eine Registrierung möglich ist, siehst Du ein Formular zur Anmeldung als Verkäufer.</p>
												<ul>
													<li>Fülle alle Pflichtfelder aus, einschließlich Name, Adresse, Telefonnummer und E-Mail.</li>
													<li>Erstelle ein sicheres Passwort (mindestens 6 Zeichen, ein Groß- und ein Kleinbuchstabe).</li>
													<li>Akzeptiere die Datenschutzbedingungen.</li>
													<li>Klicke auf <strong>"Verkäufernummer anfordern"</strong>.</li>
												</ul>
											</li>
											<li>
												<h5>E-Mail-Bestätigung</h5>
												<p>Nach dem Absenden erhältst Du eine Bestätigungs-E-Mail. Klicke auf den enthaltenen Link, um Dein Konto zu aktivieren.</p>
											</li>
											<li>
												<h5>Anmelden und Starten</h5>
												<p>Sobald Dein Konto bestätigt wurde, kannst Du Dich auf der <a href="login.php">Login-Seite</a> anmelden und Deine Verkäufernummer nutzen.</p>
											</li>
										</ol>
										<h5><strong>❓ Probleme bei der Registrierung?</strong></h5>
										<p>Falls Du keine E-Mail erhalten hast, überprüfe Deinen Spam-Ordner oder versuche es mit einer anderen E-Mail-Adresse. Bei weiteren Problemen kontaktiere den Support.</p>
									</div>
								</div>
							</div>

							<div class="card">
								<div class="card-header">
									<h2 class="mb-0 text-center text-md-left">
										<button class="btn btn-link" type="button" data-toggle="collapse" data-target="#requestSellerNumber">
											<h5>2️⃣ Verkäufernummer im Dashboard anfordern</h5>
										</button>
									</h2>
									<p class="text-center text-md-left">
										Nachdem Du Dein Konto erstellt hast, kannst Du eine Verkäufernummer beantragen. Erfahre hier wie das geht:
									</p>
								</div>
								<div id="requestSellerNumber" class="collapse fade">
									<div class="card-body">
										<h5>📌 Voraussetzungen:</h5>
										<ul>
											<li>✔ Du musst <strong>angemeldet</strong> sein.</li>
											<li>✔ Dein Konto muss <strong>verifiziert</strong> sein (über den E-Mail-Bestätigungslink).</li>
											<li>✔ Es muss ein <strong>offener Basar</strong> mit freien Verkäuferplätzen verfügbar sein.</li>
										</ul>

										<h5>🔹 Schritt-für-Schritt Anleitung</h5>
										<ol>
											<li>
												<h6>Verkäufer-Dashboard aufrufen</h6>
												<p>Logge Dich ein und öffne das <a href="seller_dashboard.php">Verkäufer-Dashboard</a>.</p>
											</li>
											<li>
												<h6>Verfügbare Basare prüfen</h6>
												<p>Falls ein kommender Basar verfügbar ist, wird eine Tabelle mit den Details angezeigt.</p>
											</li>
											<li>
												<h6>Verkäufernummer beantragen</h6>
												<p>Falls eine Nummernvergabe möglich ist:</p>
												<ul>
													<li>✅ Klicke auf <strong>"Verkäufernummer anfordern"</strong>.</li>
													<li>✅ Dein Antrag wird verarbeitet und Du erhältst eine Bestätigung.</li>
												</ul>
											</li>
											<li>
												<h6>Falls Du bereits eine Verkäufernummer hast</h6>
												<p>Falls Du bereits eine aktive Verkäufernummer hast, kannst Du eine zweite Nummer nur als <strong>Helfer</strong> beantragen.</p>
												<ul>
													<li>Aktiviere die Option „Ich möchte mich als Helfer eintragen lassen“.</li>
													<li>Wähle aus, wie Du helfen möchtest (z. B. Kuchen backen, Rücksortieren, Aufsicht führen).</li>
													<li>Bestätige die Anfrage.</li>
												</ul>
											</li>
											<li>
												<h6>Bestätigung und Verwaltung Deiner Nummer</h6>
												<p>Falls erfolgreich, siehst Du Deine Verkäufernummer im Dashboard.</p>
												<p>Falls ein Problem auftritt, kannst Du Dich über das <a href="support.php">Supportformular</a> melden.</p>
											</li>
										</ol>

										<h5>❓ Häufige Fragen (FAQ)</h5>
										<ul>
											<li><strong>Warum kann ich keine Verkäufernummer anfordern?</strong>
												<p>Es gibt möglicherweise keinen aktiven Basar oder Dein Konto ist nicht verifiziert.</p>
											</li>
											<li><strong>Wie erfahre ich, ob mein Antrag erfolgreich war?</strong>
												<p>Nach dem Absenden erhältst Du eine Bestätigungsmeldung direkt im Dashboard.</p>
											</li>
											<li><strong>Wie kann ich meine Verkäufernummer zurückgeben?</strong>
												<p>In der Verkäuferverwaltung kannst Du Deine Nummer entweder <strong>freischalten</strong> oder <strong>zurückgeben</strong>.</p>
											</li>
										</ul>

										<div class="alert alert-info">
											<h5>✨ Nächster Schritt:</h5>
											<p><strong>📌 3️⃣ Artikel erfassen & verwalten</strong> (Wie trage ich meine Produkte in das System ein?)</p>
										</div>
									</div>
								</div>
							</div>

							<div class="card">
								<div class="card-header">
									<h2 class="mb-0 text-center text-md-left">
										<button class="btn btn-link" type="button" data-toggle="collapse" data-target="#createProductsGuide">
											<h5>3️⃣ Artikel erstellen & verwalten</h5>
										</button>
									</h2>
									<p class="text-center text-md-left">
										Nach Erhalt Deiner Verkäufernummer kannst Du hier Deine Artikel anlegen und verwalten.
									</p>
								</div>
								<div id="createProductsGuide" class="collapse fade">
									<div class="card-body">
										<h5>📌 Voraussetzungen:</h5>
										<ul>
											<li>✔ Du benötigst eine <strong>aktive Verkäufernummer</strong>.</li>
											<li>✔ Ein <strong>offener Basar</strong> muss verfügbar sein.</li>
											<li>✔ Dein Konto muss <strong>verifiziert</strong> sein.</li>
										</ul>

										<h5>🔹 Schritt-für-Schritt Anleitung</h5>
										<ul>
											<li>
												<h5>1️⃣ Verkäufernummer auswählen</h5>
												<p>Falls Du mehrere Verkäufernummern besitzt, wähle zuerst die richtige Nummer im <strong>Dropdown-Menü</strong> aus.</p>
											</li>
											<li>
												<h5>2️⃣ Artikel erstellen</h5>
												<p>Trage Deine Artikelinformationen ein:</p>
												<ul>
													<li>✅ Name des Artikels</li>
													<li>✅ Größe (optional)</li>
													<li>✅ Preis (muss den Vorgaben entsprechen)</li>
												</ul>
												<p>Klicke dann auf <strong>"Artikel erstellen"</strong>.</p>
											</li>
											<li>
												<h5>3️⃣ Artikel importieren (optional)</h5>
												<p>Falls Du mehrere Artikel hast, kannst Du eine <strong>CSV-Datei</strong> hochladen.</p>
												<ul>
													<li>✅ Die Datei sollte <strong>Artikelname, Größe und Preis</strong> enthalten.</li>
													<li>✅ Wähle das richtige <strong>Trennzeichen</strong> (Komma oder Semikolon).</li>
													<li>✅ Achte auf die <strong>richtige Kodierung</strong> (UTF-8 oder ANSI).</li>
												</ul>
												<p>Nutze die Vorschau, um Fehler vor dem Import zu erkennen.</p>
											</li>
											<li>
												<h5>4️⃣ Artikel verwalten</h5>
												<p>Nach der Erstellung kannst Du:</p>
												<ul>
													<li>✅ <strong>Artikel bearbeiten</strong> (Name & Größe ändern, Preis bleibt gleich).</li>
													<li>✅ <strong>Artikel ins Lager verschieben</strong>, wenn Du sie nicht mehr aktiv verkaufen möchtest.</li>
													<li>✅ <strong>Artikel löschen</strong>, falls sie nicht mehr benötigt werden.</li>
												</ul>
											</li>
											<li>
												<h5>5️⃣ Artikel für den Basar vorbereiten</h5>
												<p>Deine Artikel müssen vor dem Basar <strong>zum Verkauf gestellt</strong> werden.</p>
												<p>Nutze dafür die Funktion <strong>„Zum Verkauf stellen“</strong>, um Artikel aus dem Lager in den aktiven Verkauf zu verschieben.</p>
											</li>
											<li>
												<h5>6️⃣ Etiketten drucken</h5>
												<p>Sobald Deine Artikel erstellt sind, kannst Du über den Button <strong>„Etiketten drucken“</strong> die QR-Codes für Deine Artikel generieren.</p>
											</li>
										</ul>

										<h5>❓ Häufige Fragen (FAQ)</h5>
										<ul>
											<li><strong>Warum kann ich keine Artikel erstellen?</strong>
												<p>Überprüfe, ob Du eine Verkäufernummer hast und der Basar aktiv ist.</p>
											</li>
											<li><strong>Warum kann ich den Preis nicht ändern?</strong>
												<p>Preisänderungen sind aus Sicherheitsgründen nicht möglich. Lösche den Artikel und erstelle ihn neu.</p>
											</li>
											<li><strong>Wie kann ich meine Artikel schnell erfassen?</strong>
												<p>Nutze die <strong>Import-Funktion</strong> mit einer CSV-Datei für eine schnelle Massen-Erstellung.</p>
											</li>
										</ul>

										<div class="alert alert-info">
											<h5>✨ Nächster Schritt:</h5>
											<p><strong>📌 4️⃣ Artikel verkaufen</strong> (Wie werden meine Artikel verkauft?)</p>
										</div>
									</div>
								</div>
							</div>

						</div>
					</div>
				</div>
			</div>

			<?php if ($role == 'cashier' || $role == 'admin' || $role == 'assistant'): ?>
				<div class="faq-item card">
					<div class="card-header">
						<h2 class="mb-0 text-center text-md-left">
							<button class="btn btn-link" type="button" data-toggle="collapse" data-target="#cashierHelp">
								<h4>📖 Kassierer-Anleitung</h4>
							</button>
						</h2>
					<p class="text-center text-md-left">Hier findest du alle wichtigen Informationen zur Bedienung des Kassensystems.</p>
				</div>
					<div id="cashierHelp" class="collapse fade">
						<div class="card-body">
							<div class="mt-1">

								<div class="accordion" id="cashierGuide">

									<!-- 1️⃣ Produkte Scannen -->
									<div class="card">
										<div class="card-header" id="scanHeading">
											<h2 class="mb-0">
												<button class="btn btn-link collapsed" type="button" data-toggle="collapse" data-target="#scan">
													1️⃣ Produkte Scannen
												</button>
											</h2>
										</div>
										<div id="scan" class="collapse fade" data-parent="#cashierGuide">
											<div class="card-body">
												<p><strong>So scannst du einen Artikel:</strong></p>
												<ol>
													<li>Halte den Artikel so, dass der Barcode gut sichtbar ist.</li>
													<li>Nutze den Scanner – Falls die Kamera nicht funktioniert, nutze die manuelle Eingabe.</li>
													<li>Nach dem Scannen wird der Artikel in der Liste angezeigt.</li>
												</ol>
												<p><strong>❌ Probleme mit dem Scannen?</strong></p>
												<ul>
													<li>Überprüfe, ob der Barcode gut lesbar ist.</li>
													<li>Falls der Scanner nicht erkennt, gib den Barcode manuell ein.</li>
												</ul>
											</div>
										</div>
									</div>

									<!-- 2️⃣ Manuelle Barcode-Eingabe -->
									<div class="card">
										<div class="card-header" id="manualInputHeading">
											<h2 class="mb-0">
												<button class="btn btn-link collapsed" type="button" data-toggle="collapse" data-target="#manualInput">
													2️⃣ Manuelle Barcode-Eingabe
												</button>
											</h2>
										</div>
										<div id="manualInput" class="collapse" data-parent="#cashierGuide">
											<div class="card-body">
												<p><strong>Falls der Scanner nicht funktioniert, kannst du den Barcode manuell eingeben:</strong></p>
												<ol>
													<li>Gehe zum Feld „Manuelle Barcodeeingabe“.</li>
													<li>Tippe den Barcode des Produkts ein.</li>
													<li>Klicke auf „Artikel hinzufügen“.</li>
												</ol>
												<p><strong>❌ Fehlermeldung „Produkt nicht gefunden“?</strong></p>
												<ul>
													<li>Überprüfe den Barcode auf Tippfehler.</li>
													<li>Falls das Produkt fehlt, informiere einen <strong>Administrator</strong>.</li>
												</ul>
											</div>
										</div>
									</div>

									<!-- 3️⃣ Wechselgeld berechnen -->
									<div class="card">
										<div class="card-header" id="changeCalcHeading">
											<h2 class="mb-0">
												<button class="btn btn-link collapsed" type="button" data-toggle="collapse" data-target="#changeCalc">
													3️⃣ Wechselgeld berechnen
												</button>
											</h2>
										</div>
										<div id="changeCalc" class="collapse" data-parent="#cashierGuide">
											<div class="card-body">
												<p><strong>So berechnest du das Wechselgeld:</strong></p>
												<ol>
													<li>Klicke auf „Abschluss“.</li>
													<li>Gib den erhaltenen Betrag ein.</li>
													<li>Das System berechnet das Wechselgeld automatisch.</li>
													<li>Bestätige den Abschluss, um den Verkauf abzuschließen.</li>
												</ol>
												<p><strong>❌ Fehlermeldung „Ungültiger Betrag“?</strong></p>
												<ul>
													<li>Der eingegebene Betrag muss <strong>mindestens die Gesamtsumme</strong> der Artikel sein.</li>
												</ul>
											</div>
										</div>
									</div>

									<!-- 4️⃣ Verkauf rückgängig machen -->
									<div class="card">
										<div class="card-header" id="removeProductHeading">
											<h2 class="mb-0">
												<button class="btn btn-link collapsed" type="button" data-toggle="collapse" data-target="#removeProduct">
													4️⃣ Verkauf rückgängig machen (Artikel entfernen)
												</button>
											</h2>
										</div>
										<div id="removeProduct" class="collapse" data-parent="#cashierGuide">
											<div class="card-body">
												<p>Falls du einen Artikel <strong>versehentlich gescannt hast</strong>, kannst du ihn entfernen:</p>
												<ul>
													<li>Klicke auf „Entfernen“ neben dem Artikel in der Liste.</li>
													<li>Der Artikel wird aus der aktuellen Rechnung entfernt.</li>
												</ul>
												<p><strong>❗ Falls ein Verkauf bereits abgeschlossen wurde, kann nur ein Administrator eine Rückerstattung durchführen.</strong></p>
											</div>
										</div>
									</div>

									<!-- 5️⃣ Support -->
									<div class="card">
										<div class="card-header" id="supportHeading">
											<h2 class="mb-0">
												<button class="btn btn-link collapsed" type="button" data-toggle="collapse" data-target="#support">
													5️⃣ Support & Hilfe
												</button>
											</h2>
										</div>
										<div id="support" class="collapse" data-parent="#cashierGuide">
											<div class="card-body">
												<p>Falls du weiterhin Probleme hast, nutze das <strong>Support-Formular</strong> oder wende dich an einen Administrator.</p>
												<p><strong>📩 Direkter Kontakt:</strong></p>
												<ul>
													<li>Falls du eine dringende Frage hast, sende eine Support-Anfrage.</li>
												</ul>
											</div>
										</div>
									</div>

								</div>
							</div>

						</div>
					</div>
				</div>
			<?php endif; ?>

			<?php if ($role == 'admin'): ?>
				<div class="faq-item card">
					<div class="card-header">
						<h2 class="mb-0">
							<button class="btn btn-link" type="button" data-toggle="collapse" data-target="#adminHelp">
								Admin-Anleitung
							</button>
						</h2>
					</div>
					<div id="adminHelp" class="collapse">
						<div class="card-body">
							<ul>
								<li><strong>Wie erstelle ich neue Benutzer?</strong> Gehe zur Admin-Seite.</li>
								<li><strong>Wie überprüfe ich Verkaufshistorien?</strong> Schaue in die Logs.</li>
							</ul>
						</div>
					</div>
				</div>
			<?php endif; ?>
		</div>

		<!-- 6️⃣ Support Contact Form -->
		<h3 id="contact" class="mt-5">Kontakt</h3>
		<p>Du benötigst Hilfe oder es fehlt eine bestimmte Funktion? Schreib uns. 😊</p>

		<?php if (isset($success_msg) && $success_msg != "") echo "<div class='alert alert-success'>$success_msg</div>"; ?>
		<?php if (isset($error_msg) && $error_msg != "") echo "<div class='alert alert-danger'>$error_msg</div>"; ?>

		<form action="support.php" method="post">
			<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
			<div class="form-group">
				<label for="email">Deine E-Mail (optional)</label>
				<input type="email" class="form-control" name="email" placeholder="Deine E-Mail-Adresse, wenn Du eine Antwort wünschst.">
			</div>
			<div class="form-group">
				<label for="message">Ihre Nachricht</label>
				<textarea class="form-control" name="message" required></textarea>
			</div>
			<button type="submit" class="btn btn-primary">Absenden</button>
		</form>
	</div>

	<!-- Back to Top Button -->
	<div id="back-to-top"><i class="fas fa-arrow-up"></i></div>

	<script nonce="<?php echo $nonce; ?>">
        $(document).ready(function() {
            // Function to toggle the visibility of the "Back to Top" button
            function toggleBackToTopButton() {
                const scrollTop = $(window).scrollTop();

                if (scrollTop > 100) {
                    $('#back-to-top').fadeIn();
                } else {
                    $('#back-to-top').fadeOut();
                }
            }

            // Initial check on page load
            toggleBackToTopButton();

            // Show or hide the "Back to Top" button on scroll
            $(window).scroll(function() {
                toggleBackToTopButton();
            });

            // Smooth scroll to top
            $('#back-to-top').click(function() {
                $('html, body').animate({ scrollTop: 0 }, 600);
                return false;
            });

        });
    </script>
</body>

</html>