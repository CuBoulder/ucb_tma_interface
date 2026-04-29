(function ($) {
  // FixIt wizard UX helpers: auto-advance after selecting category/service.
  Drupal.behaviors.ucbTmaFixitWizardUx = {
    attach: function (context) {
      function inFixitReportOrRequestForm(el) {
        var $el = $(el);
        return (
          $el.closest("form[id^='webform-submission-report-a-problem']").length ||
          $el.closest("form[id^='webform-submission-request-services']").length
        );
      }

      function clickWizardNext($scope) {
        // Webform wizard next buttons can be numbered depending on which actions element is present.
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
})(jQuery);

