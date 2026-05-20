const fs = require('fs');
const path = require('path');
const { Canvas, loadImage } = require('skia-canvas');
const PptxGenJS = require('pptxgenjs');

const root = path.resolve(__dirname, '..');
const outDir = path.join(root, 'presentation_output');
const assetsDir = path.join(outDir, 'assets');

fs.mkdirSync(assetsDir, { recursive: true });

const W = 1920;
const H = 1080;

const colors = {
  bg: '#080A0F',
  bg2: '#111823',
  red: '#BA3C34',
  redDark: '#2A0E12',
  gold: '#E6C591',
  goldSoft: '#D4A583',
  text: '#FFF5E4',
  muted: '#CDAF89',
};

async function prepareAssets() {
  const sourceCards = [
    path.join(root, 'public', 'img', 'prew', 'image_2025-11-09_22-08-25.png'),
    path.join(root, 'public', 'img', 'prew', '640x360 (9).webp'),
    path.join(root, 'public', 'img', 'prew', '640x360 (7).webp'),
  ];

  const cards = [];
  for (let i = 0; i < sourceCards.length; i += 1) {
    const target = path.join(assetsDir, `movie-card-${i + 1}.png`);
    await renderCard(sourceCards[i], target);
    cards.push(target);
  }

  return { cards };
}

function roundRect(ctx, x, y, w, h, r) {
  ctx.beginPath();
  ctx.moveTo(x + r, y);
  ctx.arcTo(x + w, y, x + w, y + h, r);
  ctx.arcTo(x + w, y + h, x, y + h, r);
  ctx.arcTo(x, y + h, x, y, r);
  ctx.arcTo(x, y, x + w, y, r);
  ctx.closePath();
}

function drawCoverImage(ctx, imgPath, x, y, w, h) {
  const img = fs.readFileSync(imgPath);
  return loadImage(img).then((image) => {
    ctx.save();
    roundRect(ctx, x, y, w, h, 8);
    ctx.clip();
    ctx.drawImage(image, x, y, w, h);
    ctx.fillStyle = 'rgba(8, 10, 15, 0.18)';
    ctx.fillRect(x, y, w, h);
    ctx.restore();
  });
}

async function renderCard(source, target) {
  const image = await loadImage(fs.readFileSync(source));
  const canvas = new Canvas(520, 292);
  const ctx = canvas.getContext('2d');
  const srcRatio = image.width / image.height;
  const outRatio = 520 / 292;
  let sx = 0;
  let sy = 0;
  let sw = image.width;
  let sh = image.height;

  if (srcRatio > outRatio) {
    sw = image.height * outRatio;
    sx = (image.width - sw) / 2;
  } else {
    sh = image.width / outRatio;
    sy = (image.height - sh) / 2;
  }

  ctx.drawImage(image, sx, sy, sw, sh, 0, 0, 520, 292);
  ctx.fillStyle = 'rgba(8, 10, 15, 0.34)';
  ctx.fillRect(0, 0, 520, 292);
  await canvas.toFile(target);
}

