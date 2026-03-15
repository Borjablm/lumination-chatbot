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
    var backdrop     = null;

    var panel         = root.querySelector(".lmc-panel");
    var bubble        = root.querySelector(".lmc-bubble");
    var closeBtn      = root.querySelector(".lmc-close");
    var form          = root.querySelector(".lmc-form");
    var input         = root.querySelector(".lmc-input");
    var messages      = root.querySelector(".lmc-messages");
    var fullscreenBtn = root.querySelector(".lmc-fullscreen-toggle");
    var exportBtn     = root.querySelector(".lmc-export");
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
        // Add backdrop and block body scroll.
        backdrop = document.createElement("div");
        backdrop.className = "lmc-fullscreen-backdrop";
        backdrop.addEventListener("click", function () { exitFullscreen(); });
        root.insertBefore(backdrop, root.firstChild);
        document.body.style.overflow = "hidden";

        root.classList.add("lmc-chatbot--fullscreen");
        fullscreenBtn.querySelector(".lmc-icon-expand").classList.add("lmc-hidden");
        fullscreenBtn.querySelector(".lmc-icon-collapse").classList.remove("lmc-hidden");

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

      document.body.style.overflow = "";
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

    function addMessage(role, text) {
      var div = document.createElement("div");
      div.className = "lmc-msg lmc-msg-" + role;

      if (role === "assistant") {
        renderAssistantContent(div, text);
      } else {
        div.textContent = text;
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

      addMessage("user", text);
      history.push({ role: "user", content: text });
      input.value = "";
      busy = true;
      form.querySelector(".lmc-send").disabled = true;
      hideSuggestions();

      var typing = showTyping();

      var formData = new FormData();
      formData.append("action",       "lumination_chatbot_send");
      formData.append("nonce",        cfg.nonce || "");
      formData.append("message",      text);
      formData.append("page_url",     window.location.href);
      formData.append("session_uuid", sessionUuid);
      formData.append("history",      JSON.stringify(history.slice(-12)));

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
