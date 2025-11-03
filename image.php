<?php

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);

# --- Helpers ---
function ensure_dir($dir) {
    if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
}

function slugify($text, $maxLen = 80) {
    $text = strtolower($text);
    $text = preg_replace('/[^a-z0-9\-_. ]+/i', '-', $text);
    $text = preg_replace('/\s+/', '-', $text);
    $text = preg_replace('/-+/', '-', $text);
    $text = trim($text, '-._');
    if (strlen($text) > $maxLen) {
        $text = substr($text, 0, $maxLen);
        $text = rtrim($text, '-._');
    }
    return $text === '' ? 'image' : $text;
}

function write_png_thumbnail($binary, $targetPath, $maxWidth = 256) {
    if (!function_exists('imagecreatefromstring')) return;
    $src = @imagecreatefromstring($binary);
    if ($src === false) return;
    $w = imagesx($src); $h = imagesy($src);
    if ($w <= 0 || $h <= 0) { imagedestroy($src); return; }
    $tw = min($maxWidth, $w);
    $th = (int)round($tw * $h / $w);
    $dst = imagecreatetruecolor($tw, $th);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
    @imagepng($dst, $targetPath);
    imagedestroy($dst);
    imagedestroy($src);
}

/**
 * Emit a PNG image that visually shows an error message so the frontend can display it.
 * This avoids broken images and surfaces backend issues directly on the canvas.
 */
function output_error_image($message, $statusCode = 500) {
    // Fallback if GD is unavailable: send plain text with image content-type
    $safeMsg = trim((string)$message);
    if (!function_exists('imagecreatetruecolor')) {
        // Always respond 200 to avoid proxy/CDN interferences (Cloudflare, etc.)
        http_response_code(200);
        header('Content-Type: image/png');
        header('Cache-Control: no-store, max-age=0');
        header('X-App-Error: 1');
        header('X-App-Error-Status: ' . (int)$statusCode);
        header('X-App-Error-Message: ' . substr(preg_replace('/\s+/', ' ', $safeMsg), 0, 512));
        echo $safeMsg; // best effort
        exit;
    }

    // Canvas size and colors (match app theme: black bg, yellow text)
    $w = 1024; $h = 512;
    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, true);
    imagesavealpha($im, true);
    $bg = imagecolorallocate($im, 0, 0, 0);
    $fg = imagecolorallocate($im, 255, 192, 0);
    $muted = imagecolorallocate($im, 160, 160, 160);
    imagefilledrectangle($im, 0, 0, $w, $h, $bg);

    // Word-wrap to readable lines
    $title = 'Error';
    $body = $safeMsg;
    $lines = [];
    $titleWrap = wordwrap($title, 70, "\n");
    $bodyWrap = wordwrap($body, 70, "\n");
    $lines = array_merge(explode("\n", $titleWrap), [''], explode("\n", $bodyWrap));

    // Draw text using built-in font
    $x = 24; $y = 24; $lineH = 18;
    imagestring($im, 5, $x, $y, 'Image Service', $muted); $y += $lineH + 6;
    foreach ($lines as $idx => $ln) {
        $color = ($idx === 0) ? $fg : $fg;
        imagestring($im, ($idx === 0 ? 5 : 3), $x, $y, $ln, $color);
        $y += ($idx === 0 ? $lineH + 4 : $lineH);
        if ($y > $h - 24) { break; }
    }

    // Output PNG (always 200 OK). Attach app-specific error headers for debugging.
    http_response_code(200);
    header('Content-Type: image/png');
    header('Cache-Control: no-store, max-age=0');
    header('X-App-Error: 1');
    header('X-App-Error-Status: ' . (int)$statusCode);
    header('X-App-Error-Message: ' . substr(preg_replace('/\s+/', ' ', $safeMsg), 0, 512));
    ob_clean();
    imagepng($im);
    imagedestroy($im);
    exit;
}

/** Read parameter from POST first, then GET */
function get_param($key, $default = null) {
    if (isset($_POST[$key]) && $_POST[$key] !== '') { return $_POST[$key]; }
    if (isset($_GET[$key])) { return $_GET[$key]; }
    return $default;
}

/** Parse data URI: data:mime;base64,.... → [bytes, mime] */
function parse_data_uri($str) {
    if (!is_string($str)) return [null, null];
    if (preg_match('#^data:([^;,]+);base64,(.+)$#is', $str, $m)) {
        $mime = trim($m[1]);
        $b64 = trim($m[2]);
        $bin = base64_decode($b64, true);
        if ($bin !== false) { return [$bin, ($mime ?: 'image/png')]; }
    }
    return [null, null];
}

/**
 * Fetch bytes and detected mime type from a source that can be:
 * - http(s) URL
 * - internal image.php URL (relative path)
 * - local filesystem path (relative to project root)
 */
