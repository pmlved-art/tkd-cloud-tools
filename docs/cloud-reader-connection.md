# Підключення TKD Cloud Reader

## Перевірено із хмари 2026-11-17

Через штатний proxy з TLS: REST index HTTP 200, namespace `tkd-cloud/v1`,
GET `/health`, GET `/orders/(?P<number>[A-Za-z0-9-]{1,64})`.
Анонімний health і `TKD-CLOUD-NONEXISTENT-CHECK` повернули HTTP 401,
`tkd_application_password_required`, лише code/message/data, без полів замовлення.
Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0;
Pragma: no-cache; Expires: 0; Vary: Authorization,Cookie,Origin.
Зовнішній контракт відповідає commit 2601fe435a27bba9dd599ed2a616e3b3d088bf4b.
Це не підтверджує тотожність серверного PHP, версію bridge або живу схему:
для них ще потрібен авторизований health і серверна звірка.

## Захищений binding

Штатний Cloud network proxy підмінює буквальний placeholder тільки на
дозволеному HTTPS destination. За інструкціями runtime `networking.md` і
onboarding `onboarding.md`, його не можна вважати raw Application Password,
кодувати Base64 або передавати довільним хостам. Клієнт використовує один
секрет `TKD_CLOUD_AUTHORIZATION` — ПОВНЕ значення заголовка Authorization.
Воно може бути literal placeholder у процесі; клієнт вставляє його без змін.
Немає власних ключів API, самостійного розв'язання placeholder або cookie auth.
Реальна підстановка ще потребує першого authorized health після secure setup.

1. WordPress → Users → All Users → `tkd-cloud`: переконатися, що роль тільки
   TKD Cloud Reader, без адміністративних/інших ролей. Відкрити профіль саме
   цього користувача → Application Passwords → додати окремий ключ для Cloud.
   Не використовувати основний пароль або ключ адміністратора.
2. Завантажити `tools/application-password-header.html`, відкрити файл локально
   через file:// (можна offline). Ввести логін `tkd-cloud` та його Application
   Password. Сформувати й виділити ПОВНЕ значення `Basic …`.
   Це оборотне кодування, тому результат так само секретний, як пароль.
   Сторінка без network/storage/clipboard API. Не зберігати заповнену сторінку.
3. Cloud environment settings → Secrets: заповнити захищене значення
   `TKD_CLOUD_AUTHORIZATION` цим повним рядком. Destination тільки
   `tkd-ukraine.com.ua`, target environment variable `TKD_CLOUD_AUTHORIZATION`.
   Це саме network secret, не звичайна несекретна environment variable.
   Не вставляти значення в чат, Git, shell arguments або install/start scripts.
   Зберегти/застосувати налаштування; якщо UI вимагає Publish — це ручний крок
   власника. Draft save сам не застосовує секрет до поточної машини.
4. Очистити сторінку, закрити вкладку й очистити буфер обміну. Перевірити state
   binding у runtime, не друкувати значення. Спочатку виконати тільки health:

   `python3 -m scripts.reader`

5. Після health, серверної звірки схеми й дозволу на конкретне замовлення:
   `python3 -m scripts.reader --number TKD-FULL-NUMBER`.
   Реальний номер не вводити в спільну історію shell. У programmatic flow
   передати номер у read_bridge(number) з дозволеного джерела, без логування.

Клієнт має фіксований HTTPS host/port `tkd-ukraine.com.ua:443`, тільки GET,
використовує inherited proxy/CA, відмовляє при відсутності proxy чи bypass,
забороняє редиректи, обмежує тіло 16 KiB, перевіряє версію/схему/no-store.
На stdout тільки факт health/order schema verification або код помилки.
Номер, id, status, credentials, headers, body й exception detail не друкуються.
Клієнт не замінює повноцінний інтерфейс робочих даних і не пише на сайт.
Реальні 401/403 слід діагностувати без обходу WordPress/proxy/TLS.

Відкликання: профіль `tkd-cloud` → Application Passwords → Revoke саме ключа
Cloud; перевірити відмову нового health. Очистити/відкликати й cloud binding.

## Локальна перевірка

`python3 -m unittest discover -s tests -v`

`node tests/test_header_page.cjs`

Тести синтетичні: перевіряють literal header, exact host/443, GET/TLS/proxy,
відмову редиректів, bounds/schema/redaction та offline encoding/очищення.
Вони не доводять справність реальної proxy secret substitution чи WP auth.
