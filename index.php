<!DOCTYPE html>
<!-- v1.6 -->
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="icon" type="image/png" href="favicon.png">
<?php
/**
 * Read the video track's width/height from an mp4's 'moov'/'tkhd' boxes.
 * Walks top-level boxes to find 'moov', then each 'trak' for its 'tkhd'
 * (width/height are 16.16 fixed-point); the first non-zero pair is the
 * video track (audio tracks have zero dimensions). Returns
 * [width, height] or null if undeterminable. Core PHP only.
 */
function vcr_video_dimensions($path) {
    $fh = @fopen($path, 'rb');
    if ($fh === false) { return null; }
    $total = filesize($path);
    if ($total === false) { fclose($fh); return null; }

    // Find the top-level 'moov' box (seeking past any large 'mdat').
    $pos = 0;
    $moovStart = null;
    $moovSize = null;
    while ($pos + 8 <= $total) {
        fseek($fh, $pos);
        $hdr = fread($fh, 8);
        if (strlen($hdr) < 8) { break; }
        $boxSize = unpack('N', $hdr)[1];
        if ($boxSize < 8 || $boxSize === 1 || $boxSize === 2 || $pos + $boxSize > $total) { break; }
        if (substr($hdr, 4, 4) === 'moov') {
            $moovStart = $pos;
            $moovSize = $boxSize;
            break;
        }
        $pos += $boxSize;
    }
    if ($moovStart === null) { fclose($fh); return null; }

    // Walk 'moov' children; for each 'trak' look for 'tkhd'.
    $result = null;
    $p = $moovStart + 8;
    $end = $moovStart + $moovSize;
    while ($p + 8 <= $end) {
        fseek($fh, $p);
        $hdr = fread($fh, 8);
        if (strlen($hdr) < 8) { break; }
        $boxSize = unpack('N', $hdr)[1];
        if ($boxSize < 8 || $boxSize === 1 || $boxSize === 2 || $p + $boxSize > $end) { break; }
        if (substr($hdr, 4, 4) === 'trak') {
            $tpos = $p + 8;
            $tend = $p + $boxSize;
            while ($tpos + 8 <= $tend) {
                fseek($fh, $tpos);
                $thdr = fread($fh, 8);
                if (strlen($thdr) < 8) { break; }
                $tsize = unpack('N', $thdr)[1];
                if ($tsize < 8 || $tsize === 1 || $tsize === 2 || $tpos + $tsize > $tend) { break; }
                if (substr($thdr, 4, 4) === 'tkhd' && $tsize - 8 >= 84) {
                    // tkhd payload: v0 has width@76 height@80; v1 adds 12 bytes
                    // (64-bit timestamps) shifting them to width@88 height@92.
                    fseek($fh, $tpos + 8);
                    $payload = fread($fh, 96);
                    if (strlen($payload) >= 84) {
                        $base = ord($payload[0]) === 1 ? 12 : 0;
                        if (strlen($payload) >= 84 + $base) {
                            $woff = 76 + $base;
                            $w = unpack('N', substr($payload, $woff, 4))[1];
                            $h = unpack('N', substr($payload, $woff + 4, 4))[1];
                            if ($w > 0 && $h > 0) {
                                $result = array($w >> 16, $h >> 16);
                                break;
                            }
                        }
                    }
                }
                $tpos += $tsize;
            }
            if ($result !== null) { break; }
        }
        $p += $boxSize;
    }
    fclose($fh);
    return $result;
}

// Absolute base URL of this page, derived from the request. Used to build
// absolute Open Graph URLs (og:image, og:video).
$isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] === '443');
$host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== '' ? $_SERVER['HTTP_HOST'] : '';
// Base path comes from the URL the client used (REQUEST_URI), not the
// filesystem script path, which can include server-internal folders.
$uriPath = parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
$base = rtrim(dirname($uriPath !== null ? $uriPath : '/'), '/');
$pageUrl = ($isHttps ? 'https' : 'http') . '://' . $host . $base;
$ogImage = $pageUrl . '/og-vcr.png';

