# Scholarship Finder Structure

This project is organized into the following top-level folders:

- `AdminController/` - admin-side action handlers such as login, scholarship, user, and application processing.
- `AdminPublic/` - admin-only static assets.
- `AdminView/` - admin pages and admin layout files.
- `Config/` - database/bootstrap configuration and shared helpers.
- `Controller/` - user-side request handlers and API-style endpoints.
- `Model/` - database models.
- `public/` - shared CSS, JavaScript, images, uploads, and temporary OCR files.
- `storage/logs/` - runtime log files.
- `tools/debug/` - standalone debug utilities.
- `tools/gwa/` - standalone OCR/GWA testing tools.
- `View/` - user-facing pages, layouts, and partials.

Notes:

- Runtime logs were moved out of source folders into `storage/logs/`.
- OCR/debug utilities were moved out of the app root into `tools/`.
- File and folder names were normalized to remove duplicate copy suffixes like ` (1)`.
