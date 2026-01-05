<?php
/**
 * PROJET INTEROPÉRABILITÉ - ATMOSPHERE.PHP
 * Page web dynamique intégrant plusieurs APIs pour décider d'utiliser sa voiture
 * 
 * Fonctionnalités:
 * - Géolocalisation IP du client
 * - Météo avec transformation XSL
 * - Carte Leaflet avec difficultés de circulation
 * - Données COVID/SRAS
 * - Qualité de l'air
 */

// Configuration pour webetu (proxy)
// ACTIVÉ pour webetu - les serveurs IUT nécessitent un proxy pour accéder à Internet
$opts = array(
    'http' => array(
        'proxy' => 'tcp://www-cache:3128',
        'request_fulluri' => true,
        'timeout' => 5
    ),
    'https' => array(
        'proxy' => 'tcp://www-cache:3128',
        'request_fulluri' => true,
        'timeout' => 5
    ),
    'ssl' => array(
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true
    )
);
$context = stream_context_create($opts);
stream_context_set_default($opts);

// Augmenter le temps d'exécution maximum
set_time_limit(30);

// ==================== FONCTIONS UTILITAIRES ====================

/**
 * Récupère le contenu d'une URL
 */
function getUrlContent($url, $headers = []) {
    try {
        $defaultHeaders = [
            'User-Agent: Mozilla/5.0 (compatible; AtmosphereBot/1.0; +http://example.com/bot)',
            'Accept: application/json, text/html, application/xml'
        ];
        
        $allHeaders = array_merge($defaultHeaders, $headers);
        
        // Configuration adaptée pour webetu avec proxy
        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $allHeaders),
                'timeout' => 5,
                'ignore_errors' => true,
                'proxy' => 'tcp://www-cache:3128',
                'request_fulluri' => true
            ],
            'https' => [
                'method' => 'GET',
                'header' => implode("\r\n", $allHeaders),
                'timeout' => 5,
                'ignore_errors' => true,
                'proxy' => 'tcp://www-cache:3128',
                'request_fulluri' => true
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ];
        
        $context = stream_context_create($opts);
        $content = @file_get_contents($url, false, $context);
        
        if ($content === false) {
            error_log("Erreur lors de la récupération de: $url");
            return null;
        }
        return $content;
    } catch (Exception $e) {
        error_log("Exception: " . $e->getMessage());
        return null;
    }
}

/**
 * Récupère l'IP du client (pas du serveur)
 */
function getClientIP() {
    $ip = '';
    
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    
    return $ip;
}

/**
 * Géolocalise une adresse IP
 */
function geolocateIP($ip) {
    $url = "http://ip-api.com/xml/$ip";
    $xml = getUrlContent($url);

    if ($xml) {
        try {
            $data = simplexml_load_string($xml);
            if ($data && $data->status == 'success') {
                $city = (string)$data->city;
                
                // Si c'est Nancy, retourner les coordonnées réelles
                if (strtolower($city) === 'nancy') {
                    return [
                        'lat' => (float)$data->lat,
                        'lon' => (float)$data->lon,
                        'city' => $city,
                        'region' => (string)$data->regionName,
                        'country' => (string)$data->country,
                        'zip' => (string)$data->zip,
                        'timezone' => (string)$data->timezone
                    ];
                }
                
                // Si ce n'est PAS Nancy, log et continuer vers le fallback
                error_log("Géolocalisation IP hors de Nancy ($city). Utilisation de l'IUT Charlemagne.");
            }
        } catch (Exception $e) {
            error_log("Erreur géolocalisation: " . $e->getMessage());
        }
    }
    
    // Fallback : retourner les coordonnées de l'IUT directement (sans API)
    error_log("Utilisation des coordonnées par défaut de l'IUT Charlemagne");

    $iutAdress = 'IUT Charlemagne';
    $iutCoord = geocodeAddress($iutAdress);
    
    if ($iutCoord['lat'] && $iutCoord['lon']) {
        return [
            'lat' => $iutCoord['lat'],
            'lon' => $iutCoord['lon'],
            'city' => 'Nancy',
            'region' => 'Grand Est',
            'country' => 'France',
            'zip' => '54000',
            'timezone' => 'Europe/Paris'
        ];
    }
    
    return [
        'lat' => 48.6880492,
        'lon' => 6.1727318,
        'city' => 'Nancy',
        'region' => 'Grand Est',
        'country' => 'France',
        'zip' => '54000',
        'timezone' => 'Europe/Paris'
    ];
}

