<div class="modal-overlay" id="loginModal">
  <section class="Form-Login">
    <div class="clos-but">
      <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
        <rect width="4.99131" height="28.9496" rx="2.49565" transform="matrix(-0.707109 -0.707105 0.707109 -0.707105 3.5293 23.9993)" fill="#E6C591"/>
        <rect width="4.99131" height="28.9496" rx="2.49565" transform="matrix(0.707109 -0.707105 0.707109 0.707105 0 3.52905)" fill="#E6C591"/>
      </svg>
    </div>

    <div class="Form-login">
      <div class="Form-Title">
        <p>Войдите или создайте аккаунт в <img src="logo.svg" alt="AKINO" class="auth-brand-logo"></p>
      </div>

      <div class="auth-feedback" id="authFeedback" hidden></div>
      <div class="auth-demo-code" id="authDemoCode" hidden></div>

      <div id="step-1">
        <form class="login-form" id="phoneForm">
          <div class="input-wrapper">
            <div class="phone-prefix">+7</div>
            <input type="tel" id="userPhoneInput" placeholder="(999) 000-00-00" pattern="[0-9]*" class="telefon_form_input" required>
          </div>
          <input type="submit" value="Продолжить" class="login-btn">
        </form>
        <div class="polz-sogl">
          Нажимая “Продолжить”, я принимаю условия <a href="Info.php?page=agreement">Пользовательского соглашения</a> ООО “AKINO”
        </div>
      </div>

      <div id="step-2" hidden>
        <div class="code-message">
          Введите код из сообщения на <span id="displayPhone">+7 (999) 999-99-99</span>
        </div>

        <form class="login-form" id="codeForm">
          <div class="code-inputs">
            <input type="tel" maxlength="1" class="digit-input" required>
            <input type="tel" maxlength="1" class="digit-input" required>
            <input type="tel" maxlength="1" class="digit-input" required>
            <input type="tel" maxlength="1" class="digit-input" required>
          </div>

          <input type="submit" value="Продолжить" class="login-btn">
        </form>

        <div class="polz-sogl auth-resend-note">
          <a href="Home.php?auth=required" class="resend-link">Нажмите, чтобы получить новый код</a>, если не пришло сообщение
        </div>
      </div>
    </div>
  </section>
</div>
