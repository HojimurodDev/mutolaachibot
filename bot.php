<?php
declare(strict_types=1);

/*
 * MutolaachiBot
 * PHP 8.1+ / Telegram Bot API / Webhook / JSON database
 *
 * Environment:
 * BOT_TOKEN   - Telegram bot token
 * ADMIN_IDS   - comma-separated Telegram IDs
 * BOT_USERNAME - bot username without @
 */

const DATA_DIR = __DIR__ . '/data';
const UPLOAD_DIR = __DIR__ . '/uploads';

date_default_timezone_set('Asia/Tashkent');

function envv(string $key, string $default = ''): string {
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

$BOT_TOKEN = envv('BOT_TOKEN');
$ADMIN_IDS = array_values(array_filter(array_map('trim', explode(',', envv('ADMIN_IDS')))));
$BOT_USERNAME = ltrim(envv('BOT_USERNAME'), '@');

if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0775, true);
foreach (['pdf','audio','covers'] as $dir) {
    if (!is_dir(UPLOAD_DIR . '/' . $dir)) @mkdir(UPLOAD_DIR . '/' . $dir, 0775, true);
}

function db(string $name, array $default = []): array {
    $file = DATA_DIR . '/' . $name . '.json';
    if (!file_exists($file)) {
        file_put_contents($file, json_encode($default, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT), LOCK_EX);
        return $default;
    }
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : $default;
}

function save_db(string $name, array $data): void {
    file_put_contents(
        DATA_DIR . '/' . $name . '.json',
        json_encode($data, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),
        LOCK_EX
    );
}

