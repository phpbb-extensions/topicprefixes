(() => {
	'use strict';

	const input = document.getElementById('topic-tag-search-input'),
		source = document.getElementById('topic-tag-search-catalog');
	if (!input || !source) {
		return;
	}

	const catalog = JSON.parse(source.textContent || '[]'),
		value = document.getElementById('topic-tag-search-value'),
		selectedBox = document.getElementById('topic-tag-search-selected'),
		options = document.getElementById('topic-tag-search-options'),
		match = document.getElementById('topic-tag-search-match'),
		combobox = input.parentNode,
		removeLabel = combobox.getAttribute('data-remove-label') || 'Remove';
	let selected = (value.value || '').split(',').filter((id) => {
		return /^\d+$/.test(id);
	}).map(Number),
		active = -1,
		visible = [];

	function findTag(id) {
		return catalog.find((tag) => tag[0] === id) || null;
	}

	function sync() {
		selectedBox.textContent = '';
		selected = selected.filter((id) => findTag(id) !== null);
		selected.forEach((id) => {
			const tag = findTag(id),
				chip = document.createElement('button');
			chip.type = 'button';
			chip.className = 'topic-tag topic-tag-selected topic-tag-search-chip';
			chip.style.backgroundColor = '#' + tag[2];
			chip.style.color = tag[4];
			chip.textContent = tag[1] + ' \u00d7';
			chip.setAttribute('aria-label', removeLabel + ' ' + tag[1]);
			chip.addEventListener('click', () => {
				selected = selected.filter((selectedId) => selectedId !== id);
				sync();
				render();
				input.focus();
			});
			selectedBox.appendChild(chip);
		});
		value.value = selected.join(',');
		match.hidden = selected.length < 2;
	}

	function choose(tag) {
		if (!selected.includes(tag[0])) {
			selected.push(tag[0]);
		}
		input.value = '';
		sync();
		render();
	}

	function close() {
		options.hidden = true;
		input.setAttribute('aria-expanded', 'false');
		input.removeAttribute('aria-activedescendant');
		active = -1;
	}

	function setActive(index) {
		active = index;
		Array.from(options.children).forEach((option, optionIndex) => {
			option.classList.toggle('active', optionIndex === active);
			option.setAttribute('aria-selected', optionIndex === active ? 'true' : 'false');
		});
		if (active >= 0 && options.children[active]) {
			input.setAttribute('aria-activedescendant', options.children[active].id);
			options.children[active].scrollIntoView({ block: 'nearest' });
		}
	}

	function render() {
		const query = input.value.trim().toLocaleLowerCase(),
			prefix = [],
			contains = [];
		catalog.forEach((tag) => {
			if (selected.includes(tag[0])) {
				return;
			}
			const position = tag[3].indexOf(query);
			if (position === 0) {
				prefix.push(tag);
			} else if (position > 0) {
				contains.push(tag);
			}
		});
		visible = prefix.concat(contains).slice(0, 20);
		options.textContent = '';
		visible.forEach((tag, index) => {
			const option = document.createElement('li'),
				badge = document.createElement('span');
			option.id = 'topic-tag-search-option-' + index;
			option.className = 'topic-tag-search-option';
			option.setAttribute('role', 'option');
			option.setAttribute('aria-selected', 'false');
			badge.className = 'topic-tag';
			badge.style.backgroundColor = '#' + tag[2];
			badge.style.color = tag[4];
			badge.textContent = tag[1];
			option.appendChild(badge);
			option.addEventListener('mousedown', (event) => {
				event.preventDefault();
				choose(tag);
			});
			options.appendChild(option);
		});
		active = -1;
		options.hidden = visible.length === 0;
		input.setAttribute('aria-expanded', visible.length ? 'true' : 'false');
	}

	input.addEventListener('focus', render);
	input.addEventListener('click', render);
	input.addEventListener('input', render);
	input.addEventListener('keydown', (event) => {
		if (event.key === 'ArrowDown' && visible.length) {
			event.preventDefault();
			setActive((active + 1) % visible.length);
		} else if (event.key === 'ArrowUp' && visible.length) {
			event.preventDefault();
			setActive(active <= 0 ? visible.length - 1 : active - 1);
		} else if (event.key === 'Enter' && active >= 0) {
			event.preventDefault();
			choose(visible[active]);
		} else if (event.key === 'Escape') {
			close();
		} else if (event.key === 'Backspace' && !input.value && selected.length) {
			selected.pop();
			sync();
			render();
		}
	});
	document.addEventListener('mousedown', (event) => {
		if (!combobox.contains(event.target)) {
			close();
		}
	});

	sync();
})();
