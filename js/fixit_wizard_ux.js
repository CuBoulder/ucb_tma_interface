(function ($, Drupal, once) {
  // FixIt wizard UX helpers: auto-advance after selecting category/service.
  Drupal.behaviors.ucbTmaFixitWizardUx = {
    attach: function (context) {
      var fixitFormSelector =
        "form[id^='webform-submission-report-a-problem'], form[id^='webform-submission-request-services']";
      // Show root preview actions only on the preview step (Drupal 10+ core `once`, not jQuery.fn.once).
      $(once("ucbTmaPreviewButtons", fixitFormSelector, context)).each(function () {
        var $form = $(this);
        var isPreview = $form.find(".webform-preview").length > 0;
        $form.find(".preview-button").toggle(isPreview);
      });

      function inFixitReportOrRequestForm(el) {
        var $el = $(el);
        return (
          $el.closest("form[id^='webform-submission-report-a-problem']").length ||
          $el.closest("form[id^='webform-submission-request-services']").length
        );
      }

      function clickWizardNext($scope) {
        // Prefer Webform/DOM hints that survive nested webform_actions IDs (actions, actions_01, …).
        // Use :submit (covers both <input type="submit"> and <button type="submit">); avoid :visible,
        // which can miss valid wizard controls depending on layout/CSS.
        var $btn = $scope
          .find(
            ":submit.webform-button--next, :submit.webform-button--preview, button.webform-button--next, button.webform-button--preview"
          )
          .filter(":enabled")
          .first();
        if (!$btn.length) {
          $btn = $scope
            .find(
              '[data-drupal-selector$="-wizard-next"], [data-drupal-selector$="-preview-next"]'
            )
            .filter(":enabled")
            .first();
        }
        if (!$btn.length) {
          $btn = $scope
            .find(
              "#edit-actions-wizard-next, #edit-actions-01-wizard-next, #edit-actions-02-wizard-next, #edit-actions-03-wizard-next"
            )
            .filter(":enabled")
            .first();
        }
        if ($btn.length) {
          var el = $btn.get(0);
          if (el && typeof el.click === "function") {
            el.click();
          } else {
            $btn.trigger("click");
          }
        }
      }

      function scheduleNextClickWithin(el) {
        // Let the radio selection settle before advancing.
        var $form = $(el).closest("form");
        if (!$form.length) return;
        setTimeout(function () {
          clickWizardNext($form);
        }, 0);
      }

      // Delegated events keep working across Webform AJAX rebuilds.
      $(document)
        .off(
          "click.ucbTmaAutoAdvance",
          "form[id^='webform-submission-report-a-problem'] #edit-categories label, form[id^='webform-submission-report-a-problem'] #edit-services label, form[id^='webform-submission-request-services'] #edit-categories label, form[id^='webform-submission-request-services'] #edit-services label"
        )
        .on(
          "click.ucbTmaAutoAdvance",
          "form[id^='webform-submission-report-a-problem'] #edit-categories label, form[id^='webform-submission-report-a-problem'] #edit-services label, form[id^='webform-submission-request-services'] #edit-categories label, form[id^='webform-submission-request-services'] #edit-services label",
          function () {
            if (!inFixitReportOrRequestForm(this)) return;
            var labelID = $(this).attr("for");
            if (labelID) $("#" + labelID).trigger("click");
            scheduleNextClickWithin(this);
          }
        );

      $(document)
        .off(
          "change.ucbTmaAutoAdvance",
          "form[id^='webform-submission-report-a-problem'] #edit-categories input, form[id^='webform-submission-report-a-problem'] #edit-services input, form[id^='webform-submission-request-services'] #edit-categories input, form[id^='webform-submission-request-services'] #edit-services input"
        )
        .on(
          "change.ucbTmaAutoAdvance",
          "form[id^='webform-submission-report-a-problem'] #edit-categories input, form[id^='webform-submission-report-a-problem'] #edit-services input, form[id^='webform-submission-request-services'] #edit-categories input, form[id^='webform-submission-request-services'] #edit-services input",
          function () {
            if (!inFixitReportOrRequestForm(this)) return;
            scheduleNextClickWithin(this);
          }
        );
    },
  };
})(jQuery, Drupal, once);