/**
 * Récupère les données météo
 */
function getWeatherData($lat, $lon) {
    // Utiliser l'API Open-Meteo (gratuite, sans clé API)
    $url = "https://api.open-meteo.com/v1/forecast?latitude=$lat&longitude=$lon&hourly=temperature_2m,precipitation_probability,windspeed_10m,weathercode&timezone=Europe/Paris&forecast_days=1";
    
    $json = getUrlContent($url);
    
    if ($json) {
        $data = json_decode($json, true);
        if ($data && isset($data['hourly'])) {
            return $data;
        }
    }
    
    return null;
}

/**
 * Transforme les données météo en XML selon la DTD
 */
function createWeatherXML($weatherData) {
    if (!$weatherData) return null;
    
    $xml = new DOMDocument('1.0', 'UTF-8');
    $xml->formatOutput = true;
    
    $meteo = $xml->createElement('meteo');
    $xml->appendChild($meteo);
    
    $date = $xml->createElement('date', date('Y-m-d'));
    $meteo->appendChild($date);
    
    // Extraire données par période
    $hourly = $weatherData['hourly'];
    $periods = [
        'matin' => [6, 7, 8, 9, 10, 11],
        'apres-midi' => [12, 13, 14, 15, 16, 17],
        'soir' => [18, 19, 20, 21, 22, 23]
    ];
    
    foreach ($periods as $periodName => $hours) {
        $prevision = $xml->createElement('prevision');
        $prevision->setAttribute('periode', $periodName);
        
        // Calculer températures min/max
        $temps = [];
        $precips = [];
        $winds = [];
        
        foreach ($hours as $hour) {
            if (isset($hourly['temperature_2m'][$hour])) {
                $temps[] = $hourly['temperature_2m'][$hour];
            }
            if (isset($hourly['precipitation_probability'][$hour])) {
                $precips[] = $hourly['precipitation_probability'][$hour];
            }
            if (isset($hourly['windspeed_10m'][$hour])) {
                $winds[] = $hourly['windspeed_10m'][$hour];
            }
        }
        
        if (!empty($temps)) {
            $temperature = $xml->createElement('temperature');
            $temperature->setAttribute('min', round(min($temps)));
            $temperature->setAttribute('max', round(max($temps)));
            $prevision->appendChild($temperature);
        }
        
        if (!empty($precips)) {
            $precipitation = $xml->createElement('precipitation');
            $precipitation->setAttribute('probabilite', round(max($precips)));
            $precipitation->setAttribute('type', max($precips) > 50 ? 'pluie' : 'faible');
            $prevision->appendChild($precipitation);
        }
        
        if (!empty($winds)) {
            $vent = $xml->createElement('vent');
            $vent->setAttribute('force', round(max($winds)));
            $vent->setAttribute('direction', 'Variable');
            $prevision->appendChild($vent);
        }
        
        $meteo->appendChild($prevision);
    }
    
    return $xml->saveXML();
}

/**
 * Applique la transformation XSL
 */
function transformWithXSL($xmlString, $xslFile) {
    try {
        $xml = new DOMDocument();
        $xml->loadXML($xmlString);
        
        $xsl = new DOMDocument();
        $xsl->load($xslFile);
        
        $processor = new XSLTProcessor();
        $processor->importStylesheet($xsl);
        
        return $processor->transformToXML($xml);
    } catch (Exception $e) {
        error_log("Erreur transformation XSL: " . $e->getMessage());
        return "<div class='error'>Erreur lors de la transformation XSL</div>";
    }
}

