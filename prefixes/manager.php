<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2016 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\topicprefixes\prefixes;

class manager implements manager_interface
{
	/** @var int Forum identifier */
	protected $forum_id;

	/** @var \phpbb\topicprefixes\prefixes\nestedset_prefixes  */
	protected $nestedset;

	/**
	 * Listener constructor
	 *
	 * @param \phpbb\topicprefixes\prefixes\nestedset_prefixes $nestedset
	 */
	public function __construct(\phpbb\topicprefixes\prefixes\nestedset_prefixes $nestedset)
	{
		$this->nestedset = $nestedset;
	}

	/**
	 * @inheritdoc
	 */
	public function get_prefixes($parent_id = 0)
	{
		return $parent_id ? $this->nestedset->get_subtree_data($parent_id) : $this->nestedset->get_all_tree_data();
	}

	/**
	 * @inheritdoc
	 */
	public function get_active_prefixes($forum_id = 0)
	{
		$prefixes = array_filter($this->get_prefixes(), [$this, 'is_enabled']);

		if ($forum_id)
		{
			$this->forum_id = $forum_id;
			$prefixes = array_filter($prefixes, [$this, 'filter_by_forum']);
		}

		$prefixes = $this->filter_empty_parents($prefixes);

		return $prefixes;
	}

	/**
	 * @inheritdoc
	 */
	public function add_prefix($tag, $forum_id)
	{
	}

	/**
	 * @inheritdoc
	 */
	public function edit_prefix($tag, $data)
	{
	}

	/**
	 * @inheritdoc
	 */
	public function delete_prefix($id)
	{
	}

	/**
	 * @inheritdoc
	 */
	public function is_enabled(array $row)
	{
		return $row['prefix_enabled'];
	}

	/**
	 * @inheritdoc
	 */
	public function is_parent(array $row)
	{
		return $row['prefix_right_id'] - $row['prefix_left_id'] > 1;
	}

	/**
	 * Filter prefixes by forum id
	 *
	 * @param array $row A row of topic prefix data
	 *
	 * @return bool True if prefix is in the given form, false otherwise
	 */
	protected function filter_by_forum(array $row)
	{
		// Check prefixes (prefixes have parents)
		if ($row['prefix_parent_id'])
		{
			$forum_ids = json_decode($row['forum_id'], true);
			if (is_array($forum_ids))
			{
				return in_array($this->forum_id, $forum_ids);
			}
		}
		// Prefix parents should also be returned true
		else if ($this->is_parent($row))
		{
			return true;
		}

		// Ignore prefix if it is not in the forum or is not a parent
		return false;
	}

	/**
	 * @param array $prefixes All topic prefixes
	 *
	 * @return array All topic prefixes with empty parents removed
	 */
	protected function filter_empty_parents(array $prefixes)
	{
		$last_parent_id = 0;

		foreach ($prefixes as $key => $prefix)
		{
			if (!$this->is_parent($prefix))
			{
				$last_parent_id = 0;
				continue;
			}

			if ($last_parent_id !== 0)
			{
				unset($prefixes[$last_parent_id]);
			}

			$last_parent_id = $key;
		}

		if ($last_parent_id !== 0)
		{
			unset($prefixes[$last_parent_id]);
		}

		reset($prefixes);

		return $prefixes;
	}
}
