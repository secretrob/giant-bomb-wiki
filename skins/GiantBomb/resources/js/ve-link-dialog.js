/**
 * Injects prefix buttons into the VE link inspector + shortens the internal tab label.
 * VE counterpart of wikieditor-link-dialog.js.
 */
(function () {
  var actions = ["edit", "formedit"];
  if (actions.indexOf(mw.config.get("wgAction")) === -1) return;

  var config = [
    { label: "Accessory", prefix: "Accessories/" },
    { label: "Character", prefix: "Characters/" },
    { label: "Company", prefix: "Companies/" },
    { label: "Concept", prefix: "Concepts/" },
    { label: "Franchise", prefix: "Franchises/" },
    { label: "Game", prefix: "Games/" },
    { label: "Genre", prefix: "Genres/" },
    { label: "Location", prefix: "Locations/" },
    { label: "Person", prefix: "People/" },
    { label: "Platform", prefix: "Platforms/" },
    { label: "Object", prefix: "Objects/" },
    { label: "Theme", prefix: "Themes/" },
  ];

  var allPrefixes = config
    .map(function (item) {
      return item.prefix.replace(/[-\/\\^$*+?.()|[\]{}]/g, "\\$&");
    })
    .join("|");

  function makeButton(btn, input) {
    var el = document.createElement("button");
    el.type = "button";
    el.textContent = "+ " + btn.label;
    el.className = "gb-link-prefix-btn";
    el.addEventListener("click", function (e) {
      e.preventDefault();

      // remove prefix if it exists
      var prefixRegex = new RegExp("^(" + allPrefixes + ")", "i");
      var baseTitle = input.value.replace(prefixRegex, "");

      // replace spaces and punctuation with underscore
      var sanitized = baseTitle.replace(
        /[\s!\"#$%&'()*+,\-./:;<=>?@\[\\\]^`{|}~]+/g,
        "_",
      );
      sanitized = sanitized.replace(/^_+/, "");
      input.value = btn.prefix + sanitized;

      // native event -> ooui TitleInputWidget picks it up and re-queries
      input.dispatchEvent(new Event("input", { bubbles: true }));
      input.focus();
    });
    return el;
  }

  function enhance(inspector) {
    // "Giant Bomb Video Game Wiki" tab label is hardcoded to wgSiteName in VE -> shorten.
    // idempotent: once renamed it no longer matches
    var tabs = inspector.querySelectorAll(
      ".oo-ui-tabOptionWidget .oo-ui-labelElement-label",
    );
    for (var i = 0; i < tabs.length; i++) {
      if (tabs[i].textContent === mw.config.get("wgSiteName")) {
        tabs[i].textContent = "Wiki";
      }
    }

    // internal tab panel is first; its LinkAnnotationWidget holds the title search input.
    // inspector contents build lazily -> leave undone and retry on the next mutation
    var widget = inspector.querySelector(
      ".oo-ui-tabPanelLayout .ve-ui-linkAnnotationWidget",
    );
    if (!widget) return;
    var input = widget.querySelector("input");
    var panel = widget.closest(".oo-ui-tabPanelLayout");
    if (!input || !panel) return;

    inspector.classList.add("gb-link-dialog-done");

    var controls = document.createElement("div");
    controls.className = "gb-link-prefix-controls";
    config.forEach(function (btn) {
      controls.appendChild(makeButton(btn, input));
    });
    panel.insertBefore(controls, panel.firstChild);
  }

  // structural subpages are never prose link targets -> hide from results
  function filterResults() {
    var opts = document.querySelectorAll(
      ".mw-widget-titleOptionWidget:not(.gb-link-filtered)",
    );
    for (var i = 0; i < opts.length; i++) {
      opts[i].classList.add("gb-link-filtered");
      var label = opts[i].textContent.trim();
      if (/\/(Images|Reviews)$/.test(label)) {
        opts[i].style.display = "none";
      }
    }
  }

  // inspectors mount lazily in a ve overlay; watch for them
  var observer = new MutationObserver(function () {
    var nodes = document.querySelectorAll(
      ".ve-ui-mwLinkAnnotationInspector:not(.gb-link-dialog-done)",
    );
    for (var i = 0; i < nodes.length; i++) enhance(nodes[i]);
    filterResults();
  });
  observer.observe(document.body, { childList: true, subtree: true });
})();
