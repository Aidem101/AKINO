# AKINO

AKINO - учебный прототип онлайн-кинотеатра. В проекте есть главная страница с подборками фильмов, каталог, карточки фильмов, просмотр, авторизация по номеру телефона, личный кабинет и админ-панель.

## На чем сделан

- PHP
- MySQL
- HTML, CSS, JavaScript
- Vercel для размещения сайта
- Railway MySQL для удаленной базы данных

## Размещение на Railway

В репозитории есть `Dockerfile`, поэтому Railway может развернуть PHP-сайт напрямую из GitHub.

1. В Railway откройте проект с MySQL и нажмите **New** -> **GitHub Repo**.
2. Выберите репозиторий `Aidem101/AKINO` и ветку `main`.
3. В переменных нового сервиса добавьте ссылку на URL базы:

```text
AKINO_DATABASE_URL=${{MySQL.MYSQL_URL}}
AKINO_APP_ENV=production
AKINO_TRUST_PROXY=1
AKINO_RUNTIME_BOOTSTRAP=1
AKINO_DEMO_AUTH=0
AKINO_APP_ORIGIN=https://ваш-домен.up.railway.app
```

Задайте также собственные значения `AKINO_AUTH_SECRET` и `AKINO_ADMIN_PASSWORD`.
После первого запуска в разделе **Settings** -> **Networking** создайте публичный домен и подставьте его в `AKINO_APP_ORIGIN`.

## База данных

Структура базы находится в файле:

```text
database/akino.sql
```

Для локального запуска настройки можно взять из:

```text
.env.example
```

Основные переменные окружения:

```text
AKINO_DB_HOST
AKINO_DB_PORT
AKINO_DB_DATABASE
AKINO_DB_USERNAME
AKINO_DB_PASSWORD
AKINO_DB_SSL_CA
AKINO_DB_SSL_VERIFY_SERVER_CERT
AKINO_ADMIN_LOGIN
AKINO_ADMIN_PASSWORD
AKINO_APP_ENV
AKINO_APP_ORIGIN
AKINO_AUTH_SECRET
AKINO_BACKUP_SECRET
AKINO_TRUST_PROXY
AKINO_RUNTIME_BOOTSTRAP
AKINO_DEMO_AUTH
AKINO_CSP_IMAGE_ORIGINS
AKINO_CSP_MEDIA_ORIGINS
```

Для рабочего окружения установите `AKINO_APP_ENV=production`, задайте отдельный
случайный `AKINO_AUTH_SECRET` длиной не менее 32 символов и отключите
`AKINO_DEMO_AUTH`. Для резервных копий задайте отдельный случайный
`AKINO_BACKUP_SECRET` длиной не менее 32 символов. При удалённом подключении к
MySQL укажите CA-сертификат через `AKINO_DB_SSL_CA`.

Внешние изображения и видео разрешаются политикой CSP только для HTTPS-источников,
перечисленных через `AKINO_CSP_IMAGE_ORIGINS` и `AKINO_CSP_MEDIA_ORIGINS`.

## Центр безопасности

В админ-панели доступен раздел «Безопасность»:

- журнал входов, блокировок и отклонённых запросов;
- статистика событий за семь дней;
- роли администратора, редактора, модератора и аудитора;
- зашифрованные резервные копии базы данных;
- контроль целостности PHP, JavaScript, CSS и конфигурационных файлов по SHA-256.

Файлы резервных копий сохраняются в `storage/backups` и не попадают в публичную
директорию или Git.

## Локальный запуск

Проект рассчитан на запуск через OSPanel/OpenServer с PHP и MySQL. Входная папка сайта:

```text
public
```

Для первичной настройки базы можно использовать:

```powershell
C:\OSPanel\modules\PHP-8.0\php.exe tools\setup_database.php
```

## OWASP ZAP

План пассивного и активного сканирования находится в:

```text
tests/zap/localhost-security-plan.yaml
```

Отчёт проверки и сравнение результатов до и после исправлений:

```text
docs/owasp-zap-report.md
tests/artifacts/zap-20260614/akino-zap-before.html
tests/artifacts/zap-20260614/akino-zap-full.html
```