async function renderPreview({ cards }) {
  const canvas = new Canvas(W, H);
  const ctx = canvas.getContext('2d');
  const bg = ctx.createLinearGradient(0, 0, 0, H);
  bg.addColorStop(0, colors.red);
  bg.addColorStop(0.34, colors.redDark);
  bg.addColorStop(0.72, colors.bg);
  ctx.fillStyle = bg;
  ctx.fillRect(0, 0, W, H);

  ctx.save();
  ctx.globalAlpha = 0.16;
  ctx.fillStyle = colors.gold;
  for (let i = 0; i < 18; i += 1) {
    roundRect(ctx, 60 + i * 104, 936, 58, 72, 8);
    ctx.fill();
  }
  ctx.fillRect(0, 914, W, 8);
  ctx.fillRect(0, 1024, W, 8);
  ctx.restore();

  ctx.save();
  ctx.shadowColor = 'rgba(0,0,0,0.5)';
  ctx.shadowBlur = 42;
  ctx.shadowOffsetY = 22;
  await drawCoverImage(ctx, cards[0], 1110, 172, 520, 292);
  await drawCoverImage(ctx, cards[1], 1225, 404, 520, 292);
  await drawCoverImage(ctx, cards[2], 1068, 637, 520, 292);
  ctx.restore();

  const fade = ctx.createLinearGradient(900, 0, W, 0);
  fade.addColorStop(0, colors.bg);
  fade.addColorStop(0.52, 'rgba(8,10,15,0.35)');
  fade.addColorStop(1, 'rgba(8,10,15,0)');
  ctx.fillStyle = fade;
  ctx.fillRect(900, 0, 1020, H);

  ctx.font = '700 60px Georgia, serif';
  ctx.fillStyle = colors.gold;
  ctx.fillText('AKINO', 112, 142);
  ctx.strokeStyle = colors.gold;
  ctx.lineWidth = 2;
  ctx.strokeText('AKINO', 112, 142);

  ctx.font = '700 30px Arial, sans-serif';
  ctx.fillStyle = colors.gold;
  ctx.fillText('Дипломная работа', 112, 222);

  ctx.font = '700 60px Georgia, serif';
  ctx.fillStyle = colors.text;
  ctx.fillText('Разработка онлайн-', 112, 336);
  ctx.fillText('кинотеатра для авторских', 112, 420);
  ctx.fillText('и короткометражных фильмов', 112, 504);

  ctx.fillStyle = colors.gold;
  ctx.fillRect(112, 610, 318, 7);

  ctx.font = '34px Arial, sans-serif';
  ctx.fillStyle = colors.goldSoft;
  ctx.fillText('Онлайн-кинотеатр AKINO', 112, 676);
  ctx.font = '28px Arial, sans-serif';
  ctx.fillStyle = colors.muted;
  ctx.fillText('авторское и короткометражное кино', 112, 730);

  ctx.fillStyle = colors.gold;
  roundRect(ctx, 112, 838, 178, 52, 8);
  ctx.fill();
  ctx.font = '800 23px Arial, sans-serif';
  ctx.fillStyle = '#0A0B0F';
  ctx.textAlign = 'center';
  ctx.fillText('Каталог', 201, 873);
  ctx.textAlign = 'left';
  ctx.font = '23px Arial, sans-serif';
  ctx.fillStyle = colors.goldSoft;
  ctx.fillText('Фильмы', 322, 873);
  ctx.fillText('Профиль', 430, 873);
  ctx.fillText('Подписка', 548, 873);

  ctx.font = '22px Arial, sans-serif';
  ctx.fillStyle = colors.muted;
  ctx.fillText('Выполнил: ____________________', 112, 982);
  ctx.fillText('Руководитель: ____________________', 650, 982);

  const pngPath = path.join(outDir, 'akino_title_slide_preview.png');
  await canvas.toFile(pngPath);
  return pngPath;
}

