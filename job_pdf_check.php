
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

/* ===============================
   TELEGRAM
   =============================== */

$BOT_TOKEN = getenv('TELEGRAM_BOT_TOKEN');
$CHAT_ID  = getenv('TELEGRAM_CHAT_ID');

function sendTelegram($msg) {
    global $BOT_TOKEN, $CHAT_ID;
    if (!$BOT_TOKEN || !$CHAT_ID) return;

    $url = "https://api.telegram.org/bot{$BOT_TOKEN}/sendMessage";
    $params = [
        'chat_id' => $CHAT_ID,
        'text' => $msg
    ];
    file_get_contents($url . "?" . http_build_query($params));
}

/* ===============================
   LOAD STATE
   =============================== */

$sources = json_decode(file_get_contents($sourcesFile), true);
$seen    = file_exists($seenFile)
         ? json_decode(file_get_contents($seenFile), true)
         : [];

if (!$sources) {
    sendTelegram("❌ Sources file empty or invalid");
    exit;
}

/* ===============================
   HTTP CONTEXT (timeout)
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

    echo "Checking: {$src['org']}\n";

    $html = false;

for ($attempt = 1; $attempt <= 2; $attempt++) {
    $html = @file_get_contents($src['url'], false, $context);
    if ($html !== false) break;
    sleep(2);
}

if ($html === false) {
    echo "SKIP (timeout): {$src['url']}\n";
    continue;
}
    if (strlen($html) < 200) {
        echo "SKIP (empty response)\n";
        continue;
    }

    preg_match_all('/href=["\']([^"\']+\.pdf)["\']/i', $html, $m);
    $pdfs = array_unique($m[1] ?? []);

    foreach ($pdfs as $pdf) {

        $name = strtolower(basename($pdf));

        // ignore non-job PDFs
        foreach ($IGNORE_KEYWORDS as $bad) {
            if (strpos($name, $bad) !== false) continue 2;
        }

        // allow only job PDFs
        $isJob = false;
        foreach ($JOB_KEYWORDS as $good) {
            if (strpos($name, $good) !== false) {
                $isJob = true; break;
            }
        }
        if (!$isJob) continue;

        if (!isset($seen[$src['org']][$name])) {

            $msg = "📢 New Job PDF\n"
                 . "🏢 {$src['org']}\n"
                 . "📄 {$pdf}";

            sendTelegram($msg);
            echo "ALERT: $name\n";

            $seen[$src['org']][$name] = date('c');
        }
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
