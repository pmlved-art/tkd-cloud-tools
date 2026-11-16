# Перевірка хмарного читання — 2026-11-17

Після публікації виправленого секрету поточна конфігурація підтверджена:
cecfgver_6afb8638d534819ebacaead52edd328c.
Runtime observations current; binding TKD_CLOUD_AUTHORIZATION ready.
Після тимчасового reconnect виконавець став доступним без змін конфігурації.

Перевірено зі свіжих з'єднань через штатний proxy з TLS та без редиректів:

- `python3 -m scripts.reader`: HTTP 200, health_verified.
- Один повний номер, прямо дозволений власником: HTTP 200, order_schema_verified.
- Bridge 0.1.1, schema tkd-order-minimal-v1-candidate; клієнт перевірив точний
  запитаний номер, мінімальні поля та Cache-Control no-store.
- Анонімний health: HTTP 401 tkd_application_password_required,
  усі 8 boolean diagnostics false, Cache-Control no-store присутній.

Секрети, raw headers/body і дані замовлення не збережено. Ніяких операцій запису
замовлень, оплат, резервів або складу не виконано. Перевірка одного замовлення
не є перевіркою всіх статусів/замовлень чи дозволом на майбутні мутації.

Запуск у Cloud з кореня /workspace/tkd-cloud-tools:
`python3 -m scripts.reader`.
Читання одного дозволеного номера: `python3 -m scripts.reader --number TKD-FULL-NUMBER`.
Клієнт виводить тільки redacted verification summary. Не копіювати cloud
placeholder або credentials на Mac. Mac може використовувати клієнт лише в
окремо налаштованому дозволеному proxy/secret середовищі; звичайний локальний
запуск без managed proxy навмисно відхиляється. Для Mac створення значення
Authorization описано в cloud-reader-connection.md, офлайн HTML зберігає
секрет лише в пам'яті сторінки. Готовий результат треба вручну скопіювати Cmd+C.
