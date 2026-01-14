<?php
// index.php - Complete version for Render.com
// Enable error reporting
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// -------------------- CONFIG --------------------
// SECURITY NOTE: Use environment variables on Render.com
define('BOT_TOKEN', getenv('BOT_TOKEN') ?: '8563112841:AAFh68stDgClAMa3hc4d4pA3dIRa-goOXc8');
define('CHANNEL_ID', getenv('CHANNEL_ID') ?: '-1002831605258');
define('GROUP_CHANNEL_ID', getenv('GROUP_CHANNEL_ID') ?: '-1003083386043');
define('CSV_FILE', 'movies.csv');
define('USERS_FILE', 'users.json');
define('STATS_FILE', 'bot_stats.json');
define('BACKUP_DIR', 'backups/');
define('CACHE_EXPIRY', 300);
define('ITEMS_PER_PAGE', 5);
define('BASE_URL', getenv('RENDER_EXTERNAL_URL') ?: (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST']);
// ------------------------------------------------

// File initialization
if (!file_exists(USERS_FILE)) {
    file_put_contents(USERS_FILE, json_encode(['users' => [], 'total_requests' => 0, 'message_logs' => []]));
    @chmod(USERS_FILE, 0666);
}

if (!file_exists(CSV_FILE)) {
    file_put_contents(CSV_FILE, "movie_name,message_id,date,video_path\n");
    @chmod(CSV_FILE, 0666);
}

if (!file_exists(STATS_FILE)) {
    file_put_contents(STATS_FILE, json_encode([
        'total_movies' => 0, 
        'total_users' => 0, 
        'total_searches' => 0, 
        'last_updated' => date('Y-m-d H:i:s')
    ]));
    @chmod(STATS_FILE, 0666);
}

if (!file_exists(BACKUP_DIR)) {
    @mkdir(BACKUP_DIR, 0777, true);
}

// memory caches
$movie_messages = array();
$movie_cache = array();
$waiting_users = array();

// ==============================
// Stats
// ==============================
function update_stats($field, $increment = 1) {
    if (!file_exists(STATS_FILE)) return;
    $stats = json_decode(file_get_contents(STATS_FILE), true);
    $stats[$field] = ($stats[$field] ?? 0) + $increment;
    $stats['last_updated'] = date('Y-m-d H:i:s');
    file_put_contents(STATS_FILE, json_encode($stats, JSON_PRETTY_PRINT));
}

function get_stats() {
    if (!file_exists(STATS_FILE)) return [];
    return json_decode(file_get_contents(STATS_FILE), true);
}

// ==============================
// Caching / CSV loading
// ==============================
function load_and_clean_csv($filename = CSV_FILE) {
    global $movie_messages;
    if (!file_exists($filename)) {
        file_put_contents($filename, "movie_name,message_id,date,video_path\n");
        return [];
    }

    $data = [];
    $handle = fopen($filename, "r");
    if ($handle !== FALSE) {
        $header = fgetcsv($handle);
        while (($row = fgetcsv($handle)) !== FALSE) {
            if (count($row) >= 3 && (!empty(trim($row[0])))) {
                $movie_name = trim($row[0]);
                $message_id_raw = isset($row[1]) ? trim($row[1]) : '';
                $date = isset($row[2]) ? trim($row[2]) : '';
                $video_path = isset($row[3]) ? trim($row[3]) : '';

                $entry = [
                    'movie_name' => $movie_name,
                    'message_id_raw' => $message_id_raw,
                    'date' => $date,
                    'video_path' => $video_path
                ];
                if (is_numeric($message_id_raw)) {
                    $entry['message_id'] = intval($message_id_raw);
                } else {
                    $entry['message_id'] = null;
                }

                $data[] = $entry;

                $movie = strtolower($movie_name);
                if (!isset($movie_messages[$movie])) $movie_messages[$movie] = [];
                $movie_messages[$movie][] = $entry;
            }
        }
        fclose($handle);
    }

    $stats = json_decode(file_get_contents(STATS_FILE), true);
    $stats['total_movies'] = count($data);
    $stats['last_updated'] = date('Y-m-d H:i:s');
    file_put_contents(STATS_FILE, json_encode($stats, JSON_PRETTY_PRINT));

    $handle = fopen($filename, "w");
    fputcsv($handle, array('movie_name','message_id','date','video_path'));
    foreach ($data as $row) {
        fputcsv($handle, [$row['movie_name'], $row['message_id_raw'], $row['date'], $row['video_path']]);
    }
    fclose($handle);

    return $data;
}

function get_cached_movies() {
    global $movie_cache;
    if (!empty($movie_cache) && (time() - $movie_cache['timestamp']) < CACHE_EXPIRY) {
        return $movie_cache['data'];
    }
    $movie_cache = [
        'data' => load_and_clean_csv(),
        'timestamp' => time()
    ];
    return $movie_cache['data'];
}

function load_movies_from_csv() {
    return get_cached_movies();
}

// ==============================
// Telegram API helpers
// ==============================
function apiRequest($method, $params = array(), $is_multipart = false) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;
    if ($is_multipart) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $res = curl_exec($ch);
        if ($res === false) {
            error_log("CURL ERROR: " . curl_error($ch));
        }
        curl_close($ch);
        return $res;
    } else {
        $options = array(
            'http' => array(
                'method' => 'POST',
                'content' => http_build_query($params),
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n"
            )
        );
        $context = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);
        if ($result === false) {
            error_log("apiRequest failed for method $method");
            return json_encode(['ok' => false, 'error' => 'Request failed']);
        }
        return $result;
    }
}

function sendMessage($chat_id, $text, $reply_markup = null, $parse_mode = null) {
    $data = [
        'chat_id' => $chat_id,
        'text' => $text
    ];
    if ($reply_markup) $data['reply_markup'] = json_encode($reply_markup);
    if ($parse_mode) $data['parse_mode'] = $parse_mode;
    return apiRequest('sendMessage', $data);
}

function copyMessage($chat_id, $from_chat_id, $message_id) {
    return apiRequest('copyMessage', [
        'chat_id' => $chat_id,
        'from_chat_id' => $from_chat_id,
        'message_id' => $message_id
    ]);
}

function forwardMessage($chat_id, $from_chat_id, $message_id) {
    return apiRequest('forwardMessage', [
        'chat_id' => $chat_id,
        'from_chat_id' => $from_chat_id,
        'message_id' => $message_id
    ]);
}

