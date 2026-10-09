# Последняя редакция: 09.10.2026 UTC+3

# Резервные копии TG Support Bot

TGSUPBOT-94. С 09.10.2026 основной слой — копии ВМ 101 и хоста Proxmox. Старое задание на Windows-ПК пока работает параллельно; его снимет оркестратор после проверки ночных запусков.

## Действующая схема Proxmox

Время ниже — московское (MSK): часы хоста Proxmox идут по Москве, проверено оркестратором.

| Когда | Что | Куда | Хранение |
| --- | --- | --- | --- |
| 01:00 | `pve-git-mirror.sh`: код с GitHub | superdata и Synology, пути ниже | 10 последних снимков на репозиторий |
| 01:30 | `pve-os-backup.sh`: операционка хоста | superdata и Synology, пути ниже | 7 последних на каждом носителе |
| 02:00 | `pve-host-backup.sh`: настройки хоста | те же два каталога | 30 последних на каждом носителе |
| 02:00 | `scripts/vm/backup-db.sh` на ВМ 101: база бота и файлы | `/opt/tg-support-bot/backups/daily`; на оба носителя внутри копии ВМ | 14 последних |
| 02:30 | `nightly-vm101`: ВМ 101 целиком | `superdata-backup` = `/superdata/share/pve-backup`; с ПК `X:\!Backups\proxmox\dump` | 7 ежедневных / 4 еженедельных / 6 ежемесячных |
| 03:15 | `nightly-vm101-synology`: ВМ 101 целиком | `synology-backup` = NFS `192.168.0.101:/volume2/2.9 Backups/Ubuntu` | 7 / 4 / 6 |

ВМ 100 и 102 не копируются по решению владельца. Отдельные копии кода Plane и OpenClaw не делаются: их код, настройки и данные лежат внутри ВМ 101 и уходят с её копией. Задания vzdump скрипты не меняют.

**Код GitHub.** `pve-git-mirror.sh` копирует все репозитории учётной записи `ukarshiev`, включая закрытые. Список берётся из GitHub API каждую ночь; новые репозитории подхватываются автоматически. Основной доступ — вход владельца через `gh` на хосте под root: `gh auth login --hostname github.com --git-protocol https --web`. Запасной вариант — ключ одной строкой в `/root/.config/pve-git-mirror/token` (root, каталог 700, файл 600); если файл существует, он имеет приоритет перед `gh`, даже если пуст или небезопасен. Без доступа копируется только публичный список `REPOSITORIES` из начала скрипта, сейчас `ukarshiev/tg-support-bot`; при ошибке API также используется этот список, но результат запуска — ошибка. Ключ `gh` даёт хосту право записи в репозитории: это осознанный выбор владельца ради простоты; сам скрипт только читает GitHub.

Локальное зеркало — `/superdata/share/pve-backup/github/<имя>.git`, снимки — `/superdata/share/pve-backup/github/<имя>/<имя>-ГГГГММДД-ЧЧММСС.bundle`. Вторая копия — NFS `192.168.0.101:/volume2/2.9 Backups/github`, каталог `<имя>`; отдельная точка монтирования `/mnt/synology-github`. Снимок создаётся только при изменении ссылок репозитория; хранятся 10 последних снимков на репозиторий на каждом носителе. Если последний снимок отсутствует на Synology, он докопируется из локального каталога без создания нового. Удалённый на GitHub репозиторий из зеркал и снимков не удаляется.

Журнал копии кода — `/var/log/pve-git-mirror.log`: `OK token gh 0` или `OK token file 0` показывает источник доступа, `OK unchanged <имя>` — отсутствие изменений. Проверяйте результат текущего запуска и наличие последнего снимка на обоих носителях: при отсутствии изменений дата снимка остаётся прежней. Восстановление — `git clone <файл>.bundle`. Копируется только отправленное на GitHub: ветки, существующие только на ПК, не входят.

**База и файлы на ВМ.** `backup-db.sh` запускает crontab пользователя `karshiev`: `0 23 * * *`. Часы ВМ идут по UTC: 23:00 UTC = 02:00 MSK, за полчаса до копирования всей ВМ. Дамп базы бота и архив файлов лежат в `/opt/tg-support-bot/backups/daily`, имена — по UTC, хранение — 14 последних. По воскресеньям выполняется пробное восстановление в одноразовый контейнер без сети. Журнал — `/opt/tg-support-bot/backups/backup-db.log`, отметка свежести — `last-success.json` в `daily`; сверяйте её дату с файлами и журналом. Отдельно эти файлы никуда не доставляются: они попадают на оба носителя внутри копии ВМ.

Копии **хоста** лежат в `/superdata/share/pve-backup/host` (root, права 700) и на Synology в NFS `192.168.0.101:/volume2/2.9 Backups/Proxmox`. Общая точка `/mnt/synology-pve-host` монтируется только на время доставки; скрипты используют общий замок NFS. Если зашифрованный том NAS после перезагрузки не разблокирован, локальная копия сохраняется, а результат запуска — ошибка с записью в журнале.

