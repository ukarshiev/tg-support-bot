# Последняя редакция: 09.10.2026 UTC+3

# Резервные копии TG Support Bot

TGSUPBOT-94: `scripts/backup-prod.ps1` работает на Windows-ПК через PowerShell 7; источник — ВМ `karshiev@192.168.1.101`, `/opt/tg-support-bot`.

## Что копируется

- PostgreSQL: вся база в формате `pg_dump -Fc`, файл `<db>-<UTC-метка>.dump`.
- Файлы приложения: `storage/app` и `storage/certs`, архив `storage-<UTC-метка>.tar.gz`.
- Для обоих файлов сохраняются контрольные суммы SHA256; они проверяются после доставки.
- `.env` и ключ шифрования хранятся отдельно в Bitwarden: в эти копии они не входят.
- Redis не копируется: это отдельное рабочее состояние очередей и отметок поллеров.
- `storage/logs` не копируется: журналы занимают много места и не восстанавливают данные приложения.

## Где лежат копии

Основной путь: `\\192.168.0.102\superdata\!Backups\tg-support-bot`; в проводнике — `X:\!Backups\tg-support-bot`.
Планировщик использует UNC: буква сетевого диска может быть ему недоступна.
В `daily`, `weekly`, `monthly` — пары файлов и `.sha256`; в `logs` — `backup-yyyy-MM.log`, в корне — `last-success.json`.
На ВМ промежуточные копии лежат в `/opt/tg-support-bot/backups/auto`.

## Расписание и хранение

Ежедневно в **03:30 по времени ПК**: 30 ежедневных, 12 еженедельных, 12 ежемесячных копий.
На ВМ остаются 3 последние пары; параметры `Keep*` позволяют изменить количество.
Первая копия ISO-недели и месяца попадает в свой ярус; период определяется по UTC-метке имени, не по дате изменения.
Старые пары удаляются только после успешного создания, доставки и нужных проверок.

## Проверка восстановлением и свежести

При создании еженедельной копии дамп восстанавливается на ПК в образе PostgreSQL сервиса `pgdb` из `docker-compose.yml`.
Контейнер без сети хранит базу в памяти и удаляется; проверяются таблицы `public` и строки `messages`, боевой стек не запускается.
Проверьте `utc` последнего успеха в `last-success.json`, указанную пару в `daily` и последнюю строку журнала: ожидается `OK`.
При `FAILED` предыдущий `last-success.json` сохраняется; `restoreVerified` показывает проверку дампа.

## Ручной запуск

Из `K:\GitHub\tg-support-bot-main-merge`, после разрешения первого запуска:
```powershell
.\scripts\backup-prod.ps1 -SshKey "$env:USERPROFILE\.ssh\id_ed25519_nopass"
.\scripts\backup-prod.ps1 -SshKey "$env:USERPROFILE\.ssh\id_ed25519_nopass" -VerifyRestore
```
Нужны доступ к сетевой папке, Docker на ПК и SSH без пароля с заранее доверенным ключом сервера.

## Планировщик Windows

Регистрация под текущим пользователем: только при его входе, пароль не сохраняется.
```powershell
$script = 'K:\GitHub\tg-support-bot-main-merge\scripts\backup-prod.ps1'
$key = "$env:USERPROFILE\.ssh\id_ed25519_nopass"
$pwsh = (Get-Command pwsh.exe).Source
$arguments = '-NoProfile -NonInteractive -WindowStyle Hidden -File "{0}" -SshKey "{1}"' -f $script, $key
$action = New-ScheduledTaskAction -Execute $pwsh -Argument $arguments
$trigger = New-ScheduledTaskTrigger -Daily -At '03:30'
$principal = New-ScheduledTaskPrincipal -UserId ([Security.Principal.WindowsIdentity]::GetCurrent().Name) -LogonType Interactive -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes 30) -MultipleInstances IgnoreNew
Register-ScheduledTask -TaskName 'tg-support-bot backup' -Action $action -Trigger $trigger -Principal $principal -Settings $settings
```
Удаление расписания (сами копии останутся):
```powershell
Unregister-ScheduledTask -TaskName 'tg-support-bot backup' -Confirm:$false
```

