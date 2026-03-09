<?php
/**
 * Plugin Name: Roxy Will Call (WooCommerce)
 * Description: Generates will call lists for WooCommerce products with check-in tracking, order links, dates, totals, and revenue.
 * Version: 0.2.0
 */

if (!defined('ABSPATH')) exit;

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

function roxy_will_call_admin_page() {
  if (!current_user_can('manage_woocommerce')) {
    wp_die('Not allowed.');
  }

  $selected_product_id = isset($_GET['product_id']) ? absint($_GET['product_id']) : 0;

  ?>
  <div class="wrap">
    <h1>Will Call</h1>

    <form method="get" style="margin: 12px 0;">
      <input type="hidden" name="page" value="roxy-will-call" />
      <label for="product_id"><strong>Select ticket product:</strong></label>
      <?php echo roxy_will_call_product_dropdown($selected_product_id); ?>
      <button class="button button-primary">Load</button>
      <?php if ($selected_product_id): ?>
        <button type="button" class="button" onclick="window.print()">Print</button>
      <?php endif; ?>
    </form>

    <?php
      if ($selected_product_id) {
        $result = roxy_will_call_get_list($selected_product_id);
        $rows = $result['rows'];
        $totals = $result['totals'];
        roxy_will_call_render_table($selected_product_id, $rows, $totals);
      } else {
        echo '<p>Select a product to generate the list.</p>';
      }
    ?>
  </div>

  <style>
    /* Print-friendly */
    @media print {
      #adminmenumain, #wpadminbar, .notice, .update-nag, .wrap form, .wrap .button { display:none !important; }
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
    .roxy-wc-summary .metric {
      min-width: 180px;
    }
    .roxy-wc-summary .label {
      font-size: 12px;
      color: #666;
      margin-bottom: 2px;
    }
    .roxy-wc-summary .value {
      font-size: 18px;
      font-weight: 700;
    }
  </style>

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const table = document.querySelector('.roxy-wc-table');
      if (!table) return;

      table.addEventListener('change', async (e) => {
        const tr = e.target.closest('tr[data-customer-key]');
        if (!tr) return;

        const productId = table.getAttribute('data-product-id');
        const customerKey = tr.getAttribute('data-customer-key');
        const checked = tr.querySelector('input.roxy-checked').checked ? 1 : 0;
        const usedQty = parseInt(tr.querySelector('input.roxy-used').value || '0', 10);

        const form = new FormData();
        form.append('action', 'roxy_will_call_save');
        form.append('nonce', table.getAttribute('data-nonce'));
        form.append('product_id', productId);
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
          } else {
            alert('Save failed.');
          }
        } catch (err) {
          alert('Save error.');
        }
      });
    });
  </script>
  <?php
}

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
    $html .= "<option value=\"" . esc_attr($id) . "\" $sel>" . esc_html("{$name} (#{$id})") . "</option>";
  }

  $html .= '</select>';
  return $html;
}

/**
 * Returns:
 * [
 *   'rows' => aggregated rows,
 *   'totals' => ['total_qty' => int, 'total_revenue' => float, 'order_count' => int]
 * ]
 *
 * Revenue uses (line_total + line_total_tax) for matching items.
 */
function roxy_will_call_get_list($product_id) {
  $statuses = ['wc-processing', 'wc-completed']; // adjust if desired

  // Pull a reasonable window; adjust as needed
  $order_ids = wc_get_orders([
    'status' => $statuses,
    'limit' => -1,
    'return' => 'ids',
    'date_created' => '>' . (new DateTime('-18 months'))->format('Y-m-d'),
  ]);

  $agg = [];
  $total_qty = 0;
  $total_revenue = 0.0;
  $matching_order_ids = [];

  foreach ($order_ids as $oid) {
    $order = wc_get_order($oid);
    if (!$order) continue;

    $matched_this_order = false;

    foreach ($order->get_items('line_item') as $item) {
      $pid = (int) $item->get_product_id();
      $vid = (int) $item->get_variation_id();
      $matches = ($pid === (int)$product_id || $vid === (int)$product_id);

      if (!$matches) continue;

      $matched_this_order = true;

      $qty = (int) $item->get_quantity();
      $total_qty += $qty;

      // Revenue: line total + tax for that line item
      $line_total = (float) $item->get_total();
      $line_tax   = (float) $item->get_total_tax();
      $total_revenue += ($line_total + $line_tax);

      $first = trim((string)$order->get_billing_first_name());
      $last  = trim((string)$order->get_billing_last_name());
      $email = strtolower(trim((string)$order->get_billing_email()));

      $name = trim($first . ' ' . $last);
      if ($name === '') $name = 'Unknown Name';
      if ($email === '') $email = 'unknown-email';

      // Customer key used for check-in persistence
      $customer_key = md5($name . '|' . $email);

      if (!isset($agg[$customer_key])) {
        $agg[$customer_key] = [
          'customer_key' => $customer_key,
          'name' => $name,
          'email' => $email,
          'qty' => 0,
          'orders' => [],         // [order_id => 'Y-m-d H:i:s']
          'latest_order_ts' => 0, // unix ts
        ];
      }

      $agg[$customer_key]['qty'] += $qty;

      $date_created = $order->get_date_created();
      $ts = $date_created ? $date_created->getTimestamp() : 0;

      if ($date_created) {
        $agg[$customer_key]['orders'][$oid] = $date_created->date('Y-m-d H:i:s');
      } else {
        $agg[$customer_key]['orders'][$oid] = '';
      }

      if ($ts > $agg[$customer_key]['latest_order_ts']) {
        $agg[$customer_key]['latest_order_ts'] = $ts;
      }
    }

    if ($matched_this_order) {
      $matching_order_ids[$oid] = true;
    }
  }

  // Convert to indexed array + sort A→Z
  $rows = array_values($agg);
  usort($rows, function($a, $b) {
    return strcasecmp($a['name'], $b['name']);
  });

  return [
    'rows' => $rows,
    'totals' => [
      'total_qty' => (int)$total_qty,
      'total_revenue' => (float)$total_revenue,
      'order_count' => (int)count($matching_order_ids),
    ],
  ];
}

