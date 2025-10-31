<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

# --- Eingabe prüfen: https?:// (Proxy) ODER gemini://<prompt> (Generierung) ---
if (!isset($_GET['url'])) {
    header('HTTP/1.1 404 Not Found'); exit;
}
$rawUrl = $_GET['url'];
$isHttps = (preg_match('#^https?://#i', $rawUrl) === 1);
$isGemini = (preg_match('#^gemini://#i', $rawUrl) === 1);

if (!$isHttps && !$isGemini) {
    header('HTTP/1.1 404 Not Found'); exit;
}

# --- .env laden (KEY=VALUE pro Zeile) ---
$envPath = __DIR__ . '/.env';
$env = [];
if (is_readable($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === ';') continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $val = trim($parts[1]);
            if (strlen($val) >= 2 && (($val[0] === '"' && substr($val, -1) === '"') || ($val[0] === "'" && substr($val, -1) === "'"))) {
                $val = substr($val, 1, -1);
            }
            $env[$key] = $val;
        }
    }
}

# --- Branch: GEMINI Bildgenerierung ---
if ($isGemini) {
    $apiKey = isset($env['GEMINI_API_KEY']) ? $env['GEMINI_API_KEY'] : getenv('GEMINI_API_KEY');
    if (empty($apiKey)) { header('HTTP/1.1 500 Internal Server Error'); echo 'Missing GEMINI_API_KEY'; exit; }

    # Prompt aus gemini://... extrahieren (URL-encodiertes Query-Value wird hier nochmals decodiert)
    $prompt = preg_replace('#^gemini://#i', '', $rawUrl);
    $prompt = urldecode($prompt);

    # Optional: Pose-Bild laden (lokale Datei oder entfernte URL) und Base64 enkodieren
    $poseUrl = isset($_GET['pose']) ? trim($_GET['pose']) : '';
    $poseB64 = null;
    $poseMime = 'image/png';
    if ($poseUrl !== '') {
        $poseData = null;
        if (preg_match('#^https?://#i', $poseUrl)) {
            // Remote holen
            $chPose = curl_init($poseUrl);
            curl_setopt_array($chPose, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10,
            ]);
            $poseData = curl_exec($chPose);
            if ($poseData !== false) {
                $ct = curl_getinfo($chPose, CURLINFO_CONTENT_TYPE);
                if (is_string($ct) && $ct !== '') { $poseMime = $ct; }
            }
            curl_close($chPose);
        } else {
            // Lokale Datei relativ zum Projektverzeichnis
            $localPath = $poseUrl;
            if (strpos($localPath, '/') !== 0) {
                $localPath = __DIR__ . '/' . $localPath;
            }
            if (is_readable($localPath)) {
                $poseData = @file_get_contents($localPath);
                $detected = @mime_content_type($localPath);
                if ($detected) { $poseMime = $detected; }
            }
        }
        if ($poseData !== null && $poseData !== false) {
            $poseB64 = base64_encode($poseData);
        }
    }

    # Disk-Cache in img/gemini anhand des Prompt-Hashes
    $cacheDir = __DIR__ . '/img/gemini';
    if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0777, true); }
    $hashSource = $prompt . '|' . ($poseUrl ?: '');
    $hash = sha1($hashSource);
    $cacheFile = $cacheDir . '/gemini-' . $hash . '.png';

    if (is_readable($cacheFile)) {
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=31536000, immutable');
        header('Content-Length: ' . filesize($cacheFile));
        readfile($cacheFile);
        exit;
    }

    $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent';

    // Prompt nur präfixen, wenn eine Pose übergeben wurde
    $prefixedPrompt = ($poseUrl !== '') ? ('Using the provided image of a man in a suit, ' . $prompt . '.The background has to be pink.') : $prompt;

    $parts = [ [ 'text' => $prefixedPrompt ] ];
    if ($poseB64) {
        $parts[] = [
            'inline_data' => [
                'mime_type' => $poseMime,
                'data' => $poseB64
            ]
        ];
    }

    $payload = json_encode([
        'contents' => [[ 'parts' => $parts ]],
        'generationConfig' => [ 'imageConfig' => [ 'aspectRatio' => '16:9' ] ]
    ]);

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER      => [
            'x-goog-api-key: ' . $apiKey,
            'Content-Type: application/json'
        ],
        CURLOPT_POST            => true,
        CURLOPT_POSTFIELDS      => $payload,
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_TIMEOUT         => 60
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        header('HTTP/1.1 502 Bad Gateway'); echo 'cURL error: ' . curl_error($ch); curl_close($ch); exit;
    }
    curl_close($ch);

    $json = json_decode($resp, true);
    $b64 = null;
    if (isset($json['candidates'])) {
        foreach ($json['candidates'] as $c) {
            if (!empty($c['content']['parts'])) {
                foreach ($c['content']['parts'] as $p) {
                    if (isset($p['inlineData']['data'])) { $b64 = $p['inlineData']['data']; break 2; }
                }
            }
        }
    }
    if (!$b64) { header('HTTP/1.1 502 Bad Gateway'); echo 'No image data from Gemini'; exit; }

    $bin = base64_decode($b64, true);
    if ($bin === false) { header('HTTP/1.1 502 Bad Gateway'); echo 'Invalid base64'; exit; }

    # In Cache schreiben und als PNG ausliefern
    @file_put_contents($cacheFile, $bin);
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=31536000, immutable');
    header('Content-Length: ' . strlen($bin));
    echo $bin;
    exit;
}

