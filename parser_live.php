<?php

// --- НАСТРОЙКИ КЭШИРОВАНИЯ ---
$cacheDir = __DIR__ . '/cache/'; // Директория для кэша. Убедитесь, что она существует и доступна для записи.
// Важно: для каждого парсера должен быть свой уникальный файл кэша.
// Этот файл будет использоваться для парсинга "Новинок" (модулей)
$cacheFileName = 'liveopencart_modules_cache.json'; // УНИКАЛЬНОЕ ИМЯ ФАЙЛА КЭША ДЛЯ ЭТОГО ПАРСЕРА
$cacheFilePath = $cacheDir . $cacheFileName;
$cacheTTL = 3 * 24 * 60 * 60; // 3 дня в секундах
// --- КОНЕЦ НАСТРОЕК КЭШИРОВАНИЯ ---

// --- НАСТРОЙКИ КЭШИРОВАНИЯ ДЛЯ RSS-ЛЕНТЫ ---
$cacheDir = __DIR__ . '/cache/';
$cacheFileName = 'liveopencart_news_rss_cache.json'; // УНИКАЛЬНОЕ ИМЯ ФАЙЛА КЭША ДЛЯ ЭТОЙ RSS-ЛЕНТЫ
$cacheFilePath = $cacheDir . $cacheFileName;
$cacheTTL = 3 * 24 * 60 * 60; // 3 дня в секундах
// --- КОНЕЦ НАСТРОЕК КЭШИРОВАНИЯ ---

// Главный URL сайта, с которого парсим.
const BASE_URL = 'https://liveopencart.ru/';

// Разрешаем кросс-доменные запросы (если лендинг на другом домене)
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8'); // Отправляем JSON-заголовок

// --- ФУНКЦИИ ДЛЯ КЭШИРОВАНИЯ ---
function getCachedData($cacheFilePath, $cacheTTL) {
    if (file_exists($cacheFilePath) && (filemtime($cacheFilePath) + $cacheTTL > time())) {
        return file_get_contents($cacheFilePath);
    }
    return false;
}

function saveCachedData($cacheFilePath, $data) {
    // Убедимся, что директория кэша существует
    $cacheDir = dirname($cacheFilePath);
    if (!is_dir($cacheDir)) {
        // Создаем рекурсивно с правами 0755. true для рекурсивного создания.
        if (!mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $cacheDir));
        }
    }
    if (file_put_contents($cacheFilePath, $data) === false) {
        throw new Exception('Не удалось записать данные в файл кэша: ' . $cacheFilePath);
    }
}
// --- КОНЕЦ ФУНКЦИЙ ДЛЯ КЭШИРОВАНИЯ ---

