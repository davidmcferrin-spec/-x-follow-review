const API = "http://localhost:8000/api.php";
const statusEl = document.getElementById("status");
const countEl = document.getElementById("count");
const visibleBtn = document.getElementById("visibleBtn");
const scrollBtn = document.getElementById("scrollBtn");

function setStatus(message) {
  statusEl.textContent = message;
}

function followingUrl(url) {
  try {
    const parsed = new URL(url);
    const host = parsed.hostname.replace(/^www\./, "");
    if (host !== "x.com" && host !== "twitter.com") return false;
    return /^\/[A-Za-z0-9_]{1,15}\/following\/?$/i.test(parsed.pathname);
  } catch (err) {
    return false;
  }
}

chrome.runtime.onMessage.addListener((message) => {
  if (!message || message.type !== "xfr-progress") return;
  countEl.textContent = String(message.count || 0);
  setStatus("Scrolling slowly. Step " + message.step + " of 40. Leave this popup open.");
});

async function send(tabId, type) {
  try {
    return await chrome.tabs.sendMessage(tabId, { type: type });
  } catch (err) {
    await chrome.scripting.executeScript({ target: { tabId: tabId }, files: ["content.js"] });
    return await chrome.tabs.sendMessage(tabId, { type: type });
  }
}

async function run(type) {
  visibleBtn.disabled = true;
  scrollBtn.disabled = true;
  try {
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    if (!tab || !tab.id || !followingUrl(tab.url || "")) {
      setStatus("This tab is not a Following page. Open https://x.com/you/following and try again.");
      return;
    }
    setStatus(type === "capture-scroll"
      ? "Scrolling the Following list slowly, up to 40 steps. Leave this popup open."
      : "Reading the accounts currently on screen.");
    let response;
    try {
      response = await send(tab.id, type);
    } catch (err) {
      setStatus("Could not read the page. Reload the Following tab and try again.");
      return;
    }
    if (!response || !response.ok) {
      setStatus((response && response.error) || "Capture failed.");
      return;
    }
    const accounts = response.accounts || [];
    countEl.textContent = String(accounts.length);
    if (!accounts.length) {
      setStatus("No accounts found in the visible rows. X changes its markup, so the capture script can miss cells. Scroll until profiles are on screen and try again.");
      return;
    }
    setStatus("Sending " + accounts.length + " accounts to localhost:8000.");
    let res;
    try {
      res = await fetch(API, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ command: "import", accounts: accounts })
      });
    } catch (err) {
      setStatus("Local server is down. From the project folder run: php -S localhost:8000");
      return;
    }
    const data = await res.json().catch(() => null);
    if (!res.ok || !data || data.error) {
      setStatus("Import rejected: " + ((data && data.error) || res.status));
      return;
    }
    setStatus("Imported. Added " + data.added + ", updated " + data.updated + ", total " + data.total + ". Review at http://localhost:8000/dashboard.html");
  } finally {
    visibleBtn.disabled = false;
    scrollBtn.disabled = false;
  }
}

visibleBtn.addEventListener("click", () => run("capture"));
scrollBtn.addEventListener("click", () => run("capture-scroll"));
