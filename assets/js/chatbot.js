(function () {
  "use strict";

  var cfg = window.luminationChatbotConfig || {};

  /* ── Markdown renderer (lightweight) ── */

  function escapeHtml(text) {
    var el = document.createElement("span");
    el.textContent = text;
    return el.innerHTML;
  }

  function renderMarkdown(text) {
    var html = text
      // fenced code blocks
      .replace(/```[\s\S]*?```/g, function (m) {
        var inner = m.slice(3, -3).replace(/^\w*\n/, "");
        return "<pre><code>" + escapeHtml(inner) + "</code></pre>";
      })
      // inline code
      .replace(/`([^`]+)`/g, function (m, c) { return "<code>" + escapeHtml(c) + "</code>"; })
      // bold
      .replace(/\*\*(.+?)\*\*/g, "<strong>$1</strong>")
      // italic
      .replace(/\*(.+?)\*/g, "<em>$1</em>")
      // headings (all levels → strong in a chat widget)
      .replace(/^#{1,3} (.+)$/gm, "<strong>$1</strong>")
      // unordered list items
      .replace(/^[*\-] (.+)$/gm, "<li>$1</li>")
      // ordered list items
      .replace(/^\d+\. (.+)$/gm, "<li>$1</li>")
      // line breaks
      .replace(/\n/g, "<br>");

    // Wrap consecutive <li> runs in <ul>
    html = html.replace(/((?:<li>.*?<\/li>(?:<br>)?)+)/g, function (m) {
      return "<ul>" + m.replace(/<br>/g, "") + "</ul>";
    });

    return html;
  }

  /* ── UUID generator ── */

  function generateUuid() {
    return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, function (c) {
      var r = (Math.random() * 16) | 0;
      var v = c === "x" ? r : (r & 0x3) | 0x8;
      return v.toString(16);
    });
  }

  /* ── Chatbot instance factory ── */

  function initChatbot(root) {
    var mode         = root.getAttribute("data-mode") || "floating";
    var welcomeText  = root.getAttribute("data-welcome") || "";
    var history      = [];
    var busy         = false;
    var welcomeShown = false;
    var sessionUuid  = generateUuid();

    var panel    = root.querySelector(".lmc-panel");
    var bubble   = root.querySelector(".lmc-bubble");
    var closeBtn = root.querySelector(".lmc-close");
    var form     = root.querySelector(".lmc-form");
    var input    = root.querySelector(".lmc-input");
    var messages = root.querySelector(".lmc-messages");

    /* ── Mode-specific init ── */

    if (mode === "embed") {
      // Embedded: panel always open; show welcome immediately.
      showWelcome();
      input.focus();
    }

    if (mode === "floating" && bubble) {
      bubble.addEventListener("click", openPanel);
    }

    if (closeBtn) {
      closeBtn.addEventListener("click", closePanel);
    }

    /* ── Toggle (floating only) ── */

    function openPanel() {
      panel.classList.remove("lmc-hidden");
      bubble.classList.add("lmc-hidden");
      showWelcome();
      input.focus();
    }

    function closePanel() {
      panel.classList.add("lmc-hidden");
      if (bubble) { bubble.classList.remove("lmc-hidden"); }
    }

    /* ── Welcome message ── */

    function showWelcome() {
      if (welcomeShown || !welcomeText) { return; }
      addMessage("assistant", welcomeText);
      welcomeShown = true;
    }

    /* ── Message rendering ── */

    function addMessage(role, text) {
      var div = document.createElement("div");
      div.className = "lmc-msg lmc-msg-" + role;

      if (role === "assistant") {
        div.innerHTML = renderMarkdown(text);
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

      var loading = addMessage("assistant", "…");
      loading.classList.add("lmc-msg-loading");

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
          messages.removeChild(loading);
          var reply = (data.success && data.data && data.data.reply)
            ? data.data.reply
            : "Sorry, I couldn\u2019t get a response.";
          addMessage("assistant", reply);
          history.push({ role: "assistant", content: reply });
        })
        .catch(function () {
          messages.removeChild(loading);
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