async function buildPptx({ cards }) {
  const pptx = new PptxGenJS();
  pptx.layout = 'LAYOUT_WIDE';
  pptx.author = 'AKINO';
  pptx.subject = 'Дипломная работа';
  pptx.title = 'Разработка онлайн-кинотеатра для авторских и короткометражных фильмов';
  pptx.company = 'AKINO';
  pptx.lang = 'ru-RU';
  pptx.theme = {
    headFontFace: 'Georgia',
    bodyFontFace: 'Arial',
    lang: 'ru-RU',
  };

  const slide = pptx.addSlide();
  slide.background = { color: colors.bg.replace('#', '') };

  slide.addShape(pptx.ShapeType.rect, {
    x: 0,
    y: 0,
    w: 13.333,
    h: 2.45,
    fill: { color: colors.red.replace('#', ''), transparency: 5 },
    line: { color: colors.red.replace('#', ''), transparency: 100 },
  });
  slide.addShape(pptx.ShapeType.rect, {
    x: 0,
    y: 2.15,
    w: 13.333,
    h: 5.35,
    fill: { color: colors.bg.replace('#', ''), transparency: 0 },
    line: { color: colors.bg.replace('#', ''), transparency: 100 },
  });

  slide.addImage({ path: cards[0], x: 7.78, y: 1.15, w: 3.62, h: 2.03, transparency: 8 });
  slide.addImage({ path: cards[1], x: 8.65, y: 2.9, w: 3.62, h: 2.03, transparency: 10 });
  slide.addImage({ path: cards[2], x: 7.42, y: 4.66, w: 3.62, h: 2.03, transparency: 13 });

  slide.addShape(pptx.ShapeType.rect, {
    x: 6.25,
    y: 0,
    w: 7.1,
    h: 7.5,
    fill: { color: colors.bg.replace('#', ''), transparency: 30 },
    line: { color: colors.bg.replace('#', ''), transparency: 100 },
  });

  slide.addText('AKINO', {
    x: 0.78,
    y: 0.58,
    w: 2.15,
    h: 0.45,
    fontFace: 'Georgia',
    fontSize: 36,
    bold: true,
    color: colors.gold.replace('#', ''),
    margin: 0,
  });
  slide.addText('Дипломная работа', {
    x: 0.78,
    y: 1.42,
    w: 3.6,
    h: 0.33,
    fontFace: 'Arial',
    fontSize: 20,
    bold: true,
    color: colors.gold.replace('#', ''),
    margin: 0,
    breakLine: false,
  });

  slide.addText('Разработка онлайн-', {
    x: 0.78,
    y: 2.18,
    w: 6.5,
    h: 0.52,
    fontFace: 'Georgia',
    fontSize: 30,
    bold: true,
    color: colors.text.replace('#', ''),
    margin: 0,
    breakLine: false,
  });
  slide.addText('кинотеатра для авторских', {
    x: 0.78,
    y: 2.78,
    w: 6.5,
    h: 0.52,
    fontFace: 'Georgia',
    fontSize: 30,
    bold: true,
    color: colors.text.replace('#', ''),
    margin: 0,
    breakLine: false,
  });
  slide.addText('и короткометражных фильмов', {
    x: 0.78,
    y: 3.38,
    w: 6.5,
    h: 0.52,
    fontFace: 'Georgia',
    fontSize: 30,
    bold: true,
    color: colors.text.replace('#', ''),
    margin: 0,
    breakLine: false,
  });

  slide.addShape(pptx.ShapeType.rect, {
    x: 0.78,
    y: 4.18,
    w: 2.22,
    h: 0.05,
    fill: { color: colors.gold.replace('#', '') },
    line: { color: colors.gold.replace('#', '') },
  });
  slide.addText('Онлайн-кинотеатр AKINO', {
    x: 0.78,
    y: 4.62,
    w: 4.2,
    h: 0.38,
    fontFace: 'Arial',
    fontSize: 22,
    color: colors.goldSoft.replace('#', ''),
    margin: 0,
  });
  slide.addText('авторское и короткометражное кино', {
    x: 0.78,
    y: 5.03,
    w: 4.6,
    h: 0.32,
    fontFace: 'Arial',
    fontSize: 18,
    color: colors.muted.replace('#', ''),
    margin: 0,
  });

  slide.addShape(pptx.ShapeType.roundRect, {
    x: 0.78,
    y: 5.82,
    w: 1.24,
    h: 0.36,
    rectRadius: 0.06,
    fill: { color: colors.gold.replace('#', '') },
    line: { color: colors.gold.replace('#', '') },
  });
  slide.addText('Каталог', {
    x: 0.78,
    y: 5.9,
    w: 1.24,
    h: 0.18,
    align: 'center',
    fontFace: 'Arial',
    fontSize: 13.5,
    bold: true,
    color: '0A0B0F',
    margin: 0,
  });
  slide.addText('Фильмы', {
    x: 2.23,
    y: 5.91,
    w: 0.72,
    h: 0.18,
    fontFace: 'Arial',
    fontSize: 13.5,
    color: colors.goldSoft.replace('#', ''),
    margin: 0,
  });
  slide.addText('Профиль', {
    x: 3.0,
    y: 5.91,
    w: 0.9,
    h: 0.18,
    fontFace: 'Arial',
    fontSize: 13.5,
    color: colors.goldSoft.replace('#', ''),
    margin: 0,
  });
  slide.addText('Подписка', {
    x: 3.92,
    y: 5.91,
    w: 1.1,
    h: 0.18,
    fontFace: 'Arial',
    fontSize: 13.5,
    color: colors.goldSoft.replace('#', ''),
    margin: 0,
  });

  slide.addText('Выполнил: ____________________', {
    x: 0.78,
    y: 6.82,
    w: 3.5,
    h: 0.2,
    fontFace: 'Arial',
    fontSize: 13,
    color: colors.muted.replace('#', ''),
    margin: 0,
  });
  slide.addText('Руководитель: ____________________', {
    x: 4.52,
    y: 6.82,
    w: 3.7,
    h: 0.2,
    fontFace: 'Arial',
    fontSize: 13,
    color: colors.muted.replace('#', ''),
    margin: 0,
  });

  await pptx.writeFile({ fileName: path.join(outDir, 'akino_title_slide.pptx') });
}

async function main() {
  const assets = await prepareAssets();
  const pngPath = await renderPreview(assets);
  await buildPptx(assets);
  console.log(JSON.stringify({
    pptx: path.join(outDir, 'akino_title_slide.pptx'),
    png: pngPath,
  }, null, 2));
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
