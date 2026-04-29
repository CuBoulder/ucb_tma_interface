(function($) {
  // IMPORTANT: Do not use the generic key "TMA" here.
  // The webform YAML also injects inline JS that defines Drupal.behaviors.TMA,
  // which can overwrite (or be overwritten by) this behavior depending on load order.
  Drupal.behaviors.ucbTmaReport = {
    attach: function(context, settings) {
      /*
	 Begin drupal behaviors
*/
// Only 
      /*
		 auto submit on button select
	*/
      // Keep auust the two FixIt webforms that need wizard auto-advance.
      function inFixitReportOrRequestForm(el) {
        var $el = $(el);
        return (
          $el.closest("form[id^='webform-submission-report-a-problem']").length ||
          $el.closest("form[id^='webform-submission-request-services']").length
        );
      }

      // Click the enabled "Next" button for the current wizard page.
      function clickWizardNext($scope) {
        // Webform wizard next buttons can be numbered depending on which actions
        // element is on the current page.
        var $btn = $scope
          .find(
            "#edit-actions-wizard-next, #edit-actions-01-wizard-next, #edit-actions-02-wizard-next"
          )
          .filter(":enabled")
          .first();
        if ($btn.length) {
          $btn.trigger("click");
        }
      }

      // Advance the wizard from an event target's form (async to let selection update first).
      function scheduleNextClickWithin(el) {
        var $form = $(el).closest("form");
        if (!$form.length) {
          return;
        }
        setTimeout(function() {
          clickWizardNext($form);
        }, 0);
      }

      // Use delegated events so this keeps working across Webform AJAX rebuilds.
      $(document)
        .off("click.ucbTmaAutoAdvance", "#edit-categories label, #edit-services label")
        .on("click.ucbTmaAutoAdvance", "#edit-categories label, #edit-services label", function() {
          if (!inFixitReportOrRequestForm(this)) {
            return;
          }
          var labelID = $(this).attr("for");
          if (labelID) {
            $("#" + labelID).trigger("click");
          }
          scheduleNextClickWithin(this);
        });

      $(document)
        .off("change.ucbTmaAutoAdvance", "#edit-categories input, #edit-services input")
        .on("change.ucbTmaAutoAdvance", "#edit-categories input, #edit-services input", function() {
          if (!inFixitReportOrRequestForm(this)) {
            return;
          }
          scheduleNextClickWithin(this);
        });

      /*
		 load building selector
	*/
      // Facility -> Building: populate the Building dropdown for the selected Facility.
      function handleFacilityChange(el) {
        // Cache results across the page to avoid repeated requests and keep UI snappy.
        window.__ucbTmaLocCache = window.__ucbTmaLocCache || {
          facilitiesByName: null, // { [facilityName]: facilityPk }
          buildingsByFacilityPk: {}, // { [facilityPk]: buildings[] }
          areasByFacilityName: {} // { [facilityName]: areas[] }
        };

        var $form = $(el).closest("form");
        var dropdown = $form.find("select[name=building]");
        // Cancel any previous in-flight building fetch for this form.
        try {
          var prevXhr = $form.data("ucbTmaXhrBuildings");
          if (prevXhr && prevXhr.abort) prevXhr.abort();
        } catch (e) {}

        // Generation token: callbacks must match current token to write.
        // This prevents stale/out-of-order async callbacks from duplicating options.
        var gen = ($form.data("ucbTmaGenBuildings") || 0) + 1;
        $form.data("ucbTmaGenBuildings", gen);
        dropdown.empty();
        dropdown.append(
          $("<option></option>")
            .attr("value", "")
            .text("Select a Building")
        );
        dropdown.prop("selectedIndex", 0);
        var $area = $form.find("select[name=area]");
        $area.empty();
        $area.append(
          $("<option></option>")
            .attr("value", "")
            .text("- None -")
        );
        $area.prop("selectedIndex", 0);
        // Facility dropdown stores the facility name as value (legacy webform design).
        // Fetch all buildings and filter client-side by facility connector (facility id).
        var selectedFacilityName = $(el).val();
        if (!selectedFacilityName) {
          return;
        }

        // Render-from-scratch so repeated callbacks never accumulate duplicate <option>s.
        function renderBuildings(buildings) {
          if (($form.data("ucbTmaGenBuildings") || 0) !== gen) {
            return;
          }
          dropdown.empty();
          dropdown.append(
            $("<option></option>").attr("value", "").text("Select a Building")
          );
          dropdown.prop("selectedIndex", 0);

          var seen = {};
          $.each(buildings || [], function(_, b) {
            if (!b) return;
            var pk = b.pk;
            if (pk === undefined || pk === null) return;
            var key = String(pk);
            if (seen[key]) return;
            seen[key] = true;
            dropdown.append(
              $("<option></option>").attr("value", String(pk)).text(htmlDecode(b.name))
            );
          });
        }

        // Resolve facility name -> facility pk (cached after first load).
        function ensureFacilityMap(cb) {
          if (window.__ucbTmaLocCache.facilitiesByName) {
            cb(window.__ucbTmaLocCache.facilitiesByName);
            return;
          }
          $.getJSON("/tma/location/facility", function(facilities) {
            var map = {};
            $.each(facilities || [], function(_, f) {
              if (f && f.name && f.pk) {
                map[String(f.name)] = f.pk;
              }
            });
            window.__ucbTmaLocCache.facilitiesByName = map;
            cb(map);
          }).fail(function() {
            window.__ucbTmaLocCache.facilitiesByName = {};
            cb(window.__ucbTmaLocCache.facilitiesByName);
          });
        }

        // Fetch all buildings once, then cache filtered subsets by facility pk.
        function ensureBuildingsForFacility(facilityPk, cb) {
          var key = String(facilityPk);
          if (window.__ucbTmaLocCache.buildingsByFacilityPk[key]) {
            cb(window.__ucbTmaLocCache.buildingsByFacilityPk[key]);
            return;
          }
          var xhr = $.getJSON("/tma/location/building", function(buildings) {
            var filtered = [];
            $.each(buildings || [], function(_, b) {
              if (!b) return;
              if (String(b.connector) !== String(facilityPk)) return;
              filtered.push(b);
            });
            window.__ucbTmaLocCache.buildingsByFacilityPk[key] = filtered;
            cb(filtered);
          }).fail(function() {
            window.__ucbTmaLocCache.buildingsByFacilityPk[key] = [];
            cb([]);
          });
          return xhr;
        }

        ensureFacilityMap(function(map) {
          if (($form.data("ucbTmaGenBuildings") || 0) !== gen) {
            return;
          }
          var facilityPk = map[selectedFacilityName] || null;
          if (!facilityPk) return;
          var xhr = ensureBuildingsForFacility(facilityPk, function(buildings) {
            renderBuildings(buildings);
          });
          if (xhr) {
            $form.data("ucbTmaXhrBuildings", xhr);
          }
        });
      }

      // Delegated binding so Webform AJAX rebuilds don't double-bind.
      $(document)
        .off(
          "change.ucbTmaLoc",
          "form[id^='webform-submission-report-a-problem'] select[name=facility], form[id^='webform-submission-request-services'] select[name=facility]"
        )
        .on(
          "change.ucbTmaLoc",
          "form[id^='webform-submission-report-a-problem'] select[name=facility], form[id^='webform-submission-request-services'] select[name=facility]",
          function() {
            handleFacilityChange(this);
          }
        );

      /*
		load area selector
	*/
      // Building -> Area: populate the Area dropdown for the selected Building.
      function handleBuildingChange(el) {
        var $form = $(el).closest("form");
        var dropdown = $form.find("select[name=area]");
        // Cancel any previous in-flight area fetch for this form.
        try {
          var prevXhr = $form.data("ucbTmaXhrAreas");
          if (prevXhr && prevXhr.abort) prevXhr.abort();
        } catch (e) {}

        // Generation token: callbacks must match current token to write.
        // This prevents stale/out-of-order async callbacks from duplicating options.
        var gen = ($form.data("ucbTmaGenAreas") || 0) + 1;
        $form.data("ucbTmaGenAreas", gen);
        dropdown.empty();
        dropdown.append(
          $("<option></option>")
            .attr("value", "")
            .text("Select an Area")
        );
        dropdown.prop("selectedIndex", 0);

        // Add loading icon
        $form.find("#edit-area").addClass("loading");
        if (!document.getElementById("loader-icon")) {
          $form.find(".loading").after('<p id="loader-icon">&nbsp;</p>');
        }

        // Building dropdown stores numeric building id as value.
        // Avoid putting building names in the URL (names can contain '/' which breaks routing).
        var buildingId = $(el).val();
        if (!buildingId) {
          $("#loader-icon").hide();
          return;
        }

        // Always-visible loader state (works even if theme hides #loader-icon).
        dropdown
          .append($("<option></option>").attr("value", "").text("Loading areas…"))
          .prop("selectedIndex", 1);

        // fetch only areas for the selected facility (campus chunk), then filter by building id.
        var facilityName = $form.find("select[name=facility]").val() || "";
        var cacheKey = String(facilityName);
        window.__ucbTmaLocCache = window.__ucbTmaLocCache || { areasByFacilityName: {} };

        // Append an option only if its value doesn't already exist in the <select>.
        function appendUniqueOption($select, value, text) {
          var v = String(value);
          if ($select.find("option[value='" + v.replace(/'/g, "\\'") + "']").length) {
            return;
          }
          $select.append($("<option></option>").attr("value", v).text(text));
        }

        // Render-from-scratch and de-dupe by area name for safety.
        function renderAreas(data) {
          if (($form.data("ucbTmaGenAreas") || 0) !== gen) {
            return;
          }
          // Reset options and rebuild; this also removes the "Loading…" option.
          dropdown.empty();
          dropdown.append(
            $("<option></option>").attr("value", "").text("Select an Area")
          );
          dropdown.prop("selectedIndex", 0);

          var seen = {};
          $.each(data || [], function(_, entry) {
            if (!entry) {
              return;
            }
            // builder maps connector => buildingId
            if (String(entry.connector) !== String(buildingId)) {
              return;
            }
            var rawName = String(entry.name || "");
            if (rawName === "") {
              return;
            }
            var seenKey = rawName;
            if (seen[seenKey]) {
              return;
            }
            seen[seenKey] = true;
            var label = entry.name;
            if (entry.description) {
              label = entry.name + ", " + entry.description;
            }
            appendUniqueOption(dropdown, htmlDecode(entry.name), htmlDecode(label));
          });
          $("#loader-icon").hide();
        }

        if (facilityName && window.__ucbTmaLocCache.areasByFacilityName[cacheKey]) {
          renderAreas(window.__ucbTmaLocCache.areasByFacilityName[cacheKey]);
          return;
        }

        var url = facilityName
          ? "/tma/location/area/" + encodeURIComponent(facilityName)
          : "/tma/location/area";
        var xhr = $.getJSON(url, function(data) {
          if (facilityName) {
            window.__ucbTmaLocCache.areasByFacilityName[cacheKey] = data || [];
          }
          renderAreas(data);
        }).fail(function() {
          // Clear loading state on fail
          dropdown.empty();
          dropdown.append(
            $("<option></option>").attr("value", "").text("Select an Area")
          );
          dropdown.prop("selectedIndex", 0);
          $("#loader-icon").hide();
        });
        if (xhr) {
          $form.data("ucbTmaXhrAreas", xhr);
        }
      }

      // Delegated binding so Webform AJAX rebuilds don't double-bind.
      $(document)
        .off(
          "change.ucbTmaLoc",
          "form[id^='webform-submission-report-a-problem'] select[name=building], form[id^='webform-submission-request-services'] select[name=building]"
        )
        .on(
          "change.ucbTmaLoc",
          "form[id^='webform-submission-report-a-problem'] select[name=building], form[id^='webform-submission-request-services'] select[name=building]",
          function() {
            handleBuildingChange(this);
          }
        );

      /*
		exception check
	*/
      // Escape exception text for safe HTML rendering inside the modal.
      function escapeHtml(s) {
        return String(s)
          .replace(/&/g, "&amp;")
          .replace(/</g, "&lt;")
          .replace(/>/g, "&gt;")
          .replace(/\"/g, "&quot;")
          .replace(/'/g, "&#039;");
      }

      // Fetch and cache exception messages (title -> exception_text) from /tma/task-exceptions.
      function ensureExceptionMapLoaded(cb) {
        if (window.__ucbTmaExceptionMap) {
          cb(window.__ucbTmaExceptionMap);
          return;
        }
        if (window.__ucbTmaExceptionMapLoading) {
          // Retry shortly until the first request finishes.
          setTimeout(function() {
            ensureExceptionMapLoaded(cb);
          }, 100);
          return;
        }
        window.__ucbTmaExceptionMapLoading = true;
        $.getJSON("/tma/task-exceptions", function(resp) {
          window.__ucbTmaExceptionMap = (resp && resp.exceptions) || {};
          window.__ucbTmaExceptionMapLoading = false;
          cb(window.__ucbTmaExceptionMap);
        }).fail(function() {
          window.__ucbTmaExceptionMap = {};
          window.__ucbTmaExceptionMapLoading = false;
          cb(window.__ucbTmaExceptionMap);
        });
      }

      // Get the selected task title (works for both <select> and radio inputs).
      function selectedIssueTitle(el) {
        var $el = $(el);
        if ($el.is("select")) {
          return ($el.find("option:selected").text() || "").trim();
        }
        // radio/checkbox fallback
        var id = $el.attr("id");
        if (id) {
          var $label = $("label[for='" + id + "']");
          if ($label.length) return ($label.text() || "").trim();
        }
        return ($el.val() || "").toString().trim();
      }

      $(
        "select[name=task_select], input[name=task_select], select[name=what_type_of_issue_would_you_like_to_report_], input[name=what_type_of_issue_would_you_like_to_report_]",
        context
      )
        .off("change.ucbTmaException")
        .on("change.ucbTmaException", function() {
          var title = selectedIssueTitle(this);
          if (!title) {
            closeExceptionModal();
            return;
          }

          ensureExceptionMapLoaded(function(map) {
            var msg = map[title];
            if (msg) {
              ensureExceptionModal();
              openExceptionModalHtml(
                "<div class='exception-content'><h2>We're Sorry</h2>" +
                  escapeHtml(msg) +
                  "</div>" +
                  "<div class='button-wrap'>" +
                  "<a href='/' class='button' name='return_home'>Return Home</a>" +
                  "<a class='button' name='okay'>OK</a>" +
                  "</div>"
              );
          $("#edit-input-information-related-to-the-issue").attr(
            "disabled",
            "disabled"
          );
          $("#edit-actions-wizard-next").attr("disabled", "disabled");
          $("#edit-actions-01-wizard-next").attr("disabled", "disabled");
            } else {
              closeExceptionModal();
              $("#edit-input-information-related-to-the-issue").removeAttr(
                "disabled"
              );
              $("#edit-actions-wizard-next").removeAttr("disabled");
              $("#edit-actions-01-wizard-next").removeAttr("disabled");
            }
          });
        });

      /*
		click the okay button
	*/
      $("a[name='okay']", context).click(function() {
        closeExceptionModal();
        $(
          "select[name=task_select], input[name=task_select], select[name=what_type_of_issue_would_you_like_to_report_], input[name=what_type_of_issue_would_you_like_to_report_]"
        ).prop("checked", false);
      });

      // The modal content is cloned into <body>, outside the webform context, so
      // bind a document-level handler for the OK button too.
      $(document)
        .off("click.ucbTmaExceptionOkay")
        .on("click.ucbTmaExceptionOkay", "#ucb-tma-exception-modal a[name='okay']", function(e) {
          e.preventDefault();
          closeExceptionModal();
          $(
            "select[name=task_select], input[name=task_select], select[name=what_type_of_issue_would_you_like_to_report_], input[name=what_type_of_issue_would_you_like_to_report_]"
          ).prop("checked", false);
        });

      /*
	 end drupal behaviors
*/
    }
  };
})(jQuery);

// Decode entity-escaped strings coming from legacy-shaped JSON feeds.
function htmlDecode(input) {
  var e = document.createElement("div");
  e.innerHTML = input;
  return e.childNodes.length === 0 ? "" : e.childNodes[0].nodeValue;
}

// Create the modal DOM once (overlay + modal container) and wire close handlers.
function ensureExceptionModal() {
  if (document.getElementById("ucb-tma-exception-overlay")) {
    return;
  }
  var overlay = document.createElement("div");
  overlay.id = "ucb-tma-exception-overlay";
  overlay.className = "ucb-tma-exception-modal-overlay";

  var modal = document.createElement("div");
  modal.id = "ucb-tma-exception-modal";
  modal.className = "ucb-tma-exception-modal";

  overlay.addEventListener("click", function() {
    closeExceptionModal();
  });

  document.body.appendChild(overlay);
  document.body.appendChild(modal);

  // ESC key closes the modal.
  document.addEventListener("keydown", function(e) {
    var key = e.key || e.keyCode;
    if (key === "Escape" || key === "Esc" || key === 27) {
      closeExceptionModal();
    }
  });
}

// Legacy helper: open modal content from an existing hidden DOM node.
function openExceptionModal(exceptionId) {
  var overlay = document.getElementById("ucb-tma-exception-overlay");
  var modal = document.getElementById("ucb-tma-exception-modal");
  if (!overlay || !modal) {
    return;
  }

  var source = document.getElementById("exception_" + exceptionId);
  if (!source) {
    return;
  }
  // Clone the existing markup so button handlers still work.
  modal.innerHTML = source.innerHTML;

  overlay.style.display = "block";
  modal.style.display = "block";
}

// Preferred helper: open modal from an HTML string (used by /tma/task-exceptions mapping).
function openExceptionModalHtml(html) {
  var overlay = document.getElementById("ucb-tma-exception-overlay");
  var modal = document.getElementById("ucb-tma-exception-modal");
  if (!overlay || !modal) {
    return;
  }
  modal.innerHTML = html;
  overlay.style.display = "block";
  modal.style.display = "block";
}

// Close and clear the modal contents.
function closeExceptionModal() {
  var overlay = document.getElementById("ucb-tma-exception-overlay");
  var modal = document.getElementById("ucb-tma-exception-modal");
  if (overlay) overlay.style.display = "none";
  if (modal) {
    modal.style.display = "none";
    modal.innerHTML = "";
  }
}
