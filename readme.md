# Настройка интеграции amoCRM с сервисом "Яндекс касса"
### Tech
* [Laravel](https://laravel.com)
* [AmoCRM API Client](https://github.com/ufee/amoapi)
* [Yandex.Checkout API PHP Client Library](https://github.com/yandex-money/yandex-checkout-sdk-php)
* SQLite

### Структура приложения
`app/CustomClass/AmoYandex.php` - Класс для удобства работы
`config/amoKassa.php` - Конфиги

### Структура на сервере
`/www/l5` - Каталог приложения
`/www/method-loskutova.center/comf5/index.php` - Точка входа в приложение
`/www/method-loskutova.center/go.php` - Точка входа в приложение обрабатывает только индекс