<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$srcDocx = $root . DIRECTORY_SEPARATOR . 'Курсовая_AKINO.docx';
$outDocx = $root . DIRECTORY_SEPARATOR . 'Курсовая_AKINO_с_рисунками.docx';
$outDir = $root . DIRECTORY_SEPARATOR . 'coursework_figures';

if (!extension_loaded('gd') || !extension_loaded('zip')) {
    fwrite(STDERR, "PHP extensions gd and zip are required.\n");
    exit(1);
}
if (!is_file($srcDocx)) {
    fwrite(STDERR, "Missing source document: $srcDocx\n");
    exit(1);
}
if (!is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}

$font = firstFile(['C:\\Windows\\Fonts\\arial.ttf', 'C:\\Windows\\Fonts\\segoeui.ttf']);
$bold = firstFile(['C:\\Windows\\Fonts\\arialbd.ttf', 'C:\\Windows\\Fonts\\segoeuib.ttf', $font]);

$figs = [
  1 => ['Интерфейсы онлайн-кинотеатров', 'Промо-блоки, каталоги, карточки и профиль пользователя.', 'board'],
  2 => ['Подборка референсов AKINO', 'Темная визуальная основа, постеры, кинолента и теплые акценты.', 'mood'],
  3 => ['Основные элементы UI-kit', 'Цвета, кнопки, поля, карточки и состояния интерфейса.', 'kit'],
  4 => ['Прототип главной страницы', 'Навигация, промо-экран и подборки фильмов.', 'home_proto'],
  5 => ['Прототип страницы каталога', 'Фильтры, сетка карточек и быстрый выбор фильма.', 'catalog_proto'],
  6 => ['Прототип страницы фильма', 'Постер, описание, метаданные и действие просмотра.', 'film_proto'],
  7 => ['Прототип страницы просмотра', 'Видеоплеер, прогресс и рекомендации.', 'watch_proto'],
  8 => ['Прототип личного кабинета', 'Профиль, избранное, история и подписка.', 'cabinet'],
  9 => ['Прототип форм авторизации', 'Ввод телефона, код подтверждения и сообщения ошибок.', 'auth'],
 10 => ['Адаптивные состояния интерфейса', 'Desktop, tablet и mobile-компоновки.', 'responsive'],
 11 => ['Прототип административных элементов', 'Управление контентом, пользователями и журналом.', 'admin_mock'],
 12 => ['Общая структура прототипа', 'Связи основных экранов онлайн-кинотеатра.', 'flow'],
 13 => ['Реализация компонента шапки', 'Фактическая шапка сайта AKINO.', 'shot:site_home.png:0,0,1440,160'],
 14 => ['Реализация главной страницы', 'Скриншот главной страницы проекта.', 'shot:site_home.png'],
 15 => ['Реализация карточки фильма', 'Фрагмент каталога с карточками фильмов.', 'shot:site_films_catalog.png:120,290,760,560'],
 16 => ['Реализация страницы каталога', 'Скриншот каталога фильмов.', 'shot:site_films_catalog.png'],
 17 => ['Реализация страницы фильма', 'Скриншот страницы фильма.', 'shot:site_film_page.png'],
 18 => ['Реализация страницы просмотра', 'Экран просмотра или состояние запроса авторизации.', 'shot:site_watch.png'],
 19 => ['Реализация личного кабинета', 'Блоки профиля, избранного и истории просмотра.', 'cabinet'],
 20 => ['Реализация форм авторизации', 'Форма входа пользователя или администратора.', 'shot:site_admin_login.png'],
 21 => ['Сообщения валидации', 'Примеры ошибок при заполнении формы.', 'validation'],
 22 => ['Фильтрация каталога', 'Выбор фильтра и обновление списка фильмов.', 'filter'],
 23 => ['Добавление в избранное', 'Состояния кнопки сохранения фильма.', 'fav'],
 24 => ['Сохранение прогресса просмотра', 'Передача текущей позиции видео в API.', 'progress'],
 25 => ['Интерфейс подписки', 'План, цена, срок действия и статус доступа.', 'subscription'],
 26 => ['Административный интерфейс', 'Вход и основные зоны управления.', 'shot:site_admin_login.png'],
];