Операционка: `pve-os-YYYYMMDD-HHMMSS.tar` и `.sha256`. Внутри — сжатые root и EFI, GPT системного диска, метаданные LVM, сведения о дисках, отдельная согласованная копия `pve-cluster/config.db` и `RESTORE.txt`. Root берётся из временного LVM-снимка; EFI читается отдельно. Не входят диски ВМ, ISO, кеш пакетов и содержимое ZFS-пула superdata. Снимок root и база настроек сняты в разное время: во время копирования лучше не менять конфигурацию хоста.

Настройки: `pve-settings-YYYYMMDD-HHMMSS.tar.zst` и `.sha256`; содержат `/etc` (включая `/etc/pve`), `/root`, cron, `/usr/local`, сведения о системе и отдельную копию `config.db`. Архивы проверяются перед чисткой старых поколений. При ошибке снятия/проверки базы настроек успех не объявляется.

### Установка и проверка свежести

Устанавливает **только оркестратор**: на хосте нужны пакеты `git` и `gh` (`apt install git gh`); копирует `pve-git-mirror.sh`, `pve-os-backup.sh` и `pve-host-backup.sh` из `scripts/proxmox` в `/usr/local/sbin`, делает исполняемыми; `pve-host-backup.cron` копирует в `/etc/cron.d/pve-host-backup`. Владелец root, `.sh` — 700, cron — 644; окончания строк LF. На ВМ оркестратор отдельно ставит `scripts/vm/backup-db.sh` в `/opt/tg-support-bot/scripts/backup-db.sh` и строку в crontab `karshiev`; `deploy-proxmox.ps1` этот скрипт не копирует. Одного изменения файлов в репозитории недостаточно: текущая редакция на хост ещё не установлена.

Журналы: `/var/log/pve-os-backup.log` и `/var/log/pve-host-backup.log`. Каждое утро сверяйте дату последних `OK local` и `OK synology`, наличие соответствующих архивов и `.sha256` на обоих носителях, а также отсутствие последующих `FAIL`. Контрольную сумму проверяют из каталога копии командой `sha256sum -c <имя-архива>.sha256`. Наличие старого `OK` не доказывает успех сегодняшнего запуска. Копии ВМ проверяют по результатам обоих заданий в Proxmox и дате файлов в обоих хранилищах.

До правок оркестратор проверил ручные запуски: операционка — 128 секунд, 2,96 ГБ, совпадение SHA256 на обоих носителях и чтение архива; снимок и монтирования убраны. Настройки — 3 секунды, 1,3 МБ. Это проверка прежнего кода: исправленную редакцию и ночные запуски ещё должен проверить оркестратор. Здесь скрипты и тесты не запускались.

### Восстановление и ограничения

ВМ 101 восстанавливают из выбранной копии vzdump только после согласования с Владыкой. Для хоста предпочтительна переустановка той же версии Proxmox с последующим выборочным восстановлением настроек. Полное восстановление операционки из архива **не репетировалось**; `RESTORE.txt` — ориентир, а не проверенная пошаговая процедура. Нельзя вслепую применять GPT/LVM-метаданные к дискам с данными или заменять базу `/etc/pve` у работающего сервиса. Диски Samsung и superdata не форматировать; загрузчик EFI — GRUB.

Архивы содержат секреты. Шифрование архивов и оповещения не добавляются по решению владельца; на Synology действует отображение пользователей в admin и права файлов 777, поэтому доступ ограничивается на стороне NAS. Следить за журналами нужно вручную. Копия на superdata не спасает от потери всего сервера; для этого нужна вторая копия на NAS.

Таймауты ограничивают команды, но не гарантируют освобождение процесса при зависании ядра/диска. Для изменений LVM запрещено принудительное убийство: ожидание LVM-замков отключено, по таймауту посылается только SIGINT, чтобы не оборвать переключение работающего root. SIGKILL и потеря питания не дают выполнить очистку: после такого сбоя оркестратор проверяет снимок `pve/root-bksnap` и точки монтирования. Неожиданное уже занятое монтирование скрипты оставляют нетронутым и сообщают ошибку.

## Временный слой: копии приложения с Windows-ПК

`scripts/backup-prod.ps1` работает через PowerShell 7; источник — ВМ `karshiev@192.168.1.101`, `/opt/tg-support-bot`. Существующее расписание оставляем до проверки ночных копий Proxmox; повторно регистрировать его не нужно.

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

Задание использует штатный OpenSSH Windows; проверяйте скрипт из обычного PowerShell, а не из Git Bash, где используется другой scp.
Копия БД и архив файлов создаются последовательно: это не единый снимок работающего приложения.
Нужны включённый ПК и вход пользователя; жёсткий лимит задания может оборвать долгую проверку.
Хранилище находится на том же физическом сервере, что и ВМ: потеря сервера уничтожит обе копии.
Оповещения о сбое нет: смотрите журнал и `last-success.json`. Основные копии ВМ уже настроены в Proxmox по схеме выше.

## Что сделать, чтобы применить изменения:

Первый запуск с проверкой восстановлением выполнен 09.10.2026 оркестратором: 36 таблиц, 4301 строка `messages`.
1) Сохранить APP_KEY из `.env` ВМ в Bitwarden — Почему: без него секреты из дампа не расшифровать.
2) Сохранить действующее расписание ПК до проверки ночных копий Proxmox; затем оркестратор снимает задание ПК.
3) Проверять журнал и свежесть `last-success.json` — Почему: автоматических оповещений пока нет.
