<?php
/* =====================================================
   TELEGRAM MOVIE REQUEST BOT
   PHP + Render.com (Webhook)
   MULTI-QUALITY SUPPORT (CSV)
   ===================================================== */

ini_set("log_errors", 1);
ini_set("error_log", __DIR__ . "/error.log");

/* ================= ENV CONFIG ================= */

$BOT_TOKEN = getenv("BOT_TOKEN"); // Render ENV
$API = "https://api.telegram.org/bot$BOT_TOKEN/";

$REQUEST_GROUP = getenv("REQUEST_GROUP") ?: -1003083386043;
$MAIN_CHANNEL  = getenv("MAIN_CHANNEL")  ?: -1002831605258;
$FORCE_CHANNEL = getenv("FORCE_CHANNEL") ?: -1003181705395;

/* ================= STORAGE ================= */

$dataDir = __DIR__ . "/data";
@mkdir($dataDir, 0777, true);

$requestsFile = $dataDir . "/requests.json";
if (!file_exists($requestsFile)) {
    file_put_contents($requestsFile, "{}");
}

/* ================= GET UPDATE (WEBHOOK) ================= */

$update = json_decode(file_get_contents("php://input"), true);
if (!$update) exit;

/* ================= FUNCTIONS ================= */

function api($method, $data = []) {
    global $API;
    $opts = [
        "http" => [
            "method"  => "POST",
            "header"  => "Content-Type:application/json",
            "content" => json_encode($data),
            "timeout" => 10
        ]
    ];
    $res = @file_get_contents($API.$method, false, stream_context_create($opts));
    return json_decode($res, true);
}

function normalize($text) {
    return strtolower(preg_replace("/[^a-z0-9]/", "", $text));
}

function forceJoinCheck($user_id) {
    global $FORCE_CHANNEL;
    $res = api("getChatMember", [
        "chat_id" => $FORCE_CHANNEL,
        "user_id" => $user_id
    ]);
    return isset($res["result"]["status"]) && $res["result"]["status"] != "left";
}

/* ================= MOVIE SEARCH (CSV → MULTI SEND) ================= */

function searchAndSendMovie($movie, $user_id) {
    global $MAIN_CHANNEL;

    if (!file_exists("movies.csv")) return false;

    $key = normalize($movie);
    $file = fopen("movies.csv", "r");
    $found = false;

    while (($row = fgetcsv($file)) !== false) {

        // Skip header
        if ($row[0] === "movie_name") continue;

        $movieName = normalize($row[0]);
        $messageId = $row[1] ?? null;

        if (!$messageId) continue;

        if (strpos($movieName, $key) !== false) {

            api("forwardMessage", [
                "chat_id" => $user_id,
                "from_chat_id" => $MAIN_CHANNEL,
                "message_id" => $messageId
            ]);

            $found = true;
            usleep(400000); // 0.4 sec delay (anti flood)
        }
    }

    fclose($file);
    return $found;
}

/* ================= MESSAGE HANDLER ================= */

if (isset($update["message"])) {

    $m = $update["message"];
    $chat_id = $m["chat"]["id"];
    $user_id = $m["from"]["id"];
    $text = trim($m["text"] ?? "");

    /* ===== /start ===== */
    if ($text === "/start") {
        api("sendMessage", [
            "chat_id" => $chat_id,
            "parse_mode" => "Markdown",
            "text" =>
"🎬 *Entertainment Tadka me aapka swagat hai!*

📢 *Bot use kaise karein:*
• Bas movie ka naam type karo  
• English / Hindi dono chalega  
• Partial naam bhi work karega  

🔍 *Examples:*
• The Raja Saab  
• Avatar  
• Baahubali  

❌ *Mat likho:*
• Technical questions  
• Player commands  

📢 Join: @threater_print_movies"
        ]);
        exit;
    }

    /* ===== FORCE JOIN CHECK ===== */
    if ($chat_id == $REQUEST_GROUP && !forceJoinCheck($user_id)) {
        api("sendMessage", [
            "chat_id" => $chat_id,
            "text" => "🚫 Pehle channel join karo 👉 @threater_print_movies"
        ]);
        exit;
    }

    /* ===== MOVIE TEXT ===== */
    if (strpos($text, "/request") === 0) {
        $movie = trim(str_replace("/request", "", $text));
    } else {
        $movie = $text;
    }

    if (strlen($movie) < 3) exit;

    /* ===== AUTO SEARCH FIRST ===== */
    if (searchAndSendMovie($movie, $user_id)) {
        api("sendMessage", [
            "chat_id" => $chat_id,
            "text" => "🎉 Movie already available thi!"
        ]);
        exit;
    }

    /* ===== DUPLICATE REQUEST CHECK ===== */
    $requests = json_decode(file_get_contents($requestsFile), true);
    if (!is_array($requests)) $requests = [];

    $key = normalize($movie);

    if (isset($requests[$key])) {
        api("sendMessage", [
            "chat_id" => $chat_id,
            "text" => "⚠️ Ye movie already request ho chuki hai."
        ]);
        exit;
    }

    /* ===== SAVE REQUEST ===== */
    $requests[$key] = [
        "movie" => $movie,
        "user_id" => $user_id,
        "time" => time()
    ];
    file_put_contents($requestsFile, json_encode($requests, JSON_PRETTY_PRINT));

    /* ===== SEND TO MAIN CHANNEL ===== */
    api("sendMessage", [
        "chat_id" => $MAIN_CHANNEL,
        "parse_mode" => "Markdown",
        "text" =>
"📥 *New Movie Request*

🎬 Movie: `$movie`
👤 User ID: `$user_id`",
        "reply_markup" => [
            "inline_keyboard" => [[
                ["text" => "✅ Completed", "callback_data" => "done|$key"]
            ]]
        ]
    ]);

    api("sendMessage", [
        "chat_id" => $chat_id,
        "text" => "✅ Tumhari request admin tak pahuch gayi hai."
    ]);
}

/* ================= CALLBACK HANDLER ================= */

if (isset($update["callback_query"])) {

    $cb = $update["callback_query"];
    $data = $cb["data"];
    $msg  = $cb["message"];

    if (strpos($data, "done|") === 0) {

        $key = explode("|", $data)[1];
        $requests = json_decode(file_get_contents($requestsFile), true);

        if (!isset($requests[$key])) exit;

        $user_id = $requests[$key]["user_id"];
        $movie   = $requests[$key]["movie"];

        api("sendMessage", [
            "chat_id" => $user_id,
            "parse_mode" => "Markdown",
            "text" => "🎉 *$movie* ab available hai!\nChannel check karo 😎"
        ]);

        unset($requests[$key]);
        file_put_contents($requestsFile, json_encode($requests, JSON_PRETTY_PRINT));

        api("editMessageReplyMarkup", [
            "chat_id" => $msg["chat"]["id"],
            "message_id" => $msg["message_id"],
            "reply_markup" => ["inline_keyboard" => []]
        ]);
    }
}
