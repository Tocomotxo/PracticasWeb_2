<?php
/**
 * Plugin Name: BD Jesus - CRUD Employees
 * Description: Employee CRUD on external MySQL. Table on top, styled UI, reuse smallest free ID. Removes id_empleado column from DB.
 * Version: 1.7.1
 */

if ( ! defined('ABSPATH') ) exit;

/* =========================
   CONFIGURATION
   ========================= */

if ( ! defined('BDY_DB_NAME') ) define('BDY_DB_NAME', 'db_jesus');
if ( ! defined('BDY_DB_HOST') ) define('BDY_DB_HOST', 'localhost');
if ( ! defined('BDY_DB_USER') ) define('BDY_DB_USER', 'jesus');
if ( ! defined('BDY_DB_PASS') ) define('BDY_DB_PASS', 'pass1');
if ( ! defined('BDY_TABLE') ) define('BDY_TABLE', 'empleados');

/* =========================
   Dropdown options
   ========================= */

function bdy_roles_options() : array {
    return ['Conductor', 'Mecánico', 'Supervisor'];
}

function bdy_permisos_options() : array {
    return [
        'Nivel 1' => 'Nivel 1 (driving allowed)',
        'Nivel 2' => 'Nivel 2 (driving + repairing allowed)',
        'Nivel 3' => 'Nivel 3 (administrative and management)',
    ];
}

/* =========================
   External DB connection
   ========================= */
function bdy_db() : wpdb {
    static $db = null;
    if ( $db instanceof wpdb ) return $db;

    $db = new wpdb(BDY_DB_USER, BDY_DB_PASS, BDY_DB_NAME, BDY_DB_HOST);

    if ( ! empty($db->last_error) ) {
        error_log('[BDY CRUD] DB connection error: ' . $db->last_error);
    }

    return $db;
}

/* =========================
   Helpers
   ========================= */

function bdy_redirect_msg(string $code, string $redirect) {
    wp_safe_redirect( add_query_arg('bdy_msg', $code, $redirect) );
    exit;
}

function bdy_db_fail_log(string $context, wpdb $db) {
    if ( ! empty($db->last_error) ) {
        error_log('[BDY CRUD] ' . $context . ': ' . $db->last_error);
    }
}