foreach ($figs as $n => $meta) {
    makeFigure($outDir, $font, $bold, $n, $meta[0], $meta[1], $meta[2]);
}
copy($srcDocx, $outDocx);
insertIntoDocx($outDocx, $outDir);
echo "OK figures: $outDir\nOK document: $outDocx\n";

function firstFile(array $paths): string {
    foreach ($paths as $p) if (is_string($p) && is_file($p)) return $p;
    fwrite(STDERR, "No font found.\n"); exit(1);
}
function col($im, int $r, int $g, int $b, int $a = 0): int { return imagecolorallocatealpha($im, $r, $g, $b, $a); }
function txt($im, string $font, int $size, int $x, int $y, int $color, string $text): void {
    imagettftext($im, $size, 0, $x, $y, $color, $font, $text);
}
function rr($im, int $x1, int $y1, int $x2, int $y2, int $r, int $fill, ?int $stroke = null): void {
    imagefilledrectangle($im, $x1 + $r, $y1, $x2 - $r, $y2, $fill);
    imagefilledrectangle($im, $x1, $y1 + $r, $x2, $y2 - $r, $fill);
    foreach ([[$x1+$r,$y1+$r],[$x2-$r,$y1+$r],[$x1+$r,$y2-$r],[$x2-$r,$y2-$r]] as $p)
        imagefilledellipse($im, $p[0], $p[1], $r*2, $r*2, $fill);
    if ($stroke !== null) imagerectangle($im, $x1, $y1, $x2, $y2, $stroke);
}
function wrap($im, string $font, int $size, int $x, int $y, int $max, int $lh, int $color, string $s): void {
    $line = '';
    foreach (preg_split('/\s+/u', $s) ?: [] as $w) {
        $try = trim("$line $w");
        $box = imagettfbbox($size, 0, $font, $try);
        if (abs($box[2] - $box[0]) > $max && $line !== '') {
            txt($im, $font, $size, $x, $y, $color, $line); $y += $lh; $line = $w;
        } else $line = $try;
    }
    if ($line !== '') txt($im, $font, $size, $x, $y, $color, $line);
}

function makeFigure(string $dir, string $font, string $bold, int $n, string $title, string $sub, string $kind): void {
    $im = imagecreatetruecolor(1400, 850);
    $bg = col($im, 8, 10, 15); $panel = col($im, 19, 23, 32); $soft = col($im, 32, 37, 49);
    $accent = col($im, 230, 197, 145); $red = col($im, 186, 60, 52);
    $text = col($im, 246, 239, 224); $muted = col($im, 202, 174, 142); $line = col($im, 74, 82, 98);
    imagefilledrectangle($im, 0, 0, 1400, 850, $bg);
    imagefilledrectangle($im, 0, 0, 1400, 170, col($im, 38, 19, 22));
    rr($im, 70, 45, 180, 120, 10, $red);
    txt($im, $bold, 27, 102, 94, $text, sprintf('%02d', $n));
    txt($im, $bold, 31, 215, 82, $text, $title);
    txt($im, $font, 18, 216, 118, $muted, $sub);
    imagefilledrectangle($im, 76, 142, 1325, 146, $accent);
    if (str_starts_with($kind, 'shot:')) drawShot($im, $dir, $font, $bold, $kind, $panel, $accent, $text, $muted, $line);
    elseif ($kind === 'board') drawBoard($im, $font, $bold, $panel, $soft, $accent, $red, $text, $muted, $line);
    elseif ($kind === 'mood') drawMood($im, $font, $bold, $panel, $soft, $accent, $red, $text, $muted);
    elseif ($kind === 'kit') drawKit($im, $font, $bold, $panel, $soft, $accent, $red, $text, $muted, $line);
    elseif ($kind === 'auth' || $kind === 'validation') drawAuth($im, $font, $bold, $panel, $accent, $red, $text, $muted, $kind === 'validation');
    elseif ($kind === 'flow' || $kind === 'progress') drawFlow($im, $font, $bold, $panel, $soft, $accent, $red, $text, $muted, $line, $kind);
    elseif ($kind === 'responsive') drawResponsive($im, $font, $bold, $panel, $soft, $accent, $red, $text, $muted);
    elseif ($kind === 'filter') drawFilter($im, $font, $bold, $panel, $soft, $accent, $red, $text, $muted);
    elseif ($kind === 'fav') drawFav($im, $font, $bold, $panel, $soft, $accent, $red, $text, $muted);
    elseif ($kind === 'subscription') drawSub($im, $font, $bold, $panel, $soft, $accent, $red, $text, $muted);
    elseif ($kind === 'cabinet') drawCabinet($im, $font, $bold, $panel, $soft, $accent, $red, $text, $muted);
    elseif ($kind === 'admin_mock') drawAdmin($im, $font, $bold, $panel, $soft, $accent, $red, $text, $muted);
    else drawScreen($im, $font, $bold, $panel, $soft, $accent, $red, $text, $muted, $kind);
    imagepng($im, $dir . DIRECTORY_SEPARATOR . sprintf('figure_%02d.png', $n));
    imagedestroy($im);
}

