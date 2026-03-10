<?php
/**
 * Plugin Name: Roxy Will Call (WooCommerce)
 * Description: Will call list for WooCommerce products or Roxy Showings with check-in tracking, order links, dates, totals, revenue, attendance counters, and search.
 * Version: 0.3.2
 */

if (!defined('ABSPATH')) exit;

const ROXY_WC_CONTEXT_SHOWING_OFFSET = 1000000000;

function roxy_will_call_context_numeric_id(string $mode, int $id): int {
  return $mode === 'showing' ? (ROXY_WC_CONTEXT_SHOWING_OFFSET + $id) : $id;
}

function roxy_will_call_parse_id_list($raw): array {
  if (is_array($raw)) {
    $raw = implode("\n", array_map('intval', $raw));
  }
  $parts = preg_split('/[\r\n,]+/', (string) $raw);
  $ids = [];
  foreach ((array)$parts as $part) {
    $id = absint(trim((string)$part));
    if ($id > 0) $ids[$id] = $id;
  }
  return array_values($ids);
}

function roxy_will_call_showing_product_ids(int $showing_id): array {
  $ids = [];
  foreach (['adult','discount','matinee','live1','live2','subscriber'] as $type) {
    $pid = (int) get_post_meta($showing_id, '_roxy_pid_' . $type, true);
    if ($pid > 0) $ids[$pid] = $pid;
  }
  $legacy = roxy_will_call_parse_id_list(get_post_meta($showing_id, '_roxy_legacy_product_ids', true));
  foreach ($legacy as $pid) $ids[$pid] = $pid;
  return array_values($ids);
}

function roxy_will_call_showing_label(int $showing_id): string {
  $title = get_the_title($showing_id) ?: ('Showing #' . $showing_id);
  $start = get_post_meta($showing_id, '_roxy_start', true);
  $date = '';
  if ($start) {
    $ts = strtotime((string)$start);
    if ($ts) $date = ' — ' . wp_date('M j, Y g:ia', $ts);
  }
  return $title . $date . ' (#' . $showing_id . ')';
}

/**
 * Admin menu
 */
add_action('admin_menu', function () {
  if (!class_exists('WooCommerce')) return;

  add_submenu_page(
    'woocommerce',
    'Will Call',
    'Will Call',
    'manage_woocommerce',
    'roxy-will-call',
    'roxy_will_call_admin_page'
  );
});

/**
 * Activation: create check-in table
 */
register_activation_hook(__FILE__, function () {
  global $wpdb;

  $table = $wpdb->prefix . 'roxy_will_call_checkins';
  $charset_collate = $wpdb->get_charset_collate();

  $sql = "CREATE TABLE IF NOT EXISTS $table (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id BIGINT UNSIGNED NOT NULL,
    customer_key VARCHAR(190) NOT NULL,
    checked_in TINYINT(1) NOT NULL DEFAULT 0,
    used_qty INT NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY product_customer (product_id, customer_key)
  ) $charset_collate;";

  require_once ABSPATH . 'wp-admin/includes/upgrade.php';
  dbDelta($sql);
});

/**
 * Admin page
 */
