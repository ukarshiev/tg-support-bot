<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Приглашение в команду поддержки</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #F9FAFB; margin: 0; padding: 32px 16px; color: #1A1D26; }
        .card { background: #FFFFFF; border-radius: 12px; max-width: 520px; margin: 0 auto; padding: 40px 36px; border: 1px solid #E5E7EB; }
        h1 { font-size: 20px; font-weight: 700; margin: 0 0 8px; }
        p { font-size: 14px; color: #6B7280; margin: 0 0 20px; line-height: 1.6; }
        .credentials { background: #F3F4F6; border-radius: 8px; padding: 16px 20px; margin: 20px 0; }
        .credentials p { margin: 4px 0; font-size: 14px; color: #1A1D26; }
        .credentials strong { font-weight: 600; }
        .btn { display: inline-block; background: #4F6EF7; color: #FFFFFF; text-decoration: none; border-radius: 10px; padding: 12px 28px; font-size: 14px; font-weight: 600; margin: 24px 0 8px; }
        .footer { font-size: 12px; color: #9CA3AF; margin-top: 32px; border-top: 1px solid #F3F4F6; padding-top: 16px; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Добро пожаловать в команду поддержки!</h1>
        <p>Администратор создал для вас аккаунт оператора. Используйте данные ниже для первого входа.</p>

        <div class="credentials">
            <p><strong>Email:</strong> {{ $email }}</p>
            <p><strong>Пароль:</strong> {{ $password }}</p>
        </div>

        <p>Смените пароль сразу после первого входа.</p>

        <a href="{{ $loginUrl }}" class="btn">Войти в панель</a>

        <div class="footer">
            Это письмо отправлено автоматически — не отвечайте на него.<br>
            Если вы не ожидали этого приглашения, проигнорируйте письмо.
        </div>
    </div>
</body>
</html>