// Resolve ?v= server-side so Open Graph tags can point at the direct video
// file. Link preview services (e.g. Discord) render a native playable video
// player from these tags instead of a plain link card.
$v = isset($_GET['v']) ? (string)$_GET['v'] : '';
$videoFile = null;
$videoUrl = null;
$videoDims = null;
if ($v !== ''
    && strpos($v, '..') === false
    && strpos($v, '/') === false
    && strpos($v, '\\') === false
    && is_file(__DIR__ . '/videos/' . $v)
) {
    $videoFile = $v;
    $videoUrl = $pageUrl . '/videos/' . rawurlencode($v);
    $videoDims = vcr_video_dimensions(__DIR__ . '/videos/' . $v);
    // Per-tape still (same basename, .jpg) becomes the og:image; fall back
    // to the default poster when the still is missing.
    $stillExt = substr($v, -4) === '.mp4' ? '.jpg' : '';
    if ($stillExt !== '' && is_file(__DIR__ . '/videos/' . substr($v, 0, -4) . $stillExt)) {
        $ogImage = $pageUrl . '/videos/' . rawurlencode(substr($v, 0, -4) . $stillExt);
    }
}
?>
<title><?php echo $videoFile !== null ? htmlspecialchars($videoFile . ' - HE1T VCR', ENT_QUOTES) : 'HE1T VCR'; ?></title>
<?php if ($videoFile !== null): ?>
<meta property="og:type" content="video.other">
<meta property="og:site_name" content="Insert Tape">
<meta property="og:title" content="<?php echo htmlspecialchars($videoFile, ENT_QUOTES); ?>">
<meta property="og:description" content="Be Kind, Please Rewind">
<meta property="og:image" content="<?php echo htmlspecialchars($ogImage, ENT_QUOTES); ?>">
<?php if ($videoDims !== null): ?>
<meta property="og:image:width" content="<?php echo (int)$videoDims[0]; ?>">
<meta property="og:image:height" content="<?php echo (int)$videoDims[1]; ?>">
<?php else: ?>
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<?php endif; ?>
<meta property="og:video" content="<?php echo htmlspecialchars($videoUrl, ENT_QUOTES); ?>">
<meta property="og:video:url" content="<?php echo htmlspecialchars($videoUrl, ENT_QUOTES); ?>">
<meta property="og:video:secure_url" content="<?php echo htmlspecialchars($videoUrl, ENT_QUOTES); ?>">
<meta property="og:video:type" content="video/mp4">
<?php if ($videoDims !== null): ?>
<meta property="og:video:width" content="<?php echo (int)$videoDims[0]; ?>">
<meta property="og:video:height" content="<?php echo (int)$videoDims[1]; ?>">
<?php endif; ?>
<meta property="twitter:card" content="player">
<meta property="twitter:player:stream" content="<?php echo htmlspecialchars($videoUrl, ENT_QUOTES); ?>">
<meta property="twitter:player:stream:content_type" content="video/mp4">
<?php if ($videoDims !== null): ?>
<meta property="twitter:player:width" content="<?php echo (int)$videoDims[0]; ?>">
<meta property="twitter:player:height" content="<?php echo (int)$videoDims[1]; ?>">
<?php endif; ?>
<?php else: ?>
<meta property="og:type" content="website">
<meta property="og:site_name" content="Insert Tape">
<meta property="og:title" content="HE1T VCR">
<meta property="og:description" content="Be Kind, Please Rewind">
<meta property="og:image" content="<?php echo htmlspecialchars($ogImage, ENT_QUOTES); ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<?php endif; ?>
<style>
@layer tokens, base, layout, components, animations;

