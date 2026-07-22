/**
 * Laioutr embedded-storefront bridge.
 *
 * Runs on every storefront page when embedded mode is on. Talks to the Laioutr
 * parent frame over postMessage: reports content height for iframe sizing and
 * notifies the parent of page loads, checkout completion, and password recovery.
 *
 * Every message uses the envelope { source, version, type, payload }. The parent
 * ignores anything without source === SOURCE. Data-bearing messages are buffered
 * until the parent completes the handshake (laioutr:init) so nothing with data is
 * broadcast to '*'; only the contentless laioutr:ready ping is.
 */
(function () {
  "use strict";

  var SOURCE = "laioutr-shopware";
  var VERSION = 1;

  var script = document.currentScript;
  var dataset = script ? script.dataset : {};
  var allowedOrigins = parseOrigins(dataset.allowedOrigins);

  var trustedOrigin = null; // exact parent origin, learned from the handshake
  var queue = []; // data-bearing messages buffered until the handshake completes

  function parseOrigins(raw) {
    if (!raw) {
      return [];
    }
    return raw
      .split(/\r\n|\r|\n/)
      .map(function (line) { return line.trim(); })
      .filter(function (line) { return line !== ""; });
  }

  // Match an origin against the configured host patterns (e.g. "*.example.com").
  function originAllowed(origin) {
    if (allowedOrigins.length === 0) {
      return true; // no allowlist configured: rely on the frame-ancestors CSP
    }
    var host;
    try {
      host = new URL(origin).hostname;
    } catch (e) {
      return false;
    }
    return allowedOrigins.some(function (pattern) {
      var re = new RegExp("^" + pattern.replace(/[.]/g, "\\.").replace(/\*/g, ".*") + "$");
      return re.test(host);
    });
  }

  function envelope(type, payload) {
    return { source: SOURCE, version: VERSION, type: type, payload: payload || {} };
  }

  function post(type, payload) {
    var message = envelope(type, payload);
    if (trustedOrigin) {
      window.parent.postMessage(message, trustedOrigin);
    } else {
      queue.push(message);
    }
  }

  function flushQueue() {
    if (!trustedOrigin) {
      return;
    }
    queue.forEach(function (message) {
      window.parent.postMessage(message, trustedOrigin);
    });
    queue = [];
  }

  function sendResize() {
    post("laioutr:resize", { height: document.body.scrollHeight });
  }

  function sendPageLoaded() {
    post("laioutr:page-loaded", {
      path: window.location.pathname,
      route: dataset.route || null,
      navigationId: dataset.navigationId || null,
      salesChannelId: dataset.salesChannelId || null
    });
  }

  function sendCheckoutFinish() {
    var el = document.querySelector("[data-laioutr-order-id]");
    if (el) {
      post("laioutr:checkout-finish", { orderId: el.getAttribute("data-laioutr-order-id") });
    }
  }

  function sendAuthChanged() {
    var from = dataset.authFrom;
    if (!from) {
      return;
    }
    var payload = { from: from };
    if (dataset.authCode) {
      payload.code = dataset.authCode;
    }
    post("laioutr:auth-changed", payload);
  }

  function wirePasswordRecovery() {
    var btn = document.querySelector(".btn-pw-recovery, [data-laioutr-pw-recovery]");
    if (btn) {
      btn.addEventListener("click", function () {
        post("laioutr:pw-recovery", {});
      });
    }
  }

  // Inbound: complete the handshake when the trusted parent replies.
  window.addEventListener("message", function (event) {
    var data = event.data;
    if (!data || data.source !== SOURCE || data.type !== "laioutr:init") {
      return;
    }
    if (!originAllowed(event.origin)) {
      return;
    }
    trustedOrigin = event.origin;
    flushQueue();
  });

  function init() {
    // Contentless ping — safe to broadcast; invites the parent's laioutr:init.
    window.parent.postMessage(envelope("laioutr:ready", {}), "*");

    sendPageLoaded();
    sendResize();
    sendCheckoutFinish();
    sendAuthChanged();
    wirePasswordRecovery();

    if (typeof ResizeObserver !== "undefined") {
      new ResizeObserver(sendResize).observe(document.body);
    }
    window.addEventListener("resize", sendResize);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