function drawShot($im, string $dir, string $font, string $bold, string $kind, int $panel, int $accent, int $text, int $muted, int $line): void {
    $parts = explode(':', $kind); $file = $dir . DIRECTORY_SEPARATOR . $parts[1];
    rr($im, 80, 195, 1320, 725, 14, $panel, $line);
    if (!is_file($file)) { txt($im, $bold, 28, 150, 430, $text, 'Скриншот недоступен'); return; }
    $src = imagecreatefrompng($file); $sw = imagesx($src); $sh = imagesy($src);
    $crop = isset($parts[2]) ? array_map('intval', explode(',', $parts[2])) : [0, 0, $sw, $sh];
    [$sx, $sy, $cw, $ch] = $crop; $cw = min($cw, $sw - $sx); $ch = min($ch, $sh - $sy);
    $scale = min(1160 / $cw, 455 / $ch); $tw = (int)($cw * $scale); $th = (int)($ch * $scale);
    imagecopyresampled($im, $src, 120 + (int)((1160 - $tw)/2), 230, $sx, $sy, $tw, $th, $cw, $ch);
    imagedestroy($src);
    rr($im, 120, 695, 1280, 740, 8, $accent);
    txt($im, $bold, 18, 155, 724, imagecolorallocate($im, 0, 0, 0), 'Фактический интерфейс проекта AKINO');
}