@layer tokens {
  :root {
    --neon-pink: #ff007f;
    --cyber-cyan: #00f3ff;
    --laser-purple: #9d4edd;
    --sunset-orange: #ff7b00;
    --outrun-dark: #1a0b2e;

    --font-retro: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    --ink: #fff;
    --ink-dim: color-mix(in srgb, var(--ink) 65%, black);
    --pink-glow: color-mix(in srgb, var(--neon-pink) 55%, transparent);
    --cyan-glow: color-mix(in srgb, var(--cyber-cyan) 30%, transparent);
    --panel: color-mix(in srgb, var(--outrun-dark) 88%, black);
    --panel-edge: color-mix(in srgb, var(--laser-purple) 45%, black);
  }
}

@layer base {
  * { margin: 0; padding: 0; box-sizing: border-box; }
  html, body {
    height: 100%;
    background: #000;
    color: var(--ink);
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  }
}

@layer layout {
  body {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    position: relative;
  }
  .bg-image {
    position: fixed;
    inset: 0;
    background: url('vcr.png') center center / contain no-repeat #000;
    pointer-events: none;
    z-index: 0;
    transition: opacity 0.3s ease;
  }
  .bg-image.dimmed { opacity: 0.3; }
  .site-header {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    z-index: 3;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.5rem 1rem;
    background: color-mix(in srgb, var(--outrun-dark) 70%, black);
    border-bottom: 1px solid var(--panel-edge);
  }
  .scanlines {
    position: fixed;
    inset: 0;
    z-index: 4;
    pointer-events: none;
    background: repeating-linear-gradient(
      to bottom,
      color-mix(in srgb, black 38%, transparent) 0 1px,
      transparent 1px 3px
    );
    opacity: 0.55;
    display: none;
  }
  .scanlines.on { display: block; }
  .vignette {
    position: fixed;
    inset: 0;
    z-index: 5;
    pointer-events: none;
    background: radial-gradient(
      ellipse at center,
      transparent 55%,
      color-mix(in srgb, black 75%, transparent) 100%
    );
  }
  video {
    max-width: 100vw;
    max-height: 80vh;
    width: auto;
    height: auto;
    position: relative;
    z-index: 1;
    border: 2px solid var(--cyan-glow);
    box-shadow: 0 0 24px var(--cyan-glow), 0 0 4px var(--cyan-glow);
    background: #000;
  }
}

