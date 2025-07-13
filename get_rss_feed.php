<?php

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // Это позволит вашему лендингу делать запросы к этому скрипту

$rss_url = isset($_GET['url']) ? $_GET['url'] : '';

if (empty($rss_url)) {
    echo json_encode(['status' => 'error', 'message' => 'Не указан URL RSS-ленты.']);
    exit;
}

// Проверяем, является ли URL валидным RSS-источником
// Для безопасности, можно добавить более строгие проверки URL,
// например, убедиться, что он начинается с liveopencart.ru или forum.opencart-russia.ru
$allowed_domains = ['liveopencart.ru', 'forum.opencart-russia.ru'];
$url_parts = parse_url($rss_url);

if (!isset($url_parts['host']) || !in_array($url_parts['host'], $allowed_domains)) {
    echo json_encode(['status' => 'error', 'message' => 'Недопустимый домен RSS-ленты.']);
    exit;
}


// Инициализация cURL для более надежной загрузки RSS
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $rss_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Следовать редиректам
curl_setopt($ch, CURLOPT_TIMEOUT, 10); // Таймаут 10 секунд
$xml_string = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_error = curl_error($ch);
curl_close($ch);

if ($xml_string === false || $http_code >= 400) {
    echo json_encode(['status' => 'error', 'message' => 'Ошибка загрузки RSS-ленты: ' . ($curl_error ? $curl_error : 'HTTP Code ' . $http_code)]);
    exit;
}

// Попытка загрузить XML
libxml_use_internal_errors(true); // Отключаем вывод ошибок XML на страницу
$xml = simplexml_load_string($xml_string);

if ($xml === false) {
    $errors = [];
    foreach (libxml_get_errors() as $error) {
        $errors[] = $error->message;
    }
    echo json_encode(['status' => 'error', 'message' => 'Ошибка парсинга XML: ' . implode(', ', $errors)]);
    exit;
}

$items = [];
foreach ($xml->channel->item as $item) {
    $items[] = [
        'title'       => (string)$item->title,
        'link'        => (string)$item->link,
        'description' => (string)$item->description,
        'pubDate'     => (string)$item->pubDate,
        'author'      => (string)$item->author, // Некоторые RSS-ленты могут иметь поле author
    ];
}

$response = [
    'status' => 'ok',
    'feed' => [
        'title' => (string)$xml->channel->title,
        'link'  => (string)$xml->channel->link,
        'url'   => $rss_url,
    ],
    'items' => $items
];

echo json_encode($response);

?>