function tg(string $method, array $params = []): array {
    global $BOT_TOKEN;
    if (!$BOT_TOKEN) return ['ok'=>false, 'description'=>'BOT_TOKEN is missing'];

    $ch = curl_init("https://api.telegram.org/bot{$BOT_TOKEN}/{$method}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $json = json_decode((string)$response, true);
    return is_array($json) ? $json : ['ok'=>false, 'description'=>'Invalid Telegram response'];
}

function esc(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function idstr($id): string {
    return (string)$id;
}

function isAdmin($id): bool {
    global $ADMIN_IDS;
    return in_array((string)$id, $ADMIN_IDS, true);
}

function sendMsg($chatId, string $text, ?array $keyboard = null): void {
    $params = [
        'chat_id'=>$chatId,
        'text'=>$text,
        'parse_mode'=>'HTML',
        'disable_web_page_preview'=>true
    ];
    if ($keyboard) {
        $params['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
    }
    tg('sendMessage', $params);
}

function editMsg($chatId, $messageId, string $text, ?array $keyboard = null): void {
    $params = [
        'chat_id'=>$chatId,
        'message_id'=>$messageId,
        'text'=>$text,
        'parse_mode'=>'HTML'
    ];
    if ($keyboard) {
        $params['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
    }
    tg('editMessageText', $params);
}

function answerCb(string $id, string $text = ''): void {
    tg('answerCallbackQuery', [
        'callback_query_id'=>$id,
        'text'=>$text,
        'show_alert'=>false
    ]);
}

function getUser(array $from): array {
    global $users;

    $id = idstr($from['id']);

    if (!isset($users[$id])) {
        $users[$id] = [
            'id'=>(int)$from['id'],
            'first_name'=>$from['first_name'] ?? '',
            'last_name'=>$from['last_name'] ?? '',
            'username'=>$from['username'] ?? '',
            'coins'=>0,
            'referrals'=>0,
            'referrer'=>null,
            'favorites'=>[],
            'purchased'=>[],
            'created_at'=>date('c'),
            'last_seen'=>date('c')
        ];
    } else {
        $users[$id]['first_name'] = $from['first_name'] ?? $users[$id]['first_name'];
        $users[$id]['last_name'] = $from['last_name'] ?? $users[$id]['last_name'];
        $users[$id]['username'] = $from['username'] ?? $users[$id]['username'];
        $users[$id]['last_seen'] = date('c');
    }

    save_db('users', $users);
    return $users[$id];
}

function setState($id, ?array $state): void {
    global $states;
    $key = idstr($id);
    if ($state === null) unset($states[$key]);
    else $states[$key] = $state;
    save_db('states', $states);
}

function getState($id): ?array {
    global $states;
    return $states[idstr($id)] ?? null;
}

function mainKeyboard(bool $admin = false): array {
    $rows = [
        [
            ['text'=>'📚 Kitoblar','callback_data'=>'books'],
            ['text'=>'🔎 Qidirish','callback_data'=>'search']
        ],
        [
            ['text'=>'🎧 Audio kitoblar','callback_data'=>'audio'],
            ['text'=>'❤️ Sevimlilar','callback_data'=>'favorites']
        ],
        [
            ['text'=>'👤 Profil','callback_data'=>'profile'],
            ['text'=>'🎁 Taklif qilish','callback_data'=>'referral']
        ],
        [
            ['text'=>'📂 Kategoriyalar','callback_data'=>'categories'],
            ['text'=>'ℹ️ Yordam','callback_data'=>'help']
        ]
    ];

    if ($admin) {
        $rows[] = [['text'=>'⚙️ Admin panel','callback_data'=>'admin']];
    }

    return ['inline_keyboard'=>$rows];
}

function bookById($id): ?array {
    global $books;
    return $books[(string)$id] ?? null;
}

function bookText(array $book): string {
    $formats = [];
    if (!empty($book['pdf'])) $formats[] = '📕 PDF';
    if (!empty($book['audio'])) $formats[] = '🎧 Audio';

    return "📖 <b>".esc($book['title'])."</b>\n\n"
        ."✍️ ".esc($book['author'] ?? 'Nomaʼlum')."\n"
        ."📂 ".esc($book['category'] ?? 'Boshqa')."\n"
        ."💰 ".(int)($book['price'] ?? 0)." MCoin\n"
        ."📦 ".implode(' • ', $formats);
}

function bookKeyboard(array $book, $userId): array {
    $u = getUser(['id'=>$userId]);
    $favorites = array_map('strval', $u['favorites'] ?? []);
    $favorite = in_array((string)$book['id'], $favorites, true);

    $rows = [];

    if (!empty($book['pdf'])) {
        $rows[] = [['text'=>'📕 PDF o‘qish','callback_data'=>'getpdf:'.$book['id']]];
    }
    if (!empty($book['audio'])) {
        $rows[] = [['text'=>'🎧 Audio tinglash','callback_data'=>'getaudio:'.$book['id']]];
    }

    $rows[] = [[
        'text'=>$favorite ? '💔 Sevimlidan olish' : '❤️ Sevimliga qo‘shish',
        'callback_data'=>'fav:'.$book['id']
    ]];
    $rows[] = [['text'=>'⬅️ Menyu','callback_data'=>'home']];

    return ['inline_keyboard'=>$rows];
}

function sendBook($chatId, array $book): void {
    if (!empty($book['cover']) && file_exists(__DIR__.'/'.$book['cover'])) {
        tg('sendPhoto', [
            'chat_id'=>$chatId,
            'photo'=>new CURLFile(__DIR__.'/'.$book['cover']),
            'caption'=>bookText($book),
            'parse_mode'=>'HTML',
            'reply_markup'=>json_encode(bookKeyboard($book, $chatId), JSON_UNESCAPED_UNICODE)
        ]);
    } else {
        sendMsg($chatId, bookText($book), bookKeyboard($book, $chatId));
    }
}

function listBooks($chatId, array $list, string $title = '📚 Kitoblar'): void {
    if (!$list) {
        sendMsg($chatId, $title."\n\nHozircha kitob topilmadi.", mainKeyboard(isAdmin($chatId)));
        return;
    }

    $text = "<b>".esc($title)."</b>\n\n";
    $buttons = [];

    foreach (array_slice($list, 0, 30) as $book) {
        $text .= "• <b>".esc($book['title'])."</b> — ".esc($book['author'] ?? '')."\n";
        $buttons[] = [[
            'text'=>'📖 '.mb_substr($book['title'], 0, 45),
            'callback_data'=>'book:'.$book['id']
        ]];
    }

    sendMsg($chatId, $text, ['inline_keyboard'=>$buttons]);
}

$users = db('users', []);
$books = db('books', []);
$cats = db('categories', ['Badiiy','Ilmiy','Diniy','Biznes','Psixologiya','Tarix','Bolalar','Boshqa']);
$states = db('states', []);
$settings = db('settings', [
    'welcome'=>'<b>Mutolaachi</b> — kitob o‘qish va audio tinglash botiga xush kelibsiz! 📚',
    'coin_name'=>'MCoin'
]);

$update = json_decode((string)file_get_contents('php://input'), true);

if (!$update) {
    echo 'OK';
    exit;
}

/* CALLBACKS */
if (isset($update['callback_query'])) {
    $cq = $update['callback_query'];
    $uid = $cq['from']['id'];
    $chatId = $cq['message']['chat']['id'];
    $messageId = $cq['message']['message_id'];
    $data = $cq['data'] ?? '';

    getUser($cq['from']);
    answerCb($cq['id']);

    if ($data === 'home') {
        editMsg($chatId, $messageId, $settings['welcome'], mainKeyboard(isAdmin($uid)));
        exit;
    }

    if ($data === 'books') {
        listBooks($chatId, array_values($books));
        exit;
    }

    if ($data === 'audio') {
        $audioBooks = array_values(array_filter($books, fn($b)=>!empty($b['audio'])));
        listBooks($chatId, $audioBooks, '🎧 Audio kitoblar');
        exit;
    }

    if ($data === 'favorites') {
        $u = getUser(['id'=>$uid]);
        $favoriteIds = array_map('strval', $u['favorites'] ?? []);
        $favoriteBooks = array_values(array_filter(
            $books,
            fn($b)=>in_array((string)$b['id'], $favoriteIds, true)
        ));
        listBooks($chatId, $favoriteBooks, '❤️ Sevimlilar');
        exit;
    }

    if ($data === 'categories') {
        $buttons = [];
        foreach ($cats as $cat) {
            $buttons[] = [[
                'text'=>'📂 '.$cat,
                'callback_data'=>'cat:'.base64_encode($cat)
            ]];
        }
        sendMsg($chatId, '📂 <b>Kategoriyalar</b>', ['inline_keyboard'=>$buttons]);
        exit;
    }

    if ($data === 'profile') {
        $u = getUser(['id'=>$uid]);
        sendMsg(
            $chatId,
            "👤 <b>Profil</b>\n\n".
            "🆔 <code>{$uid}</code>\n".
            "📚 Xaridlar: ".count($u['purchased'] ?? [])."\n".
            "❤️ Sevimlilar: ".count($u['favorites'] ?? [])."\n".
            "🪙 MCoin: <b>".(int)$u['coins']."</b>\n".
            "👥 Referallar: ".(int)$u['referrals'],
            mainKeyboard(isAdmin($uid))
        );
        exit;
    }

    if ($data === 'referral') {
        global $BOT_USERNAME;
        $link = $BOT_USERNAME
            ? "https://t.me/{$BOT_USERNAME}?start=ref_{$uid}"
            : 'Bot username sozlanmagan';

        sendMsg(
            $chatId,
            "🎁 <b>Do‘stlaringizni taklif qiling</b>\n\n".
            "Sizning havolangiz:\n<code>".esc($link)."</code>\n\n".
            "Yangi foydalanuvchilar ushbu havola orqali kirganda referral hisoblanadi."
        );
        exit;
    }

    if ($data === 'help') {
        sendMsg(
            $chatId,
            "ℹ️ <b>Yordam</b>\n\n".
            "🔎 Kitob nomi yoki muallifini yozing.\n".
            "📕 PDF kitoblarni oching.\n".
            "🎧 Audio kitoblarni tinglang.\n".
            "❤️ Sevimlilarni saqlang.\n\n".
            "Admin: /admin"
        );
        exit;
    }

    if (str_starts_with($data, 'cat:')) {
        $category = base64_decode(substr($data, 4));
        $filtered = array_values(array_filter(
            $books,
            fn($b)=>(($b['category'] ?? '') === $category)
        ));
        listBooks($chatId, $filtered, '📂 '.$category);
        exit;
    }

    if (str_starts_with($data, 'book:')) {
        $book = bookById(substr($data, 5));
        if ($book) sendBook($chatId, $book);
        exit;
    }

    if (str_starts_with($data, 'fav:')) {
        $id = substr($data, 4);
        $key = (string)$uid;
        $favorites = array_map('strval', $users[$key]['favorites'] ?? []);

        if (in_array($id, $favorites, true)) {
            $favorites = array_values(array_diff($favorites, [$id]));
        } else {
            $favorites[] = $id;
        }

        $users[$key]['favorites'] = $favorites;
        save_db('users', $users);

        $book = bookById($id);
        if ($book) editMsg($chatId, $messageId, bookText($book), bookKeyboard($book, $uid));
        exit;
    }

    if (str_starts_with($data, 'getpdf:') || str_starts_with($data, 'getaudio:')) {
        $isAudio = str_starts_with($data, 'getaudio:');
        $id = substr($data, $isAudio ? 9 : 7);
        $book = bookById($id);

        if (!$book) {
            sendMsg($chatId, 'Kitob topilmadi.');
            exit;
        }

        $path = $isAudio ? ($book['audio'] ?? '') : ($book['pdf'] ?? '');

        if (!$path || !file_exists(__DIR__.'/'.$path)) {
            sendMsg($chatId, 'Fayl hali mavjud emas.');
            exit;
        }

        if ($isAudio) {
            tg('sendAudio', [
                'chat_id'=>$chatId,
                'audio'=>new CURLFile(__DIR__.'/'.$path),
                'caption'=>'🎧 '.($book['title'] ?? 'Mutolaachi')
            ]);
        } else {
            tg('sendDocument', [
                'chat_id'=>$chatId,
                'document'=>new CURLFile(__DIR__.'/'.$path),
                'caption'=>'📕 '.($book['title'] ?? 'Mutolaachi')
            ]);
        }
        exit;
    }

    if ($data === 'search') {
        setState($uid, ['action'=>'search']);
        sendMsg($chatId, '🔎 Kitob nomi yoki muallifni yuboring:');
        exit;
    }

    if ($data === 'admin' && isAdmin($uid)) {
        sendMsg(
            $chatId,
            "⚙️ <b>Admin panel</b>\n\n".
            "/addbook — kitob qo‘shish\n".
            "/books — barcha kitoblar\n".
            "/users — foydalanuvchilar\n".
            "/stats — statistika\n".
            "/cancel — amalni bekor qilish"
        );
        exit;
    }

    exit;
}

/* MESSAGE */
$message = $update['message'] ?? null;
if (!$message) {
    echo 'OK';
    exit;
}

$chatId = $message['chat']['id'];
$uid = $message['from']['id'];
$text = trim($message['text'] ?? '');
getUser($message['from']);

if ($text === '/start') {
    $parts = preg_split('/\s+/', $text);
    $ref = $parts[1] ?? '';

    if (str_starts_with($ref, 'ref_')) {
        $referrer = substr($ref, 4);
        $key = (string)$uid;

        if ($referrer !== (string)$uid && isset($users[$referrer]) && empty($users[$key]['referrer'])) {
            $users[$key]['referrer'] = $referrer;
            $users[$referrer]['referrals'] = (int)($users[$referrer]['referrals'] ?? 0) + 1;
            save_db('users', $users);
        }
    }

    sendMsg(
        $chatId,
        $settings['welcome']."\n\n".
        "🔎 Kitob qidirish uchun kitob nomini yozing.",
        mainKeyboard(isAdmin($uid))
    );
    exit;
}

if ($text === '/cancel') {
    setState($uid, null);
    sendMsg($chatId, '✅ Amal bekor qilindi.', mainKeyboard(isAdmin($uid)));
    exit;
}

if ($text === '/admin' && isAdmin($uid)) {
    sendMsg(
        $chatId,
        "⚙️ <b>Admin panel</b>\n\n".
        "/addbook\n/books\n/users\n/stats\n/cancel"
    );
    exit;
}

if ($text === '/users' && isAdmin($uid)) {
    sendMsg($chatId, '👥 Foydalanuvchilar: <b>'.count($users).'</b>');
    exit;
}

if ($text === '/stats' && isAdmin($uid)) {
    sendMsg(
        $chatId,
        "📊 <b>Statistika</b>\n\n".
        "👥 Users: ".count($users)."\n".
        "📚 Books: ".count($books)
    );
    exit;
}

if ($text === '/books' && isAdmin($uid)) {
    listBooks($chatId, array_values($books), '📚 Barcha kitoblar');
    exit;
}

/* ADMIN: ADD BOOK */
if ($text === '/addbook' && isAdmin($uid)) {
    setState($uid, ['action'=>'addbook_title']);
    sendMsg($chatId, '📖 Kitob nomini yuboring:');
    exit;
}

$state = getState($uid);

if ($state && isAdmin($uid)) {
    switch ($state['action'] ?? '') {
        case 'addbook_title':
            $state['title'] = $text;
            $state['action'] = 'addbook_author';
            setState($uid, $state);
            sendMsg($chatId, '✍️ Muallif nomini yuboring:');
            exit;

        case 'addbook_author':
            $state['author'] = $text;
            $state['action'] = 'addbook_category';
            setState($uid, $state);
            sendMsg($chatId, '📂 Kategoriya nomini yuboring:');
            exit;

        case 'addbook_category':
            $state['category'] = $text;
            $state['action'] = 'addbook_file';
            setState($uid, $state);
            sendMsg($chatId, '📎 Endi PDF yoki audio faylni yuboring:');
            exit;

        case 'addbook_price':
            $price = max(0, (int)$text);
            $fileId = $state['file_id'];

            $fileInfo = tg('getFile', ['file_id'=>$fileId]);
            $remotePath = $fileInfo['result']['file_path'] ?? '';

            if (!$remotePath) {
                sendMsg($chatId, '❌ Telegram faylini olishda xatolik.');
                setState($uid, null);
                exit;
            }

            $extension = pathinfo($remotePath, PATHINFO_EXTENSION) ?: 'bin';
            $audioExtensions = ['mp3','m4a','ogg','wav','aac'];
            $isAudio = in_array(strtolower($extension), $audioExtensions, true);

            $id = (string)(count($books)
                ? max(array_map('intval', array_keys($books))) + 1
                : 1);

            $relativePath = 'uploads/'.($isAudio ? 'audio' : 'pdf').'/'.$id.'.'.$extension;
            $downloadUrl = "https://api.telegram.org/file/bot{$BOT_TOKEN}/".$remotePath;
            $fileData = @file_get_contents($downloadUrl);

            if ($fileData === false) {
                sendMsg($chatId, '❌ Faylni yuklab bo‘lmadi.');
                setState($uid, null);
                exit;
            }

            file_put_contents(__DIR__.'/'.$relativePath, $fileData, LOCK_EX);

            $books[$id] = [
                'id'=>(int)$id,
                'title'=>$state['title'],
                'author'=>$state['author'],
                'category'=>$state['category'],
                'price'=>$price,
                'pdf'=>$isAudio ? '' : $relativePath,
                'audio'=>$isAudio ? $relativePath : '',
                'cover'=>'',
                'created_at'=>date('c')
            ];

            save_db('books', $books);
            setState($uid, null);

            sendMsg(
                $chatId,
                "✅ <b>Kitob qo‘shildi!</b>\n\n".bookText($books[$id]),
                mainKeyboard(true)
            );
            exit;
    }
}

/* FILE UPLOAD FOR ADMIN */
if (isAdmin($uid) && isset($message['document'])) {
    $state = getState($uid);

    if ($state && ($state['action'] ?? '') === 'addbook_file') {
        $state['file_id'] = $message['document']['file_id'];
        $state['action'] = 'addbook_price';
        setState($uid, $state);
        sendMsg($chatId, '💰 Kitob narxini MCoin bilan yuboring. Bepul bo‘lsa <b>0</b> yozing:');
        exit;
    }
}

if (isAdmin($uid) && isset($message['audio'])) {
    $state = getState($uid);

    if ($state && ($state['action'] ?? '') === 'addbook_file') {
        $state['file_id'] = $message['audio']['file_id'];
        $state['action'] = 'addbook_price';
        setState($uid, $state);
        sendMsg($chatId, '💰 Kitob narxini MCoin bilan yuboring. Bepul bo‘lsa <b>0</b> yozing:');
        exit;
    }
}

/* SEARCH */
if ($state && ($state['action'] ?? '') === 'search') {
    $query = mb_strtolower($text);

    $found = array_values(array_filter($books, function($book) use ($query) {
        $haystack = mb_strtolower(
            ($book['title'] ?? '').' '.
            ($book['author'] ?? '').' '.
            ($book['category'] ?? '')
        );
        return $query !== '' && str_contains($haystack, $query);
    }));

    setState($uid, null);
    listBooks($chatId, $found, '🔎 Qidiruv natijalari');
    exit;
}

/* NATURAL SEARCH */
if ($text !== '' && $text[0] !== '/') {
    $query = mb_strtolower($text);

    $found = array_values(array_filter($books, function($book) use ($query) {
        $haystack = mb_strtolower(($book['title'] ?? '').' '.($book['author'] ?? ''));
        return str_contains($haystack, $query);
    }));

    if ($found) {
        listBooks($chatId, $found, '🔎 Qidiruv natijalari');
    } else {
        sendMsg(
            $chatId,
            "🔎 <b>Hech narsa topilmadi.</b>\n\nBoshqa kitob nomini yozib ko‘ring.",
            mainKeyboard(isAdmin($uid))
        );
    }
}

echo 'OK';