function drawBoard($im, string $font, string $bold, int $panel, int $soft, int $accent, int $red, int $text, int $muted, int $line): void {
    foreach (['Главная', 'Каталог', 'Карточка', 'Профиль'] as $i => $name) {
        $x = 95 + $i * 315; rr($im, $x, 220, $x + 260, 660, 12, $panel, $line);
        imagefilledrectangle($im, $x + 20, 250, $x + 240, 380, $i % 2 ? $red : $accent);
        for ($j=0;$j<3;$j++) rr($im, $x + 25 + $j*72, 425, $x + 82 + $j*72, 535, 7, $soft);
        txt($im, $bold, 23, $x + 25, 595, $text, $name);
        txt($im, $font, 15, $x + 25, 625, $muted, 'типовой экран сервиса');
    }
}
function drawMood($im, string $font, string $bold, int $panel, int $soft, int $accent, int $red, int $text, int $muted): void {
    foreach (['Темная основа','Кинолента','Постеры','Акцент','Короткий метр','Авторское кино'] as $i=>$name) {
        $x=95+($i%3)*405; $y=215+intdiv($i,3)*235; rr($im,$x,$y,$x+340,$y+178,14,$panel);
        imagefilledrectangle($im,$x+26,$y+25,$x+314,$y+100,[$soft,$accent,$red][$i%3]);
        txt($im,$bold,22,$x+26,$y+140,$text,$name); txt($im,$font,15,$x+26,$y+164,$muted,'референс визуального языка');
    }
}
function drawKit($im, string $font, string $bold, int $panel, int $soft, int $accent, int $red, int $text, int $muted, int $line): void {
    rr($im,90,210,520,680,12,$panel,$line); txt($im,$bold,24,125,260,$text,'Палитра');
    foreach ([['#080A0F',8,10,15],['#BA3C34',186,60,52],['#E6C591',230,197,145],['#202531',32,37,49]] as $i=>$c) {
        imagefilledrectangle($im,125,295+$i*75,220,350+$i*75,imagecolorallocate($im,$c[1],$c[2],$c[3]));
        txt($im,$font,18,245,332+$i*75,$muted,$c[0]);
    }
    rr($im,570,210,1310,680,12,$panel,$line); txt($im,$bold,24,610,260,$text,'Компоненты');
    rr($im,610,300,790,355,8,$accent); txt($im,$bold,18,656,335,imagecolorallocate($im,0,0,0),'Смотреть');
    rr($im,835,300,1150,355,8,$accent); txt($im,$font,17,865,335,imagecolorallocate($im,0,0,0),'Поиск фильма');
    for($i=0;$i<4;$i++){ rr($im,610+$i*165,410,735+$i*165,610,8,$soft); imagefilledrectangle($im,626+$i*165,430,718+$i*165,555,$i%2?$red:$accent); txt($im,$font,14,625+$i*165,585,$text,'Фильм'); }
}
function drawScreen($im,string $font,string $bold,int $panel,int $soft,int $accent,int $red,int $text,int $muted,string $kind): void {
    rr($im,100,200,1300,705,14,$panel); txt($im,$bold,22,135,245,$accent,'AKINO'); txt($im,$font,15,250,245,$muted,'Главная  Каталог  Фильмы  Профиль');
    imagefilledrectangle($im,135,285,1265,425,$red); txt($im,$bold,31,170,360,$text, str_replace('_',' ',ucfirst($kind)));
    for($r=0;$r<3;$r++){ txt($im,$bold,19,140,485+$r*70,$text,['Подборка','Новинки','Рекомендации'][$r]); for($j=0;$j<5;$j++) rr($im,330+$j*175,455+$r*70,455+$j*175,518+$r*70,7,$soft); }
}
function drawAuth($im,string $font,string $bold,int $panel,int $accent,int $red,int $text,int $muted,bool $errors): void {
    foreach ([230=>'Вход',760=>'Регистрация'] as $x=>$name){ rr($im,$x,235,$x+410,650,14,$panel); txt($im,$bold,29,$x+45,305,$text,$name); txt($im,$font,17,$x+45,350,$muted,'Номер телефона'); rr($im,$x+45,380,$x+365,435,8,$accent); txt($im,$font,17,$x+65,415,imagecolorallocate($im,0,0,0),'+7 (___) ___-__-__'); rr($im,$x+45,475,$x+365,530,8,$red); txt($im,$bold,18,$x+145,510,$text,'Получить код'); if($errors) txt($im,$bold,16,$x+45,585,$red,'Ошибка заполнения поля'); }
}
function drawResponsive($im,string $font,string $bold,int $panel,int $soft,int $accent,int $red,int $text,int $muted): void {
    foreach ([['Desktop',105,240,520,350],['Tablet',680,250,300,420],['Mobile',1070,260,190,410]] as $d){[$name,$x,$y,$w,$h]=$d; txt($im,$bold,20,$x,$y-22,$text,$name); rr($im,$x,$y,$x+$w,$y+$h,16,$panel); imagefilledrectangle($im,$x+20,$y+25,$x+$w-20,$y+92,$red); for($i=0;$i<6;$i++){ $cols=max(1,(int)floor($w/145)); rr($im,$x+25+($i%$cols)*125,$y+125+intdiv($i,$cols)*85,min($x+$w-20,$x+120+($i%$cols)*125),$y+185+intdiv($i,$cols)*85,7,$soft); } }
}
function drawFlow($im,string $font,string $bold,int $panel,int $soft,int $accent,int $red,int $text,int $muted,int $line,string $kind): void {
    $nodes=$kind==='progress'?['video.currentTime','API /progress','watch_progress']:['Главная','Каталог','Фильм','Просмотр','Профиль','Админ','База данных'];
    foreach($nodes as $i=>$name){ $x=145+($i%4)*285; $y=270+intdiv($i,4)*190; if($i>0) imageline($im,$x-95,$y+35,$x,$y+35,$accent); rr($im,$x,$y,$x+210,$y+72,10,$i%2?$panel:$red,$line); txt($im,$bold,17,$x+25,$y+43,$text,$name); }
}
function drawFilter($im,string $font,string $bold,int $panel,int $soft,int $accent,int $red,int $text,int $muted): void {
    drawScreen($im,$font,$bold,$panel,$soft,$accent,$red,$text,$muted,'catalog'); rr($im,300,455,455,518,7,$red); txt($im,$bold,18,940,330,$accent,'Фильтр активен: Драма');
}
function drawFav($im,string $font,string $bold,int $panel,int $soft,int $accent,int $red,int $text,int $muted): void {
    for($i=0;$i<3;$i++){ $x=260+$i*310; rr($im,$x,250,$x+245,630,12,$panel); imagefilledrectangle($im,$x+28,285,$x+217,505,$i==1?$red:$accent); txt($im,$bold,19,$x+28,550,$text,'Фильм AKINO'); rr($im,$x+28,575,$x+217,620,8,$i==1?$red:$soft); txt($im,$font,15,$x+58,604,$i==1?$text:$muted,$i==1?'Сохранено':'В избранное'); }
}
function drawSub($im,string $font,string $bold,int $panel,int $soft,int $accent,int $red,int $text,int $muted): void {
    rr($im,240,235,1160,650,14,$panel); txt($im,$bold,34,300,310,$text,'Подписка AKINO'); txt($im,$font,20,300,355,$muted,'Доступ к авторскому кино и короткому метру'); txt($im,$bold,43,300,445,$accent,'299 ₽ / 30 дней'); rr($im,300,505,560,570,8,$red); txt($im,$bold,21,365,546,$text,'Оформить'); rr($im,700,300,1050,560,12,$soft); txt($im,$bold,22,745,370,$accent,'Статус: активна'); txt($im,$font,18,745,425,$muted,'Действует до 15.05.2026');
}
function drawCabinet($im,string $font,string $bold,int $panel,int $soft,int $accent,int $red,int $text,int $muted): void {
    rr($im,100,200,1300,705,14,$panel); rr($im,135,245,390,650,12,$soft); imagefilledellipse($im,260,335,120,120,$accent); txt($im,$bold,23,180,435,$text,'Пользователь'); txt($im,$font,16,180,470,$muted,'Подписка активна'); foreach(['Избранное','История','Продолжить просмотр'] as $i=>$s){ txt($im,$bold,21,440,270+$i*130,$text,$s); for($j=0;$j<4;$j++) rr($im,440+$j*190,300+$i*130,600+$j*190,370+$i*130,7,$soft); }
}
function drawAdmin($im,string $font,string $bold,int $panel,int $soft,int $accent,int $red,int $text,int $muted): void {
    rr($im,100,200,1300,705,14,$panel); imagefilledrectangle($im,125,220,345,685,$soft); txt($im,$bold,24,155,270,$accent,'Admin'); foreach(['Фильмы','Пользователи','Подписки','Журнал'] as $i=>$s) txt($im,$font,17,155,340+$i*55,$muted,$s); for($i=0;$i<4;$i++){ rr($im,390,260+$i*95,1215,325+$i*95,8,$soft); txt($im,$bold,17,420,300+$i*95,$text,['Добавить фильм','Редактировать','Проверить подписки','Действия'][$i]); rr($im,1035,275+$i*95,1170,312+$i*95,8,$accent); }
}

