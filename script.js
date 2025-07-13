document.addEventListener('DOMContentLoaded', function() {
    const websiteInput = document.getElementById('websiteInput');
    const checkButton = document.getElementById('checkButton');
    const resultMessage = document.getElementById('resultMessage');
    const checkerModal = document.getElementById('checkerModal');

    let warezSites = [];
    // Загружаем список "варезных" сайтов из checker.txt
    fetch('checker.txt')
        .then(response => {
            if (!response.ok) {
                // Если файл не найден или ошибка HTTP, выводим сообщение
                throw new Error(`HTTP error! status: ${response.status}. Возможно, файл checker.txt отсутствует.`);
            }
            return response.text();
        })
        .then(data => {
            // Разделяем строку по запятым, обрезаем пробелы и фильтруем пустые строки
            warezSites = data.split(',').map(s => s.trim()).filter(s => s.length > 0);
            console.log('Список варезных сайтов загружен:', warezSites);
        })
        .catch(error => {
            console.error('Ошибка при загрузке checker.txt:', error);
            resultMessage.innerHTML = '<div class="alert alert-danger">Ошибка загрузки списка сайтов. Пожалуйста, попробуйте позже.</div>';
            checkButton.disabled = true; // Отключаем кнопку, если список не загружен
        });

    checkButton.addEventListener('click', function() {
        const url = websiteInput.value.trim();
        if (url === '') {
            resultMessage.innerHTML = '<div class="alert alert-warning">Пожалуйста, введите URL.</div>';
            return;
        }

        try {
            // Используем объект URL для парсинга адреса и извлечения имени хоста
            // Добавляем 'http://' если протокол отсутствует, чтобы URL-парсинг работал корректно
            const parsedUrl = new URL(url.startsWith('http://') || url.startsWith('https://') ? url : `http://${url}`);
            let hostname = parsedUrl.hostname;

            // Удаляем "www." если присутствует
            if (hostname.startsWith('www.')) {
                hostname = hostname.substring(4);
            }

            console.log('Проверяем имя хоста:', hostname);

            if (warezSites.includes(hostname)) {
                resultMessage.innerHTML = `<div class="alert alert-danger">Сайт <strong>${hostname}</strong> находится в нашем списке подозрительных сайтов, нарушающих лицензии.</div>`;
            } else {
                resultMessage.innerHTML = `<div class="alert alert-success">Сайт <strong>${hostname}</strong> не найден в нашем списке подозрительных сайтов.</div>`;
            }

        } catch (e) {
            resultMessage.innerHTML = '<div class="alert alert-danger">Неверный формат URL. Пожалуйста, введите корректный URL.</div>';
            console.error('Ошибка парсинга URL:', e);
        }
    });

    // Очищаем поле ввода и сообщение при закрытии модального окна
    checkerModal.addEventListener('hidden.bs.modal', function () {
        websiteInput.value = '';
        resultMessage.innerHTML = '';
    });
});