function answerCallbackQuery($callback_query_id, $text = null) {
    $data = ['callback_query_id' => $callback_query_id];
    if ($text) $data['text'] = $text;
    return apiRequest('answerCallbackQuery', $data);
}

// ==============================
// DELIVERY LOGIC - UPDATED (NO SENDER NAME)
// ==============================
function deliver_item_to_chat($chat_id, $item) {
    if (!empty($item['message_id']) && is_numeric($item['message_id'])) {
        // Use COPY instead of FORWARD to hide sender name
        copyMessage($chat_id, CHANNEL_ID, $item['message_id']);
        return true;
    }

    $text = "🎬 " . ($item['movie_name'] ?? 'Unknown') . "\n";
    $text .= "Ref: " . ($item['message_id_raw'] ?? 'N/A') . "\n";
    $text .= "Date: " . ($item['date'] ?? 'N/A') . "\n";
    sendMessage($chat_id, $text, null, 'HTML');
    return false;
}

// ==============================
// Pagination helpers
// ==============================
function get_all_movies_list() {
    $all = get_cached_movies();
    return $all;
}

function paginate_movies(array $all, int $page): array {
    $total = count($all);
    if ($total === 0) return ['total'=>0,'total_pages'=>1,'page'=>1,'slice'=>[]];
    $total_pages = (int)ceil($total / ITEMS_PER_PAGE);
    $page = max(1, min($page, $total_pages));
    $start = ($page - 1) * ITEMS_PER_PAGE;
    return [
        'total' => $total,
        'total_pages' => $total_pages,
        'page' => $page,
        'slice' => array_slice($all, $start, ITEMS_PER_PAGE)
    ];
}

function forward_page_movies($chat_id, array $page_movies) {
    $i = 1;
    foreach ($page_movies as $m) {
        $num = str_pad((string)$i, 2, '0', STR_PAD_LEFT);
        deliver_item_to_chat($chat_id, $m);
        usleep(300000);
        $i++;
    }
}

function build_totalupload_keyboard(int $page, int $total_pages): array {
    $kb = ['inline_keyboard' => []];
    $row = [];
    if ($page > 1) $row[] = ['text'=>'⏮️ Previous','callback_data'=>'tu_prev_'.($page-1)];
    if ($page < $total_pages) $row[] = ['text'=>'⏭️ Next','callback_data'=>'tu_next_'.($page+1)];
    if (!empty($row)) $kb['inline_keyboard'][] = $row;
    $kb['inline_keyboard'][] = [
        ['text'=>'🎬 View Movie','callback_data'=>'tu_view_'.$page],
        ['text'=>'🛑 Stop','callback_data'=>'tu_stop']
    ];
    return $kb;
}

// ==============================
// /totalupload controller
// ==============================
function totalupload_controller($chat_id, $page = 1) {
    $all = get_all_movies_list();
    if (empty($all)) {
        sendMessage($chat_id, "⚠️ Abhi tak koi movie record nahi mila. Baad me try karein.");
        return;
    }
    $pg = paginate_movies($all, (int)$page);
    forward_page_movies($chat_id, $pg['slice']);

    $title = "📊 Total Uploads\n";
    $title .= "• Page {$pg['page']}/{$pg['total_pages']}\n";
    $title .= "• Showing: " . count($pg['slice']) . " of {$pg['total']}\n\n";
    $title .= "➡️ Navigate with buttons below or tap View Movie to re-send current page.";

    $kb = build_totalupload_keyboard($pg['page'], $pg['total_pages']);
    sendMessage($chat_id, $title, $kb, 'HTML');
}

// ==============================
// Append movie
// ==============================
function append_movie($movie_name, $message_id_raw, $date = null, $video_path = '') {
    if (empty(trim($movie_name))) return;
    if ($date === null) $date = date('d-m-Y');
    $entry = [$movie_name, $message_id_raw, $date, $video_path];
    $handle = fopen(CSV_FILE, "a");
    fputcsv($handle, $entry);
    fclose($handle);

    global $movie_messages, $movie_cache, $waiting_users;
    $movie = strtolower(trim($movie_name));
    $item = [
        'movie_name' => $movie_name,
        'message_id_raw' => $message_id_raw,
        'date' => $date,
        'video_path' => $video_path,
        'message_id' => is_numeric($message_id_raw) ? intval($message_id_raw) : null
    ];
    if (!isset($movie_messages[$movie])) $movie_messages[$movie] = [];
    $movie_messages[$movie][] = $item;
    $movie_cache = [];

    foreach ($waiting_users as $query => $users) {
        if (strpos($movie, $query) !== false) {
            foreach ($users as $user_data) {
                list($user_chat_id, $user_id) = $user_data;
                deliver_item_to_chat($user_chat_id, $item);
                sendMessage($user_chat_id, "✅ '$query' ab channel me add ho gaya!");
            }
            unset($waiting_users[$query]);
        }
    }

    update_stats('total_movies', 1);
}

// ==============================
// Search & language & points
// ==============================
function smart_search($query) {
    global $movie_messages;
    $query_lower = strtolower(trim($query));
    $results = array();
    foreach ($movie_messages as $movie => $entries) {
        $score = 0;
        if ($movie == $query_lower) $score = 100;
        elseif (strpos($movie, $query_lower) !== false) $score = 80 - (strlen($movie) - strlen($query_lower));
        else {
            similar_text($movie, $query_lower, $similarity);
            if ($similarity > 60) $score = $similarity;
        }
        if ($score > 0) $results[$movie] = ['score'=>$score,'count'=>count($entries)];
    }
    uasort($results, function($a,$b){return $b['score'] - $a['score'];});
    return array_slice($results,0,10);
}

function detect_language($text) {
    $hindi_keywords = ['फिल्म','मूवी','डाउनलोड','हिंदी'];
    $english_keywords = ['movie','download','watch','print'];
    $h=0;$e=0;
    foreach ($hindi_keywords as $k) if (strpos($text,$k)!==false) $h++;
    foreach ($english_keywords as $k) if (stripos($text,$k)!==false) $e++;
    return $h>$e ? 'hindi' : 'english';
}