/**
 * Récupère les difficultés de circulation (Grand Est)
 */
function getTrafficData($lat, $lon) {
    // API du Grand Nancy - Données CIFS Waze
    $url = "https://carto.g-ny.org/data/cifs/cifs_waze_v2.json";
    
    $json = getUrlContent($url);
    if ($json) {
        $data = json_decode($json, true);
        
        // L'API retourne un objet avec une clé "incidents"
        if (isset($data['incidents']) && is_array($data['incidents'])) {
            error_log("Traffic: " . count($data['incidents']) . " incidents trouvés");
            return $data['incidents'];
        }
    }
    
    error_log("Traffic: Impossible de récupérer les données de trafic");
    return [];
}

/**
 * Récupère les données SRAS dans les égouts
 */
function getSRASData() {
    $url = "https://www.data.gouv.fr/fr/datasets/r/2963ccb5-344d-4978-bdd3-08aaf9efe514";
    
    $csv = getUrlContent($url);
    if (!$csv) {
        error_log("SRAS: Impossible de récupérer le CSV depuis l'URL");
        return [];
    }
    
    error_log("SRAS: CSV récupéré avec succès, taille: " . strlen($csv) . " bytes");
    
    $lines = explode("\n", $csv);
    $lines = array_filter(array_map('trim', $lines));
    
    if (count($lines) < 2) {
        error_log("SRAS: CSV vide ou invalide - nombre de lignes: " . count($lines));
        return [];
    }
    
    $header = str_getcsv(array_shift($lines), ';');
    
    $maxevilleIndex = array_search('MAXEVILLE', $header);
    if ($maxevilleIndex === false) {
        error_log("SRAS: Colonne MAXEVILLE non trouvée dans les headers: " . implode(', ', $header));
        return [];
    }
    
    error_log("SRAS: Colonne MAXEVILLE trouvée à l'index $maxevilleIndex");
    
    $data = [];
    
    foreach ($lines as $line) {
        if (empty($line)) continue;
        
        $row = str_getcsv($line, ';');
        
        if (count($row) < count($header)) continue;
        
        $item = array_combine($header, $row);
        
        // Récupérer la semaine et la valeur de MAXEVILLE
        $semaine = $item['semaine'] ?? '';
        $valueMaxeville = $item['MAXEVILLE'] ?? 'NA';
        
        // Ignorer les valeurs NA
        if ($valueMaxeville === 'NA' || $valueMaxeville === '' || empty($semaine)) {
            continue;
        }
        
        // Convertir la semaine (format "2022-S31") en date
        // Extraire l'année et le numéro de semaine
        if (preg_match('/(\d{4})-S(\d+)/', $semaine, $matches)) {
            $year = $matches[1];
            $week = $matches[2];
            
            // Créer une date à partir de la semaine (lundi de cette semaine)
            $date = new DateTime();
            $date->setISODate($year, $week);
            $dateStr = $date->format('Y-m-d');
            
            $data[] = [
                'date' => $dateStr,
                'value' => (float)str_replace(',', '.', $valueMaxeville),
                'station' => 'Maxéville'
            ];
        }
    }
    
    // Trier par date et garder les 30 derniers
    usort($data, function($a, $b) {
        return strtotime($a['date']) - strtotime($b['date']);
    });
    
    $data = array_slice($data, -30);
    
    error_log("SRAS: Données SRAS récupérées pour MAXEVILLE: " . count($data) . " enregistrements");
    
    return $data;
}

/**
 * Récupère la qualité de l'air
 */
