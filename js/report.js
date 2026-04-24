(function($) {
  // IMPORTANT: Do not use the generic key "TMA" here.
  // The webform YAML also injects inline JS that defines Drupal.behaviors.TMA,
  // which can overwrite (or be overwritten by) this behavior depending on load order.
  Drupal.behaviors.ucbTmaReport = {
    attach: function(context, settings) {
      /*
	 Begin drupal behaviors
*/

      /*
		 auto submit on button select
	*/
      $("#edit-categories label, #edit-services label", context).on(
        "mouseup",
        function() {
          labelID = $(this).attr("for");
          $("#" + labelID).trigger("click");
          $("#edit-actions-wizard-next").trigger("click");
        }
      );

      /*
		 load building selector
	*/
      $("select[name=facility]", context).change(function() {
        var dropdown = $("select[name=building]");
        dropdown.empty();
        dropdown.append(
          $("<option></option>")
            .attr("value", "")
            .text("Select a Building")
        );
        dropdown.prop("selectedIndex", 0);
        $("select[name=area]").empty();
        $("select[name=area]").append(
          $("<option></option>")
            .attr("value", "")
            .text("- None -")
        );
        $("select[name=area]").prop("selectedIndex", 0);
        // Facility dropdown stores the facility name as value (legacy webform design).
        // Fetch all buildings and filter client-side by facility connector (facility id).
        var selectedFacilityName = this.value;
        if (!selectedFacilityName) {
          return;
        }

        var facilityNameUrl = "/tma/location/facility";
        $.getJSON(facilityNameUrl, function(facilities) {
          var facilityId = null;
          $.each(facilities, function(_, f) {
            if (f && f.name === selectedFacilityName) {
              facilityId = f.pk;
              return false;
            }
          });
          if (!facilityId) {
            return;
          }

          var buildingsUrl = "/tma/location/building";
          $.getJSON(buildingsUrl, function(buildings) {
            $.each(buildings, function(_, b) {
              if (!b) {
                return;
              }
              // builder maps connector => facilityId
              if (String(b.connector) !== String(facilityId)) {
                return;
              }
              dropdown.append(
                $("<option></option>")
                  .attr("value", b.pk)
                  .text(htmlDecode(b.name))
              );
            });
          });
        });
      });

      /*
		load area selector
	*/
      $("select[name=building]", context).change(function() {
        var dropdown = $("select[name=area]");
        dropdown.empty();
        dropdown.append(
          $("<option></option>")
            .attr("value", "")
            .text("Select an Area")
        );
        dropdown.prop("selectedIndex", 0);

        // Add loading icon
        $("#edit-area").addClass("loading");
        $(".loading").after('<p id="loader-icon">&nbsp;</p>');

        // Building dropdown stores numeric building id as value.
        // Avoid putting building names in the URL (names can contain '/' which breaks routing).
        var buildingId = this.value;
        if (!buildingId) {
          $("#loader-icon").hide();
          return;
        }

        // Areas endpoint is keyed by facility name, so we fetch all areas and filter by building connector.
        var url = "/tma/location/area";
        $.getJSON(url, function(data) {
          $.each(data, function(_, entry) {
            if (!entry) {
              return;
            }
            // builder maps connector => buildingId
            if (String(entry.connector) !== String(buildingId)) {
              return;
            }
            var label = entry.name;
            if (entry.description) {
              label = entry.name + ", " + entry.description;
            }
            dropdown.append(
              $("<option></option>")
                .attr("value", htmlDecode(entry.name))
                .text(htmlDecode(label))
            );
          });
          // Hide Loading icon
          $("#loader-icon").hide();
        });
      });

      /*
		exception check
	*/
      function escapeHtml(s) {
        return String(s)
          .replace(/&/g, "&amp;")
          .replace(/</g, "&lt;")
          .replace(/>/g, "&gt;")
          .replace(/\"/g, "&quot;")
          .replace(/'/g, "&#039;");
      }

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

function htmlDecode(input) {
  var e = document.createElement("div");
  e.innerHTML = input;
  return e.childNodes.length === 0 ? "" : e.childNodes[0].nodeValue;
}

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

function closeExceptionModal() {
  var overlay = document.getElementById("ucb-tma-exception-overlay");
  var modal = document.getElementById("ucb-tma-exception-modal");
  if (overlay) overlay.style.display = "none";
  if (modal) {
    modal.style.display = "none";
    modal.innerHTML = "";
  }
}
