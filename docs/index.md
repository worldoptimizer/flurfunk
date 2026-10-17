# Flurfunk

Flurfunk adds team conversations to WordPress. Channels, threads and tasks live in wp-admin,
next to the posts they are about. Other plugins can add slash commands that insert live cards,
and anything a plugin registers can carry its own threads.

Flurfunk is a standalone plugin under GPL-2.0-or-later. It has its own database tables, needs no
external service and no build step.

![The Flurfunk overview: sidebar, thread list and an open thread](assets/screenshot-overview.png)

## Documentation

| Page | For whom | What is in it |
| --- | --- | --- |
| [Installation and administration](installation.md) | Site admins | Requirements, setup, access, assistants, cron, moving sites, privacy, troubleshooting |
| [User guide](user-guide.md) | Everyone using Flurfunk | Threads, formatting, mentions, tasks, files, notifications, shortcuts |
| [Developer guide](developers.md) | Plugin and site developers | Data model, REST API, providers, targets, embeds, hooks, WP-CLI |
| [Examples](examples.md) | Developers | Copy-paste code: a provider, a target type, a card renderer, CI scripts, filters |

## Feature tour

### Channels and threads

A channel holds threads. A thread has an optional title, a text, replies, files and cards. The
overview at *Flurfunk* in the admin menu has three columns: the sidebar with channels and views,
the thread list, and the open thread.

The sidebar shows:

- **All threads**, **Mentions**, **My tasks** and **Open tasks**
- the channels you can see, with unread numbers
- posts (or other targets) with recent conversations
- the bell with your notifications
- your name at the bottom, which opens your personal settings

### Conversations about posts

A thread can be attached to a post. It then shows up in the **Conversations** panel of the
block editor and as a number in the posts list column. Plugins can register more target types
(a product, a customer, a dossier), and the "Attached to" picker searches all of them.

![The Conversations panel in the block editor](assets/screenshot-editor-panel.png)

### Tasks

Any thread can be a task with a state (open, in progress, question, done), a due date and
people responsible. "My tasks" lists your tasks by due date and marks overdue ones in red. The
**Board** shows all tasks in columns by state; cards move by drag and drop or with the keyboard.
Responsible people are notified when they get a task, when a task needs an answer, and on the
due date.

![The board](assets/screenshot-board.png)

### Mentions, formatting, files

Write `@Name` to mention someone. Simple Slack-like formatting (`*bold*`, `_italic_`, lists,
quotes, code) works in every message. Files are uploaded right into the conversation or picked
from the media library (up to 50 per message). Images show as previews or a gallery and open in
a lightbox; audio and video show as players. Uploaded files stay out of the media library until
someone adds them, without being copied or moved.

### Slash commands and cards

Typing `/` opens a menu of commands. `/post` inserts a post as a card. Other plugins register
their own commands (the bundled MailerPress integration adds `/newsletter`). A card stores a
snapshot of its data, so it stays readable when the source changes, and can be updated on
request.

![A card inserted with a slash command](assets/screenshot-card.png)

### Notifications

Every notification lands in the in-app list behind the bell. Each person chooses what reaches
them by mail (right away, a daily summary, everything, never) and by Web Push to browsers and
phones. There are quiet hours, a pause button, per-channel levels, and per-thread follow, mute
and "remind me".

### Team and guests

People are either **team** or **guests**, decided by a capability. Each channel says what the
team and what guests see: everything, only threads about their own posts, or nothing. Guest
authors can talk about their own texts without seeing internal discussions.

### Assistants

Agents and scripts post through assistant accounts. They have no editing rights in WordPress,
are clearly marked "Assistant" in the conversation, and connect through WP-CLI or the REST API
with an application password.

### Embeds

Developers can put threads anywhere in wp-admin: next to the items of a checklist, as a whole
channel shown as a numbered list, or as the conversations about one object.

### Data and privacy

One JSON format, `flurfunk/1`, covers export and import, with or without files. Flurfunk takes
part in WordPress's personal data export and erase tools, and cleans up when users are deleted.

## Where Flurfunk shows up

| Place | What |
| --- | --- |
| Admin menu *Flurfunk* | The overview app, with an unread badge |
| *Settings → Flurfunk* | Name, logo, colours, team and guests, channels, assistants, post types, data |
| Block editor | "Conversations" panel for the configured post types |
| Posts list | Column with the number of conversations and open tasks |
| Other admin screens | Embedded threads, if a plugin adds them |

## Project

- Source: [github.com/worldoptimizer/flurfunk](https://github.com/worldoptimizer/flurfunk)
- License: GPL-2.0-or-later
- Translations: English source strings, German included
