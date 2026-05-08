(function ($) {
  // FixIt location cascade: facility -> building -> area.
  // Uses Platform-backed /tma/location/* JSON routes and caches results in-memory.
  Drupal.behaviors.ucbTmaFixitLocationCascade = {
    attach: function () {
      function initCache() {
        window.__ucbTmaLocCache = window.__ucbTmaLocCache || {
          facilitiesByName: null, // { [facilityName]: facilityPk }
          buildingsByFacilityPk: {}, // { [facilityPk]: buildings[] }
          areasByFacilityName: {}, // { [facilityName]: areas[] }
        };
        return window.__ucbTmaLocCache;
      }

      // Decode entity-escaped strings coming from legacy-shaped JSON feeds.
      function htmlDecode(input) {
        var e = document.createElement("div");
        e.innerHTML = input;
        return e.childNodes.length === 0 ? "" : e.childNodes[0].nodeValue;
      }

      function ensureFacilityMap(cb) {
        var cache = initCache();
        if (cache.facilitiesByName) {
          cb(cache.facilitiesByName);
          return;
        }
        $.getJSON("/tma/location/facility", function (facilities) {
          var map = {};
          $.each(facilities || [], function (_, f) {
            if (f && f.name && f.pk) map[String(f.name)] = f.pk;
          });
          cache.facilitiesByName = map;
          cb(map);
        }).fail(function () {
          cache.facilitiesByName = {};
          cb(cache.facilitiesByName);
        });
      }

      function ensureBuildingsForFacility(facilityPk, cb) {
        var cache = initCache();
        var key = String(facilityPk);
        if (cache.buildingsByFacilityPk[key]) {
          cb(cache.buildingsByFacilityPk[key]);
          return null;
        }
        return $.getJSON("/tma/location/building", function (buildings) {
          var filtered = [];
          $.each(buildings || [], function (_, b) {
            if (!b) return;
            if (String(b.connector) !== String(facilityPk)) return;
            filtered.push(b);
          });
          cache.buildingsByFacilityPk[key] = filtered;
          cb(filtered);
        }).fail(function () {
          cache.buildingsByFacilityPk[key] = [];
          cb([]);
        });
      }

      function renderBuildings($form, gen, buildings) {
        if (($form.data("ucbTmaGenBuildings") || 0) !== gen) return;
        var $building = $form.find("select[name=building]");
        $building.empty();
        $building.append($("<option></option>").attr("value", "").text("Select a Building"));
        $building.prop("selectedIndex", 0);

        var seen = {};
        $.each(buildings || [], function (_, b) {
          if (!b) return;
          var pk = b.pk;
          if (pk === undefined || pk === null) return;
          var key = String(pk);
          if (seen[key]) return;
          seen[key] = true;
          var name = htmlDecode(b.name);
          $building.append(
            $("<option></option>")
              // Store the label as the submitted value
              .attr("value", String(name))
              // Keep the numeric PK for area filtering
              .attr("data-ucb-tma-pk", String(pk))
              .text(name)
          );
        });
      }

      function renderAreas($form, gen, buildingId, areas) {
        if (($form.data("ucbTmaGenAreas") || 0) !== gen) return;
        var $area = $form.find("select[name=area]");
        $area.empty();
        $area.append($("<option></option>").attr("value", "").text("Select an Area"));
        $area.prop("selectedIndex", 0);

        var seen = {};
        $.each(areas || [], function (_, entry) {
          if (!entry) return;
          if (String(entry.connector) !== String(buildingId)) return;
          var rawName = String(entry.name || "");
          if (!rawName) return;
          if (seen[rawName]) return;
          seen[rawName] = true;
          var label = entry.description ? entry.name + ", " + entry.description : entry.name;
          $area.append(
            $("<option></option>")
              .attr("value", htmlDecode(entry.name))
              .text(htmlDecode(label))
          );
        });
      }

      function handleFacilityChange(el) {
        initCache();
        var $form = $(el).closest("form");
        var selectedFacilityName = $(el).val();

        // Cancel in-flight building fetch for this form.
        try {
          var prevXhr = $form.data("ucbTmaXhrBuildings");
          if (prevXhr && prevXhr.abort) prevXhr.abort();
        } catch (e) {}

        // Increment generation so stale callbacks can't write.
        var gen = ($form.data("ucbTmaGenBuildings") || 0) + 1;
        $form.data("ucbTmaGenBuildings", gen);

        // Reset dependent dropdowns immediately.
        renderBuildings($form, gen, []);
        var $area = $form.find("select[name=area]");
        $area.empty();
        $area.append($("<option></option>").attr("value", "").text("- None -"));
        $area.prop("selectedIndex", 0);

        if (!selectedFacilityName) return;

        ensureFacilityMap(function (map) {
          if (($form.data("ucbTmaGenBuildings") || 0) !== gen) return;
          var facilityPk = map[selectedFacilityName] || null;
          if (!facilityPk) return;
          var xhr = ensureBuildingsForFacility(facilityPk, function (buildings) {
            renderBuildings($form, gen, buildings);
          });
          if (xhr) $form.data("ucbTmaXhrBuildings", xhr);
        });
      }

      function handleBuildingChange(el) {
        initCache();
        var $form = $(el).closest("form");
        // The select value is the building name; use the data attribute for numeric PK
        var $sel = $(el);
        var buildingId = $sel.find("option:selected").attr("data-ucb-tma-pk") || "";
        var facilityName = $form.find("select[name=facility]").val() || "";
        var cache = window.__ucbTmaLocCache;
        var cacheKey = String(facilityName);

        // Cancel in-flight area fetch for this form.
        try {
          var prevXhr = $form.data("ucbTmaXhrAreas");
          if (prevXhr && prevXhr.abort) prevXhr.abort();
        } catch (e) {}

        var gen = ($form.data("ucbTmaGenAreas") || 0) + 1;
        $form.data("ucbTmaGenAreas", gen);

        var $area = $form.find("select[name=area]");
        $area.empty();
        $area.append($("<option></option>").attr("value", "").text("Select an Area"));
        $area.prop("selectedIndex", 0);

        if (!buildingId) return;

        // Always-visible loader (works even if spinner CSS is missing).
        $area.append($("<option></option>").attr("value", "").text("Loading areas…"));
        $area.prop("selectedIndex", 1);

        if (facilityName && cache.areasByFacilityName[cacheKey]) {
          renderAreas($form, gen, buildingId, cache.areasByFacilityName[cacheKey]);
          return;
        }

        var url = facilityName
          ? "/tma/location/area/" + encodeURIComponent(facilityName)
          : "/tma/location/area";
        var xhr = $.getJSON(url, function (data) {
          if (facilityName) cache.areasByFacilityName[cacheKey] = data || [];
          renderAreas($form, gen, buildingId, data);
        }).fail(function () {
          if (($form.data("ucbTmaGenAreas") || 0) !== gen) return;
          $area.empty();
          $area.append($("<option></option>").attr("value", "").text("Select an Area"));
          $area.prop("selectedIndex", 0);
        });
        $form.data("ucbTmaXhrAreas", xhr);
      }

      // Delegated binding prevents double-binding across Webform AJAX rebuilds.
      var facilitySel =
        "form[id^='webform-submission-report-a-problem'] select[name=facility], form[id^='webform-submission-request-services'] select[name=facility]";
      var buildingSel =
        "form[id^='webform-submission-report-a-problem'] select[name=building], form[id^='webform-submission-request-services'] select[name=building]";

      $(document)
        .off("change.ucbTmaLocCascade", facilitySel)
        .on("change.ucbTmaLocCascade", facilitySel, function () {
          handleFacilityChange(this);
        });

      $(document)
        .off("change.ucbTmaLocCascade", buildingSel)
        .on("change.ucbTmaLocCascade", buildingSel, function () {
          handleBuildingChange(this);
        });
    },
  };
})(jQuery);

