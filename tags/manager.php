<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 * @noinspection UnnecessaryCastingInspection
 * @noinspection PhpCastIsUnnecessaryInspection
 * @noinspection PhpRedundantOptionalArgumentInspection
 */

namespace phpbb\topicprefixes\tags;

use phpbb\cache\driver\driver_interface as cache;
use phpbb\db\driver\driver_interface;

/**
 * Topic tag definition and forum availability manager.
 */
class manager
{
	public const DEFAULT_COLOR = '4A76A8';
	public const CACHE_KEY = '_topicprefixes_tag_catalog';
	public const MAX_NAME_LENGTH = 50;
	public const MAX_NAME_STORAGE_LENGTH = 255;

	/** @var driver_interface Database connection */
	protected $db;

	/** @var string Tag definition table */
	protected $tags_table;

	/** @var string Forum/tag map table */
	protected $forums_map_table;

	/** @var string Topic/tag map table */
	protected $topic_map_table;

	/** @var string Forums table */
	protected $forums_table;

	/** @var cache|null Cache driver */
	protected $cache;

	/** @var array|null Request-local tag catalog */
	protected $catalog;

	/**
	 * Constructor.
	 *
	 * @param driver_interface $db Database connection
	 * @param string $tags_table Tag definition table
	 * @param string $forums_map_table Forum/tag map table
	 * @param string $topic_map_table Topic/tag map table
	 * @param string $forums_table Forums table
	 * @param cache|null $cache Cache driver
	 */
	public function __construct(driver_interface $db, string $tags_table, string $forums_map_table, string $topic_map_table, string $forums_table, ?cache $cache = null)
	{
		$this->db = $db;
		$this->tags_table = $tags_table;
		$this->forums_map_table = $forums_map_table;
		$this->topic_map_table = $topic_map_table;
		$this->forums_table = $forums_table;
		$this->cache = $cache;
	}

	/**
	 * Get one tag and its available forums.
	 *
	 * @param int $tag_id Tag identifier
	 * @return array|false Tag data, or false when missing
	 */
	public function get_tag(int $tag_id)
	{
		$catalog = $this->get_catalog();
		$tag_id = (int) $tag_id;
		if (!isset($catalog['tags'][$tag_id]))
		{
			return false;
		}

		$tag = $catalog['tags'][$tag_id];
		$tag['forum_ids'] = $catalog['tag_forums'][$tag_id] ?? [];

		return $tag;
	}

	/**
	 * Get every tag in display order.
	 *
	 * @return array Tags keyed by identifier
	 */
	public function get_tags(): array
	{
		return $this->get_catalog()['tags'];
	}

	/**
	 * Get selected tag definitions from the cached catalog.
	 *
	 * @param array $tag_ids Tag identifiers
	 * @return array Tags keyed by identifier
	 */
	public function get_tags_by_ids(array $tag_ids): array
	{
		$requested = array_fill_keys(array_map('intval', $tag_ids), true);
		return array_intersect_key($this->get_tags(), $requested);
	}

	/**
	 * Get exact search tags selected by definition ID.
	 *
	 * @param array $tag_ids Submitted tag definition identifiers
	 * @return array Search-ready tags keyed by identifier
	 */
	public function resolve_search_tags(array $tag_ids): array
	{
		$requested = array_fill_keys(array_filter(array_map('intval', $tag_ids)), true);
		if (!$requested)
		{
			return [];
		}

		$selected = [];
		foreach ($this->get_tags_by_ids(array_keys($requested)) as $tag_id => $tag)
		{
			$selected[(int) $tag_id] = $this->prepare_search_tag((int) $tag_id, $tag);
		}

		return $selected;
	}

	/**
	 * Get exact selected search tags available in at least one allowed forum.
	 *
	 * Disabled tags remain resolvable so historical searches can be reopened,
	 * but removed forum access must never disclose their definitions.
	 *
	 * @param array $tag_ids   Submitted tag definition identifiers
	 * @param array $forum_ids Forums current user may search
	 * @return array Search-ready tags keyed by identifier
	 */
	public function resolve_search_tags_for_forums(array $tag_ids, array $forum_ids): array
	{
		$forum_ids = array_fill_keys(array_filter(array_map('intval', $forum_ids)), true);
		if (!$forum_ids)
		{
			return [];
		}

		$catalog = $this->get_catalog();
		$selected = [];
		foreach ($this->resolve_search_tags($tag_ids) as $tag_id => $tag)
		{
			$available = array_intersect_key(
				$forum_ids,
				array_fill_keys($catalog['tag_forums'][$tag_id] ?? [], true)
			);
			if ($available)
			{
				$selected[(int) $tag_id] = $tag;
			}
		}

		return $selected;
	}

