// Endpoints
const AUTH_URL = "api/auth_api.php";
const FAV_URL = "api/favorites_api.php";
const RECS_URL = "api/recommendations_api.php";
const DAYMIX_URL = "api/daymix_api.php";
const FEEDBACK_URL = "api/feedback_api.php";

// State
let currentType = "";
let favorites = [];
let recommendationPool = { music: [], album: [], artist: [] };
const VISIBLE_REC_LIMIT = 6;

// Helpers
function safeJSONParse(text) {
  try {
    return JSON.parse(text);
  } catch {
    return null;
  }
}

// ===== MODIFIED HELPER FUNCTION =====
// This function is now smarter. It will read the error message from the
// server's JSON response even on a 400 or 500 error.
async function requestOnce(url, body, mode) {
  const headers = {};
  let payload;
  if (mode === "json") {
    headers["Content-Type"] = "application/json";
    payload = JSON.stringify(body);
  } else {
    headers["Content-Type"] = "application/x-www-form-urlencoded;charset=UTF-8";
    const params = new URLSearchParams();
    Object.entries(body).forEach(([k, v]) => params.append(k, v));
    payload = params.toString();
  }

  const res = await fetch(url, { method: "POST", headers, body: payload });
  const text = await res.text();
  const data = safeJSONParse(text); // Try to parse JSON regardless of status

  if (!res.ok) {
    // If request failed, use the server's message if available, otherwise use a generic one.
    const errorMessage = data?.message || `Error: HTTP ${res.status} at ${url}`;
    throw new Error(errorMessage);
  }

  if (!data || typeof data !== "object" || !("status" in data)) {
    throw new Error(
      `Invalid JSON response from ${url}: ${text.slice(0, 200)}...`
    );
  }
  return data;
}
// ===== END MODIFICATION =====

async function postApi(url, body) {
  try {
    return await requestOnce(url, body, "json");
  } catch {
    return await requestOnce(url, body, "form");
  }
}
function escapeHTML(str = "") {
  return String(str).replace(
    /[&<>"'`=\/]/g,
    (s) =>
      ({
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#39;",
        "/": "&#x2F;",
        "`": "&#x60;",
        "=": "&#x3D;",
      }[s])
  );
}
function showNotification(message, type = "success") {
  const el = document.createElement("div");
  el.className = `notification ${type}`;
  el.textContent = message;
  document.body.appendChild(el);
  void el.offsetWidth;
  el.classList.add("show");
  setTimeout(() => {
    el.classList.remove("show");
    setTimeout(() => el.remove(), 300);
  }, 3000);
}
function showLoadingOverlay(show) {
  document.getElementById("loadingOverlay")?.classList.toggle("active", show);
}
function getCategoryLabel(type) {
  return { music: "Música", album: "Álbum", artist: "Artista" }[type] || type;
}

// ===== New Features =====

function confirmAction(message, onOk) {
  const modal = document.getElementById("confirmModal");
  if (!modal) {
    onOk?.();
    return;
  }
  const msg = document.getElementById("confirmMessage");
  const okBtn = modal.querySelector("[data-confirm-ok]");
  const cancelBtns = modal.querySelectorAll("[data-confirm-cancel]");
  msg.textContent = message || "Tem certeza?";
  modal.classList.add("active");
  const close = () => modal.classList.remove("active");
  const handleOk = () => {
    close();
    cleanup();
    onOk?.();
  };
  const handleCancel = () => {
    close();
    cleanup();
  };
  const cleanup = () => {
    okBtn.removeEventListener("click", handleOk);
    cancelBtns.forEach((b) => b.removeEventListener("click", handleCancel));
  };
  okBtn.addEventListener("click", handleOk);
  cancelBtns.forEach((b) => b.addEventListener("click", handleCancel));
}

const DISMISSED_KEY = "dismissed_recs_v1";
const getDismissed = () => {
  try {
    return JSON.parse(localStorage.getItem(DISMISSED_KEY) || "[]");
  } catch {
    return [];
  }
};
const setDismissed = (arr) =>
  localStorage.setItem(DISMISSED_KEY, JSON.stringify(arr));
const norm = (s) =>
  (s || "")
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "");
const recKey = (r) =>
  `${r.type || ""}:${norm(r.name || "")}:${norm(r.artist || "")}`;

