<?php
/**
 * The template for displaying 404 pages (Not Found).
 *
 * @package Martfury
 */

get_header();

// Elementor `404` location
if (!function_exists('elementor_theme_do_location') || !elementor_theme_do_location('single')) {
    get_template_part('template-parts/content', '404');
}

get_footer();
