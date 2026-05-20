<?php
$pageTitle = 'О нас - AKINO';
$bodyClass = '';
$activeNav = '';
require __DIR__ . '/includes/page-top.php';
?>
<main class="catalog-page">
    <section class="help">
        <h1>Помощь</h1>
      
        <details class="accordion">
          <summary >О нас</summary>
          <div class="content">
            <p>
                AKINO — онлайн-кинотеатр, посвящённый авторскому и короткометражному кино. Здесь собраны уникальные работы независимых режиссёров, фестивальные фильмы и студенческие проекты, которые редко можно увидеть на больших экранах. Платформа создана для тех, кто ищет новое киноязык, свежие идеи и живые эмоции. Каждый пользователь может создать свой профиль, получать персональные рекомендации и сохранять избранные фильмы..
            </p>
          </div>
        </details>
      
        <details class="accordion">
          <summary>Как смотреть фильмы в AKINO</summary>
          <div class="content">
            <p>
                Оформите подписку и получите доступ к постоянно обновляемой библиотеке авторских и короткометражных фильмов без рекламы. Новые релизы и фестивальные премьеры появляются каждую неделю, а некоторые картины можно посмотреть эксклюзивно только на AKINO.
            </p>
          </div>
        </details>
      
        <details class="accordion">
          <summary>Где смотреть AKINO</summary>
          <div class="content">
            <p>
                Смотрите любимые фильмы на любом устройстве: смартфоне, планшете, компьютере. Один аккаунт можно использовать на нескольких устройствах — выбирайте, где удобно, и наслаждайтесь настоящим авторским кино.
            </p>
          </div>
        </details>
      </section>
  </main>

  <?php require __DIR__ . '/includes/footer.php'; ?>

  <script>


// Знак вопроса перед addEventListener проверит, не null ли переменная
    document.addEventListener('DOMContentLoaded', function() {
        const openBtn = document.getElementById('openMenu');
        const closeBtn = document.getElementById('closeMenu');
        const menu = document.getElementById('mobileMenu');

        if (openBtn && menu) {
            openBtn.addEventListener('click', function() {
                console.log("Меню открывается"); // Проверка в консоли (F12)
                menu.classList.add('open');
                document.body.style.overflow = 'hidden';
            });
        }

        if (closeBtn && menu) {
            closeBtn.addEventListener('click', function() {
                console.log("Меню закрывается");
                menu.classList.remove('open');
                document.body.style.overflow = '';
            });
        }
    });
  </script>

<?php require __DIR__ . '/includes/page-bottom.php'; ?>