function send_multilingual_response($chat_id, $message_type, $language) {
    $responses = [
        'hindi'=>[
            'welcome' => "🎬 Boss, kis movie ki talash hai?",
            'found' => "✅ Mil gayi! Movie forward ho rahi hai...",
            'not_found' => "😔 Yeh movie abhi available nahi hai!\n\n📝 Aap ise request kar sakte hain: @EntertainmentTadka7860\n\n🔔 Jab bhi yeh add hogi, main automatically bhej dunga!",
            'searching' => "🔍 Dhoondh raha hoon... Zara wait karo"
        ],
        'english'=>[
            'welcome' => "🎬 Boss, which movie are you looking for?",
            'found' => "✅ Found it! Forwarding the movie...",
            'not_found' => "😔 This movie isn't available yet!\n\n📝 You can request it here: @EntertainmentTadka7860\n\n🔔 I'll send it automatically once it's added!",
            'searching' => "🔍 Searching... Please wait"
        ]
    ];
    sendMessage($chat_id, $responses[$language][$message_type]);
}

function update_user_points($user_id, $action) {
    $points_map = ['search'=>1,'found_movie'=>5,'daily_login'=>10];
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    if (!isset($users_data['users'][$user_id]['points'])) $users_data['users'][$user_id]['points'] = 0;
    $users_data['users'][$user_id]['points'] += ($points_map[$action] ?? 0);
    $users_data['users'][$user_id]['last_activity'] = date('Y-m-d H:i:s');
    file_put_contents(USERS_FILE, json_encode($users_data, JSON_PRETTY_PRINT));
}

function advanced_search($chat_id, $query, $user_id = null) {
    global $movie_messages, $waiting_users;
    $q = strtolower(trim($query));
    
    // 1. Minimum length check
    if (strlen($q) < 2) {
        sendMessage($chat_id, "❌ Please enter at least 2 characters for search");
        return;
    }
    
    // 2. STRONGER INVALID KEYWORDS FILTER
    $invalid_keywords = [
        // Technical words
        'vlc', 'audio', 'track', 'change', 'open', 'kar', 'me', 'hai',
        'how', 'what', 'problem', 'issue', 'help', 'solution', 'fix',
        'error', 'not working', 'download', 'play', 'video', 'sound',
        'subtitle', 'quality', 'hd', 'full', 'part', 'scene',
        
        // Common group chat words
        'hi', 'hello', 'hey', 'good', 'morning', 'night', 'bye',
        'thanks', 'thank', 'ok', 'okay', 'yes', 'no', 'maybe',
        'who', 'when', 'where', 'why', 'how', 'can', 'should',
        
        // Hindi common words
        'kaise', 'kya', 'kahan', 'kab', 'kyun', 'kon', 'kisne',
        'hai', 'hain', 'ho', 'raha', 'raha', 'rah', 'tha', 'thi',
        'mere', 'apne', 'tumhare', 'hamare', 'sab', 'log', 'group'
    ];
    
    // 3. SMART WORD ANALYSIS
    $query_words = explode(' ', $q);
    $total_words = count($query_words);
    
    $invalid_count = 0;
    foreach ($query_words as $word) {
        if (in_array($word, $invalid_keywords)) {
            $invalid_count++;
        }
    }
    
    // 4. STRICTER THRESHOLD - 50% se zyada invalid words ho toh block
    if ($invalid_count > 0 && ($invalid_count / $total_words) > 0.5) {
        $help_msg = "🎬 Please enter a movie name!\n\n";
        $help_msg .= "🔍 Examples of valid movie names:\n";
        $help_msg .= "• kgf\n• pushpa\n• avengers\n• hindi movie\n• spider-man\n\n";
        $help_msg .= "❌ Technical queries like 'vlc', 'audio track', etc. are not movie names.\n\n";
        $help_msg .= "📢 Join: @threater_print_movies\n";
        $help_msg .= "💬 Help: @EntertainmentTadka7860";
        sendMessage($chat_id, $help_msg, null, 'HTML');
        return;
    }
    
    // 5. MOVIE NAME PATTERN VALIDATION
    $movie_pattern = '/^[a-zA-Z0-9\s\-\.\,\&\+\(\)\:\'\"]+$/';
    if (!preg_match($movie_pattern, $query)) {
        sendMessage($chat_id, "❌ Invalid movie name format. Only letters, numbers, and basic punctuation allowed.");
        return;
    }
    
    $found = smart_search($q);
    if (!empty($found)) {
        $msg = "🔍 Found " . count($found) . " movies for '$query':\n\n";
        $i=1;
        foreach ($found as $movie=>$data) {
            $msg .= "$i. $movie (" . $data['count'] . " entries)\n";
            $i++; if ($i>15) break;
        }
        sendMessage($chat_id, $msg);
        $keyboard = ['inline_keyboard'=>[]];
        foreach (array_slice(array_keys($found),0,5) as $movie) {
            $keyboard['inline_keyboard'][] = [[ 'text'=>"🎬 ".ucwords($movie), 'callback_data'=>$movie ]];
        }
        sendMessage($chat_id, "🚀 Top matches:", $keyboard);
        if ($user_id) update_user_points($user_id, 'found_movie');
    } else {
        $lang = detect_language($query);
        send_multilingual_response($chat_id, 'not_found', $lang);
        if (!isset($waiting_users[$q])) $waiting_users[$q] = [];
        $waiting_users[$q][] = [$chat_id, $user_id ?? $chat_id];
    }
    update_stats('total_searches', 1);
    if ($user_id) update_user_points($user_id, 'search');
}

// ==============================
// Admin stats
// ==============================
function admin_stats($chat_id) {
    $stats = get_stats();
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    $total_users = count($users_data['users'] ?? []);
    $msg = "📊 Bot Statistics\n\n";
    $msg .= "🎬 Total Movies: " . ($stats['total_movies'] ?? 0) . "\n";
    $msg .= "👥 Total Users: " . $total_users . "\n";
    $msg .= "🔍 Total Searches: " . ($stats['total_searches'] ?? 0) . "\n";
    $msg .= "🕒 Last Updated: " . ($stats['last_updated'] ?? 'N/A') . "\n\n";
    $csv_data = load_and_clean_csv();
    $recent = array_slice($csv_data, -5);
    $msg .= "📈 Recent Uploads:\n";
    foreach ($recent as $r) $msg .= "• " . $r['movie_name'] . " (" . $r['date'] . ")\n";
    sendMessage($chat_id, $msg, null, 'HTML');
}

