<?php
/**
 * Plugin Name: UltraLove Product Stock Export
 * Description: Fast direct product stock export via database queries. Provides WP-CLI command and admin page.
 * Version: 1.0
 * Author: UltraLove
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class UltraLove_Product_Stock_Export {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'handle_export_request']);
        
        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('ultralove export-stock', [$this, 'wp_cli_export']);
        }
    }
    
    public function add_admin_menu() {
        add_management_page(
            'Product Stock Export',
            'Product Stock Export',
            'manage_options',
            'ultralove-stock-export',
            [$this, 'render_admin_page']
        );
    }
    
    public function render_admin_page() {
        ?>
        <div class="wrap">
            <h1>Product Stock Export</h1>
            <p>Export all products with SKU and stock quantities as CSV.</p>
            
            <form method="post">
                <?php wp_nonce_field('ultralove_export_stock', 'ultralove_export_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="status">Status Filter</label></th>
                        <td>
                            <select name="status" id="status">
                                <option value="publish">Published Only</option>
                                <option value="any">All</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="include_variations">Include Variations</label></th>
                        <td>
                            <input type="checkbox" name="include_variations" id="include_variations" value="1" checked />
                            <p class="description">Include product variations if available</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Export to CSV', 'primary', 'ultralove_export_submit'); ?>
            </form>
        </div>
        <?php
    }
    
    public function handle_export_request() {
        if (!isset($_POST['ultralove_export_submit'])) {
            return;
        }
        
        if (!isset($_POST['ultralove_export_nonce']) || !wp_verify_nonce($_POST['ultralove_export_nonce'], 'ultralove_export_stock')) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $status = sanitize_text_field($_POST['status'] ?? 'publish');
        $include_variations = !empty($_POST['include_variations']);
        
        $this->export_stock_csv($status, $include_variations, true);
    }
    
    public function wp_cli_export($args, $assoc_args) {
        $status = $assoc_args['status'] ?? 'publish';
        $include_variations = $assoc_args['variations'] ?? true;
        
        $this->export_stock_csv($status, $include_variations, false);
    }
    
    private function export_stock_csv($status = 'publish', $include_variations = true, $download = false) {
        global $wpdb;
        
        $filename = 'products-stock-' . gmdate('Y-m-d-His') . '.csv';
        
        if ($download) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            $output = fopen('php://output', 'w');
        } else {
            $filepath = trailingslashit(wp_upload_dir()['basedir']) . 'wp-all-export/' . $filename;
            wp_mkdir_p(dirname($filepath));
            $output = fopen($filepath, 'w');
        }
        
        // Write header row
        fputcsv($output, ['Product ID', 'SKU', 'Title', 'Stock Quantity', 'Stock Status']);
        
        // Query products with stock from the optimized lookup table
        $query = "
            SELECT 
                p.ID,
                l.sku,
                p.post_title,
                l.stock_quantity,
                l.stock_status
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->prefix}wc_product_meta_lookup l ON l.product_id = p.ID
            WHERE p.post_type = 'product'
        ";
        
        if ($status !== 'any') {
            $query .= $wpdb->prepare(" AND p.post_status = %s", $status);
        }
        
        $query .= " ORDER BY p.ID";
        
        $results = $wpdb->get_results($query, ARRAY_A);
        
        if (empty($results)) {
            fputcsv($output, ['No products found']);
            fclose($output);
            if (!$download) {
                WP_CLI::success("Export file: " . $filepath);
            }
            return;
        }
        
        $row_count = 0;
        foreach ($results as $row) {
            fputcsv($output, [
                $row['ID'],
                $row['sku'] ?: '',
                $row['post_title'] ?: '',
                $row['stock_quantity'] !== null ? $row['stock_quantity'] : '',
                $row['stock_status'] ?: 'instock'
            ]);
            $row_count++;
        }
        
        fclose($output);
        
        if ($download) {
            exit;
        } else {
            WP_CLI::success("Exported {$row_count} products to: " . $filepath);
        }
    }
}

// Initialize plugin
add_action('plugins_loaded', function() {
    UltraLove_Product_Stock_Export::get_instance();
});