// Get the smallest missing positive integer id (starting from 1)
function bdy_next_free_id(wpdb $db, string $table) : int {
    $one_exists = (int) $db->get_var("SELECT COUNT(*) FROM `$table` WHERE id = 1");
    if ($one_exists === 0) return 1;

    $next = (int) $db->get_var("
        SELECT MIN(t1.id + 1)
        FROM `$table` t1
        LEFT JOIN `$table` t2 ON t2.id = t1.id + 1
        WHERE t2.id IS NULL
    ");

    if ($next < 1) {
        $next = (int) $db->get_var("SELECT COALESCE(MAX(id),0) + 1 FROM `$table`");
    }

    return $next;
}

/* =========================
   Permission check
   ========================= */
function bdy_can_manage() : bool {
    $can = current_user_can('manage_options') || current_user_can('edit_pages');
    return (bool) apply_filters('bdy_can_manage', $can);
}

/* =========================
   Create table (NEW schema without id_empleado)
   ========================= */
register_activation_hook(__FILE__, 'bdy_create_table');

function bdy_create_table() {
    $db = bdy_db();
    $table = BDY_TABLE;
    $charset_collate = $db->get_charset_collate();

    // NEW schema: no id_empleado column
    $sql = "CREATE TABLE IF NOT EXISTS `$table` (
        `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        `nombre` VARCHAR(100) NOT NULL,
        `telefono` VARCHAR(30) NOT NULL,
        `rol` VARCHAR(50) NOT NULL,
        `permisos` VARCHAR(50) NOT NULL,
        `vigencia_permiso` DATE NOT NULL,
        PRIMARY KEY (`id`)
    ) $charset_collate;";

    $ok = $db->query($sql);
    if ( false === $ok ) {
        bdy_db_fail_log('Table creation failed', $db);
    }

    // Run migration too (in case table existed with old schema)
    bdy_maybe_migrate_drop_employee_id();
}

/* =========================
   MIGRATION: drop id_empleado column + its UNIQUE index
   ========================= */

// Run migration on init (admin/editor only), so it also works without re-activating plugin
add_action('init', 'bdy_maybe_migrate_drop_employee_id');

function bdy_maybe_migrate_drop_employee_id() {
    if ( ! is_user_logged_in() ) return;
    if ( ! bdy_can_manage() ) return;

    $db = bdy_db();
    $table = BDY_TABLE;

    // Check if column exists
    $col = $db->get_row( $db->prepare("SHOW COLUMNS FROM `$table` LIKE %s", 'id_empleado') );
    if ( ! $col ) return; // already migrated or table not found

    // Find any index that uses id_empleado and drop it
    $indexes = $db->get_results( $db->prepare("SHOW INDEX FROM `$table` WHERE Column_name = %s", 'id_empleado') );
    if ( is_array($indexes) ) {
        $seen = [];
        foreach ($indexes as $ix) {
            $key_name = $ix->Key_name ?? '';
            if ( $key_name && empty($seen[$key_name]) ) {
                $seen[$key_name] = true;
                $drop_ix = $db->query("ALTER TABLE `$table` DROP INDEX `$key_name`");
                if ( false === $drop_ix ) {
                    // If it fails, log and continue; dropping column may still work for non-unique indexes
                    bdy_db_fail_log('Drop index failed (' . $key_name . ')', $db);
                }
            }
        }
    }

    // Now drop the column
    $drop_col = $db->query("ALTER TABLE `$table` DROP COLUMN `id_empleado`");
    if ( false === $drop_col ) {
        bdy_db_fail_log('Drop column id_empleado failed', $db);
    }
}

/* =========================
   Handle POST actions (add/update/delete)
   ========================= */
add_action('init', 'bdy_handle_actions');

function bdy_handle_actions() {
    if ( ! is_user_logged_in() ) return;
    if ( empty($_POST['bdy_action']) ) return;

    if ( empty($_POST['bdy_nonce']) || ! wp_verify_nonce($_POST['bdy_nonce'], 'bdy_nonce_action') ) return;
    if ( ! bdy_can_manage() ) return;

    $db    = bdy_db();
    $table = BDY_TABLE;

    $action = sanitize_text_field($_POST['bdy_action']);

    $redirect = wp_get_referer() ? wp_get_referer() : home_url('/');
    $redirect = remove_query_arg(['bdy_msg', 'edit_id'], $redirect);

    $roles_allowed = bdy_roles_options();
    $perms_allowed = array_keys(bdy_permisos_options());

    if ( $action === 'add' || $action === 'update' ) {
        $nombre   = sanitize_text_field($_POST['nombre'] ?? '');
        $telefono = sanitize_text_field($_POST['telefono'] ?? '');
        $rol      = sanitize_text_field($_POST['rol'] ?? '');
        $permisos = sanitize_text_field($_POST['permisos'] ?? '');
        $vigencia = sanitize_text_field($_POST['vigencia_permiso'] ?? '');

        if ( ! $nombre || ! $telefono || ! $vigencia ) {
            bdy_redirect_msg('missing', $redirect);
        }

        if ( ! in_array($rol, $roles_allowed, true) ) $rol = $roles_allowed[0];
        if ( ! in_array($permisos, $perms_allowed, true) ) $permisos = $perms_allowed[0];

        if ( ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $vigencia) ) {
            bdy_redirect_msg('bad_date', $redirect);
        }

        $data = [
            'nombre'           => $nombre,
            'telefono'         => $telefono,
            'rol'              => $rol,
            'permisos'         => $permisos,
            'vigencia_permiso' => $vigencia,
        ];
    }

    if ( $action === 'add' ) {
        $next_id = bdy_next_free_id($db, $table);
        $data_with_id = ['id' => $next_id] + $data;

        $ok = $db->insert($table, $data_with_id, ['%d','%s','%s','%s','%s','%s']);

        if ( false === $ok ) {
            bdy_db_fail_log('Insert failed', $db);
            bdy_redirect_msg('add_failed', $redirect);
        }

        bdy_redirect_msg('added', $redirect);
    }

    if ( $action === 'update' ) {
        $id = absint($_POST['id'] ?? 0);
        if ( ! $id ) bdy_redirect_msg('missing', $redirect);

        $ok = $db->update($table, $data, ['id' => $id], ['%s','%s','%s','%s','%s'], ['%d']);

        if ( false === $ok ) {
            bdy_db_fail_log('Update failed', $db);
            bdy_redirect_msg('update_failed', $redirect);
        }

        bdy_redirect_msg('updated', $redirect);
    }

    if ( $action === 'delete' ) {
        $id = absint($_POST['id'] ?? 0);
        if ( ! $id ) bdy_redirect_msg('missing', $redirect);

        $ok = $db->delete($table, ['id' => $id], ['%d']);

        if ( false === $ok ) {
            bdy_db_fail_log('Delete failed', $db);
            bdy_redirect_msg('delete_failed', $redirect);
        }

        bdy_redirect_msg('deleted', $redirect);
    }
}

/* =========================
   UI styles (PREMIUM)
   ========================= */
add_action('wp_enqueue_scripts', 'bdy_enqueue_styles');

function bdy_enqueue_styles() {
    wp_register_style('bdy-crud-ui', false, [], '1.7.1');
    wp_enqueue_style('bdy-crud-ui');

    $css = "
.bdy-wrap{max-width:1100px;margin:0 auto;}
.bdy-card{background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:14px;padding:16px;box-shadow:0 4px 18px rgba(0,0,0,.05);margin:14px 0;}
.bdy-head{display:flex;gap:12px;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;}
.bdy-title{margin:0;font-size:20px;line-height:1.2;}
.bdy-sub{margin:4px 0 0;color:rgba(0,0,0,.65);font-size:13px;}

.bdy-msg{padding:12px;border-radius:12px;border:1px solid rgba(0,0,0,.08);margin:0 0 12px 0;}
.bdy-msg--ok{border-color:rgba(46,204,113,.55);background:rgba(46,204,113,.08);}
.bdy-msg--warn{border-color:rgba(241,196,15,.60);background:rgba(241,196,15,.10);}
.bdy-msg--err{border-color:rgba(231,76,60,.60);background:rgba(231,76,60,.10);}

.bdy-row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;}
.bdy-field{display:flex;flex-direction:column;gap:6px;min-width:220px;flex:1;}
.bdy-field label{font-size:13px;color:rgba(0,0,0,.75);}
.bdy-input,.bdy-select{width:100%;padding:10px 12px;border-radius:12px;border:1px solid rgba(0,0,0,.18);background:#fff;}
.bdy-input:focus,.bdy-select:focus{outline:none;border-color:rgba(0,116,232,.85);box-shadow:0 0 0 3px rgba(0,116,232,.15);}

.bdy-tablewrap{width:100%;overflow:auto;border-radius:14px;border:1px solid rgba(0,0,0,.08);}
.bdy-table{border-collapse:separate;border-spacing:0;width:100%;min-width:760px;background:#fff;}
.bdy-table thead th{position:sticky;top:0;background:#f7f9fc;border-bottom:1px solid rgba(0,0,0,.08);text-align:left;padding:12px;font-size:13px;color:rgba(0,0,0,.75);}
.bdy-table tbody td{border-bottom:1px solid rgba(0,0,0,.06);padding:12px;vertical-align:middle;}
.bdy-table tbody tr:hover{background:rgba(0,116,232,.04);}

.bdy-badge{display:inline-block;padding:4px 10px;border-radius:999px;font-size:12px;border:1px solid rgba(0,0,0,.10);background:rgba(0,0,0,.03);}
.bdy-badge--expired{border-color:rgba(231,76,60,.55);background:rgba(231,76,60,.10);color:#b03025;font-weight:800;}

.bdy-small{font-size:12px;color:rgba(0,0,0,.65);}

/* =========================
   PREMIUM BUTTONS (Edit/Delete aligned)
   ========================= */
.bdy-actions{
  display:flex;
  align-items:center;
  justify-content:flex-start;
  gap:10px;
  flex-wrap:nowrap;
  white-space:nowrap;
}

.bdy-actions form{
  display:flex;
  align-items:center;
  margin:0;
  padding:0;
}

.bdy-btn{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  height:40px;
  padding:0 16px;
  border-radius:12px;
  border:1px solid rgba(0,0,0,.12);
  background:#fff;
  cursor:pointer;
  text-decoration:none;
  font-weight:800;
  font-size:14px;
  line-height:1;
  transition:transform .12s ease, filter .12s ease, box-shadow .12s ease;
  box-shadow:0 6px 18px rgba(0,0,0,.06);
}
.bdy-btn:hover{filter:brightness(1.02);}
.bdy-btn:active{transform:translateY(1px); box-shadow:0 4px 12px rgba(0,0,0,.06);}

.bdy-btn--primary{
  background:linear-gradient(135deg,#0d6efd,#0b5ed7);
  border-color:#0b5ed7;
  color:#fff;
}
.bdy-btn--primary:hover{filter:brightness(1.06);}

.bdy-btn--ghost{background:transparent; box-shadow:none;}

.bdy-btn--danger{
  background:linear-gradient(135deg,#e74c3c,#c0392b);
  border-color:#c0392b;
  color:#fff;
}
.bdy-btn--danger:hover{filter:brightness(1.06);}
";

    wp_add_inline_style('bdy-crud-ui', $css);
}

/* =========================
   SEO / privacy: noindex when shortcode is rendered
   ========================= */
add_action('wp_head', 'bdy_noindex_when_shortcode', 1);

function bdy_noindex_when_shortcode() {
    if ( ! empty($GLOBALS['bdy_shortcode_rendered']) ) {
        echo "\n<meta name=\"robots\" content=\"noindex,nofollow\" />\n";
    }
}

/* =========================
   Shortcode [bdy_empleados]
   ========================= */
add_shortcode('bdy_empleados', 'bdy_shortcode');

function bdy_shortcode() {
    $GLOBALS['bdy_shortcode_rendered'] = true;

    if ( ! is_user_logged_in() ) {
        return '<div class="bdy-wrap"><div class="bdy-card"><p>You must be logged in to view this page.</p></div></div>';
    }
    if ( ! bdy_can_manage() ) {
        return '<div class="bdy-wrap"><div class="bdy-card"><p>You do not have permission to manage this data.</p></div></div>';
    }

    $db    = bdy_db();
    $table = BDY_TABLE;

    $edit_id = isset($_GET['edit_id']) ? absint($_GET['edit_id']) : 0;
    $msg     = isset($_GET['bdy_msg']) ? sanitize_text_field($_GET['bdy_msg']) : '';
    $search  = isset($_GET['bdy_q']) ? sanitize_text_field($_GET['bdy_q']) : '';

    $editing = null;
    if ( $edit_id ) {
        $editing = $db->get_row($db->prepare("SELECT * FROM `$table` WHERE id = %d", $edit_id));
    }

    if ( $search !== '' ) {
        $like = '%' . $db->esc_like($search) . '%';
        $rows = $db->get_results(
            $db->prepare(
                "SELECT * FROM `$table`
                 WHERE nombre LIKE %s
                    OR telefono LIKE %s
                    OR rol LIKE %s
                    OR permisos LIKE %s
                 ORDER BY id DESC",
                $like, $like, $like, $like
            )
        );
    } else {
        $rows = $db->get_results("SELECT * FROM `$table` ORDER BY id DESC");
    }

    if ( ! empty($db->last_error) ) {
        bdy_db_fail_log('Select failed', $db);
        return '<div class="bdy-wrap"><div class="bdy-card">'
            . '<div class="bdy-msg bdy-msg--err"><strong>❌ Error</strong> Database error while loading data.</div>'
            . '</div></div>';
    }

    $roles     = bdy_roles_options();
    $perms_map = bdy_permisos_options();
    $today     = wp_date('Y-m-d');

    $base_url = get_permalink(get_queried_object_id());
    $preserve = $_GET;
    unset($preserve['bdy_msg']);

    ob_start();
    ?>
    <div class="bdy-wrap">

        <div class="bdy-card">
            <div class="bdy-head">
                <div>
                    <h2 class="bdy-title">Employees</h2>
                    <p class="bdy-sub">Manage employees (CRUD). Table is shown first; the form is below.</p>
                </div>
            </div>

            <?php
            if ( $msg ) {
                $messages = [
                    'added'         => ['✅ Record successfully saved.', 'ok'],
                    'updated'       => ['✅ Record successfully updated.', 'ok'],
                    'deleted'       => ['✅ Record successfully deleted.', 'ok'],
                    'missing'       => ['⚠️ Missing required fields.', 'warn'],
                    'bad_date'      => ['⚠️ Invalid date format.', 'warn'],
                    'add_failed'    => ['❌ Error while inserting record.', 'err'],
                    'update_failed' => ['❌ Error while updating record.', 'err'],
                    'delete_failed' => ['❌ Error while deleting record.', 'err'],
                ];
                if ( isset($messages[$msg]) ) {
                    [$text, $type] = $messages[$msg];
                    $cls = $type === 'ok' ? 'bdy-msg--ok' : ($type === 'warn' ? 'bdy-msg--warn' : 'bdy-msg--err');
                    echo '<div class="bdy-msg ' . esc_attr($cls) . '"><strong>' . esc_html($text) . '</strong></div>';
                }
            }
            ?>

            <form method="get" class="bdy-row" style="margin-top:10px;">
                <?php
                foreach ($_GET as $k => $v) {
                    if ( in_array($k, ['bdy_q','edit_id','bdy_msg'], true) ) continue;
                    echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
                }
                ?>
                <div class="bdy-field" style="min-width:260px;">
                    <label for="bdy_q">Search</label>
                    <input id="bdy_q" class="bdy-input" type="text" name="bdy_q" placeholder="Name, phone..." value="<?php echo esc_attr($search); ?>">
                </div>

                <div class="bdy-row" style="gap:8px;">
                    <button class="bdy-btn bdy-btn--primary" type="submit">Search</button>
                    <?php if ($search !== ''): ?>
                        <?php
                        $clear_args = $preserve;
                        unset($clear_args['bdy_q'], $clear_args['edit_id']);
                        $clear_url = add_query_arg($clear_args, $base_url);
                        ?>
                        <a class="bdy-btn bdy-btn--ghost" href="<?php echo esc_url($clear_url); ?>">Clear</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="bdy-card">
            <div class="bdy-head">
                <div>
                    <h3 class="bdy-title" style="font-size:18px;">Employee list</h3>
                    <p class="bdy-sub">Edit loads the record into the form.</p>
                </div>
            </div>

            <div class="bdy-tablewrap" role="region" aria-label="Employees table">
                <table class="bdy-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Phone</th>
                            <th>Role</th>
                            <th>Permissions</th>
                            <th>Expiry</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ( empty($rows) ): ?>
                        <tr><td colspan="7">No records found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                            <?php $expired = ($r->vigencia_permiso < $today); ?>
                            <tr>
                                <td><?php echo esc_html($r->id); ?></td>
                                <td><?php echo esc_html($r->nombre); ?></td>
                                <td><?php echo esc_html($r->telefono); ?></td>
                                <td><span class="bdy-badge"><?php echo esc_html($r->rol); ?></span></td>
                                <td><span class="bdy-badge"><?php echo esc_html($r->permisos); ?></span></td>
                                <td>
                                    <?php if ($expired): ?>
                                        <span class="bdy-badge bdy-badge--expired"><?php echo esc_html($r->vigencia_permiso); ?> · EXPIRED</span>
                                    <?php else: ?>
                                        <span class="bdy-badge"><?php echo esc_html($r->vigencia_permiso); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="bdy-actions">
                                        <?php $edit_action = add_query_arg($preserve, $base_url) . '#bdy-form'; ?>
                                        <form method="get" action="<?php echo esc_url($edit_action); ?>">
                                            <?php
                                            foreach ($preserve as $k => $v) {
                                                if ($k === 'edit_id') continue;
                                                echo '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr($v) . '">';
                                            }
                                            ?>
                                            <input type="hidden" name="edit_id" value="<?php echo esc_attr($r->id); ?>">
                                            <button class="bdy-btn bdy-btn--primary" type="submit">Edit</button>
                                        </form>

                                        <form method="post" onsubmit="return confirm('Delete this record?');" style="display:inline-flex;align-items:center;margin:0;">
                                            <?php wp_nonce_field('bdy_nonce_action', 'bdy_nonce'); ?>
                                            <input type="hidden" name="bdy_action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo esc_attr($r->id); ?>">
                                            <button class="bdy-btn bdy-btn--danger" type="submit">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="bdy-card" id="bdy-form">
            <div class="bdy-head">
                <div>
                    <h3 class="bdy-title" style="font-size:18px;"><?php echo $editing ? 'Edit employee' : 'Add employee'; ?></h3>
                    <p class="bdy-sub">Fill in the fields and save.</p>
                </div>

                <?php if ($editing): ?>
                    <?php
                    $cancel_args = $preserve;
                    unset($cancel_args['edit_id']);
                    $cancel_url = add_query_arg($cancel_args, $base_url) . '#bdy-form';
                    ?>
                    <a class="bdy-btn bdy-btn--ghost" href="<?php echo esc_url($cancel_url); ?>">Cancel</a>
                <?php endif; ?>
            </div>

            <form method="post">
                <?php wp_nonce_field('bdy_nonce_action', 'bdy_nonce'); ?>
                <input type="hidden" name="bdy_action" value="<?php echo $editing ? 'update' : 'add'; ?>">
                <?php if ($editing): ?>
                    <input type="hidden" name="id" value="<?php echo esc_attr($editing->id); ?>">
                <?php endif; ?>

                <div class="bdy-row">
                    <div class="bdy-field">
                        <label for="bdy_nombre">Name</label>
                        <input id="bdy_nombre" class="bdy-input" type="text" name="nombre" required value="<?php echo esc_attr($editing->nombre ?? ''); ?>">
                    </div>

                    <div class="bdy-field">
                        <label for="bdy_telefono">Phone</label>
                        <input id="bdy_telefono" class="bdy-input" type="text" name="telefono" required value="<?php echo esc_attr($editing->telefono ?? ''); ?>">
                    </div>
                </div>

                <div class="bdy-row" style="margin-top:10px;">
                    <div class="bdy-field">
                        <label for="bdy_rol">Role</label>
                        <select id="bdy_rol" class="bdy-select" name="rol" required>
                            <?php
                            $current_role = $editing->rol ?? $roles[0];
                            foreach ($roles as $role_value) {
                                echo '<option value="' . esc_attr($role_value) . '" ' . selected($current_role, $role_value, false) . '>'
                                   . esc_html($role_value) . '</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <div class="bdy-field">
                        <label for="bdy_permisos">Permissions</label>
                        <select id="bdy_permisos" class="bdy-select" name="permisos" required>
                            <?php
                            $current_perm = $editing->permisos ?? array_key_first($perms_map);
                            foreach ($perms_map as $value => $label) {
                                echo '<option value="' . esc_attr($value) . '" ' . selected($current_perm, $value, false) . '>'
                                   . esc_html($label) . '</option>';
                            }
                            ?>
                        </select>
                    </div>

                    <div class="bdy-field">
                        <label for="bdy_vigencia">Permission expiry</label>
                        <input id="bdy_vigencia" class="bdy-input" type="date" name="vigencia_permiso" required
                               value="<?php echo esc_attr($editing->vigencia_permiso ?? ''); ?>">
                    </div>
                </div>

                <div class="bdy-row" style="margin-top:14px;">
                    <button class="bdy-btn bdy-btn--primary" type="submit"><?php echo $editing ? 'Save changes' : 'Add'; ?></button>
                    <span class="bdy-small">Changes are saved in the external database.</span>
                </div>
            </form>
        </div>

        <?php if ($edit_id): ?>
            <script>
              (function(){
                var el = document.getElementById('bdy-form');
                if (el) el.scrollIntoView({behavior:'smooth', block:'start'});
              })();
            </script>
        <?php endif; ?>

    </div>
    <?php
    return ob_get_clean();
}