function roxy_will_call_admin_page() {
  if (!current_user_can('manage_woocommerce')) {
    wp_die('Not allowed.');
  }

  if (!function_exists('wc_get_products')) {
    echo '<div class="notice notice-error"><p>WooCommerce functions not available. Is WooCommerce active?</p></div>';
    return;
  }

  $mode = isset($_GET['mode']) && $_GET['mode'] === 'product' ? 'product' : 'showing';
  $selected_product_id = isset($_GET['product_id']) ? absint($_GET['product_id']) : 0;
  $selected_showing_id = isset($_GET['showing_id']) ? absint($_GET['showing_id']) : 0;
  ?>
  <div class="wrap">
    <h1>Will Call</h1>

    <form method="get" style="margin: 12px 0; display:flex; gap:10px; align-items:end; flex-wrap:wrap;">
      <input type="hidden" name="page" value="roxy-will-call" />
      <div>
        <label for="mode"><strong>Mode</strong></label><br />
        <select name="mode" id="mode">
          <option value="showing" <?php selected($mode, 'showing'); ?>>Showing</option>
          <option value="product" <?php selected($mode, 'product'); ?>>Product</option>
        </select>
      </div>
      <div class="roxy-wc-mode roxy-wc-mode-showing" <?php if ($mode !== 'showing') echo 'style="display:none"'; ?>>
        <label for="showing_id"><strong>Select showing:</strong></label><br />
        <?php echo roxy_will_call_showing_dropdown($selected_showing_id); ?>
      </div>
      <div class="roxy-wc-mode roxy-wc-mode-product" <?php if ($mode !== 'product') echo 'style="display:none"'; ?>>
        <label for="product_id"><strong>Select ticket product:</strong></label><br />
        <?php echo roxy_will_call_product_dropdown($selected_product_id); ?>
      </div>
      <div>
        <button class="button button-primary">Load</button>
        <?php if (($mode === 'product' && $selected_product_id) || ($mode === 'showing' && $selected_showing_id)): ?>
          <button type="button" class="button" onclick="window.print()">Print</button>
        <?php endif; ?>
      </div>
    </form>

    <?php
      if ($mode === 'showing' && $selected_showing_id) {
        $result = roxy_will_call_get_showing_list($selected_showing_id);
        roxy_will_call_render_table('showing', $selected_showing_id, $result['rows'], $result['totals']);
      } elseif ($mode === 'product' && $selected_product_id) {
        $result = roxy_will_call_get_list([$selected_product_id]);
        roxy_will_call_render_table('product', $selected_product_id, $result['rows'], $result['totals']);
      } else {
        echo '<p>Select a showing or product to generate the list.</p>';
      }
    ?>
  </div>

  <style>
    @media print {
      #adminmenumain, #wpadminbar, .notice, .update-nag, .wrap form, .wrap .button, .roxy-wc-search-wrap { display:none !important; }
      .wrap { margin: 0; }
      table { font-size: 12px; }
      input[type="number"] { width: 60px; }
      a { text-decoration: none; color: #000; }
    }

    .roxy-wc-table input[type="number"] { width: 70px; }
    .roxy-wc-muted { color:#666; font-size: 12px; }
    .roxy-wc-saved { color: #0a7; font-weight: 600; display:none; margin-left: 8px; }

    .roxy-wc-summary {
      display: flex;
      gap: 18px;
      padding: 12px 14px;
      background: #fff;
      border: 1px solid #dcdcde;
      border-radius: 8px;
      margin: 10px 0 12px;
      align-items: center;
      flex-wrap: wrap;
    }
    .roxy-wc-summary .metric { min-width: 160px; }
    .roxy-wc-summary .label { font-size: 12px; color: #666; margin-bottom: 2px; }
    .roxy-wc-summary .value { font-size: 18px; font-weight: 700; }

    .roxy-wc-search-wrap {
      margin: 12px 0;
      background: #fff;
      border: 1px solid #dcdcde;
      border-radius: 8px;
      padding: 12px 14px;
    }
    .roxy-wc-search-wrap label {
      display: block;
      font-size: 12px;
      color: #666;
      margin-bottom: 6px;
    }
    .roxy-wc-search {
      width: 100%;
      max-width: 420px;
      padding: 8px 10px;
    }

    .roxy-wc-hidden {
      display: none !important;
    }
  </style>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const table = document.querySelector('.roxy-wc-table');
      const modeSelect = document.getElementById('mode');
      if (modeSelect) {
        modeSelect.addEventListener('change', () => {
          document.querySelectorAll('.roxy-wc-mode').forEach(el => el.style.display = 'none');
          const target = document.querySelector('.roxy-wc-mode-' + modeSelect.value);
          if (target) target.style.display = '';
        });
      }
      if (!table) return;

      const searchInput = document.querySelector('.roxy-wc-search');
      const checkedInEl = document.getElementById('roxy-wc-checked-in');
      const remainingEl = document.getElementById('roxy-wc-remaining');
      const soldEl = document.getElementById('roxy-wc-total-sold');

      function recalcAttendance() {
        let sold = 0;
        let checkedIn = 0;

        const rows = table.querySelectorAll('tbody tr[data-customer-key]');
        rows.forEach((tr) => {
          const qty = parseInt(tr.getAttribute('data-qty') || '0', 10);
          const usedInput = tr.querySelector('input.roxy-used');
          const usedQty = parseInt((usedInput && usedInput.value) || '0', 10);

          sold += qty;
          checkedIn += usedQty;
        });

        const remaining = Math.max(0, sold - checkedIn);

        if (soldEl) soldEl.textContent = sold.toLocaleString();
        if (checkedInEl) checkedInEl.textContent = checkedIn.toLocaleString();
        if (remainingEl) remainingEl.textContent = remaining.toLocaleString();
      }

      function applySearch() {
        if (!searchInput) return;
        const q = searchInput.value.trim().toLowerCase();
        const rows = table.querySelectorAll('tbody tr[data-customer-key]');

        rows.forEach((tr) => {
          const haystack = (tr.getAttribute('data-search') || '').toLowerCase();
          if (!q || haystack.includes(q)) {
            tr.classList.remove('roxy-wc-hidden');
          } else {
            tr.classList.add('roxy-wc-hidden');
          }
        });
      }

      if (searchInput) {
        searchInput.addEventListener('input', applySearch);
      }

      table.addEventListener('change', async (e) => {
        const tr = e.target.closest('tr[data-customer-key]');
        if (!tr) return;

        const contextId = table.getAttribute('data-context-id');
        const customerKey = tr.getAttribute('data-customer-key');
        const checked = tr.querySelector('input.roxy-checked').checked ? 1 : 0;
        const usedInput = tr.querySelector('input.roxy-used');
        const maxQty = parseInt(usedInput.getAttribute('max') || '0', 10);
        let usedQty = parseInt(usedInput.value || '0', 10);

        if (isNaN(usedQty) || usedQty < 0) usedQty = 0;
        if (usedQty > maxQty) usedQty = maxQty;
        usedInput.value = usedQty;

        const form = new FormData();
        form.append('action', 'roxy_will_call_save');
        form.append('nonce', table.getAttribute('data-nonce'));
        form.append('context_id', contextId);
        form.append('customer_key', customerKey);
        form.append('checked_in', checked);
        form.append('used_qty', usedQty);

        try {
          const res = await fetch(ajaxurl, { method: 'POST', body: form });
          const json = await res.json();
          if (json && json.success) {
            const badge = tr.querySelector('.roxy-wc-saved');
            badge.style.display = 'inline';
            setTimeout(()=> badge.style.display = 'none', 900);
            recalcAttendance();
          } else {
            alert('Save failed.');
          }
        } catch (err) {
          alert('Save error.');
        }
      });

      recalcAttendance();
      applySearch();
    });
  </script>
  <?php
}

/**
 * Product dropdown
 */
function roxy_will_call_product_dropdown($selected) {
  $products = wc_get_products([
    'limit' => 200,
    'status' => ['publish', 'private'],
    'orderby' => 'date',
    'order' => 'DESC',
    'return' => 'objects',
  ]);

  $html = '<select name="product_id" id="product_id" style="min-width:360px;">';
  $html .= '<option value="0">— Select —</option>';

  foreach ($products as $p) {
    $id = $p->get_id();
    $name = $p->get_name();
    $sel = selected($selected, $id, false);
    $html .= '<option value="' . esc_attr($id) . '" ' . $sel . '>' . esc_html($name . ' (#' . $id . ')') . '</option>';
  }

  $html .= '</select>';
  return $html;
}

/**
 * Showing dropdown
 */
function roxy_will_call_showing_dropdown($selected) {
  $posts = get_posts([
    'post_type' => 'roxy_showing',
    'post_status' => ['publish','private','draft','future'],
    'numberposts' => 200,
    'orderby' => 'meta_value',
    'meta_key' => '_roxy_start',
    'order' => 'ASC',
    'meta_query' => [[
      'key' => '_roxy_start',
      'compare' => 'EXISTS',
    ]],
  ]);

  $html = '<select name="showing_id" id="showing_id" style="min-width:420px;">';
  $html .= '<option value="0">— Select Showing —</option>';
  foreach ($posts as $p) {
    $id = $p->ID;
    $sel = selected($selected, $id, false);
    $html .= '<option value="' . esc_attr($id) . '" ' . $sel . '>' . esc_html(roxy_will_call_showing_label($id)) . '</option>';
  }
  $html .= '</select>';
  return $html;
}

/**
 * Build will call list + totals
 */
function roxy_will_call_get_showing_list($showing_id) {
  $product_ids = roxy_will_call_showing_product_ids((int)$showing_id);
  return roxy_will_call_get_list($product_ids);
}

function roxy_will_call_get_list($product_ids) {
  $product_ids = array_values(array_filter(array_map('intval', (array)$product_ids)));
  if (!$product_ids) {
    return [
      'rows' => [],
      'totals' => ['total_qty' => 0, 'total_revenue' => 0.0, 'order_count' => 0],
    ];
  }

  $statuses = ['wc-processing', 'wc-completed'];

  $order_ids = wc_get_orders([
    'type' => 'shop_order',
    'status' => $statuses,
    'limit' => -1,
    'return' => 'ids',
    'date_created' => '>' . (new DateTime('-18 months'))->format('Y-m-d'),
  ]);

  $product_lookup = array_fill_keys($product_ids, true);
  $agg = [];
  $total_qty = 0;
  $total_revenue = 0.0;
  $matching_order_ids = [];

  foreach ($order_ids as $oid) {
    $order = wc_get_order($oid);
    if (!$order) continue;
    if (class_exists('WC_Order_Refund') && ($order instanceof WC_Order_Refund)) continue;
    if (!($order instanceof WC_Order)) continue;

    $matched_this_order = false;

    foreach ($order->get_items('line_item') as $item) {
      $pid = (int)$item->get_product_id();
      $vid = (int)$item->get_variation_id();
      $matches = isset($product_lookup[$pid]) || isset($product_lookup[$vid]);
      if (!$matches) continue;

      $matched_this_order = true;
      $qty = (int)$item->get_quantity();
      $total_qty += $qty;

      $line_total = (float)$item->get_total();
      $line_tax   = (float)$item->get_total_tax();
      $total_revenue += ($line_total + $line_tax);

      $first = trim((string)$order->get_billing_first_name());
      $last  = trim((string)$order->get_billing_last_name());
      $email = strtolower(trim((string)$order->get_billing_email()));
      $name = trim($first . ' ' . $last);
      if ($name === '') $name = 'Unknown Name';
      if ($email === '') $email = 'unknown-email';
      $customer_key = md5($name . '|' . $email);

      if (!isset($agg[$customer_key])) {
        $agg[$customer_key] = [
          'customer_key' => $customer_key,
          'name' => $name,
          'email' => $email,
          'qty' => 0,
          'orders' => [],
          'latest_order_ts' => 0,
        ];
      }

      $agg[$customer_key]['qty'] += $qty;
      $date_created = $order->get_date_created();
      $ts = $date_created ? $date_created->getTimestamp() : 0;
      $agg[$customer_key]['orders'][(int)$oid] = $date_created ? $date_created->date('Y-m-d H:i:s') : '';
      if ($ts > (int)$agg[$customer_key]['latest_order_ts']) {
        $agg[$customer_key]['latest_order_ts'] = $ts;
      }
    }

    if ($matched_this_order) {
      $matching_order_ids[(int)$oid] = true;
    }
  }

  $rows = array_values($agg);
  usort($rows, function($a, $b) { return strcasecmp($a['name'], $b['name']); });

  return [
    'rows' => $rows,
    'totals' => [
      'total_qty' => (int)$total_qty,
      'total_revenue' => (float)$total_revenue,
      'order_count' => (int)count($matching_order_ids),
    ],
  ];
}

/**
 * Render table
 */
function roxy_will_call_render_table($mode, $id, $rows, $totals) {
  $nonce = wp_create_nonce('roxy_will_call_save');
  $context_id = roxy_will_call_context_numeric_id($mode, (int)$id);
  $checkins = roxy_will_call_get_checkins_map($context_id);

  if ($mode === 'showing') {
    $title = roxy_will_call_showing_label((int)$id);
  } else {
    $product = wc_get_product((int)$id);
    $title = $product ? $product->get_name() : 'Product #' . (int)$id;
  }

  $total_qty = isset($totals['total_qty']) ? (int)$totals['total_qty'] : 0;
  $total_revenue = isset($totals['total_revenue']) ? (float)$totals['total_revenue'] : 0.0;
  $order_count = isset($totals['order_count']) ? (int)$totals['order_count'] : 0;

  $checked_in_total = 0;
  foreach ($rows as $r) {
    $key = $r['customer_key'];
    $saved = isset($checkins[$key]) ? $checkins[$key] : ['checked_in' => 0, 'used_qty' => 0];
    $used_qty = isset($saved['used_qty']) ? (int)$saved['used_qty'] : 0;
    if ($used_qty < 0) $used_qty = 0;
    if ($used_qty > (int)$r['qty']) $used_qty = (int)$r['qty'];
    $checked_in_total += $used_qty;
  }
  $remaining_total = max(0, $total_qty - $checked_in_total);

  echo '<h2 style="margin-top:18px;">' . esc_html($title) . '</h2>';
  if ($mode === 'showing') {
    echo '<p class="roxy-wc-muted">Showing mode combines new Roxy ticket products and any mapped Legacy Product IDs. Orders counted: Processing + Completed.</p>';
  } else {
    echo '<p class="roxy-wc-muted">Orders counted: Processing + Completed. Refunds are ignored.</p>';
  }

  echo '<div class="roxy-wc-summary">';
  echo '  <div class="metric"><div class="label">Total items sold</div><div class="value" id="roxy-wc-total-sold">' . esc_html(number_format_i18n($total_qty)) . '</div></div>';
  echo '  <div class="metric"><div class="label">Checked In</div><div class="value" id="roxy-wc-checked-in">' . esc_html(number_format_i18n($checked_in_total)) . '</div></div>';
  echo '  <div class="metric"><div class="label">Remaining</div><div class="value" id="roxy-wc-remaining">' . esc_html(number_format_i18n($remaining_total)) . '</div></div>';
  echo '  <div class="metric"><div class="label">Total revenue</div><div class="value">' . wp_kses_post(wc_price($total_revenue)) . '</div></div>';
  echo '  <div class="metric"><div class="label">Matching orders</div><div class="value">' . esc_html(number_format_i18n($order_count)) . '</div></div>';
  echo '</div>';

  echo '<div class="roxy-wc-search-wrap">';
  echo '  <label for="roxy-wc-search">Search will call list</label>';
  echo '  <input type="text" id="roxy-wc-search" class="roxy-wc-search" placeholder="Search name, email, or order #..." />';
  echo '</div>';

  echo '<table class="widefat striped roxy-wc-table" data-context-id="' . esc_attr((int)$context_id) . '" data-nonce="' . esc_attr($nonce) . '">';
  echo '<thead><tr>';
  echo '<th style="width:40px;">#</th>';
  echo '<th>Name</th>';
  echo '<th style="width:220px;">Email</th>';
  echo '<th style="width:170px;">Orders</th>';
  echo '<th style="width:150px;">Order Date</th>';
  echo '<th style="width:90px;">Qty</th>';
  echo '<th style="width:90px;">Used</th>';
  echo '<th style="width:110px;">Checked In</th>';
  echo '<th style="width:90px;">Saved</th>';
  echo '</tr></thead><tbody>';

  $i = 0;
  foreach ($rows as $r) {
    $i++;
    $key = $r['customer_key'];
    $qty = (int)$r['qty'];
    $saved = isset($checkins[$key]) ? $checkins[$key] : ['checked_in' => 0, 'used_qty' => 0];
    $checked = ((int)$saved['checked_in'] === 1);
    $used_qty = (int)$saved['used_qty'];
    if ($used_qty < 0) $used_qty = 0;
    if ($used_qty > $qty) $used_qty = $qty;

    $order_links = [];
    $order_ids_for_search = [];
    if (!empty($r['orders']) && is_array($r['orders'])) {
      $order_ids = array_keys($r['orders']);
      sort($order_ids, SORT_NUMERIC);
      foreach ($order_ids as $oid) {
        $url = admin_url('post.php?post=' . absint($oid) . '&action=edit');
        $order_links[] = '<a href="' . esc_url($url) . '" target="_blank">#' . esc_html($oid) . '</a>';
        $order_ids_for_search[] = '#' . absint($oid);
        $order_ids_for_search[] = (string) absint($oid);
      }
    }
    $orders_html = $order_links ? implode(', ', $order_links) : '—';
    $latest_date = '—';
    if (!empty($r['latest_order_ts'])) {
      $latest_date = wp_date('Y-m-d g:ia', (int)$r['latest_order_ts']);
    }
    $search_text = trim($r['name'] . ' ' . $r['email'] . ' ' . implode(' ', $order_ids_for_search));

    echo '<tr data-customer-key="' . esc_attr($key) . '" data-qty="' . esc_attr($qty) . '" data-search="' . esc_attr(strtolower($search_text)) . '">';
    echo '<td>' . esc_html($i) . '</td>';
    echo '<td><strong>' . esc_html($r['name']) . '</strong></td>';
    echo '<td>' . esc_html($r['email']) . '</td>';
    echo '<td>' . wp_kses_post($orders_html) . '</td>';
    echo '<td>' . esc_html($latest_date) . '</td>';
    echo '<td>' . esc_html($qty) . '</td>';
    echo '<td><input class="roxy-used" type="number" min="0" max="' . esc_attr($qty) . '" value="' . esc_attr($used_qty) . '" /></td>';
    echo '<td><label><input class="roxy-checked" type="checkbox" ' . checked($checked, true, false) . ' /> yes</label></td>';
    echo '<td><span class="roxy-wc-saved">Saved ✓</span></td>';
    echo '</tr>';
  }

  if ($i === 0) {
    echo '<tr><td colspan="9">No matching purchases found for this selection.</td></tr>';
  }

  echo '</tbody></table>';
}

/**
 * Checkins map
 */
function roxy_will_call_get_checkins_map($context_id) {
  global $wpdb;
  $table = $wpdb->prefix . 'roxy_will_call_checkins';
  $rows = $wpdb->get_results(
    $wpdb->prepare("SELECT customer_key, checked_in, used_qty FROM $table WHERE product_id = %d", (int)$context_id),
    ARRAY_A
  );
  $map = [];
  foreach ($rows as $r) {
    $map[$r['customer_key']] = [
      'checked_in' => (int)$r['checked_in'],
      'used_qty' => (int)$r['used_qty'],
    ];
  }
  return $map;
}

/**
 * AJAX save
 */
add_action('wp_ajax_roxy_will_call_save', function () {
  if (!current_user_can('manage_woocommerce')) {
    wp_send_json_error(['message' => 'Not allowed']);
  }

  $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
  if (!wp_verify_nonce($nonce, 'roxy_will_call_save')) {
    wp_send_json_error(['message' => 'Bad nonce']);
  }

  $context_id = isset($_POST['context_id']) ? absint($_POST['context_id']) : 0;
  $customer_key = isset($_POST['customer_key']) ? sanitize_text_field($_POST['customer_key']) : '';
  $checked_in = isset($_POST['checked_in']) ? absint($_POST['checked_in']) : 0;
  $used_qty = isset($_POST['used_qty']) ? intval($_POST['used_qty']) : 0;

  if (!$context_id || !$customer_key) {
    wp_send_json_error(['message' => 'Missing data']);
  }

  if ($used_qty < 0) $used_qty = 0;
  if ($checked_in !== 1) $checked_in = 0;

  global $wpdb;
  $table = $wpdb->prefix . 'roxy_will_call_checkins';

  $wpdb->replace($table, [
    'product_id' => $context_id,
    'customer_key' => $customer_key,
    'checked_in' => $checked_in,
    'used_qty' => $used_qty,
    'updated_at' => current_time('mysql'),
  ], ['%d', '%s', '%d', '%d', '%s']);

  wp_send_json_success(['saved' => true]);
});
