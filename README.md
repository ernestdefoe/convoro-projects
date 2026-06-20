# Projects for Convoro

A user-project **showcase** for [Convoro](https://convoro.co). Members publish
projects — a game, an app, a book, a mod, anything — as polished **cards** on a
`/projects` page, filterable by **category**. Each project gets a detail page
with **likes**, typed **custom fields**, **link buttons**, and the author can
**feature** their best work on their profile.

A faithful Convoro port of the Flarum "Projects" extension, rebuilt on Convoro's
server-rendered extension pattern so every page sits inside the real forum shell
(header, footer and live theme all match).

Free, first-party, MIT-licensed. Requires Convoro core **≥ 1.39.6**.

## Features

- **Project cards** — cover image, category badges (with each category's colour
  and icon), author, on-card field values, excerpt, link buttons and a like
  toggle. Click anywhere to open the detail page.
- **Category filter** — chips at the top of `/projects` filter the grid
  instantly; `?cat=<id>` deep-links to a category.
- **Detail pages** — hero image, full description, a typed facts grid (URLs
  become links, dates are formatted, booleans show Yes/No, prefixes/suffixes are
  honoured), primary vs. ghost link buttons and a like button.
- **Create / edit form** — title, excerpt, description, drag-to-browse **cover
  image upload**, multi-select categories, one input per admin-defined custom
  field (text / long-text / number / date / URL / select / yes-no, required
  enforced) and one URL slot per configured button (with optional custom label
  and per-button domain allow-lists). Optionally allow ad-hoc extra links.
- **Custom fields** — admins define typed parameters (e.g. Genre, Release date,
  Price) with an icon, prefix/suffix and a "show on card" toggle.
- **Link buttons** — admins define link slots, optionally restricted to certain
  domains (e.g. only `youtube.com`), marked primary/required, with fixed or
  custom labels.
- **Likes** — members like projects; the count is denormalised on the project.
- **Featured on profile** — authors toggle which published projects are featured;
  the profile showcase lists featured ones first.
- **Moderation** — members without the skip-moderation permission have their
  submissions held as *pending* until a moderator approves (or rejects with a
  reason).
- **Widgets** — a "Projects" showcase under each member's profile and a compact
  "Latest projects" card in the forum sidebar, both styled with live theme
  tokens.
- **Admin** — a tabbed manager (Categories / Fields / Buttons / Moderation)
  embedded in the admin area.

## Permissions

- `projects.create` *(baseline on)* — members may create projects.
- `projects.skipModeration` — their projects publish instantly instead of going
  through moderation.
- `projects.moderate` — approve/reject submissions and manage the categories,
  custom fields and button slots. Admins always have this.

A project's author may edit or delete it (if they still hold `projects.create`);
moderators may edit, delete or moderate any project.

## Install

Install from the Convoro Marketplace, then set permissions and define your
categories, custom fields and button slots under
**Admin → Extensions → Projects**.
