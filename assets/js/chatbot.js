(function () {
  "use strict";

  var cfg = window.luminationChatbotConfig || {};

  /* ── UUID generator ── */

  function generateUuid() {
    return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, function (c) {
      var r = (Math.random() * 16) | 0;
      var v = c === "x" ? r : (r & 0x3) | 0x8;
      return v.toString(16);
    });
  }

  /* ── Fallback markdown renderer (used when marked.js unavailable) ── */

  function escapeHtml(text) {
    var el = document.createElement("span");
    el.textContent = text;
    return el.innerHTML;
  }

  function renderMarkdownFallback(text) {
    var html = text
      .replace(/```[\s\S]*?```/g, function (m) {
        var inner = m.slice(3, -3).replace(/^\w*\n/, "");
        return "<pre><code>" + escapeHtml(inner) + "</code></pre>";
      })
      .replace(/`([^`]+)`/g, function (m, c) { return "<code>" + escapeHtml(c) + "</code>"; })
      .replace(/\*\*(.+?)\*\*/g, "<strong>$1</strong>")
      .replace(/\*(.+?)\*/g, "<em>$1</em>")
      .replace(/^#{1,3} (.+)$/gm, "<strong>$1</strong>")
      .replace(/^[*\-] (.+)$/gm, "<li>$1</li>")
      .replace(/^\d+\. (.+)$/gm, "<li>$1</li>")
      .replace(/\n/g, "<br>");

    html = html.replace(/((?:<li>.*?<\/li>(?:<br>)?)+)/g, function (m) {
      return "<ul>" + m.replace(/<br>/g, "") + "</ul>";
    });

    return html;
  }

  /* ── Render assistant message with MathJax support ── */

  function renderAssistantContent(container, markdownText) {
    // Use Core's LuminationMathRenderer if available (protect → parse → sanitize → restore → typeset).
    if (typeof window.LuminationMathRenderer !== "undefined") {
      window.LuminationMathRenderer.render(container, markdownText);
      return;
    }

    // Fallback: use marked + DOMPurify if available, else basic renderer.
    if (typeof marked !== "undefined" && typeof DOMPurify !== "undefined") {
      if (typeof marked.setOptions === "function") {
        marked.setOptions({ gfm: true, breaks: true });
      }
      container.innerHTML = DOMPurify.sanitize(marked.parse(markdownText), {
        USE_PROFILES: { html: true }
      });
    } else {
      container.innerHTML = renderMarkdownFallback(markdownText);
    }
  }

  /* ── Chatbot instance factory ── */

  function initChatbot(root) {
    var mode         = root.getAttribute("data-mode") || "floating";
    var welcomeText  = root.getAttribute("data-welcome") || "";
    var history      = [];
    var busy         = false;
    var welcomeShown = false;
    var isFullscreen = false;
    var sessionUuid  = generateUuid();
    var pendingFile  = null;
    var backdrop     = null;

    var panel         = root.querySelector(".lmc-panel");
    var bubble        = root.querySelector(".lmc-bubble");
    var closeBtn      = root.querySelector(".lmc-close");
    var form          = root.querySelector(".lmc-form");
    var input         = root.querySelector(".lmc-input");
    var messages      = root.querySelector(".lmc-messages");
    var fullscreenBtn = root.querySelector(".lmc-fullscreen-toggle");
    var exportBtn     = root.querySelector(".lmc-export");
    var attachBtn     = root.querySelector(".lmc-attach");
    var fileInput     = root.querySelector(".lmc-file-input");

    /* ── Mode-specific init ── */

    if (mode === "embed") {
      showWelcome();
      showSuggestions();
      input.focus();
    }

    if (mode === "floating" && bubble) {
      bubble.addEventListener("click", openPanel);
    }

    if (closeBtn) {
      closeBtn.addEventListener("click", closePanel);
    }

    /* ── Fullscreen toggle ── */

    if (fullscreenBtn) {
      fullscreenBtn.addEventListener("click", toggleFullscreen);
    }

    function toggleFullscreen() {
      isFullscreen = !isFullscreen;

      if (isFullscreen) {
        root.classList.add("lmc-chatbot--fullscreen");
        fullscreenBtn.querySelector(".lmc-icon-expand").classList.add("lmc-hidden");
        fullscreenBtn.querySelector(".lmc-icon-collapse").classList.remove("lmc-hidden");

        // Add backdrop for floating mode.
        if (mode === "floating") {
          backdrop = document.createElement("div");
          backdrop.className = "lmc-fullscreen-backdrop";
          document.body.appendChild(backdrop);
        }

        // Show panel if hidden (floating mode).
        panel.classList.remove("lmc-hidden");
        if (bubble) { bubble.classList.add("lmc-hidden"); }

        // Escape key exits fullscreen.
        document.addEventListener("keydown", handleEscapeFullscreen);
      } else {
        exitFullscreen();
      }

      messages.scrollTop = messages.scrollHeight;
      input.focus();
    }

    function exitFullscreen() {
      isFullscreen = false;
      root.classList.remove("lmc-chatbot--fullscreen");
      fullscreenBtn.querySelector(".lmc-icon-expand").classList.remove("lmc-hidden");
      fullscreenBtn.querySelector(".lmc-icon-collapse").classList.add("lmc-hidden");

      if (backdrop) {
        backdrop.remove();
        backdrop = null;
      }

      document.removeEventListener("keydown", handleEscapeFullscreen);
    }

    function handleEscapeFullscreen(e) {
      if (e.key === "Escape" && isFullscreen) {
        exitFullscreen();
      }
    }

    /* ── Export chat ── */

    if (exportBtn) {
      exportBtn.addEventListener("click", exportChat);
    }

    function exportChat() {
      if (history.length === 0) { return; }

      var lines = [];
      var title = root.querySelector(".lmc-title");
      lines.push("Chat Export — " + (title ? title.textContent : "AI Assistant"));
      lines.push("Date: " + new Date().toLocaleString());
      lines.push("─".repeat(40));
      lines.push("");

      history.forEach(function (entry) {
        var label = entry.role === "user" ? "You" : "Assistant";
        lines.push(label + ":");
        lines.push(entry.content);
        lines.push("");
      });

      var blob = new Blob([lines.join("\n")], { type: "text/plain;charset=utf-8" });
      var url = URL.createObjectURL(blob);
      var a = document.createElement("a");
      a.href = url;
      a.download = "chat-export-" + new Date().toISOString().slice(0, 10) + ".txt";
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    }

    /* ── File upload ── */

    if (attachBtn && fileInput) {
      attachBtn.addEventListener("click", function () {
        fileInput.click();
      });

      fileInput.addEventListener("change", function () {
        var file = fileInput.files[0];
        if (!file) { return; }

        // Validate size.
        if (file.size > (cfg.fileMaxSize || 2 * 1024 * 1024)) {
          alert("File too large. Maximum size is " + (cfg.fileMaxSizeMB || 2) + " MB.");
          fileInput.value = "";
          return;
        }

        pendingFile = file;
        showFilePreview(file);
      });
    }

    function showFilePreview(file) {
      removeFilePreview();

      var preview = document.createElement("div");
      preview.className = "lmc-file-preview";

      // Thumbnail for images.
      if (file.type.startsWith("image/")) {
        var thumb = document.createElement("img");
        thumb.className = "lmc-file-preview-thumb";
        thumb.src = URL.createObjectURL(file);
        preview.appendChild(thumb);
      }

      var name = document.createElement("span");
      name.className = "lmc-file-preview-name";
      name.textContent = file.name;
      preview.appendChild(name);

      var remove = document.createElement("button");
      remove.className = "lmc-file-preview-remove";
      remove.type = "button";
      remove.innerHTML = "&times;";
      remove.addEventListener("click", function () {
        clearFile();
      });
      preview.appendChild(remove);

      // Insert preview before the input row.
      form.insertBefore(preview, form.firstChild);
    }

    function removeFilePreview() {
      var existing = form.querySelector(".lmc-file-preview");
      if (existing) { existing.remove(); }
    }

    function clearFile() {
      pendingFile = null;
      if (fileInput) { fileInput.value = ""; }
      removeFilePreview();
    }

    /* ── Suggested prompts ── */

    function showSuggestions() {
      var prompts = cfg.suggestedPrompts || [];
      if (prompts.length === 0) { return; }

      var container = document.createElement("div");
      container.className = "lmc-suggestions";

      prompts.forEach(function (text) {
        var btn = document.createElement("button");
        btn.className = "lmc-suggestion-btn";
        btn.type = "button";
        btn.textContent = text;
        btn.addEventListener("click", function () {
          input.value = text;
          hideSuggestions();
          form.dispatchEvent(new Event("submit", { cancelable: true }));
        });
        container.appendChild(btn);
      });

      // Insert after messages, before form.
      panel.insertBefore(container, form);
    }

    function hideSuggestions() {
      var el = panel.querySelector(".lmc-suggestions");
      if (el) { el.remove(); }
    }

    /* ── Toggle (floating only) ── */

    function openPanel() {
      panel.classList.remove("lmc-hidden");
      bubble.classList.add("lmc-hidden");
      showWelcome();
      showSuggestions();
      input.focus();
    }

    function closePanel() {
      if (isFullscreen) { exitFullscreen(); }
      panel.classList.add("lmc-hidden");
      if (bubble) { bubble.classList.remove("lmc-hidden"); }
    }

    /* ── Welcome message ── */

    function showWelcome() {
      if (welcomeShown || !welcomeText) { return; }
      addMessage("assistant", welcomeText);
      welcomeShown = true;
    }

    /* ── Typing indicator ── */

    function showTyping() {
      var indicator = document.createElement("div");
      indicator.className = "lmc-typing";
      indicator.innerHTML =
        '<span class="lmc-typing-dot"></span>' +
        '<span class="lmc-typing-dot"></span>' +
        '<span class="lmc-typing-dot"></span>';
      messages.appendChild(indicator);
      messages.scrollTop = messages.scrollHeight;
      return indicator;
    }

    /* ── Message rendering ── */

    function addMessage(role, text, fileName) {
      var div = document.createElement("div");
      div.className = "lmc-msg lmc-msg-" + role;

      if (role === "assistant") {
        renderAssistantContent(div, text);
      } else {
        div.textContent = text;
      }

      // Show file badge on user message.
      if (fileName && role === "user") {
        var badge = document.createElement("div");
        badge.className = "lmc-file-badge";
        badge.innerHTML =
          '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>' +
          " " + escapeHtml(fileName);
        div.appendChild(badge);
      }

      messages.appendChild(div);
      messages.scrollTop = messages.scrollHeight;
      return div;
    }

    /* ── Send message ── */

    form.addEventListener("submit", function (e) {
      e.preventDefault();

      var text = input.value.trim();
      if (!text || busy) { return; }

      var currentFile = pendingFile;
      var currentFileName = currentFile ? currentFile.name : null;

      addMessage("user", text, currentFileName);
      history.push({ role: "user", content: text });
      input.value = "";
      busy = true;
      form.querySelector(".lmc-send").disabled = true;
      clearFile();
      hideSuggestions();

      var typing = showTyping();

      var formData = new FormData();
      formData.append("action",       "lumination_chatbot_send");
      formData.append("nonce",        cfg.nonce || "");
      formData.append("message",      text);
      formData.append("page_url",     window.location.href);
      formData.append("session_uuid", sessionUuid);
      formData.append("history",      JSON.stringify(history.slice(-12)));

      if (currentFile) {
        formData.append("file", currentFile);
      }

      fetch(cfg.ajaxUrl, { method: "POST", body: formData })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (typing.parentNode) { typing.remove(); }
          if (data.success && data.data && data.data.reply) {
            var reply = data.data.reply;
            addMessage("assistant", reply);
            history.push({ role: "assistant", content: reply });
          } else {
            var errMsg = (data.data && data.data.message)
              ? data.data.message
              : "Sorry, I couldn\u2019t get a response.";
            addMessage("assistant", errMsg);
          }
        })
        .catch(function () {
          if (typing.parentNode) { typing.remove(); }
          addMessage("assistant", "Something went wrong. Please try again.");
        })
        .finally(function () {
          busy = false;
          form.querySelector(".lmc-send").disabled = false;
          input.focus();
        });
    });
  }

  /* ── Bootstrap: attach to every .lmc-chatbot on the page ── */

  function boot() {
    var roots = document.querySelectorAll(".lmc-chatbot");
    roots.forEach(function (root) {
      initChatbot(root);
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }
})();