## Восстановление: только после явного «да» Владыки

Процедура восстановления на бою целиком не выполнялась; автоматически проверяется только восстановление дампа во временный контейнер.
Согласуйте UTC-метку, потерю новых данных и откат; передайте выбранную пару и `.sha256` на ВМ в `backups/restore`.
Команды ниже выполняются вручную на ВМ; подставьте имя базы, пользователя и метку.
```bash
cd /opt/tg-support-bot
db_name='<имя базы>'; db_user='<пользователь БД>'; stamp='<UTC-метка копии>'
mkdir -p backups/restore
test -s "backups/restore/${db_name}-${stamp}.dump"
(cd backups/restore && sha256sum -c "${db_name}-${stamp}.dump.sha256" && sha256sum -c "storage-${stamp}.tar.gz.sha256")
docker compose exec -T pgdb pg_dump -U "$db_user" -d "$db_name" -Fc > "backups/before-restore-$(date -u +%Y%m%d-%H%M%S).dump"
```
Убедитесь, что свежий дамп непустой и читается через `pg_restore --list`; без него остановитесь.
Остановите запись и сохраните окончательное текущее состояние для отката:
```bash
docker compose stop nginx app queue reverb scheduler telegram_poller ai_telegram_poller
docker compose exec -T pgdb pg_dump -U "$db_user" -d "$db_name" -Fc > backups/restore/rollback.dump
test -s backups/restore/rollback.dump
docker compose run --rm --no-deps -T --entrypoint tar app -czf - -C /var/www/storage app certs > backups/restore/rollback-storage.tar.gz
test -s backups/restore/rollback-storage.tar.gz
tar -tzf backups/restore/rollback-storage.tar.gz > /dev/null
docker compose cp backups/restore/rollback.dump pgdb:/tmp/rollback.dump
docker compose exec -T pgdb pg_restore --list /tmp/rollback.dump > /dev/null
docker compose exec -T pgdb rm -- /tmp/rollback.dump
docker compose cp "backups/restore/${db_name}-${stamp}.dump" pgdb:/tmp/restore.dump
docker compose exec -T pgdb pg_restore --list /tmp/restore.dump > /dev/null
docker compose exec -T pgdb pg_restore -U "$db_user" --exit-on-error --clean --if-exists --no-owner --no-privileges -d "$db_name" /tmp/restore.dump
docker compose exec -T pgdb rm -- /tmp/restore.dump
docker compose run --rm --no-deps -T --entrypoint tar app -xzf - -C /var/www/storage < "backups/restore/storage-${stamp}.tar.gz"
docker compose exec -T pgdb psql -U "$db_user" -d "$db_name" -c "SELECT count(*) FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE'; SELECT count(*) FROM messages;"
```
Сравните таблицы с копией, проверьте файлы и ключ шифрования из Bitwarden; затем запустите:
```bash
docker compose up -d --no-build nginx app queue reverb scheduler telegram_poller ai_telegram_poller
docker compose ps
```
При ошибке не запускайте сервисы; откат — из свежего дампа и архива. Распаковка не удаляет лишние файлы: согласуйте их отдельно.

## Известные ограничения

Копия БД и архив файлов создаются последовательно: это не единый снимок работающего приложения.
Нужны включённый ПК и вход пользователя; жёсткий лимит задания может оборвать долгую проверку.
Хранилище находится на том же физическом сервере, что и ВМ: потеря сервера уничтожит обе копии.
Оповещения о сбое нет: смотрите журнал и `last-success.json`. Рекомендуется также backup ВМ в Proxmox.

## Что сделать, чтобы применить изменения:

Первый запуск с проверкой восстановлением выполнен 09.10.2026 оркестратором: 36 таблиц, 4301 строка `messages`.
1) Сохранить APP_KEY из `.env` ВМ в Bitwarden — Почему: без него секреты из дампа не расшифровать.
2) Зарегистрировать расписание выше — Почему: получать копии ежедневно в 03:30.
3) Проверять журнал и свежесть `last-success.json` — Почему: автоматических оповещений пока нет.
