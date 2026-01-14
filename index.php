<?php
/* =====================================================
   TELEGRAM MOVIE REQUEST BOT
   PHP + Render.com (Webhook)
   ===================================================== */

ini_set("log_errors", 1);
ini_set("error_log", __DIR__."/error.log");

/* ================= CONFIG ================= */

$BOT_TOKEN = "8563112841:AAFh68stDgClAMa3hc4d4pA3dIRa-goOXc8";
$API = "https://api.telegram.org/bot$BOT_TOKEN/";

$REQUEST_GROUP = -1003083386043;
$MAIN_CHANNEL  = -1002831605258;
$FORCE_CHANNEL = -1003181705395;

/* ================= STORAGE ================= */

$dataDir = __DIR__ . "/data";
@mkdir($dataDir, 0777, true);

$requestsFile = "$dataDir/requests.json";
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
            "content" => json_encode($data)
        ]
    ];
    $res = file_get_contents($API.$method, false, stream_context_create($opts));
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

/* 🔍 SEARCH FROM movies.csv */
function searchAndSendMovie($movie, $user_id) {
    global $MAIN_CHANNEL;

    $key = normalize($movie);
    $file = fopen("movies.csv", "r");

    while (($row = fgetcsv($file)) !== false) {
        if (normalize($row[0]) === $key) {
            api("forwardMessage", [
                "chat_id" => $user_id,
                "from_chat_id" => $MAIN_CHANNEL,
                "message_id" => $row[1]
            ]);
            fclose($file);
            return true;
        }
    }

    fclose($file);
    return false;
}

/* ================= MESSAGE HANDLER ================= */

if (isset($update["message"])) {

    $m = $update["message"];
    $chat_id = $m["chat"]["id"];
    $user_id = $m["from"]["id"];
    $text = trim($m["text"] ?? "");

    if ($text === "/start") {
        api("sendMessage", [
            "chat_id" => $chat_id,
            "parse_mode" => "Markdown",
            "text" =>
"🎬 *Entertainment Tadka me aapka swagat hai!*

Bas movie ka naam bhejo 🎥"
        ]);
        exit;
    }

    if ($chat_id == $REQUEST_GROUP && !forceJoinCheck($user_id)) {
        api("sendMessage", [
            "chat_id" => $chat_id,
            "text" => "🚫 Pehle channel join karo 👉 @threater_print_movies"
        ]);
        exit;
    }

    $movie = (strpos($text, "/request") === 0)
        ? trim(str_replace("/request", "", $text))
        : $text;

    if (strlen($movie) < 3) exit;

    if (searchAndSendMovie($movie, $user_id)) {
        api("sendMessage", [
            "chat_id" => $chat_id,
            "text" => "🎉 Movie already available thi!"
        ]);
        exit;
    }

    $requests = json_decode(file_get_contents($requestsFile), true);
    $key = normalize($movie);

    if (isset($requests[$key])) {
        api("sendMessage", [
            "chat_id" => $chat_id,
            "text" => "⚠️ Ye movie already requested hai."
        ]);
        exit;
    }

    $requests[$key] = ["movie"=>$movie,"user_id"=>$user_id];
    file_put_contents($requestsFile, json_encode($requests));

    api("sendMessage", [
        "chat_id" => $MAIN_CHANNEL,
        "parse_mode" => "Markdown",
        "text" =>
"📥 *New Movie Request*

🎬 $movie
👤 $user_id",
        "reply_markup" => [
            "inline_keyboard" => [[
                ["text"=>"✅ Completed","callback_data"=>"done|$key"]
            ]]
        ]
    ]);

    api("sendMessage", [
        "chat_id" => $chat_id,
        "text" => "✅ Request admin tak pahuch gayi."
    ]);
}

/* ================= CALLBACK ================= */

if (isset($update["callback_query"])) {

    $cb = $update["callback_query"];
    $data = $cb["data"];
    $msg  = $cb["message"];

    if (strpos($data, "done|") === 0) {

        $key = explode("|", $data)[1];
        $requests = json_decode(file_get_contents($requestsFile), true);

        if (!isset($requests[$key])) exit;

        api("sendMessage", [
            "chat_id" => $requests[$key]["user_id"],
            "text" => "🎉 {$requests[$key]['movie']} ab available hai!"
        ]);

        unset($requests[$key]);
        file_put_contents($requestsFile, json_encode($requests));

        api("editMessageReplyMarkup", [
            "chat_id" => $msg["chat"]["id"],
            "message_id" => $msg["message_id"],
            "reply_markup" => ["inline_keyboard"=>[]]
        ]);
    }
}
