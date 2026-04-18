<?php
/**
 * Template Name: Export Zip
 *
 * The template file for displaying full width.
 *
 * @package Martfury
 */

get_header();

use MassEdge\WordPress\Plugin\ExportMediaLibrary\API;

$filename = 'uploads.zip';

            // set folder structure
            $folderStructure = API::FOLDER_STRUCTURE_FLAT;
            

            // set compress option
            $compress = 0;

  API::export([
                    'filename' => $filename,
                    'folder_structure' => $folderStructure,
                    'compress' => $compress,
                    'add_attachment_callback' => function($value, $params) {
                        return apply_filters('massedge-wp-eml/export/add_attachment', $value, $params);
                    },
                    'add_attachment_failed_callback' => function($params) {
                        do_action('massedge-wp-eml/export/add_attachment_failed', $params);
                    },
                    'add_extra_files_callback' => function($params) {
                        do_action('massedge-wp-eml/export/add_extra_files', $params);
                    }
                ]);
            

?>

<div class="wrap">
    <h1>Export Media Library</h1>

    <form action="" method="post" target="_blank">
        <?php //wp_nonce_field(self::FIELD_NONCE_DOWNLOAD_ACTION) ?>

        <table class="form-table">
            <tr>
                <th>Folder Structure</th>
                <td>
                    <select name="FIELD_FOLDER_STRUCTURE">
                        <option value="FOLDER_STRUCTURE_FLAT">Single folder with all files</option>
                       
                    </select>
                </td>
            </tr>
            <tr>
                <th>Compress</th>
                <td>
                    <select name="FIELD_COMPRESS">
                     
                        <option value="1">Yes</option>
                    </select>
                    <p class="description">Enabling compression can decrease the size of the zip download, but requires more processing on the server.</p>
                </td>
            </tr>
        </table>

        <p class="submit">
            <button type="submit" class="button button-primary" name="FIELD_SUBMIT_DOWNLOAD" value="1">Download Zip</button>
        </p>
    </form>
</div>
<?php get_footer(); ?>
