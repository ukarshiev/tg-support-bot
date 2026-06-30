0.1.2 – 30.06.2026 18:41
- [Исправление] (Docker) KAR-276 — Добавлен host.docker.internal:host-gateway для app/queue/scheduler, чтобы tg-support-bot мог обращаться к локальному PostEditBot API из контейнера.

0.1.1 – 30.06.2026 18:18
- [Исправление] (Docker) KAR-276 — Добавлен отдельный локальный nginx-конфиг для Docker overlay, чтобы админка поддержки открывалась по HTTP без изменения upstream-шаблона.

0.1.0 – 30.06.2026
- [Новый функционал] (Support Bridge) KAR-276 — Добавлена базовая интеграция с PostEditBot: отдельный модуль, настройки UI, карточка клиента, AI-контекст, Docker override и runbook обновления из upstream.