// ==============================
// Show CSV Data
// ==============================
function show_csv_data($chat_id, $show_all = false) {
    if (!file_exists(CSV_FILE)) {
        sendMessage($chat_id, "❌ CSV file not found.");
        return;
    }
    
    $handle = fopen(CSV_FILE, "r");
    if ($handle === FALSE) {
        sendMessage($chat_id, "❌ Error opening CSV file.");
        return;
    }
    
    fgetcsv($handle);
    
    $movies = [];
    while (($row = fgetcsv($handle)) !== FALSE) {
        if (count($row) >= 3) {
            $movies[] = $row;
        }
    }
    fclose($handle);
    
    if (empty($movies)) {
        sendMessage($chat_id, "📊 CSV file is empty.");
        return;
    }
    
    $movies = array_reverse($movies);
    
    $limit = $show_all ? count($movies) : 10;
    $movies = array_slice($movies, 0, $limit);
    
    $message = "📊 CSV Movie Database\n\n";
    $message .= "📁 Total Movies: " . count($movies) . "\n";
    if (!$show_all) {
        $message .= "🔍 Showing latest 10 entries\n";
        $message .= "📋 Use '/checkcsv all' for full list\n\n";
    } else {
        $message .= "📋 Full database listing\n\n";
    }
    
    $i = 1;
    foreach ($movies as $movie) {
        $movie_name = $movie[0] ?? 'N/A';
        $message_id = $movie[1] ?? 'N/A';
        $date = $movie[2] ?? 'N/A';
        
        $message .= "$i. 🎬 " . htmlspecialchars($movie_name) . "\n";
        $message .= "   📝 ID: $message_id\n";
        $message .= "   📅 Date: $date\n\n";
        
        $i++;
        
        if (strlen($message) > 3000) {
            sendMessage($chat_id, $message, null, 'HTML');
            $message = "📊 Continuing...\n\n";
        }
    }
    
    $message .= "💾 File: " . CSV_FILE . "\n";
    $message .= "⏰ Last Updated: " . date('Y-m-d H:i:s', filemtime(CSV_FILE));
    
    sendMessage($chat_id, $message, null, 'HTML');
}

// ==============================
// Backups & daily digest
// ==============================
function auto_backup() {
    $backup_files = [CSV_FILE, USERS_FILE, STATS_FILE];
    $backup_dir = BACKUP_DIR . date('Y-m-d');
    if (!file_exists($backup_dir)) @mkdir($backup_dir, 0777, true);
    foreach ($backup_files as $f) if (file_exists($f)) copy($f, $backup_dir . '/' . basename($f) . '.bak');
    $old = glob(BACKUP_DIR . '*', GLOB_ONLYDIR);
    if (count($old) > 7) {
        usort($old, function($a,$b){return filemtime($a)-filemtime($b);});
        foreach (array_slice($old, 0, count($old)-7) as $d) {
            $files = glob($d . '/*'); foreach ($files as $ff) @unlink($ff); @rmdir($d);
        }
    }
}

function send_daily_digest() {
    $yesterday = date('d-m-Y', strtotime('-1 day'));
    $y_movies = [];
    $h = fopen(CSV_FILE, "r");
    if ($h !== FALSE) {
        fgetcsv($h);
        while (($r = fgetcsv($h)) !== FALSE) {
            if (count($r)>=3 && $r[2] == $yesterday) $y_movies[] = $r[0];
        }
        fclose($h);
    }
    if (!empty($y_movies)) {
        $users_data = json_decode(file_get_contents(USERS_FILE), true);
        foreach ($users_data['users'] as $uid => $ud) {
            $msg = "📅 Daily Movie Digest\n\n";
            $msg .= "📢 Join our channel: @threater_print_movies\n\n";
            $msg .= "🎬 Yesterday's Uploads (" . $yesterday . "):\n";
            foreach (array_slice($y_movies,0,10) as $m) $msg .= "• " . $m . "\n";
            if (count($y_movies)>10) $msg .= "• ... and " . (count($y_movies)-10) . " more\n";
            $msg .= "\n🔥 Total: " . count($y_movies) . " movies";
            sendMessage($uid, $msg, null, 'HTML');
        }
    }
}

// ==============================
// Other commands
// ==============================
function check_date($chat_id) {
    if (!file_exists(CSV_FILE)) { sendMessage($chat_id, "⚠️ Abhi tak koi data save nahi hua."); return; }
    $date_counts = [];
    $h=fopen(CSV_FILE,'r'); if ($h!==FALSE) {
        fgetcsv($h);
        while (($r=fgetcsv($h))!==FALSE) if (count($r)>=3) { $d=$r[2]; if(!isset($date_counts[$d])) $date_counts[$d]=0; $date_counts[$d]++; }
        fclose($h);
    }
    krsort($date_counts);
    $msg = "📅 Movies Upload Record\n\n";
    $total_days=0; $total_movies=0;
    foreach ($date_counts as $date=>$count) { $msg .= "➡️ $date: $count movies\n"; $total_days++; $total_movies += $count; }
    $msg .= "\n📊 Summary:\n";
    $msg .= "• Total Days: $total_days\n• Total Movies: $total_movies\n• Average per day: " . round($total_movies / max(1,$total_days),2);
    sendMessage($chat_id,$msg,null,'HTML');
}

function total_uploads($chat_id, $page = 1) {
    totalupload_controller($chat_id, $page);
}

function test_csv($chat_id) {
    if (!file_exists(CSV_FILE)) { sendMessage($chat_id,"⚠️ CSV file not found."); return; }
    $h = fopen(CSV_FILE,'r');
    if ($h!==FALSE) {
        fgetcsv($h);
        $i=1; $msg="";
        while (($r=fgetcsv($h))!==FALSE) {
            if (count($r)>=3) {
                $line = "$i. {$r[0]} | ID/Ref: {$r[1]} | Date: {$r[2]}\n";
                if (strlen($msg) + strlen($line) > 4000) { sendMessage($chat_id,$msg); $msg=""; }
                $msg .= $line; $i++;
            }
        }
        fclose($h);
        if (!empty($msg)) sendMessage($chat_id,$msg);
    }
}

