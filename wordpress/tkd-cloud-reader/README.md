# TKD Cloud Reader 0.1.1 — кандидат для серверної перевірки

НЕ встановлено на production. Жива схема TKD_FO_Orders не звірена.
Відомі лише локальні назви: post_type `tkd_order`, метаполя
`_tkd_order_number` та `_tkd_status`. Не WooCommerce. Без fallback.

## Перевірка до встановлення

Адміністратор/розробник має звірити чинний серверний код і базу: точний номер
TKD-..., унікальність активних номерів, одне значення кожного метаполя,
статус як непорожній ASCII token `[A-Za-z0-9_-]+` до 64 байтів і валідний
`post_modified_gmt`. Інші значення дають 503, а не припущення/перетворення.
Спочатку перевірити на staging з синтетичними даними та реальною WordPress
Application Password authentication. Наявність типу в health не доводить
сумісності всіх полів. Потрібні WordPress 5.6+ та PHP 7.4+.

## Встановлення після серверної перевірки

1. Звірити SHA-256 ZIP. У WordPress: Plugins → Add New → Upload Plugin;
   вибрати `tkd-cloud-reader-0.1.1-candidate.zip`, Install Now, Activate.
   Це ручний крок власника; пакет сам нічого не розгортає.
2. Активація додає тільки роль `tkd_cloud_reader` з `read` і
   `tkd_cloud_read_orders`. Не створює користувача або ключа. Існуюча роль
   з додатковими правами спричиняє відмову активації. Інші ролі не змінюються.
3. Users → Add New: створити окремого технічного користувача тільки з роллю
   TKD Cloud Reader. Не додавати інші ролі або адміністративні можливості.
4. У профілі цього користувача → Application Passwords створити окремий
   відкличний пароль для хмари. Основний пароль адміністратора, cookies і MFA
   не переносити. Значення передавати лише через захищені налаштування.
   API використовує нативну WP Application Password authentication через HTTPS.
   Cookie-сесія, навіть адміністративна, доступу не надає. Адміністратор та
   multisite super-admin без явного `tkd_cloud_read_orders` отримують відмову.
5. Перед хмарним запитом визначити підтримуване binding секрету для точного
   HTTPS-хоста `tkd-ukraine.com.ua`. Python auth-клієнт відкладено до перевірки
   binding: вигаданих plaintext ключів або формату proxy placeholder немає.

## Контракт читання

Лише GET (також відхиляється HEAD), обидва маршрути вимагають HTTPS,
успішного `application_password_did_authenticate` для поточного user ID
і явного права `tkd_cloud_read_orders`:

- `/wp-json/tkd-cloud/v1/health`: `post_type_exists`, `bridge_version`, `schema_version`.
- `/wp-json/tkd-cloud/v1/orders/TKD-100`: лише `id`, `number`, `status`,
  `post_modified_gmt`, `schema_version`, `bridge_version`.

Шлях приймає `[A-Za-z0-9-]{1,64}`, повний номер додатково має починатися з
`TKD-`; query/body не підміняють path number. Пошук бінарний, точний за
`_tkd_order_number` у `tkd_order`. Немає списку, suffix-пошуку або getters ТКД.
Виключено trash/auto-draft; 404 — не знайдено, 409 — дублікати серед допустимих
статусів, 503 — непідтверджена/неузгоджена схема. Не повертаються totals,
імена, телефони, email, реквізити або довільні метаполя. Код не змінює замовлень,
оплат, резервів чи залишків. Нативна WP автентифікація може оновлювати власні
службові дані використання Application Password, що не є записом замовлення.

Відповіді namespace, включно з нормалізованими REST errors, отримують
`Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0`,
`Pragma: no-cache`, `Expires: 0`, `Vary: Authorization, Cookie`.
Окремо виключити `/wp-json/tkd-cloud/v1/*` з CDN/reverse proxy/page cache:
плагін не контролює кеш, який відповідає до виконання WordPress/PHP.

## Обов'язкова серверна перевірка

На staging: відмова без ключа, cookie-only, неправильний/відкликаний ключ,
Application Password без cap (у тому числі admin/super-admin); GET з належним
технічним користувачем. Перевірити POST/PUT/DELETE/HEAD, точний номер проти
suffix/іншого регістру, дублікат 409, trash/auto-draft/відсутнє 404, збій схеми
503, мінімальні поля, заголовки помилок автентифікації і CDN cache bypass.
Зіставити один дозволений номер з серверною БД без копіювання клієнтських даних.
Не надсилати ключі/відповіді клієнтів у Git, термінальні журнали або чат.

## Відкликання та видалення

У профілі технічного користувача → Application Passwords → Revoke відповідного
ключа. Новий запит має бути відхилений. За потреби заблокувати/видалити окремого
технічного користувача стандартним WP UI. Deactivate прибирає endpoints для
наступних запитів; Delete/uninstall — no-op щодо ролей, користувачів і даних.
Ключі автоматично не видаляються: відкликати їх окремо. In-flight запити
деактивація не скасовує. Плагін не змінює privacy/sharing середовища.

## Локальні тести

З кореня репозиторію: `php tests/php/test-reader.php`.
З розпакованого plugin: `php tests/test-reader.php`.
Lint: `php -l tkd-cloud-reader.php` і `php -l uninstall.php`.
Тести використовують синтетичні WordPress stubs. Це НЕ інтеграційна перевірка
реального WordPress, його SQL, сервера, proxy, authentication чи cache.
`php tests/test-reader.php` запускається тільки CLI; тестові stubs не повинні
виконуватися через веб. Ніякі production дані не входять до fixtures.

## 0.1.1: тимчасова boolean-діагностика health401

Тільки GET health з помилкою `tkd_application_password_required` додає
`data.auth_diagnostics`: http_authorization_present,
redirect_http_authorization_present, authorization_basic_scheme,
php_auth_user_present, php_auth_pw_present, native_app_password_status_is_user,
native_app_password_user_matches_current, native_app_password_uuid_present.
Усі значення boolean. Немає значень/довжин/фрагментів/хешів заголовків,
user ID, UUID або decoded credentials. Orders401 діагностики не містить.
Це спостереження, не fallback автентифікації: чинний hook/cap/HTTPS/GET
залишено без змін. Basic scheme не доводить правильність чи підстановку секрету.

Офіційне джерело: WordPress core `wp-includes/rest-api.php`,
`rest_application_password_collect_status` (з 5.6) встановлює request-local
`wp_rest_application_password_status` (WP_User/WP_Error) та
`wp_rest_application_password_uuid`; `rest_get_authenticated_app_password`
повертає останній (з 5.7).
https://github.com/WordPress/wordpress-develop/blob/6.8/src/wp-includes/rest-api.php
Цей пакет не викликає collector і не змінює його globals.
Оновлення: вручну Upload Plugin → Replace current with uploaded; існуючу
роль/користувача/Application Password не видаляти й не створювати повторно.
Після оновлення повторити health з чинним binding і анонімно. Перевірити лише
boolean-поля, не виводити raw headers або credentials. Native WP auth errors,
які виникають до permission callback, не переписуються цим плагіном.
