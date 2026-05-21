# AKINO

AKINO - учебный прототип онлайн-кинотеатра. В проекте есть главная страница с подборками фильмов, каталог, карточки фильмов, просмотр, авторизация по номеру телефона, личный кабинет и админ-панель.

## На чем сделан

- PHP
- MySQL
- HTML, CSS, JavaScript
- Vercel для размещения сайта
- Railway MySQL для удаленной базы данных

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
AKINO_ADMIN_LOGIN
AKINO_ADMIN_PASSWORD
AKINO_RUNTIME_BOOTSTRAP
AKINO_DEMO_AUTH
```

## Локальный запуск

Проект рассчитан на запуск через OSPanel/OpenServer с PHP и MySQL. Входная папка сайта:

```text
public
```

Для первичной настройки базы можно использовать:

```powershell
C:\OSPanel\modules\PHP-8.0\php.exe tools\setup_database.php
```
