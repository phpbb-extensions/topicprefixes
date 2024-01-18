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
	/**
	 * @var nestedset_prefixes
	 */
	protected $nestedset;

	/**
	 * Constructor
	 *
	 * @param nestedset_prefixes $nestedset
	 */
	public function __construct(nestedset_prefixes $nestedset)
	{
		$this->nestedset = $nestedset;
	}

	/**
	 * @inheritdoc
	 */
	public function get_prefix($id)
	{
		$prefix = $this->nestedset->get_subtree_data($id);

		return count($prefix) ? $prefix[$id] : false;
	}

	/**
	 * @inheritdoc
	 */
	public function get_prefixes($forum_id = 0)
	{
		if ($forum_id)
		{
			$this->nestedset->where_forum_id($forum_id);
		}

		return $this->nestedset->get_all_tree_data();
	}

	/**
	 * @inheritdoc
	 */
	public function get_active_prefixes($forum_id = 0)
	{
		return array_filter($this->get_prefixes($forum_id), [$this, 'is_enabled']);
	}

	/**
	 * @inheritdoc
	 */
	public function add_prefix($tag, $forum_id)
	{
		$data = [
			'prefix_tag'		=> $tag,
			'forum_id'		=> (int) $forum_id,
			'prefix_enabled'	=> true,
		];

		return $tag !== '' ? $this->nestedset->insert($data) : false;
	}

	/**
	 * @inheritdoc
	 */
	public function delete_prefix($id)
	{
		return $this->nestedset->delete($id);
	}

	/**
	 * @inheritdoc
	 */
	public function update_prefix($id, array $data)
	{
		return $this->nestedset->update_item($id, $data);
	}

	/**
	 * @inheritdoc
	 */
	public function move_prefix($id, $direction = 'up', $amount = 1)
	{
		$amount = (int) $amount;

		$this->nestedset->move($id, ($direction !== 'up' ? -$amount : $amount));
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
	public function prepend_prefix($prefix, $subject)
	{
		if ($prefix && strpos($subject, $prefix) !== 0)
		{
			$subject = $prefix . ' ' . $subject;
		}

		return $subject;
	}
}
