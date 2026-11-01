# phpBB Topic Tags

This is the repository for development of phpBB Topic Tags, formerly named Topic Prefixes.

[![Build Status](https://github.com/phpbb-extensions/topicprefixes/actions/workflows/tests.yml/badge.svg)](https://github.com/phpbb-extensions/topicprefixes/actions)
[![codecov](https://codecov.io/gh/phpbb-extensions/topicprefixes/branch/master/graph/badge.svg?token=Dc0GWOeQWj)](https://codecov.io/gh/phpbb-extensions/topicprefixes)

The phpBB Topic Tags extension provides administrator-curated topic tags. Tags are structured topic metadata and are displayed separately from topic titles as colored badges. Features include:

- Create, edit, order, enable, disable, and color topic tags.
- Make each tag available in one or more forums.
- Assign multiple tags while creating a topic or editing its first post.
- Display readable colored badges in topic lists, topic pages, and search results.
- Filter forum and category Active Topics lists by one or more tags using AND semantics.
- Optionally enable tag search in ACP > General > Board features, adding a permission-filtered autocomplete to advanced search with all/any matching and tag-only topic results.
- Filter keyword and author search results shown as topics or posts when using phpBB native, MySQL fulltext, or PostgreSQL search. Sphinx supports tag-only search but not combined tag and text/author search.
- Upgrade existing title prefixes safely into relational tag assignments.
- This is the same extension currently in use at phpbb.com in the Extensions and Styles in development forums.

## Upgrading from 1.x

Before enabling version 2, make a full database backup and disable the board in
General > Board settings. The extension blocks a legacy upgrade while the board
is enabled.

Migration splits complete adjacent bracket sequences such as `[3.3][DEV]` into
separate tags. Legacy prefixes duplicated within or across multiple forums are
consolidated into one tag available in each applicable forum when their decoded
names and enabled states match. Other combined formats and same-name definitions with
different enabled states remain separate. This upgrade is one-way; restore the
pre-upgrade database backup to undo it.

## Repair tools

Before applying any repair, make a current database backup. Disable the board
unless intentionally using `--force`. Each tool shows a preview and asks for
confirmation before changing anything.

### Repair tags

Use this command to split a combined tag into separate tags or merge one tag
into another:

```shell
php bin/phpbbcli.php topicprefixes:repair-tags
```

To repair a specific tag, add `--tag-id=ID`.

To keep the board enabled during the repair, add `--force`. Active requests may
conflict with repair changes, so use this only when maintenance mode is not
practical.

### Repair topics

Use this command to repair missing topic tags or old prefixes, usually after
upgrading from version 1 to version 2:

```shell
php bin/phpbbcli.php topicprefixes:repair-topics
```

To keep the board enabled during any repair action, add `--force`. Active
requests may conflict with repair changes.

Choose the action that matches the problem:

- **Recover missing topic tags:** use when a topic still shows its old prefix
  and has no tag.
- **Clean leftover topic titles:** use when tags migrated correctly but old
  prefix text remains in topic titles.
- **Clean historical reply subjects:** optionally remove old prefixes from
  replies. Reply subjects are normally kept as part of the discussion history.

Missing-tag candidates are grouped by forum and inferred tags. You can review
each group in pages of 20, stop previewing at any page, then approve or skip the
whole group. The confirmation reports how many candidates were and were not
previewed. Each completed repair action creates one administrator-log summary.

For title or reply cleanup, enter the old prefix exactly as shown and select the
forum to repair. If post subjects change, rebuild the search index in ACP >
Maintenance > Search index.

Some missing information cannot be recovered from the current database. If the
tool cannot identify the old tag, use a pre-upgrade backup to determine it.

📦 [Download](https://www.phpbb.com/customise/db/extension/topicprefixes/) the latest release of this extension.

🐞 [Report bugs](https://github.com/phpbb-extensions/topicprefixes/issues) to our Issue Tracker.

💬 [Support](https://www.phpbb.com/customise/db/extension/topicprefixes/support) can be requested and discussed in this extension's support forum at phpBB.com.

## License

[GNU General Public License v2](license.txt)
