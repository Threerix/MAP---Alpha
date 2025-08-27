// js/auth.js - Versão Corrigida
document.addEventListener("DOMContentLoaded", () => {
  console.log("auth.js loaded and DOM is ready.");

  const AUTH_URL = "api/auth_api.php";
  let isSubmitting = false;

  const loginForm = document.getElementById("loginForm");
  const registerForm = document.getElementById("registerForm");
  const tabButtons = document.querySelectorAll(".tab-btn");

  // Verificar se já está logado ao carregar a página
  checkIfAlreadyLoggedIn();

  // Tab switching
  tabButtons.forEach((btn) => {
    btn.addEventListener("click", () => {
      tabButtons.forEach((b) => b.classList.remove("active"));
      btn.classList.add("active");

      const targetTab = btn.dataset.tab;
      document.querySelectorAll(".auth-form").forEach((form) => {
        if (form.id === "loginForm" && targetTab === "login") {
          form.classList.add("active");
          form.style.display = "block";
        } else if (form.id === "registerForm" && targetTab === "register") {
          form.classList.add("active");
          form.style.display = "block";
        } else {
          form.classList.remove("active");
          form.style.display = "none";
        }
      });
    });
  });

  // Função para verificar se já está logado
  async function checkIfAlreadyLoggedIn() {
    const token = localStorage.getItem("session_token");
    if (!token) {
      console.log("Nenhum token encontrado, permanecendo na tela de login.");
      return;
    }

    console.log("Token encontrado:", token.substring(0, 8) + "...");

    // Se há um token, assume que é válido e vai para index.html
    // O index.html que vai verificar se realmente funciona
    if (token && token.length > 20) {
      // Tokens do seu sistema têm 48 chars (24 bytes em hex)
      console.log("Token encontrado, redirecionando para index.html...");
      redirectToIndex();
    } else {
      console.log("Token parece inválido, removendo...");
      localStorage.removeItem("session_token");
    }
  }

  // Função centralizada de redirecionamento
  function redirectToIndex() {
    console.log("Iniciando redirecionamento para index.html");

    // Tenta diferentes métodos de redirecionamento
    try {
      // Método 1: window.location.href
      window.location.href = "index.html";
    } catch (error) {
      console.error("Erro no primeiro método, tentando alternativo:", error);

      // Método 2: window.location.replace
      try {
        window.location.replace("index.html");
      } catch (error2) {
        console.error("Erro no segundo método, tentando terceiro:", error2);

        // Método 3: window.location.assign
        try {
          window.location.assign("index.html");
        } catch (error3) {
          console.error("Todos os métodos falharam:", error3);
          alert(
            "Login realizado com sucesso! Por favor, navegue manualmente para a página inicial."
          );
        }
      }
    }
  }

  // Login Handler
  if (loginForm) {
    loginForm.addEventListener("submit", async (e) => {
      e.preventDefault();
      if (isSubmitting) return;

      console.log("1. Login form submitted.");

      const username = document.getElementById("username").value.trim();
      const password = document.getElementById("password").value;

      // Limpar mensagens de erro anteriores
      clearErrorMessages();

      if (!username || !password) {
        showError("Por favor, preencha o usuário e a senha.");
        return;
      }

      isSubmitting = true;
      const submitBtn = document.getElementById("loginSubmit");
      submitBtn.disabled = true;
      submitBtn.innerHTML = "<span>Entrando...</span>";

      try {
        console.log("2. Sending login request to backend...", { username });

        const response = await fetch(AUTH_URL, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            action: "login",
            username,
            password,
          }),
        });

        console.log(
          "3. Received response from backend. Status:",
          response.status
        );

        const text = await response.text();
        console.log("4. Raw response:", text);

        let data;
        try {
          data = JSON.parse(text);
        } catch (parseError) {
          console.error("Erro ao fazer parse do JSON:", parseError);
          throw new Error("Resposta inválida do servidor");
        }

        console.log("5. Parsed JSON data:", data);

        if (
          response.ok &&
          data.status === "success" &&
          data.data?.session_token
        ) {
          console.log(
            "6. Login SUCCESSFUL. Token received:",
            data.data.session_token
          );

          // Salva o token
          localStorage.setItem("session_token", data.data.session_token);

          // Mostra mensagem de sucesso
          showSuccess("Login realizado com sucesso!");

          // Aguarda um momento antes de redirecionar
          setTimeout(() => {
            console.log("7. Redirecionando após delay...");
            redirectToIndex();
          }, 500);
        } else {
          console.error("Login FAILED. Backend message:", data.message);
          showError(data.message || "Usuário ou senha incorretos.");
        }
      } catch (error) {
        console.error("CRITICAL ERROR during login fetch:", error);
        showError("Ocorreu um erro de conexão. Tente novamente.");
      } finally {
        isSubmitting = false;
        submitBtn.disabled = false;
        submitBtn.innerHTML = "<span>Entrar</span>";
      }
    });
  }

  // Register Handler (básico por enquanto)
  if (registerForm) {
    registerForm.addEventListener("submit", async (e) => {
      e.preventDefault();
      showError("Função de registro em desenvolvimento.");
    });
  }

  // Funções auxiliares para mostrar erros/sucessos
  function clearErrorMessages() {
    document.querySelectorAll(".error-message").forEach((el) => {
      el.textContent = "";
      el.style.display = "none";
    });
  }

  function showError(message) {
    console.error("Erro:", message);

    // Tenta mostrar no campo específico ou usa alert como fallback
    const errorElement =
      document.getElementById("loginUsernameError") ||
      document.getElementById("loginPasswordError");

    if (errorElement) {
      errorElement.textContent = message;
      errorElement.style.display = "block";
    } else {
      alert(message);
    }
  }

  function showSuccess(message) {
    console.log("Sucesso:", message);
    // Você pode implementar uma notificação visual aqui se desejar
  }
});
