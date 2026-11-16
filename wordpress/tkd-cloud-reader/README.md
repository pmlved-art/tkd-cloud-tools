# TKD Cloud Reader 0.2.0 — read-only integration candidate

Пакет для ручного review та оновлення. Не розгорнуто на production.
Це необхідний етап звірки живих модулів, не завершене підключення записів.
Локальні TKD_FO файли 6–15 листопада не доводять відповідність live коду.
Не WooCommerce: тільки `tkd_order`, `_tkd_order_number`, `_tkd_status`.
Потрібні WP 5.6+, PHP 7.4+; stock потребує 64-bit PHP.

## Доступ, встановлення, відкликання

Усі шість маршрутів тільки GET, з єдиною permission_callback: HTTPS,
успішний native WordPress Application Password hook для поточного user ID,
явне `tkd_cloud_read_orders` плюс current_user_can. Cookie-only, admin чи
super-admin без явного cap відхиляються. POST/PUT/PATCH/DELETE/HEAD не відкрито.
Роль незмінна: тільки `read` і `tkd_cloud_read_orders`; конфліктна існуюча роль
спричиняє відмову активації. Оновлення не створює акаунтів/ключів, не змінює
інші ролі. Існуючі `tkd-cloud`, його роль та Application Password залишаються.
Details містить персональні дані: той самий cap тепер дозволяє цю захищену
проєкцію, власник явно дозволив таке читання. Не надавати роль стороннім.

Після review: Plugins → Add New → Upload Plugin → вибрати ZIP → Replace current
with uploaded. Перевірити SHA256 до встановлення. Не видаляти користувача/ключ.
Усі namespace-відповіді, включно з нормалізованими REST errors, отримують
`Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0`,
`Pragma: no-cache`, `Expires: 0`, `Vary: Authorization, Cookie`.
Виключити `/wp-json/tkd-cloud/v1/*` з CDN/page cache: плагін не контролює кеш,
що відповідає до PHP. Native auth може оновити службові дані last-used ключа;
плагін не виконує business writes. Deactivate прибирає маршрути наступних
запитів. Uninstall no-op: дані, роль і ключі не видаляються. Відкликання ключа:
профіль `tkd-cloud` → Application Passwords → Revoke відповідного ключа; окремо
відкликати cloud binding. Не використовувати основний пароль адміністратора.

## GET-контракт, prefix /wp-json/tkd-cloud/v1

### /health

Незмінні `post_type_exists`, `bridge_version`, `schema_version`.
Health200 підтверджує тільки існування типу, не сумісність усієї схеми.
Schema `tkd-order-minimal-v1-candidate`. Збережено health401 boolean diagnostics
з 0.1.1, без значень/довжин/фрагментів/хешів заголовків або декодування Basic.
Core status/UUID лише спостерігаються; fallback автентифікації не додано.

### /orders/{full-number}

Повний `TKD-...`, 1–64 ASCII alnum/hyphen; точний BINARY `_tkd_order_number`
у `tkd_order`. GET повертає лише `id,number,status,post_modified_gmt,
schema_version,bridge_version`. Не suffix і не довільний meta API.
Trash/auto-draft виключені, missing404, duplicate409, malformed schema503.

### /orders?number=10069 або ?number=TKD-FULL-NUMBER

Один exact full-number або numeric suffix 1–18 цифр. Suffix має бути цілим
останнім сегментом; не substring, не latest, не guessing. Prepared SELECT у fixed WP posts/postmeta: BINARY prefix і RIGHT equality
для повного suffix-сегмента, потім точне перечитування повного номера.
Без binary REGEXP (несумісного з деякими MySQL 8 конфігураціями). Максимум два
кандидати для виявлення дубліката: 409 за неунікальності, 404 за відсутності.
Повертає ту саму мінімальну відповідь. Параметри крім number відхиляються400.
Recent-list навмисно не реалізовано в цій версії.

### /orders/{full-number}/details

Schema `tkd-order-details-v1-candidate`. Базові мінімальні поля та `metadata`,
`revision`, `revision_scope: informational-read-snapshot`. `metadata` має лише:

```
_tkd_order_number
_tkd_status
_tkd_customer_name
_tkd_customer_phone
_tkd_customer_email
_tkd_client_total
_tkd_confirmed_amount
_tkd_prepayment_received
_tkd_paid_at
_tkd_fo_order_items
_tkd_fo_payments
_tkd_fo_invoice_id
_tkd_fo_stock_state
_tkd_fo_reservation_stage
_tkd_fo_reservation_until
```