@layer components {
  .power-led {
    flex-shrink: 0;
    width: 0.65rem;
    height: 0.65rem;
    border-radius: 50%;
    background: var(--neon-pink);
    box-shadow: 0 0 8px var(--pink-glow), 0 0 3px var(--neon-pink);
    animation: led-blink 1.6s steps(1, end) infinite;
  }
  .scanlines-toggle {
    margin-left: auto;
    display: flex;
    align-items: center;
    gap: 0.4rem;
    cursor: pointer;
    font-size: 0.7rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--ink-dim);
    user-select: none;
  }
  .scanlines-toggle input {
    appearance: none;
    width: 2.2rem;
    height: 1.1rem;
    border-radius: 0.55rem;
    background: color-mix(in srgb, var(--outrun-dark) 80%, black);
    border: 1px solid var(--panel-edge);
    position: relative;
    cursor: pointer;
    transition: background 0.2s, border-color 0.2s;
  }
  .scanlines-toggle input::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 3px;
    transform: translateY(-50%);
    width: 0.85rem;
    height: 0.85rem;
    border-radius: 50%;
    background: var(--ink-dim);
    transition: transform 0.2s, background 0.2s;
  }
  .scanlines-toggle input:checked {
    background: color-mix(in srgb, var(--neon-pink) 30%, var(--outrun-dark));
    border-color: var(--neon-pink);
  }
  .scanlines-toggle input:checked::before {
    transform: translateY(-50%) translateX(1.1rem);
    background: var(--neon-pink);
    box-shadow: 0 0 6px var(--pink-glow);
  }
  .brand {
    margin-left: auto;
    margin-right: auto;
    text-align: center;
    font-size: clamp(1.1rem, 3.5vw, 1.75rem);
    font-weight: 700;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    color: var(--ink);
    text-shadow: 0 0 12px var(--pink-glow), 0 2px 4px rgba(0, 0, 0, 0.8);
    pointer-events: none;
  }
  .brand a {
    color: inherit;
    text-decoration: none;
    pointer-events: auto;
    transition: text-shadow 0.2s ease, color 0.2s ease;
  }
  .brand a:hover {
    color: var(--cyber-cyan);
    text-shadow: 0 0 14px var(--cyan-glow), 0 2px 4px rgba(0, 0, 0, 0.8);
  }
  .download-btn {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0.5rem auto;
    width: 48px;
    height: 48px;
    border: 1px solid var(--panel-edge);
    background: color-mix(in srgb, var(--outrun-dark) 60%, transparent);
    color: var(--cyber-cyan);
    font-family: var(--font-retro);
    font-size: 2rem;
    line-height: 1;
    border-radius: 50%;
    cursor: pointer;
    text-decoration: none;
    box-shadow: 0 0 10px var(--cyan-glow);
    transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
  }
  .download-btn:hover {
    border-color: var(--neon-pink);
    background: color-mix(in srgb, var(--outrun-dark) 80%, transparent);
    box-shadow: 0 0 16px var(--pink-glow);
    color: var(--neon-pink);
  }
  .error {
    text-align: center;
    padding: 2rem;
    line-height: 1.6;
    font-size: clamp(0.9rem, 2.5vw, 1rem);
  }
  .error h1 {
    font-size: clamp(1.25rem, 4vw, 1.5rem);
    margin-bottom: 0.5rem;
    font-weight: 700;
    letter-spacing: 0.12em;
    color: var(--sunset-orange);
    text-shadow: 0 0 12px color-mix(in srgb, var(--sunset-orange) 45%, transparent);
  }
  .error p { color: var(--ink-dim); }
  .error code {
    background: color-mix(in srgb, var(--outrun-dark) 80%, black);
    padding: 0.15em 0.4em;
    border-radius: 4px;
    border: 1px solid var(--panel-edge);
    color: var(--ink);
  }
  .vhs-btn {
    width: 100px;
    flex-shrink: 0;
    cursor: pointer;
    border: 1px solid transparent;
    background: none;
    padding: 0;
    opacity: 0.85;
    transition: opacity 0.2s ease, filter 0.2s ease, border-color 0.2s ease;
  }
  .vhs-btn img { display: block; width: 100%; height: auto; }
  .vhs-btn:hover {
    opacity: 1;
    border-color: var(--neon-pink);
    filter: drop-shadow(0 0 6px var(--pink-glow));
  }
  .tape-window {
    position: fixed;
    top: 76px;
    left: 12px;
    width: 340px;
    max-width: calc(100vw - 24px);
    z-index: 7;
    background: var(--panel);
    border: 1px solid var(--laser-purple);
    border-radius: 6px;
    box-shadow: 0 8px 28px rgba(0, 0, 0, 0.7), 0 0 18px var(--cyan-glow);
  }
  .tape-title {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    padding: 0.6rem 2.4rem 0.6rem 0.8rem;
    font-size: 0.95rem;
    font-weight: 700;
    letter-spacing: 0.22em;
    text-transform: uppercase;
    color: var(--cyber-cyan);
    text-shadow: 0 0 10px var(--cyan-glow);
    border-bottom: 1px solid var(--panel-edge);
    background: linear-gradient(
      90deg,
      color-mix(in srgb, var(--laser-purple) 18%, transparent),
      transparent
    );
  }
  .tape-close {
    position: absolute;
    top: 0.4rem;
    right: 0.5rem;
    width: 26px;
    height: 26px;
    border: 1px solid transparent;
    background: none;
    color: var(--ink-dim);
    font-size: 1rem;
    font-family: var(--font-retro);
    line-height: 1;
    cursor: pointer;
    border-radius: 4px;
    transition: color 0.2s ease, border-color 0.2s ease;
  }
  .tape-close:hover {
    color: var(--neon-pink);
    border-color: var(--neon-pink);
  }
  .tape-list {
    max-height: 60vh;
    overflow-y: auto;
    padding: 0.4rem 0;
  }
  .tape-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.45rem 0.8rem;
    cursor: pointer;
    font-size: 0.9rem;
    transition: background 0.15s ease;
  }
  .tape-row:hover {
    background: color-mix(in srgb, var(--laser-purple) 22%, transparent);
  }
  .tape-row img {
    width: 26px;
    height: 18px;
    flex-shrink: 0;
    opacity: 0.85;
  }
  .tape-empty {
    padding: 0.8rem;
    color: var(--ink-dim);
    font-size: 0.85rem;
    text-align: center;
  }
  .ticker-strip {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    z-index: 3;
    overflow: hidden;
    white-space: nowrap;
    border-top: 1px solid var(--panel-edge);
    background: color-mix(in srgb, var(--outrun-dark) 80%, black);
  }
  .ticker-strip span {
    display: inline-block;
    padding: 0.35rem 0;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.35em;
    text-transform: uppercase;
    color: var(--neon-pink);
    text-shadow: 0 0 8px var(--pink-glow);
    animation: ticker-scroll 12s linear infinite;
  }
}