function roxy_will_call_render_table($product_id, $rows, $totals) {
  $nonce = wp_create_nonce('roxy_will_call_save');

  // Load existing check-ins
  $checkins = roxy_will_call_get_checkins_map($product_id);

  $product = wc_get_product($product_id);
  $title = $product ? $product->get_name() : "Product #$product_id";

  echo '<h2 style="margin-top:18px;">' . esc_html($title) . '</h2>';
  echo '<p class="roxy-wc-muted">Orders counted: Processing + Completed. Names are aggregated by Billing Name + Email.</p>';

  // Summary metrics
  $total_qty = isset($totals['total_qty']) ? (int)$totals['total_qty'] : 0;
  $total_revenue = isset($totals['total_revenue']) ? (float)$totals['total_revenue'] : 0.0;
  $order_count = isset($totals['order_count']) ? (int)$totals['order_count'] : 0;

  echo '<div class="roxy-wc-summary">';
  echo '  <div class="metric"><div class="label">Total items sold</div><div class="value">' . esc_html(number_format_i18n($total_qty)) . '</div></div>';
  echo '  <div class="metric"><div class="label">Total revenue</div><div class="value">' . wp_kses_post(wc_price($total_revenue)) . '</div></div>';
  echo '  <div class="metric"><div class="label">Matching orders</div><div class="value">' . esc_html(number_format_i18n($order_count)) . '</div></div>';
  echo '</div>';

  echo '<table class="widefat striped roxy-wc-table" data-product-id="' . esc_attr($product_id) . '" data-nonce="' . esc_attr($nonce) . '">';
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

    $saved = $checkins[$key] ?? ['checked_in' => 0, 'used_qty' => 0];
    $checked = (int)$saved['checked_in'] === 1;
    $used_qty = (int)$saved['used_qty'];

    // clamp used qty to sensible range
    if ($used_qty < 0) $used_qty = 0;
    if ($used_qty > $qty) $used_qty = $qty;

    // Build order links
    $order_links = [];
    if (!empty($r['orders']) && is_array($r['orders'])) {
      $order_ids = array_keys($r['orders']);
      sort($order_ids, SORT_NUMERIC);

      foreach ($order_ids as $oid) {
        $url = admin_url('post.php?post=' . absint($oid) . '&action=edit');
        $order_links[] = '<a href="' . esc_url($url) . '" target="_blank">#' . esc_html($oid) . '</a>';
      }
    }
    $orders_html = $order_links ? implode(', ', $order_links) : '—';

    // Latest order date for this aggregated row
    $latest_date = '—';
    if (!empty($r['latest_order_ts'])) {
      $latest_date = wp_date('Y-m-d g:ia', (int)$r['latest_order_ts']);
    }

    echo '<tr data-customer-key="' . esc_attr($key) . '">';
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
    echo '<tr><td colspan="9">No matching purchases found for this product.</td></tr>';
  }

  echo '</tbody></table>';
}

function roxy_will_call_get_checkins_map($product_id) {
  global $wpdb;
  $table = $wpdb->prefix . 'roxy_will_call_checkins';

  $rows = $wpdb->get_results(
    $wpdb->prepare("SELECT customer_key, checked_in, used_qty FROM $table WHERE product_id = %d", $product_id),
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

add_action('wp_ajax_roxy_will_call_save', function () {
  if (!current_user_can('manage_woocommerce')) {
    wp_send_json_error(['message' => 'Not allowed']);
  }

  $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
  if (!wp_verify_nonce($nonce, 'roxy_will_call_save')) {
    wp_send_json_error(['message' => 'Bad nonce']);
  }

  $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
  $customer_key = isset($_POST['customer_key']) ? sanitize_text_field($_POST['customer_key']) : '';
  $checked_in = isset($_POST['checked_in']) ? absint($_POST['checked_in']) : 0;
  $used_qty = isset($_POST['used_qty']) ? intval($_POST['used_qty']) : 0;

  if (!$product_id || !$customer_key) {
    wp_send_json_error(['message' => 'Missing fields']);
  }

  if ($used_qty < 0) $used_qty = 0;
  if ($checked_in !== 1) $checked_in = 0;

  global $wpdb;
  $table = $wpdb->prefix . 'roxy_will_call_checkins';
  $now = current_time('mysql');

  // Upsert
  $existing = $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM $table WHERE product_id=%d AND customer_key=%s",
    $product_id, $customer_key
  ));

  if ($existing) {
    $wpdb->update(
      $table,
      ['checked_in' => $checked_in, 'used_qty' => $used_qty, 'updated_at' => $now],
      ['id' => $existing],
      ['%d','%d','%s'],
      ['%d']
    );
  } else {
    $wpdb->insert(
      $table,
      [
        'product_id' => $product_id,
        'customer_key' => $customer_key,
        'checked_in' => $checked_in,
        'used_qty' => $used_qty,
        'updated_at' => $now
      ],
      ['%d','%s','%d','%d','%s']
    );
  }

  wp_send_json_success(['ok' => true]);
});