function updateRecsCounters() {
  const getCount = (id) =>
    document.querySelectorAll(`#${id} .recommendation-card`).length;
  const set = (id, val) => {
    const el = document.getElementById(id);
    if (el) el.textContent = val;
  };
  const show = (id, on) => {
    const el = document.getElementById(id);
    if (el) el.style.display = on ? "block" : "none";
  };
  const m = getCount("recsMusic"),
    a = getCount("recsAlbum"),
    r = getCount("recsArtist");
  set("countMusic", m);
  set("countAlbum", a);
  set("countArtist", r);
  show("recsMusicEmpty", !m);
  show("recsAlbumEmpty", !a);
  show("recsArtistEmpty", !r);
}

function renderSingleRec(rec) {
  const encodedName = encodeURIComponent(rec.name || "");
  const encodedArtist = encodeURIComponent(rec.artist || "");
  const card = document.createElement("div");
  card.className = "recommendation-card";
  card.dataset.type = escapeHTML(rec.type || "");
  card.dataset.name = encodedName;
  card.dataset.artist = encodedArtist;
  card.innerHTML = `<button class="dismiss-btn" title="Não mostrar">&times;</button>
        <h3>${escapeHTML(rec.name || "")}</h3><p>${escapeHTML(
    rec.artist || ""
  )}</p>
        <div class="recommendation-reason">${escapeHTML(rec.reason || "")}</div>
        <div class="rating-stars" data-rated="0">${[1, 2, 3, 4, 5]
          .map(
            (i) =>
              `<button data-value="${i}" title="Avaliar ${i} estrelas">&#9733;</button>`
          )
          .join("")}</div>`;
  return card;
}

function renderVisibleRecommendations() {
  const dismissed = new Set(getDismissed());
  const mount = (type, gridId) => {
    const grid = document.getElementById(gridId);
    if (!grid) return;
    recommendationPool[type] = (recommendationPool[type] || []).filter(
      (r) => !dismissed.has(recKey(r))
    );
    const toDisplay = recommendationPool[type].slice(0, VISIBLE_REC_LIMIT);
    recommendationPool[type] =
      recommendationPool[type].slice(VISIBLE_REC_LIMIT);
    grid.innerHTML = "";
    toDisplay.forEach((rec) => grid.appendChild(renderSingleRec(rec)));
  };
  mount("music", "recsMusic");
  mount("album", "recsAlbum");
  mount("artist", "recsArtist");
  updateRecsCounters();
}

function refillRecommendation(type) {
  if (!recommendationPool[type] || recommendationPool[type].length === 0) {
    updateRecsCounters();
    return;
  }
  const nextRec = recommendationPool[type].shift();
  const gridId = `recs${type.charAt(0).toUpperCase() + type.slice(1)}`;
  const grid = document.getElementById(gridId);
  if (grid) {
    const newCard = renderSingleRec(nextRec);
    grid.appendChild(newCard);
    updateRecsCounters();
  }
}

function setupDock() {
  const items = document.querySelectorAll(".dock-item[data-view]");
  const views = document.querySelectorAll(".view");
  items.forEach((btn) =>
    btn.addEventListener("click", () => {
      items.forEach((b) => b.classList.remove("active"));
      btn.classList.add("active");
      const target = btn.getAttribute("data-view");
      views.forEach((v) => v.classList.toggle("active", v.id === target));
    })
  );
}

// ===== FIX FOR DAYMIX =====
async function generateDayMix(seed) {
  showLoadingOverlay(true);
  try {
    const token = localStorage.getItem("session_token");
    if (!token) {
      showNotification(
        "Você precisa estar logado para gerar uma mix.",
        "error"
      );
      return [];
    }
    const data = await postApi(DAYMIX_URL, {
      action: "generate",
      session_token: token,
      seed: seed,
    });
    if (data.status === "success" && data.data?.tracks) {
      return data.data.tracks;
    } else {
      // This part was missing proper error handling
      showNotification(
        data.message || "Não foi possível gerar a mix.",
        "error"
      );
      return [];
    }
  } catch (err) {
    console.error("Erro ao gerar Day Mix:", err);
    // MODIFIED: This now shows the specific error message from the server!
    showNotification(err.message, "error");
    return [];
  } finally {
    showLoadingOverlay(false);
  }
}

function renderDayMix(list) {
  const grid = document.getElementById("dayMixGrid");
  const countEl = document.getElementById("dayMixCount");
  const emptyEl = document.getElementById("dayMixEmpty");

  if (!grid || !countEl || !emptyEl) return;

  // Clear previous results before showing new ones or an empty message
  grid.innerHTML = "";

  if (!list || list.length === 0) {
    countEl.textContent = "0";
    emptyEl.textContent = "Informe uma música para começar.";
    emptyEl.style.display = "block";
    return;
  }

  emptyEl.style.display = "none";
  list.forEach((rec) => {
    const card = renderSingleRec(rec);
    grid.appendChild(card);
  });
  countEl.textContent = list.length;
}
// ===== END FIX FOR DAYMIX =====

// ===== Funções Principais =====
function displayFavorites() {
  const grid = document.getElementById("favoritesGrid"),
    section = document.getElementById("favoritesSection");
  if (!grid || !section) return;
  section.style.display = "block";
  if (!favorites || favorites.length === 0) {
    grid.innerHTML =
      '<div class="empty-message">Adicione seus favoritos!</div>';
    return;
  }
  grid.innerHTML = favorites
    .map(
      (item, index) => `<div class="favorite-item" data-index="${index}">
      <div class="favorite-header"><h3>${escapeHTML(
        item.name
      )}</h3><button class="remove-btn" onclick="removeFavorite(${index})" title="Remover"><svg viewBox="0 0 24 24" width="16" height="16"><path fill="currentColor" d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg></button></div>
      <p class="favorite-artist">${escapeHTML(getCategoryLabel(item.type))}${
        item.artist ? ` • ${escapeHTML(item.artist)}` : ""
      }</p>
    </div>`
    )
    .join("");
}

async function updateRecommendations() {
  try {
    const data = await postApi(RECS_URL, {
      action: "get",
      session_token: localStorage.getItem("session_token"),
    });
    if (data.status === "success" && data.data) {
      recommendationPool = data.data;
      renderVisibleRecommendations();
    } else {
      renderVisibleRecommendations();
    }
  } catch (err) {
    console.warn("Falha ao carregar recomendações:", err);
    recommendationPool = { music: [], album: [], artist: [] };
    renderVisibleRecommendations();
  }
}

async function loadFavorites() {
  const token = localStorage.getItem("session_token");
  if (!token) return;
  try {
    const data = await postApi(FAV_URL, {
      action: "get_all",
      session_token: token,
    });
    favorites =
      data.status === "success" && Array.isArray(data.data?.favorites)
        ? data.data.favorites
        : [];
  } catch (err) {
    console.error("Erro de conexão ao carregar favoritos:", err);
    favorites = [];
  } finally {
    displayFavorites();
    updateRecommendations();
  }
}

async function addFavorite(type, name, artist, cardElement = null) {
  if (cardElement) {
    cardElement.remove();
    refillRecommendation(type);
  }
  const token = localStorage.getItem("session_token");
  if (!token) {
    showNotification("Você precisa estar logado.", "error");
    return;
  }
  try {
    const data = await postApi(FAV_URL, {
      action: "add",
      session_token: token,
      type,
      name,
      artist,
    });
    if (data.status === "success") {
      showNotification(data.message || "Adicionado!", "success");
      await loadFavorites();
    } else {
      showNotification(data.message || "Não foi possível adicionar.", "error");
    }
  } catch (err) {
    console.error("Erro ao adicionar favorito:", err);
    showNotification(err.message || "Erro de conexão.", "error");
  }
}

async function removeFavoriteConfirmed(index) {
  const item = favorites[index],
    token = localStorage.getItem("session_token");
  if (!token || !item) return;
  try {
    const data = await postApi(FAV_URL, {
      action: "remove",
      session_token: token,
      ...item,
    });
    if (data.status === "success") {
      showNotification(data.message || "Removido.", "success");
      await loadFavorites();
    } else {
      showNotification(data.message || "Não foi possível remover.", "error");
    }
  } catch (err) {
    console.error("Erro ao remover favorito:", err);
    showNotification(err.message || "Erro de conexão.", "error");
  }
}
window.removeFavorite = (index) => {
  const item = favorites[index] || {};
  confirmAction(`Remover "${item.name || "este item"}" dos favoritos?`, () =>
    removeFavoriteConfirmed(index)
  );
};

function showModal(type) {
  currentType = type;
  const modal = document.getElementById("inputModal");
  document.getElementById(
    "modalTitle"
  ).textContent = `Adicionar ${getCategoryLabel(type)}`;
  document.querySelector("#submitBtn span").textContent = `Adicionar`;
  document.getElementById("itemName").placeholder =
    type === "artist" ? "Ex: Oasis" : "Ex: Wonderwall - Oasis";
  modal?.classList.add("active");
}
function closeModal() {
  const modal = document.getElementById("inputModal");
  if (modal) {
    modal.classList.remove("active");
    document.getElementById("itemName").value = "";
  }
}

async function handleSubmit(e) {
  e.preventDefault();
  const fullText = document.getElementById("itemName").value.trim();
  if (!fullText) {
    showNotification("Preencha o campo.", "error");
    return;
  }
  let name = fullText,
    artist = "";
  if (currentType !== "artist") {
    const parts = fullText.split(" - ");
    if (parts.length > 1) {
      name = parts.slice(0, -1).join(" - ").trim();
      artist = parts[parts.length - 1].trim();
    } else {
      showNotification("Use o formato 'Nome - Artista'.", "error");
      return;
    }
  }
  await addFavorite(currentType, name, artist);
  closeModal();
}

async function checkSession() {
  const sessionToken = localStorage.getItem("session_token");
  if (!sessionToken) {
    window.location.href = "auth.html";
    return;
  }

  try {
    const data = await postApi(AUTH_URL, {
      action: "check_session",
      session_token: sessionToken,
    });

    if (data.status === "success" && data.data?.is_logged_in) {
      const user = data.data.user || {};
      const spotifyLoginDiv = document.getElementById("spotifyLogin");
      const userInfoDiv = document.getElementById("userInfo");
      const avatarDiv = document.getElementById("userAvatar");
      const nameSpan = document.getElementById("username");

      if (user.spotify_user_id) {
        // Check if connected to Spotify
        spotifyLoginDiv.style.display = "none";
        userInfoDiv.style.display = "flex";
        nameSpan.textContent =
          user.spotify_display_name || user.username || "Usuário";

        if (user.spotify_avatar_url) {
          avatarDiv.style.backgroundImage = `url(${user.spotify_avatar_url})`;
          avatarDiv.textContent = "";
        } else {
          avatarDiv.style.backgroundImage = "";
          avatarDiv.textContent = (user.spotify_display_name || "U")
            .charAt(0)
            .toUpperCase();
        }
      } else {
        spotifyLoginDiv.style.display = "block";
        userInfoDiv.style.display = "none";
      }
      await loadFavorites();
    } else {
      localStorage.removeItem("session_token");
      window.location.href = "auth.html";
    }
  } catch (err) {
    console.error("Erro na verificação de sessão:", err);
    localStorage.removeItem("session_token");
    window.location.href = "auth.html";
  }
}
window.connectSpotify = () => {
  const token = localStorage.getItem("session_token");
  if (token) {
    window.location.href = `api/spotify_auth_start.php?token=${token}`;
  } else {
    showNotification("Você precisa estar logado no MAP primeiro.", "error");
  }
};

window.logout = async () => {
  const token = localStorage.getItem("session_token");
  try {
    if (token)
      await postApi(AUTH_URL, { action: "logout", session_token: token });
  } catch (err) {
    console.warn("Falha no logout do servidor:", err);
  } finally {
    localStorage.removeItem("session_token");
    localStorage.removeItem(DISMISSED_KEY); // Clear dismissed recs on logout
    window.location.href = "auth.html";
  }
};

function setupEventListeners() {
  document.addEventListener("click", (e) => {
    const addBtn = e.target.closest(".category-btn");
    if (addBtn) {
      showModal(addBtn.dataset.type);
      return;
    }
    const recCard = e.target.closest(".recommendation-card");
    if (
      recCard &&
      !e.target.closest(".dismiss-btn") &&
      !e.target.closest(".rating-stars")
    ) {
      const type = decodeURIComponent(recCard.dataset.type || "");
      const name = decodeURIComponent(recCard.dataset.name || "");
      const artist = decodeURIComponent(recCard.dataset.artist || "");
      addFavorite(type, name, artist, recCard);
      return;
    }
    const dismissBtn = e.target.closest(".dismiss-btn");
    if (dismissBtn) {
      const card = dismissBtn.closest(".recommendation-card");
      const type = decodeURIComponent(card.dataset.type || "");
      const name = decodeURIComponent(card.dataset.name || "");
      confirmAction(`Ocultar "${name}"?`, () => {
        const dismissed = getDismissed();
        const key = recKey({
          type,
          name,
          artist: decodeURIComponent(card.dataset.artist || ""),
        });
        if (!dismissed.includes(key)) dismissed.push(key);
        setDismissed(dismissed);
        card?.remove();
        refillRecommendation(type);
      });
      return;
    }
    const starBtn = e.target.closest(".rating-stars button");
    if (starBtn) {
      const card = starBtn.closest(".recommendation-card");
      const rating = parseInt(starBtn.dataset.value, 10);
      const type = decodeURIComponent(card.dataset.type || "");
      const name = decodeURIComponent(card.dataset.name || "");
      const artist = decodeURIComponent(card.dataset.artist || "");
      postApi(FEEDBACK_URL, {
        action: "rate",
        session_token: localStorage.getItem("session_token"),
        type,
        name,
        artist,
        rating,
      });
      starBtn.parentElement
        .querySelectorAll("button")
        .forEach((s, i) => s.classList.toggle("rated", i < rating));
    }
  });
  document.getElementById("closeBtn")?.addEventListener("click", closeModal);
  document.getElementById("addForm")?.addEventListener("submit", handleSubmit);
  document.getElementById("inputModal")?.addEventListener("click", (e) => {
    if (e.target === e.currentTarget) closeModal();
  });
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape") closeModal();
  });
}

document.addEventListener("DOMContentLoaded", () => {
  setupEventListeners();
  setupDock();
  if (typeof createAutocomplete === "function") {
    createAutocomplete("itemName", "itemNameSuggestions", () => {
      if (currentType === "music") return "track";
      if (currentType === "album") return "album";
      return "artist";
    });
    createAutocomplete("daySeedInput", "daySeedSuggestions", "track");
  }
  document
    .getElementById("dayMixForm")
    ?.addEventListener("submit", async (e) => {
      e.preventDefault();
      const seed = document.getElementById("daySeedInput")?.value.trim();
      if (!seed) return;
      const empty = document.getElementById("dayMixEmpty");
      if (empty) {
        empty.textContent = "Gerando...";
        empty.style.display = "block";
      }
      const list = await generateDayMix(seed);
      renderDayMix(list);
    });
  checkSession();
});