function fetch_bytes_and_mime($url, $defaultMime = 'image/png') {
    $data = null;
    $mime = $defaultMime;
    if (!is_string($url) || $url === '') { return [null, $mime]; }

    if (preg_match('#^https?://#i', $url)) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8'
            ],
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        ]);
        $data = curl_exec($ch);
        if ($data !== false) {
            $ct = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            if (is_string($ct) && $ct !== '') { $mime = $ct; }
        }
        curl_close($ch);
        return [$data, $mime];
    }

    // Internal image.php URL (relative)
    if (preg_match('#^(?:\./)?image\.php\?#i', $url) || preg_match('#^image\.php\?#i', $url)) {
        // Respect subdirectory deployments (e.g., /yout-thu/) when constructing absolute URLs
        $scheme = 'http';
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) { $scheme = $_SERVER['HTTP_X_FORWARDED_PROTO']; }
        else if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') { $scheme = 'https'; }
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '127.0.0.1';
        $scriptName = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/image.php';
        $basePath = rtrim(str_replace('\\','/', dirname($scriptName)), '/'); // e.g., /yout-thu
        $rel = ltrim(preg_replace('#^\./#', '', $url), '/'); // image.php?...
        $absolute = $scheme . '://' . $host . ($basePath ? $basePath : '') . '/' . $rel;
        $forwardKey = trim((string)($_SERVER['HTTP_X_GEMINI_API_KEY'] ?? ''));
        $ch = curl_init($absolute);
        $hdrs = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [ 'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8' ],
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        ];
        if ($forwardKey !== '') {
            $headers = [ 'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8', 'X-Gemini-API-Key: ' . $forwardKey ];
            $hdrs[CURLOPT_HTTPHEADER] = $headers;
        }
        curl_setopt_array($ch, $hdrs);
        $data = curl_exec($ch);
        if ($data !== false) {
            $ct = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            if (is_string($ct) && $ct !== '') { $mime = $ct; }
        }
        curl_close($ch);
        return [$data, $mime];
    }

    // Local file path (supports both relative like img/.. and absolute web paths like /yout-thu/img/...)
    $localPath = $url;
    if (strpos($localPath, '/') !== 0) {
        // relative web path → filesystem path under this script directory
        $localPath = __DIR__ . '/' . $localPath;
    } else {
        // absolute web path → map from web base (/subdir) to filesystem (this dir)
        $scriptName = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/image.php';
        $basePath = rtrim(str_replace('\\','/', dirname($scriptName)), '/'); // e.g., /yout-thu
        if ($basePath !== '' && strpos($localPath, $basePath . '/') === 0) {
            $relative = ltrim(substr($localPath, strlen($basePath)), '/');
            $localPath = __DIR__ . '/' . $relative;
        }
    }
    if (is_readable($localPath)) {
        $data = @file_get_contents($localPath);
        $detected = @mime_content_type($localPath);
        if ($detected) { $mime = $detected; }
    }
    return [$data, $mime];
}

# --- Eingabe prüfen: https?:// (Proxy) ODER gemini://<prompt> (Generierung) ---
$rawUrl = get_param('url', '');
if (!is_string($rawUrl) || $rawUrl === '') {
    output_error_image('Missing required parameter: url', 400);
}
$isHttps = (preg_match('#^https?://#i', $rawUrl) === 1);
$isGemini = (preg_match('#^gemini://#i', $rawUrl) === 1);