function getAirQuality($lat, $lon) {
    // Essayer plusieurs APIs de qualité de l'air
    
    // 1. API AQICN (World Air Quality Index)
    $url = "https://api.waqi.info/feed/geo:$lat;$lon/?token=demo";
    
    $json = getUrlContent($url);
    if ($json) {
        $data = json_decode($json, true);
        if ($data && isset($data['data']) && $data['status'] === 'ok') {
            error_log("Air Quality: Données récupérées depuis WAQI");
            
            // Formater les données pour correspondre au format attendu
            $result = [
                'measurements' => []
            ];
            
            // AQI global
            if (isset($data['data']['aqi'])) {
                $result['measurements'][] = [
                    'parameter' => 'AQI',
                    'value' => (float)$data['data']['aqi'],
                    'unit' => 'AQI'
                ];
            }
            
            // Polluants individuels
            if (isset($data['data']['iaqi'])) {
                foreach ($data['data']['iaqi'] as $pollutant => $info) {
                    if (isset($info['v'])) {
                        $result['measurements'][] = [
                            'parameter' => strtoupper($pollutant),
                            'value' => (float)$info['v'],
                            'unit' => 'µg/m³'
                        ];
                    }
                }
            }
            
            if (!empty($result['measurements'])) {
                return $result;
            }
        }
    }
    
    // 2. API OpenAQ (fallback)
    $url2 = "https://api.openaq.org/v2/latest?coordinates=$lat,$lon&radius=50000&limit=1";
    
    $json2 = getUrlContent($url2);
    if ($json2) {
        $data2 = json_decode($json2, true);
        if ($data2 && isset($data2['results'][0])) {
            error_log("Air Quality: Données récupérées depuis OpenAQ");
            return $data2['results'][0];
        }
    }
    
    // 3. Données fictives pour Nancy (pour les tests)
    error_log("Air Quality: Utilisation de données par défaut pour Nancy");
    return [
        'measurements' => [
            [
                'parameter' => 'AQI',
                'value' => 45,
                'unit' => 'AQI'
            ],
            [
                'parameter' => 'PM2.5',
                'value' => 12.5,
                'unit' => 'µg/m³'
            ],
            [
                'parameter' => 'PM10',
                'value' => 23.8,
                'unit' => 'µg/m³'
            ],
            [
                'parameter' => 'NO2',
                'value' => 18.2,
                'unit' => 'µg/m³'
            ],
            [
                'parameter' => 'O3',
                'value' => 35.6,
                'unit' => 'µg/m³'
            ]
        ],
        'location' => 'Nancy, France',
        'city' => 'Nancy'
    ];
}

/**
 * Géocode une adresse en coordonnées
 */
function geocodeAddress($address) {
    // API Nominatim (OpenStreetMap) - Requiert un User-Agent
    $url = "https://nominatim.openstreetmap.org/search?q=" . urlencode($address) . "&format=json&limit=1";
    
    $json = getUrlContent($url);
    if ($json) {
        $data = json_decode($json, true);
        if ($data && count($data) > 0) {
            return [
                'lat' => (float)$data[0]['lat'],
                'lon' => (float)$data[0]['lon'],
                'display_name' => $data[0]['display_name']
            ];
        }
    }
    
    return null;
}

// ==================== RÉCUPÉRATION DES DONNÉES ====================

