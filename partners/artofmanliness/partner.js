document.addEventListener("DOMContentLoaded", function () {
  // --- Configuration ---
  const publicationId = "artofmanliness";
  const contentSelector = ".post-content-column";

  // Set to true to enable detailed console logs for debugging.
  const debuggerEnabled = false;
  // --- End Configuration ---

  const logStyle = "color: #0073aa; font-weight: bold;";

  // Helper function for conditional logging
  const log = (message, style = "", ...args) => {
    if (debuggerEnabled) {
      if (style) console.log(message, style, ...args);
      else console.log(message, ...args);
    }
  };
  const warn = (message) => {
    if (debuggerEnabled) console.warn(message);
  };

  log(
    `%c[Instaread Player] Initializing for publication: ${publicationId}`,
    logStyle
  );

  const mainContent = document.querySelector(contentSelector);

  if (!mainContent) {
    console.error(
      `[Instaread Player] CRITICAL: Main content container ('${contentSelector}') not found. Script will not run.`
    );
    return;
  }
  log("[Instaread Player] SUCCESS: Found main content container:", mainContent);

  const createPlayerElements = () => {
    const wrapper = document.createElement("div");
    wrapper.className = "playerContainer instaread-content-wrapper";
    wrapper.innerHTML = `
            <instaread-player publication="${publicationId}" class="instaread-player">
              <div class="instaread-audio-player" style="box-sizing:border-box;margin:0">
                <iframe id="instaread_iframe" width="100%" height="100%" scrolling="no" frameborder="0" loading="lazy" title="Audio Article" style="display:block" data-pin-nopin="true"></iframe>
              </div>
            </instaread-player>`;
    const script = document.createElement("script");
    script.src = `https://player.instaread.co/js/instaread.${publicationId}.js?version=${Date.now()}`;
    script.type = "module";
    return { wrapper, script };
  };

  const { wrapper, script } = createPlayerElements();

  const firstElement = mainContent.firstElementChild;
  let targetImageElement = null;

  if (firstElement) {
    log("[Instaread Player] Checking first element in content:", firstElement);
    if (firstElement.tagName === "P" && firstElement.querySelector("img")) {
      log(
        "[Instaread Player] LOGIC: First element is a paragraph containing a featured image."
      );
      targetImageElement = firstElement;
    }
  }

  if (targetImageElement) {
    log(
      "%c[Instaread Player] Injecting AFTER the featured image element.",
      logStyle
    );
    targetImageElement.after(wrapper, script);
    console.log("[Instaread Player] Injection complete.");
  } else if (firstElement && firstElement.tagName === "P" && firstElement.textContent.trim() !== "") {
    // Well-formed post: first child is a real text <p>. Inject before it.
    log(
      "%c[Instaread Player] SUCCESS: First element is a text paragraph. Injecting BEFORE it.",
      logStyle,
      firstElement
    );
    firstElement.before(wrapper, script);
    console.log("[Instaread Player] Injection complete.");
  } else if (firstElement && firstElement.tagName === "BR") {
    // Malformed lead variant: post starts with a stray <br/> followed by bare text
    // (no opening <p>). Inject right above the <br/> so the player sits above the
    // first line of article copy instead of being prepended above all wrappers.
    log(
      "%c[Instaread Player] LOGIC: First element is <br>. Injecting BEFORE the <br>.",
      logStyle,
      firstElement
    );
    firstElement.before(wrapper, script);
    console.log("[Instaread Player] Injection complete.");
  } else {
    // Malformed lead (no <p>, no <br>). Prepend to the content container so the
    // player sits above all article content.
    log(
      "[Instaread Player] LOGIC: No leading text paragraph. Prepending player to top of content container."
    );
    mainContent.prepend(wrapper, script);
    console.log("[Instaread Player] Injection complete.");
  }
});