// ==============================
// Group Message Filter
// ==============================
function is_valid_movie_query($text) {
    $text = strtolower(trim($text));
    
    // Skip commands
    if (strpos($text, '/') === 0) {
        return true; // Commands allow karo
    }
    
    // Skip very short messages
    if (strlen($text) < 3) {
        return false;
    }
    
    // Common group chat phrases block karo
    $invalid_phrases = [
        'good morning', 'good night', 'hello', 'hi ', 'hey ', 'thank you', 'thanks',
        'welcome', 'bye', 'see you', 'ok ', 'okay', 'yes', 'no', 'maybe',
        'how are you', 'whats up', 'anyone', 'someone', 'everyone',
        'problem', 'issue', 'help', 'question', 'doubt', 'query'
    ];
    
    foreach ($invalid_phrases as $phrase) {
        if (strpos($text, $phrase) !== false) {
            return false;
        }
    }
    
    // Movie-like patterns allow karo
    $movie_patterns = [
        'movie', 'film', 'video', 'download', 'watch', 'hd', 'full', 'part',
        'series', 'episode', 'season', 'bollywood', 'hollywood'
    ];
    
    foreach ($movie_patterns as $pattern) {
        if (strpos($text, $pattern) !== false) {
            return true;
        }
    }
    
    // Agar koi specific movie jaisa lagta hai (3+ characters, spaces, numbers allowed)
    if (preg_match('/^[a-zA-Z0-9\s\-\.\,]{3,}$/', $text)) {
        return true;
    }
    
    return false;
}

// ==============================
// Render.com Specific Functions
// ==============================
function render_health_check() {
    $stats = get_stats();
    return [
        'status' => 'healthy',
        'timestamp' => date('Y-m-d H:i:s'),
        'bot_name' => 'Entertainment Tadka',
        'stats' => [
            'total_movies' => $stats['total_movies'] ?? 0,
            'total_users' => count(json_decode(file_get_contents(USERS_FILE), true)['users'] ?? []),
            'total_searches' => $stats['total_searches'] ?? 0
        ],
        'files' => [
            'csv' => file_exists(CSV_FILE) ? 'exists' : 'missing',
            'users' => file_exists(USERS_FILE) ? 'exists' : 'missing',
            'stats' => file_exists(STATS_FILE) ? 'exists' : 'missing',
            'backups' => file_exists(BACKUP_DIR) ? 'exists' : 'missing'
        ],
        'environment' => [
            'bot_token_set' => !empty(BOT_TOKEN),
            'channel_id_set' => !empty(CHANNEL_ID),
            'base_url' => BASE_URL
        ]
    ];
}

function render_setup_webhook() {
    $webhook_url = BASE_URL . $_SERVER['SCRIPT_NAME'];
    $result = json_decode(apiRequest('setWebhook', ['url' => $webhook_url]), true);
    
    return [
        'action' => 'webhook_setup',
        'timestamp' => date('Y-m-d H:i:s'),
        'webhook_url' => $webhook_url,
        'result' => $result['ok'] ?? false,
        'description' => $result['description'] ?? 'Unknown error',
        'bot_info' => json_decode(apiRequest('getMe'), true)
    ];
}

function render_backup_files() {
    $backup_dir = BACKUP_DIR . date('Y-m-d_H-i-s');
    if (!file_exists($backup_dir)) @mkdir($backup_dir, 0777, true);
    
    $files = [CSV_FILE, USERS_FILE, STATS_FILE];
    $backed_up = [];
    
    foreach ($files as $file) {
        if (file_exists($file)) {
            $dest = $backup_dir . '/' . basename($file) . '.bak';
            copy($file, $dest);
            $backed_up[] = [
                'file' => $file,
                'backup' => $dest,
                'size' => filesize($file)
            ];
        }
    }
    
    return [
        'backup_created' => date('Y-m-d H:i:s'),
        'backup_dir' => $backup_dir,
        'files' => $backed_up,
        'total_size' => array_sum(array_column($backed_up, 'size'))
    ];
}

// ==============================
// Main update processing (webhook)
// ==============================

// Special endpoints for Render.com
if (isset($_GET['health'])) {
    // Health check endpoint for Render.com monitoring
    header('Content-Type: application/json');
    echo json_encode(render_health_check(), JSON_PRETTY_PRINT);
    exit;
}

if (isset($_GET['setup'])) {
    // Auto setup webhook for Render.com
    header('Content-Type: application/json');
    echo json_encode(render_setup_webhook(), JSON_PRETTY_PRINT);
    exit;
}

if (isset($_GET['backup'])) {
    // Create manual backup
    header('Content-Type: application/json');
    echo json_encode(render_backup_files(), JSON_PRETTY_PRINT);
    exit;
}