function insertIntoDocx(string $docx, string $figDir): void {
    $zip = new ZipArchive();
    if ($zip->open($docx) !== true) fail("Cannot open DOCX: $docx");
    $docName = zipName($zip, 'word/document.xml');
    $relsName = zipName($zip, 'word/_rels/document.xml.rels');
    $typesName = zipName($zip, '[Content_Types].xml');
    $docXml = $docName ? $zip->getFromName($docName) : false;
    $relsXml = $relsName ? $zip->getFromName($relsName) : false;
    $typesXml = $typesName ? $zip->getFromName($typesName) : false;
    if ($docXml === false || $relsXml === false || $typesXml === false) fail('Incomplete DOCX package.');

    $rels = new DOMDocument('1.0', 'UTF-8');
    $rels->preserveWhiteSpace = false;
    $rels->loadXML($relsXml);
    $relRoot = $rels->documentElement;
    $ridNum = 0;
    foreach ($rels->getElementsByTagName('Relationship') as $rel) {
        if (preg_match('/^rId(\d+)$/', $rel->getAttribute('Id'), $m)) $ridNum = max($ridNum, (int)$m[1]);
    }

    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->preserveWhiteSpace = true;
    $doc->loadXML($docXml);
    $xp = new DOMXPath($doc);
    $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    $body = $xp->query('/w:document/w:body')->item(0);
    if (!$body instanceof DOMElement) fail('Cannot find document body.');

    $inserted = 0;
    $paragraphs = iterator_to_array($xp->query('/w:document/w:body/w:p'));
    foreach ($paragraphs as $p) {
        $plain = trim(preg_replace('/\s+/u', ' ', $p->textContent ?? '') ?? '');
        if (!preg_match('/^Рисунок\s+([1-9]|1[0-9]|2[0-6])\s/u', $plain, $m)) continue;
        $n = (int)$m[1];
        $png = $figDir . DIRECTORY_SEPARATOR . sprintf('figure_%02d.png', $n);
        if (!is_file($png)) fail("Missing figure: $png");
        $media = sprintf('word/media/coursework_figure_%02d.png', $n);
        if ($zip->locateName($media) !== false) $zip->deleteName($media);
        $zip->addFile($png, $media);
        $rid = 'rId' . (++$ridNum);
        $rel = $rels->createElementNS('http://schemas.openxmlformats.org/package/2006/relationships', 'Relationship');
        $rel->setAttribute('Id', $rid);
        $rel->setAttribute('Type', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image');
        $rel->setAttribute('Target', 'media/' . basename($media));
        $relRoot->appendChild($rel);
        [$pw, $ph] = getimagesize($png);
        $cx = 5486400;
        $cy = (int)round($cx * ($ph / $pw));
        $body->insertBefore(imageParagraph($doc, $rid, $n, $cx, $cy), $p);
        $inserted++;
    }
    if ($inserted !== 26) fail("Expected 26 inserted images, got $inserted.");

    $typesXml = ensurePngType($typesXml);
    $zip->addFromString($docName, $doc->saveXML());
    $zip->addFromString($relsName, $rels->saveXML());
    $zip->addFromString($typesName, $typesXml);
    $zip->close();
}

function imageParagraph(DOMDocument $doc, string $rid, int $n, int $cx, int $cy): DOMElement {
    $xml = <<<XML
<w:p xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0"><wp:extent cx="$cx" cy="$cy"/><wp:docPr id="$n" name="Рисунок $n"/><wp:cNvGraphicFramePr><a:graphicFrameLocks noChangeAspect="1"/></wp:cNvGraphicFramePr><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:pic><pic:nvPicPr><pic:cNvPr id="0" name="figure_$n.png"/><pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="$rid"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="$cx" cy="$cy"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r></w:p>
XML;
    $tmp = new DOMDocument('1.0', 'UTF-8');
    $tmp->loadXML($xml);
    return $doc->importNode($tmp->documentElement, true);
}

function ensurePngType(string $xml): string {
    if (str_contains($xml, 'Extension="png"') || str_contains($xml, "Extension='png'")) return $xml;
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = true;
    $dom->loadXML($xml);
    $node = $dom->createElementNS('http://schemas.openxmlformats.org/package/2006/content-types', 'Default');
    $node->setAttribute('Extension', 'png');
    $node->setAttribute('ContentType', 'image/png');
    $dom->documentElement->appendChild($node);
    return $dom->saveXML();
}

function fail(string $message): void {
    fwrite(STDERR, $message . "\n");
    exit(1);
}

function zipName(ZipArchive $zip, string $name): ?string {
    if ($zip->locateName($name) !== false) return $name;
    $alt = str_replace('/', '\\', $name);
    if ($zip->locateName($alt) !== false) return $alt;
    return null;
}
