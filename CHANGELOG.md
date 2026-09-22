# Changelog

### 2.0.0 - TBD

- Renamed the user-facing extension from phpBB Topic Prefixes to phpBB Topic Tags.
- Replaced title text prefixes with relational topic tag metadata.
- Added multiple colored tags per topic and automatic badge text contrast.
- Added global ACP tag management with forum availability controls.
- Added accessible tag selection when creating topics and editing first posts.
- Added tag badges to forum, topic, and search displays.
- Added multi-tag AND filtering to forum topic lists.
- Preserved tag metadata through topic moves, splits, forks, and merges.
- Added relationship cleanup for topic and forum deletion.
- Added conservative migration of legacy definitions, assignments, titles, and first-post subjects.
- Note: implementations for showing tags on UCP main page, bookmarks, subscriptions and search results shown as posts will take effect after phpBB core adds new proposed events.

### 1.0.2 - 2025-12-17

- Strengthened compatibility with both phpBB3 and phpBB4-alpha-1.
- Compatibility fix for the Quick Edit extension.
- Cleaned out leftover jQuery code for consistent JavaScript.
- Other internal code improvements.

### 1.0.1 - 2024-01-20

- Resolved PHP errors that occurred when attempting to create a new Prefix tag in the ACP with an empty input field.
- Addressed an issue where editing posts and switching between Prefix or no Prefix now ensures that all Reply posts are updated with the correct Prefix upon form submission.
- Fixed the behavior where, during post-preview, the selected Prefix remains chosen in the posting form.
- Updated the prefix's Enable/Disable toggle to a more user-friendly graphical UI switch.
- Eliminated deprecated jQuery commands.
- Various internal code improvements.

### 1.0.0 - 2018-12-24

- First release
