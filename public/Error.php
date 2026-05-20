<?php
$pageTitle = '404 - AKINO';
$bodyClass = '';
$activeNav = '';
require __DIR__ . '/includes/page-top.php';
?>
<main>
    <div class="Error-404">
        <div class="text-404-error">
            404
        </div>
        <div class="text-notFound">
            Not Found
        </div>
    </div>
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
