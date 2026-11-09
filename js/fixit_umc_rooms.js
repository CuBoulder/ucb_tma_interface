(function (Drupal, once) {
  // UMC specific form. Rooms come from /tma/location/area, limited to building 3740194 (union id)
  // Submit posts to /tma/umc-request and shows the returned request number
  var UMC_BUILDING_ID = "3740194";

  function htmlDecode(input) {
    var e = document.createElement("div");
    e.innerHTML = input;
    return e.childNodes.length === 0 ? "" : e.childNodes[0].nodeValue;
  }

  function option(value, text) {
    var el = document.createElement("option");
    el.value = value;
    el.textContent = text;
    return el;
  }

  function renderRooms(room, areas, selected) {
    room.replaceChildren();

    var seen = {};
    var rows = [];
    (areas || []).forEach(function (entry) {
      if (!entry || String(entry.connector) !== UMC_BUILDING_ID) {
        return;
      }
      var rawName = String(entry.name || "");
      if (!rawName || seen[rawName]) {
        return;
      }
      seen[rawName] = true;
      if (entry.pk === undefined || entry.pk === null || String(entry.pk) === "") {
        return;
      }
      var label = entry.description ? entry.name + ", " + entry.description : entry.name;
      rows.push({ value: String(entry.pk), text: htmlDecode(label) });
    });
    rows.sort(function (a, b) {
      return a.text.localeCompare(b.text, undefined, { sensitivity: "base" });
    });
    if (!rows.length) {
      room.appendChild(option("", "Choose"));
      room.selectedIndex = 0;
      return;
    }
    rows.forEach(function (row) {
      room.appendChild(option(row.value, row.text));
    });
    var keep = selected && room.querySelector('option[value="' + selected + '"]');
    room.value = keep ? selected : rows[0].value;
  }

  function fieldValue(form, name) {
    var el = form.querySelector('[name="' + name + '"]');
    return el && el.value ? String(el.value).trim() : "";
  }

  function showMessage(form, text) {
    var note = form.querySelector(".ucb-tma-umc-result");
    if (!note) {
      note = document.createElement("div");
      note.className = "ucb-tma-umc-result";
      note.setAttribute("role", "status");
      form.prepend(note);
    }
    note.replaceChildren();
    text.split("\n").forEach(function (line, index) {
      if (index) {
        note.appendChild(document.createElement("br"));
      }
      note.appendChild(document.createTextNode(line));
    });
    note.hidden = false;
  }

  function showSuccess(form, number) {
    Array.prototype.forEach.call(form.children, function (child) {
      child.hidden = true;
    });
    var note = document.createElement("div");
    note.className = "ucb-tma-umc-result";
    note.setAttribute("role", "status");
    note.appendChild(document.createTextNode("Thank you!"));
    var done = document.createElement("p");
    var strong = document.createElement("strong");
    strong.textContent = "You've completed your UMC Maintenance Request";
    done.appendChild(strong);
    var request = document.createElement("p");
    request.appendChild(document.createTextNode("Request #"));
    var id = document.createElement("strong");
    id.textContent = number;
    request.appendChild(id);
    request.appendChild(document.createTextNode(" has been submitted. We will keep you updated with the progress."));
    note.appendChild(done);
    note.appendChild(request);
    form.prepend(note);
  }

  function setButtonsDisabled(form, disabled) {
    form.querySelectorAll("[type='submit']").forEach(function (button) {
      button.disabled = disabled;
    });
  }

  function submitUmcRequest(form) {
    var payload = {
      name: (fieldValue(form, "name[first]") + " " + fieldValue(form, "name[last]")).replace(/\s+/g, " ").trim(),
      email: fieldValue(form, "email_address"),
      phone: fieldValue(form, "phone_number"),
      department: fieldValue(form, "department"),
      room: fieldValue(form, "room"),
      detail: fieldValue(form, "request_detail"),
    };
    if (!payload.name || !payload.email || !payload.room || !payload.detail) {
      showMessage(form, "Name, email, room, and request detail are required.");
      return;
    }

    setButtonsDisabled(form, true);
    showMessage(form, "Submitting your request…");

    fetch("/session/token")
      .then(function (response) {
        if (!response.ok) {
          throw new Error("token");
        }
        return response.text();
      })
      .then(function (token) {
        return fetch("/tma/umc-request", {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "X-CSRF-Token": token,
          },
          body: JSON.stringify(payload),
        });
      })
      .then(function (response) {
        return response.json().then(function (body) {
          return { ok: response.ok, body: body };
        });
      })
      .then(function (result) {
        var number = result.ok && result.body && result.body.number ? String(result.body.number) : "";
        if (!number) {
          setButtonsDisabled(form, false);
          showMessage(form, (result.body && result.body.error) || "Platform did not create the request.");
          return;
        }
        showSuccess(form, number);
      })
      .catch(function () {
        setButtonsDisabled(form, false);
        showMessage(form, "The request could not be submitted.");
      });
  }

  Drupal.behaviors.ucbTmaUmcRooms = {
    attach: function (context) {
      once(
        "ucbTmaUmcRooms",
        "form[id^='webform-submission-umc-maintenance-request']",
        context
      ).forEach(function (form) {
        var room = form.querySelector('select[name="room"]');
        if (!room) {
          return;
        }
        var selected = room.value;
        room.replaceChildren(option("", "Choose"), option("", "Loading rooms…"));
        room.selectedIndex = 1;

        fetch("/tma/location/area")
          .then(function (response) {
            if (!response.ok) {
              throw new Error("areas");
            }
            return response.json();
          })
          .then(function (areas) {
            renderRooms(room, areas, selected);
          })
          .catch(function () {
            room.replaceChildren(option("", "Choose"));
            room.selectedIndex = 0;
          });

        form.addEventListener("submit", function (event) {
          event.preventDefault();
          submitUmcRequest(form);
        });
      });
    },
  };
})(Drupal, once);