if (!$isHttps && !$isGemini) {
    output_error_image('Invalid url scheme. Expected https:// or gemini://', 400);
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
    $envKey = isset($env['GEMINI_API_KEY']) ? $env['GEMINI_API_KEY'] : getenv('GEMINI_API_KEY');
    $headerKey = trim((string)($_SERVER['HTTP_X_GEMINI_API_KEY'] ?? ''));
    $action = get_param('action', '');
    if ($action === 'has_key') {
        header('Content-Type: application/json');
        header('Cache-Control: no-store, max-age=0');
        echo json_encode([ 'hasKey' => !empty($envKey) ]);
        exit;
    }
    $apiKey = ($headerKey !== '') ? $headerKey : $envKey;
    if (empty($apiKey)) { output_error_image('Missing GEMINI_API_KEY. Define it in .env or environment.', 500); }

    # Prompt aus gemini://... extrahieren (URL-encodiertes Query-Value wird hier nochmals decodiert)
    $prompt = preg_replace('#^gemini://#i', '', $rawUrl);
    $prompt = urldecode($prompt);

    # Optional: Pose-Bild laden (lokale Datei oder entfernte URL) und Base64 enkodieren (nur für Pose-Generierung)
    $poseUrl = trim((string)get_param('pose', ''));
    $poseB64 = null;
    $poseMime = 'image/png';
    if ($poseUrl !== '') {
        list($poseData, $detMime) = fetch_bytes_and_mime($poseUrl, $poseMime);
        if ($detMime) { $poseMime = $detMime; }
        if ($poseData !== null && $poseData !== false) {
            $poseB64 = base64_encode($poseData);
        }
    }

    # Optionale Referenz-Bilder (für Background-Generierung): Reihenfolge: Background, Pose, Second, Third
    $refKeys = ['ref_background', 'ref_pose', 'ref_second', 'ref_third'];
    $refInlineParts = [];
    foreach ($refKeys as $rk) {
        $val = trim((string)get_param($rk, ''));
        $dataParam = get_param($rk . '_data', '');
        $mimeParam = trim((string)get_param($rk . '_mime', ''));

        $bytes = null; $mime = 'image/png';
        if (is_string($dataParam) && $dataParam !== '') {
            // Raw base64 provided via *_data (no data: prefix)
            $bytes = base64_decode($dataParam, true);
            if ($bytes === false) { $bytes = null; }
            $mime = $mimeParam ?: 'image/png';
        } else if ($val !== '') {
            // data: URI support
            if (stripos($val, 'data:') === 0) {
                list($bytes, $mime) = parse_data_uri($val);
            } else {
                list($bytes, $mime) = fetch_bytes_and_mime($val, 'image/png');
            }
        }
        if ($bytes !== null && $bytes !== false) {
            if (!preg_match('#^image/#i', (string)$mime)) {
                output_error_image('Reference "' . $rk . '" is not an image (Content-Type: ' . (string)$mime . '). Check base path and URLs.', 400);
            }
            $refInlineParts[] = [
                'inline_data' => [
                    'mime_type' => $mime,
                    'data' => base64_encode($bytes)
                ]
            ];
        }
    }

    # Disk-Cache in img/gemini/<backgrounds|poses> mit Prompt im Dateinamen und Hash-Suffix
    $baseDir   = __DIR__ . '/img/gemini';
    $isPoseGen = ($poseUrl !== '');
    $assetDir  = $baseDir . '/' . ($isPoseGen ? 'poses' : 'backgrounds');
    $thumbDir  = $baseDir . '/' . ($isPoseGen ? 'poses_thumbs' : 'backgrounds_thumbs');
    ensure_dir($assetDir);
    ensure_dir($thumbDir);

    $hashParts = [$prompt, ($poseUrl ?: '')];
    foreach ($refKeys as $rk) { $hashParts[] = (string)get_param($rk, (string)get_param($rk . '_mime', '')) . '|' . (string)get_param($rk . '_data', ''); }
    $hashSource = implode('|', $hashParts);
    $hash = sha1($hashSource);
    $short = substr($hash, 0, 10);
    $slug = slugify($prompt, 80);

    # Optional: Pose-Index in Dateiname aufnehmen (z.B. pose12)
    $poseTag = '';
    if ($isPoseGen) {
        if (preg_match('#/poses/(\d+)\.png$#', $poseUrl, $m)) {
            $poseTag = 'pose' . $m[1] . '-';
        }
    }

    $filename = $poseTag . $slug . '--' . $short . '.png';
    $cacheFile = $assetDir . '/' . $filename;

    # Falls bereits vorhanden, alternativ per Glob anhand Hash suchen (Slug kann sich ändern)
    if (!is_readable($cacheFile)) {
        $matches = glob($assetDir . '/*--' . $short . '.png');
        if ($matches && count($matches) > 0) {
            $cacheFile = $matches[0];
        }
    }

    if (is_readable($cacheFile)) {
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=31536000, immutable');
        header('Content-Length: ' . filesize($cacheFile));
        readfile($cacheFile);
        exit;
    }

    $endpoint = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent';

    // Prompt nur präfixen, wenn eine Pose übergeben wurde
    $prefixedPrompt = $prompt;

    // Build parts: inline refs first (background, pose, second, third), then optional pose (pose generation), then text
    $parts = [];
    foreach ($refInlineParts as $p) { $parts[] = $p; }
    if ($poseB64) {
        $parts[] = [
            'inline_data' => [
                'mime_type' => $poseMime,
                'data' => $poseB64
            ]
        ];
    }
    $parts[] = [ 'text' => $prefixedPrompt ];

    $payload = json_encode([
        'contents' => [[ 'parts' => $parts ]],
        'generationConfig' => [ 'imageConfig' => [ 'aspectRatio' => ($isPoseGen ? '9:16' : '16:9') ] ]
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
        $err = curl_error($ch);
        error_log('Gemini request failed: ' . $err);
        curl_close($ch);
        output_error_image('Gemini request failed: ' . $err, 502);
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
    if (!$b64) {
        $hint = '';
        if (isset($json['error'])) {
            $hint = json_encode($json['error']);
        } elseif (is_string($resp)) {
            $hint = substr($resp, 0, 400);
        }
        error_log('Gemini no image data. Response hint: ' . $hint);
        output_error_image('No image data from Gemini. ' . $hint, 502);
    }

    $bin = base64_decode($b64, true);
    if ($bin === false) { output_error_image('Invalid base64 in Gemini response', 502); }

    # In Cache schreiben, Thumbnail erzeugen und als PNG ausliefern
    @file_put_contents($cacheFile, $bin);
    $thumbPath = $thumbDir . '/' . basename($cacheFile);
    write_png_thumbnail($bin, $thumbPath, 256);

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
if ($response === false) {
    $err = curl_error($ch);
    error_log('Proxy cURL error: ' . $err);
    curl_close($ch);
    output_error_image('Proxy request failed: ' . $err, 502);
}
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
        output_error_image('Upstream returned non-image content-type: ' . $ct, 404);
    }
    header('Content-Type: ' . $ct);
} else {
    output_error_image('Upstream response missing content-type header', 404);
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
