<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <title>bazaar - Leitfaden</title>
    <meta name="description" content="Ein visueller Leitfaden zur Nutzung der bazaar Nummernbasar Software.">
    <link rel="stylesheet" href="assets/bootstrap/css/bootstrap.min.css">
    <style>
        .navbar-brand img {
            height: 30px;
            margin-right: 10px;
        }
        .carousel-control-prev-icon,
        .carousel-control-next-icon {
            background-color: rgba(0, 0, 0, 0.5); /* Darker background for better visibility */
            border-radius: 50%;
            padding: 10px;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-md navbar-light bg-light py-3">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="index.php">
                <img src="assets/img/favicon/favicon-96x96.png" alt="bazaar Logo">
                bazaar
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item"><a class="nav-link" href="index.php">Home</a></li>
                    <li class="nav-item"><a class="nav-link active" href="#">Leitfaden</a></li>
                    <li class="nav-item"><a class="nav-link" href="download.php">Download</a></li>
                    <li class="nav-item"><a class="nav-link" href="about.php">Über uns</a></li>
                </ul>
            </div>
        </div>
    </nav>
    <div class="container mt-4">
        <h1>Leitfaden</h1>
		<hr>
		<h3>Wie funktioniert das?</h3>
		<p>Ihr braucht einen eigenen Webserver. Das kann auch ein Raspberry sein oder ein günstiger Hoster. Ihr solltet Erfahrung in dem Bereich haben, da auch ein Mailserver für den Versand an die Verkäufer vorausgesetzt wird. Verkäufer beantragen eine Verkäufernummer über ein Online-Portal und erstellen nach Verifizierung ihrer Mailadresse ein Konto und ihre Etiketten mit Artikelnamen, Größe, Preis und einem Strichcode. Die Artikel und Verkäufer können auch exportiert und importiert werden um z.B. zwischen einer Onlineinstallation (Homepage) auf eine Offlineinstallation (Raspberry) hin und her zu Schalten. Der oder die Kassierer scannen während des Basars die Barcodes mit ihren Mobiltelefonen. Am Ende des Basars erstellt der Administrator die Abrechnung für jede Verkäufernummer. Die Software erstellt eine E-Mail und/oder einen Ausdruck für jeden Verkäufer mit einer Liste der verkauften / nicht verkauften Artikel und der Gesamtsumme.</p>
		<hr>
		<h3>Screenshots</h3>
        <div id="guideCarousel" class="carousel slide" data-bs-ride="carousel">
            <div class="carousel-inner">
                <div class="carousel-item active">
                    <img src="https://github.com/c3rebro/bazaar/blob/master/docs/Clipboard01.jpg?raw=true" class="d-block w-50" alt="Installationsassistent">
                    <div class="carousel-caption">
                        <p>Installationsassistent</p>
                    </div>
                </div>
                <div class="carousel-item">
                    <img src="https://github.com/c3rebro/bazaar/blob/master/docs/Clipboard02.jpg?raw=true" class="d-block w-100" alt="Verkäufernummer anfordern">
                    <div class="carousel-caption">
                        <p>Verkäufernummer anfordern</p>
                    </div>
                </div>
                <div class="carousel-item">
                    <img src="https://github.com/c3rebro/bazaar/blob/master/docs/Clipboard03.jpg?raw=true" class="d-block w-100" alt="Bestätigungs-Email">
                    <div class="carousel-caption">
                        <p>Bestätigungs-Email</p>
                    </div>
                </div>
                <!-- Add more carousel items for each image -->
            </div>
            <a class="carousel-control-prev" href="#guideCarousel" role="button" data-bs-slide="prev">
                <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                <span class="visually-hidden">Previous</span>
            </a>
            <a class="carousel-control-next" href="#guideCarousel" role="button" data-bs-slide="next">
                <span class="carousel-control-next-icon" aria-hidden="true"></span>
                <span class="visually-hidden">Next</span>
            </a>
        </div>
    </div>
    <script src="assets/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>