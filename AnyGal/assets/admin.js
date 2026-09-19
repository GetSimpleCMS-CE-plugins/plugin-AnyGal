/* AnyGal admin: template editors, tabs and the images manager.
   AnyGal.php prints this file inline, so it must never contain the closing script tag. */
(function () {
	'use strict';

	var root = document.getElementById('anygal-root');
	if (!root) { return; }

	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) { e.className = cls; }
		if (text !== undefined && text !== null) { e.textContent = text; }
		return e;
	}

	function button(cls, text, title) {
		var b = el('button', cls, text);
		b.type = 'button';
		if (title) { b.title = title; }
		return b;
	}

	/* ---------- code editors + tabs ---------- */

	var editors = [];
	var tabs = Array.prototype.slice.call(root.querySelectorAll('.anygal-tab'));
	var panels = Array.prototype.slice.call(root.querySelectorAll('.anygal-panel'));

	function showTab(name) {
		panels.forEach(function (p) { p.hidden = (p.getAttribute('data-panel') !== name); });
		tabs.forEach(function (t) { t.classList.toggle('active', t.getAttribute('data-tab') === name); });
		if (name === 'templates') { editors.forEach(function (cm) { cm.refresh(); }); }
	}

	tabs.forEach(function (t) {
		t.addEventListener('click', function () { showTab(t.getAttribute('data-tab')); });
	});
	showTab(root.getAttribute('data-start') || 'templates');

	window.addEventListener('load', function () {
		if (typeof CodeMirror === 'undefined') { return; }
		['anygal-wrapper', 'anygal-item'].forEach(function (id) {
			var ta = document.getElementById(id);
			if (!ta) { return; }
			editors.push(CodeMirror.fromTextArea(ta, {
				theme: 'blackboard',
				lineNumbers: true,
				matchBrackets: true,
				indentUnit: 4,
				indentWithTabs: true,
				lineWrapping: true,
				mode: 'htmlmixed'
			}));
		});
		editors.forEach(function (cm) { cm.refresh(); });
	});

	/* ---------- images manager ---------- */

	var box = document.getElementById('anygal-images');
	if (box) { initImages(box); }

	function initImages(box) {
		var L = JSON.parse(box.getAttribute('data-lang'));
		var cfg = {
			url: box.getAttribute('data-ajax'),
			nonce: box.getAttribute('data-nonce'),
			gallery: box.getAttribute('data-gallery'),
			hasData: box.getAttribute('data-has-data') === '1',
			maxBytes: parseInt(box.getAttribute('data-max'), 10) || 0
		};
		var list = box.querySelector('.anygal-img-list');
		var empty = box.querySelector('.anygal-img-empty');
		var errBox = box.querySelector('.anygal-img-error');
		var countEls = root.querySelectorAll('.anygal-tab-count');
		var lastOrder = '';

		var chain = Promise.resolve();

		function toFormData(obj) {
			var fd = new FormData();
			Object.keys(obj || {}).forEach(function (k) { fd.append(k, obj[k]); });
			return fd;
		}

		function api(action, fields, isGet) {
			var run = function () {
				var opts = { credentials: 'same-origin' };
				if (!isGet) {
					var fd = (fields instanceof FormData) ? fields : toFormData(fields);
					fd.append('nonce', cfg.nonce);
					opts.method = 'POST';
					opts.body = fd;
				}
				return fetch(cfg.url + '&anygal_action=' + encodeURIComponent(action), opts)
					.then(function (r) {
						return r.json().catch(function () { throw new Error(L.ajax_error); });
					})
					.then(function (d) {
						if (d && d.nonce) { cfg.nonce = d.nonce; }
						if (!d || !d.ok) { throw new Error((d && d.message) || L.ajax_error); }
						return d;
					});
			};
			var p = chain.then(run);
			chain = p.catch(function () {});
			return p;
		}

		function showError(msg) {
			errBox.textContent = msg ? '✕ ' + msg : '';
			errBox.hidden = !msg;
		}

		function currentOrder() {
			return Array.prototype.map.call(list.children, function (r) { return r.getAttribute('data-id'); });
		}

		function refresh() {
			var n = list.children.length;
			empty.hidden = n > 0;
			Array.prototype.forEach.call(countEls, function (c) { c.textContent = '(' + n + ')'; });
			lastOrder = currentOrder().join(',');
		}

		function saveOrder() {
			var order = currentOrder();
			if (order.join(',') === lastOrder) { return; }
			lastOrder = order.join(',');
			api('reorder', { gallery: cfg.gallery, order: JSON.stringify(order) })
				.then(function () { showError(''); })
				.catch(function (e) { showError(e.message); });
		}

		/* ----- one image row ----- */

		function addRow(img) {
			var row = el('div', 'anygal-row');
			row.setAttribute('data-id', img.id);

			var handle = el('span', 'anygal-handle', '⠿');
			handle.title = L.drag;

			var thumb = el('img', 'anygal-row-thumb');
			thumb.src = img.thumb;
			thumb.alt = '';
			thumb.draggable = false;
			thumb.loading = 'lazy';

			var main = el('div', 'anygal-row-main');
			var fname = el('div', 'anygal-row-file', img.file);
			main.appendChild(fname);

			var dataInput = null;
			if (cfg.hasData) {
				dataInput = el('input');
				dataInput.type = 'text';
				dataInput.maxLength = 500;
				dataInput.placeholder = L.data_field || 'Data / Link';
				dataInput.value = img.data || '';
				main.appendChild(dataInput);
			}

			var title = el('input');
			title.type = 'text';
			title.maxLength = 300;
			title.placeholder = L.title;
			title.value = img.title || '';
			var desc = el('textarea');
			desc.rows = 2;
			desc.placeholder = L.desc;
			desc.value = img.desc || '';
			var status = el('span', 'anygal-row-status');

			main.appendChild(title);
			main.appendChild(desc);
			main.appendChild(status);

			var tools = el('div', 'anygal-row-tools');
			var up = button('', '▲', L.move_up);
			var down = button('', '▼', L.move_down);
			var del = button('danger', '✕', L.remove);
			tools.appendChild(up);
			tools.appendChild(down);
			tools.appendChild(del);

			row.appendChild(handle);
			row.appendChild(thumb);
			row.appendChild(main);
			row.appendChild(tools);
			list.appendChild(row);

			var timer = null;
			function saveFields() {
				status.style.color = '';
				status.textContent = L.saving;
				var payload = {
					gallery: cfg.gallery,
					id: img.id,
					title: title.value,
					desc: desc.value
				};
				if (cfg.hasData && dataInput) {
					payload.data = dataInput.value;
				}
				api('update_image', payload)
					.then(function (d) {
						if (dataInput && d.data !== undefined) { dataInput.value = d.data; }
						title.value = d.title;
						desc.value = d.desc;
						status.textContent = '✓ ' + L.saved;
						showError('');
						clearTimeout(timer);
						timer = setTimeout(function () { status.textContent = ''; }, 1800);
					})
					.catch(function (e) {
						status.textContent = '';
						showError(e.message);
					});
			}

			if (dataInput) {
				dataInput.addEventListener('change', saveFields);
			}
			title.addEventListener('change', saveFields);
			desc.addEventListener('change', saveFields);

			up.addEventListener('click', function () {
				var prev = row.previousElementSibling;
				if (prev) { list.insertBefore(row, prev); saveOrder(); }
			});
			down.addEventListener('click', function () {
				var next = row.nextElementSibling;
				if (next) { list.insertBefore(next, row); saveOrder(); }
			});
			del.addEventListener('click', function () {
				if (!window.confirm(L.confirm_remove)) { return; }
				api('remove_image', { gallery: cfg.gallery, id: img.id })
					.then(function () { row.remove(); refresh(); showError(''); })
					.catch(function (e) { showError(e.message); });
			});
		}

		/* ----- drag to reorder ----- */

		var dragging = null;

		list.addEventListener('mousedown', function (e) {
			var h = e.target.closest ? e.target.closest('.anygal-handle') : null;
			if (h) { h.closest('.anygal-row').draggable = true; }
		});
		list.addEventListener('dragstart', function (e) {
			var row = e.target.closest ? e.target.closest('.anygal-row') : null;
			if (!row || !row.draggable) { return; }
			dragging = row;
			row.classList.add('dragging');
			e.dataTransfer.effectAllowed = 'move';
			e.dataTransfer.setData('text/plain', row.getAttribute('data-id'));
		});
		list.addEventListener('dragover', function (e) {
			if (!dragging) { return; }
			e.preventDefault();
			var after = null;
			var rows = list.querySelectorAll('.anygal-row:not(.dragging)');
			for (var i = 0; i < rows.length; i++) {
				var r = rows[i].getBoundingClientRect();
				if (e.clientY < r.top + r.height / 2) { after = rows[i]; break; }
			}
			if (after) { list.insertBefore(dragging, after); } else { list.appendChild(dragging); }
		});
		list.addEventListener('drop', function (e) { if (dragging) { e.preventDefault(); } });
		list.addEventListener('dragend', function () {
			if (!dragging) { return; }
			dragging.classList.remove('dragging');
			dragging.draggable = false;
			dragging = null;
			saveOrder();
		});

		/* ----- image browser + uploader ----- */

		function openPicker() {
			var state = { files: [], path: '', query: '', selected: {}, count: 0 };

			var overlay = el('div', 'anygal-modal');
			var dlg = el('div', 'anygal-dialog');
			overlay.appendChild(dlg);

			var head = el('div', 'anygal-dlg-head');
			head.appendChild(el('strong', '', L.add_images));
			var closeBtn = button('anygal-x', '×', L.close);
			head.appendChild(closeBtn);

			var tabBar = el('div', 'anygal-dlg-tabs');
			var tBrowse = button('anygal-dlg-tab active', L.browse);
			var tUpload = button('anygal-dlg-tab', L.upload);
			tabBar.appendChild(tBrowse);
			tabBar.appendChild(tUpload);

			var browse = el('div', 'anygal-dlg-pane');
			var bar = el('div', 'anygal-dlg-bar');
			var crumbs = el('div', 'anygal-crumbs');
			var search = el('input', 'anygal-search');
			search.type = 'search';
			search.placeholder = L.search;
			bar.appendChild(crumbs);
			bar.appendChild(search);
			var grid = el('div', 'anygal-grid');
			browse.appendChild(bar);
			browse.appendChild(grid);

			var upPane = el('div', 'anygal-dlg-pane');
			upPane.hidden = true;
			var drop = el('label', 'anygal-drop');
			drop.appendChild(el('span', '', '⬆️ ' + L.choose_files));
			drop.appendChild(el('small', '', L.upload_hint));
			var fileInput = el('input');
			fileInput.type = 'file';
			fileInput.multiple = true;
			fileInput.accept = 'image/jpeg,image/png,image/gif,image/webp';
			drop.appendChild(fileInput);
			var upStatus = el('div', 'anygal-up-status');
			upPane.appendChild(drop);
			upPane.appendChild(upStatus);

			var foot = el('div', 'anygal-dlg-foot');
			var info = el('span', 'anygal-dlg-info');
			var cancel = button('anygal-btn anygal-btn-cancel', L.cancel);
			var addBtn = button('anygal-btn', L.add_selected);
			addBtn.disabled = true;
			foot.appendChild(info);
			foot.appendChild(cancel);
			foot.appendChild(addBtn);

			dlg.appendChild(head);
			dlg.appendChild(tabBar);
			dlg.appendChild(browse);
			dlg.appendChild(upPane);
			dlg.appendChild(foot);
			document.body.appendChild(overlay);

			function close() {
				document.removeEventListener('keydown', onKey);
				overlay.remove();
			}
			function onKey(e) { if (e.key === 'Escape') { close(); } }
			document.addEventListener('keydown', onKey);
			closeBtn.addEventListener('click', close);
			cancel.addEventListener('click', close);
			overlay.addEventListener('mousedown', function (e) { if (e.target === overlay) { close(); } });

			function setTab(name) {
				var isBrowse = (name === 'browse');
				browse.hidden = !isBrowse;
				upPane.hidden = isBrowse;
				tBrowse.classList.toggle('active', isBrowse);
				tUpload.classList.toggle('active', !isBrowse);
			}
			tBrowse.addEventListener('click', function () { setTab('browse'); });
			tUpload.addEventListener('click', function () { setTab('upload'); });

			function updateFoot() {
				addBtn.disabled = state.count === 0;
				addBtn.textContent = L.add_selected + (state.count ? ' (' + state.count + ')' : '');
			}

			function toggle(file, tile) {
				if (state.selected[file]) { delete state.selected[file]; state.count--; } else { state.selected[file] = true; state.count++; }
				if (tile) { tile.classList.toggle('selected', !!state.selected[file]); }
				updateFoot();
			}

			function renderCrumbs() {
				crumbs.textContent = '';
				function crumb(label, path) {
					var b = button('anygal-crumb', label);
					b.addEventListener('click', function () {
						state.path = path;
						state.query = '';
						search.value = '';
						renderGrid();
					});
					return b;
				}
				crumbs.appendChild(crumb(L.uploads_root, ''));
				var acc = '';
				(state.path ? state.path.split('/') : []).forEach(function (part) {
					acc = acc ? acc + '/' + part : part;
					crumbs.appendChild(el('span', 'anygal-sep', '/'));
					crumbs.appendChild(crumb(part, acc));
				});
			}

			function fileTile(f, label) {
				var b = button('anygal-tile' + (state.selected[f.file] ? ' selected' : ''), '', f.file);
				var img = el('img');
				img.src = f.thumb;
				img.alt = '';
				img.loading = 'lazy';
				b.appendChild(img);
				b.appendChild(el('span', 'anygal-tile-name', label));
				b.appendChild(el('span', 'anygal-tick', '✓'));
				b.addEventListener('click', function () { toggle(f.file, b); });
				return b;
			}

			function folderTile(name, path, n) {
				var b = button('anygal-tile anygal-tile-folder', '', name);
				
				// Create the icon container
				var icon = el('span', 'anygal-folder-icon');
				icon.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="44" height="44" viewBox="0 0 24 24" fill="#FFAE34"><path d="M10 4H4c-1.11 0-2 .89-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-8l-2-2z"/></svg>';
				
				b.appendChild(icon);
				b.appendChild(el('span', '', name + ' (' + n + ')'));
				b.addEventListener('click', function () { state.path = path; renderGrid(); });
				return b;
			}

			function renderGrid() {
				renderCrumbs();
				grid.textContent = '';
				var q = state.query.trim().toLowerCase();
				var shown = 0;

				if (q) {
					state.files.forEach(function (f) {
						if (f.file.toLowerCase().indexOf(q) !== -1) { grid.appendChild(fileTile(f, f.file)); shown++; }
					});
				} else {
					var prefix = state.path ? state.path + '/' : '';
					var dirs = {};
					var dirOrder = [];
					var here = [];
					state.files.forEach(function (f) {
						if (f.file.indexOf(prefix) !== 0) { return; }
						var rest = f.file.slice(prefix.length);
						var i = rest.indexOf('/');
						if (i === -1) { here.push(f); return; }
						var d = rest.slice(0, i);
						if (!dirs[d]) { dirs[d] = 0; dirOrder.push(d); }
						dirs[d]++;
					});
					dirOrder.sort().forEach(function (d) {
						grid.appendChild(folderTile(d, prefix + d, dirs[d]));
						shown++;
					});
					here.forEach(function (f) {
						grid.appendChild(fileTile(f, f.file.split('/').pop()));
						shown++;
					});
				}
				if (!shown) { grid.appendChild(el('p', '', L.empty_folder)); }
			}

			search.addEventListener('input', function () { state.query = search.value; renderGrid(); });

			function loadFiles() {
				grid.textContent = '…';
				api('list_uploads', null, true)
					.then(function (d) { state.files = d.files || []; renderGrid(); search.focus(); })
					.catch(function (e) { grid.textContent = ''; grid.appendChild(el('p', 'anygal-err', e.message)); });
			}

			function uploadFiles(fileList) {
				var files = Array.prototype.slice.call(fileList);
				if (!files.length) { return; }
				upStatus.textContent = '';
				var uploadTarget = state.path;
				var jobs = files.map(function (file) {
					var line = el('div', 'anygal-up-line', '… ' + file.name);
					upStatus.appendChild(line);
					if (cfg.maxBytes && file.size > cfg.maxBytes) {
						line.textContent = '✕ ' + file.name + ' - ' + L.too_large;
						line.className += ' err';
						return Promise.resolve();
					}
					var fd = new FormData();
					fd.append('file[]', file);
					if (uploadTarget) {
						fd.append('folder', uploadTarget);
					}
					return api('upload', fd)
						.then(function (d) {
							(d.files || []).forEach(function (f) {
								state.files.unshift(f);
								if (!state.selected[f.file]) { state.selected[f.file] = true; state.count++; }
							});
							if (d.errors && d.errors.length) {
								line.textContent = '✕ ' + d.errors.join(' ');
								line.className += ' err';
							} else {
								line.textContent = '✓ ' + file.name;
								line.className += ' ok';
							}
						})
						.catch(function (e) {
							line.textContent = '✕ ' + file.name + ' - ' + e.message;
							line.className += ' err';
						});
				});
				Promise.all(jobs).then(function () {
					updateFoot();
					state.query = '';
					search.value = '';
					renderGrid();
					if (!upStatus.querySelector('.err') && state.count) { setTab('browse'); }
				});
			}

			fileInput.addEventListener('change', function () {
				uploadFiles(fileInput.files);
				fileInput.value = '';
			});
			['dragenter', 'dragover'].forEach(function (ev) {
				drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('over'); });
			});
			['dragleave', 'drop'].forEach(function (ev) {
				drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('over'); });
			});
			drop.addEventListener('drop', function (e) {
				if (e.dataTransfer && e.dataTransfer.files) { uploadFiles(e.dataTransfer.files); }
			});

			addBtn.addEventListener('click', function () {
				var files = Object.keys(state.selected);
				if (!files.length) { return; }
				addBtn.disabled = true;
				info.className = 'anygal-dlg-info';
				info.textContent = L.saving;
				api('add_images', { gallery: cfg.gallery, files: JSON.stringify(files) })
					.then(function (d) {
						(d.images || []).forEach(addRow);
						refresh();
						showError('');
						close();
					})
					.catch(function (e) {
						addBtn.disabled = false;
						info.className = 'anygal-dlg-info err';
						info.textContent = e.message;
					});
			});

			updateFoot();
			loadFiles();
		}

		/* ----- start ----- */

		var initial = [];
		try { initial = JSON.parse(box.getAttribute('data-images')) || []; } catch (e) { initial = []; }
		initial.forEach(addRow);
		refresh();
		box.querySelector('.anygal-img-add').addEventListener('click', openPicker);
	}
})();