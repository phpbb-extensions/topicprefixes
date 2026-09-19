<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2016 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 * @noinspection UnnecessaryCastingInspection
 * @noinspection PhpCastIsUnnecessaryInspection
 * @noinspection PhpRedundantOptionalArgumentInspection
 */

namespace phpbb\topicprefixes\tags;

use phpbb\db\driver\driver_interface;

/**
 * Topic tag definition and forum availability manager.
 */
class manager
{
	const DEFAULT_COLOR = '4A76A8';

	/** @var driver_interface */
	protected $db;

	/** @var string Tag definition table */
	protected $tags_table;

	/** @var string Forum/tag map table */
	protected $forums_map_table;

	/** @var string Topic/tag map table */
	protected $topic_map_table;

	/** @var string Forums table */
	protected $forums_table;

	/**
	 * Constructor.
	 *
	 * @param driver_interface $db               Database connection
	 * @param string           $tags_table       Tag definition table
	 * @param string           $forums_map_table Forum/tag map table
	 * @param string           $topic_map_table  Topic/tag map table
	 * @param string           $forums_table     Forums table
	 */
	public function __construct(driver_interface $db, $tags_table, $forums_map_table, $topic_map_table, $forums_table)
	{
		$this->db = $db;
		$this->tags_table = $tags_table;
		$this->forums_map_table = $forums_map_table;
		$this->topic_map_table = $topic_map_table;
		$this->forums_table = $forums_table;
	}

	/**
	 * Get one tag and its available forums.
	 *
	 * @param int $tag_id Tag identifier
	 * @return array|false Tag data, or false when missing
	 */
	public function get_tag(int $tag_id)
	{
		$sql = 'SELECT *
			FROM ' . $this->tags_table . '
			WHERE prefix_id = ' . (int) $tag_id;
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if ($row)
		{
			$row['forum_ids'] = $this->get_forum_ids($row['prefix_id']);
		}

		return $row;
	}

	/**
	 * Get every tag in display order.
	 *
	 * @return array Tags keyed by identifier
	 */
	public function get_tags(): array
	{
		$sql = 'SELECT *
			FROM ' . $this->tags_table . '
			ORDER BY prefix_order ASC, prefix_id ASC';
		$result = $this->db->sql_query($sql);
		$tags = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$tags[(int) $row['prefix_id']] = $row;
		}
		$this->db->sql_freeresult($result);

		return $tags;
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
		$sql = 'SELECT p.*
			FROM ' . $this->tags_table . ' p
			INNER JOIN ' . $this->forums_map_table . ' pf
				ON pf.prefix_id = p.prefix_id
			WHERE pf.forum_id = ' . (int) $forum_id .
			($enabled_only ? ' AND p.prefix_enabled = 1' : '') . '
			ORDER BY p.prefix_order ASC, p.prefix_id ASC';
		$result = $this->db->sql_query($sql);
		$tags = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$tags[(int) $row['prefix_id']] = $row;
		}
		$this->db->sql_freeresult($result);

		return $tags;
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

		$sql = 'SELECT p.*
			FROM ' . $this->tags_table . ' p
			INNER JOIN ' . $this->forums_map_table . ' pf
				ON pf.prefix_id = p.prefix_id
			WHERE pf.forum_id = ' . (int) $forum_id . '
				AND p.prefix_enabled = 1
				AND ' . $this->db->sql_in_set('p.prefix_id', $tag_ids) . '
			ORDER BY p.prefix_order ASC, p.prefix_id ASC';
		$result = $this->db->sql_query($sql);
		$tags = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$tags[(int) $row['prefix_id']] = $row;
		}
		$this->db->sql_freeresult($result);

		return $tags;
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
		$name = trim($name);
		$color = $this->normalize_color($color);
		if ($name === '' || $color === '')
		{
			return false;
		}

		$sql = 'SELECT MAX(prefix_order) AS max_order FROM ' . $this->tags_table;
		$result = $this->db->sql_query($sql);
		$max_order = (int) $this->db->sql_fetchfield('max_order');
		$this->db->sql_freeresult($result);

		$this->db->sql_transaction('begin');
		$sql = 'INSERT INTO ' . $this->tags_table . ' ' . $this->db->sql_build_array('INSERT', [
			'prefix_tag' => $name,
			'prefix_color' => $color,
			'prefix_enabled' => (int) (bool) $enabled,
			'prefix_order' => $max_order + 1,
		]);
		$this->db->sql_query($sql);
		$tag_id = (int) $this->db->sql_nextid();
		$this->replace_forums($tag_id, $forum_ids);
		$this->db->sql_transaction('commit');

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
		$name = trim($name);
		$color = $this->normalize_color($color);
		if (!$tag_id || $name === '' || $color === '' || !$this->tag_exists($tag_id))
		{
			return false;
		}

		$this->db->sql_transaction('begin');
		$sql = 'UPDATE ' . $this->tags_table . '
			SET ' . $this->db->sql_build_array('UPDATE', [
				'prefix_tag' => $name,
				'prefix_color' => $color,
				'prefix_enabled' => (int) (bool) $enabled,
			]) . '
			WHERE prefix_id = ' . $tag_id;
		$this->db->sql_query($sql);
		$this->replace_forums($tag_id, $forum_ids);
		$this->db->sql_transaction('commit');

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

		return true;
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

		$this->db->sql_transaction('begin');
		$sql = 'UPDATE ' . $this->tags_table . '
			SET prefix_order = CASE prefix_id
				WHEN ' . $current_id . ' THEN ' . $target_order . '
				WHEN ' . $target_id . ' THEN ' . $current_order . '
			END
			WHERE ' . $this->db->sql_in_set('prefix_id', [$current_id, $target_id]);
		$this->db->sql_query($sql);
		$this->db->sql_transaction('commit');

		return true;
	}

	/**
	 * Get forum identifiers assigned to one tag.
	 *
	 * @param int $tag_id Tag identifier
	 * @return array Forum identifiers
	 */
	public function get_forum_ids(int $tag_id): array
	{
		$sql = 'SELECT forum_id
			FROM ' . $this->forums_map_table . '
			WHERE prefix_id = ' . (int) $tag_id . '
			ORDER BY forum_id ASC';
		$result = $this->db->sql_query($sql);
		$forum_ids = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$forum_ids[] = (int) $row['forum_id'];
		}
		$this->db->sql_freeresult($result);

		return $forum_ids;
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
			ORDER BY f.left_id ASC';
		$result = $this->db->sql_query($sql);
		$forums = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$forums[(int) $row['prefix_id']][] = $row['forum_name'];
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
	public function normalize_color(string $color): string
	{
		$color = strtoupper(ltrim(trim($color), '#'));
		return preg_match('/^[0-9A-F]{6}$/D', $color) ? $color : '';
	}

	/**
	 * Check whether tag exists.
	 *
	 * @param int $tag_id Tag identifier
	 * @return bool
	 */
	protected function tag_exists(int $tag_id): bool
	{
		$sql = 'SELECT prefix_id FROM ' . $this->tags_table . ' WHERE prefix_id = ' . (int) $tag_id;
		$result = $this->db->sql_query($sql);
		$exists = $this->db->sql_fetchfield('prefix_id') !== false;
		$this->db->sql_freeresult($result);
		return $exists;
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
