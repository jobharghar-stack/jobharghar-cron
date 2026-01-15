<?php

/* ===============================
   CONFIG
   =============================== */

$sourcesFile = __DIR__ . '/sources_jobs.json';
$seenFile    = __DIR__ . '/seen_job_pdfs.json';

/* PDF keywords */
$JOB_KEYWORDS = [
  'recruitment','advertisement','engagement',
  'notification','vacancy','walkin','appointment','advt'
];

$IGNORE_KEYWORDS = [
  'result','answer','merit','score','selection'
];

/* HTML job signals */
$REQUIRED_JOB_WORDS = [
  'vacancy','recruitment','apply','posts','eligibility'
];

/* Ignore noisy internal pages */
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
   HELPERS
   =============================== */

function cleanText($html) {
  $text = strtolower(strip_tags($html));
  $text = preg_replace('/\s+/', ' ', $text);
  return substr($text, 0, 5000); // limit noise
}

function isRealJobPage($text, $requiredWords) {
  $hits = 0;
  foreach ($requiredWords as $w) {
    if (strpos($text, $w) !== false) $hits++;
  }
  return $hits >= 2; // minimum signal
}

function makeAbsoluteUrl($base, $link) {
  if (strpos($link, 'http') === 0) return $link;
  $p = parse_url($base);
  return $p['scheme'].'://'.$p['host'].'/'.ltrim($link,'/');
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
   HTTP CONTEXT
   =============================== */

$context = stream_context_create([
  'http' => [
    'timeout' => 20,
    'header'  => "User-Agent: JobHarGharBot/1.0\r\n"
  ],
  'ssl' => [
    'verify_peer' => false,
    'verify_peer_name' => false
  ]
]);

/* ===============================
   MAIN LOOP
   =============================== */

foreach ($sources as $src) {

  $org = $src['org'] ?? 'Unknown';
  echo "Checking: {$org}\n";

  /* fetch source page */
  $html = false;
  for ($i=1; $i<=2; $i++) {
    $html = @file_get_contents($src['url'], false, $context);
    if ($html !== false) break;
    sleep(2);
  }

  if ($html === false || strlen($html) < 200) {
    echo "SKIP (timeout/empty)\n";
    continue;
  }

  $cleanSourceText = cleanText($html);

  /* ===============================
     EMPLOYMENT NEWS (STRICT)
     =============================== */

  if (stripos($src['url'], 'employmentnews') !== false) {

    if (preg_match('/(\d{1,2}\s+(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\s+\d{4})/i',
        $cleanSourceText, $m)) {

      $issueDate = $m[1];

      if (($seen[$org]['issue'] ?? '') !== $issueDate) {
        sendTelegram("📰 Employment News – New Issue\n📅 {$issueDate}");
        $seen[$org]['issue'] = $issueDate;
      }
    }
    continue; // do NOT process HTML links further
  }

  /* ===============================
     PDF DETECTION (HASH BASED)
     =============================== */

  preg_match_all('/href=["\']([^"\']+\.pdf)["\']/i', $html, $m);
  $pdfs = array_unique($m[1] ?? []);

  foreach ($pdfs as $pdf) {

    $pdf = makeAbsoluteUrl($src['url'], $pdf);
    $name = strtolower(basename($pdf));

    foreach ($IGNORE_KEYWORDS as $bad) {
      if (strpos($name, $bad) !== false) continue 2;
    }

    $isJob = false;
    foreach ($JOB_KEYWORDS as $good) {
      if (strpos($name, $good) !== false) {
        $isJob = true; break;
      }
    }
    if (!$isJob) continue;

    $content = @file_get_contents($pdf, false, $context);
    if (!$content) continue;

    $hash = md5($content);

    if (($seen[$org]['pdf'][$pdf] ?? '') === $hash) continue;

    sendTelegram(
      "📢 New/Updated Job PDF\n".
      "🏢 {$org}\n".
      "📄 {$pdf}"
    );

    $seen[$org]['pdf'][$pdf] = $hash;
  }

  /* ===============================
     HTML RECRUITMENT PAGES (STRICT)
     =============================== */

  preg_match_all(
    '/href=["\']([^"\']+(recruit|vacancy|notification|advertisement)[^"\']*)["\']/i',
    $html,
    $links
  );

  $jobPages = array_unique($links[1] ?? []);

  foreach ($jobPages as $link) {

    foreach ($IGNORE_PAGE_PATTERNS as $bad) {
      if (stripos($link, $bad) !== false) continue 2;
    }

    $link = makeAbsoluteUrl($src['url'], $link);

    $pageHtml = @file_get_contents($link, false, $context);
    if (!$pageHtml || strlen($pageHtml) < 300) continue;

    $cleanPageText = cleanText($pageHtml);

    if (!isRealJobPage($cleanPageText, $REQUIRED_JOB_WORDS)) continue;

    /* rate limit: max 1 HTML alert/day per org */
    $lastAlert = $seen[$org]['last_html_alert'] ?? 0;
    if (time() - $lastAlert < 86400) continue;

    $hash = md5($cleanPageText);

    if (($seen[$org]['page'][$link] ?? '') === $hash) continue;

    sendTelegram(
      "🆕 Recruitment Page Updated\n".
      "🏢 {$org}\n".
      "🔗 {$link}"
    );

    $seen[$org]['page'][$link] = $hash;
    $seen[$org]['last_html_alert'] = time();
  }
}

/* ===============================
   SAVE STATE
   =============================== */

file_put_contents(
  $seenFile,
  json_encode($seen, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

echo "Done\n";
