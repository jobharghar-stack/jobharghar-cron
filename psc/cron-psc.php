<?php
$VALID_YEARS = [
    date('Y'),          // 2026
    date('Y') - 1       // 2025
];
function isRecentPdf($pdf, $validYears) {
    $pdf = strtolower($pdf);

    foreach ($validYears as $y) {
        if (strpos($pdf, (string)$y) !== false) {
            return true;
        }
    }

    if (preg_match('/new|latest|current/', $pdf)) {
        return true;
    }

    return false;
}

$sourcesFile = __DIR__ . '/sources_psc.json';
$seenFile    = __DIR__ . '/seen_psc.json';

$BOT_TOKEN = getenv('TELEGRAM_BOT_TOKEN');
$CHAT_ID   = getenv('TELEGRAM_CHAT_ID');

function sendTelegram($msg) {
    global $BOT_TOKEN, $CHAT_ID;
    if (!$BOT_TOKEN || !$CHAT_ID) return;
    $url = "https://api.telegram.org/bot{$BOT_TOKEN}/sendMessage";
    file_get_contents($url . '?' . http_build_query([
        'chat_id' => $CHAT_ID,
        'text' => "[PSC JOB]\n" . $msg,
        'disable_web_page_preview' => true
    ]));
}

$sources = json_decode(file_get_contents($sourcesFile), true);
$seen = file_exists($seenFile) ? json_decode(file_get_contents($seenFile), true) : [];

foreach ($sources as $src) {
    echo "Checking: {$src['org']}\n";

    $ctx = stream_context_create([
        'http' => ['timeout' => 50, 'header' => "User-Agent: JobHarGharBot\r\n"],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false]
    ]);

    $html = @file_get_contents($src['url'], false, $ctx);
    if (!$html || strlen($html) < 200) {
        echo "SKIP: {$src['org']}\n";
        continue;
    }

    preg_match_all('/href=["\']([^"\']+\.pdf)["\']/i', $html, $m);
    $pdfs = array_unique($m[1] ?? []);

  foreach ($pdfs as $pdf) {

    if (!isRecentPdf($pdf, $VALID_YEARS)) {
        continue; // ❌ ignore old PDFs
    }

    if (isset($seen[$pdf])) {
        continue; // already processed
    }

    if (!$isFirstRun) {
        sendTelegram(
            "{$src['org']}\n{$pdf}"
        );
    }

    $seen[$pdf] = time();
}

}

file_put_contents($seenFile, json_encode($seen, JSON_PRETTY_PRINT));
echo "Done\n";
