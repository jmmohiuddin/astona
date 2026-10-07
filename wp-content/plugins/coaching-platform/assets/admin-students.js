/**
 * Astona > Students: live search without a page reload. The plain GET form keeps working without JavaScript; this
 * script only upgrades it. Rows come from admin-ajax (action cc_students_search) as server-rendered, fully escaped
 * `<tr>` markup of the same table class as the page; they are parsed with DOMParser (an inert document, scripts never
 * run) and the nodes are moved into the table body. Everything else is written with textContent.
 */
(function () {
  'use strict';

  var cfg = window.ccStudents;
  var form = document.getElementById('cc-students-filter');
  var list = document.getElementById('the-list');
  var status = document.getElementById('cc-students-status');
  var pager = document.getElementById('cc-students-pager');
  if (!cfg || !form || !list || !status || !pager || !window.fetch || !window.AbortController) {
    return;
  }

  var DEBOUNCE_MS = 250;
  var FIELDS = ['s', 'batch_id', 'status', 'payment_state'];
  var timer = null;
  var controller = null;
  var current = { page: 1, pages: 1 };
  var params = new URLSearchParams(window.location.search);

  document.querySelectorAll('.tablenav-pages').forEach(function (el) {
    el.hidden = true;
  });

  function filterValues() {
    var values = {};
    FIELDS.forEach(function (name) {
      var el = form.elements[name];
      values[name] = el ? el.value.trim() : '';
    });
    return values;
  }

  function syncUrlAndLinks(values, page) {
    var url = new URL(window.location.href);
    FIELDS.forEach(function (name) {
      if (values[name] && values[name] !== '0') {
        url.searchParams.set(name, values[name]);
      } else {
        url.searchParams.delete(name);
      }
    });
    if (page > 1) {
      url.searchParams.set('paged', String(page));
    } else {
      url.searchParams.delete('paged');
    }
    window.history.replaceState(null, '', url.toString());

    var exportLink = document.getElementById('cc-students-export');
    if (exportLink) {
      var link = new URL(exportLink.href);
      FIELDS.forEach(function (name) {
        if (values[name] && values[name] !== '0') {
          link.searchParams.set(name, values[name]);
        } else {
          link.searchParams.delete(name);
        }
      });
      exportLink.href = link.toString();
    }
    var scope = document.querySelector('#cc-students-bulk input[name="scope_batch_id"]');
    if (scope) {
      scope.value = values.batch_id && values.batch_id !== '0' ? values.batch_id : '0';
    }
  }

  function replaceRows(html) {
    var doc = new DOMParser().parseFromString('<table><tbody>' + html + '</tbody></table>', 'text/html');
    var rows = Array.prototype.slice.call(doc.querySelectorAll('tbody > tr'));
    list.textContent = '';
    rows.forEach(function (row) {
      list.appendChild(document.importNode(row, true));
    });
    document.querySelectorAll('.wp-list-table .check-column input[type="checkbox"]').forEach(function (box) {
      box.checked = false;
    });
  }

  function pagerButton(label, page, disabled) {
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'button';
    button.textContent = label;
    button.disabled = disabled;
    if (!disabled) {
      button.addEventListener('click', function () {
        run(page, true);
      });
    }
    return button;
  }

  function renderPager(page, pages) {
    pager.textContent = '';
    pager.hidden = pages <= 1;
    if (pages <= 1) {
      return;
    }
    var label = document.createElement('span');
    label.className = 'cc-students-pager__label';
    label.textContent = 'Page ' + page + ' of ' + pages;
    pager.appendChild(pagerButton('Previous', page - 1, page <= 1));
    pager.appendChild(label);
    pager.appendChild(pagerButton('Next', page + 1, page >= pages));
  }

  function run(page, immediate) {
    window.clearTimeout(timer);
    var go = function () {
      if (controller) {
        controller.abort();
      }
      controller = new AbortController();
      var values = filterValues();
      var query = new URLSearchParams({ action: cfg.action, nonce: cfg.nonce, page: String(page) });
      FIELDS.forEach(function (name) {
        if (values[name]) {
          query.set(name === 'batch_id' ? 'batch' : name, values[name]);
        }
      });
      ['orderby', 'order'].forEach(function (name) {
        if (params.get(name)) {
          query.set(name, params.get(name));
        }
      });
      fetch(cfg.ajaxUrl + '?' + query.toString(), { credentials: 'same-origin', signal: controller.signal })
        .then(function (response) {
          if (!response.ok) {
            throw new Error('http ' + response.status);
          }
          return response.json();
        })
        .then(function (payload) {
          if (!payload || !payload.success) {
            throw new Error('rejected');
          }
          var data = payload.data;
          replaceRows(String(data.rows_html));
          current = { page: Number(data.page), pages: Number(data.pages) };
          renderPager(current.page, current.pages);
          syncUrlAndLinks(values, current.page);
          var total = Number(data.total);
          status.textContent = total + (total === 1 ? ' student found' : ' students found');
        })
        .catch(function (error) {
          if (error && error.name === 'AbortError') {
            return;
          }
          status.textContent = 'The search failed. Press Filter to reload the list.';
        });
    };
    if (immediate) {
      go();
    } else {
      timer = window.setTimeout(go, DEBOUNCE_MS);
    }
  }

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    run(1, true);
  });
  form.addEventListener('input', function (event) {
    if (event.target && event.target.name === 's') {
      run(1, false);
    }
  });
  form.addEventListener('change', function (event) {
    if (event.target && event.target.tagName === 'SELECT') {
      run(1, true);
    }
  });

  var initialPage = parseInt(params.get('paged') || '1', 10);
  var initialPages = parseInt(status.getAttribute('data-pages') || '1', 10);
  current = { page: isNaN(initialPage) ? 1 : initialPage, pages: initialPages };
  renderPager(current.page, current.pages);
})();
