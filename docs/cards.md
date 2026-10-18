# Card gallery

Cards are what a slash command inserts into a message: a post, a comment, an order, a report.
A provider sends **data only**; Flurfunk draws it from a fixed set of building blocks, so every
card looks native, works in light and dark, and stays readable on a phone. Buttons on a card
(card actions) run PHP on the site, with an inline confirmation where needed.

This page shows every block as the app draws it, light and dark side by side. The schema of
each block, the limits and complete provider examples are in the developer guide:
[card building blocks](developers.md#card-building-blocks) and
[card actions](developers.md#card-actions).

| Block | Data |
| --- | --- |
| `title`, `subtitle`, `url`, `status` | strings; `status` is `{label, tone}` |
| `fields` | `[{label, value, url?}]`, up to 12 |
| `checklist` | `[{label, done}]`, up to 20 |
| `progress` | `{value, max, label?}` |
| `people` | user IDs, up to 12 |
| `table` | `{columns: [...], rows: [[...]]}`, up to 6 columns and 20 rows |
| `badges` | strings, up to 8 |
| `quote` | plain text, up to 2,000 characters |
| `metric`, `spark`, `text`, `meta`, `image`, `audio`, `action`, `target` | as before |
| `actions` | IDs of the registered actions available right now |

<!-- The gallery itself is drawn on the website only (https://worldoptimizer.github.io/flurfunk/cards.html).
{% include card-gallery.html %}
-->

The gallery is generated from the plugin's own `assets/app.js` and `assets/app.css` with
`node tools/docs-cards.mjs`.
