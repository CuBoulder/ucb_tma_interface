(function($) {
  Drupal.behaviors.TMA = {
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
        // get facility ID
        var url = "/rest/facility/" + this.value;
        $.getJSON(url, function(data) {
          $.each(data, function(key, entry) {
            url = "/rest/buildings/" + entry.field_tma_facility_id;
            // Populate dropdown with list of buildings in facility
            $.getJSON(url, function(data) {
              $.each(data, function(key, entry) {
                dropdown.append(
                  $("<option></option>")
                    .attr("value", htmlDecode(entry.name))
                    .text(htmlDecode(entry.name))
                );
              });
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

        // get building ID
        var url = "/rest/building/" + this.value;
        $.getJSON(url, function(data) {
          $.each(data, function(key, entry) {
            url = "/rest/areas/" + entry.field_tma_building_id_;
            // Populate dropdown with list of areas in building
            $.getJSON(url, function(data) {
              $.each(data, function(key, entry) {
                dropdown.append(
                  $("<option></option>")
                    .attr("value", htmlDecode(entry.name))
                    .text(
                      htmlDecode(
                        entry.name + ", " + entry.field_tma_description
                      )
                    )
                );
              });
              // Hide Loading icon
              $("#loader-icon").hide();
            });
          });
        });
      });

      /*
		exception check
	*/
      $(
        "input[name=task_select], input[name=what_type_of_issue_would_you_like_to_report_]",
        context
      ).change(function() {
        var aExceptions = $("#aException")
          .html()
          .split(",");
        if (aExceptions.indexOf(this.value) != -1) {
          $.colorbox({
            width: "600px",
            height: "400px",
            inline: true,
            title: " ",
            href: "#exception_" + this.value
          });
          $("#edit-input-information-related-to-the-issue").attr(
            "disabled",
            "disabled"
          );
          $("#edit-actions-wizard-next").attr("disabled", "disabled");
          $("#edit-actions-01-wizard-next").attr("disabled", "disabled");
        } else {
          $("#edit-input-information-related-to-the-issue").removeAttr(
            "disabled"
          );
          $("#edit-actions-wizard-next").removeAttr("disabled");
          $("#edit-actions-01-wizard-next").removeAttr("disabled");
        }
      });

      /*
		click the okay button
	*/
      $("a[name='okay']", context).click(function() {
        $.colorbox.close();
        $(
          "input[name=task_select], input[name=what_type_of_issue_would_you_like_to_report_]"
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