Кожне поле `{present: bool, value: ...}`: missing → false/null, stored null →
true/null, stored zero → true/"0" або "0.00". Amounts — decimal strings,
до 12 цілих/2 дробових цифр, без exponent/comma/rounding. Scalar text UTF-8,
до 512 байтів, без control characters. Invoice ID — digits/empty string,
до 18 цифр. Дубльоване метаполе → 409. Number/status звіряються з базовою
відповіддю, інші schema failures503. Клієнт відхиляє невідомі поля відповіді.

Items — sparse scalar projection тільки:
`sku,name,quantity,unit_price,item_id,unit`. `price` НЕ є полем ціни.
Quantity до 3 дробових цифр, unit_price до 2; stored number/string приймаються
без округлення, повертаються рядками. Optional item_id зберігається тільки
як рядок/int→string. Невідомі поля та nested opaque не повертаються.

Payments — sparse stored-row projection тільки:
`id,amount,fee,net_amount,recipient_key,channel_key,received_at,source,status,
created_at,settled_at,settlement_date_precision,settlement_date_source`.
Не повний payment record: payer_name, reference, bank_transaction_id,
user_id, note, bank_synced_at, settlement_payer та довільні tokens відсутні.
Missing nested fields лишаються missing; stored null лишається null.
Не обчислюється payment total, не підсумовуються void rows. Legacy aggregate
може зберігатися окремо: жодного твердження про reconciliation/отримані кошти.
Дубльований непорожній payment id або malformed arrays →503.

До 100 items і 100 payments, list-only arrays, максимум 64 полів рядка,
name до 512, інші nested text до 128, id/item_id до 64 байтів.
Вхід: до 4096 nodes, depth8 і сумарно128KiB scalar strings; об'єкти відхиляються,
не викликається JsonSerializable. Тіло details до128KiB; перевищення →503.
GET не нормалізує/не reconcile, не викликає get_order_items/get_responsibility
або будь-які бізнес-getters. Збережені значення не переписуються.

Revision SHA256 відповідає релевантній проєкції snapshot; той самий quoted
revision у ETag. Це НЕ транзакційний snapshot, lock або write CAS/precondition.
Послідовні reads можуть перетнути конкурентну зміну. Перед майбутнім write
adapter потрібні нативні lock/version/preflight/idempotency/audit.

### /integration-manifest

Schema `tkd-integration-manifest-v1-candidate`. Жодних параметрів class/path/file.
Фіксовані class names:

```
TKD_FO_Orders
TKD_FO_Payments
TKD_FO_DB
TKD_FO_Invoices
TKD_FO_Deliveries
TKD_FO_Inventory_Admin
TKD_FO_Mobile_Cabinet_20261011
TKD_FO_Order_Editor
TKD_FO_Customers
TKD_FO_Phone_Orders
TKD_FO_Order_Comments
```

Для кожного: name, present, file_sha256 (або null), versions, methods.
Лише вже завантажені класи (`class_exists(name,false)`, autoload не запускається).
Відсутній клас → present false, порожні methods, без fallback.
Versions: global TKD_FO_VERSION та public class VERSION/DB_VERSION/SCHEMA_VERSION,
лише bounded version-token. Локальні 0.1.223/DB3 не hardcoded як live evidence.
Методи: тільки public static (включно з inherited), name, required_args,
total_args, source_sha256. Method body НІКОЛИ не виконується.

File hash — SHA256 точних байтів. Method hash — inclusive source lines
Reflection getStartLine/getEndLine: оригінальні LF/CRLF включно з фінальним
newline, якщо він є. Docblock до start line не входить. Методи на одному
рядку мають той самий line slice; це не AST/semantic hash. Хеші стосуються файлів на диску, не є доказом актуальності OPcache.
Paths/source/default
values/parameter values/довільні constants не повертаються. Розбіжність method
або file hash потребує review, не автоматичного дозволу запису.

Максимум 500 methods сумарно, source file2MiB, 64 files, aggregate8MiB,
50000 LF/file, output512KiB. Lines кешуються лише в межах запиту. Перевищення
дає явну503, без silent truncation. Обмеження можуть вимагати подальшого review
на реальному наборі модулів, а не автоматичного підняття меж.