if (isset($_GET['admin_panel'])) {
    // HTML admin panel
    $health = render_health_check();
    $stats = get_stats();
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    
    echo '<!DOCTYPE html>
    <html>
    <head>
        <title>🎬 Entertainment Tadka - Admin Panel</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
            body { font-family: Arial, sans-serif; max-width: 1000px; margin: 0 auto; padding: 20px; background: #f5f5f5; }
            .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 10px; margin-bottom: 20px; }
            .card { background: white; border-radius: 8px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
            .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; }
            .stat-box { background: #f8f9fa; border-left: 4px solid #667eea; padding: 15px; }
            .btn { display: inline-block; background: #667eea; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin: 5px; }
            .btn-success { background: #28a745; }
            .btn-warning { background: #ffc107; }
            .btn-danger { background: #dc3545; }
            table { width: 100%; border-collapse: collapse; margin-top: 15px; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            th { background: #f2f2f2; }
            .status-healthy { color: #28a745; font-weight: bold; }
            .status-error { color: #dc3545; font-weight: bold; }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>🎬 Entertainment Tadka Bot</h1>
            <p>Admin Panel • Hosted on Render.com</p>
        </div>
        
        <div class="card">
            <h2>📊 System Status</h2>
            <div class="stats-grid">
                <div class="stat-box">
                    <h3>Bot Status</h3>
                    <p class="status-healthy">✅ Running</p>
                    <p>Uptime: ' . date('Y-m-d H:i:s') . '</p>
                </div>
                <div class="stat-box">
                    <h3>Total Movies</h3>
                    <p>' . ($stats['total_movies'] ?? 0) . '</p>
                </div>
                <div class="stat-box">
                    <h3>Total Users</h3>
                    <p>' . count($users_data['users'] ?? []) . '</p>
                </div>
                <div class="stat-box">
                    <h3>Total Searches</h3>
                    <p>' . ($stats['total_searches'] ?? 0) . '</p>
                </div>
            </div>
        </div>
        
        <div class="card">
            <h2>⚡ Quick Actions</h2>
            <p>
                <a href="?setup" class="btn">🔗 Setup Webhook</a>
                <a href="?health" class="btn btn-success" target="_blank">❤️ Health Check</a>
                <a href="?backup" class="btn btn-warning">💾 Create Backup</a>
                <a href="' . BASE_URL . '" class="btn">🏠 Back to Home</a>
            </p>
        </div>
        
        <div class="card">
            <h2>📁 File Status</h2>
            <table>
                <tr>
                    <th>File</th>
                    <th>Status</th>
                    <th>Size</th>
                    <th>Last Modified</th>
                </tr>';
    
    $files = [
        'movies.csv' => CSV_FILE,
        'users.json' => USERS_FILE,
        'bot_stats.json' => STATS_FILE,
        'error.log' => 'error.log'
    ];
    
    foreach ($files as $name => $path) {
        if (file_exists($path)) {
            $size = filesize($path);
            $modified = date('Y-m-d H:i:s', filemtime($path));
            echo "<tr>
                    <td>{$name}</td>
                    <td><span class='status-healthy'>✅ Exists</span></td>
                    <td>" . ($size > 1024 ? round($size/1024, 2) . ' KB' : $size . ' bytes') . "</td>
                    <td>{$modified}</td>
                  </tr>";
        } else {
            echo "<tr>
                    <td>{$name}</td>
                    <td><span class='status-error'>❌ Missing</span></td>
                    <td>-</td>
                    <td>-</td>
                  </tr>";
        }
    }
    
    echo '</table>
        </div>
        
        <div class="card">
            <h2>🔧 Recent Activity</h2>
            <pre style="background: #f8f9fa; padding: 15px; border-radius: 5px; max-height: 300px; overflow: auto;">';
    
    if (file_exists('error.log')) {
        $log_content = file_get_contents('error.log');
        echo htmlspecialchars(substr($log_content, -5000)); // Last 5KB
    } else {
        echo "No log file found.";
    }
    
    echo '</pre>
        </div>
        
        <footer style="text-align: center; margin-top: 30px; color: #666;">
            <p>🎬 Entertainment Tadka Bot • Render.com Deployment • ' . date('Y') . '</p>
        </footer>
    </body>
    </html>';
    exit;
}

// Main Telegram update processing
$update = json_decode(file_get_contents('php://input'), true);
if ($update) {
    // Log incoming update
    file_put_contents('error.log', date('Y-m-d H:i:s') . " - Update received: " . json_encode($update) . "\n", FILE_APPEND);
    
    get_cached_movies();

    // Channel post handling
    if (isset($update['channel_post'])) {
        $message = $update['channel_post'];
        $message_id = $message['message_id'];
        $chat_id = $message['chat']['id'];

        if ($chat_id == CHANNEL_ID) {
            $text = '';

            if (isset($message['caption'])) {
                $text = $message['caption'];
            }
            elseif (isset($message['text'])) {
                $text = $message['text'];
            }
            elseif (isset($message['document'])) {
                $text = $message['document']['file_name'];
            }
            else {
                $text = 'Uploaded Media - ' . date('d-m-Y H:i');
            }

            if (!empty(trim($text))) {
                append_movie($text, $message_id, date('d-m-Y'), '');
            }
        }
    }

    if (isset($update['message'])) {
        $message = $update['message'];
        $chat_id = $message['chat']['id'];
        $user_id = $message['from']['id'];
        $text = isset($message['text']) ? $message['text'] : '';
        $chat_type = $message['chat']['type'] ?? 'private';

        // GROUP MESSAGE FILTERING
        if ($chat_type !== 'private') {
            // Group mein sirf valid movie queries allow karo
            if (strpos($text, '/') === 0) {
                // Commands allow karo
            } else {
                // Random group messages check karo
                if (!is_valid_movie_query($text)) {
                    // Invalid message hai, ignore karo
                    return;
                }
            }
        }

        $users_data = json_decode(file_get_contents(USERS_FILE), true);
        if (!isset($users_data['users'][$user_id])) {
            $users_data['users'][$user_id] = [
                'first_name' => $message['from']['first_name'] ?? '',
                'last_name' => $message['from']['last_name'] ?? '',
                'username' => $message['from']['username'] ?? '',
                'joined' => date('Y-m-d H:i:s'),
                'last_active' => date('Y-m-d H:i:s'),
                'points' => 0
            ];
            $users_data['total_requests'] = ($users_data['total_requests'] ?? 0) + 1;
            file_put_contents(USERS_FILE, json_encode($users_data, JSON_PRETTY_PRINT));
            update_stats('total_users', 1);
        }
        $users_data['users'][$user_id]['last_active'] = date('Y-m-d H:i:s');
        file_put_contents(USERS_FILE, json_encode($users_data, JSON_PRETTY_PRINT));

        if (strpos($text, '/') === 0) {
            $parts = explode(' ', $text);
            $command = $parts[0];
            if ($command == '/checkdate') check_date($chat_id);
            elseif ($command == '/totalupload' || $command == '/totaluploads' || $command == '/TOTALUPLOAD') totalupload_controller($chat_id, 1);
            elseif ($command == '/testcsv') test_csv($chat_id);
            elseif ($command == '/checkcsv') {
                $show_all = (isset($parts[1]) && strtolower($parts[1]) == 'all');
                show_csv_data($chat_id, $show_all);
            }
            elseif ($command == '/start') {
                $welcome = "🎬 Welcome to Entertainment Tadka!\n\n";
                $welcome .= "📢 How to use this bot:\n";
                $welcome .= "• Simply type any movie name\n";
                $welcome .= "• Use English or Hindi\n";
                $welcome .= "• Partial names also work\n\n";
                $welcome .= "🔍 Examples:\n";
                $welcome .= "• kgf\n• pushpa\n• avengers\n• hindi movie\n• spider-man\n\n";
                $welcome .= "❌ Don't type:\n";
                $welcome .= "• Technical questions\n";
                $welcome .= "• Player instructions\n";
                $welcome .= "• Non-movie queries\n\n";
                $welcome .= "📢 Join: @threater_print_movies\n";
                $welcome .= "💬 Request/Help: @EntertainmentTadka7860";
                sendMessage($chat_id, $welcome, null, 'HTML');
                update_user_points($user_id, 'daily_login');
            }
            elseif ($command == '/stats' && $user_id == 1080317415) admin_stats($chat_id);
            elseif ($command == '/help') {
                $help = "🤖 Entertainment Tadka Bot\n\n📢 Join our channel: @threater_print_movies\n\n📋 Available Commands:\n/start, /checkdate, /totalupload, /testcsv, /checkcsv, /help\n\n🔍 Simply type any movie name to search!";
                sendMessage($chat_id, $help, null, 'HTML');
            }
        } else if (!empty(trim($text))) {
            $lang = detect_language($text);
            send_multilingual_response($chat_id, 'searching', $lang);
            advanced_search($chat_id, $text, $user_id);
        }
    }

    if (isset($update['callback_query'])) {
        $query = $update['callback_query'];
        $message = $query['message'];
        $chat_id = $message['chat']['id'];
        $data = $query['data'];

        global $movie_messages;
        $movie_lower = strtolower($data);
        if (isset($movie_messages[$movie_lower])) {
            $entries = $movie_messages[$movie_lower];
            $cnt = 0;
            foreach ($entries as $entry) {
                deliver_item_to_chat($chat_id, $entry);
                usleep(200000);
                $cnt++;
            }
            sendMessage($chat_id, "✅ '$data' ke $cnt messages forward/send ho gaye!\n\n📢 Join our channel: @threater_print_movies");
            answerCallbackQuery($query['id'], "🎬 $cnt items sent!");
        }
        elseif (strpos($data, 'tu_prev_') === 0) {
            $page = (int)str_replace('tu_prev_','', $data);
            totalupload_controller($chat_id, $page);
            answerCallbackQuery($query['id'], "Page $page");
        }
        elseif (strpos($data, 'tu_next_') === 0) {
            $page = (int)str_replace('tu_next_','', $data);
            totalupload_controller($chat_id, $page);
            answerCallbackQuery($query['id'], "Page $page");
        }
        elseif (strpos($data, 'tu_view_') === 0) {
            $page = (int)str_replace('tu_view_','', $data);
            $all = get_all_movies_list();
            $pg = paginate_movies($all, $page);
            forward_page_movies($chat_id, $pg['slice']);
            answerCallbackQuery($query['id'], "Re-sent current page movies");
        }
        elseif ($data === 'tu_stop') {
            sendMessage($chat_id, "✅ Pagination stopped. Type /totalupload to start again.");
            answerCallbackQuery($query['id'], "Stopped");
        }
        elseif (strpos($data, 'uploads_page_') === 0) {
            $page = intval(str_replace('uploads_page_', '', $data));
            total_uploads($chat_id, $page);
            answerCallbackQuery($query['id'], "Page $page loaded");
        }
        elseif ($data == 'view_current_movie') {
            $message_text = $query['message']['text'] ?? '';
            if (preg_match('/Page (\d+)\/(\d+)/', $message_text, $m)) {
                $current_page = (int)$m[1];
                $all = get_all_movies_list();
                $items_per_page = ITEMS_PER_PAGE;
                $start = ($current_page - 1) * $items_per_page;
                $current_movies = array_slice($all, $start, $items_per_page);
                $forwarded = 0;
                foreach ($current_movies as $movie) {
                    if (deliver_item_to_chat($chat_id, $movie)) $forwarded++;
                    usleep(500000);
                }
                if ($forwarded > 0) sendMessage($chat_id, "✅ Current page ki $forwarded movies forward ho gayi!\n\n📢 Join: @threater_print_movies");
                else sendMessage($chat_id, "❌ Kuch technical issue hai. Baad mein try karein.");
            }
            answerCallbackQuery($query['id'], "Movies forwarding...");
        }
        elseif ($data == 'uploads_stop') {
            sendMessage($chat_id, "✅ Pagination stopped. Type /totaluploads again to restart.");
            answerCallbackQuery($query['id'], "Pagination stopped");
        }
        else {
            sendMessage($chat_id, "❌ Movie not found: " . $data);
            answerCallbackQuery($query['id'], "❌ Movie not available");
        }
    }

    // Scheduled tasks
    $current_hour = date('H:i');
    if ($current_hour == '00:00') auto_backup();
    if ($current_hour == '08:00') send_daily_digest();
}

// Manual save test function
if (isset($_GET['test_save'])) {
    function manual_save_to_csv($movie_name, $message_id) {
        $entry = [$movie_name, $message_id, date('d-m-Y'), ''];
        $handle = fopen(CSV_FILE, "a");
        if ($handle !== FALSE) {
            fputcsv($handle, $entry);
            fclose($handle);
            @chmod(CSV_FILE, 0666);
            return true;
        }
        return false;
    }
    
    manual_save_to_csv("Metro In Dino (2025)", 1924);
    manual_save_to_csv("Metro In Dino 2025 WebRip 480p x265 HEVC 10bit Hindi ESubs", 1925);
    manual_save_to_csv("Metro In Dino (2025) Hindi 720p HEVC HDRip x265 AAC 5.1 ESubs", 1926);
    manual_save_to_csv("Metro In Dino (2025) Hindi 720p HDRip x264 AAC 5.1 ESubs", 1927);
    manual_save_to_csv("Metro In Dino (2025) Hindi 1080p HDRip x264 AAC 5.1 ESubs", 1928);
    
    echo "✅ All 5 movies manually save ho gayi!<br>";
    echo "📊 <a href='?check_csv=1'>Check CSV</a> | ";
    echo "<a href='?admin_panel'>Admin Panel</a> | ";
    echo "<a href='?setup'>Setup Webhook</a>";
    exit;
}

// Check CSV content
if (isset($_GET['check_csv'])) {
    echo "<h3>CSV Content:</h3>";
    if (file_exists(CSV_FILE)) {
        $lines = file(CSV_FILE);
        foreach ($lines as $line) {
            echo htmlspecialchars($line) . "<br>";
        }
    } else {
        echo "❌ CSV file not found!";
    }
    exit;
}

// CLI or manual webhook setup
if (php_sapi_name() === 'cli' || isset($_GET['setwebhook'])) {
    $webhook_url = BASE_URL . $_SERVER['SCRIPT_NAME'];
    $result = apiRequest('setWebhook', ['url' => $webhook_url]);
    echo "<h1>Webhook Setup</h1>";
    echo "<p>Result: " . htmlspecialchars($result) . "</p>";
    echo "<p>Webhook URL: " . htmlspecialchars($webhook_url) . "</p>";
    $bot_info = json_decode(apiRequest('getMe'), true);
    if ($bot_info && isset($bot_info['ok']) && $bot_info['ok']) {
        echo "<h2>Bot Info</h2>";
        echo "<p>Name: " . htmlspecialchars($bot_info['result']['first_name']) . "</p>";
        echo "<p>Username: @" . htmlspecialchars($bot_info['result']['username']) . "</p>";
        echo "<p>Channel: @threater_print_movies</p>";
    }
    exit;
}

// Default homepage when accessed via browser
if (!isset($update) || !$update) {
    $stats = get_stats();
    $users_data = json_decode(file_get_contents(USERS_FILE), true);
    
    echo '<!DOCTYPE html>
    <html>
    <head>
        <title>🎬 Entertainment Tadka Bot</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
            body { 
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; 
                max-width: 800px; 
                margin: 0 auto; 
                padding: 20px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                min-height: 100vh;
            }
            .container { 
                background: rgba(255, 255, 255, 0.95); 
                color: #333;
                padding: 30px; 
                border-radius: 15px; 
                box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            }
            .header { text-align: center; margin-bottom: 30px; }
            .stats-grid { 
                display: grid; 
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); 
                gap: 15px; 
                margin: 20px 0; 
            }
            .stat-card { 
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
                color: white; 
                padding: 20px; 
                border-radius: 10px; 
                text-align: center;
                box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            }
            .btn { 
                display: inline-block; 
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); 
                color: white; 
                padding: 12px 24px; 
                text-decoration: none; 
                border-radius: 8px; 
                margin: 10px 5px;
                font-weight: bold;
                box-shadow: 0 4px 6px rgba(0,0,0,0.1);
                transition: transform 0.2s;
            }
            .btn:hover { transform: translateY(-2px); }
            .btn-success { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); }
            .btn-warning { background: linear-gradient(135deg, #ffc107 0%, #ff6b6b 100%); }
            .command { 
                background: #f8f9fa; 
                padding: 10px; 
                border-radius: 5px; 
                font-family: monospace; 
                margin: 5px 0;
                border-left: 4px solid #667eea;
            }
            footer { 
                text-align: center; 
                margin-top: 30px; 
                color: rgba(255,255,255,0.8);
                font-size: 0.9em;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1 style="color: #667eea; margin-top: 0;">🎬 Entertainment Tadka Bot</h1>
                <p style="color: #666;">Telegram Movie Search Bot • Hosted on Render.com</p>
            </div>
            
            <div style="text-align: center; margin: 20px 0;">
                <div class="status" style="background: #28a745; color: white; padding: 10px; border-radius: 5px; display: inline-block;">
                    ✅ Bot is Running
                </div>
            </div>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <h3 style="margin-top: 0;">🎬 Movies</h3>
                    <p style="font-size: 2em; font-weight: bold;">' . ($stats['total_movies'] ?? 0) . '</p>
                </div>
                <div class="stat-card">
                    <h3 style="margin-top: 0;">👥 Users</h3>
                    <p style="font-size: 2em; font-weight: bold;">' . count($users_data['users'] ?? []) . '</p>
                </div>
                <div class="stat-card">
                    <h3 style="margin-top: 0;">🔍 Searches</h3>
                    <p style="font-size: 2em; font-weight: bold;">' . ($stats['total_searches'] ?? 0) . '</p>
                </div>
                <div class="stat-card">
                    <h3 style="margin-top: 0;">🕒 Updated</h3>
                    <p style="font-size: 1em;">' . ($stats['last_updated'] ?? 'N/A') . '</p>
                </div>
            </div>
            
            <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin: 20px 0;">
                <h2 style="color: #667eea; margin-top: 0;">🚀 Setup Instructions</h2>
                <ol style="line-height: 1.6;">
                    <li><strong>Step 1:</strong> Click <a href="?setup" style="color: #667eea; font-weight: bold;">Setup Webhook</a></li>
                    <li><strong>Step 2:</strong> Visit Telegram Bot: <a href="https://t.me/EntertainmentTadka7860_bot" target="_blank" style="color: #667eea;">@EntertainmentTadka7860_bot</a></li>
                    <li><strong>Step 3:</strong> Type any movie name to search!</li>
                </ol>
            </div>
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="?setup" class="btn">🔗 Setup Webhook</a>
                <a href="?health" class="btn btn-success" target="_blank">❤️ Health Check</a>
                <a href="?admin_panel" class="btn btn-warning">⚡ Admin Panel</a>
                <a href="https://t.me/threater_print_movies" class="btn" target="_blank">📢 Telegram Channel</a>
            </div>
            
            <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin: 20px 0;">
                <h2 style="color: #667eea; margin-top: 0;">📋 Available Commands</h2>
                <div class="command">/start - Welcome message</div>
                <div class="command">/checkdate - Date-wise stats</div>
                <div class="command">/totalupload - Upload statistics</div>
                <div class="command">/testcsv - View all movies</div>
                <div class="command">/checkcsv - Check CSV data</div>
                <div class="command">/help - Help message</div>
            </div>
            
            <div style="background: #f8f9fa; padding: 20px; border-radius: 10px; margin: 20px 0;">
                <h2 style="color: #667eea; margin-top: 0;">🔧 Quick Actions</h2>
                <p>
                    <a href="?backup" style="color: #667eea; text-decoration: none; margin-right: 15px;">💾 Create Backup</a>
                    <a href="?check_csv" style="color: #667eea; text-decoration: none; margin-right: 15px;">📊 View CSV</a>
                    <a href="?test_save" style="color: #667eea; text-decoration: none;">🎬 Test Save</a>
                </p>
            </div>
        </div>
        
        <footer>
            <p>🎬 Entertainment Tadka Bot • Powered by PHP & Telegram API</p>
            <p>📱 <a href="https://t.me/threater_print_movies" style="color: white;" target="_blank">@threater_print_movies</a> • 💬 <a href="https://t.me/EntertainmentTadka7860" style="color: white;" target="_blank">@EntertainmentTadka7860</a></p>
            <p>🚀 Deployed on Render.com • ' . date('Y') . '</p>
        </footer>
    </body>
    </html>';
}
?>
