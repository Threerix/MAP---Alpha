// js/autocomplete.js

function createAutocomplete(inputId, suggestionsId, typeProvider) {
  const input = document.getElementById(inputId);
  const suggestionsBox = document.getElementById(suggestionsId);
  if (!input || !suggestionsBox) return;

  let debounceTimer;

  input.addEventListener("input", () => {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(async () => {
      const query = input.value.trim();
      if (query.length < 2) {
        suggestionsBox.style.display = "none";
        return;
      }

      const type =
        typeof typeProvider === "function" ? typeProvider() : typeProvider;

      try {
        const response = await fetch(
          `api/search_api.php?type=${type}&query=${encodeURIComponent(query)}`
        );
        const data = await response.json();

        if (data.status === "success" && data.data.length > 0) {
          suggestionsBox.innerHTML = data.data
            .map((item) => {
              const text = item.artist
                ? `${item.name} - ${item.artist}`
                : item.name;
              return `<div class="suggestion-item" data-value="${escapeHTML(
                text
              )}">${escapeHTML(item.name)} <span class="artist">${escapeHTML(
                item.artist || ""
              )}</span></div>`;
            })
            .join("");
          suggestionsBox.style.display = "block";
        } else {
          suggestionsBox.style.display = "none";
        }
      } catch (e) {
        console.error("Autocomplete failed:", e);
        suggestionsBox.style.display = "none";
      }
    }, 300);
  });

  suggestionsBox.addEventListener("click", (e) => {
    const item = e.target.closest(".suggestion-item");
    if (item) {
      input.value = item.dataset.value;
      suggestionsBox.style.display = "none";
    }
  });

  document.addEventListener("click", (e) => {
    if (!input.contains(e.target) && !suggestionsBox.contains(e.target)) {
      suggestionsBox.style.display = "none";
    }
  });
}