Stock schema: тільки фіксований suffix `tkd_fo_stock` з `$wpdb->prefix`,
без виклику TKD_FO_DB::stock_table/install/maybe_upgrade/getters.
SHOW COLUMNS повертає лише name/type/precision/scale для відомих:
`id,sku,name,unit,price,exw_price_eur,cost_price_uah,on_hand,reserved,incoming,
page_id,active,created_at,updated_at`. Ніяких значень/cost/defaults/повного prefix.
До64 columns у схемі; невідомі columns не виводяться. State unavailable,
incompatible або read-compatible. Останнє означає лише guard для stock read,
не сумісність бізнес-логіки/write. Нестача stock schema не ховає hashes класів.

### /stock?limit=25&cursor=0 або /stock?sku=EXACT-SKU

Schema `tkd-stock-read-v1-candidate`. Спочатку SHOW COLUMNS fixed table,
потім schema guard, і тільки після нього bounded SELECT:
`id,sku,name,unit,on_hand,reserved,incoming,active,updated_at`.
Schema guard перевіряє наявність/тип усіх дев'яти колонок. Quantities тільки
integer/decimal (scale<=6), float/double schema відхиляється. Не вгадує колонки.
ID/active integer types, sku/name/unit text types, updated_at datetime/timestamp.
Немає cost/prices у rows або SQL SELECT. Decimal values — рядки (до12 цілих,
6 дробових), точне subtraction у 64-bit fixed scale6:
`available_on_hand_minus_reserved = on_hand - reserved`; incoming НЕ додається.
Негативна різниця повертається без виправлення. active передається як stored0/1,
рядки не ховаються автоматично за active, щоб не змінювати сенс stored view.

Limit1..50 (default25), numeric id cursor до18 цифр, ASC, lookahead1;
`next_cursor` — останній повернутий id за наявності наступної сторінки, інакше
null. Це не consistent multi-page snapshot. SKU — BINARY exact equality,
до128 UTF-8 bytes, limit2 для unique detection, duplicate409/missing404.
SKU і cursor не поєднуються. Довільні sort/column/filter не приймаються.
Параметри лише limit/cursor/sku. Output128KiB; malformed schema/rows/DB error503.
Помилки DB не повертають SQL/table-prefix/details, suppression відновлюється.

## Клієнт, тести, наступний етап

Із кореня checkout:

```
python3 -m scripts.reader
python3 -m scripts.reader --command manifest
python3 -m scripts.reader --command details --number TKD-FULL-NUMBER
python3 -m scripts.reader --command lookup --number 10069
python3 -m scripts.reader --command stock --limit 25 --cursor 0
python3 -m scripts.reader --command stock --sku SYNTHETIC-SKU
```

CLI завжди повертає лише redacted verification summary. Programmatic
`read_bridge(command="manifest", include_data=True)` та відповідний details/stock
виклик можуть дати перевірену проєкцію в пам'яті; її не логувати/не зберігати
в Git. CLI не має перемикача друку персональних даних. Персональні
поля, номери, статуси, headers/body/credentials не логуються. Клієнт перевіряє
схему/версію/no-store, для details ETag, для stock exact decimal result.
Health/minimal сумісні з 0.1.0,0.1.1,0.2.0; нові команди вимагають 0.2.0.
Endpoint-specific caps: health/lookup/minimal16KiB, details/stock128KiB,
manifest512KiB. Fixed HTTPS host443, inherited managed proxy/CA, без redirects.
Existing literal full Authorization binding без перетворення/читання секрету.
Вивід verified доводить контракт відповіді, не правильність всіх бізнес-даних.

```
python3 -m unittest discover -s tests -v
php tests/php/test-read-extensions.php
php tests/php/test-manifest-bounds.php methods
php tests/php/test-manifest-bounds.php bytes
```

У ZIP тести лежать у `tests/`, тому `php tests/test-read-extensions.php` тощо.
Тільки synthetic fixtures/WP stubs, НЕ live WordPress SQL/auth/cache integration.
CLI guards не дозволяють запуск harness через веб. Жодних клієнтських даних.
Live staging review обов'язковий: cookie/auth denial, exact/suffix duplicates,
raw stored shapes/zero/missing, no-store на auth errors, stock DB guard,
метод/file hashes проти актуального серверного коду. Нічого не deploy автоматично.

Наступний етап тільки після live manifest/source review: конкретні native
services з locks/revision/preflight/idempotency/audit. НЕ відкрито жодних writes.
record_payment сам по собі не покриває UI seller/bank/overpayment rules;
change_stock має власні транзакції й не є готовим CAS API. Прямі метазаписи,
SQL writes та наївна зовнішня транзакція заборонені цим пакетом.
