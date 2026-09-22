# UCB TMA Interface

Connects CU Boulder's Fix It webforms to [TMA Systems webTMA](https://webtma.com/platformapi/swagger/index.html) (Platform API v7). This module turns those submissions into TMA request logs, and keeps local facility, building, area, and task data in sync with TMA.

## What it does

- Creates two Forms: **Report a problem** (`report_a_problem`) and **Request services** (`request_services`) wizard webforms collect the issue, location, and contact info.
- On final submit, the **UCB TMA form handler** posts a RequestLog to Platform API `POST /v2/Requests`.
- The TMA request number is stored on the webform as `ticket_id` for the confirmation page, you can use the TMA portal (`https://tma.colorado.edu/WebTMA7/SAML/ACS?c=ucb`) to check this number under Requests.
- Cascading **facility → building → area** dropdowns are filled from TMA (and from seeded Drupal taxonomy).
- Some tasks show an **exception modal** instead of submitting (for example, issues that should be handled another way). These lock the form and show a modal with follow up instructions, to prevent submissions.
- A post-form completion **Fix It Survey** (`fix_it_survey`) collects emoji feedback after confirmation.

## Requirements

Beyond core Drupal functionality, you also need:
- TMA Platform API credentials (username, password, client name)

These are configured as described below:

## Configuration

Go to **Configuration → TMA Interface** (`/admin/config/tma`):

| Setting | Purpose |
| --- | --- |
| Platform API Base URL | Example: `https://devtma7.colorado.edu/webTMA7/platformapi` (no trailing slash) |
| Authentication Username / Password / Client Name | JWT login for `POST /v2/Users/Authenticate` |
| Verbose TMA API debug logging | Logs request/response payloads. Can include PII. |
| Facility / Area LocationTypeId | RequestLog location types (typically Facility `10`, Area `7`) |

## Seeding data (Drush)

Location taxonomy is pulled live from TMA API. Tasks are hard-coded and come from `data/fixit_tasks.yml`.

```bash
# Import facilities, buildings, areas, and tasks
drush tma:seed-fixit

# Same command, shorter alias
drush tma-seed-fixit
```

Optional flags:

| Option | Description |
| --- | --- |
| `--skip-locations` | Skip facility / building / area import |
| `--skip-tasks` | Skip task node import |
| `--tasks-file=/path/to/file.yml` | Use a custom tasks YAML instead of `data/fixit_tasks.yml` |
| `--tasks-verbose` | Log each task create/update and taxonomy link |

The location import can take several minutes. It creates or updates:

- **Facility** terms (`field_tma_facility_id`)
- **Building** terms (`field_tma_building_id_`, parent facility id)
- **Area** terms (`field_tma_area_id`, building id, floor, description)

## Platform API

Official docs: [TMA Platform API (Swagger)](https://webtma.com/platformapi/swagger/index.html)

This module talks to Platform API **v7** with a JWT Bearer token.

1. Authenticate: `POST /v2/Users/Authenticate` (`userName`, `password`, `clientName`). The token is cached for 5 minutes.
2. Subsequent calls send `Authorization: Bearer <token>`.

Endpoints used here:

| Endpoint | Used for |
| --- | --- |
| `POST /v2/Users/Authenticate` | JWT login - need to provided Bearer token with below requests |
| `GET /v2/Facilities`, `/v2/Buildings`, `/v2/Areas` | Location seed + dropdown JSON |
| `POST /v2/Requests` | Create a Fix It request log |
| `PATCH /v2/Requests/{id}` | Set task code after create (POST often leaves it empty) |
| `GET /v2/Requests`, `/v2/Tasks`, `/v2/RequestTypes`, `/v2/RepairCenters`, `/v2/Floors` | Lookup ids, codes, and verification |

Drupal also exposes local JSON routes used by the forms:

- `/tma/location/facility`
- `/tma/location/building`
- `/tma/location/area` and `/tma/location/area/{facility}`
- `/tma/task-exceptions`

## Content and forms

The module installs:

- Vocabularies: **facility**, **building**, **area**, **categories**, **services**
- Content type: **Task** (options on the two Fix It forms)
- Webforms: Report a problem, Request services, Fix It Survey
- View: `task_selection` (filters tasks by selected category/service)

## Fix It Survey block

To show the “How did we do?” emoji form after submit:

1. Go to **Structure → Block layout** and place a Webform block for `fix_it_survey` (Content or Below Content).
2. Under **Visibility → Pages**, show only:

```
/report-a-problem-confirmation
/request-services-confirmation
```
