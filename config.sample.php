<?php
// Скопируйте в config.php и заполните. config.php не попадает в git.
return [
    // Адрес, где развёрнут сервис (для ссылок в задачах и QR-кодах)
    'base_url' => 'http://localhost:8080',
    // Входящий вебхук Битрикс24 с правами task, user: https://ПОРТАЛ.bitrix24.by/rest/1/КЛЮЧ/
    'b24_webhook' => '',
    // ID ответственного в Б24 для задач по отделам (если не задано — автор вебхука)
    'b24_responsible' => ['sklad' => 1, 'shop' => 1, 'sales' => 1, 'office' => 1],
    // Пароль для сброса демо-данных
    'demo_reset_key' => 'novichok',
];
