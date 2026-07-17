# Безопасность внешних интеграций

## Проверка исходящего webhook

Получатель должен проверять подпись **до** разбора JSON:

1. Найти секрет по `X-Tg-Support-Key-Id`.
2. Отклонить timestamp старше 5 минут или более чем на 5 минут из будущего.
3. Вычислить `HMAC-SHA256(timestamp + "." + webhook_id + "." + raw_body)`.
4. Сравнить `v1=<hex>` через `hash_equals`.
5. Сохранить `X-Tg-Support-Webhook-Id` и не обрабатывать его повторно.

```php
$expected = 'v1=' . hash_hmac(
    'sha256',
    $timestamp . '.' . $webhookId . '.' . $rawBody,
    $secret,
);

if (abs(time() - (int) $timestamp) > 300 || ! hash_equals($expected, $signature)) {
    http_response_code(403);
    exit;
}
```

При ротации сначала установите у получателя pending-секрет, затем активируйте его в панели. Идентификатор доставки не меняется при повторах, timestamp и подпись пересчитываются для каждой попытки.

## Выкладка и финализация токенов

Команда `php artisan security:external-preflight` блокирует выкладку при наличии legacy Widget-ключей или bearer-токенов без SHA-256.

Через 24 часа после совместимого релиза:

1. Проверьте `last_used_at` существующего токена.
2. Выполните `php artisan external-tokens:finalize --force`.
3. Убедитесь, что plaintext `token` очищен и секретов нет в логах.

Widget принимает только короткоживущий `X-Widget-Token`, который доверенный External-клиент получает через endpoint выдачи Widget-сессии.
# Telegram file proxy

Telegram-вложения выдаются только по временным подписанным URL из поля
`file_url` External API или `attachment_urls` Widget API. Ссылка действует 15
минут, привязана к `file_id` и режиму `inline`/`attachment` и не должна
кэшироваться или сохраняться клиентом. Прямой `/api/files/{file_id}` без
подписи возвращает `403`. Новый клиент использует `GET`; подписанный `POST`
сохранён временно только для совместимости.