try {
    $clientIP = getClientIP();
    $geolocation = geolocateIP($clientIP);
    
    // Récupération avec gestion d'erreurs
    $weatherData = @getWeatherData($geolocation['lat'], $geolocation['lon']);
    $weatherXML = $weatherData ? createWeatherXML($weatherData) : null;
    
    $trafficData = @getTrafficData($geolocation['lat'], $geolocation['lon']);
    if (!is_array($trafficData)) $trafficData = [];
    
    $srasData = @getSRASData();
    if (!is_array($srasData)) $srasData = [];
    
    $airQuality = @getAirQuality($geolocation['lat'], $geolocation['lon']);
    
} catch (Exception $e) {
    error_log("ERREUR CRITIQUE: " . $e->getMessage());
    // Valeurs par défaut pour continuer
    $clientIP = '127.0.0.1';
    $geolocation = ['lat' => 48.688, 'lon' => 6.172, 'city' => 'Nancy', 'region' => 'Grand Est', 'country' => 'France', 'zip' => '54000', 'timezone' => 'Europe/Paris'];
    $weatherData = null;
    $weatherXML = null;
    $trafficData = [];
    $srasData = [];
    $airQuality = null;
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atmosphere - Décider d'utiliser sa voiture</title>
    <link rel="stylesheet" href="style.css">
    
    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body>
    <div class="container">
        <header>
            <h1>🌍 ATMOSPHERE</h1>
            <p class="subtitle">Décidez intelligemment d'utiliser votre voiture</p>
            
            <div class="location-info">
                <div class="location-item">
                    <strong>📍 Localisation:</strong>
                    <span><?php echo htmlspecialchars($geolocation['city'] . ', ' . $geolocation['region']); ?></span>
                </div>
                <div class="location-item">
                    <strong>🌐 IP:</strong>
                    <span><?php echo htmlspecialchars($clientIP); ?></span>
                </div>
                <div class="location-item">
                    <strong>📅 Date:</strong>
                    <span><?php echo date('d/m/Y H:i'); ?></span>
                </div>
                <div class="location-item">
                    <strong>🗺️ Coordonnées:</strong>
                    <span><?php echo round($geolocation['lat'], 4) . ', ' . round($geolocation['lon'], 4); ?></span>
                </div>
            </div>
        </header>

        <!-- ==================== SECTION 1: MÉTÉO ==================== -->
        <section class="section">
            <?php
            if ($weatherXML) {
                $meteoHTML = transformWithXSL($weatherXML, 'meteo.xsl');
                echo $meteoHTML;
            } else {
                echo "<div class='error'>⚠️ Impossible de récupérer les données météo</div>";
            }
            ?>
        </section>

        <!-- ==================== SECTION 2: CIRCULATION ==================== -->
        <section class="section">
            <h2>🚗 État du trafic dans le Grand Nancy</h2>
            <div id="map"></div>
            
            <?php if (empty($trafficData)): ?>
                <div class="info">ℹ️ Aucune difficulté de circulation signalée actuellement dans la zone</div>
            <?php endif; ?>
        </section>

        <!-- ==================== SECTION 3: COVID/SRAS ==================== -->
        <section class="section">
    <h2>🦠 Surveillance du SRAS-CoV-2 dans les eaux usées</h2>
    
    <?php if (!empty($srasData)): ?>
        <?php
        // Récupérer la dernière mesure
        $lastMeasure = end($srasData);
        $value = $lastMeasure['value'] ?? 0;
        $date = isset($lastMeasure['date']) ? 
            date('d/m/Y', strtotime($lastMeasure['date'])) : 'N/A';
        $station = $lastMeasure['station'] ?? 'Maxéville';
        
        // Calculer la tendance
        $trend = null;
        if (count($srasData) >= 2) {
            $current = end($srasData)['value'];
            $previous = $srasData[count($srasData) - 2]['value'];
            
            $diff = (($current - $previous) / $previous) * 100;
            
            if ($diff > 5) {
                $trend = 'up';
                $trendText = '⬆️ En hausse';
            } elseif ($diff < -5) {
                $trend = 'down';
                $trendText = '⬇️ En baisse';
            } else {
                $trend = 'stable';
                $trendText = '➡️ Stable';
            }
        } else {
            $trendText = '➡️ Données insuffisantes';
        }
        ?>
        
        <div class="covid-container">
            <div class="covid-card">
                <h3>📊 Dernière mesure</h3>
                <div class="covid-value">
                    <?php echo round($value, 2); ?>%
                </div>
                <p>Taux de positivité PCR</p>
                <small><?php echo $date; ?></small>
            </div>
            
            <div class="covid-card">
                <h3>📍 Station</h3>
                <div class="covid-value">🏭</div>
                <p><?php echo htmlspecialchars($station); ?></p>
                <small>Grand Nancy</small>
            </div>
            
            <div class="covid-card">
                <h3>📈 Tendance</h3>
                <div class="covid-trend <?php echo $trend === 'up' ? 'trend-up' : ($trend === 'down' ? 'trend-down' : ''); ?>">
                    <?php echo $trendText; ?>
                </div>
                <p>Par rapport à la semaine précédente</p>
            </div>
            
            <div class="covid-card">
                <h3>📅 Données</h3>
                <div class="covid-value"><?php echo count($srasData); ?></div>
                <p>Mesures disponibles</p>
                <small>Mise à jour hebdomadaire</small>
            </div>
        </div>
        
        <div class="chart-container">
            <canvas id="covidChart"></canvas>
        </div>
        
        <div class="info">
            ℹ️ <strong>À propos :</strong> La surveillance du SRAS-CoV-2 dans les eaux usées 
            est un indicateur précoce de l'évolution de l'épidémie. Les mesures sont effectuées à 
            <?php echo htmlspecialchars($station); ?> et permettent de détecter la circulation 
            du virus avant l'apparition de symptômes. Données mises à jour hebdomadairement.
        </div>
    
    <?php else: ?>
        <div class="error">
            ⚠️ Impossible de récupérer les données de surveillance du SRAS-CoV-2.
        </div>
        <div class="info">
            ℹ️ Consultez les logs PHP pour plus de détails sur l'erreur.
        </div>
    <?php endif; ?>
</section>

        <!-- ==================== SECTION 4: QUALITÉ DE L'AIR ==================== -->
        <section class="section">
            <h2>💨 Qualité de l'air</h2>
            
            <div class="air-quality">
                <?php if ($airQuality): ?>
                    <div class="air-quality-badge aqi-good">
                        <div class="index">
                            <?php echo isset($airQuality['measurements'][0]['value']) ? 
                                round($airQuality['measurements'][0]['value']) : 'N/A'; ?>
                        </div>
                        <div class="label">
                            <?php echo isset($airQuality['measurements'][0]['parameter']) ? 
                                strtoupper($airQuality['measurements'][0]['parameter']) : 'AQI'; ?>
                        </div>
                    </div>
                    
                    <div class="air-quality-details">
                        <h3>Détails des polluants</h3>
                        <?php 
                        if (isset($airQuality['measurements'])) {
                            foreach ($airQuality['measurements'] as $measurement) {
                                echo "<div class='pollutant'>";
                                echo "<strong>" . strtoupper($measurement['parameter']) . "</strong>";
                                echo "<span>" . round($measurement['value'], 2) . " " . $measurement['unit'] . "</span>";
                                echo "</div>";
                            }
                        }
                        ?>
                    </div>
                <?php else: ?>
                    <div class="info">ℹ️ Données de qualité de l'air non disponibles pour cette localisation</div>
                <?php endif; ?>
            </div>
        </section>

        <!-- ==================== RECOMMANDATION FINALE ==================== -->
        <?php
        // Calculer une recommandation basée sur tous les critères
        $score = 0;
        $reasons = [];
        
        // Météo (à améliorer avec analyse du XML)
        if ($weatherData) {
            $score += 2;
        }
        
        // Trafic
        if (count($trafficData) > 5) {
            $score -= 2;
            $reasons[] = "Nombreuses difficultés de circulation";
        } else {
            $score += 1;
        }
        
        // Qualité de l'air
        if ($airQuality && isset($airQuality['measurements'][0]['value'])) {
            $aqi = $airQuality['measurements'][0]['value'];
            if ($aqi > 100) {
                $score -= 2;
                $reasons[] = "Qualité de l'air médiocre";
            } else {
                $score += 1;
            }
        }
        
        $useCarRecommendation = $score > 0;
        ?>
        
        <div class="recommendation <?php echo $useCarRecommendation ? '' : 'negative'; ?>">
            <h2><?php echo $useCarRecommendation ? '✅ Conditions favorables' : '⚠️ Conditions défavorables'; ?></h2>
            <div class="icon"><?php echo $useCarRecommendation ? '🚗' : '🚌'; ?></div>
            <p>
                <?php 
                if ($useCarRecommendation) {
                    echo "Les conditions actuelles sont favorables à l'utilisation de votre véhicule.";
                } else {
                    echo "Nous vous recommandons d'utiliser les transports en commun aujourd'hui.";
                }
                ?>
            </p>
            <?php if (!empty($reasons)): ?>
                <p style="margin-top: 15px; font-size: 1.1em;">
                    <?php echo implode(' • ', $reasons); ?>
                </p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    
    <script>
        // ==================== CARTE LEAFLET ====================
    const map = L.map('map').setView([<?php echo $geolocation['lat']; ?>, <?php echo $geolocation['lon']; ?>], 12);
    
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors',
        maxZoom: 19
    }).addTo(map);
    
    // Marqueur position utilisateur
    L.marker([<?php echo $geolocation['lat']; ?>, <?php echo $geolocation['lon']; ?>], {
        icon: L.divIcon({
            className: 'custom-marker',
            html: '<div style="background: #3498db; width: 30px; height: 30px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 5px rgba(0,0,0,0.3);"></div>',
            iconSize: [30, 30]
        })
    }).addTo(map).bindPopup('<b>📍 Votre position</b><br><?php echo htmlspecialchars($geolocation['city']); ?>');
    
    // Marqueurs difficultés circulation
    <?php foreach ($trafficData as $traffic): ?>
    <?php 
    // Adapter selon la structure de l'API Grand Nancy
    $lat = null;
    $lon = null;
    $description = 'Incident de circulation';
    $type = 'Inconnu';
    
    // Structure API Grand Nancy (CIFS)
    if (isset($traffic['location']['polyline'])) {
        // Format: "48.70400769279893 6.136025780296651"
        $coords = explode(' ', trim($traffic['location']['polyline']));
        if (count($coords) == 2) {
            $lat = (float)$coords[0];
            $lon = (float)$coords[1];
        }
        $description = $traffic['description'] ?? $traffic['short_description'] ?? 'Incident';
        $type = $traffic['type'] ?? 'Travaux';
        $street = $traffic['location']['street'] ?? '';
        $locationDesc = $traffic['location']['location_description'] ?? '';
    }
    // Structure GeoJSON (features) - fallback
    elseif (isset($traffic['geometry']['coordinates'])) {
        $lon = $traffic['geometry']['coordinates'][0];
        $lat = $traffic['geometry']['coordinates'][1];
        $description = $traffic['properties']['description'] ?? $traffic['properties']['libelle'] ?? 'Incident';
        $type = $traffic['properties']['type'] ?? 'Incident';
        $street = '';
        $locationDesc = '';
    }
    // Structure data.gouv.fr (records) - fallback
    elseif (isset($traffic['fields'])) {
        if (isset($traffic['fields']['geo_point_2d'])) {
            $lat = $traffic['fields']['geo_point_2d'][0];
            $lon = $traffic['fields']['geo_point_2d'][1];
        } elseif (isset($traffic['fields']['coordonnees'])) {
            $coords = explode(',', $traffic['fields']['coordonnees']);
            $lat = floatval($coords[0]);
            $lon = floatval($coords[1]);
        }
        $description = $traffic['fields']['libelle'] ?? $traffic['fields']['description'] ?? 'Incident';
        $type = $traffic['fields']['type'] ?? 'Incident';
        $street = '';
        $locationDesc = '';
    }
    // Structure simple avec lat/lon - fallback
    elseif (isset($traffic['lat']) && isset($traffic['lon'])) {
        $lat = $traffic['lat'];
        $lon = $traffic['lon'];
        $description = $traffic['description'] ?? $traffic['libelle'] ?? 'Incident';
        $type = $traffic['type'] ?? 'Incident';
        $street = '';
        $locationDesc = '';
    }
    
    // Si on a des coordonnées valides
    if ($lat && $lon):
        // Choisir une couleur selon le type
        $color = '#e74c3c'; // rouge par défaut
        if (strpos(strtolower($type), 'construction') !== false || 
            strpos(strtolower($type), 'travaux') !== false) {
            $color = '#f39c12'; // orange pour travaux
        }
        
        // Icône selon le type
        $icon = '🚧';
        if (strpos(strtolower($description), 'eau') !== false || 
            strpos(strtolower($description), 'assainissement') !== false) {
            $icon = '💧';
        } elseif (strpos(strtolower($description), 'chauffage') !== false) {
            $icon = '🔥';
        }
    ?>
    L.marker([<?php echo $lat; ?>, <?php echo $lon; ?>], {
        icon: L.divIcon({
            className: 'custom-marker',
            html: '<div style="background: <?php echo $color; ?>; width: 25px; height: 25px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 5px rgba(0,0,0,0.3);"></div>',
            iconSize: [25, 25]
        })
    }).addTo(map).bindPopup(`
        <div style="max-width: 300px;">
            <b><?php echo $icon; ?> <?php echo htmlspecialchars($type); ?></b><br>
            <?php if (!empty($street)): ?>
                <strong>📍 <?php echo htmlspecialchars($street); ?></strong><br>
            <?php endif; ?>
            <?php if (!empty($locationDesc)): ?>
                <em><?php echo htmlspecialchars($locationDesc); ?></em><br>
            <?php endif; ?>
            <br>
            <?php echo htmlspecialchars($description); ?><br>
            <small style="color: #666;">Coordonnées: <?php echo round($lat, 4); ?>, <?php echo round($lon, 4); ?></small>
        </div>
    `);
    <?php endif; ?>
    <?php endforeach; ?>
        
        // ==================== GRAPHIQUE COVID/SRAS ====================
        <?php if (!empty($srasData)): ?>
