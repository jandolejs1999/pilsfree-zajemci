<?php

/**
 * Hey PF buddies, you can use this to send Telegram notification when new user registers IMMEDIATELY
 * Just copy .env.template as .env and change username/password
 * Than change token to your telegram bot token + chat ID from telegram (help is on internet)
 * You can run cron as often as you wish, just point it to run
 */


function login() {

    // Firstly set cookie for pf web
    $ch = curl_init('https://login.pilsfree.net');
    curl_setopt_array($ch, array(
        CURLOPT_HEADER => 0,
        CURLOPT_POST => 1,
        CURLOPT_COOKIEJAR => 'cookie',
        CURLOPT_COOKIEFILE => 'cookie',
        CURLOPT_FOLLOWLOCATION => 1,
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_POSTFIELDS => [
            'i_login'=> $_ENV['LOGIN'],
            'i_pass' => $_ENV['PASS'],
            'i_login_akce' => 'Přihlásit',
        ],
    ));
    curl_exec($ch);

    curl_close($ch);

    // Secondly transfer cookie to chicago by magic redirects :)
    $ch = curl_init('https://' . $_ENV['CHICAGO_HOST'] . '/zajemci.php');
    curl_setopt_array($ch, array(
        CURLOPT_HEADER => 0,
        CURLOPT_POST => 1,
        CURLOPT_COOKIEJAR => 'cookie',
        CURLOPT_COOKIEFILE => 'cookie',
        CURLOPT_FOLLOWLOCATION => 1,
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_POSTFIELDS => [
            'i_login'=> $_ENV['LOGIN'],
            'i_pass' => $_ENV['PASS'],
            'i_login_akce' => 'Přihlásit',
        ],
    ));
    curl_exec($ch);
    curl_close($ch);

    file_put_contents('debug', date("Y/m/d h:i:j") . " Logged in" . "\n", FILE_APPEND);
}


/**
 * @throws Exception
 */
function fetchQuery(): array {

    $ch = curl_init('https://' . $_ENV['CHICAGO_HOST'] . '/JT_Actions.php?action=listzajemci&jtStartIndex=0&jtPageSize=10&jtSorting=id%20DESC');
    curl_setopt_array($ch, array(
        CURLOPT_HEADER => 0,
        CURLOPT_COOKIEJAR => 'cookie',
        CURLOPT_COOKIEFILE => 'cookie',
        CURLOPT_RETURNTRANSFER => 1,
    ));
    $result = curl_exec($ch);
    curl_close($ch);

    if (empty($result)) {
        throw new Exception('Fetch failed, no data received');
    }

    $parsed = json_decode($result, true);
    if (empty($parsed)) {
        throw new Exception('Parsing failed');
    }

    if ($parsed['Result'] !== "OK") {
        throw new Exception('Data not fetched correctly');
    }

    return $parsed;
}

function process(array $list) {
    file_put_contents('debug', date("Y/m/d h:i:j") . " Found '" . count($list['Records']) . "' records" . "\n", FILE_APPEND);
    foreach ($list['Records'] as $record) {

        $id = $record['id'];
        $link = $record['is_link'];
        $name = $record['name'];
        $addr = $record['kde'];

        // Little phone parsing
        $phone = $record['tel'];
        $phone = preg_replace("~\s~", '', $phone);
        $phone = trim($phone);

        $sent = file_get_contents('used');
        $sent = explode("\n", $sent);
        if (in_array($id, $sent)) {
            continue;
        }
        file_put_contents('log', date("Y/m/d h:i:j") . " Sending $id - $name" . "\n", FILE_APPEND);

        // send telegram
        $ch = curl_init('https://api.telegram.org/bot' . $_ENV['TOKEN'] . '/sendMessage');
        curl_setopt_array($ch, array(
            CURLOPT_HEADER => 0,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => [
                'chat_id' => $_ENV['TELEGRAM_ID'],
                'disable_web_page_preview' => 1,
                'parse_mode' => 'html',
                'text' => "Nový zájemce: $name\nPhone: $phone\nPlace: $addr\nLink: $link",
            ],
        ));
        curl_exec($ch);
        curl_close($ch);

        file_put_contents('log', date("Y/m/d h:i:j") . " Sent $id - $name" . "\n", FILE_APPEND);
        file_put_contents('used', "\n$id", FILE_APPEND);
    }
}


file_put_contents('debug', date("Y/m/d h:i:j") . " PHP Start" . "\n", FILE_APPEND);

if (!is_file('used')) {
    touch('used');
}


$list = false;

try {
    // Firstly try to fetch data without login (cookie is still saved)
    $list = fetchQuery();
} catch (Exception $e) {

    login();

    try {
        // Secondly if first fetch fails than login first
        $list = fetchQuery();
    } catch (Exception $e) {
        // If something fails, log error
        file_put_contents('error', date("Y/m/d h:i:j") . ' ' . $e->getMessage() . "\n", FILE_APPEND);
    }
}

process($list);

file_put_contents('debug', date("Y/m/d h:i:j") . ' PHP Stop' . "\n", FILE_APPEND);