	/**
	 * Get enabled tag definitions available in searchable forums.
	 *
	 * @param array $forum_ids Forums current user may search
	 * @return array Search-ready tags keyed by identifier
	 */
	public function get_search_catalog(array $forum_ids): array
	{
		$forum_ids = array_fill_keys(array_filter(array_map('intval', $forum_ids)), true);
		if (!$forum_ids)
		{
			return [];
		}

		$catalog = $this->get_catalog();
		$tags = [];
		foreach ($catalog['tags'] as $tag_id => $tag)
		{
			$available = array_intersect_key(
				$forum_ids,
				array_fill_keys($catalog['tag_forums'][$tag_id] ?? [], true)
			);
			if (!empty($tag['prefix_enabled']) && $available)
			{
				$tags[(int) $tag_id] = $this->prepare_search_tag((int) $tag_id, $tag);
			}
		}

		return $tags;
	}

	/**
	 * Normalize tag text for client-side autocomplete matching.
	 *
	 * @param string $name Decoded tag name
	 * @return string Case-insensitive phpBB search key
	 */
	public static function search_name_key(string $name): string
	{
		return utf8_clean_string(trim($name));
	}

	/**
	 * Get tags available in one forum.
	 *
	 * @param int  $forum_id    Forum identifier
	 * @param bool $enabled_only Return enabled tags only
	 * @return array Tags keyed by identifier
	 */
	public function get_available_tags(int $forum_id, bool $enabled_only = true): array
	{
		$catalog = $this->get_catalog();
		$forum_tags = $catalog['forum_tags'][(int) $forum_id] ?? [];
		return array_filter($catalog['tags'], static function ($tag, $tag_id) use ($enabled_only, $forum_tags) {
			return isset($forum_tags[$tag_id]) && (!$enabled_only || !empty($tag['prefix_enabled']));
		}, ARRAY_FILTER_USE_BOTH);
	}

	/**
	 * Get definitions unavailable for new assignments in one forum.
	 *
	 * @param int $forum_id Forum identifier
	 * @return array Tag identifiers
	 */
	public function get_unavailable_tag_ids(int $forum_id): array
	{
		$catalog = $this->get_catalog();
		return array_keys(array_diff_key(
			$catalog['tags'],
			$catalog['forum_tags'][(int) $forum_id] ?? []
		));
	}

	/**
	 * Get submitted tags that are enabled and available in a forum.
	 *
	 * @param int   $forum_id Forum identifier
	 * @param array $tag_ids  Submitted tag identifiers
	 * @return array Valid tags keyed by identifier
	 */
	public function get_assignable_tags(int $forum_id, array $tag_ids): array
	{
		$tag_ids = array_values(array_unique(array_map('intval', $tag_ids)));
		if (!$tag_ids)
		{
			return [];
		}

		$catalog = $this->get_catalog();
		$forum_tags = $catalog['forum_tags'][(int) $forum_id] ?? [];
		$submitted = array_fill_keys($tag_ids, true);
		return array_filter($catalog['tags'], static function ($tag, $tag_id) use ($submitted, $forum_tags) {
			return isset($submitted[$tag_id], $forum_tags[$tag_id]) && !empty($tag['prefix_enabled']);
		}, ARRAY_FILTER_USE_BOTH);
	}

