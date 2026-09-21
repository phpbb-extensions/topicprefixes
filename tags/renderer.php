<?php
/**
 *
 * Topic Prefixes extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbb\topicprefixes\tags;

/**
 * Topic tag presentation helper.
 */
class renderer
{
	/** @var string phpBB root path */
	protected $root_path;

	/** @var string PHP extension */
	protected $php_ext;

	/** @var array Request-local foreground colors keyed by background */
	protected $contrast_colors = [];

	/**
	 * Constructor.
	 *
	 * @param string $root_path phpBB root path
	 * @param string $php_ext   PHP extension
	 */
	public function __construct($root_path, $php_ext)
	{
		$this->root_path = $root_path;
		$this->php_ext = $php_ext;
	}

	/**
	 * Convert tag records into safe template data.
	 *
	 * @param array $tags        Tag records
	 * @param int   $forum_id    Forum identifier for filter URLs
	 * @param array $selected_ids Selected filter identifiers
	 * @param array $url_params  Sorting parameters to preserve
	 * @param bool  $toggle      Toggle selected IDs in generated URLs
	 * @return array Template tag rows
	 */
	public function render(array $tags, int $forum_id = 0, array $selected_ids = [], array $url_params = [], bool $toggle = false): array
	{
		$selected_ids = array_values(array_unique(array_map('intval', $selected_ids)));
		$selected = array_fill_keys($selected_ids, true);
		$rendered = [];
		foreach ($tags as $tag)
		{
			$tag_id = (int) $tag['prefix_id'];
			$is_selected = isset($selected[$tag_id]);
			$link_ids = $selected_ids;
			if ($toggle && $is_selected)
			{
				$link_ids = array_values(array_diff($link_ids, [$tag_id]));
			}
			else if (!isset($selected[$tag_id]))
			{
				$link_ids[] = $tag_id;
			}

			$rendered[] = [
				'TAG_ID' => $tag_id,
				'TAG_NAME' => utf8_htmlspecialchars($tag['prefix_tag']),
				'TAG_COLOR' => '#' . $tag['prefix_color'],
				'TAG_TEXT_COLOR' => $this->contrast_color($tag['prefix_color']),
				'S_SELECTED' => $is_selected,
				'U_FILTER' => $forum_id ? $this->filter_url($forum_id, $link_ids, $url_params) : '',
			];
		}

		return $rendered;
	}

	/**
	 * Select black or white text with the greatest WCAG contrast.
	 *
	 * @param string $hex Six-digit hexadecimal background color
	 * @return string Hexadecimal foreground color
	 */
	public function contrast_color(string $hex): string
	{
		$hex = strtoupper(ltrim($hex, '#'));
		if (isset($this->contrast_colors[$hex]))
		{
			return $this->contrast_colors[$hex];
		}
		$channels = [
			hexdec(substr($hex, 0, 2)) / 255,
			hexdec(substr($hex, 2, 2)) / 255,
			hexdec(substr($hex, 4, 2)) / 255,
		];
		foreach ($channels as $key => $channel)
		{
			$channels[$key] = $channel <= 0.03928 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
		}
		$luminance = 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
		$white_contrast = 1.05 / ($luminance + 0.05);
		$black_contrast = ($luminance + 0.05) / 0.05;

		return $this->contrast_colors[$hex] = $white_contrast >= $black_contrast ? '#FFFFFF' : '#000000';
	}

	/**
	 * Build viewforum URL for selected tags.
	 *
	 * @param int   $forum_id  Forum identifier
	 * @param array $tag_ids   Selected tag identifiers
	 * @param array $url_params Sorting parameters to preserve
	 * @return string Viewforum URL
	 */
	public function filter_url(int $forum_id, array $tag_ids, array $url_params = []): string
	{
		$params = ['f' => $forum_id];
		foreach (['st', 'sk', 'sd'] as $name)
		{
			if (isset($url_params[$name]) && $url_params[$name] !== '')
			{
				$params[$name] = $url_params[$name];
			}
		}
		$tag_ids = array_values(array_unique(array_filter(array_map('intval', $tag_ids))));
		if ($tag_ids)
		{
			sort($tag_ids, SORT_NUMERIC);
			$params['tags'] = implode(',', $tag_ids);
		}

		return append_sid($this->root_path . 'viewforum.' . $this->php_ext, http_build_query($params, '', '&amp;'));
	}
}
