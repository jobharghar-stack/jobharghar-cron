<?php

/* ===============================
   CONFIG
   =============================== */

$sourcesFile = __DIR__ . '/sources_jobs.json';
$seenFile    = __DIR__ . '/seen_job_pdfs.json';

$MAX_NOTICE_AGE_DAYS = 45;

$JOB_KEYWORDS = [
  'recruitment','advertisement','engagement',
  'notification','vacancy','walkin','appointment','advt'
];

$IGNORE_KEYWORDS = [
  'result','answer','merit','score','selection'
];

$REQUIRED_JOB_WORDS = [
  'vacancy','recruitment','apply','posts','eligibility'
];

$IGNORE_PAGE_PATTERNS = [
  'login','register','dashboard','captcha',
  'privacy','terms','contact','sitemap'
];

/* ===============================
   TELEGRAM
   =============================== */

$BOT_TOKEN = getenv('TELEGRAM_BOT_TOKEN');
$CHAT_ID   = getenv('TELEGRAM_CHAT_ID');

function sendTelegram($msg) {
  global $BOT_TOKEN, $CHAT_ID;
  if (!$BOT_TOKEN || !$CHAT_ID) return;

  $url = "https://api.telegram.org/bot{$BOT_TOKEN}/sendMessage";
  $params = [
    'chat_id' => $CHAT_ID,
    'text'    => $msg,
    'disable_web_page_preview' => true
  ];
  @file_get_contents($url . '?' . http_build_query($params));
}

/* ===============================
   SAFE FETCH (HARD 30s LIMIT)
   =============================== */

function fetchUrl($url, $timeout = 30) {

  if (!filter_var($url, FILTER_VALIDATE_URL)) return false;

  $ch = curl_init();
  curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,

    CURLOPT_CONNECTTIMEOUT => 5,   // DNS + TCP
    CURLOPT_TIMEOUT => $timeout,  // TOTAL HARD LIMIT

    CURLOPT_NOSIGNAL => true,
    CURLOPT_USERAGENT => 'JobHarGharBot/1.0',
    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,

    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
  ]);

  $data = curl_exec($ch);
  curl_close($ch);

  return $data;
}

/* ===============================
   HELPERS
   =============================== */

function cleanText($html) {
  $text = strtolower(strip_tags($html));
  $text = preg_replace('/\s+/', ' ', $text);
  return substr($text, 0, 7000);
}

function isRealJobText($text, $required) {
  $hits = 0;
  foreach ($required as $w) {
    if (strpos($text, $w) !== false) $hits++;
  }
  return $hits >= 2;
}

function makeAbsoluteUrl($base, $link) {
  if (strpos($link, 'http') === 0) return $link;
  $p = parse_url($base);
  return $p['scheme'].'://'.$p['host'].'/'.ltrim($link,'/');
}

function isRecentNotice($text, $maxDays) {
  if (preg_match(
    '/(\d{1,2}\s+(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\s+\d{4})/i',
    $text,
    $m
  )) {
    $date = strtotime($m[1]);
    if ($date && $date < strtotime("-{$maxDays} days")) {
      return false;
    }
  }
  return true;
}

/* ===============================
   LOAD STATE
   =============================== */

$sources = json_decode(file_get_contents($sourcesFile), true);
$seen    = file_exists($seenFile)
         ? json_decode(file_get_contents($seenFile), true)
         : [];

if (!$sources || !is_array($sources)) {
  sendTelegram("❌ Sources file empty or invalid");
  exit;
}

/* ===============================
   MAIN LOOP
   =============================== */

foreach ($sources as $src) {

  $org = $src['org'] ?? 'Unknown';
  echo "Checking: {$org}\n";

  $isFirstScan = empty($seen[$org]['initialized']);

  /* ---- Fetch source page (30s max) ---- */
  $html = fetchUrl($src['url'], 30);
  if (!$html || strlen($html) < 200) {
    echo "SKIP (source not responding)\n";
    continue;
  }

  preg_match_all(
    '/href=["\']([^"\']+(recruit|vacancy|notification|advertisement)[^"\']*)["\']/i',
    $html,
    $links
  );

  $jobPages = array_unique($links[1] ?? []);

  foreach ($jobPages as $pageLink) {

    foreach ($IGNORE_PAGE_PATTERNS as $bad) {
      if (stripos($pageLink, $bad) !== false) continue 2;
    }

    $pageLink = makeAbsoluteUrl($src['url'], $pageLink);
    $pageId   = md5($pageLink);

    if (isset($seen[$org]['html_job'][$pageId])) continue;

    /* ---- Fetch detail page (30s max) ---- */
    $pageHtml = fetchUrl($pageLink, 30);
    if (!$pageHtml || strlen($pageHtml) < 300) {
      echo "SKIP (page not responding)\n";
      continue;
    }

    $cleanPageText = cleanText($pageHtml);
    if (!isRealJobText($cleanPageText, $REQUIRED_JOB_WORDS)) continue;
    if (!isRecentNotice($cleanPageText, $MAX_NOTICE_AGE_DAYS)) continue;

    if (!$isFirstScan) {
      sendTelegram(
        "🆕 Recruitment Notice\n".
        "🏢 {$org}\n".
        "🔗 {$pageLink}"
      );
    }

    $seen[$org]['html_job'][$pageId] = [
      'url' => $pageLink,
      'time' => time()
    ];

    /* ---- Check PDFs inside page ---- */
    preg_match_all('/href=["\']([^"\']+\.pdf)["\']/i', $pageHtml, $pm);
    $pdfs = array_unique($pm[1] ?? []);

    foreach ($pdfs as $pdf) {

      $pdf = makeAbsoluteUrl($pageLink, $pdf);
      $name = strtolower(basename($pdf));

      foreach ($IGNORE_KEYWORDS as $bad) {
        if (strpos($name, $bad) !== false) continue 2;
      }

      $content = fetchUrl($pdf, 30);
      if (!$content) {
        echo "SKIP (pdf not responding)\n";
        continue;
      }

      $hash = md5($content);

      if ($isFirstScan) {
        $seen[$org]['pdf'][$pdf] = $hash;
        continue;
      }

      if (($seen[$org]['pdf'][$pdf] ?? '') === $hash) continue;

      sendTelegram(
        "📄 Official Notification PDF Released\n".
        "🏢 {$org}\n".
        "📄 {$pdf}"
      );

      $seen[$org]['pdf'][$pdf] = $hash;
      $seen[$org]['html_job'][$pageId]['pdf'] = $pdf;
    }
  }

  $seen[$org]['initialized'] = true;
}

/* ===============================
   SAVE STATE
   =============================== */

file_put_contents(
  $seenFile,
  json_encode($seen, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

echo "Done\n";
