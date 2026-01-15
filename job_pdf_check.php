<?php

/* ===============================
   CONFIG
   =============================== */

$sourcesFile = __DIR__ . '/sources_jobs.json';
$seenFile    = __DIR__ . '/seen_job_pdfs.json';

$JOB_KEYWORDS = [
    'recruitment','advertisement','engagement',
    'notification','vacancy','walkin','appointment','advt'
];

$IGNORE_KEYWORDS = [
    'result','answer','merit','score','selection'
];

$PAGE_KEYWORDS = [
    'recruit','vacancy','advertisement',
    'engagement','appointment','walkin'
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
    @file_get_contents($url . "?" . http_build_query($params));
}

/* ===============================
   HELPERS
   =============================== */

function isJobPage($html, $keywords) {
    $text = strtolower(strip_tags($html));
    foreach ($keywords as $k) {
        if (strpos($text, $k) !== false) return true;
    }
    return false;
}

function makeAbsoluteUrl($baseUrl, $link) {
    if (strpos($link, 'http') === 0) return $link;
    $p = parse_url($baseUrl);
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
    'verify_peer'      => false,
    'verify_peer_name' => false
  ]
]);

/* ===============================
   MAIN LOOP
   =============================== */

foreach ($sources as $src) {

    $org = $src['org'] ?? 'Unknown';
    echo "Checking: {$org}\n";

    $html = false;

    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $html = @file_get_contents($src['url'], false, $context);
        if ($html !== false) break;
        sleep(2);
    }

    if ($html === false || strlen($html) < 200) {
        echo "SKIP (timeout/empty): {$src['url']}\n";
        continue;
    }

    /* ===============================
       EMPLOYMENT NEWS (SPECIAL)
       =============================== */

    if (stripos($src['url'], 'employmentnews') !== false) {
        $pageHash = md5($html);
        if (($seen[$org]['page']['home'] ?? '') !== $pageHash) {
            sendTelegram("📰 Employment News updated\n🔗 {$src['url']}");
            $seen[$org]['page']['home'] = $pageHash;
        }
    }

    /* ===============================
       PDF DETECTION
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
       HTML RECRUITMENT PAGE DETECTION
       =============================== */

    preg_match_all(
      '/href=["\']([^"\']+(?:recruit|vacancy|notification|advertisement)[^"\']*)["\']/i',
      $html,
      $links
    );

    $jobPages = array_unique($links[1] ?? []);

    foreach ($jobPages as $link) {

        $link = makeAbsoluteUrl($src['url'], $link);

        $pageHtml = @file_get_contents($link, false, $context);
        if (!$pageHtml || strlen($pageHtml) < 300) continue;

        if (!isJobPage($pageHtml, $PAGE_KEYWORDS)) continue;

        $hash = md5($pageHtml);

        if (($seen[$org]['page'][$link] ?? '') === $hash) continue;

        sendTelegram(
            "🆕 Recruitment Page Updated\n".
            "🏢 {$org}\n".
            "🔗 {$link}"
        );

        $seen[$org]['page'][$link] = $hash;
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
