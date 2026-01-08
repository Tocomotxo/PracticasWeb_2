<?php
/**
 * Plugin Name: Shared Table Editor (External MySQL) - Safe Version
 * Description: Editable table synced with external MySQL via AJAX. Safer activation + better error handling.
 * Version: 2.2.0
 */

if (!defined('ABSPATH')) exit;

class STE_External_Driver_Table_Safe {
  private $db;         // External wpdb connection
  private $table_name; // External table name
  private $version = '2.2.0';

  // Allowed select values (edit as needed)
  private $roles = ['CONDUCTOR', 'ADMIN', 'OPERADOR'];
  private $permissions = ['NIVEL 1', 'NIVEL 2', 'NIVEL 3'];

  public function __construct() {
    // NOTE: Do NOT connect to external DB in activation hook.
    // We'll connect lazily (only when needed).
    $this->table_name = defined('STE_EXT_TABLE') ? STE_EXT_TABLE : 'ste_drivers';

    add_shortcode('shared_table_editor', [$this, 'shortcode']);
    add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);

    add_action('wp_ajax_ste_list', [$this, 'ajax_list']);
    add_action('wp_ajax_ste_add_row', [$this, 'ajax_add_row']);
    add_action('wp_ajax_ste_save_row', [$this, 'ajax_save_row']);
  }

  /** Create a dedicated wpdb connection to an external database. */
  private function get_external_db() {
    if ($this->db) return $this->db;

    // If you did NOT add constants, we fail gracefully.
    $required = ['STE_EXT_DB_NAME','STE_EXT_DB_USER','STE_EXT_DB_PASSWORD','STE_EXT_DB_HOST'];
    foreach ($required as $c) {
      if (!defined($c) || constant($c) === '') {
        error_log("STE: Missing constant {$c} in wp-config.php");
        return null;
      }
    }

    $this->db = new wpdb(STE_EXT_DB_USER, STE_EXT_DB_PASSWORD, STE_EXT_DB_NAME, STE_EXT_DB_HOST);

    // Optional charset setup
    if (method_exists($this->db, 'set_charset') && isset($this->db->dbh)) {
      $this->db->set_charset($this->db->dbh, 'utf8mb4');
    }

    return $this->db;
  }

  /** Access control */
  private function user_can_access(): bool {
    return is_user_logged_in() && current_user_can('read');
  }

  public function enqueue_assets() {
    if (!is_singular()) return;
    global $post;
    if (!$post || stripos($post->post_content, '[shared_table_editor') === false) return;

    wp_enqueue_style('ste-css', plugin_dir_url(__FILE__) . 'ste.css', [], $this->version);
    wp_enqueue_script('ste-js', plugin_dir_url(__FILE__) . 'ste.js', ['jquery'], $this->version, true);

    wp_localize_script('ste-js', 'STE', [
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'nonce' => wp_create_nonce('ste_nonce'),
      'roles' => $this->roles,
      'permissions' => $this->permissions,
    ]);
  }

  public function shortcode() {
    if (!$this->user_can_access()) return '<p>No access.</p>';

    // Try to ensure table exists (best-effort) when rendering.
    $this->ensure_external_table_exists();

    ob_start(); ?>
      <div class="ste-wrap">
        <div class="ste-topbar">
          <button type="button" class="ste-btn ste-btn-primary" id="ste-add-row">Añadir</button>
          <span id="ste-status" class="ste-status" aria-live="polite"></span>
        </div>

        <div class="ste-table-wrap">
          <table class="ste-table" id="ste-driver-table">
            <thead>
              <tr>
                <th>ID</th>
                <th>NOMBRE</th>
                <th>ROLL</th>
                <th>PERMISOS</th>
                <th>VALIDEZ CARNE DE CONDUCIR</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr><td colspan="6">Loading...</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    <?php
    return ob_get_clean();
  }

  /** Security + DB availability checks for AJAX */
  private function verify_request_or_die() {
    if (!$this->user_can_access()) {
      wp_send_json_error(['message' => 'Unauthorized'], 403);
    }
    check_ajax_referer('ste_nonce', 'nonce');

    $db = $this->get_external_db();
    if (!$db) {
      wp_send_json_error(['message' => 'External DB config missing (wp-config.php constants)'], 500);
    }
    if (!empty($db->error) || !empty($db->last_error)) {
      error_log('STE: External DB error: ' . ($db->error ?: $db->last_error));
      wp_send_json_error(['message' => 'Database connection error'], 500);
    }
  }

  /** Create table if it does not exist (best effort). */
  private function ensure_external_table_exists() {
    $db = $this->get_external_db();
    if (!$db) return;

    // Check table exists
    $exists = $db->get_var($db->prepare(
      "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=%s AND table_name=%s",
      STE_EXT_DB_NAME,
      $this->table_name
    ));

    if ((int)$exists > 0) return;

    // Create table (no dbDelta; direct SQL for external DB)
    $sql = "CREATE TABLE {$this->table_name} (
      driver_id BIGINT UNSIGNED NOT NULL,
      name VARCHAR(255) NOT NULL,
      role VARCHAR(50) NOT NULL,
      permission VARCHAR(50) NOT NULL,
      license_valid_until DATE NULL,
      updated_by BIGINT UNSIGNED NULL,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (driver_id),
      KEY updated_at (updated_at),
      KEY updated_by (updated_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    $db->query($sql);

    if (!empty($db->last_error)) {
      // Not fatal; just log it. The user may not have CREATE privilege.
      error_log('STE: Failed creating external table: ' . $db->last_error);
    }
  }

  public function ajax_list() {
    $this->verify_request_or_die();
    $db = $this->get_external_db();

    $rows = $db->get_results(
      "SELECT driver_id, name, role, permission, license_valid_until, updated_at, updated_by
       FROM {$this->table_name}
       ORDER BY driver_id ASC
       LIMIT 1000",
      ARRAY_A
    );

    if (!empty($db->last_error)) {
      error_log('STE: List query error: ' . $db->last_error);
      wp_send_json_error(['message' => 'Query failed'], 500);
    }

    foreach ($rows as &$r) {
      $user = !empty($r['updated_by']) ? get_user_by('id', (int)$r['updated_by']) : null;
      $r['updated_by_name'] = $user ? $user->display_name : '';
    }

    wp_send_json_success([
      'rows' => $rows,
      'roles' => $this->roles,
      'permissions' => $this->permissions,
    ]);
  }

  public function ajax_add_row() {
    $this->verify_request_or_die();
    $db = $this->get_external_db();

    $suggested_id = time();
    while ((int)$db->get_var($db->prepare(
      "SELECT COUNT(*) FROM {$this->table_name} WHERE driver_id=%d",
      $suggested_id
    )) > 0) {
      $suggested_id++;
    }

    wp_send_json_success([
      'row' => [
        'driver_id' => $suggested_id,
        'name' => '',
        'role' => $this->roles[0],
        'permission' => $this->permissions[0],
        'license_valid_until' => null,
      ]
    ]);
  }

  public function ajax_save_row() {
    $this->verify_request_or_die();
    $db = $this->get_external_db();
    $user_id = get_current_user_id();

    $driver_id = isset($_POST['driver_id']) ? (int) $_POST['driver_id'] : 0;
    $original_driver_id = isset($_POST['original_driver_id']) ? (int) $_POST['original_driver_id'] : 0;

    $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
    $role = isset($_POST['role']) ? sanitize_text_field($_POST['role']) : '';
    $permission = isset($_POST['permission']) ? sanitize_text_field($_POST['permission']) : '';
    $license_valid_until = isset($_POST['license_valid_until']) ? sanitize_text_field($_POST['license_valid_until']) : '';

    if ($driver_id <= 0) wp_send_json_error(['message' => 'Driver ID must be > 0'], 400);
    if ($name === '') wp_send_json_error(['message' => 'Name is required'], 400);
    if (!in_array($role, $this->roles, true)) wp_send_json_error(['message' => 'Invalid role'], 400);
    if (!in_array($permission, $this->permissions, true)) wp_send_json_error(['message' => 'Invalid permission'], 400);

    $date_value = null;
    if ($license_valid_until !== '') {
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $license_valid_until)) {
        wp_send_json_error(['message' => 'Invalid date format'], 400);
      }
      $date_value = $license_valid_until;
    }

    // Handle primary key change
    if ($original_driver_id > 0 && $original_driver_id !== $driver_id) {
      $exists_new = (int)$db->get_var($db->prepare(
        "SELECT COUNT(*) FROM {$this->table_name} WHERE driver_id=%d",
        $driver_id
      ));
      if ($exists_new > 0) wp_send_json_error(['message' => 'That ID already exists'], 409);

      $sql = $db->prepare(
        "UPDATE {$this->table_name}
         SET driver_id=%d, name=%s, role=%s, permission=%s, license_valid_until=%s, updated_by=%d
         WHERE driver_id=%d",
        $driver_id, $name, $role, $permission, $date_value, $user_id, $original_driver_id
      );
      $db->query($sql);

      if (!empty($db->last_error)) {
        error_log('STE: Update (pk change) error: ' . $db->last_error);
        wp_send_json_error(['message' => 'DB update failed'], 500);
      }

      wp_send_json_success(['message' => 'Saved', 'driver_id' => $driver_id]);
    }

    // Upsert
    $exists = (int)$db->get_var($db->prepare(
      "SELECT COUNT(*) FROM {$this->table_name} WHERE driver_id=%d",
      $driver_id
    ));

    if ($exists > 0) {
      $sql = $db->prepare(
        "UPDATE {$this->table_name}
         SET name=%s, role=%s, permission=%s, license_valid_until=%s, updated_by=%d
         WHERE driver_id=%d",
        $name, $role, $permission, $date_value, $user_id, $driver_id
      );
      $db->query($sql);
    } else {
      $sql = $db->prepare(
        "INSERT INTO {$this->table_name}
         (driver_id, name, role, permission, license_valid_until, updated_by)
         VALUES (%d, %s, %s, %s, %s, %d)",
        $driver_id, $name, $role, $permission, $date_value, $user_id
      );
      $db->query($sql);
    }

    if (!empty($db->last_error)) {
      error_log('STE: Save error: ' . $db->last_error);
      wp_send_json_error(['message' => 'DB save failed'], 500);
    }

    wp_send_json_success(['message' => 'Saved', 'driver_id' => $driver_id]);
  }
}

new STE_External_Driver_Table_Safe();