(function () {
  if (globalThis.__xfrLoaded) return;
  globalThis.__xfrLoaded = true;

  const SKIP = new Set([
    "home", "explore", "notifications", "messages", "settings", "i", "compose",
    "search", "login", "signup", "tos", "privacy", "intent", "share", "jobs",
    "about", "help", "download", "account", "display", "hashtag"
  ]);
  const NOISE = /^(follow|following|follows you|promoted|subscribe|subscribed|blocked|mute|muted|more)$/i;

  function textOf(el) {
    if (!el) return "";
    return (el.innerText || el.textContent || "").replace(/\s+/g, " ").trim();
  }

  function clip(value, max) {
    if (!value) return "";
    return value.length > max ? value.slice(0, max) : value;
  }

  function pathOf(href) {
    if (!href) return "";
    try {
      if (/^https?:\/\//i.test(href)) return new URL(href).pathname;
    } catch (err) {
      return "";
    }
    return href.split("?")[0].split("#")[0];
  }

  function handleFromHref(href) {
    const match = pathOf(href).match(/^\/([A-Za-z0-9_]{1,15})\/?$/);
    if (!match) return null;
    if (SKIP.has(match[1].toLowerCase())) return null;
    return match[1];
  }

  function pageInfo() {
    const match = location.pathname.match(/^\/([A-Za-z0-9_]{1,15})\/following\/?$/i);
    return match ? { ok: true, owner: match[1] } : { ok: false, owner: "" };
  }

  function isChrome(el) {
    return !!el.closest('nav, [role="navigation"], [data-testid="sidebarColumn"]');
  }

  function findId(row) {
    const nodes = [row, ...row.querySelectorAll("*")];
    for (const el of nodes) {
      if (!el.getAttribute) continue;
      for (const attr of ["data-user-id", "data-userid"]) {
        const value = el.getAttribute(attr);
        if (value && /^\d{4,30}$/.test(value)) return value;
      }
      const testid = el.getAttribute("data-testid") || "";
      const fromTest = testid.match(/^(\d{4,30})-(follow|unfollow)$/i);
      if (fromTest) return fromTest[1];
      if (el.tagName === "A") {
        const href = el.getAttribute("href") || "";
        const fromLink = href.match(/\/i\/user\/(\d{4,30})/);
        if (fromLink) return fromLink[1];
      }
    }
    return "";
  }

  function nameFromRow(row, username) {
    const handle = username.toLowerCase();
    const links = [...row.querySelectorAll("a[href]")].filter((a) => {
      const found = handleFromHref(a.getAttribute("href"));
      return found && found.toLowerCase() === handle && !isChrome(a);
    });
    for (const link of links) {
      const spans = [...link.querySelectorAll("span")];
      for (const span of spans) {
        if (span.querySelector("span")) continue;
        const value = textOf(span);
        if (!value || value.length > 80) continue;
        const lower = value.toLowerCase();
        if (lower === handle || lower === "@" + handle || NOISE.test(value)) continue;
        return value;
      }
    }
    return "";
  }

  function bioFromRow(row, username, name) {
    const desc = row.querySelector('[data-testid="UserDescription"]');
    if (desc) return clip(textOf(desc), 500);
    const handle = "@" + username.toLowerCase();
    const chunks = [];
    for (const el of row.querySelectorAll('[dir="auto"]')) {
      if (el.closest("a, button")) continue;
      if (el.querySelector('[dir="auto"]')) continue;
      const value = textOf(el);
      if (!value || value.length > 500 || NOISE.test(value)) continue;
      const lower = value.toLowerCase();
      if (value === name || lower === username.toLowerCase() || lower === handle) continue;
      chunks.push(value);
    }
    chunks.sort((a, b) => b.length - a.length);
    return chunks[0] ? clip(chunks[0], 500) : "";
  }

  function extractAccount(row, owner) {
    let username = "";
    for (const link of row.querySelectorAll("a[href]")) {
      if (isChrome(link)) continue;
      const found = handleFromHref(link.getAttribute("href"));
      if (!found) continue;
      if (owner && found.toLowerCase() === owner.toLowerCase()) continue;
      username = found;
      break;
    }
    if (!username) return null;
    const name = nameFromRow(row, username);
    const bio = bioFromRow(row, username, name);
    const numeric = findId(row);
    return {
      id: numeric || username,
      username: username,
      name: name || username,
      bio: bio,
      url: "https://x.com/" + username
    };
  }

  function rowsFromLinks(root) {
    const seen = new Set();
    const rows = [];
    for (const link of root.querySelectorAll("a[href]")) {
      if (isChrome(link) || !handleFromHref(link.getAttribute("href"))) continue;
      const row = link.closest('[data-testid="cellInnerDiv"], article, [data-testid="UserCell"]')
        || link.parentElement;
      if (!row || seen.has(row) || isChrome(row)) continue;
      seen.add(row);
      rows.push(row);
    }
    return rows;
  }

  function findRows() {
    const primary = document.querySelector('[data-testid="primaryColumn"]');
    if (primary) {
      const cells = [...primary.querySelectorAll('[data-testid="UserCell"]')];
      if (cells.length) return cells;
      const loose = rowsFromLinks(primary);
      if (loose.length) return loose;
    }
    const cells = [...document.querySelectorAll('[data-testid="UserCell"]')].filter((el) => !isChrome(el));
    if (cells.length) return cells;
    return rowsFromLinks(document.body);
  }

  function collectVisible() {
    const info = pageInfo();
    if (!info.ok) {
      return { ok: false, error: "Open an X Following page first (x.com/you/following)." };
    }
    const map = new Map();
    for (const row of findRows()) {
      const account = extractAccount(row, info.owner);
      if (!account) continue;
      const key = account.username.toLowerCase();
      const prev = map.get(key);
      if (!prev) {
        map.set(key, account);
        continue;
      }
      const id = /^\d{4,}$/.test(prev.id) ? prev.id : (/^\d{4,}$/.test(account.id) ? account.id : prev.id);
      const name = prev.name && prev.name.toLowerCase() !== prev.username.toLowerCase()
        ? prev.name
        : (account.name || prev.name);
      const bio = (account.bio || "").length > (prev.bio || "").length ? account.bio : prev.bio;
      map.set(key, {
        id: id,
        username: prev.username,
        name: name || prev.username,
        bio: bio || "",
        url: "https://x.com/" + prev.username
      });
    }
    return { ok: true, accounts: [...map.values()] };
  }

  function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
  }

  // Quiet scrolls in a row before the Following list counts as finished.
  // Long enough for a virtualized list to catch up after a slow load.
  const STAGNANT_SCROLLS = 12;
  const SCROLL_PAUSE_MS = 750;
  // Safety only. At the pace seen on a real Following page (~6 new rows per
  // step), this still covers well over 400 accounts before it gives up.
  const MAX_SCROLL_STEPS = 2000;

  function scrollOnce() {
    const cell = document.querySelector('[data-testid="primaryColumn"] [data-testid="UserCell"]')
      || document.querySelector('[data-testid="UserCell"]');
    const targets = [];
    let el = cell ? cell.parentElement : null;
    while (el && el !== document.documentElement) {
      const style = getComputedStyle(el);
      if (/(auto|scroll)/.test(style.overflowY) && el.scrollHeight > el.clientHeight + 80) {
        targets.push(el);
      }
      el = el.parentElement;
    }
    targets.push(window);
    for (const target of targets) {
      if (target === window) {
        const before = window.scrollY;
        const delta = Math.floor(window.innerHeight * 0.8);
        window.scrollTo(0, before + delta);
        if (Math.abs(window.scrollY - before) > 1) return true;
      } else {
        const before = target.scrollTop;
        target.scrollTop = before + Math.floor(target.clientHeight * 0.8);
        if (Math.abs(target.scrollTop - before) > 1) return true;
      }
    }
    return false;
  }

  function reportProgress(count, step) {
    try {
      chrome.runtime.sendMessage({ type: "xfr-progress", count: count, step: step }, () => {
        void chrome.runtime.lastError;
      });
    } catch (err) {
      /* popup may have closed */
    }
  }

  function mergeAccount(map, account) {
    const key = account.username.toLowerCase();
    const prev = map.get(key);
    if (!prev) {
      map.set(key, account);
      return true;
    }
    const id = /^\d{4,}$/.test(prev.id) ? prev.id : (/^\d{4,}$/.test(account.id) ? account.id : prev.id);
    const bio = (account.bio || "").length > (prev.bio || "").length ? account.bio : prev.bio;
    const name = prev.name && prev.name.toLowerCase() !== prev.username.toLowerCase()
      ? prev.name
      : (account.name || prev.name);
    map.set(key, {
      id: id,
      username: prev.username,
      name: name || prev.username,
      bio: bio || "",
      url: "https://x.com/" + prev.username
    });
    return false;
  }

  async function captureAndScroll() {
    const first = collectVisible();
    if (!first.ok) return first;
    const map = new Map();
    for (const account of first.accounts) mergeAccount(map, account);
    reportProgress(map.size, 0);
    let stagnant = 0;
    let steps = 0;
    while (steps < MAX_SCROLL_STEPS && stagnant < STAGNANT_SCROLLS) {
      steps++;
      scrollOnce();
      await sleep(SCROLL_PAUSE_MS);
      const snap = collectVisible();
      if (!snap.ok) return snap;
      let added = 0;
      for (const account of snap.accounts) {
        if (mergeAccount(map, account)) added++;
      }
      reportProgress(map.size, steps);
      if (added === 0) stagnant++;
      else stagnant = 0;
    }
    return {
      ok: true,
      accounts: [...map.values()],
      exhausted: stagnant >= STAGNANT_SCROLLS
    };
  }

  chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
    if (!message || !message.type) return;
    if (message.type === "ping") {
      sendResponse({ ok: true });
      return;
    }
    if (message.type === "capture") {
      sendResponse(collectVisible());
      return;
    }
    if (message.type === "capture-scroll") {
      captureAndScroll().then(sendResponse).catch((err) => {
        sendResponse({ ok: false, error: String(err && err.message ? err.message : err) });
      });
      return true;
    }
  });
})();