@layer animations {
  @keyframes led-blink {
    0%, 60% { opacity: 1; }
    61%, 100% { opacity: 0.25; }
  }
  @keyframes ticker-scroll {
    from { transform: translateX(100vw); }
    to { transform: translateX(-100%); }
  }
  @media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
      animation: none !important;
      transition: none !important;
    }
  }
}
</style>
</head>
<body>
<?php
$videoDir = __DIR__ . '/videos';
$paths = is_dir($videoDir) ? glob($videoDir . '/*.mp4') : array();
if ($paths === false) { $paths = array(); }
$tapes = array();
foreach ($paths as $path) {
  if (is_file($path)) { $tapes[] = basename($path); }
}
usort($tapes, function ($a, $b) { return strcmp($a, $b); });
?>
<script>
/*TAPES:BEGIN*/
var TAPES = <?php echo json_encode($tapes, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
/*TAPES:END*/
(function () {
  var v = new URLSearchParams(location.search).get('v');

  var bg = document.createElement('div');
  bg.className = 'bg-image';
  document.body.appendChild(bg);

  var scanlines = document.createElement('div');
  scanlines.className = 'scanlines';
  document.body.appendChild(scanlines);

  var vignette = document.createElement('div');
  vignette.className = 'vignette';
  document.body.appendChild(vignette);

  var header = document.createElement('header');
  header.className = 'site-header';
  var led = document.createElement('span');
  led.className = 'power-led';
  led.setAttribute('aria-hidden', 'true');
  var brand = document.createElement('div');
  brand.className = 'brand';
  brand.textContent = 'HE1T VCR';
  var scanToggle = document.createElement('label');
  scanToggle.className = 'scanlines-toggle';
  var scanCheck = document.createElement('input');
  scanCheck.type = 'checkbox';
  scanCheck.setAttribute('aria-label', 'Scanlines');
  var scanText = document.createElement('span');
  scanText.textContent = 'Scanlines';
  scanToggle.appendChild(scanCheck);
  scanToggle.appendChild(scanText);
  header.appendChild(led);
  header.appendChild(brand);
  header.appendChild(scanToggle);
  document.body.appendChild(header);

  scanCheck.addEventListener('change', function () {
    scanlines.classList.toggle('on', scanCheck.checked);
  });

  var vhsBtn = document.createElement('button');
  vhsBtn.className = 'vhs-btn';
  /* aria-label: "Insert Tape to Play" */
  vhsBtn.setAttribute('aria-label', 'Insert Tape to Play');
  var vhsImg = document.createElement('img');
  vhsImg.src = 'vhs.png';
  vhsImg.alt = 'VHS tape';
  vhsBtn.appendChild(vhsImg);
  header.insertBefore(vhsBtn, led);

  var ticker = document.createElement('div');
  ticker.className = 'ticker-strip';
  ticker.setAttribute('aria-hidden', 'true');
  var tickerText = document.createElement('span');
  tickerText.textContent = '\u25C4\u25C4 REWIND \u25BA\u25BA \u00B7 BE KIND, PLEASE REWIND \u00B7 INSERT TAPE TO PLAY \u00B7 ';
  ticker.appendChild(tickerText);
  document.body.appendChild(ticker);

  // Reserve exactly the header's height in the flow so the centered content
  // (video + download button) can never be positioned underneath the header,
  // regardless of viewport size. Re-measure after fonts/images settle.
  function reserveHeaderSpace() {
    document.body.style.paddingTop = header.offsetHeight + 'px';
  }
  reserveHeaderSpace();
  vhsImg.addEventListener('load', reserveHeaderSpace);
  window.addEventListener('resize', reserveHeaderSpace);

  var win = document.createElement('div');
  win.className = 'tape-window';
  win.style.display = 'none';
  var title = document.createElement('div');
  title.className = 'tape-title';
  title.textContent = 'Insert Tape to Play';
  var close = document.createElement('button');
  close.className = 'tape-close';
  close.setAttribute('aria-label', 'Close');
  close.textContent = '\u2715';
  var list = document.createElement('div');
  list.className = 'tape-list';
  win.appendChild(title);
  win.appendChild(close);
  win.appendChild(list);
  document.body.appendChild(win);

  function openWindow() {
    win.style.display = 'block';
    renderTapes(TAPES);
  }

  function renderTapes(files) {
    list.textContent = '';
    if (!files.length) {
      var empty = document.createElement('div');
      empty.className = 'tape-empty';
      empty.textContent = 'No tapes found';
      list.appendChild(empty);
      return;
    }
    files.forEach(function (name) {
      var row = document.createElement('div');
      row.className = 'tape-row';
      var icon = document.createElement('img');
      icon.src = 'vhs.png';
      icon.alt = '';
      var label = document.createElement('span');
      label.textContent = name;
      row.appendChild(icon);
      row.appendChild(label);
      row.addEventListener('click', function () {
        location.href = './?v=' + encodeURIComponent(name);
      });
      list.appendChild(row);
    });
  }

  vhsBtn.addEventListener('click', function () {
    if (win.style.display === 'block') { win.style.display = 'none'; } else { openWindow(); }
  });
  close.addEventListener('click', function () { win.style.display = 'none'; });

  if (!v) {
    return;
  }

  var src;
  if (v.includes('..') || v.startsWith('/') || v.includes('://')) {
    showError('Invalid video URL', 'Video must be a filename within the videos directory.');
    return;
  }
  if (TAPES.indexOf(v) === -1) {
    // Not a tape: skip the player and download button entirely.
    showError('Video not found', 'The file does not exist.');
    return;
  }
  src = 'videos/' + v;

  var video = document.createElement('video');
  video.src = src;
  video.controls = true;
  // Autoplay is only guaranteed when the user has interacted with the page
  // (e.g. clicked a tape in the chooser). Direct deep-link loads may still
  // be blocked by the browser's autoplay policy; the video shows controls
  // and plays on the user's first interaction in that case.
  video.autoplay = true;
  video.setAttribute('autoplay', '');
  video.playsInline = true;
  video.setAttribute('playsinline', '');
  document.body.appendChild(video);

  var dl = document.createElement('a');
  dl.className = 'download-btn';
  dl.href = video.src;
  dl.download = video.src.split('/').pop().split('?')[0] || 'video';
  dl.title = 'Download video';
  dl.textContent = '⬇';
  document.body.appendChild(dl);
  bg.classList.add('dimmed');
  brand.innerHTML = '<a href="/">HE1T VCR</a>';

  video.addEventListener('error', function () {
    if (!video.error) return;
    showError('Video not found', 'The file does not exist.');
  });

  function showError(title, message) {
    var el = document.createElement('div');
    el.className = 'error';
    el.style.zIndex = '1';
    if (message) {
      var p = document.createElement('p');
      p.innerHTML = message;
      el.appendChild(p);
    }
    document.body.appendChild(el);
  }
})();
</script>
</body>
</html>