	/**
	 * Create tag definition and forum relationships.
	 *
	 * @param string $name      Tag text
	 * @param string $color     Six-digit hexadecimal background color
	 * @param bool   $enabled   Enabled state
	 * @param array  $forum_ids Available forum identifiers
	 * @return array|false Created tag, or false for invalid data
	 */
	public function add_tag(string $name, string $color, bool $enabled, array $forum_ids)
	{
		$name = self::normalize_name($name);
		$color = self::normalize_color($color);
		if ($name === '' || $color === '')
		{
			return false;
		}

		$sql = 'SELECT MAX(prefix_order) AS max_order FROM ' . $this->tags_table;
		$result = $this->db->sql_query($sql);
		$max_order = (int) $this->db->sql_fetchfield('max_order');
		$this->db->sql_freeresult($result);

		$this->db->sql_transaction('begin');
		$sql = 'INSERT INTO ' . $this->tags_table . '
			(prefix_tag, prefix_color, prefix_enabled, prefix_order)
			VALUES (' . $this->sql_text_literal($name) . ', ' . $this->sql_text_literal($color) . ', ' .
			(int) (bool) $enabled . ', ' . ($max_order + 1) . ')';
		$this->db->sql_query($sql);
		$tag_id = (int) $this->db->sql_last_inserted_id();
		$this->replace_forums($tag_id, $forum_ids);
		$this->db->sql_transaction('commit');
		$this->invalidate_catalog();

		return $this->get_tag($tag_id);
	}

	/**
	 * Update tag definition and forum relationships.
	 *
	 * @param int    $tag_id   Tag identifier
	 * @param string $name     Tag text
	 * @param string $color    Six-digit hexadecimal background color
	 * @param bool   $enabled  Enabled state
	 * @param array  $forum_ids Available forum identifiers
	 * @return array|false Updated tag, or false when invalid or missing
	 */
	public function update_tag(int $tag_id, string $name, string $color, bool $enabled, array $forum_ids)
	{
		$tag_id = (int) $tag_id;
		$name = self::normalize_name($name);
		$color = self::normalize_color($color);
		if (!$tag_id || $name === '' || $color === '' || !$this->tag_exists($tag_id))
		{
			return false;
		}

		$this->db->sql_transaction('begin');
		$sql = 'UPDATE ' . $this->tags_table . '
			SET prefix_tag = ' . $this->sql_text_literal($name) . ',
				prefix_color = ' . $this->sql_text_literal($color) . ',
				prefix_enabled = ' . (int) (bool) $enabled . '
			WHERE prefix_id = ' . $tag_id;
		$this->db->sql_query($sql);
		$this->replace_forums($tag_id, $forum_ids);
		$this->db->sql_transaction('commit');
		$this->invalidate_catalog();

		return $this->get_tag($tag_id);
	}

	/**
	 * Set tag enabled state.
	 *
	 * @param int  $tag_id Tag identifier
	 * @param bool $enabled Enabled state
	 * @return bool Whether tag existed
	 */
	public function set_enabled(int $tag_id, bool $enabled): bool
	{
		if (!$this->tag_exists($tag_id))
		{
			return false;
		}

		$sql = 'UPDATE ' . $this->tags_table . '
			SET prefix_enabled = ' . (int) (bool) $enabled . '
			WHERE prefix_id = ' . (int) $tag_id;
		$this->db->sql_query($sql);
		$this->invalidate_catalog();

		return true;
	}

	/**
	 * Delete tag and all relationships.
	 *
	 * @param int $tag_id Tag identifier
	 * @return bool Whether tag existed
	 */
	public function delete_tag(int $tag_id): bool
	{
		$tag_id = (int) $tag_id;
		if (!$this->tag_exists($tag_id))
		{
			return false;
		}

		$this->db->sql_transaction('begin');
		$this->db->sql_query('DELETE FROM ' . $this->topic_map_table . ' WHERE prefix_id = ' . $tag_id);
		$this->db->sql_query('DELETE FROM ' . $this->forums_map_table . ' WHERE prefix_id = ' . $tag_id);
		$this->db->sql_query('DELETE FROM ' . $this->tags_table . ' WHERE prefix_id = ' . $tag_id);
		$this->db->sql_transaction('commit');
		$this->invalidate_catalog();

		return true;
	}

	/**
	 * Delete availability relationships for removed forums.
	 *
	 * @param array $forum_ids Forum identifiers
	 * @return void
	 */
	public function delete_forum_availability(array $forum_ids): void
	{
		$forum_ids = array_values(array_unique(array_filter(array_map('intval', $forum_ids))));
		if ($forum_ids)
		{
			$this->db->sql_query('DELETE FROM ' . $this->forums_map_table . '
				WHERE ' . $this->db->sql_in_set('forum_id', $forum_ids));
			$this->invalidate_catalog();
		}
	}

	/**
	 * Move tag one position in display order.
	 *
	 * @param int    $tag_id    Tag identifier
	 * @param string $direction Move direction: up or down
	 * @return bool Whether tag existed
	 */
	public function move_tag(int $tag_id, string $direction): bool
	{
		$tags = array_values($this->get_tags());
		$current = false;
		foreach ($tags as $index => $tag)
		{
			if ((int) $tag['prefix_id'] === (int) $tag_id)
			{
				$current = $index;
				break;
			}
		}

		if ($current === false)
		{
			return false;
		}

		$target = $direction === 'down' ? $current + 1 : $current - 1;
		if (!isset($tags[$target]))
		{
			return true;
		}

		$current_id = (int) $tags[$current]['prefix_id'];
		$current_order = (int) $tags[$current]['prefix_order'];
		$target_id = (int) $tags[$target]['prefix_id'];
		$target_order = (int) $tags[$target]['prefix_order'];
		if ($current_order === $target_order)
		{
			$swap = $tags[$current];
			$tags[$current] = $tags[$target];
			$tags[$target] = $swap;
			return $this->reorder_tags(array_column($tags, 'prefix_id'));
		}
		$order = $this->db->sql_case(
			'prefix_id = ' . $current_id,
			(string) $target_order,
			$this->db->sql_case(
				'prefix_id = ' . $target_id,
				(string) $current_order,
				'prefix_order'
			)
		);

		$this->db->sql_transaction('begin');
		$sql = 'UPDATE ' . $this->tags_table . '
			SET prefix_order = ' . $order . '
			WHERE ' . $this->db->sql_in_set('prefix_id', [$current_id, $target_id]);
		$this->db->sql_query($sql);
		$this->db->sql_transaction('commit');
		$this->invalidate_catalog();

		return true;
	}

	/**
	 * Replace the complete tag display order.
	 *
	 * The submitted identifiers must contain every current tag exactly once.
	 * This prevents a stale ACP page from silently dropping newly-created tags.
	 *
	 * @param array $tag_ids Tag identifiers in desired display order
	 * @return bool Whether the complete order was valid and saved
	 */
	public function reorder_tags(array $tag_ids): bool
	{
		$tag_ids = array_map('intval', array_values($tag_ids));
		$current_tags = $this->get_tags();
		$current_ids = array_map('intval', array_keys($current_tags));

		if (count($tag_ids) !== count(array_unique($tag_ids)))
		{
			return false;
		}

		$submitted_set = $tag_ids;
		$current_set = $current_ids;
		sort($submitted_set);
		sort($current_set);
		if ($submitted_set !== $current_set)
		{
			return false;
		}

		$this->db->sql_transaction('begin');
		foreach ($tag_ids as $position => $tag_id)
		{
			$order = $position + 1;
			if ((int) $current_tags[$tag_id]['prefix_order'] === $order)
			{
				continue;
			}

			$this->db->sql_query('UPDATE ' . $this->tags_table . '
				SET prefix_order = ' . $order . '
				WHERE prefix_id = ' . $tag_id);
		}
		$this->db->sql_transaction('commit');
		$this->invalidate_catalog();

		return true;
	}

	/**
	 * Get forum names grouped by tag.
	 *
	 * @return array Forum names keyed by tag identifier
	 */
	public function get_forum_names_by_tag(): array
	{
		$sql = 'SELECT pf.prefix_id, f.forum_name
			FROM ' . $this->forums_map_table . ' pf
			INNER JOIN ' . $this->forums_table . ' f
				ON f.forum_id = pf.forum_id
			ORDER BY pf.prefix_id ASC, f.left_id ASC';
		$result = $this->db->sql_query($sql);
		$forums = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$forums[(int) $row['prefix_id']][] = self::decode_name($row['forum_name']);
		}
		$this->db->sql_freeresult($result);

		return $forums;
	}

	/**
	 * Normalize and validate hexadecimal color.
	 *
	 * @param string $color Submitted color
	 * @return string Normalized color, or empty string when invalid
	 */
	public static function normalize_color(string $color): string
	{
		$color = strtoupper(ltrim(trim($color), '#'));
		return preg_match('/^[0-9A-F]{6}$/D', $color) ? $color : '';
	}

	/**
	 * Prepare semantic tag text for phpBB's encoded database storage.
	 *
	 * HTML-sensitive characters are escaped and four-byte characters are stored
	 * as numeric Unicode references. Reject names whose stored representation
	 * would exceed the legacy column size.
	 *
	 * @param string $name Submitted tag text
	 * @return string Storage-safe text, or empty string when invalid
	 */
	public static function normalize_name(string $name): string
	{
		$name = trim($name);
		if (utf8_strlen($name) > self::MAX_NAME_LENGTH)
		{
			return '';
		}
		$name = utf8_htmlspecialchars($name);
		$name = utf8_encode_ucr($name);

		return utf8_strlen($name) <= self::MAX_NAME_STORAGE_LENGTH ? $name : '';
	}

	/**
	 * Restore stored HTML and Unicode references to semantic text.
	 *
	 * @param string $name Stored phpBB text
	 * @return string Display text
	 */
	public static function decode_name(string $name): string
	{
		return htmlspecialchars_decode(utf8_decode_ncr($name), ENT_COMPAT);
	}

	/**
	 * Check whether tag exists.
	 *
	 * @param int $tag_id Tag identifier
	 * @return bool
	 */
	protected function tag_exists(int $tag_id): bool
	{
		return isset($this->get_catalog()['tags'][(int) $tag_id]);
	}

	/**
	 * Load tag definitions and forum availability with one cacheable query.
	 *
	 * @return array Tag catalog
	 */
	protected function get_catalog(): array
	{
		if ($this->catalog !== null)
		{
			return $this->catalog;
		}

		if ($this->cache && ($catalog = $this->cache->get(self::CACHE_KEY)) !== false)
		{
			return $this->catalog = $catalog;
		}

		$catalog = [
			'tags' => [],
			'tag_forums' => [],
			'forum_tags' => [],
		];
		$sql = 'SELECT p.*, pf.forum_id
			FROM ' . $this->tags_table . ' p
			LEFT JOIN ' . $this->forums_map_table . ' pf
				ON pf.prefix_id = p.prefix_id
			ORDER BY p.prefix_order ASC, p.prefix_id ASC, pf.forum_id ASC';
		$result = $this->db->sql_query($sql);
		while ($row = $this->db->sql_fetchrow($result))
		{
			$tag_id = (int) $row['prefix_id'];
			$forum_id = (int) $row['forum_id'];
			unset($row['forum_id']);
			$row['prefix_tag'] = self::decode_name($row['prefix_tag']);
			$catalog['tags'][$tag_id] = $row;
			if ($forum_id)
			{
				$catalog['tag_forums'][$tag_id][] = $forum_id;
				$catalog['forum_tags'][$forum_id][$tag_id] = true;
			}
		}
		$this->db->sql_freeresult($result);

		$this->catalog = $catalog;
		if ($this->cache)
		{
			$this->cache->put(self::CACHE_KEY, $catalog);
		}

		return $catalog;
	}

	/**
	 * Prepare one exact tag definition for search autocomplete.
	 *
	 * @param int   $tag_id Tag identifier
	 * @param array $tag    Tag definition
	 * @return array Search-ready tag record
	 */
	protected function prepare_search_tag(int $tag_id, array $tag): array
	{
		$tag['prefix_id'] = $tag_id;
		$tag['prefix_order'] = (int) $tag['prefix_order'];
		$tag['search_name'] = self::search_name_key($tag['prefix_tag']);
		return $tag;
	}

	/**
	 * Invalidate persistent and request-local tag metadata.
	 *
	 * @return void
	 */
	protected function invalidate_catalog(): void
	{
		$this->catalog = null;
		if ($this->cache)
		{
			$this->cache->destroy(self::CACHE_KEY);
		}
	}

	/**
	 * Quote text for portable Unicode SQL literals.
	 *
	 * @param string $value Unquoted value
	 * @return string Quoted SQL literal
	 */
	protected function sql_text_literal(string $value): string
	{
		$unicode_prefix = strpos($this->db->get_sql_layer(), 'mssql') === 0 ? 'N' : '';

		return $unicode_prefix . "'" . $this->db->sql_escape($value) . "'";
	}

	/**
	 * Replace forum availability relationships for one tag.
	 *
	 * @param int   $tag_id    Tag identifier
	 * @param array $forum_ids Submitted forum identifiers
	 * @return void
	 */
	protected function replace_forums(int $tag_id, array $forum_ids): void
	{
		$forum_ids = array_values(array_unique(array_map('intval', $forum_ids)));
		$valid_ids = [];
		if ($forum_ids)
		{
			$sql = 'SELECT forum_id
				FROM ' . $this->forums_table . '
				WHERE forum_type = ' . FORUM_POST . '
					AND ' . $this->db->sql_in_set('forum_id', $forum_ids);
			$result = $this->db->sql_query($sql);
			while ($row = $this->db->sql_fetchrow($result))
			{
				$valid_ids[] = (int) $row['forum_id'];
			}
			$this->db->sql_freeresult($result);
		}

		$this->db->sql_query('DELETE FROM ' . $this->forums_map_table . ' WHERE prefix_id = ' . (int) $tag_id);
		$rows = [];
		foreach ($valid_ids as $forum_id)
		{
			$rows[] = ['forum_id' => $forum_id, 'prefix_id' => $tag_id];
		}
		if ($rows)
		{
			$this->db->sql_multi_insert($this->forums_map_table, $rows);
		}
	}
}