// Функция для парсинга блока "Новинки" с сайта liveopencart.ru
function parseNewArrivals($url) {
    // Инициализация cURL для получения веб-страницы
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Увеличим таймаут на всякий случай
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Временное решение для тестирования, в продакшене лучше настроить верификацию SSL

    $html = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    // Проверка, был ли HTML успешно получен
    if ($html === false || $http_code >= 400) {
        return ['error' => 'Не удалось получить веб-страницу: ' . ($curl_error ? $curl_error : 'HTTP Code ' . $http_code)];
    }

    // Инициализация DOMDocument
    $doc = new DOMDocument();
    // Подавляем предупреждения из-за некорректного HTML
    // Важно: HTML-ENTITIES для корректной обработки кириллицы
    @$doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors(); // Очищаем буфер ошибок
    $xpath = new DOMXPath($doc);

    // Находим блок "Новинки" по тексту заголовка
    $boxHeading = $xpath->query("//div[@class='box-heading' and normalize-space(text())='Новинки']");
    if ($boxHeading->length === 0) {
        // Если блок "Новинки" не найден по русскому тексту, попробуем по английскому "New Arrivals"
        // (хотя сайт liveopencart.ru обычно на русском, но лучше предусмотреть)
        $boxHeading = $xpath->query("//div[@class='box-heading' and normalize-space(text())='New Arrivals']");
        if ($boxHeading->length === 0) {
            return ['error' => 'Блок "Новинки" (или "New Arrivals") не найден. Проверьте XPath или текст заголовка блока.'];
        }
    }

    // Получаем родительский блок и его содержимое
    $boxContent = $xpath->query("following-sibling::div[contains(@class, 'box-content')]", $boxHeading->item(0));
    if ($boxContent->length === 0) {
        return ['error' => 'Содержимое блока "Новинки" не найдено.'];
    }

    // Инициализируем массив результатов
    $products = [];

    // Находим все элементы продуктов с учетом новой структуры
    // Ищем div, который содержит классы col-lg-25, col-md-4 и т.д., и является контейнером продукта
    // Используем более точный XPath, если есть возможность: например, по классу 'product-layout' или 'product-thumb'
    $productNodes = $xpath->query(".//div[contains(@class, 'product-thumb') or (contains(@class, 'col-') and .//div[@class='image'])]", $boxContent->item(0));
    
    foreach ($productNodes as $node) {
        $product = [];

        // Извлекаем название и URL
        $nameNode = $xpath->query(".//div[@class='caption']/div[@class='name']/a", $node); // Обновленный XPath
        if ($nameNode->length > 0) {
            $product['name'] = trim($nameNode->item(0)->textContent);
            $product['url'] = !empty($nameNode->item(0)->getAttribute('href')) ?
                               (strpos($nameNode->item(0)->getAttribute('href'), 'http') === 0 ? $nameNode->item(0)->getAttribute('href') : BASE_URL . ltrim($nameNode->item(0)->getAttribute('href'), '/')) : '';
        } else {
             // Fallback for older/different structures if the above fails
             $nameNode = $xpath->query(".//div[@class='name']/a", $node);
             if ($nameNode->length > 0) {
                 $product['name'] = trim($nameNode->item(0)->textContent);
                 $product['url'] = !empty($nameNode->item(0)->getAttribute('href')) ?
                                    (strpos($nameNode->item(0)->getAttribute('href'), 'http') === 0 ? $nameNode->item(0)->getAttribute('href') : BASE_URL . ltrim($nameNode->item(0)->getAttribute('href'), '/')) : '';
             } else {
                continue; // Skip this item if no name/url found, it's likely not a product
             }
        }

        // Извлекаем URL изображения
        $imageNode = $xpath->query(".//div[@class='image']/a/img", $node);
        if ($imageNode->length > 0) {
            $src = $imageNode->item(0)->getAttribute('src');
            $product['image'] = !empty($src) ?
                                 (strpos($src, 'http') === 0 ? $src : BASE_URL . ltrim($src, '/')) : '';
        } else {
            $product['image'] = ''; // Empty string if no image found
        }

        // Извлекаем цену (новую и старую)
        $priceNode = $xpath->query(".//div[@class='price']", $node);
        if ($priceNode->length > 0) {
            $priceNewNode = $xpath->query(".//span[@class='price-new']", $priceNode->item(0));
            $priceOldNode = $xpath->query(".//span[@class='price-old']", $priceNode->item(0));
            $product['price_new'] = $priceNewNode->length > 0 ? trim($priceNewNode->item(0)->textContent) : trim($priceNode->item(0)->textContent);
            $product['price_old'] = $priceOldNode->length > 0 ? trim($priceOldNode->item(0)->textContent) : null;
        } else {
            $product['price_new'] = null;
            $product['price_old'] = null;
        }

        // Извлекаем стикеры (обновленный XPath для july-stickers и general stickers)
        $stickers = [];
        // Пробуем найти стикеры в общих контейнерах для стикеров
        $stickerContainers = $xpath->query(".//div[contains(@class, 'stickers')] | .//div[contains(@class, 'july-stickers')]", $node);

        foreach ($stickerContainers as $container) {
            $stickerNodesInContainer = $xpath->query("./div[contains(@class, 'sticker')]", $container);
            foreach ($stickerNodesInContainer as $sticker) {
                $stickers[] = trim($sticker->textContent);
            }
        }
        $product['stickers'] = array_unique($stickers); // Убираем дубликаты стикеров

        // Добавляем продукт в результаты, если название и URL присутствуют
        if (!empty($product['name']) && !empty($product['url'])) {
            $products[] = $product;
        }
    }

    // Если не найдено продуктов, но ошибок не было, возможно, структура изменилась или блок пуст
    if (empty($products) && !isset($results['error'])) {
        return ['error' => 'Продукты в блоке "Новинки" не найдены. Проверьте XPath для productNodes.', 'products' => []];
    }


    return ['status' => 'ok', 'products' => $products];
}

// ====================================================================
// Основной вызов для отдачи JSON (с логикой кэширования)
// ====================================================================

try {
    // Попытка получить данные из кэша
    $cachedData = getCachedData($cacheFilePath, $cacheTTL);

    if ($cachedData !== false) {
        // Если данные найдены в кэше и они актуальны, отдаем их
        echo $cachedData;
    } else {
        // Если кэш отсутствует или устарел, выполняем парсинг
        $url_to_parse = BASE_URL; // URL страницы, которую парсим
        $results = parseNewArrivals($url_to_parse);

        // Если парсинг прошел успешно, сохраняем данные в кэш
        if (isset($results['status']) && $results['status'] === 'ok') {
            $jsonData = json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            saveCachedData($cacheFilePath, $jsonData);
            echo $jsonData;
        } else {
            // Если при парсинге произошла ошибка, выводим ее и не кэшируем
            echo json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            // Optionally: log the error
            error_log("Error parsing Liveopencart modules: " . ($results['error'] ?? 'Unknown error'));
        }
    }

} catch (Exception $e) {
    // Обработка ошибок кэширования или других непредвиденных исключений
    echo json_encode(['error' => 'Произошла ошибка сервера: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    error_log("Unhandled exception in parser_live.php: " . $e->getMessage());
}

?>