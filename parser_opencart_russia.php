<?php
   
// --- НАСТРОЙКИ КЭШИРОВАНИЯ ---
$cacheDir = __DIR__ . '/cache/'; // Директория для кэша. Убедитесь, что она существует и доступна для записи.
// Важно: для каждого парсера должен быть свой уникальный файл кэша.
// Этот файл будет использоваться для парсинга "Новинок" (модулей)
$cacheFileName = 'opencart_russia_cache.json'; // УНИКАЛЬНОЕ ИМЯ ФАЙЛА КЭША ДЛЯ ЭТОГО ПАРСЕРА
$cacheFilePath = $cacheDir . $cacheFileName;
$cacheTTL = 3 * 24 * 60 * 60; // 3 дня в секундах
// --- КОНЕЦ НАСТРОЕК КЭШИРОВАНИЯ ---

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

// Главный URL сайта, с которого парсим.
const BASE_URL = 'https://shop.opencart-russia.ru/';

// Разрешаем кросс-доменные запросы (если лендинг на другом домене)
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8'); // Отправляем JSON-заголовок

// Функция для парсинга блока "Новые файлы" с сайта shop.opencart-russia.ru
function parseNewFiles($url) {
    // Инициализация cURL для получения веб-страницы
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $html = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    // Проверка, был ли HTML успешно получен
    if ($html === false || $http_code >= 400) {
        error_log("CURL ERROR: Failed to fetch URL '{$url}'. HTTP Code: {$http_code}, Error: {$curl_error}");
        return ['error' => 'Не удалось получить веб-страницу: ' . ($curl_error ? $curl_error : 'HTTP Code ' . $http_code)];
    }
    
    // Инициализация DOMDocument
    $doc = new DOMDocument();
    @$doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $xpath = new DOMXPath($doc);

    // Находим блок "Новые файлы"
    $boxHeading = $xpath->query("//div[@class='box-heading' and normalize-space(text())='Новые файлы']");
    
    if ($boxHeading->length === 0) {
        error_log("XPATH ERROR: Box heading 'Новые файлы' not found on {$url}.");
        return ['error' => 'Блок "Новые файлы" не найден. Проверьте XPath или текст заголовка блока.'];
    }
    error_log("XPATH DEBUG: Found box heading 'Новые файлы'.");

    // НОВОЕ: Сначала находим box-content, который является прямым соседом box-heading
    $boxContainer = $xpath->query("following-sibling::div[@class='box-content']", $boxHeading->item(0));

    if ($boxContainer->length === 0) {
        error_log("XPATH ERROR: Box content div not found as following-sibling of heading.");
        return ['error' => 'Контейнер box-content не найден после заголовка "Новые файлы". Проверьте структуру HTML.'];
    }
    error_log("XPATH DEBUG: Found box content div.");

    // Затем внутри box-content находим latest-carousel
    $boxContent = $xpath->query(".//div[@id='latest-carousel']", $boxContainer->item(0));
    
    if ($boxContent->length === 0) {
        error_log("XPATH ERROR: Box content with id 'latest-carousel' not found inside box-content div.");
        return ['error' => 'Содержимое блока "Новые файлы" (latest-carousel) не найдено внутри box-content.'];
    }
    error_log("XPATH DEBUG: Found box content with id 'latest-carousel'.");


    // Инициализируем массив результатов
    $products = [];

    // Находим все элементы продуктов (slider-item) внутри box-content
    $productNodes = $xpath->query(".//div[@class='slider-item']", $boxContent->item(0));
    
    if ($productNodes->length === 0) {
        error_log("XPATH ERROR: No product items (slider-item) found within the box content.");
        return ['error' => 'Элементы продуктов не найдены внутри блока "Новые файлы".'];
    }
    error_log("XPATH DEBUG: Found " . $productNodes->length . " product items.");

    foreach ($productNodes as $node) {
        $product = [];

        // Внутренний блок, который содержит всю информацию о продукте
        $productInnerNode = $xpath->query(".//div[@class='product-block-inner']", $node);
        if ($productInnerNode->length === 0) {
            error_log("XPATH DEBUG: Skipping product item, product-block-inner not found.");
            continue;
        }
        $innerNode = $productInnerNode->item(0); // Работаем относительно этого узла

        // Извлекаем название и URL
        $nameNode = $xpath->query(".//div[@class='name']/a", $innerNode);
        if ($nameNode->length > 0) {
            $product['name'] = trim($nameNode->item(0)->textContent);
            $product['url'] = !empty($nameNode->item(0)->getAttribute('href')) ?
                               (strpos($nameNode->item(0)->getAttribute('href'), 'http') === 0 ? $nameNode->item(0)->getAttribute('href') : BASE_URL . ltrim($nameNode->item(0)->getAttribute('href'), '/')) : '';
        } else {
             error_log("XPATH DEBUG: Skipping product, name link not found.");
             continue; // Если нет имени, то и смысла нет парсить дальше этот продукт
        }

        // Извлекаем URL изображения и преобразуем его к оригиналу
        $imageNode = $xpath->query(".//div[@class='image']/a/img", $innerNode);
        if ($imageNode->length > 0) {
            $src = $imageNode->item(0)->getAttribute('src');
            if (!empty($src)) {
                // Удаляем "/cache/" из пути
                $src = str_replace('/cache/', '/', $src);
                // Удаляем "-80x80.jpeg" или "-XXXxYYY.ext" перед расширением
                $src = preg_replace('/-\d+x\d+\.(jpeg|jpg|png|gif)$/i', '.$1', $src);
                // Если путь относительный, делаем его абсолютным
                $product['image'] = (strpos($src, 'http') === 0 ? $src : BASE_URL . ltrim($src, '/'));
            } else {
                $product['image'] = null;
            }
        } else {
            $product['image'] = null; // Если нет изображения, ставим null
        }

        // Извлекаем цену
        $priceNode = $xpath->query(".//div[@class='price']", $innerNode);
        if ($priceNode->length > 0) {
            // Здесь нет price-new или price-old, просто берем текст из price
            $product['price'] = trim($priceNode->item(0)->textContent);
        } else {
            $product['price'] = null;
        }

        // Для этого сайта стикеров в той структуре, что мы парсили liveopencart.ru, нет.
        // Поэтому просто добавляем пустой массив стикеров.
        $product['stickers'] = [];

        // Добавляем продукт в результаты
        $products[] = $product;
    }

    return ['status' => 'ok', 'products' => $products];
}

// ====================================================================
// Основной вызов для отдачи JSON
// ====================================================================

$url_to_parse = BASE_URL; // URL страницы, которую парсим

try {
    // Попытка получить данные из кэша
    $cachedData = getCachedData($cacheFilePath, $cacheTTL);

    if ($cachedData !== false) {
        // Если данные найдены в кэше и они актуальны, отдаем их
        echo $cachedData;
    } else {
        // Если кэш отсутствует или устарел, выполняем парсинг
        $url_to_parse = BASE_URL; // URL страницы, которую парсим
        $results = parseNewFiles($url_to_parse);

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
    error_log("Unhandled exception in parser_opencart_russia.php: " . $e->getMessage());
}

?>