const ctx = document.getElementById('covidChart').getContext('2d');
const covidChart = new Chart(ctx, {
    type: 'line',
    data: {
        labels: [
            <?php 
            foreach ($srasData as $item) {
                echo "'" . date('d/m', strtotime($item['date'])) . "',";
            }
            ?>
        ],
        datasets: [{
            label: 'Taux PCR positif (%)',
            data: [
                <?php 
                foreach ($srasData as $item) {
                    echo $item['value'] . ',';
                }
                ?>
            ],
            borderColor: '#3498db',
            backgroundColor: 'rgba(52, 152, 219, 0.1)',
            tension: 0.4,
            fill: true,
            pointRadius: 4,
            pointHoverRadius: 6,
            pointBackgroundColor: '#3498db',
            pointBorderColor: '#fff',
            pointBorderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            title: {
                display: true,
                text: 'Évolution du SRAS-CoV-2 dans les eaux usées - <?php echo htmlspecialchars($station ?? "Maxéville"); ?>',
                font: {
                    size: 16
                }
            },
            legend: {
                display: true,
                position: 'bottom'
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return 'Taux PCR: ' + context.parsed.y.toFixed(2) + '%';
                    }
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                title: {
                    display: true,
                    text: 'Taux de positivité PCR (%)'
                },
                ticks: {
                    callback: function(value) {
                        return value.toFixed(1) + '%';
                    }
                }
            },
            x: {
                title: {
                    display: true,
                    text: 'Date de prélèvement'
                }
            }
        }
    }
});
<?php endif; ?>
    </script>
</body>
</html>