# --- Branch: HTTPS Proxy (z. B. Unsplash) ---
$url = $rawUrl;

# Unsplash client_id anhängen, falls vorhanden
$unsplashClientId = isset($env['UNSPLASH_CLIENT_ID']) ? $env['UNSPLASH_CLIENT_ID'] : getenv('UNSPLASH_CLIENT_ID');
if (!empty($unsplashClientId)) {
    $url .= (strpos($url, '?') !== false ? '&' : '?') . 'client_id=' . urlencode($unsplashClientId);
}

# Client hat bereits Item? (nur für Proxy sinnvoll)
if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) or isset($_SERVER['HTTP_IF_NONE_MATCH'])) {
    header('HTTP/1.1 304 Not Modified'); exit;
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
curl_setopt($ch, CURLOPT_BUFFERSIZE, 12800);
curl_setopt($ch, CURLOPT_NOPROGRESS, false);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/96.0.4664.110 Safari/537.36');
curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function($DownloadSize, $Downloaded, $UploadSize, $Uploaded) { return ($Downloaded > 1024 * 4096) ? 1 : 0; } ); # max 4096kb

$version = curl_version();
if ($version !== false && ($version['features'] & CURL_VERSION_SSL)) {
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
}

$response = curl_exec($ch);
if ($response === false) { header('HTTP/1.1 502 Bad Gateway'); echo 'cURL error: ' . curl_error($ch); curl_close($ch); exit; }
$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
curl_close($ch);

$header_blocks = array_filter(preg_split('#\n\s*\n#Uis', substr($response, 0, $header_size)));
$header_array  = explode("\n", array_pop($header_blocks));
$body          = substr($response, $header_size);

$headers = [];
foreach ($header_array as $header_value) {
    $header_pieces = explode(':', $header_value);
    if (count($header_pieces) == 2) {
        $headers[strtolower($header_pieces[0])] = trim($header_pieces[1]);
    }
}

if (array_key_exists('content-type', $headers)) {
    $ct = $headers['content-type'];
    if (preg_match('#image/png|image/.*icon|image/jpe?g|image/gif|image/webp|image/svg\+xml#i', $ct) !== 1) {
        header('HTTP/1.1 404 Not Found'); exit;
    }
    header('Content-Type: ' . $ct);
} else {
    header('HTTP/1.1 404 Not Found'); exit;
}

if (array_key_exists('content-length', $headers))
    header('Content-Length: ' . $headers['content-length']);
if (array_key_exists('expires', $headers))
    header('Expires: ' . $headers['expires']);
if (array_key_exists('cache-control', $headers))
    header('Cache-Control: ' . $headers['cache-control']);
if (array_key_exists('last-modified', $headers))
    header('Last-Modified: ' . $headers['last-modified']);

echo $body;
exit;

?>
