# Changelog

### 2.0.0-dev - TBD

- Renamed phpBB Topic Prefixes to phpBB Topic Tags and replaced single text prefixes embedded in titles with relational topic metadata.
- Added multiple color-coded tags per topic with automatic badge text contrast and badges throughout forum, topic, MCP, and search displays.
- Added centralized ACP management for tag text, color, order, status, and availability across multiple forums.
- Added accessible tag selection when creating topics or editing their first posts.
- Added clickable forum filters that can combine multiple tags using AND matching.
- Preserved tags through topic moves, splits, forks, and merges, including tags retained after moving into forums where they are unavailable for new assignments.
- Added a safe, restartable upgrade that converts legacy prefixes and assignments into tags while removing matching prefix text from topic titles and first-post subjects.
- Raised minimum requirements to phpBB 3.3.0 and PHP 7.2.
- Note: implementations for showing tags on UCP main page, bookmarks, and subscriptions will take effect after phpBB adds new proposed events (targeted for 3.3.19).

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
