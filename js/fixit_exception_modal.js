(function ($) {
  // FixIt task exception modal:
  // - Fetches exception messages from /tma/task-exceptions (title -> exception_text)
  // - Shows a lightweight modal without contrib dependencies (no Colorbox)
  Drupal.behaviors.ucbTmaFixitExceptionModal = {
    attach: function (context) {
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
          setTimeout(function () {
            ensureExceptionMapLoaded(cb);
          }, 100);
          return;
        }
        window.__ucbTmaExceptionMapLoading = true;
        $.getJSON("/tma/task-exceptions", function (resp) {
          window.__ucbTmaExceptionMap = (resp && resp.exceptions) || {};
          window.__ucbTmaExceptionMapLoading = false;
          cb(window.__ucbTmaExceptionMap);
        }).fail(function () {
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
        var id = $el.attr("id");
        if (id) {
          var $label = $("label[for='" + id + "']");
          if ($label.length) return ($label.text() || "").trim();
        }
        return ($el.val() || "").toString().trim();
      }

      function ensureExceptionModal() {
        if (document.getElementById("ucb-tma-exception-overlay")) return;

        var overlay = document.createElement("div");
        overlay.id = "ucb-tma-exception-overlay";
        overlay.className = "ucb-tma-exception-modal-overlay";

        var modal = document.createElement("div");
        modal.id = "ucb-tma-exception-modal";
        modal.className = "ucb-tma-exception-modal";

        overlay.addEventListener("click", function () {
          closeExceptionModal();
        });

        document.body.appendChild(overlay);
        document.body.appendChild(modal);

        // ESC key closes the modal.
        document.addEventListener("keydown", function (e) {
          var key = e.key || e.keyCode;
          if (key === "Escape" || key === "Esc" || key === 27) {
            closeExceptionModal();
          }
        });
      }

      function openExceptionModalHtml(html) {
        var overlay = document.getElementById("ucb-tma-exception-overlay");
        var modal = document.getElementById("ucb-tma-exception-modal");
        if (!overlay || !modal) return;
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

      function disableNext(disabled) {
        var action = disabled ? "attr" : "removeAttr";
        $("#edit-input-information-related-to-the-issue")[action]("disabled", "disabled");
        $("#edit-actions-wizard-next")[action]("disabled", "disabled");
        $("#edit-actions-01-wizard-next")[action]("disabled", "disabled");
      }

      $(
        "select[name=task_select], input[name=task_select], select[name=what_type_of_issue_would_you_like_to_report_], input[name=what_type_of_issue_would_you_like_to_report_]",
        context
      )
        .off("change.ucbTmaException")
        .on("change.ucbTmaException", function () {
          var title = selectedIssueTitle(this);
          if (!title) {
            closeExceptionModal();
            return;
          }

          ensureExceptionMapLoaded(function (map) {
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
              disableNext(true);
            } else {
              closeExceptionModal();
              disableNext(false);
            }
          });
        });

      // OK button inside webform context (rare) + document-level handler (modal is appended to body).
      $("a[name='okay']", context).off("click.ucbTmaExceptionOkay").on("click.ucbTmaExceptionOkay", function (e) {
        e.preventDefault();
        closeExceptionModal();
        $(
          "select[name=task_select], input[name=task_select], select[name=what_type_of_issue_would_you_like_to_report_], input[name=what_type_of_issue_would_you_like_to_report_]"
        ).prop("checked", false);
      });

      $(document)
        .off("click.ucbTmaExceptionOkay", "#ucb-tma-exception-modal a[name='okay']")
        .on("click.ucbTmaExceptionOkay", "#ucb-tma-exception-modal a[name='okay']", function (e) {
          e.preventDefault();
          closeExceptionModal();
          $(
            "select[name=task_select], input[name=task_select], select[name=what_type_of_issue_would_you_like_to_report_], input[name=what_type_of_issue_would_you_like_to_report_]"
          ).prop("checked", false);
        });
    },
  };
})(jQuery);

